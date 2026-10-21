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
 * one side deleted and the other edited, the edit is kept.
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
			if (isset($processed[$uri]) || isset($byUri[$uri])) {
				continue;
			}
			$plan[] = ['action' => $writable ? SyncReport::PUSH_CREATE : SyncReport::PULL_DELETE, 'uri' => $uri, 'gid' => '', 'group' => null, 'state' => null, 'note' => $writable ? '' : T::t('Deleted because the calendar is read-only')];
		}

		$plan = array_values(array_filter($plan, static function (array $p) use ($report): bool {
			if ($p['action'] === 'none') {
				$report->unchanged++;
				return false;
			}
			return true;
		}));

		$deletions = count(array_filter($plan, static fn (array $p) => in_array($p['action'], [SyncReport::PULL_DELETE, SyncReport::PUSH_DELETE], true)));
		$blockDeletes = !$ctx->allowMassDelete && SyncReport::tooManyDeletions($deletions, max(count($states), count($local)));
		if ($blockDeletes) {
			$report->error(T::t('Refused to delete %s items at once. Check them, then continue with occ googletwowaysync:sync --allow-mass-delete.', [$deletions]));
		}

		$contents = $this->loadContents($ncId, $plan);
		$pullFailed = false;
		$pushBlocked = false;
		foreach ($plan as $p) {
			$action = $p['action'];
			if ($blockDeletes && in_array($action, [SyncReport::PULL_DELETE, SyncReport::PUSH_DELETE], true)) {
				$pullFailed = true;
				continue;
			}
			$isPush = in_array($action, [SyncReport::PUSH_CREATE, SyncReport::PUSH_UPDATE, SyncReport::PUSH_DELETE], true);
			if ($isPush && $pushBlocked) {
				continue;
			}
			try {
				$this->execute($p, $col, $ncId, $tz, $defaults, $local, $contents, $ctx, $report);
			} catch (GoogleApiException $e) {
				$report->error($this->label($p, $contents) . ': ' . $e->getMessage());
				if ($e->isRateLimit()) {
					$pushBlocked = true;
				}
				if (!$isPush) {
					$pullFailed = true;
				}
			} catch (\Throwable $e) {
				$report->error($this->label($p, $contents) . ': ' . $e->getMessage());
				if (!$isPush) {
					$pullFailed = true;
				}
			}
		}

		if (!$ctx->preview) {
			// A failed pull keeps the old token (or none on the first sync), so
			// the next run lists those items again instead of skipping them.
			if (!$pullFailed) {
				$col->setSyncToken($nextToken);
			}
			$col->setInitialized(1);
			$col->setLastSync(time());
			$col->setLastSummary($report->summary());
			$col->setLastError($report->errors === [] ? null : implode("\n", array_slice($report->errors, 0, 20)));
			$this->collections->update($col);
		}
		return $report;
	}

	/**
	 * @param array<string, mixed>|null $obj local object row
	 * @param array<string, mixed> $g
	 * @return array<string, mixed>
	 */
	private function decide(ObjectState $st, array $g, ?array $obj, bool $googleChanged, bool $writable): array {
		$p = ['uri' => $st->getNcUri(), 'gid' => $st->getGoogleId(), 'group' => $g, 'state' => $st, 'note' => ''];
		$eventWritable = $writable && !($g['readonly'] ?? false);
		$localChanged = $obj !== null && $obj['etag'] !== $st->getNcEtag();
		if ($obj === null) {
			if ($g['deleted']) {
				return $p + ['action' => 'forget'];
			}
			if ($googleChanged || !$eventWritable) {
				return ['action' => SyncReport::PULL_CREATE, 'note' => $googleChanged ? T::t('Restored because Google changed it') : T::t('Restored because the calendar is read-only')] + $p;
			}
			return ['action' => SyncReport::PUSH_DELETE] + $p;
		}
		if ($g['deleted']) {
			if ($localChanged && $writable) {
				return ['action' => SyncReport::PUSH_CREATE, 'note' => T::t('Uploaded again because Nextcloud changed it after Google deleted it')] + $p;
			}
			return ['action' => SyncReport::PULL_DELETE] + $p;
		}
		if ($googleChanged && $localChanged) {
			if (!$eventWritable || $g['updated'] >= (int)$obj['lastmodified']) {
				return ['action' => SyncReport::PULL_UPDATE, 'note' => T::t('Both sides changed; Google is newer')] + $p;
			}
			return ['action' => SyncReport::PUSH_UPDATE, 'note' => T::t('Both sides changed; Nextcloud is newer')] + $p;
		}
		if ($googleChanged) {
			return ['action' => SyncReport::PULL_UPDATE] + $p;
		}
		if ($localChanged) {
			return $eventWritable
				? ['action' => SyncReport::PUSH_UPDATE] + $p
				: ['action' => SyncReport::PULL_UPDATE, 'note' => T::t('Reverted because the calendar is read-only')] + $p;
		}
		return ['action' => 'none'] + $p;
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
				if (!$same && $this->converter->mergeIntoCalendar($vcal, $google, $g['master'], $overridesRaw, $tz)) {
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
						$this->saveState($st, $col, (string)$p['gid'], $g, $uri, (string)$local[$uri]['etag']);
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
				$this->pushOverrides($ctx, $calPath, $gid, $localGroup, $diff['changed'] + array_fill_keys($diff['onlyLocal'], EventConverter::OVERRIDE_FIELDS), $tz, $report);
				$fresh = $this->fetchGroup($ctx, $col->getGoogleId(), $gid, (string)$g['uid']);
				$this->saveState($st, $col, $gid, $fresh, $uri, (string)$local[$uri]['etag']);
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
				$this->pushOverrides($ctx, $calPath, $gid, $localGroup, array_fill_keys(array_keys($localGroup['overrides']), EventConverter::OVERRIDE_FIELDS), $tz, $report);
				$fresh = $this->fetchGroup($ctx, $col->getGoogleId(), $gid, (string)($created['iCalUID'] ?? $uid));
				$this->saveState($st, $col, $gid, $fresh, $uri, (string)$local[$uri]['etag']);
				return;
		}
	}

	/**
	 * @param array{master: array<string, mixed>, overrides: array<string, array<string, mixed>>} $localGroup
	 * @param array<string, list<string>> $fieldsByKey
	 */
	private function pushOverrides(SyncContext $ctx, string $calPath, string $gid, array $localGroup, array $fieldsByKey, string $tz, SyncReport $report): void {
		foreach ($fieldsByKey as $key => $fields) {
			$body = $this->converter->toGoogle($localGroup['overrides'][$key], $fields, $tz, true);
			if ($body === []) {
				continue;
			}
			try {
				$this->google->request($ctx, 'PATCH', GoogleClient::CALENDAR . $calPath . '/' . rawurlencode(EventConverter::instanceId($gid, (string)$key)), ['sendUpdates' => 'none'], $body);
			} catch (GoogleApiException $e) {
				if (!$e->isNotFound()) {
					throw $e;
				}
				$report->error(T::t('Could not find occurrence %s of a repeating event on Google, so it was not uploaded', [$key]));
			}
		}
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
	 */
	private function saveState(?ObjectState $st, Collection $col, string $gid, array $g, string $uri, string $etag): void {
		$st ??= new ObjectState();
		$st->setCollectionId($col->getId());
		$st->setGoogleId($gid);
		$st->setGoogleUid((string)$g['uid']);
		$st->setGoogleFp((string)$g['fp']);
		$st->setGoogleUpdated((int)$g['updated']);
		$st->setNcUri($uri);
		$st->setNcEtag($etag);
		$st->setIgnored(0);
		$st->setLastSync(time());
		if ($st->getId() === null) {
			$this->states->insert($st);
		} else {
			$this->states->update($st);
		}
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
