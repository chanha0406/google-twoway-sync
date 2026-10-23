<?php

declare(strict_types=1);

namespace OCA\GoogleTwoWaySync\Service;

use OCA\GoogleTwoWaySync\L10n\T;

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\GoogleTwoWaySync\Db\Collection;
use OCA\GoogleTwoWaySync\Db\CollectionMapper;
use OCA\GoogleTwoWaySync\Db\ObjectState;
use OCA\GoogleTwoWaySync\Db\ObjectStateMapper;
use OCA\GoogleTwoWaySync\Exception\GoogleApiException;
use OCP\BackgroundJob\IJobList;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;

/**
 * Two-way sync of one Google calendar with one Nextcloud calendar.
 *
 * One object on each side is a master event with its exceptions. Changes are
 * found from the Google sync token and from the Nextcloud etags stored at the
 * end of the previous run. When both sides changed, the later edit wins; when
 * one side deleted and the other edited, the edit is kept. Exception occurrences
 * that failed to upload stay pending, so a newer Google master does not win and
 * the next pull does not delete them.
 */
class CalendarSyncService {
	public const LEGACY_JOB = 'OCA\Google\BackgroundJob\ImportCalendarJob';
	private const DEFAULT_TZ = 'Asia/Seoul';

	public function __construct(
		private GoogleClient $google,
		private EventConverter $converter,
		private CalDavBackend $caldav,
		private ObjectStateMapper $states,
		private CollectionMapper $collections,
		private IJobList $jobs,
		private CalendarAppearance $appearance,
		private PlanRunner $runner,
	) {
	}

	/**
	 * URI Google Synchronization gives the calendar it imports a Google calendar
	 * into, kept so that the import is taken over as is.
	 */
	public static function stableUri(string $calId): string {
		return 'google-import-' . substr(hash('sha256', $calId), 0, 32);
	}

	public function existingCalendarId(string $userId, string $calId): int {
		$cal = $this->caldav->getCalendarByUri('principals/users/' . $userId, self::stableUri($calId));
		return $cal !== null && !self::isInTrash($cal) ? (int)$cal['id'] : 0;
	}

	public function hasLegacyJob(Collection $col): bool {
		foreach ($this->jobs->getJobsIterator(self::LEGACY_JOB, null, 0) as $job) {
			$args = $job->getArgument();
			if (is_array($args) && ($args['user_id'] ?? '') === $col->getUserId() && ($args['cal_id'] ?? '') === $col->getGoogleId()) {
				return true;
			}
		}
		return false;
	}

	public function removeLegacyJob(Collection $col): bool {
		foreach ($this->jobs->getJobsIterator(self::LEGACY_JOB, null, 0) as $job) {
			$args = $job->getArgument();
			if (is_array($args) && ($args['user_id'] ?? '') === $col->getUserId() && ($args['cal_id'] ?? '') === $col->getGoogleId()) {
				$this->jobs->remove(self::LEGACY_JOB, $args);
				return true;
			}
		}
		return false;
	}

	/**
	 * @param array<string, mixed> $entry the calendarList entry of the calendar
	 */
	public function sync(Collection $col, array $entry, SyncContext $ctx): SyncReport {
		$this->storeGoogleMeta($col, $entry, !$ctx->preview);
		$report = new SyncReport($col->getDisplayName());
		$tz = (string)($entry['timeZone'] ?? self::DEFAULT_TZ);
		/** @var list<array{method?: string, minutes?: int}> $defaults */
		$defaults = is_array($entry['defaultReminders'] ?? null) ? $entry['defaultReminders'] : [];
		$writable = $col->isWritable();
		$calId = $col->getGoogleId();

		$ncId = $this->resolveCalendar($col, $ctx, $report);
		$sameCalendar = $ncId > 0 && $ncId === $col->getNcId();
		$states = $sameCalendar ? $this->states->findByCollection($col->getId()) : [];
		$initialized = $sameCalendar && $col->getInitialized() === 1;

		$local = [];
		if ($ncId > 0) {
			foreach ($this->caldav->getCalendarObjects($ncId) as $obj) {
				if (($obj['component'] ?? '') === 'vevent') {
					$local[(string)$obj['uri']] = $obj;
				}
			}
		}
		$byUri = [];
		$byGid = [];
		foreach ($states as $st) {
			$byUri[$st->getNcUri()] = $st;
			if ($st->getGoogleId() !== '') {
				$byGid[$st->getGoogleId()] = $st;
			}
		}

		$full = !$initialized || ($col->getSyncToken() ?? '') === '';
		try {
			[$items, $nextToken] = $this->listEvents($ctx, $calId, $full ? null : $col->getSyncToken());
		} catch (GoogleApiException $e) {
			if ($full || !$e->isExpiredSyncToken()) {
				throw $e;
			}
			$full = true;
			[$items, $nextToken] = $this->listEvents($ctx, $calId, null);
		}

		$groups = [];
		if ($full) {
			$masters = [];
			$exceptions = [];
			foreach ($items as $item) {
				if (isset($item['recurringEventId'])) {
					$exceptions[(string)$item['recurringEventId']][] = $item;
				} else {
					$masters[(string)$item['id']] = $item;
				}
			}
			foreach ($masters as $gid => $master) {
				$groups[$gid] = ($master['status'] ?? '') === 'cancelled'
					? self::deletedGroup()
					: $this->makeGroup($master, $exceptions[$gid] ?? []);
			}
		} else {
			$changed = [];
			foreach ($items as $item) {
				$gid = (string)($item['recurringEventId'] ?? $item['id']);
				$changed[$gid] = $changed[$gid] ?? $item;
				if (!isset($item['recurringEventId'])) {
					$changed[$gid] = $item;
				}
			}
			foreach ($changed as $gid => $item) {
				if (!isset($item['recurringEventId']) && ($item['status'] ?? '') === 'cancelled') {
					$groups[$gid] = self::deletedGroup();
					continue;
				}
				$uid = (string)($item['iCalUID'] ?? (isset($byGid[$gid]) ? $byGid[$gid]->getGoogleUid() : ''));
				$groups[$gid] = $this->fetchGroup($ctx, $calId, (string)$gid, $uid);
			}
		}

		// Decide what to do with every object
		$plan = [];
		$processed = [];
		foreach ($groups as $gid => $g) {
			$gid = (string)$gid;
			$st = $byGid[$gid] ?? null;
			if ($st === null) {
				if ($g['deleted']) {
					continue;
				}
				if (isset($local[$gid]) && !isset($byUri[$gid])) {
					$plan[] = ['action' => SyncReport::ADOPT, 'uri' => $gid, 'gid' => $gid, 'group' => $g, 'state' => null];
					$processed[$gid] = true;
				} else {
					$uri = isset($local[$gid]) ? $gid . '-' . substr(sha1($gid), 0, 8) : $gid;
					$plan[] = ['action' => SyncReport::PULL_CREATE, 'uri' => $uri, 'gid' => $gid, 'group' => $g, 'state' => null];
				}
				continue;
			}
			$processed[$st->getNcUri()] = true;
			$plan[] = $this->decide($st, $g, $local[$st->getNcUri()] ?? null, $g['deleted'] || $g['fp'] !== $st->getGoogleFp(), $writable);
		}
		foreach ($states as $st) {
			$uri = $st->getNcUri();
			if ($st->getIgnored() === 1 || isset($processed[$uri])) {
				continue;
			}
			$processed[$uri] = true;
			$obj = $local[$uri] ?? null;
			if ($full) {
				// Not in the full listing: deleted in Google
				$plan[] = $this->decide($st, self::deletedGroup(), $obj, true, $writable);
				continue;
			}
			if ($obj !== null && $obj['etag'] === $st->getNcEtag()) {
				$report->unchanged++;
				continue;
			}
			$g = $this->fetchGroup($ctx, $calId, $st->getGoogleId(), (string)$st->getGoogleUid());
			$plan[] = $this->decide($st, $g, $obj, $g['deleted'] || $g['fp'] !== $st->getGoogleFp(), $writable);
		}
		foreach ($local as $uri => $obj) {
			$uri = (string)$uri;
			if (isset($processed[$uri]) || isset($byUri[$uri]) || !$initialized) {
				continue;
			}
			$plan[] = ['action' => $writable ? SyncReport::PUSH_CREATE : SyncReport::PULL_DELETE, 'uri' => $uri, 'gid' => '', 'group' => null, 'state' => null, 'note' => $writable ? '' : T::t('Deleted because the calendar is read-only')];
		}

		$contents = $this->loadContents($ncId, array_values(array_filter($plan, static fn (array $p): bool => $p['action'] !== 'none')));
		$ok = $this->runner->run(
			$plan,
			$ctx,
			$report,
			max(count($states), count($local)),
			static fn (int $n): string => T::t('Refused to delete %s items at once. Check them, then continue with occ googletwowaysync:sync --allow-mass-delete.', [$n]),
			fn (array $p) => $this->execute($p, $col, $ncId, $tz, $defaults, $local, $contents, $ctx, $report),
			fn (array $p): string => $this->label($p, $contents),
		);
		if (!$ctx->preview) {
			// A failed pull keeps the old token (or none on the first sync), so
			// the next run lists those items again instead of skipping them.
			$this->runner->finish($col, $report, $ok, $nextToken);
		}
		return $report;
	}

	/**
	 * @param array<string, mixed>|null $obj local object row
	 * @param array<string, mixed> $g
	 * @return array<string, mixed>
	 */
	private function decide(ObjectState $st, array $g, ?array $obj, bool $googleChanged, bool $writable): array {
		$pending = $this->pendingOverrideKeys($st);
		$itemWritable = $writable && !($g['readonly'] ?? false);
		$local = SyncDecision::pendingLocal(
			$obj !== null && $obj['etag'] !== $st->getNcEtag(),
			(int)($obj['lastmodified'] ?? 0),
			(int)$g['updated'],
			$pending,
			$itemWritable,
		);
		$d = SyncDecision::decide(
			$obj !== null,
			$g['deleted'],
			$googleChanged,
			$local['localChanged'],
			(int)$g['updated'],
			$local['localModified'],
			$writable,
			$itemWritable,
		);
		$note = $d['note'];
		if ($pending !== [] && $itemWritable && ($d['action'] === SyncReport::PUSH_UPDATE || $d['action'] === SyncReport::PUSH_CREATE)) {
			$note = 'Exception occurrences that failed to upload are sent again';
		}
		return ['action' => $d['action'], 'uri' => $st->getNcUri(), 'gid' => $st->getGoogleId(), 'group' => $g, 'state' => $st, 'note' => $note !== '' ? T::t($note) : ''];
	}

	/**
	 * @param array<string, mixed> $p
	 * @param list<array{method?: string, minutes?: int}> $defaults
	 * @param array<string, array<string, mixed>> $local
	 * @param array<string, string> $contents
	 */
	private function execute(array $p, Collection $col, int $ncId, string $tz, array $defaults, array $local, array $contents, SyncContext $ctx, SyncReport $report): void {
		$action = $p['action'];
		$uri = (string)$p['uri'];
		/** @var ?ObjectState $st */
		$st = $p['state'];
		$g = $p['group'];
		$note = (string)($p['note'] ?? '');
		$calPath = 'calendars/' . rawurlencode($col->getGoogleId()) . '/events';

		switch ($action) {
			case 'forget':
				if (!$ctx->preview && $st !== null) {
					$this->states->delete($st);
				}
				return;

			case SyncReport::PULL_CREATE:
				[$google, $overridesRaw] = $this->googleComparable($g, $defaults);
				$vcal = $this->converter->buildCalendar((string)$g['uid'], $google, $g['master'], $overridesRaw, $tz);
				$report->add($action, $this->groupLabel($g), $note);
				if ($ctx->preview) {
					return;
				}
				$etag = (string)$this->caldav->createCalendarObject($ncId, $uri, $vcal->serialize());
				$this->saveState($st, $col, (string)$p['gid'], $g, $uri, $etag);
				return;

			case SyncReport::ADOPT:
			case SyncReport::PULL_UPDATE:
				[$google, $overridesRaw] = $this->googleComparable($g, $defaults);
				$vcal = $this->readCalendar($contents[$uri] ?? null);
				$localGroup = $vcal === null ? null : $this->converter->groupFromCalendar($vcal, $tz);
				if ($vcal === null || $localGroup === null) {
					throw new \RuntimeException(T::t('Could not read Nextcloud events'));
				}
				$diff = $this->converter->groupDiff($google, $localGroup);
				$same = EventConverter::isSame($diff);
				$etag = (string)$local[$uri]['etag'];
				if ($action === SyncReport::ADOPT) {
					$report->add($action, $this->groupLabel($g), $same ? '' : T::t('Nextcloud content updated: %s', [EventConverter::describe($diff)]));
				} elseif ($same) {
					$report->unchanged++;
				} else {
					$report->add($action, $this->groupLabel($g), trim($note . ' ' . EventConverter::describe($diff)));
				}
				if ($ctx->preview) {
					return;
				}
				if (!$same && $this->converter->mergeIntoCalendar($vcal, $google, $g['master'], $overridesRaw, $tz, $this->pendingOverrideKeys($st))) {
					$etag = (string)$this->caldav->updateCalendarObject($ncId, $uri, $vcal->serialize());
				}
				$this->saveState($st, $col, (string)$p['gid'], $g, $uri, $etag);
				return;

			case SyncReport::PULL_DELETE:
				$report->add($action, $g !== null && !$g['deleted'] ? $this->groupLabel($g) : $this->localLabel($uri, $contents), $note);
				if ($ctx->preview) {
					return;
				}
				$this->caldav->deleteCalendarObject($ncId, $uri, CalDavBackend::CALENDAR_TYPE_CALENDAR, true);
				if ($st !== null) {
					$this->states->delete($st);
				}
				return;

			case SyncReport::PUSH_DELETE:
				$report->add($action, $st !== null ? (string)$st->getGoogleId() : $uri, $note);
				if ($ctx->preview || $st === null) {
					return;
				}
				try {
					$this->google->request($ctx, 'DELETE', GoogleClient::CALENDAR . $calPath . '/' . rawurlencode($st->getGoogleId()), ['sendUpdates' => 'none']);
				} catch (GoogleApiException $e) {
					if (!$e->isNotFound()) {
						throw $e;
					}
				}
				$this->states->delete($st);
				return;

			case SyncReport::PUSH_UPDATE:
				[$google] = $this->googleComparable($g, $defaults);
				$vcal = $this->readCalendar($contents[$uri] ?? null);
				$localGroup = $vcal === null ? null : $this->converter->groupFromCalendar($vcal, $tz);
				if ($localGroup === null) {
					throw new \RuntimeException(T::t('Could not read Nextcloud events'));
				}
				$diff = $this->converter->groupDiff($google, $localGroup);
				if (EventConverter::isSame($diff)) {
					$report->unchanged++;
					if (!$ctx->preview) {
						$this->saveState($st, $col, (string)$p['gid'], $g, $uri, (string)$local[$uri]['etag'], []);
					}
					return;
				}
				$detail = trim($note . ' ' . EventConverter::describe($diff));
				if ($diff['onlyGoogle'] !== []) {
					$detail .= T::t('(%s exception occurrences exist only on Google and were left unchanged)', [count($diff['onlyGoogle'])]);
				}
				$report->add($action, $this->groupLabel($g), $detail);
				if ($ctx->preview) {
					return;
				}
				$gid = (string)$p['gid'];
				if ($diff['master'] !== []) {
					$body = $this->converter->toGoogle($localGroup['master'], $diff['master'], $tz, true);
					$this->google->request($ctx, 'PATCH', GoogleClient::CALENDAR . $calPath . '/' . rawurlencode($gid), ['sendUpdates' => 'none'], $body);
				}
				$this->finishPush($ctx, $col, $st, $gid, $g, $uri, (string)$local[$uri]['etag'], $localGroup, $diff['changed'] + array_fill_keys($diff['onlyLocal'], EventConverter::OVERRIDE_FIELDS), $calPath, $tz, $report);
				return;

			case SyncReport::PUSH_CREATE:
				$vcal = $this->readCalendar($contents[$uri] ?? null);
				$localGroup = $vcal === null ? null : $this->converter->groupFromCalendar($vcal, $tz);
				[$masterEvent] = $vcal === null ? [null] : $this->converter->localEvents($vcal, $tz);
				if ($localGroup === null || $masterEvent === null || $localGroup['master']['start'] === null) {
					$report->add(SyncReport::SKIP, $uri, T::t('Event without a start time'));
					return;
				}
				$uid = $this->googleUid((string)$masterEvent->UID, $ncId);
				$report->add($action, $localGroup['master']['summary'] . ' ' . $this->dateLabel($localGroup['master']['start']), $note);
				if ($ctx->preview) {
					return;
				}
				$body = $this->converter->toGoogle($localGroup['master'], EventConverter::FIELDS, $tz, false);
				$body['iCalUID'] = $uid;
				$created = $this->google->request($ctx, 'POST', GoogleClient::CALENDAR . $calPath . '/import', [], $body);
				$gid = (string)$created['id'];
				$createdUid = (string)($created['iCalUID'] ?? $uid);
				$this->finishPush($ctx, $col, $st, $gid, [
					'uid' => $createdUid,
					'fp' => '',
					'updated' => isset($created['updated']) ? (int)strtotime((string)$created['updated']) : 0,
				], $uri, (string)$local[$uri]['etag'], $localGroup, array_fill_keys(array_keys($localGroup['overrides']), EventConverter::OVERRIDE_FIELDS), $calPath, $tz, $report);
				return;
		}
	}

	/**
	 * Uploads exception occurrences and stores the result. Failed keys stay
	 * pending and the Nextcloud etag is not moved, so the next run uploads them
	 * again. Stopping the sync token is not enough: the master already on Google
	 * would win the next conflict and the pull would delete the local occurrences.
	 *
	 * @param array<string, mixed> $fallback group stored when a fresh read does not finish
	 * @param array{master: array<string, mixed>, overrides: array<string, array<string, mixed>>} $localGroup
	 * @param array<string, list<string>> $fieldsByKey
	 */
	private function finishPush(SyncContext $ctx, Collection $col, ?ObjectState $st, string $gid, array $fallback, string $uri, string $etag, array $localGroup, array $fieldsByKey, string $calPath, string $tz, SyncReport $report): void {
		$failed = [];
		try {
			$failed = $this->pushOverrides($ctx, $calPath, $gid, $localGroup, $fieldsByKey, $tz, $report, $failed);
		} catch (GoogleApiException $e) {
			if ($e->isRateLimit() && $failed !== []) {
				$this->saveState($st, $col, $gid, $fallback, $uri, $etag, $failed);
			}
			throw $e;
		}
		try {
			$fresh = $this->fetchGroup($ctx, $col->getGoogleId(), $gid, (string)$fallback['uid']);
		} catch (GoogleApiException $e) {
			if ($failed !== []) {
				$this->saveState($st, $col, $gid, $fallback, $uri, $etag, $failed);
			}
			throw $e;
		}
		$this->saveState($st, $col, $gid, $fresh, $uri, $etag, $failed);
	}

	/**
	 * @param array{master: array<string, mixed>, overrides: array<string, array<string, mixed>>} $localGroup
	 * @param array<string, list<string>> $fieldsByKey
	 * @param list<string> $failed keys that were not uploaded; still filled when a rate limit is thrown
	 * @return list<string>
	 */
	private function pushOverrides(SyncContext $ctx, string $calPath, string $gid, array $localGroup, array $fieldsByKey, string $tz, SyncReport $report, array &$failed): array {
		$failed = [];
		foreach ($fieldsByKey as $key => $fields) {
			$body = $this->converter->toGoogle($localGroup['overrides'][$key], $fields, $tz, true);
			if ($body === []) {
				continue;
			}
			try {
				$this->google->request($ctx, 'PATCH', GoogleClient::CALENDAR . $calPath . '/' . rawurlencode(EventConverter::instanceId($gid, (string)$key)), ['sendUpdates' => 'none'], $body);
			} catch (GoogleApiException $e) {
				$failed[] = (string)$key;
				if ($e->isRateLimit()) {
					throw $e;
				}
				if ($e->isNotFound()) {
					$report->error(T::t('Could not find occurrence %s of a repeating event on Google, so it was not uploaded', [$key]));
				} else {
					$report->error(T::t('Could not upload occurrence %1$s of a repeating event: %2$s', [$key, $e->getMessage()]));
				}
			}
		}
		return $failed;
	}

	/**
	 * Strips the "<calendar id>-" prefix Google Synchronization put in front of
	 * the iCalUID.
	 */
	private function googleUid(string $uid, int $ncId): string {
		$prefix = $ncId . '-';
		return str_starts_with($uid, $prefix) && strlen($uid) > strlen($prefix) ? substr($uid, strlen($prefix)) : $uid;
	}

	/**
	 * Stores Google title/color on the collection row. Does not touch the
	 * Nextcloud calendar; call syncAppearance for that.
	 *
	 * @param array<string, mixed> $entry
	 */
	public function storeGoogleMeta(Collection $col, array $entry, bool $persist): void {
		$googleName = (string)($entry['summaryOverride'] ?? $entry['summary'] ?? $col->getGoogleId());
		$googleColor = isset($entry['backgroundColor']) ? (string)$entry['backgroundColor'] : null;
		$changed = $googleName !== $col->getDisplayName()
			|| self::normalizeColor($col->getColor()) !== self::normalizeColor($googleColor);
		$col->setDisplayName($googleName);
		$col->setColor($googleColor);
		if ($persist && $changed && $col->getId() !== null) {
			$this->collections->update($col);
		}
	}

	/**
	 * Writes Google name/color onto the Nextcloud calendar when the local
	 * calendar still matches the last value we applied, or when forced.
	 *
	 * @param array<string, mixed>|null $calendar
	 */
	public function syncAppearance(Collection $col, ?array $calendar, bool $preview, bool $forceName = false, bool $forceColor = false): void {
		if ($preview || !is_array($calendar)) {
			return;
		}
		$this->appearance->apply($col, $calendar, self::calendarDisplayName($col), self::normalizeColor($col->getColor()), $forceName, $forceColor);
	}

	/**
	 * Forces the Nextcloud calendar name and/or color to the stored Google values.
	 */
	public function matchGoogle(Collection $col, bool $name, bool $color): void {
		$cal = $this->appearance->linked($col, $col->getNcId());
		if ($cal === null) {
			throw new \RuntimeException(T::t('This calendar is not linked to Nextcloud yet.'));
		}
		$this->syncAppearance($col, $cal, false, $name, $color);
	}

	public function syncAppearanceFor(Collection $col): void {
		$this->syncAppearance($col, $this->appearance->linked($col, $col->getNcId()), false);
	}

	/**
	 * @return array{nameCustomized: bool, colorCustomized: bool}
	 */
	public function appearanceFlags(Collection $col): array {
		return $this->appearance->flags(
			$this->appearance->linked($col, $col->getNcId()),
			self::calendarDisplayName($col),
			self::normalizeColor($col->getColor()),
		);
	}

	private function resolveCalendar(Collection $col, SyncContext $ctx, SyncReport $report): int {
		$principal = 'principals/users/' . $col->getUserId();
		$id = $col->getNcId();
		if ($id > 0) {
			$cal = $this->caldav->getCalendarById($id);
			if ($cal !== null && !self::isInTrash($cal) && ($cal['principaluri'] ?? '') === $principal) {
				$this->syncAppearance($col, $cal, $ctx->preview);
				return $id;
			}
		}
		$uri = self::stableUri($col->getGoogleId());
		$cal = $this->caldav->getCalendarByUri($principal, $uri);
		if ($cal !== null && !self::isInTrash($cal)) {
			$newId = (int)$cal['id'];
			$this->syncAppearance($col, $cal, $ctx->preview);
		} else {
			if ($ctx->preview) {
				$report->lines[] = '[' . T::t('New calendar') . '] ' . self::calendarDisplayName($col);
				return 0;
			}
			if ($cal !== null) {
				$uri .= '-' . time();
			}
			$desiredName = self::calendarDisplayName($col);
			$desiredColor = self::normalizeColor($col->getColor());
			$props = [
				'{DAV:}displayname' => $desiredName,
				'components' => 'VEVENT',
			];
			if ($desiredColor !== '') {
				$props['{http://apple.com/ns/ical/}calendar-color'] = $desiredColor;
			}
			$newId = (int)$this->caldav->createCalendar($principal, $uri, $props);
			$col->setAppliedName($desiredName);
			$col->setAppliedColor($desiredColor !== '' ? $desiredColor : null);
		}
		if (!$ctx->preview && $newId !== $col->getNcId()) {
			$this->states->deleteByCollection($col->getId());
			$col->setNcId($newId);
			$col->setInitialized(0);
			$col->setSyncToken(null);
			$this->collections->update($col);
		} elseif (!$ctx->preview && ($col->getAppliedName() !== null || $col->getAppliedColor() !== null)) {
			$this->collections->update($col);
		}
		return $newId;
	}

	public static function calendarDisplayName(Collection $col): string {
		$name = trim($col->getDisplayName());
		if ($name === '') {
			$name = T::t('Calendars');
		}
		$suffix = ' (Google Calendar)';
		if (str_ends_with($name, $suffix)) {
			return mb_substr($name, 0, 255);
		}
		return mb_substr($name, 0, 255 - mb_strlen($suffix)) . $suffix;
	}

	public static function normalizeColor(?string $color): string {
		return Appearance::normalizeColor($color);
	}

	private static function isInTrash(array $calendar): bool {
		return CalendarAppearance::isInTrash($calendar);
	}

	/**
	 * @return array{list<array<string, mixed>>, ?string}
	 */
	private function listEvents(SyncContext $ctx, string $calId, ?string $syncToken): array {
		$query = ['maxResults' => 2500];
		if ($syncToken !== null) {
			$query['syncToken'] = $syncToken;
		}
		[$items, $last] = $this->google->listAll($ctx, GoogleClient::CALENDAR . 'calendars/' . rawurlencode($calId) . '/events', $query, 'items');
		return [$items, isset($last['nextSyncToken']) ? (string)$last['nextSyncToken'] : null];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function fetchGroup(SyncContext $ctx, string $calId, string $gid, string $uid): array {
		$base = GoogleClient::CALENDAR . 'calendars/' . rawurlencode($calId) . '/events';
		$master = null;
		if ($uid === '') {
			$master = $this->getEvent($ctx, $base, $gid);
			if ($master === null) {
				return self::deletedGroup();
			}
			$uid = (string)$master['iCalUID'];
		}
		$exceptions = [];
		[$items] = $this->google->listAll($ctx, $base, ['iCalUID' => $uid, 'maxResults' => 2500], 'items');
		foreach ($items as $item) {
			if (($item['id'] ?? '') === $gid) {
				$master = $item;
			} elseif (($item['recurringEventId'] ?? '') === $gid) {
				$exceptions[] = $item;
			}
		}
		$master ??= $this->getEvent($ctx, $base, $gid);
		if ($master === null || ($master['status'] ?? '') === 'cancelled') {
			return self::deletedGroup();
		}
		return $this->makeGroup($master, $exceptions);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function getEvent(SyncContext $ctx, string $base, string $gid): ?array {
		try {
			$event = $this->google->request($ctx, 'GET', $base . '/' . rawurlencode($gid));
		} catch (GoogleApiException $e) {
			if ($e->isNotFound()) {
				return null;
			}
			throw $e;
		}
		return ($event['status'] ?? '') === 'cancelled' ? null : $event;
	}

	/**
	 * @param array<string, mixed> $master
	 * @param list<array<string, mixed>> $exceptions
	 * @return array<string, mixed>
	 */
	private function makeGroup(array $master, array $exceptions): array {
		$tags = [($master['id'] ?? '') . ':' . ($master['etag'] ?? '')];
		$updated = isset($master['updated']) ? strtotime((string)$master['updated']) : 0;
		foreach ($exceptions as $e) {
			$tags[] = ($e['id'] ?? '') . ':' . ($e['etag'] ?? '');
			$updated = max($updated, isset($e['updated']) ? (int)strtotime((string)$e['updated']) : 0);
		}
		sort($tags);
		$type = (string)($master['eventType'] ?? 'default');
		return [
			'deleted' => false,
			'master' => $master,
			'exceptions' => $exceptions,
			'fp' => sha1(implode("\n", $tags)),
			'updated' => (int)$updated,
			'uid' => (string)($master['iCalUID'] ?? ''),
			// Birthdays, Gmail events, out of office... only allow a few fields to change
			'readonly' => $type !== 'default',
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function deletedGroup(): array {
		return ['deleted' => true, 'master' => null, 'exceptions' => [], 'fp' => '', 'updated' => 0, 'uid' => '', 'readonly' => false];
	}

	/**
	 * @param array<string, mixed> $g
	 * @param list<array{method?: string, minutes?: int}> $defaults
	 * @return array{0: array{master: array<string, mixed>, overrides: array<string, array<string, mixed>>}, 1: array<string, array<string, mixed>>}
	 */
	private function googleComparable(array $g, array $defaults): array {
		$raw = [];
		foreach ($g['exceptions'] as $e) {
			$key = is_array($e['originalStartTime'] ?? null) ? $this->converter->googleKey($e['originalStartTime']) : null;
			if ($key !== null) {
				$raw[$key] = $e;
			}
		}
		return [$this->converter->groupFromGoogle($g['master'], $g['exceptions'], $defaults), $raw];
	}

	/**
	 * @param array<string, mixed> $g
	 * @param list<string>|null $pendingOverrides null leaves extra alone; a list is the push result
	 */
	private function saveState(?ObjectState $st, Collection $col, string $gid, array $g, string $uri, string $etag, ?array $pendingOverrides = null): void {
		$st ??= new ObjectState();
		$pushed = $pendingOverrides === null ? null : SyncDecision::overridePushState($pendingOverrides);
		$st->setCollectionId($col->getId());
		$st->setGoogleId($gid);
		$st->setGoogleUid((string)$g['uid']);
		$st->setGoogleFp((string)$g['fp']);
		$st->setGoogleUpdated((int)$g['updated']);
		$st->setNcUri($uri);
		if ($pushed === null || !$pushed['keepEtag']) {
			$st->setNcEtag($etag);
		} elseif ($st->getId() === null) {
			// No etag was stored yet. An empty one cannot match the local object,
			// so the next run still sees it as changed.
			$st->setNcEtag('');
		}
		$st->setIgnored(0);
		$st->setLastSync(time());
		if ($pushed !== null) {
			$st->setExtra($pushed['extra']);
		}
		if ($st->getId() === null) {
			$this->states->insert($st);
		} else {
			$this->states->update($st);
		}
	}

	/**
	 * @return list<string>
	 */
	private function pendingOverrideKeys(?ObjectState $st): array {
		return SyncDecision::pendingOverrideKeys($st === null ? null : $st->getExtra());
	}

	/**
	 * @param list<array<string, mixed>> $plan
	 * @return array<string, string> calendar data by URI
	 */
	private function loadContents(int $ncId, array $plan): array {
		if ($ncId === 0) {
			return [];
		}
		$uris = [];
		foreach ($plan as $p) {
			if (in_array($p['action'], [SyncReport::ADOPT, SyncReport::PULL_UPDATE, SyncReport::PUSH_UPDATE, SyncReport::PUSH_CREATE, SyncReport::PULL_DELETE], true)) {
				$uris[] = (string)$p['uri'];
			}
		}
		$out = [];
		foreach (array_chunk($uris, 200) as $chunk) {
			foreach ($this->caldav->getMultipleCalendarObjects($ncId, $chunk) as $obj) {
				$out[(string)$obj['uri']] = (string)$obj['calendardata'];
			}
		}
		return $out;
	}

	private function readCalendar(?string $data): ?VCalendar {
		if ($data === null || $data === '') {
			return null;
		}
		try {
			$vcal = Reader::read($data, Reader::OPTION_FORGIVING);
		} catch (\Throwable) {
			return null;
		}
		return $vcal instanceof VCalendar ? $vcal : null;
	}

	/**
	 * @param array<string, mixed> $g
	 */
	private function groupLabel(array $g): string {
		$m = $g['master'] ?? [];
		$start = is_array($m['start'] ?? null) ? ($m['start']['date'] ?? substr((string)($m['start']['dateTime'] ?? ''), 0, 10)) : '';
		return trim((string)($m['summary'] ?? T::t('(no title)')) . ' ' . $start);
	}

	/**
	 * @param array{date: string}|array{ts: int, tz: ?string} $start
	 */
	private function dateLabel(array $start): string {
		return $start['date'] ?? date('Y-m-d', $start['ts']);
	}

	/**
	 * @param array<string, string> $contents
	 */
	private function localLabel(string $uri, array $contents): string {
		$vcal = $this->readCalendar($contents[$uri] ?? null);
		$summary = $vcal !== null && isset($vcal->VEVENT) ? (string)($vcal->VEVENT->SUMMARY ?? '') : '';
		return $summary !== '' ? $summary : $uri;
	}

	/**
	 * @param array<string, mixed> $p
	 * @param array<string, string> $contents
	 */
	private function label(array $p, array $contents): string {
		if (is_array($p['group'] ?? null) && !($p['group']['deleted'] ?? true)) {
			return $this->groupLabel($p['group']);
		}
		return $this->localLabel((string)$p['uri'], $contents);
	}
}
