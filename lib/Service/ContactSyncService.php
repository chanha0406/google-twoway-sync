<?php

declare(strict_types=1);

namespace OCA\GoogleTwoWaySync\Service;

use OCA\DAV\CardDAV\CardDavBackend;
use OCA\GoogleTwoWaySync\Db\Collection;
use OCA\GoogleTwoWaySync\Db\CollectionMapper;
use OCA\GoogleTwoWaySync\Db\ObjectState;
use OCA\GoogleTwoWaySync\Db\ObjectStateMapper;
use OCA\GoogleTwoWaySync\Exception\GoogleApiException;
use OCP\BackgroundJob\IJobList;
use Sabre\VObject\Component\VCard;
use Sabre\VObject\Reader;
use Sabre\VObject\UUIDUtil;

/**
 * Two-way sync of the Google contacts with one Nextcloud address book.
 *
 * On the first run, the cards Google Synchronization imported (URI prefix
 * google_contact_) are matched to their person, and every other card already
 * in the address book is recorded as ignored: those were made on the phone
 * and may duplicate people already in Google. Cards created after that are
 * uploaded.
 */
class ContactSyncService {
	public const LEGACY_JOB = 'OCA\Google\BackgroundJob\ImportContactsJob';
	public const LEGACY_PREFIX = 'google_contact_';
	private const IGNORE = 'ignore';
	/** People API allows about 60 writes per minute and user */
	private const MAX_WRITES_PER_RUN = 40;

	private int $writes = 0;

	public function __construct(
		private GoogleClient $google,
		private ContactConverter $converter,
		private CardDavBackend $carddav,
		private ObjectStateMapper $states,
		private CollectionMapper $collections,
		private IJobList $jobs,
	) {
	}

	public function hasLegacyJob(Collection $col): bool {
		return $this->legacyJobArgs($col) !== null;
	}

	public function removeLegacyJob(Collection $col): bool {
		$args = $this->legacyJobArgs($col);
		if ($args === null) {
			return false;
		}
		$this->jobs->remove(self::LEGACY_JOB, $args);
		return true;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function legacyJobArgs(Collection $col): ?array {
		foreach ($this->jobs->getJobsIterator(self::LEGACY_JOB, null, 0) as $job) {
			$args = $job->getArgument();
			if (is_array($args) && ($args['user_id'] ?? '') === $col->getUserId() && (int)($args['address_book_key'] ?? 0) === $col->getNcId()) {
				return $args;
			}
		}
		return null;
	}

	/**
	 * Address book the legacy import writes to, or else the default one.
	 */
	public function defaultAddressBook(string $userId): int {
		foreach ($this->jobs->getJobsIterator(self::LEGACY_JOB, null, 0) as $job) {
			$args = $job->getArgument();
			if (is_array($args) && ($args['user_id'] ?? '') === $userId && isset($args['address_book_key'])) {
				return (int)$args['address_book_key'];
			}
		}
		foreach ($this->carddav->getAddressBooksForUser('principals/users/' . $userId) as $ab) {
			if (($ab['uri'] ?? '') === 'contacts') {
				return (int)$ab['id'];
			}
		}
		return 0;
	}

	public function sync(Collection $col, SyncContext $ctx): SyncReport {
		$report = new SyncReport($col->getDisplayName());
		$this->writes = 0;
		$abId = $col->getNcId();
		$ab = $abId > 0 ? $this->carddav->getAddressBookById($abId) : null;
		if ($ab === null || ($ab['principaluri'] ?? '') !== 'principals/users/' . $col->getUserId()) {
			throw new \RuntimeException('동기화할 주소록이 없습니다.');
		}

		$cards = [];
		foreach ($this->carddav->getCards($abId) as $card) {
			$cards[(string)$card['uri']] = $card;
		}
		$states = $this->states->findByCollection($col->getId());
		$initialized = $col->getInitialized() === 1;
		$byUri = [];
		$byGid = [];
		foreach ($states as $st) {
			$byUri[$st->getNcUri()] = $st;
			if ($st->getGoogleId() !== '') {
				$byGid[$st->getGoogleId()] = $st;
			}
		}

		$groupNames = $this->groupNames($ctx);
		$full = !$initialized || ($col->getSyncToken() ?? '') === '';
		try {
			[$people, $nextToken] = $this->listPeople($ctx, $full ? null : $col->getSyncToken());
		} catch (GoogleApiException $e) {
			if ($full || !$e->isExpiredSyncToken()) {
				throw $e;
			}
			$full = true;
			[$people, $nextToken] = $this->listPeople($ctx, null);
		}
		$persons = [];
		foreach ($people as $person) {
			$rn = (string)($person['resourceName'] ?? '');
			if ($rn !== '') {
				$persons[$rn] = self::entry($person);
			}
		}

		$plan = [];
		$processed = [];
		foreach ($persons as $rn => $g) {
			$rn = (string)$rn;
			$st = $byGid[$rn] ?? null;
			if ($st === null) {
				if ($g['deleted']) {
					continue;
				}
				$uri = self::legacyUri($rn);
				if (isset($cards[$uri]) && !isset($byUri[$uri])) {
					$plan[] = ['action' => SyncReport::ADOPT, 'uri' => $uri, 'gid' => $rn, 'group' => $g, 'state' => null];
					$processed[$uri] = true;
				} else {
					$plan[] = ['action' => SyncReport::PULL_CREATE, 'uri' => isset($cards[$uri]) ? $uri . '-' . substr(sha1($rn), 0, 8) : $uri, 'gid' => $rn, 'group' => $g, 'state' => null];
				}
				continue;
			}
			$processed[$st->getNcUri()] = true;
			$plan[] = $this->decide($st, $g, $cards[$st->getNcUri()] ?? null);
		}
		foreach ($states as $st) {
			$uri = $st->getNcUri();
			if (isset($processed[$uri])) {
				continue;
			}
			$processed[$uri] = true;
			$card = $cards[$uri] ?? null;
			if ($st->getIgnored() === 1) {
				if ($card === null) {
					$plan[] = ['action' => 'forget', 'uri' => $uri, 'gid' => '', 'group' => null, 'state' => $st];
				}
				continue;
			}
			if ($full) {
				$plan[] = $this->decide($st, self::deleted(), $card);
				continue;
			}
			if ($card !== null && $card['etag'] === $st->getNcEtag()) {
				$report->unchanged++;
				continue;
			}
			$plan[] = $this->decide($st, $this->fetchPerson($ctx, $st->getGoogleId()), $card);
		}
		foreach ($cards as $uri => $card) {
			$uri = (string)$uri;
			if (isset($processed[$uri]) || isset($byUri[$uri])) {
				continue;
			}
			if ($initialized) {
				$plan[] = ['action' => SyncReport::PUSH_CREATE, 'uri' => $uri, 'gid' => '', 'group' => null, 'state' => null];
			} elseif (str_starts_with($uri, self::LEGACY_PREFIX)) {
				// Imported from a person that is gone, the legacy import would delete it too
				$plan[] = ['action' => SyncReport::PULL_DELETE, 'uri' => $uri, 'gid' => '', 'group' => null, 'state' => null, 'note' => '구글에 없는 가져온 연락처'];
			} else {
				$plan[] = ['action' => self::IGNORE, 'uri' => $uri, 'gid' => '', 'group' => null, 'state' => null];
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
		$blockDeletes = !$ctx->allowMassDelete && SyncReport::tooManyDeletions($deletions, max(count($states), count($cards)));
		if ($blockDeletes) {
			$report->error('한 번에 지울 연락처가 ' . $deletions . '건이라 삭제를 보류했습니다. 확인 후 occ googletwowaysync:sync --allow-mass-delete 로 진행하세요.');
		}

		$pullFailed = false;
		$pushBlocked = false;
		foreach ($plan as $p) {
			$action = $p['action'];
			if ($blockDeletes && in_array($action, [SyncReport::PULL_DELETE, SyncReport::PUSH_DELETE], true)) {
				$pullFailed = true;
				continue;
			}
			$isPush = in_array($action, [SyncReport::PUSH_CREATE, SyncReport::PUSH_UPDATE, SyncReport::PUSH_DELETE], true);
			if ($isPush && ($pushBlocked || $this->writes >= self::MAX_WRITES_PER_RUN)) {
				if (!$pushBlocked && !$ctx->preview) {
					$pushBlocked = true;
					$report->error('한 번에 올릴 수 있는 수(' . self::MAX_WRITES_PER_RUN . ')를 넘어 나머지는 다음 동기화로 미룹니다.');
				}
				if (!$ctx->preview) {
					continue;
				}
			}
			try {
				$this->execute($p, $col, $abId, $groupNames, $cards, $ctx, $report);
			} catch (GoogleApiException $e) {
				$report->error($this->label($p, $cards) . ': ' . $e->getMessage());
				if ($e->isRateLimit()) {
					$pushBlocked = true;
				}
				if (!$isPush) {
					$pullFailed = true;
				}
			} catch (\Throwable $e) {
				$report->error($this->label($p, $cards) . ': ' . $e->getMessage());
				if (!$isPush) {
					$pullFailed = true;
				}
			}
		}

		if (!$ctx->preview) {
			if (!$pullFailed || ($col->getSyncToken() ?? '') === '') {
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
	 * @param array<string, mixed> $g
	 * @param array<string, mixed>|null $card
	 * @return array<string, mixed>
	 */
	private function decide(ObjectState $st, array $g, ?array $card): array {
		$p = ['uri' => $st->getNcUri(), 'gid' => $st->getGoogleId(), 'group' => $g, 'state' => $st, 'note' => ''];
		$googleChanged = $g['deleted'] || $g['fp'] !== $st->getGoogleFp();
		$localChanged = $card !== null && $card['etag'] !== $st->getNcEtag();
		if ($card === null) {
			if ($g['deleted']) {
				return ['action' => 'forget'] + $p;
			}
			return $googleChanged
				? ['action' => SyncReport::PULL_CREATE, 'note' => '구글에서 고쳐져 되살림'] + $p
				: ['action' => SyncReport::PUSH_DELETE] + $p;
		}
		if ($g['deleted']) {
			return $localChanged
				? ['action' => SyncReport::PUSH_CREATE, 'note' => '구글에서 지웠지만 NC에서 고쳐 다시 올림'] + $p
				: ['action' => SyncReport::PULL_DELETE] + $p;
		}
		if ($googleChanged && $localChanged) {
			return $g['updated'] >= (int)$card['lastmodified']
				? ['action' => SyncReport::PULL_UPDATE, 'note' => '양쪽 수정, 구글이 나중'] + $p
				: ['action' => SyncReport::PUSH_UPDATE, 'note' => '양쪽 수정, NC가 나중'] + $p;
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
	 * @param array<string, string> $groupNames
	 * @param array<string, array<string, mixed>> $cards
	 */
	private function execute(array $p, Collection $col, int $abId, array $groupNames, array $cards, SyncContext $ctx, SyncReport $report): void {
		$action = $p['action'];
		$uri = (string)$p['uri'];
		/** @var ?ObjectState $st */
		$st = $p['state'];
		$g = $p['group'];
		$note = (string)($p['note'] ?? '');

		switch ($action) {
			case 'forget':
				if (!$ctx->preview && $st !== null) {
					$this->states->delete($st);
				}
				return;

			case self::IGNORE:
				$report->add(SyncReport::SKIP, $this->label($p, $cards), '전환 전에 NC에만 있던 연락처라 올리지 않음');
				if (!$ctx->preview) {
					$ignored = new ObjectState();
					$ignored->setCollectionId($col->getId());
					$ignored->setGoogleId('');
					$ignored->setGoogleFp('');
					$ignored->setGoogleUpdated(0);
					$ignored->setNcUri($uri);
					$ignored->setNcEtag((string)$cards[$uri]['etag']);
					$ignored->setIgnored(1);
					$ignored->setLastSync(time());
					$this->states->insert($ignored);
				}
				return;

			case SyncReport::PULL_CREATE:
				$person = $g['person'];
				$report->add($action, $this->personLabel($person), $note);
				if ($ctx->preview) {
					return;
				}
				$card = new VCard(['VERSION' => '3.0', 'UID' => UUIDUtil::getUUID()]);
				$c = $this->converter->fromGoogle($person, $groupNames);
				$this->converter->applyToVCard($card, $c, array_merge(ContactConverter::FIELDS, ContactConverter::PULL_ONLY), $this->converter->displayName($person));
				$photo = $this->converter->photoUrl($person);
				if ($photo !== null) {
					$this->applyPhoto($card, $photo);
				}
				$etag = (string)$this->carddav->createCard($abId, $uri, $card->serialize());
				$this->saveState($st, $col, (string)$p['gid'], $g, $uri, $etag, $photo);
				return;

			case SyncReport::ADOPT:
			case SyncReport::PULL_UPDATE:
				$person = $g['person'];
				$card = $this->readCard($cards[$uri]['carddata'] ?? null);
				if ($card === null) {
					throw new \RuntimeException('Nextcloud 연락처를 읽지 못했습니다');
				}
				$google = $this->converter->fromGoogle($person, $groupNames);
				$fields = $this->converter->diff($google, $this->converter->fromVCard($card), array_merge(ContactConverter::FIELDS, ContactConverter::PULL_ONLY));
				$photo = $this->converter->photoUrl($person);
				$oldPhoto = $st !== null ? (json_decode((string)$st->getExtra(), true)['photo'] ?? null) : null;
				// Adopted cards keep the photo the legacy import downloaded
				$photoChanged = $action === SyncReport::PULL_UPDATE && $photo !== $oldPhoto;
				if ($action === SyncReport::ADOPT) {
					$report->add($action, $this->personLabel($person), $fields === [] ? '' : 'NC 내용 갱신: ' . implode(',', $fields));
				} elseif ($fields === [] && !$photoChanged) {
					$report->unchanged++;
				} else {
					$report->add($action, $this->personLabel($person), trim($note . ' ' . implode(',', $fields) . ($photoChanged ? ' 사진' : '')));
				}
				if ($ctx->preview) {
					return;
				}
				$etag = (string)$cards[$uri]['etag'];
				if ($fields !== [] || $photoChanged) {
					$this->converter->applyToVCard($card, $google, $fields, $this->converter->displayName($person));
					if ($photoChanged) {
						if ($photo !== null) {
							$this->applyPhoto($card, $photo);
						} else {
							$this->converter->setPhoto($card, null, '');
						}
					}
					$etag = (string)$this->carddav->updateCard($abId, $uri, $card->serialize());
				}
				$this->saveState($st, $col, (string)$p['gid'], $g, $uri, $etag, $photo);
				return;

			case SyncReport::PULL_DELETE:
				$report->add($action, $g !== null && !$g['deleted'] ? $this->personLabel($g['person']) : $this->label($p, $cards), $note);
				if ($ctx->preview) {
					return;
				}
				$this->carddav->deleteCard($abId, $uri);
				if ($st !== null) {
					$this->states->delete($st);
				}
				return;

			case SyncReport::PUSH_DELETE:
				$report->add($action, $this->label($p, $cards), $note);
				if ($ctx->preview || $st === null) {
					return;
				}
				$this->writes++;
				try {
					$this->google->request($ctx, 'DELETE', GoogleClient::PEOPLE . $st->getGoogleId() . ':deleteContact');
				} catch (GoogleApiException $e) {
					if (!$e->isNotFound()) {
						throw $e;
					}
				}
				$this->states->delete($st);
				return;

			case SyncReport::PUSH_UPDATE:
				$person = $g['person'];
				$card = $this->readCard($cards[$uri]['carddata'] ?? null);
				if ($card === null) {
					throw new \RuntimeException('Nextcloud 연락처를 읽지 못했습니다');
				}
				$localC = $this->converter->fromVCard($card);
				$fields = $this->converter->diff($this->converter->fromGoogle($person, $groupNames), $localC, ContactConverter::FIELDS);
				if ($fields === []) {
					$report->unchanged++;
					if (!$ctx->preview) {
						$this->saveState($st, $col, (string)$p['gid'], $g, $uri, (string)$cards[$uri]['etag'], $this->converter->photoUrl($person));
					}
					return;
				}
				$report->add($action, $this->personLabel($person), trim($note . ' ' . implode(',', $fields)));
				if ($ctx->preview) {
					return;
				}
				[$body, $update] = $this->converter->toGoogle($localC, $fields);
				$body['etag'] = $person['etag'] ?? '';
				$this->writes++;
				$updated = $this->google->request($ctx, 'PATCH', GoogleClient::PEOPLE . $p['gid'] . ':updateContact', [
					'updatePersonFields' => implode(',', $update),
					'personFields' => ContactConverter::PERSON_FIELDS,
				], $body);
				$this->saveState($st, $col, (string)$p['gid'], self::entry($updated), $uri, (string)$cards[$uri]['etag'], $this->converter->photoUrl($updated));
				return;

			case SyncReport::PUSH_CREATE:
				$card = $this->readCard($cards[$uri]['carddata'] ?? null);
				if ($card === null) {
					throw new \RuntimeException('Nextcloud 연락처를 읽지 못했습니다');
				}
				$report->add($action, $this->label($p, $cards), $note);
				if ($ctx->preview) {
					return;
				}
				[$body] = $this->converter->toGoogle($this->converter->fromVCard($card), ContactConverter::FIELDS);
				$body = array_filter($body, static fn ($v) => $v !== []);
				$this->writes++;
				$created = $this->google->request($ctx, 'POST', GoogleClient::PEOPLE . 'people:createContact', [
					'personFields' => ContactConverter::PERSON_FIELDS,
				], $body);
				$this->saveState($st, $col, (string)$created['resourceName'], self::entry($created), $uri, (string)$cards[$uri]['etag'], $this->converter->photoUrl($created));
				return;
		}
	}

	private function applyPhoto(VCard $card, string $url): void {
		try {
			[$data, $type] = $this->google->download($url);
			$this->converter->setPhoto($card, $data, $type);
		} catch (\Throwable) {
			// a missing photo is not worth failing the contact for
		}
	}

	/**
	 * @param array<string, mixed> $g
	 */
	private function saveState(?ObjectState $st, Collection $col, string $rn, array $g, string $uri, string $etag, ?string $photo): void {
		$st ??= new ObjectState();
		$st->setCollectionId($col->getId());
		$st->setGoogleId($rn);
		$st->setGoogleUid(null);
		$st->setGoogleFp((string)$g['fp']);
		$st->setGoogleUpdated((int)$g['updated']);
		$st->setNcUri($uri);
		$st->setNcEtag($etag);
		$st->setIgnored(0);
		$st->setExtra(json_encode(['photo' => $photo]) ?: null);
		$st->setLastSync(time());
		if ($st->getId() === null) {
			$this->states->insert($st);
		} else {
			$this->states->update($st);
		}
	}

	/**
	 * @param array<string, mixed> $person
	 * @return array<string, mixed>
	 */
	private static function entry(array $person): array {
		$updated = 0;
		foreach (($person['metadata']['sources'] ?? []) as $source) {
			if (($source['type'] ?? '') === 'CONTACT' && isset($source['updateTime'])) {
				$updated = max($updated, (int)strtotime((string)$source['updateTime']));
			}
		}
		return [
			'deleted' => (bool)($person['metadata']['deleted'] ?? false),
			'person' => $person,
			'fp' => (string)($person['etag'] ?? ''),
			'updated' => $updated,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function deleted(): array {
		return ['deleted' => true, 'person' => null, 'fp' => '', 'updated' => 0];
	}

	public static function legacyUri(string $resourceName): string {
		return self::LEGACY_PREFIX . str_replace('/', '_', $resourceName);
	}

	/**
	 * @return array{list<array<string, mixed>>, ?string}
	 */
	private function listPeople(SyncContext $ctx, ?string $syncToken): array {
		$query = [
			'personFields' => ContactConverter::PERSON_FIELDS,
			'pageSize' => 1000,
			'requestSyncToken' => 'true',
		];
		if ($syncToken !== null) {
			$query['syncToken'] = $syncToken;
		}
		$people = [];
		do {
			$result = $this->google->request($ctx, 'GET', GoogleClient::PEOPLE . 'people/me/connections', $query);
			foreach (($result['connections'] ?? []) as $person) {
				$people[] = $person;
			}
			$query['pageToken'] = (string)($result['nextPageToken'] ?? '');
		} while ($query['pageToken'] !== '');
		return [$people, isset($result['nextSyncToken']) ? (string)$result['nextSyncToken'] : null];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function fetchPerson(SyncContext $ctx, string $rn): array {
		try {
			$person = $this->google->request($ctx, 'GET', GoogleClient::PEOPLE . $rn, ['personFields' => ContactConverter::PERSON_FIELDS]);
		} catch (GoogleApiException $e) {
			if ($e->isNotFound()) {
				return self::deleted();
			}
			throw $e;
		}
		return self::entry($person);
	}

	/**
	 * @return array<string, string> group name by resourceName
	 */
	private function groupNames(SyncContext $ctx): array {
		$out = [];
		$query = ['pageSize' => 1000, 'groupFields' => 'name,groupType'];
		do {
			$result = $this->google->request($ctx, 'GET', GoogleClient::PEOPLE . 'contactGroups', $query);
			foreach (($result['contactGroups'] ?? []) as $group) {
				if (($group['groupType'] ?? '') === 'USER_CONTACT_GROUP') {
					$out[(string)$group['resourceName']] = (string)($group['formattedName'] ?? $group['name'] ?? '');
				}
			}
			$query['pageToken'] = (string)($result['nextPageToken'] ?? '');
		} while ($query['pageToken'] !== '');
		return $out;
	}

	private function readCard(?string $data): ?VCard {
		if ($data === null || $data === '') {
			return null;
		}
		try {
			$card = Reader::read($data, Reader::OPTION_FORGIVING);
		} catch (\Throwable) {
			return null;
		}
		return $card instanceof VCard ? $card : null;
	}

	/**
	 * @param array<string, mixed>|null $person
	 */
	private function personLabel(?array $person): string {
		if ($person === null) {
			return '(이름 없음)';
		}
		$name = $this->converter->displayName($person);
		if ($name === '') {
			$name = (string)($person['emailAddresses'][0]['value'] ?? $person['phoneNumbers'][0]['value'] ?? $person['resourceName'] ?? '(이름 없음)');
		}
		return $name;
	}

	/**
	 * @param array<string, mixed> $p
	 * @param array<string, array<string, mixed>> $cards
	 */
	private function label(array $p, array $cards): string {
		if (is_array($p['group'] ?? null) && !($p['group']['deleted'] ?? true)) {
			return $this->personLabel($p['group']['person']);
		}
		$card = $this->readCard($cards[(string)$p['uri']]['carddata'] ?? null);
		$fn = $card !== null ? trim((string)($card->FN ?? '')) : '';
		return $fn !== '' ? $fn : (string)$p['uri'];
	}
}
