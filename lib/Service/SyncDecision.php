<?php

declare(strict_types=1);

namespace OCA\GoogleTwoWaySync\Service;

/**
 * What to do with one synced item, given what changed on each side since the
 * last sync. Shared by calendars, contacts and tasks. Pure, so it is unit
 * tested; notes are English source strings that the caller translates.
 */
final class SyncDecision {
	/**
	 * @param bool $exists the Nextcloud object is still there
	 * @param bool $writable the collection may be written to Google
	 * @param bool $itemWritable this item may be written to Google
	 * @return array{action: string, note: string}
	 */
	public static function decide(
		bool $exists,
		bool $deleted,
		bool $googleChanged,
		bool $localChanged,
		int $googleUpdated,
		int $localModified,
		bool $writable = true,
		bool $itemWritable = true,
	): array {
		if (!$exists) {
			if ($deleted) {
				return self::to('forget');
			}
			if ($googleChanged) {
				return self::to(SyncReport::PULL_CREATE, 'Restored because Google changed it');
			}
			return $itemWritable
				? self::to(SyncReport::PUSH_DELETE)
				: self::to(SyncReport::PULL_CREATE, 'Restored because the calendar is read-only');
		}
		if ($deleted) {
			return $localChanged && $writable
				? self::to(SyncReport::PUSH_CREATE, 'Uploaded again because Nextcloud changed it after Google deleted it')
				: self::to(SyncReport::PULL_DELETE);
		}
		if ($googleChanged && $localChanged) {
			return !$itemWritable || $googleUpdated >= $localModified
				? self::to(SyncReport::PULL_UPDATE, 'Both sides changed; Google is newer')
				: self::to(SyncReport::PUSH_UPDATE, 'Both sides changed; Nextcloud is newer');
		}
		if ($googleChanged) {
			return self::to(SyncReport::PULL_UPDATE);
		}
		if ($localChanged) {
			return $itemWritable
				? self::to(SyncReport::PUSH_UPDATE)
				: self::to(SyncReport::PULL_UPDATE, 'Reverted because the calendar is read-only');
		}
		return self::to('none');
	}

	/**
	 * Exception occurrences that failed to upload count as a local change and
	 * win over a newer Google master, while the item can still be written.
	 * Read-only keeps the values it was given, so the ordinary pull stands.
	 *
	 * @param list<string> $pendingOverrides
	 * @return array{localChanged: bool, localModified: int}
	 */
	public static function pendingLocal(bool $localChanged, int $localModified, int $googleUpdated, array $pendingOverrides, bool $itemWritable): array {
		if ($pendingOverrides === [] || !$itemWritable) {
			return ['localChanged' => $localChanged, 'localModified' => $localModified];
		}
		return [
			'localChanged' => true,
			'localModified' => max($localModified, $googleUpdated) + 1,
		];
	}

	/**
	 * What to store after a push of exception occurrences.
	 * Failed keys stay pending and the caller keeps the previous Nextcloud etag.
	 * A push with no failures clears that mark.
	 *
	 * @param list<string> $failed
	 * @return array{keepEtag: bool, extra: ?string}
	 */
	public static function overridePushState(array $failed): array {
		if ($failed === []) {
			return ['keepEtag' => false, 'extra' => null];
		}
		$extra = json_encode(['pendingOverrides' => array_values($failed)], JSON_UNESCAPED_UNICODE);
		return ['keepEtag' => true, 'extra' => $extra === false ? null : $extra];
	}

	/**
	 * @return list<string>
	 */
	public static function pendingOverrideKeys(?string $extra): array {
		$decoded = json_decode((string)$extra, true);
		if (!is_array($decoded) || !isset($decoded['pendingOverrides']) || !is_array($decoded['pendingOverrides'])) {
			return [];
		}
		$keys = [];
		foreach ($decoded['pendingOverrides'] as $key) {
			if (is_string($key) && $key !== '') {
				$keys[] = $key;
			}
		}
		return $keys;
	}

	/**
	 * @return array{action: string, note: string}
	 */
	private static function to(string $action, string $note = ''): array {
		return ['action' => $action, 'note' => $note];
	}
}
