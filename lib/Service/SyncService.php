<?php

declare(strict_types=1);

namespace OCA\GoogleTwoWaySync\Service;

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
			$cols[] = [
				'id' => $col->getId(),
				'kind' => $col->getKind(),
				'name' => $col->getDisplayName(),
				'color' => $col->getColor(),
				'writable' => $col->isWritable(),
				'enabled' => $col->getEnabled() === 1,
				'legacyJob' => $this->legacyImportRuns($col),
				'lastSync' => $col->getLastSync(),
				'lastSummary' => $col->getLastSummary(),
				'lastError' => $col->getLastError(),
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
			$col->setDisplayName((string)($entry['summaryOverride'] ?? $entry['summary'] ?? $googleId));
			$col->setAccessRole((string)($entry['accessRole'] ?? ''));
			$col->setColor(isset($entry['backgroundColor']) ? (string)$entry['backgroundColor'] : null);
			if ($isNew) {
				$col->setEnabled(0);
				$col->setInitialized(0);
				$col->setLastSync(0);
				$col->setNcId($this->calendars->existingCalendarId($userId, $googleId));
				$this->collections->insert($col);
			} else {
				$this->collections->update($col);
			}
		}
		if ($this->collections->findByGoogleId($userId, Collection::KIND_CONTACTS, self::CONTACTS_ID) === null) {
			$col = new Collection();
			$col->setUserId($userId);
			$col->setKind(Collection::KIND_CONTACTS);
			$col->setGoogleId(self::CONTACTS_ID);
			$col->setGoogleHash(CollectionMapper::hash(self::CONTACTS_ID));
			$col->setDisplayName('구글 연락처');
			$col->setAccessRole('owner');
			$col->setEnabled(0);
			$col->setInitialized(0);
			$col->setLastSync(0);
			$col->setNcId($this->contacts->defaultAddressBook($userId));
			$this->collections->insert($col);
		}
		if ($this->tokens->isConnected($userId) && $this->tokens->hasTasksScope($userId)) {
			try {
				$this->refreshTaskLists($ctx);
			} catch (GoogleApiException $e) {
				if ($e->getStatus() !== 403) {
					throw $e;
				}
				throw new \RuntimeException('할 일 목록을 가져오지 못했습니다. Google Cloud에서 Tasks API를 켜고 구글 연결을 다시 누르세요.', 0, $e);
			}
		}
		return $this->state($userId);
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
		if (!$preview && !$this->tokens->isConnected($userId)) {
			throw new AuthException('구글 계정을 먼저 연결하세요.');
		}
		$path = Application::APP_ID . '/' . $userId;
		$this->locking->acquireLock($path, ILockingProvider::LOCK_EXCLUSIVE);
		try {
			$ctx = new SyncContext($userId, $preview, $legacy, $allowMassDelete);
			return $this->syncLocked($ctx, $collectionId);
		} finally {
			$this->locking->releaseLock($path, ILockingProvider::LOCK_EXCLUSIVE);
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
				$report->error('Google Synchronization이 켜져 있어 건너뜁니다. 그 앱을 끄거나, occ googletwowaysync:cutover 로 가져오기 작업을 지우세요.');
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
						throw new \RuntimeException('구글 캘린더 목록에 없는 캘린더입니다.');
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
					throw new \RuntimeException('알 수 없는 동기화 종류입니다.');
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
			$col->setUserId($ctx->userId);
			$col->setKind(Collection::KIND_TASKS);
			$col->setGoogleId($googleId);
			$col->setGoogleHash(CollectionMapper::hash($googleId));
			$col->setDisplayName((string)($list['title'] ?? '할 일'));
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
			}
		}
		foreach ($this->collections->findByUser($ctx->userId) as $col) {
			if ($col->getKind() !== Collection::KIND_TASKS || isset($seen[$col->getGoogleId()])) {
				continue;
			}
			$col->setEnabled(0);
			$col->setLastError('구글 할 일 목록에서 이 목록을 찾지 못했습니다.');
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
		throw new AuthException('구글 계정을 먼저 연결하세요.');
	}
}
