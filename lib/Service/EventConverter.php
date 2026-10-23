<?php

declare(strict_types=1);

namespace OCA\GoogleTwoWaySync\Service;

use OCA\GoogleTwoWaySync\L10n\T;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\DateTimeParser;
use Sabre\VObject\Property;
use Sabre\VObject\Property\ICalendar\DateTime as DateTimeProperty;
use Sabre\VObject\Reader;

/**
 * Converts between Google events and iCalendar through a common "comparable"
 * shape that only holds what both sides can represent:
 *
 *   summary, description, location: string
 *   start, end: ['date' => 'Y-m-d'] or ['ts' => int, 'tz' => ?string]
 *   rrule: normalised RRULE/RDATE lines, rruleRaw: the same lines as written
 *   exdate: keys of excluded occurrences, see key()
 *   reminders: sorted "popup:15" / "email:60"
 *   status, transparency, visibility: Google vocabulary
 *
 * Both sides are compared in that shape, so properties only one side knows
 * (attendees, attachments, X- properties) neither cause updates nor get lost:
 * an update only touches the fields that differ.
 *
 * A recurring event is a group: the master and its exceptions, keyed by the
 * start of the occurrence they replace. Google cancelled instances count as
 * EXDATE of the master.
 */
class EventConverter {
	public const FIELDS = ['summary', 'description', 'location', 'start', 'end', 'rrule', 'exdate', 'reminders', 'status', 'transparency', 'visibility'];
	public const OVERRIDE_FIELDS = ['summary', 'description', 'location', 'start', 'end', 'reminders', 'status', 'transparency', 'visibility'];
	private const MAX_REMINDER_MINUTES = 40320;

	// Google → comparable

	/**
	 * @param array<string, mixed> $master
	 * @param list<array<string, mixed>> $exceptions
	 * @param list<array{method?: string, minutes?: int}> $defaultReminders
	 * @return array{master: array<string, mixed>, overrides: array<string, array<string, mixed>>}
	 */
	public function groupFromGoogle(array $master, array $exceptions, array $defaultReminders): array {
		$cancelled = [];
		$overrides = [];
		foreach ($exceptions as $e) {
			$key = is_array($e['originalStartTime'] ?? null) ? $this->googleKey($e['originalStartTime']) : null;
			if ($key === null) {
				continue;
			}
			if (($e['status'] ?? '') === 'cancelled') {
				$cancelled[] = $key;
				continue;
			}
			$overrides[$key] = $this->fromGoogle($e, $defaultReminders, []);
		}
		ksort($overrides);
		return [
			'master' => $this->fromGoogle($master, $defaultReminders, $cancelled),
			'overrides' => $overrides,
		];
	}

	/**
	 * @param array<string, mixed> $e
	 * @param list<array{method?: string, minutes?: int}> $defaultReminders
	 * @param list<string> $extraExdates
	 * @return array<string, mixed>
	 */
	public function fromGoogle(array $e, array $defaultReminders, array $extraExdates): array {
		$start = $this->googleTime(is_array($e['start'] ?? null) ? $e['start'] : []);
		$end = $this->googleTime(is_array($e['end'] ?? null) ? $e['end'] : []) ?? $start;
		$tz = $start['tz'] ?? null;
		[$raw, $norm, $exdates] = $this->parseRecurrence(is_array($e['recurrence'] ?? null) ? $e['recurrence'] : [], $tz);
		$exdates = array_values(array_unique(array_merge($exdates, $extraExdates)));
		sort($exdates);

		$reminders = [];
		$rem = is_array($e['reminders'] ?? null) ? $e['reminders'] : [];
		$list = !empty($rem['useDefault']) ? $defaultReminders : ($rem['overrides'] ?? []);
		foreach ($list as $r) {
			if (isset($r['minutes'])) {
				$reminders[] = (($r['method'] ?? 'popup') === 'email' ? 'email' : 'popup') . ':' . (int)$r['minutes'];
			}
		}

		$status = (string)($e['status'] ?? '');
		$visibility = (string)($e['visibility'] ?? '');
		return [
			'summary' => self::text($e['summary'] ?? ''),
			'description' => self::text($e['description'] ?? ''),
			'location' => self::text($e['location'] ?? ''),
			'start' => $start,
			'end' => $end,
			'rrule' => $norm,
			'rruleRaw' => $raw,
			'exdate' => $exdates,
			'reminders' => self::sortedUnique($reminders),
			'status' => in_array($status, ['tentative', 'cancelled'], true) ? $status : 'confirmed',
			'transparency' => ($e['transparency'] ?? '') === 'transparent' ? 'transparent' : 'opaque',
			'visibility' => in_array($visibility, ['private', 'confidential'], true) ? $visibility : 'default',
		];
	}

	/**
	 * @param array<string, mixed> $t
	 * @return array{date: string}|array{ts: int, tz: ?string}|null
	 */
	private function googleTime(array $t): ?array {
		if (isset($t['date'])) {
			return ['date' => (string)$t['date']];
		}
		if (isset($t['dateTime'])) {
			$tz = isset($t['timeZone']) && $t['timeZone'] !== '' ? (string)$t['timeZone'] : null;
			return ['ts' => (new DateTimeImmutable((string)$t['dateTime']))->getTimestamp(), 'tz' => $tz];
		}
		return null;
	}

	/**
	 * @param array<string, mixed> $t
	 */
	public function googleKey(array $t): ?string {
		$time = $this->googleTime($t);
		return $time === null ? null : self::timeKey($time);
	}

	/**
	 * @param array{date: string}|array{ts: int, tz: ?string} $time
	 */
	public static function timeKey(array $time): string {
		return isset($time['date']) ? 'D:' . str_replace('-', '', $time['date']) : 'T:' . $time['ts'];
	}

	/**
	 * Id of the Google instance that a key designates, for patching an exception.
	 */
	public static function instanceId(string $masterId, string $key): string {
		if (str_starts_with($key, 'D:')) {
			return $masterId . '_' . substr($key, 2);
		}
		return $masterId . '_' . gmdate('Ymd\THis\Z', (int)substr($key, 2));
	}

	/**
	 * @param list<string> $lines
	 * @return array{list<string>, list<string>, list<string>} raw lines, normalised lines, exdate keys
	 */
	private function parseRecurrence(array $lines, ?string $tz): array {
		$raw = [];
		$norm = [];
		$exdates = [];
		foreach ($lines as $line) {
			$line = trim((string)$line);
			if ($line === '') {
				continue;
			}
			if (stripos($line, 'EXDATE') === 0) {
				$prop = $this->parseProperty($line);
				if ($prop instanceof DateTimeProperty) {
					$exdates = array_merge($exdates, $this->dateKeys($prop, $tz));
				}
				continue;
			}
			$raw[] = $line;
			$norm[] = $this->normalizeRuleLine($line, $tz);
		}
		sort($norm);
		return [$raw, $norm, $exdates];
	}

	private function normalizeRuleLine(string $line, ?string $tz): string {
		$upper = strtoupper($line);
		if (str_starts_with($upper, 'RRULE')) {
			$value = substr($line, strpos($line, ':') + 1);
			$parts = [];
			foreach (explode(';', $value) as $part) {
				if (!str_contains($part, '=')) {
					continue;
				}
				[$k, $v] = explode('=', $part, 2);
				$k = strtoupper(trim($k));
				$v = strtoupper(trim($v));
				if ($k === 'WKST' && $v === 'MO') {
					continue;
				}
				if ($k === 'UNTIL') {
					$v = $this->normalizeUntil($v, $tz);
				} elseif (str_contains($v, ',')) {
					$list = explode(',', $v);
					sort($list);
					$v = implode(',', $list);
				}
				$parts[$k] = $v;
			}
			ksort($parts);
			$out = [];
			foreach ($parts as $k => $v) {
				$out[] = $k . '=' . $v;
			}
			return 'RRULE:' . implode(';', $out);
		}
		if (str_starts_with($upper, 'RDATE')) {
			$prop = $this->parseProperty($line);
			if ($prop instanceof DateTimeProperty) {
				$keys = $this->dateKeys($prop, $tz);
				sort($keys);
				return 'RDATE:' . implode(',', $keys);
			}
		}
		return $upper;
	}

	/**
	 * UNTIL is compared by day: clients disagree on whether it is the start of the
	 * last occurrence or the end of its day.
	 */
	private function normalizeUntil(string $v, ?string $tz): string {
		try {
			$dt = DateTimeParser::parse($v, new DateTimeZone($tz ?? 'UTC'));
			return $dt->setTimezone(new DateTimeZone($tz ?? 'UTC'))->format('Ymd');
		} catch (\Throwable) {
			return $v;
		}
	}

	private function parseProperty(string $line): ?Property {
		$ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:x\r\nBEGIN:VEVENT\r\nUID:x\r\nDTSTAMP:20000101T000000Z\r\n"
			. $line . "\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
		try {
			/** @var VCalendar $cal */
			$cal = Reader::read($ics, Reader::OPTION_FORGIVING);
		} catch (\Throwable) {
			return null;
		}
		$name = strtoupper((string)strtok($line, ':;'));
		$props = $cal->VEVENT->select($name);
		return $props === [] ? null : reset($props);
	}

	/**
	 * @return list<string>
	 */
	private function dateKeys(DateTimeProperty $prop, ?string $tz): array {
		$keys = [];
		$zone = new DateTimeZone($tz ?? 'UTC');
		$hasTime = $prop->hasTime();
		foreach ($prop->getDateTimes($zone) as $dt) {
			$keys[] = $hasTime ? 'T:' . $dt->getTimestamp() : 'D:' . $dt->format('Ymd');
		}
		return $keys;
	}

	// iCalendar → comparable

	/**
	 * @return array{0: ?VEvent, 1: array<string, VEvent>} master and exceptions by key
	 */
	public function localEvents(VCalendar $vcal, string $defaultTz): array {
		$master = null;
		$overrides = [];
		foreach ($vcal->select('VEVENT') as $ev) {
			/** @var VEvent $ev */
			$rid = $ev->{'RECURRENCE-ID'};
			if ($rid instanceof DateTimeProperty) {
				$overrides[$this->propKey($rid, $defaultTz)] = $ev;
			} elseif ($master === null) {
				$master = $ev;
			}
		}
		ksort($overrides);
		return [$master, $overrides];
	}

	/**
	 * @return array{master: array<string, mixed>, overrides: array<string, array<string, mixed>>}|null
	 */
	public function groupFromCalendar(VCalendar $vcal, string $defaultTz): ?array {
		[$master, $overrides] = $this->localEvents($vcal, $defaultTz);
		if ($master === null) {
			return null;
		}
		$out = [];
		foreach ($overrides as $key => $ev) {
			$out[$key] = $this->fromVEvent($ev, $defaultTz);
		}
		return ['master' => $this->fromVEvent($master, $defaultTz), 'overrides' => $out];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function fromVEvent(VEvent $ev, string $defaultTz): array {
		$start = $ev->DTSTART instanceof DateTimeProperty ? $this->propTime($ev->DTSTART, $defaultTz) : null;
		$end = null;
		if ($ev->DTEND instanceof DateTimeProperty) {
			$end = $this->propTime($ev->DTEND, $defaultTz);
		} elseif (isset($ev->DURATION) && $start !== null) {
			$end = $this->addDuration($start, (string)$ev->DURATION, $defaultTz);
		} elseif ($start !== null) {
			$end = isset($start['date'])
				? ['date' => (new DateTimeImmutable($start['date']))->modify('+1 day')->format('Y-m-d')]
				: $start;
		}
		$tz = $start['tz'] ?? null;

		$raw = [];
		$norm = [];
		foreach ($ev->select('RRULE') as $rule) {
			$line = 'RRULE:' . $rule->getValue();
			$raw[] = $line;
			$norm[] = $this->normalizeRuleLine($line, $tz);
		}
		foreach ($ev->select('RDATE') as $rdate) {
			$line = trim($rdate->serialize());
			$raw[] = $line;
			$norm[] = $this->normalizeRuleLine($line, $tz);
		}
		sort($norm);
		$exdates = [];
		foreach ($ev->select('EXDATE') as $exdate) {
			if ($exdate instanceof DateTimeProperty) {
				$exdates = array_merge($exdates, $this->dateKeys($exdate, $tz ?? $defaultTz));
			}
		}
		$exdates = array_values(array_unique($exdates));
		sort($exdates);

		$status = strtoupper((string)($ev->STATUS ?? ''));
		$class = strtoupper((string)($ev->CLASS ?? ''));
		return [
			'summary' => self::text((string)($ev->SUMMARY ?? '')),
			'description' => self::text((string)($ev->DESCRIPTION ?? '')),
			'location' => self::text((string)($ev->LOCATION ?? '')),
			'start' => $start,
			'end' => $end,
			'rrule' => $norm,
			'rruleRaw' => $raw,
			'exdate' => $exdates,
			'reminders' => $this->localReminders($ev, $start, $end, $defaultTz),
			'status' => match ($status) {
				'TENTATIVE' => 'tentative',
				'CANCELLED' => 'cancelled',
				default => 'confirmed',
			},
			'transparency' => strtoupper((string)($ev->TRANSP ?? '')) === 'TRANSPARENT' ? 'transparent' : 'opaque',
			'visibility' => match ($class) {
				'PRIVATE' => 'private',
				'CONFIDENTIAL' => 'confidential',
				default => 'default',
			},
		];
	}

	/**
	 * @return array{date: string}|array{ts: int, tz: ?string}
	 */
	private function propTime(DateTimeProperty $prop, string $defaultTz): array {
		if (!$prop->hasTime()) {
			return ['date' => $prop->getDateTime()->format('Y-m-d')];
		}
		if ($prop->isFloating()) {
			return ['ts' => $prop->getDateTime(new DateTimeZone($defaultTz))->getTimestamp(), 'tz' => $defaultTz];
		}
		$dt = $prop->getDateTime();
		$name = $dt->getTimezone()->getName();
		return ['ts' => $dt->getTimestamp(), 'tz' => str_contains($name, '/') ? $name : null];
	}

	private function propKey(DateTimeProperty $prop, string $defaultTz): string {
		return self::timeKey($this->propTime($prop, $defaultTz));
	}

	/**
	 * @param array{date: string}|array{ts: int, tz: ?string} $start
	 * @return array{date: string}|array{ts: int, tz: ?string}
	 */
	private function addDuration(array $start, string $duration, string $defaultTz): array {
		try {
			$interval = DateTimeParser::parseDuration($duration);
		} catch (\Throwable) {
			return $start;
		}
		if (isset($start['date'])) {
			return ['date' => (new DateTimeImmutable($start['date'], new DateTimeZone($defaultTz)))->add($interval)->format('Y-m-d')];
		}
		return ['ts' => (new DateTimeImmutable('@' . $start['ts']))->add($interval)->getTimestamp(), 'tz' => $start['tz']];
	}

	/**
	 * @param array{date: string}|array{ts: int, tz: ?string}|null $start
	 * @param array{date: string}|array{ts: int, tz: ?string}|null $end
	 * @return list<string>
	 */
	private function localReminders(VEvent $ev, ?array $start, ?array $end, string $defaultTz): array {
		if ($start === null) {
			return [];
		}
		$startTs = $this->timestamp($start, $defaultTz);
		$endTs = $end === null ? $startTs : $this->timestamp($end, $defaultTz);
		$out = [];
		foreach ($ev->select('VALARM') as $alarm) {
			$trigger = $alarm->TRIGGER ?? null;
			if ($trigger === null) {
				continue;
			}
			$minutes = null;
			if (strtoupper((string)($trigger['VALUE'] ?? '')) === 'DATE-TIME' && $trigger instanceof DateTimeProperty) {
				$minutes = ($startTs - $trigger->getDateTime()->getTimestamp()) / 60;
			} else {
				try {
					$interval = DateTimeParser::parseDuration((string)$trigger);
				} catch (\Throwable) {
					continue;
				}
				$seconds = $interval->d * 86400 + $interval->h * 3600 + $interval->i * 60 + $interval->s;
				if ($interval->invert === 1) {
					$seconds = -$seconds;
				}
				$base = strtoupper((string)($trigger['RELATED'] ?? 'START')) === 'END' ? $endTs : $startTs;
				$minutes = ($startTs - ($base + $seconds)) / 60;
			}
			if ($minutes < 0) {
				// Google has no reminders after the start
				continue;
			}
			$method = strtoupper((string)($alarm->ACTION ?? '')) === 'EMAIL'
				|| strtolower((string)($alarm->{'X-GTSYNC-METHOD'} ?? '')) === 'email'
				? 'email' : 'popup';
			$out[] = $method . ':' . min(self::MAX_REMINDER_MINUTES, (int)round($minutes));
		}
		return self::sortedUnique($out);
	}

	/**
	 * @param array{date: string}|array{ts: int, tz: ?string} $time
	 */
	private function timestamp(array $time, string $defaultTz): int {
		if (isset($time['date'])) {
			return (new DateTimeImmutable($time['date'], new DateTimeZone($defaultTz)))->getTimestamp();
		}
		return $time['ts'];
	}

	// Comparing

	/**
	 * @param array<string, mixed> $a
	 * @param array<string, mixed> $b
	 * @param list<string> $fields
	 * @return list<string> the fields that differ
	 */
	public function diff(array $a, array $b, array $fields): array {
		$out = [];
		foreach ($fields as $field) {
			if ($this->cmp($field, $a[$field] ?? null) !== $this->cmp($field, $b[$field] ?? null)) {
				$out[] = $field;
			}
		}
		return $out;
	}

	private function cmp(string $field, mixed $value): string {
		if ($field === 'start' || $field === 'end') {
			return is_array($value) ? self::timeKey($value) : '';
		}
		return json_encode($value, JSON_UNESCAPED_UNICODE) ?: '';
	}

	/**
	 * @param array{master: array<string, mixed>, overrides: array<string, array<string, mixed>>} $google
	 * @param array{master: array<string, mixed>, overrides: array<string, array<string, mixed>>} $local
	 * @return array{master: list<string>, changed: array<string, list<string>>, onlyGoogle: list<string>, onlyLocal: list<string>}
	 */
	public function groupDiff(array $google, array $local): array {
		$changed = [];
		foreach (array_intersect_key($google['overrides'], $local['overrides']) as $key => $g) {
			$fields = $this->diff($g, $local['overrides'][$key], self::OVERRIDE_FIELDS);
			if ($fields !== []) {
				$changed[$key] = $fields;
			}
		}
		return [
			'master' => $this->diff($google['master'], $local['master'], self::FIELDS),
			'changed' => $changed,
			'onlyGoogle' => array_map('strval', array_keys(array_diff_key($google['overrides'], $local['overrides']))),
			'onlyLocal' => array_map('strval', array_keys(array_diff_key($local['overrides'], $google['overrides']))),
		];
	}

	/**
	 * @param array{master: list<string>, changed: array<string, list<string>>, onlyGoogle: list<string>, onlyLocal: list<string>} $diff
	 */
	public static function isSame(array $diff): bool {
		return $diff['master'] === [] && $diff['changed'] === [] && $diff['onlyGoogle'] === [] && $diff['onlyLocal'] === [];
	}

	/**
	 * @param array{master: list<string>, changed: array<string, list<string>>, onlyGoogle: list<string>, onlyLocal: list<string>} $diff
	 */
	public static function describe(array $diff): string {
		$parts = $diff['master'];
		$n = count($diff['changed']) + count($diff['onlyGoogle']) + count($diff['onlyLocal']);
		if ($n > 0) {
			$parts[] = T::t('Exception occurrence %s', [$n]);
		}
		return implode(',', $parts);
	}

	// comparable → Google

	/**
	 * @param array<string, mixed> $c
	 * @param list<string> $fields
	 * @return array<string, mixed>
	 */
	public function toGoogle(array $c, array $fields, string $calendarTz, bool $patch): array {
		$recurring = ($c['rruleRaw'] ?? []) !== [];
		$zoneForTimed = $recurring ? ($c['start']['tz'] ?? $calendarTz) : null;
		$body = [];
		foreach ($fields as $field) {
			switch ($field) {
				case 'summary':
				case 'description':
				case 'location':
					$body[$field] = $c[$field];
					break;
				case 'start':
				case 'end':
					if (is_array($c[$field])) {
						$body[$field] = $this->googleTimeBody($c[$field], $zoneForTimed, $patch);
					}
					break;
				case 'rrule':
				case 'exdate':
					$body['recurrence'] = $this->recurrenceLines($c, $calendarTz);
					break;
				case 'reminders':
					$overrides = [];
					foreach (array_slice($c['reminders'], 0, 5) as $r) {
						[$method, $minutes] = explode(':', $r, 2);
						$overrides[] = ['method' => $method, 'minutes' => (int)$minutes];
					}
					$body['reminders'] = ['useDefault' => false, 'overrides' => $overrides];
					break;
				case 'status':
				case 'transparency':
				case 'visibility':
					$body[$field] = $c[$field];
					break;
			}
		}
		return $body;
	}

	/**
	 * @param array{date: string}|array{ts: int, tz: ?string} $time
	 * @return array<string, ?string>
	 */
	private function googleTimeBody(array $time, ?string $fallbackTz, bool $patch): array {
		if (isset($time['date'])) {
			return ['date' => $time['date']] + ($patch ? ['dateTime' => null, 'timeZone' => null] : []);
		}
		$tz = $time['tz'] ?? $fallbackTz;
		$dt = (new DateTimeImmutable('@' . $time['ts']))->setTimezone(new DateTimeZone($tz ?? 'UTC'));
		$out = ['dateTime' => $dt->format(DateTimeInterface::RFC3339)];
		if ($tz !== null || $patch) {
			$out['timeZone'] = $tz;
		}
		if ($patch) {
			$out['date'] = null;
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $c
	 * @return list<string>
	 */
	private function recurrenceLines(array $c, string $calendarTz): array {
		$lines = $c['rruleRaw'] ?? [];
		if ($lines === []) {
			return [];
		}
		$tz = $c['start']['tz'] ?? $calendarTz;
		foreach ($c['exdate'] as $key) {
			if (str_starts_with($key, 'D:')) {
				$lines[] = 'EXDATE;VALUE=DATE:' . substr($key, 2);
			} else {
				$dt = (new DateTimeImmutable('@' . substr($key, 2)))->setTimezone(new DateTimeZone($tz));
				$lines[] = 'EXDATE;TZID=' . $tz . ':' . $dt->format('Ymd\THis');
			}
		}
		return $lines;
	}

	// comparable → iCalendar

	/**
	 * @param array<string, mixed> $c
	 * @param list<string> $fields
	 */
	public function applyToVEvent(VEvent $ev, array $c, array $fields, string $defaultTz): void {
		foreach ($fields as $field) {
			switch ($field) {
				case 'summary':
				case 'description':
				case 'location':
					$name = strtoupper($field);
					unset($ev->{$name});
					if ($c[$field] !== '') {
						$ev->add($name, $c[$field]);
					}
					break;
				case 'start':
					unset($ev->DTSTART);
					if (is_array($c['start'])) {
						$this->addTime($ev, 'DTSTART', $c['start']);
					}
					break;
				case 'end':
					unset($ev->DTEND, $ev->DURATION);
					if (is_array($c['end'])) {
						$this->addTime($ev, 'DTEND', $c['end']);
					}
					break;
				case 'rrule':
					unset($ev->RRULE, $ev->RDATE);
					foreach ($c['rruleRaw'] as $line) {
						if (stripos($line, 'RRULE') === 0) {
							$ev->add('RRULE', substr($line, strpos($line, ':') + 1));
							continue;
						}
						$prop = $this->parseProperty($line);
						if ($prop !== null) {
							$params = [];
							foreach ($prop->parameters() as $param) {
								$params[$param->name] = $param->getValue();
							}
							$ev->add($prop->name, $prop->getParts(), $params);
						}
					}
					break;
				case 'exdate':
					unset($ev->EXDATE);
					$tz = $c['start']['tz'] ?? null;
					foreach ($c['exdate'] as $key) {
						$this->addKeyTime($ev, 'EXDATE', $key, $tz);
					}
					break;
				case 'reminders':
					$ev->remove('VALARM');
					foreach ($c['reminders'] as $r) {
						[$method, $minutes] = explode(':', $r, 2);
						$alarm = $ev->add('VALARM', [
							'ACTION' => 'DISPLAY',
							'DESCRIPTION' => 'Reminder',
							'TRIGGER' => '-PT' . (int)$minutes . 'M',
						]);
						if ($method === 'email') {
							$alarm->add('X-GTSYNC-METHOD', 'email');
						}
					}
					break;
				case 'status':
					unset($ev->STATUS);
					$ev->add('STATUS', strtoupper($c['status']));
					break;
				case 'transparency':
					unset($ev->TRANSP);
					$ev->add('TRANSP', strtoupper($c['transparency']));
					break;
				case 'visibility':
					unset($ev->CLASS);
					$ev->add('CLASS', $c['visibility'] === 'default' ? 'PUBLIC' : strtoupper($c['visibility']));
					break;
			}
		}
	}

	/**
	 * @param array{date: string}|array{ts: int, tz: ?string} $time
	 */
	private function addTime(VEvent $ev, string $name, array $time): void {
		if (isset($time['date'])) {
			$ev->add($name, str_replace('-', '', $time['date']), ['VALUE' => 'DATE']);
			return;
		}
		$ev->add($name, (new DateTimeImmutable('@' . $time['ts']))->setTimezone(new DateTimeZone($time['tz'] ?? 'UTC')));
	}

	private function addKeyTime(VEvent $ev, string $name, string $key, ?string $tz): void {
		if (str_starts_with($key, 'D:')) {
			$ev->add($name, substr($key, 2), ['VALUE' => 'DATE']);
			return;
		}
		$ev->add($name, (new DateTimeImmutable('@' . substr($key, 2)))->setTimezone(new DateTimeZone($tz ?? 'UTC')));
	}

	/**
	 * @param array<string, mixed> $e Google event
	 */
	private function applyMeta(VEvent $ev, array $e): void {
		$updated = isset($e['updated']) ? new DateTimeImmutable((string)$e['updated']) : new DateTimeImmutable();
		$updated = $updated->setTimezone(new DateTimeZone('UTC'));
		unset($ev->{'LAST-MODIFIED'}, $ev->DTSTAMP);
		$ev->add('LAST-MODIFIED', $updated);
		$ev->add('DTSTAMP', $updated);
		if (!isset($ev->CREATED) && isset($e['created'])) {
			$ev->add('CREATED', (new DateTimeImmutable((string)$e['created']))->setTimezone(new DateTimeZone('UTC')));
		}
		if (isset($e['sequence'])) {
			unset($ev->SEQUENCE);
			$ev->add('SEQUENCE', (int)$e['sequence']);
		}
	}

	/**
	 * @param array{master: array<string, mixed>, overrides: array<string, array<string, mixed>>} $group
	 * @param array<string, mixed> $googleMaster
	 * @param array<string, array<string, mixed>> $googleOverrides Google exceptions by key
	 */
	public function buildCalendar(string $uid, array $group, array $googleMaster, array $googleOverrides, string $defaultTz): VCalendar {
		$vcal = new VCalendar(['PRODID' => '-//chanha//googletwowaysync//EN']);
		/** @var VEvent $ev */
		$ev = $vcal->add('VEVENT', ['UID' => $uid]);
		$this->applyToVEvent($ev, $group['master'], self::FIELDS, $defaultTz);
		$this->applyMeta($ev, $googleMaster);
		$tz = $group['master']['start']['tz'] ?? null;
		foreach ($group['overrides'] as $key => $c) {
			/** @var VEvent $o */
			$o = $vcal->add('VEVENT', ['UID' => $uid]);
			$this->addKeyTime($o, 'RECURRENCE-ID', (string)$key, $tz);
			$this->applyToVEvent($o, $c, self::OVERRIDE_FIELDS, $defaultTz);
			$this->applyMeta($o, $googleOverrides[$key] ?? $googleMaster);
		}
		return $vcal;
	}

	/**
	 * Writes what differs from Google into an existing calendar object, leaving
	 * everything Google does not know about in place.
	 *
	 * @param array{master: array<string, mixed>, overrides: array<string, array<string, mixed>>} $google
	 * @param array<string, mixed> $googleMaster
	 * @param array<string, array<string, mixed>> $googleOverrides
	 * @param list<string> $keepLocalOverrides occurrence keys that stay even when only the local calendar has them
	 */
	public function mergeIntoCalendar(VCalendar $vcal, array $google, array $googleMaster, array $googleOverrides, string $defaultTz, array $keepLocalOverrides = []): bool {
		$local = $this->groupFromCalendar($vcal, $defaultTz);
		[$master, $overrideEvents] = $this->localEvents($vcal, $defaultTz);
		if ($local === null || $master === null) {
			return false;
		}
		$diff = $this->groupDiff($google, $local);
		if (self::isSame($diff)) {
			return false;
		}
		$this->applyToVEvent($master, $google['master'], $diff['master'], $defaultTz);
		$this->applyMeta($master, $googleMaster);
		foreach ($diff['changed'] as $key => $fields) {
			$this->applyToVEvent($overrideEvents[$key], $google['overrides'][$key], $fields, $defaultTz);
			$this->applyMeta($overrideEvents[$key], $googleOverrides[$key] ?? $googleMaster);
		}
		$uid = (string)$master->UID;
		$tz = $google['master']['start']['tz'] ?? null;
		foreach ($diff['onlyGoogle'] as $key) {
			/** @var VEvent $o */
			$o = $vcal->add('VEVENT', ['UID' => $uid]);
			$this->addKeyTime($o, 'RECURRENCE-ID', $key, $tz);
			$this->applyToVEvent($o, $google['overrides'][$key], self::OVERRIDE_FIELDS, $defaultTz);
			$this->applyMeta($o, $googleOverrides[$key] ?? $googleMaster);
		}
		$keep = array_fill_keys($keepLocalOverrides, true);
		foreach ($diff['onlyLocal'] as $key) {
			// A failed upload leaves this occurrence only on Nextcloud.
			if (isset($keep[$key])) {
				continue;
			}
			$vcal->remove($overrideEvents[$key]);
		}
		return true;
	}

	private static function text(mixed $value): string {
		return trim(str_replace("\r\n", "\n", (string)$value));
	}

	/**
	 * @param list<string> $values
	 * @return list<string>
	 */
	private static function sortedUnique(array $values): array {
		$values = array_values(array_unique($values));
		sort($values);
		return $values;
	}
}
