<?php

declare(strict_types=1);

namespace OCA\GoogleTwoWaySync\Service;

use Sabre\VObject\Component\VCard;
use Sabre\VObject\Property;

/**
 * Converts between Google people and vCards through a comparable shape that
 * holds the fields both sides know. Updates only touch fields that differ, so
 * everything else on a card (X- properties, photos, iOS labels) stays as is.
 *
 * Only the CONTACT source of a person is used: profile data cannot be written
 * through the contact and would be duplicated into it otherwise.
 */
class ContactConverter {
	public const FIELDS = ['names', 'nicknames', 'emails', 'phones', 'addresses', 'org', 'birthday', 'note', 'urls'];
	/** Taken from Google, never written back (contact groups need their own API) */
	public const PULL_ONLY = ['categories'];
	public const PERSON_FIELDS = 'names,nicknames,emailAddresses,phoneNumbers,addresses,organizations,birthdays,biographies,urls,photos,memberships,metadata';

	private const UPDATE_FIELDS = [
		'names' => 'names',
		'nicknames' => 'nicknames',
		'emails' => 'emailAddresses',
		'phones' => 'phoneNumbers',
		'addresses' => 'addresses',
		'org' => 'organizations',
		'birthday' => 'birthdays',
		'note' => 'biographies',
		'urls' => 'urls',
	];
	private const PHONE_TYPES = ['mobile', 'workMobile', 'homeFax', 'workFax', 'otherFax', 'pager', 'main', 'home', 'work', 'other'];
	private const ADR_KEYS = ['poBox', 'extendedAddress', 'streetAddress', 'city', 'region', 'postalCode', 'country'];

	// Google → comparable

	/**
	 * @param array<string, mixed> $p
	 * @param array<string, string> $groupNames
	 * @return array<string, mixed>
	 */
	public function fromGoogle(array $p, array $groupNames): array {
		$name = $this->contactItems($p, 'names')[0] ?? [];
		$names = [
			(string)($name['familyName'] ?? ''),
			(string)($name['givenName'] ?? ''),
			(string)($name['middleName'] ?? ''),
			(string)($name['honorificPrefix'] ?? ''),
			(string)($name['honorificSuffix'] ?? ''),
		];
		if (implode('', $names) === '') {
			$names[1] = (string)($name['unstructuredName'] ?? $name['displayName'] ?? '');
		}
		$org = $this->contactItems($p, 'organizations')[0] ?? [];
		$birthday = '';
		foreach ($this->contactItems($p, 'birthdays') as $b) {
			$d = $b['date'] ?? null;
			if (is_array($d) && isset($d['month'], $d['day'])) {
				$birthday = isset($d['year'])
					? sprintf('%04d-%02d-%02d', $d['year'], $d['month'], $d['day'])
					: sprintf('--%02d-%02d', $d['month'], $d['day']);
				break;
			}
		}
		$categories = [];
		foreach (($p['memberships'] ?? []) as $m) {
			$rn = $m['contactGroupMembership']['contactGroupResourceName'] ?? null;
			if (is_string($rn) && isset($groupNames[$rn])) {
				$categories[] = $groupNames[$rn];
			}
		}
		sort($categories);

		return [
			'names' => self::trimAll($names),
			'nicknames' => array_map(static fn ($n) => trim((string)($n['value'] ?? '')), $this->contactItems($p, 'nicknames')),
			'emails' => array_map(static fn ($e) => [
				'type' => self::simpleType((string)($e['type'] ?? '')),
				'value' => trim((string)($e['value'] ?? '')),
			], $this->contactItems($p, 'emailAddresses')),
			'phones' => array_map(static fn ($t) => [
				'type' => in_array($t['type'] ?? '', self::PHONE_TYPES, true) ? (string)$t['type'] : '',
				'value' => trim((string)($t['value'] ?? '')),
			], $this->contactItems($p, 'phoneNumbers')),
			'addresses' => array_map(static function ($a) {
				$out = ['type' => self::simpleType((string)($a['type'] ?? ''))];
				foreach (self::ADR_KEYS as $k) {
					$out[$k] = trim((string)($a[$k] ?? ''));
				}
				return $out;
			}, $this->contactItems($p, 'addresses')),
			'org' => [trim((string)($org['name'] ?? '')), trim((string)($org['title'] ?? ''))],
			'birthday' => $birthday,
			'note' => self::text($this->contactItems($p, 'biographies')[0]['value'] ?? ''),
			'urls' => array_map(static fn ($u) => [
				'type' => (string)($u['type'] ?? ''),
				'value' => trim((string)($u['value'] ?? '')),
			], $this->contactItems($p, 'urls')),
			'categories' => $categories,
		];
	}

	/**
	 * @param array<string, mixed> $p
	 * @return list<array<string, mixed>>
	 */
	private function contactItems(array $p, string $field): array {
		$out = [];
		foreach (($p[$field] ?? []) as $item) {
			if (!is_array($item)) {
				continue;
			}
			$source = $item['metadata']['source']['type'] ?? 'CONTACT';
			if ($source === 'CONTACT') {
				$out[] = $item;
			}
		}
		return $out;
	}

	public function displayName(array $p): string {
		$name = $this->contactItems($p, 'names')[0] ?? [];
		return trim((string)($name['displayName'] ?? $name['unstructuredName'] ?? ''));
	}

	public function photoUrl(array $p): ?string {
		foreach (($p['photos'] ?? []) as $photo) {
			if (is_array($photo) && empty($photo['default']) && isset($photo['url'])
				&& ($photo['metadata']['source']['type'] ?? 'CONTACT') === 'CONTACT') {
				return self::fullSizePhotoUrl((string)$photo['url']);
			}
		}
		return null;
	}

	/**
	 * Google hands out contact photos as =s100 thumbnails; ask for a large one.
	 */
	public static function fullSizePhotoUrl(string $url): string {
		return preg_replace('/=s\d+(-[A-Za-z0-9-]+)?$/', '=s1024', $url) ?? $url;
	}

	// vCard → comparable

	/**
	 * @return array<string, mixed>
	 */
	public function fromVCard(VCard $card): array {
		$names = ['', '', '', '', ''];
		if (isset($card->N)) {
			$parts = $card->N->getParts();
			for ($i = 0; $i < 5; $i++) {
				$names[$i] = (string)($parts[$i] ?? '');
			}
		}
		if (implode('', $names) === '') {
			$names[1] = (string)($card->FN ?? '');
		}
		$nicknames = [];
		foreach ($card->select('NICKNAME') as $prop) {
			foreach ($prop->getParts() as $part) {
				$nicknames[] = trim((string)$part);
			}
		}
		$emails = [];
		foreach ($card->select('EMAIL') as $prop) {
			$emails[] = ['type' => self::simpleType(self::firstKnown(self::types($prop), ['home', 'work', 'other'])), 'value' => trim((string)$prop)];
		}
		$phones = [];
		foreach ($card->select('TEL') as $prop) {
			$value = trim((string)$prop);
			if (str_starts_with(strtolower($value), 'tel:')) {
				$value = substr($value, 4);
			}
			$phones[] = ['type' => self::phoneType(self::types($prop)), 'value' => $value];
		}
		$addresses = [];
		foreach ($card->select('ADR') as $prop) {
			$parts = $prop->getParts();
			$a = ['type' => self::simpleType(self::firstKnown(self::types($prop), ['home', 'work', 'other']))];
			foreach (self::ADR_KEYS as $i => $k) {
				$a[$k] = trim((string)($parts[$i] ?? ''));
			}
			$addresses[] = $a;
		}
		$org = '';
		if (isset($card->ORG)) {
			$org = trim((string)($card->ORG->getParts()[0] ?? ''));
		}
		$urls = [];
		foreach ($card->select('URL') as $prop) {
			$urls[] = ['type' => strtolower((string)(self::types($prop)[0] ?? '')), 'value' => trim((string)$prop)];
		}
		$categories = [];
		foreach ($card->select('CATEGORIES') as $prop) {
			foreach ($prop->getParts() as $part) {
				if (trim((string)$part) !== '') {
					$categories[] = trim((string)$part);
				}
			}
		}
		sort($categories);

		return [
			'names' => self::trimAll($names),
			'nicknames' => $nicknames,
			'emails' => $emails,
			'phones' => $phones,
			'addresses' => $addresses,
			'org' => [$org, trim((string)($card->TITLE ?? ''))],
			'birthday' => isset($card->BDAY) ? $this->birthday($card->BDAY) : '',
			'note' => self::text((string)($card->NOTE ?? '')),
			'urls' => $urls,
			'categories' => array_values(array_unique($categories)),
		];
	}

	private function birthday(Property $prop): string {
		$value = trim((string)$prop);
		$omit = (string)($prop['X-APPLE-OMIT-YEAR'] ?? '');
		if (preg_match('/^--(\d{2})-?(\d{2})$/', $value, $m)) {
			return '--' . $m[1] . '-' . $m[2];
		}
		if (preg_match('/^(\d{4})-?(\d{2})-?(\d{2})/', $value, $m)) {
			if ($omit !== '' && $omit === $m[1]) {
				return '--' . $m[2] . '-' . $m[3];
			}
			return $m[1] . '-' . $m[2] . '-' . $m[3];
		}
		return '';
	}

	/**
	 * @return list<string>
	 */
	private static function types(Property $prop): array {
		$out = [];
		$param = $prop['TYPE'] ?? null;
		if ($param !== null) {
			foreach ($param->getParts() as $part) {
				$out[] = strtolower((string)$part);
			}
		}
		return $out;
	}

	/**
	 * @param list<string> $types
	 * @param list<string> $known
	 */
	private static function firstKnown(array $types, array $known): string {
		foreach ($types as $t) {
			if (in_array($t, $known, true)) {
				return $t;
			}
		}
		return '';
	}

	/**
	 * @param list<string> $types
	 */
	private static function phoneType(array $types): string {
		$has = static fn (string $t) => in_array($t, $types, true);
		$cell = $has('cell') || $has('iphone') || $has('mobile');
		return match (true) {
			$cell && $has('work') => 'workMobile',
			$cell => 'mobile',
			$has('fax') && $has('home') => 'homeFax',
			$has('fax') && $has('work') => 'workFax',
			$has('fax') => 'otherFax',
			$has('pager') => 'pager',
			$has('main') => 'main',
			$has('home') => 'home',
			$has('work') => 'work',
			$has('other') => 'other',
			default => '',
		};
	}

	/**
	 * @return list<string>
	 */
	private static function phoneTypeToVCard(string $type): array {
		return match ($type) {
			'mobile' => ['CELL'],
			'workMobile' => ['WORK', 'CELL'],
			'homeFax' => ['HOME', 'FAX'],
			'workFax' => ['WORK', 'FAX'],
			'otherFax' => ['FAX'],
			'pager' => ['PAGER'],
			'main' => ['MAIN'],
			'home' => ['HOME'],
			'work' => ['WORK'],
			'other' => ['OTHER'],
			default => [],
		};
	}

	private static function simpleType(string $type): string {
		$type = strtolower($type);
		return in_array($type, ['home', 'work', 'other'], true) ? $type : '';
	}

	// Comparing

	/**
	 * @param array<string, mixed> $a
	 * @param array<string, mixed> $b
	 * @param list<string> $fields
	 * @return list<string>
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
		$keys = [];
		switch ($field) {
			case 'emails':
				foreach ($value ?? [] as $e) {
					$keys[] = $e['type'] . '|' . strtolower($e['value']);
				}
				break;
			case 'phones':
				foreach ($value ?? [] as $t) {
					$keys[] = $t['type'] . '|' . preg_replace('/[^0-9+*#]/', '', $t['value']);
				}
				break;
			case 'urls':
				foreach ($value ?? [] as $u) {
					$keys[] = $u['value'];
				}
				break;
			case 'addresses':
				foreach ($value ?? [] as $a) {
					$keys[] = implode('|', $a);
				}
				break;
			case 'nicknames':
				$keys = array_values(array_filter($value ?? [], static fn ($v) => $v !== ''));
				break;
			default:
				return json_encode($value, JSON_UNESCAPED_UNICODE) ?: '';
		}
		sort($keys);
		return json_encode($keys, JSON_UNESCAPED_UNICODE) ?: '';
	}

	// comparable → Google

	/**
	 * @param array<string, mixed> $c
	 * @param list<string> $fields
	 * @return array{array<string, mixed>, list<string>} body and updatePersonFields
	 */
	public function toGoogle(array $c, array $fields): array {
		$body = [];
		$update = [];
		foreach ($fields as $field) {
			if (!isset(self::UPDATE_FIELDS[$field])) {
				continue;
			}
			$key = self::UPDATE_FIELDS[$field];
			$update[] = $key;
			switch ($field) {
				case 'names':
					[$family, $given, $middle, $prefix, $suffix] = $c['names'];
					$body[$key] = implode('', $c['names']) === '' ? [] : [array_filter([
						'familyName' => $family,
						'givenName' => $given,
						'middleName' => $middle,
						'honorificPrefix' => $prefix,
						'honorificSuffix' => $suffix,
					], static fn ($v) => $v !== '')];
					break;
				case 'nicknames':
					$body[$key] = array_map(static fn ($v) => ['value' => $v], array_values(array_filter($c['nicknames'], static fn ($v) => $v !== '')));
					break;
				case 'emails':
				case 'phones':
				case 'urls':
					$body[$key] = array_map(static fn ($e) => array_filter(['value' => $e['value'], 'type' => $e['type']], static fn ($v) => $v !== ''), $c[$field]);
					break;
				case 'addresses':
					$body[$key] = array_map(static fn ($a) => array_filter($a, static fn ($v) => $v !== ''), $c['addresses']);
					break;
				case 'org':
					[$name, $title] = $c['org'];
					$body[$key] = $name === '' && $title === '' ? [] : [array_filter(['name' => $name, 'title' => $title], static fn ($v) => $v !== '')];
					break;
				case 'birthday':
					$b = $c['birthday'];
					if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $b, $m)) {
						$body[$key] = [['date' => ['year' => (int)$m[1], 'month' => (int)$m[2], 'day' => (int)$m[3]]]];
					} elseif (preg_match('/^--(\d{2})-(\d{2})$/', $b, $m)) {
						$body[$key] = [['date' => ['month' => (int)$m[1], 'day' => (int)$m[2]]]];
					} else {
						$body[$key] = [];
					}
					break;
				case 'note':
					$body[$key] = $c['note'] === '' ? [] : [['value' => $c['note'], 'contentType' => 'TEXT_PLAIN']];
					break;
			}
		}
		return [$body, $update];
	}

	// comparable → vCard

	/**
	 * @param array<string, mixed> $c
	 * @param list<string> $fields
	 */
	public function applyToVCard(VCard $card, array $c, array $fields, string $displayName): void {
		$v3 = str_starts_with((string)($card->VERSION ?? '3.0'), '3');
		foreach ($fields as $field) {
			switch ($field) {
				case 'names':
					unset($card->N, $card->FN);
					if (implode('', $c['names']) !== '') {
						$card->add('N', $c['names']);
					}
					$fn = $displayName !== '' ? $displayName : trim($c['names'][0] . $c['names'][1]);
					$card->add('FN', $fn !== '' ? $fn : ($c['org'][0] ?? ''));
					break;
				case 'nicknames':
					unset($card->NICKNAME);
					foreach ($c['nicknames'] as $n) {
						if ($n !== '') {
							$card->add('NICKNAME', $n);
						}
					}
					break;
				case 'emails':
					unset($card->EMAIL);
					foreach ($c['emails'] as $e) {
						$card->add('EMAIL', $e['value'], $e['type'] !== '' ? ['TYPE' => strtoupper($e['type'])] : []);
					}
					break;
				case 'phones':
					unset($card->TEL);
					foreach ($c['phones'] as $t) {
						$types = self::phoneTypeToVCard($t['type']);
						$card->add('TEL', $t['value'], $types !== [] ? ['TYPE' => $types] : []);
					}
					break;
				case 'addresses':
					unset($card->ADR);
					foreach ($c['addresses'] as $a) {
						$parts = [];
						foreach (self::ADR_KEYS as $k) {
							$parts[] = $a[$k];
						}
						$card->add('ADR', $parts, $a['type'] !== '' ? ['TYPE' => strtoupper($a['type'])] : []);
					}
					break;
				case 'org':
					unset($card->ORG, $card->TITLE);
					if ($c['org'][0] !== '') {
						$card->add('ORG', $c['org'][0]);
					}
					if ($c['org'][1] !== '') {
						$card->add('TITLE', $c['org'][1]);
					}
					break;
				case 'birthday':
					unset($card->BDAY);
					$b = $c['birthday'];
					if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $b, $m)) {
						$card->add('BDAY', $v3 ? $b : $m[1] . $m[2] . $m[3], ['VALUE' => 'DATE']);
					} elseif (preg_match('/^--(\d{2})-(\d{2})$/', $b, $m)) {
						if ($v3) {
							$card->add('BDAY', '1604-' . $m[1] . '-' . $m[2], ['VALUE' => 'DATE', 'X-APPLE-OMIT-YEAR' => '1604']);
						} else {
							$card->add('BDAY', '--' . $m[1] . $m[2], ['VALUE' => 'DATE']);
						}
					}
					break;
				case 'note':
					unset($card->NOTE);
					if ($c['note'] !== '') {
						$card->add('NOTE', $c['note']);
					}
					break;
				case 'urls':
					unset($card->URL);
					foreach ($c['urls'] as $u) {
						$card->add('URL', $u['value'], $u['type'] !== '' ? ['TYPE' => $u['type']] : []);
					}
					break;
				case 'categories':
					unset($card->CATEGORIES);
					if ($c['categories'] !== []) {
						$card->add('CATEGORIES', $c['categories']);
					}
					break;
			}
		}
		$this->dropOrphanLabels($card);
	}

	/**
	 * iOS labels a property through a group (item1.TEL + item1.X-ABLabel); the
	 * label is left alone once its property was replaced.
	 */
	private function dropOrphanLabels(VCard $card): void {
		$groups = [];
		foreach ($card->children() as $child) {
			if ($child instanceof Property && $child->group !== null && strtoupper($child->name) !== 'X-ABLABEL') {
				$groups[strtolower($child->group)] = true;
			}
		}
		foreach ($card->select('X-ABLABEL') as $label) {
			if ($label->group !== null && !isset($groups[strtolower($label->group)])) {
				$card->remove($label);
			}
		}
	}

	public function setPhoto(VCard $card, ?string $data, string $contentType): void {
		unset($card->PHOTO);
		if ($data === null || $data === '') {
			return;
		}
		$type = str_contains(strtolower($contentType), 'png') ? 'PNG' : 'JPEG';
		if (str_starts_with((string)($card->VERSION ?? '3.0'), '3')) {
			$card->add('PHOTO', $data, ['ENCODING' => 'b', 'TYPE' => $type]);
		} else {
			$card->add('PHOTO', 'data:image/' . strtolower($type) . ';base64,' . base64_encode($data), ['VALUE' => 'URI']);
		}
	}

	/**
	 * @param list<string> $values
	 * @return list<string>
	 */
	private static function trimAll(array $values): array {
		return array_map(static fn ($v) => trim((string)$v), $values);
	}

	private static function text(mixed $value): string {
		return trim(str_replace("\r\n", "\n", (string)$value));
	}
}
