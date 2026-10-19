<?php

declare(strict_types=1);

namespace OCA\GoogleTwoWaySync\Service;

use OCA\GoogleTwoWaySync\L10n\T;

use OCA\GoogleTwoWaySync\AppInfo\Application;
use OCA\GoogleTwoWaySync\Db\Collection;
use OCA\GoogleTwoWaySync\Db\CollectionMapper;
use OCA\GoogleTwoWaySync\Exception\AuthException;
use OCA\GoogleTwoWaySync\Exception\GoogleApiException;
use OCP\App\IAppManager;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

class SyncService {
	public const CONTACTS_ID = 'people/me';

	public function __construct(
		private CollectionMapper $collections,
		private CalendarSyncService $calendars,
		private ContactSyncService $contacts,
		private TaskSyncService $tasks,
		private GoogleClient $google,
		private TokenService $tokens,
		private ILockingProvider $locking,
		private IAppManager $appManager,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function state(string $userId): array {
		$cols = [];
		foreach ($this->collections->findByUser($userId) as $col) {
			$flags = match ($col->getKind()) {
				Collection::KIND_CALENDAR => $this->calendars->appearanceFlags($col),
				Collection::KIND_TASKS => $this->tasks->appearanceFlags($col),
				default => ['nameCustomized' => false, 'colorCustomized' => false],
			};
			$cols[] = [
				'id' => $col->getId(),
				'kind' => $col->getKind(),
				'name' => $col->getDisplayName(),
				'color' => $col->getKind() === Collection::KIND_TASKS
					? TaskSyncService::DEFAULT_COLOR
					: $col->getColor(),
				'writable' => $col->isWritable(),
				'enabled' => $col->getEnabled() === 1,
				'legacyJob' => $this->legacyImportRuns($col),
				'lastSync' => $col->getLastSync(),
				'lastSummary' => $col->getLastSummary(),
				'lastError' => $col->getLastError(),
				'nameCustomized' => $flags['nameCustomized'],
				'colorCustomized' => $flags['colorCustomized'],
			];
		}
		return [
			'connected' => $this->tokens->isConnected($userId),
			'legacyAppEnabled' => $this->legacyAppEnabled(),
			'legacyToken' => $this->tokens->hasLegacyToken($userId),
			'redirectUri' => $this->tokens->getRedirectUri(),
			'tasksScope' => $this->tokens->hasTasksScope($userId),
			'collections' => $cols,
		];
	}

	public function hasLegacyJob(Collection $col): bool {
		return match ($col->getKind()) {
			Collection::KIND_CALENDAR => $this->calendars->hasLegacyJob($col),
			Collection::KIND_CONTACTS => $this->contacts->hasLegacyJob($col),
			default => false,
		};
	}

	/**
	 * The old import only runs while Google Synchronization is enabled. With
	 * that app disabled its jobs stay in the database but cron does not load
	 * them, so this app can sync the same calendars and address book.
	 */
	public function legacyImportRuns(Collection $col): bool {
		return $this->legacyAppEnabled() && $this->hasLegacyJob($col);
	}

	private function legacyAppEnabled(): bool {
		return $this->appManager->isEnabledForUser(Application::LEGACY_APP_ID);
	}

	/**
	 * Reads the calendar list from Google and adds the contacts collection.
	 * Without a connection of its own, the read-only token of Google
	 * Synchronization is used, so that previews work before the switch.
	 *
	 * @return array<string, mixed>
	 */
	public function refreshCollections(string $userId): array {
		$ctx = $this->readContext($userId);
		foreach ($this->calendarList($ctx) as $entry) {
			$googleId = (string)$entry['id'];
			$col = $this->collections->findByGoogleId($userId, Collection::KIND_CALENDAR, $googleId);
			$isNew = $col === null;
			$col ??= new Collection();
			$col->setUserId($userId);
			$col->setKind(Collection::KIND_CALENDAR);
			$col->setGoogleId($googleId);
			$col->setGoogleHash(CollectionMapper::hash($googleId));
			$col->setAccessRole((string)($entry['accessRole'] ?? ''));
			$this->calendars->storeGoogleMeta($col, $entry, false);
			if ($isNew) {
				$col->setEnabled(0);
				$col->setInitialized(0);
				$col->setLastSync(0);
				$col->setNcId($this->calendars->existingCalendarId($userId, $googleId));
				$this->collections->insert($col);
			} else {
				$this->collections->update($col);
			}
			$this->calendars->syncAppearanceFor($col);
		}
		$contacts = $this->collections->findByGoogleId($userId, Collection::KIND_CONTACTS, self::CONTACTS_ID);
		if ($contacts === null) {
			$col = new Collection();
			$col->setUserId($userId);
			$col->setKind(Collection::KIND_CONTACTS);
			$col->setGoogleId(self::CONTACTS_ID);
			$col->setGoogleHash(CollectionMapper::hash(self::CONTACTS_ID));
			$col->setDisplayName(T::t('Google contacts'));
			$col->setAccessRole('owner');
			$col->setEnabled(0);
			$col->setInitialized(0);
			$col->setLastSync(0);
			$col->setNcId($this->contacts->defaultAddressBook($userId));
			$this->collections->insert($col);
		} else {
			$abId = $this->contacts->resolveAddressBookId($userId, $contacts->getNcId());
			if ($abId > 0 && $abId !== $contacts->getNcId()) {
				$contacts->setNcId($abId);
				$this->collections->update($contacts);
			}
		}
		if ($this->tokens->isConnected($userId) && $this->tokens->hasTasksScope($userId)) {
			try {
				$this->refreshTaskLists($ctx);
			} catch (GoogleApiException $e) {
				if ($e->getStatus() !== 403) {
					throw $e;
				}
				throw new \RuntimeException(T::t('Could not load task lists. Enable the Tasks API in Google Cloud and connect Google again.'), 0, $e);
			}
		}
		return $this->state($userId);
	}

	/**
	 * Overwrites the Nextcloud calendar title and/or color with the stored Google values.
	 *
	 * @return array<string, mixed>
	 */
	public function matchGoogleAppearance(string $userId, int $collectionId, bool $name, bool $color): array {
		if (!$name && !$color) {
			throw new \InvalidArgumentException(T::t('Choose a name or a color to match.'));
		}
		$col = $this->collections->findForUser($userId, $collectionId);
		if ($col === null) {
			throw new \InvalidArgumentException(T::t('Unknown item: %s', [(string)$collectionId]));
		}
		if ($col->getKind() === Collection::KIND_CALENDAR) {
			$this->calendars->matchGoogle($col, $name, $color);
		} elseif ($col->getKind() === Collection::KIND_TASKS) {
			$this->tasks->matchGoogle($col, $name, $color);
		} else {
			throw new \InvalidArgumentException(T::t('Only calendars and task lists have a name and color.'));
		}
		$state = $this->state($userId);
		$state['message'] = T::t('Matched to Google.');
		return $state;
	}

	/**
	 * @param list<int> $enabledIds
	 */
	public function saveSelection(string $userId, array $enabledIds): void {
		foreach ($this->collections->findByUser($userId) as $col) {
			$enabled = in_array($col->getId(), $enabledIds, true) ? 1 : 0;
			if ($col->getEnabled() !== $enabled) {
				$col->setEnabled($enabled);
				$this->collections->update($col);
			}
		}
	}

	/**
	 * Enables the collections Google Synchronization currently imports.
	 *
	 * @return list<string> names of the enabled collections
	 */
	public function enableLegacy(string $userId): array {
		$names = [];
		foreach ($this->collections->findByUser($userId) as $col) {
			if ($this->hasLegacyJob($col)) {
				if ($col->getEnabled() !== 1) {
					$col->setEnabled(1);
					$this->collections->update($col);
				}
				$names[] = $col->getDisplayName();
			}
		}
		return $names;
	}

	/**
	 * Removes the Google Synchronization jobs of the enabled collections, after
	 * which their real sync is allowed.
	 *
	 * @return list<string>
	 */
	public function cutover(string $userId, ?int $collectionId = null): array {
		$done = [];
		foreach ($this->collections->findByUser($userId) as $col) {
			if ($col->getEnabled() !== 1 || ($collectionId !== null && $col->getId() !== $collectionId)) {
				continue;
			}
			$removed = match ($col->getKind()) {
				Collection::KIND_CALENDAR => $this->calendars->removeLegacyJob($col),
				Collection::KIND_CONTACTS => $this->contacts->removeLegacyJob($col),
				default => false,
			};
			if ($removed) {
				$done[] = $col->getDisplayName();
			}
		}
		return $done;
	}

	public function syncAll(): void {
		foreach ($this->collections->findUserIdsToSync() as $userId) {
			if (!$this->tokens->isConnected($userId)) {
				continue;
			}
			try {
				$this->syncUser($userId);
			} catch (LockedException) {
				// A sync started from the settings page is still running
			} catch (\Throwable $e) {
				$this->logger->warning('Google two-way sync failed for ' . $userId . ': ' . $e->getMessage(), [
					'app' => Application::APP_ID,
					'exception' => $e,
				]);
			}
		}
	}

	/**
	 * @return array{reports: list<array<string, mixed>>, requests: int, preview: bool}
	 */
	public function syncUser(string $userId, bool $preview = false, bool $legacy = false, bool $allowMassDelete = false, ?int $collectionId = null): array {
		T::forUser($userId);
		try {
		if (!$preview && !$this->tokens->isConnected($userId)) {
			throw new AuthException(T::t('Connect a Google account first.'));
		}
		$path = Application::APP_ID . '/' . $userId;
		$this->locking->acquireLock($path, ILockingProvider::LOCK_EXCLUSIVE);
		try {
			$ctx = new SyncContext($userId, $preview, $legacy, $allowMassDelete);
			return $this->syncLocked($ctx, $collectionId);
		} finally {
			$this->locking->releaseLock($path, ILockingProvider::LOCK_EXCLUSIVE);
		}
			} finally {
			T::forUser(null);
		}
	}

	/**
	 * @return array{reports: list<array<string, mixed>>, requests: int, preview: bool}
	 */
	private function syncLocked(SyncContext $ctx, ?int $collectionId): array {
		$cols = array_values(array_filter(
			$this->collections->findByUser($ctx->userId),
			static fn (Collection $c) => $collectionId !== null ? $c->getId() === $collectionId : $c->getEnabled() === 1,
		));
		$entries = null;
		$reports = [];
		foreach ($cols as $col) {
			if (!$ctx->preview && $this->legacyImportRuns($col)) {
				$report = new SyncReport($col->getDisplayName());
				$report->error(T::t('Skipped because Google Synchronization is enabled. Turn that app off, or remove its import jobs with occ googletwowaysync:cutover.'));
				$reports[] = $this->withKind($report, $col);
				continue;
			}
			try {
				if ($col->getKind() === Collection::KIND_CALENDAR) {
					if ($entries === null) {
						$entries = [];
						foreach ($this->calendarList($ctx) as $entry) {
							$entries[(string)$entry['id']] = $entry;
						}
					}
					if (!isset($entries[$col->getGoogleId()])) {
						throw new \RuntimeException(T::t('This calendar is not in the Google calendar list.'));
					}
					$entry = $entries[$col->getGoogleId()];
					if (!$ctx->preview && (string)($entry['accessRole'] ?? '') !== $col->getAccessRole()) {
						$col->setAccessRole((string)($entry['accessRole'] ?? ''));
						$this->collections->update($col);
					}
					$report = $this->calendars->sync($col, $entry, $ctx);
				} elseif ($col->getKind() === Collection::KIND_CONTACTS) {
					$report = $this->contacts->sync($col, $ctx);
				} elseif ($col->getKind() === Collection::KIND_TASKS) {
					$report = $this->tasks->sync($col, $ctx);
				} else {
					throw new \RuntimeException(T::t('Unknown sync kind.'));
				}
			} catch (\Throwable $e) {
				$report = new SyncReport($col->getDisplayName());
				$report->error($e->getMessage());
				if (!$ctx->preview) {
					$col->setLastError($e->getMessage());
					$col->setLastSync(time());
					$this->collections->update($col);
				}
				if (!$e instanceof AuthException && !$e instanceof \OCA\GoogleTwoWaySync\Exception\GoogleApiException) {
					$this->logger->warning('Google two-way sync of ' . $col->getDisplayName() . ' failed: ' . $e->getMessage(), [
						'app' => Application::APP_ID,
						'exception' => $e,
					]);
				}
			}
			$reports[] = $this->withKind($report, $col);
		}
		return ['reports' => $reports, 'requests' => $ctx->requests, 'preview' => $ctx->preview];
	}

	/**
	 * @return array{name: string, summary: string, errors: list<string>, lines: list<string>, kind: string}
	 */
	private function withKind(SyncReport $report, Collection $col): array {
		$array = $report->toArray();
		$array['kind'] = $col->getKind();
		return $array;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function calendarList(SyncContext $ctx): array {
		$items = [];
		$query = ['maxResults' => 250];
		do {
			$result = $this->google->request($ctx, 'GET', GoogleClient::CALENDAR . 'users/me/calendarList', $query);
			foreach (($result['items'] ?? []) as $item) {
				$items[] = $item;
			}
			$query['pageToken'] = (string)($result['nextPageToken'] ?? '');
		} while ($query['pageToken'] !== '');
		return $items;
	}

	private function refreshTaskLists(SyncContext $ctx): void {
		$seen = [];
		foreach ($this->taskLists($ctx) as $list) {
			$googleId = (string)($list['id'] ?? '');
			if ($googleId === '') {
				continue;
			}
			$seen[$googleId] = true;
			$col = $this->collections->findByGoogleId($ctx->userId, Collection::KIND_TASKS, $googleId);
			$isNew = $col === null;
			$col ??= new Collection();
			$googleName = (string)($list['title'] ?? T::t('Tasks'));
			$col->setUserId($ctx->userId);
			$col->setKind(Collection::KIND_TASKS);
			$col->setGoogleId($googleId);
			$col->setGoogleHash(CollectionMapper::hash($googleId));
			$col->setDisplayName($googleName);
			$col->setAccessRole('owner');
			$col->setLastError(null);
			if ($isNew) {
				$col->setEnabled(0);
				$col->setInitialized(0);
				$col->setLastSync(0);
				$col->setNcId(0);
				$this->collections->insert($col);
			} else {
				$this->collections->update($col);
				$this->tasks->syncAppearanceFor($col);
			}
		}
		foreach ($this->collections->findByUser($ctx->userId) as $col) {
			if ($col->getKind() !== Collection::KIND_TASKS || isset($seen[$col->getGoogleId()])) {
				continue;
			}
			$col->setEnabled(0);
			$col->setLastError(T::t('This list was not found in Google Tasks.'));
			$this->collections->update($col);
		}
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function taskLists(SyncContext $ctx): array {
		$items = [];
		$query = ['maxResults' => 100];
		do {
			$result = $this->google->request($ctx, 'GET', GoogleClient::TASKS . 'users/@me/lists', $query);
			foreach (($result['items'] ?? []) as $item) {
				if (is_array($item)) {
					$items[] = $item;
				}
			}
			$query['pageToken'] = (string)($result['nextPageToken'] ?? '');
		} while ($query['pageToken'] !== '');
		return $items;
	}

	private function readContext(string $userId): SyncContext {
		if ($this->tokens->isConnected($userId)) {
			return new SyncContext($userId, false);
		}
		if ($this->tokens->hasLegacyToken($userId)) {
			return new SyncContext($userId, true, true);
		}
		throw new AuthException(T::t('Connect a Google account first.'));
	}
}
