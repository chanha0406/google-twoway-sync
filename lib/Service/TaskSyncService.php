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
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;

/**
 * Two-way sync of one Google task list with its own Nextcloud calendar.
 * That calendar accepts tasks only, so it stays out of the event calendars.
 *
 * Google tasks have a date, not a time. A time set in Nextcloud is stored as
 * that date on Google and comes back as a date.
 */
class TaskSyncService {
	private const MAX_WRITES_PER_RUN = 40;

	private int $writes = 0;

	public function __construct(
		private GoogleClient $google,
		private CalDavBackend $caldav,
		private ObjectStateMapper $states,
		private CollectionMapper $collections,
	) {
	}

	public function sync(Collection $col, SyncContext $ctx): SyncReport {
		$report = new SyncReport($col->getDisplayName());
		$this->writes = 0;
		$ncId = $ctx->preview ? $this->existingCalendarId($col) : $this->ensureCalendar($col);
		$local = $ncId > 0 ? $this->localTodos($ncId) : [];
		$states = $this->states->findByCollection($col->getId());
		$initialized = $col->getInitialized() === 1 && $ncId > 0 && $ncId === $col->getNcId();
		$byUri = [];
		$byGid = [];
		foreach ($states as $st) {
			$byUri[$st->getNcUri()] = $st;
			if ($st->getGoogleId() !== '') {
				$byGid[$st->getGoogleId()] = $st;
			}
		}

		$full = !$initialized || ($col->getSyncToken() ?? '') === '';
		$tasks = [];
		foreach ($this->listTasks($ctx, $col->getGoogleId(), $full ? null : $col->getSyncToken()) as $task) {
			$id = (string)($task['id'] ?? '');
			if ($id !== '') {
				$tasks[$id] = self::entry($task);
			}
		}

		$plan = [];
		$processed = [];
		foreach ($tasks as $id => $g) {
			$id = (string)$id;
			$st = $byGid[$id] ?? null;
			if ($st === null) {
				if ($g['deleted']) {
					continue;
				}
				$uri = self::uriFor($id);
				if (isset($local[$uri])) {
					$uri .= '-' . substr(sha1($id), 0, 8);
				}
				$plan[] = ['action' => SyncReport::PULL_CREATE, 'uri' => $uri, 'gid' => $id, 'group' => $g, 'state' => null];
				continue;
			}
			$processed[$st->getNcUri()] = true;
			$plan[] = $this->decide($st, $g, $local[$st->getNcUri()] ?? null);
		}
		foreach ($states as $st) {
			$uri = $st->getNcUri();
			if ($st->getIgnored() === 1 || isset($processed[$uri])) {
				continue;
			}
			$processed[$uri] = true;
			$obj = $local[$uri] ?? null;
			if ($full) {
				$plan[] = $this->decide($st, self::deleted(), $obj);
				continue;
			}
			if ($obj !== null && $obj['etag'] === $st->getNcEtag()) {
				$report->unchanged++;
				continue;
			}
			$plan[] = $this->decide($st, $this->fetchTask($ctx, $col->getGoogleId(), $st->getGoogleId()), $obj);
		}
		foreach ($local as $uri => $obj) {
			$uri = (string)$uri;
			if (isset($processed[$uri]) || isset($byUri[$uri])) {
				continue;
			}
			if ($initialized) {
				$plan[] = ['action' => SyncReport::PUSH_CREATE, 'uri' => $uri, 'gid' => '', 'group' => null, 'state' => null];
			}
		}

		if ($ncId > 0) {
			$plannedUri = [];
			foreach ($plan as $p) {
				if (($p['action'] ?? '') !== 'none') {
					$plannedUri[(string)$p['uri']] = true;
				}
			}
			foreach ($plan as &$p) {
				if (($p['action'] ?? '') !== 'none') {
					continue;
				}
				$due = is_array($p['group'] ?? null) ? (string)($p['group']['shape']['due'] ?? '') : '';
				if ($due === '' || !$this->dueNeedsRepair($ncId, (string)$p['uri'])) {
					continue;
				}
				$p['action'] = SyncReport::PULL_UPDATE;
				$p['note'] = T::t('Repaired due date format for the calendar');
				$plannedUri[(string)$p['uri']] = true;
			}
			unset($p);
			foreach ($local as $uri => $obj) {
				$uri = (string)$uri;
				if (isset($plannedUri[$uri]) || !$this->dueNeedsRepair($ncId, $uri)) {
					continue;
				}
				$st = $byUri[$uri] ?? null;
				$gid = $st !== null ? $st->getGoogleId() : '';
				$g = ($gid !== '' && isset($tasks[$gid])) ? $tasks[$gid] : null;
				if ($g === null || ($g['shape']['due'] ?? '') === '') {
					try {
						$shape = $this->shapeFromUri($ncId, $uri);
					} catch (\Throwable) {
						continue;
					}
					if ($shape['due'] === '') {
						continue;
					}
					$g = [
						'deleted' => false,
						'shape' => $shape,
						'fp' => $st !== null ? $st->getGoogleFp() : sha1((string)json_encode($shape, JSON_UNESCAPED_UNICODE)),
						'updated' => 0,
						'parent' => '',
					];
				}
				$plan[] = [
					'action' => SyncReport::PULL_UPDATE,
					'uri' => $uri,
					'gid' => $gid,
					'group' => $g,
					'state' => $st,
					'note' => T::t('Repaired due date format for the calendar'),
				];
			}
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
			$report->error(T::t('Refused to delete %s tasks at once. Check them, then continue with occ googletwowaysync:sync --allow-mass-delete.', [$deletions]));
		}

		$failed = false;
		$pushBlocked = false;
		foreach ($plan as $p) {
			$action = $p['action'];
			if ($blockDeletes && in_array($action, [SyncReport::PULL_DELETE, SyncReport::PUSH_DELETE], true)) {
				$failed = true;
				continue;
			}
			$isPush = in_array($action, [SyncReport::PUSH_CREATE, SyncReport::PUSH_UPDATE, SyncReport::PUSH_DELETE], true);
			if ($isPush && ($pushBlocked || $this->writes >= self::MAX_WRITES_PER_RUN)) {
				if (!$pushBlocked && !$ctx->preview) {
					$pushBlocked = true;
					$report->error(T::t('The per-run write limit (%s) was reached. The rest waits for the next sync.', [self::MAX_WRITES_PER_RUN]));
				}
				if (!$ctx->preview) {
					$failed = true;
					continue;
				}
			}
			try {
				$this->execute($p, $col, $ncId, $ctx, $report);
			} catch (GoogleApiException $e) {
				$report->error($this->label($p) . ': ' . $e->getMessage());
				$failed = true;
				if ($e->isRateLimit()) {
					$pushBlocked = true;
				}
			} catch (\Throwable $e) {
				$report->error($this->label($p) . ': ' . $e->getMessage());
				$failed = true;
			}
		}

		if (!$ctx->preview && $ncId > 0) {
			if (!$failed) {
				$col->setSyncToken(gmdate('Y-m-d\TH:i:s\Z', time() - 2));
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
	 * @param array<string, mixed>|null $obj
	 * @param array<string, mixed> $g
	 * @return array<string, mixed>
	 */
	private function decide(ObjectState $st, array $g, ?array $obj): array {
		$p = ['uri' => $st->getNcUri(), 'gid' => $st->getGoogleId(), 'group' => $g, 'state' => $st, 'note' => ''];
		$googleChanged = $g['deleted'] || $g['fp'] !== $st->getGoogleFp();
		$localChanged = $obj !== null && $obj['etag'] !== $st->getNcEtag();
		if ($obj === null) {
			if ($g['deleted']) {
				return ['action' => 'forget'] + $p;
			}
			return $googleChanged
				? ['action' => SyncReport::PULL_CREATE, 'note' => T::t('Restored because Google changed it')] + $p
				: ['action' => SyncReport::PUSH_DELETE] + $p;
		}
		if ($g['deleted']) {
			return $localChanged
				? ['action' => SyncReport::PUSH_CREATE, 'note' => T::t('Uploaded again because Nextcloud changed it after Google deleted it')] + $p
				: ['action' => SyncReport::PULL_DELETE] + $p;
		}
		if ($googleChanged && $localChanged) {
			return $g['updated'] >= (int)$obj['lastmodified']
				? ['action' => SyncReport::PULL_UPDATE, 'note' => T::t('Both sides changed; Google is newer')] + $p
				: ['action' => SyncReport::PUSH_UPDATE, 'note' => T::t('Both sides changed; Nextcloud is newer')] + $p;
		}
		if ($googleChanged) {
			return ['action' => SyncReport::PULL_UPDATE] + $p;
		}
		if ($localChanged) {
			return ['action' => SyncReport::PUSH_UPDATE] + $p;
		}
		return ['action' => 'none'] + $p;
	}

	/**
	 * @param array<string, mixed> $p
	 */
	private function execute(array $p, Collection $col, int $ncId, SyncContext $ctx, SyncReport $report): void {
		$action = $p['action'];
		$uri = (string)$p['uri'];
		/** @var ?ObjectState $st */
		$st = $p['state'];
		$g = $p['group'];
		$note = (string)($p['note'] ?? '');
		$listId = $col->getGoogleId();

		switch ($action) {
			case 'forget':
				if (!$ctx->preview && $st !== null) {
					$this->states->delete($st);
				}
				return;

			case SyncReport::PULL_CREATE:
			case SyncReport::PULL_UPDATE:
				$shape = is_array($g) ? $g['shape'] : [];
				$report->add($action, (string)($shape['title'] ?? $uri), $note);
				if ($ctx->preview || $ncId <= 0) {
					return;
				}
				$etag = $this->writeTodo($ncId, $uri, $shape, (string)$p['gid']);
				$this->saveState($st, $col, (string)$p['gid'], $g, $uri, $etag);
				return;

			case SyncReport::PULL_DELETE:
				$report->add($action, $uri, $note);
				if ($ctx->preview || $ncId <= 0) {
					return;
				}
				$this->caldav->deleteCalendarObject($ncId, $uri, CalDavBackend::CALENDAR_TYPE_CALENDAR, true);
				if ($st !== null) {
					$this->states->delete($st);
				}
				return;

			case SyncReport::PUSH_DELETE:
				$report->add($action, (string)$p['gid'], $note);
				if ($ctx->preview) {
					return;
				}
				$this->writes++;
				$this->google->request($ctx, 'DELETE', GoogleClient::TASKS . 'lists/' . rawurlencode($listId) . '/tasks/' . rawurlencode((string)$p['gid']));
				if ($st !== null) {
					$this->states->delete($st);
				}
				return;

			case SyncReport::PUSH_CREATE:
			case SyncReport::PUSH_UPDATE:
				$shape = $this->shapeFromUri($ncId, $uri);
				$report->add($action, (string)$shape['title'], $note);
				if ($ctx->preview) {
					return;
				}
				$this->writes++;
				if ($action === SyncReport::PUSH_CREATE) {
					$task = $this->google->request($ctx, 'POST', GoogleClient::TASKS . 'lists/' . rawurlencode($listId) . '/tasks', [], self::body($shape, ''));
				} else {
					$parent = is_array($g) ? (string)($g['parent'] ?? '') : '';
					$task = $this->google->request($ctx, 'PATCH', GoogleClient::TASKS . 'lists/' . rawurlencode($listId) . '/tasks/' . rawurlencode((string)$p['gid']), [], self::body($shape, $parent));
				}
				$saved = self::entry($task);
				$etag = $this->writeTodo($ncId, $uri, $saved['shape'], (string)($task['id'] ?? $p['gid']));
				$this->saveState($st, $col, (string)($task['id'] ?? $p['gid']), $saved, $uri, $etag);
				return;
		}
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function listTasks(SyncContext $ctx, string $listId, ?string $updatedMin): array {
		$items = [];
		$query = [
			'maxResults' => 100,
			'showCompleted' => 'true',
			'showHidden' => 'true',
		];
		if ($updatedMin !== null && $updatedMin !== '') {
			$query['updatedMin'] = $updatedMin;
			$query['showDeleted'] = 'true';
		}
		$url = GoogleClient::TASKS . 'lists/' . rawurlencode($listId) . '/tasks';
		do {
			$result = $this->google->request($ctx, 'GET', $url, $query);
			foreach (($result['items'] ?? []) as $item) {
				if (is_array($item)) {
					$items[] = $item;
				}
			}
			$query['pageToken'] = (string)($result['nextPageToken'] ?? '');
		} while ($query['pageToken'] !== '');
		return $items;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function fetchTask(SyncContext $ctx, string $listId, string $taskId): array {
		try {
			$task = $this->google->request($ctx, 'GET', GoogleClient::TASKS . 'lists/' . rawurlencode($listId) . '/tasks/' . rawurlencode($taskId));
		} catch (GoogleApiException $e) {
			if ($e->isNotFound()) {
				return self::deleted();
			}
			throw $e;
		}
		return self::entry($task);
	}

	/**
	 * Writes the Google list title onto the Nextcloud calendar. Used only when
	 * Google renamed the list, not on every sync.
	 */
	public function applyGoogleDisplayName(Collection $col): void {
		$ncId = $this->existingCalendarId($col);
		if ($ncId <= 0) {
			return;
		}
		$calendar = $this->caldav->getCalendarById($ncId);
		$name = self::localName((string)$col->getDisplayName());
		if (!is_array($calendar) || ($calendar['{DAV:}displayname'] ?? '') === $name) {
			return;
		}
		$patch = new \Sabre\DAV\PropPatch(['{DAV:}displayname' => $name]);
		$this->caldav->updateCalendar($ncId, $patch);
		$patch->commit();
	}

	private function ensureCalendar(Collection $col): int {
		$existing = $this->existingCalendarId($col);
		$name = self::localName((string)$col->getDisplayName());
		if ($existing > 0) {
			if ($existing !== $col->getNcId()) {
				$col->setNcId($existing);
				$this->collections->update($col);
			}
			return $existing;
		}

		$principal = 'principals/users/' . $col->getUserId();
		$uri = 'google-tasks-' . substr($col->getGoogleHash(), 0, 32);
		$params = [
			'{DAV:}displayname' => $name,
			'components' => 'VTODO',
			'{http://apple.com/ns/ical/}calendar-color' => '#7986cb',
		];
		try {
			$id = (int)$this->caldav->createCalendar($principal, $uri, $params);
		} catch (\Throwable) {
			$id = (int)$this->caldav->createCalendar($principal, $uri . '-' . $col->getId(), $params);
		}
		$col->setNcId($id);
		$col->setInitialized(0);
		$col->setSyncToken(null);
		$this->collections->update($col);
		return $id;
	}

	private function existingCalendarId(Collection $col): int {
		$principal = 'principals/users/' . $col->getUserId();
		$existing = (int)$col->getNcId();
		if ($existing > 0) {
			$calendar = $this->caldav->getCalendarById($existing);
			if (is_array($calendar)
				&& ($calendar['{http://owncloud.org/ns}deleted-at'] ?? null) === null
				&& ($calendar['principaluri'] ?? '') === $principal) {
				return $existing;
			}
		}
		$uri = 'google-tasks-' . substr($col->getGoogleHash(), 0, 32);
		$calendar = $this->caldav->getCalendarByUri($principal, $uri);
		return is_array($calendar) ? (int)$calendar['id'] : 0;
	}

	/**
	 * @return array<string, array{uri: string, etag: string, lastmodified: int}>
	 */
	private function localTodos(int $ncId): array {
		$todos = [];
		foreach ($this->caldav->getCalendarObjects($ncId) as $object) {
			if (($object['component'] ?? '') !== 'vtodo') {
				continue;
			}
			$uri = (string)($object['uri'] ?? '');
			if ($uri === '') {
				continue;
			}
			$todos[$uri] = [
				'uri' => $uri,
				'etag' => (string)($object['etag'] ?? ''),
				'lastmodified' => (int)($object['lastmodified'] ?? 0),
			];
		}
		return $todos;
	}

	/**
	 * @param array{title: string, notes: string, status: string, due: string} $shape
	 */
	private function writeTodo(int $ncId, string $uri, array $shape, string $taskId): string {
		$ics = self::serialize($shape, $taskId !== '' ? $taskId : $uri);
		$existing = $this->caldav->getCalendarObject($ncId, $uri);
		if ($existing === null) {
			return (string)$this->caldav->createCalendarObject($ncId, $uri, $ics);
		}
		return (string)$this->caldav->updateCalendarObject($ncId, $uri, $ics);
	}

	/**
	 * @return array{title: string, notes: string, status: string, due: string}
	 */
	private function shapeFromUri(int $ncId, string $uri): array {
		$object = $this->caldav->getCalendarObject($ncId, $uri);
		$data = is_array($object) ? ($object['calendardata'] ?? '') : '';
		if (!is_string($data) || $data === '') {
			throw new \RuntimeException(T::t('Could not read Nextcloud tasks.'));
		}
		$vcal = Reader::read($data, Reader::OPTION_FORGIVING);
		if (!$vcal instanceof VCalendar) {
			throw new \RuntimeException(T::t('Could not read Nextcloud tasks.'));
		}
		foreach ($vcal->getComponents() as $component) {
			if ($component->name !== 'VTODO') {
				continue;
			}
			$status = strtoupper((string)($component->STATUS ?? ''));
			$percent = (string)($component->{'PERCENT-COMPLETE'} ?? '');
			return [
				'title' => trim((string)($component->SUMMARY ?? '')),
				'notes' => trim((string)($component->DESCRIPTION ?? '')),
				'status' => ($status === 'COMPLETED' || $percent === '100') ? 'completed' : 'needsAction',
				'due' => self::dueFromProperty($component->DUE ?? null),
			];
		}
		throw new \RuntimeException(T::t('The task has no to-do component.'));
	}

	private function dueNeedsRepair(int $ncId, string $uri): bool {
		$object = $this->caldav->getCalendarObject($ncId, $uri);
		$data = is_array($object) ? ($object['calendardata'] ?? '') : '';
		if (!is_string($data) || $data === '' || stripos($data, 'DUE') === false) {
			return false;
		}
		try {
			$vcal = Reader::read($data, Reader::OPTION_FORGIVING);
			if (!$vcal instanceof VCalendar || !isset($vcal->VTODO->DUE)) {
				return false;
			}
			$vcal->VTODO->DUE->getDateTime();
			$value = (string)$vcal->VTODO->DUE->getValue();
			return str_contains($value, '-');
		} catch (\Throwable) {
			return true;
		}
	}

	/**
	 * @param \Sabre\VObject\Property|null $due
	 */
	private static function dueFromProperty($due): string {
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
	 * @param array<string, mixed> $g
	 */
	private function saveState(?ObjectState $st, Collection $col, string $taskId, array $g, string $uri, string $etag): void {
		$st ??= new ObjectState();
		$st->setCollectionId($col->getId());
		$st->setGoogleId($taskId);
		$st->setGoogleUid(null);
		$st->setGoogleFp((string)($g['fp'] ?? ''));
		$st->setGoogleUpdated((int)($g['updated'] ?? 0));
		$st->setNcUri($uri);
		$st->setNcEtag($etag);
		$st->setIgnored(0);
		$st->setExtra(null);
		$st->setLastSync(time());
		if ($st->getId() === null) {
			$this->states->insert($st);
		} else {
			$this->states->update($st);
		}
	}

	/**
	 * @param array<string, mixed> $p
	 */
	private function label(array $p): string {
		$g = $p['group'] ?? null;
		if (is_array($g) && isset($g['shape']['title']) && $g['shape']['title'] !== '') {
			return (string)$g['shape']['title'];
		}
		return (string)($p['uri'] ?? $p['gid'] ?? T::t('Tasks'));
	}

	/**
	 * @param array<string, mixed> $task
	 * @return array<string, mixed>
	 */
	private static function entry(array $task): array {
		$shape = self::shape($task);
		return [
			'deleted' => !empty($task['deleted']),
			'shape' => $shape,
			'fp' => sha1((string)json_encode($shape, JSON_UNESCAPED_UNICODE)),
			'updated' => isset($task['updated']) ? (int)strtotime((string)$task['updated']) : 0,
			'parent' => (string)($task['parent'] ?? ''),
		];
	}

	/**
	 * @param array<string, mixed> $task
	 * @return array{title: string, notes: string, status: string, due: string}
	 */
	private static function shape(array $task): array {
		$due = '';
		if (!empty($task['due'])) {
			$stamp = strtotime((string)$task['due']);
			$due = $stamp ? gmdate('Y-m-d', $stamp) : '';
		}
		return [
			'title' => trim((string)($task['title'] ?? '')),
			'notes' => trim((string)($task['notes'] ?? '')),
			'status' => ($task['status'] ?? '') === 'completed' ? 'completed' : 'needsAction',
			'due' => $due,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function deleted(): array {
		return ['deleted' => true, 'shape' => ['title' => '', 'notes' => '', 'status' => 'needsAction', 'due' => ''], 'fp' => '', 'updated' => 0, 'parent' => ''];
	}

	/**
	 * @param array{title: string, notes: string, status: string, due: string} $shape
	 * @return array<string, mixed>
	 */
	private static function body(array $shape, string $parent): array {
		$body = [
			'title' => $shape['title'],
			'notes' => $shape['notes'],
			'status' => $shape['status'],
			'due' => $shape['due'] !== '' ? $shape['due'] . 'T00:00:00.000Z' : null,
		];
		if ($parent !== '') {
			$body['parent'] = $parent;
		}
		return $body;
	}

	/**
	 * @param array{title: string, notes: string, status: string, due: string} $shape
	 */
	private static function serialize(array $shape, string $uid): string {
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

	private static function uriFor(string $taskId): string {
		$safe = preg_replace('/[^A-Za-z0-9_-]/', '', $taskId) ?? '';
		if ($safe === '' || strlen($safe) > 180) {
			$safe = substr(hash('sha256', $taskId), 0, 32);
		}
		return $safe . '.ics';
	}

	private static function localName(string $name): string {
		$name = trim($name);
		foreach ([' (구글 할 일)', ' (Google Tasks)'] as $old) {
			if (str_ends_with($name, $old)) {
				$name = trim(mb_substr($name, 0, mb_strlen($name) - mb_strlen($old)));
			}
		}
		if ($name === '') {
			$name = T::t('Tasks');
		}
		$suffix = ' (Google Tasks)';
		return mb_substr($name, 0, 255 - mb_strlen($suffix)) . $suffix;
	}
}
