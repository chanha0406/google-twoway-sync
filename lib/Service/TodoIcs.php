<?php

declare(strict_types=1);

namespace OCA\GoogleTwoWaySync\Service;

use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;

/**
 * VTODO reading and writing for Google Tasks. Pure: only Sabre, so it is unit
 * tested without Nextcloud.
 */
final class TodoIcs {
	/**
	 * @param \Sabre\VObject\Property|null $due
	 */
	public static function dueFromProperty($due): string {
		if ($due === null) {
			return '';
		}
		try {
			return $due->getDateTime()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d');
		} catch (\Throwable) {
			$raw = trim((string)$due->getValue());
			if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m) === 1) {
				return $m[1] . '-' . $m[2] . '-' . $m[3];
			}
			if (preg_match('/^(\d{4})(\d{2})(\d{2})/', $raw, $m) === 1) {
				return $m[1] . '-' . $m[2] . '-' . $m[3];
			}
			return '';
		}
	}

	/**
	 * @param array{title: string, notes: string, status: string, due: string} $shape
	 */
	public static function serialize(array $shape, string $uid): string {
		$calendar = new VCalendar();
		$todo = $calendar->createComponent('VTODO');
		$todo->UID = 'google-task-' . $uid;
		$todo->SUMMARY = $shape['title'];
		if ($shape['notes'] !== '') {
			$todo->DESCRIPTION = $shape['notes'];
		}
		if ($shape['due'] !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $shape['due']) === 1) {
			$todo->add('DUE', str_replace('-', '', $shape['due']), ['VALUE' => 'DATE']);
		}
		$done = $shape['status'] === 'completed';
		$todo->STATUS = $done ? 'COMPLETED' : 'NEEDS-ACTION';
		$todo->{'PERCENT-COMPLETE'} = $done ? 100 : 0;
		if ($done) {
			$todo->COMPLETED = gmdate('Ymd\THis\Z');
		}
		$calendar->add($todo);
		return $calendar->serialize();
	}

	/**
	 * Put Google's fields into the existing VTODO. Google Tasks has only a
	 * title, notes, status and a due date, so alarms (including iPhone
	 * location reminders), priority, start date, categories and the UID stay
	 * as the CalDAV client wrote them. Returns null when there is no VTODO.
	 *
	 * @param array{title: string, notes: string, status: string, due: string} $shape
	 */
	public static function merge(string $data, array $shape): ?string {
		try {
			$calendar = Reader::read($data, Reader::OPTION_FORGIVING);
		} catch (\Throwable) {
			return null;
		}
		if (!$calendar instanceof VCalendar || !isset($calendar->VTODO)) {
			return null;
		}
		$todo = $calendar->VTODO;

		$todo->SUMMARY = $shape['title'];
		if ($shape['notes'] !== '') {
			$todo->DESCRIPTION = $shape['notes'];
		} else {
			$todo->remove('DESCRIPTION');
		}

		// Keep the local DUE (and its time) while Google has the same day.
		// A DUE written as 2026-10-21 is the old broken format and is rewritten.
		$due = $todo->DUE ?? null;
		$sameDay = $due !== null
			&& !str_contains((string)$due->getValue(), '-')
			&& self::dueFromProperty($due) === $shape['due'];
		if (!$sameDay) {
			$timed = self::shiftedDue($due, $shape['due']);
			$todo->remove('DUE');
			if ($timed !== null) {
				// Keep the time of day so DUE stays the same value type as DTSTART.
				$todo->add('DUE', $timed);
			} elseif ($shape['due'] !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $shape['due']) === 1) {
				$todo->add('DUE', str_replace('-', '', $shape['due']), ['VALUE' => 'DATE']);
			}
		}

		$status = strtoupper((string)($todo->STATUS ?? ''));
		$wasDone = $status === 'COMPLETED' || (string)($todo->{'PERCENT-COMPLETE'} ?? '') === '100';
		$done = $shape['status'] === 'completed';
		if ($done !== $wasDone) {
			$todo->STATUS = $done ? 'COMPLETED' : 'NEEDS-ACTION';
			$todo->{'PERCENT-COMPLETE'} = $done ? 100 : 0;
			if ($done) {
				$todo->COMPLETED = gmdate('Ymd\THis\Z');
			} else {
				$todo->remove('COMPLETED');
			}
		}

		$now = gmdate('Ymd\THis\Z');
		$todo->DTSTAMP = $now;
		$todo->{'LAST-MODIFIED'} = $now;
		return $calendar->serialize();
	}

	/**
	 * A timed local DUE moved by whole days so its UTC date is Google's day.
	 *
	 * @param \Sabre\VObject\Property|null $due
	 */
	private static function shiftedDue($due, string $day): ?\DateTimeImmutable {
		if (!$due instanceof \Sabre\VObject\Property\ICalendar\DateTime || !$due->hasTime()
			|| preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1) {
			return null;
		}
		try {
			$local = $due->getDateTime();
			$from = new \DateTimeImmutable($local->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d'), new \DateTimeZone('UTC'));
			$to = new \DateTimeImmutable($day, new \DateTimeZone('UTC'));
		} catch (\Throwable) {
			return null;
		}
		$days = (int)$from->diff($to)->format('%r%a');
		return $local->modify(($days >= 0 ? '+' : '') . $days . ' days');
	}
}
