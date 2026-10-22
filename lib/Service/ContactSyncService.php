<?php

declare(strict_types=1);

namespace OCA\GoogleTwoWaySync\Service;

use OCA\GoogleTwoWaySync\L10n\T;

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
	/** People API allows about 60 writes per minute and user; past that the run stops at the rate limit and the rest waits */
	private const MAX_WRITES_PER_RUN = 200;
	/** people:batchGet takes at most 200 resource names per request */
	private const BATCH_GET_SIZE = 200;

	private int $writes = 0;
	private bool $forcePhotos = false;

	public function __construct(
		private GoogleClient $google,
		private ContactConverter $converter,
		private CardDavBackend $carddav,
		private ObjectStateMapper $states,
		private CollectionMapper $collections,
		private IJobList $jobs,
		private PlanRunner $runner,
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
		foreach ($this->carddav->getUsersOwnAddressBooks('principals/users/' . $userId) as $ab) {
			if (($ab['uri'] ?? '') === 'contacts') {
				return (int)$ab['id'];
			}
		}
		return 0;
	}

	/**
	 * Stored address book id when it still belongs to the user, otherwise the
	 * default contacts book. Returns 0 when the user has none yet.
	 */
	public function resolveAddressBookId(string $userId, int $ncId): int {
		$principal = 'principals/users/' . $userId;
		if ($ncId > 0) {
			$ab = $this->carddav->getAddressBookById($ncId);
			if ($ab !== null && ($ab['principaluri'] ?? '') === $principal) {
				return $ncId;
			}
		}
		return $this->defaultAddressBook($userId);
	}

	/**
	 * Address book to sync into. Rebinds a missing or stale nc_id, and creates
	 * the default contacts book when the user has none. Preview does not write.
	 */
	public function ensureAddressBook(Collection $col, bool $preview = false): int {
		$id = $this->resolveAddressBookId($col->getUserId(), $col->getNcId());
		if ($id === 0) {
			if ($preview) {
				throw new \RuntimeException(T::t('There is no address book to sync.'));
			}
			$principal = 'principals/users/' . $col->getUserId();
			try {
				$id = (int)$this->carddav->createAddressBook($principal, 'contacts', [
					'{DAV:}displayname' => 'Contacts',
				]);
			} catch (\Throwable) {
				$id = $this->defaultAddressBook($col->getUserId());
			}
			if ($id === 0) {
				throw new \RuntimeException(T::t('There is no address book to sync.'));
			}
		}
		if (!$preview && $id !== $col->getNcId()) {
			$this->rebind($col, $id);
		}
		return $id;
	}

	/**
	 * Point the collection at another address book. States from the old book
	 * would make every Google contact look deleted locally and push deletes to
	 * Google, so start over with a full sync like a new calendar does.
	 */
	public function rebind(Collection $col, int $id): void {
		if ($col->getNcId() > 0) {
			$this->states->deleteByCollection($col->getId());
			$col->setInitialized(0);
			$col->setSyncToken(null);
		}
		$col->setNcId($id);
		$this->collections->update($col);
	}

	public function sync(Collection $col, SyncContext $ctx): SyncReport {
		$report = new SyncReport($col->getDisplayName());
		$this->writes = 0;
		$this->forcePhotos = $ctx->forcePhotos;
		$abId = $this->ensureAddressBook($col, $ctx->preview);
		$ab = $this->carddav->getAddressBookById($abId);
		if ($ab === null || ($ab['principaluri'] ?? '') !== 'principals/users/' . $col->getUserId()) {
			throw new \RuntimeException(T::t('There is no address book to sync.'));
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
		$pending = [];
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
			if ($card !== null && self::sameEtag((string)$card['etag'], $st->getNcEtag()) && !$this->photoNeverPushed($st, null, $card) && !$this->photoRetry($st) && !$this->forcePending($st, $card)) {
				$report->unchanged++;
				continue;
			}
			$pending[] = [$st, $card];
		}
		if ($pending !== []) {
			$limited = false;
			$fetched = $this->fetchPeople($ctx, array_map(static fn (array $row): string => $row[0]->getGoogleId(), $pending), $limited);
			$deferred = 0;
			foreach ($pending as [$st, $card]) {
				$gid = $st->getGoogleId();
				if (!isset($fetched[$gid])) {
					$deferred++;
					continue;
				}
				$plan[] = $this->decide($st, $fetched[$gid], $card);
			}
			if ($deferred > 0) {
				$report->error($limited
					? T::t('The per-minute Google read limit was reached, so %s contacts wait for the next sync.', [$deferred])
					: T::t('Google did not return %s contacts, so they wait for the next sync.', [$deferred]));
			}
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
				$plan[] = ['action' => SyncReport::PULL_DELETE, 'uri' => $uri, 'gid' => '', 'group' => null, 'state' => null, 'note' => T::t('Imported contact that is no longer on Google')];
			} else {
				$plan[] = ['action' => self::IGNORE, 'uri' => $uri, 'gid' => '', 'group' => null, 'state' => null];
			}
		}

		$ok = $this->runner->run(
			$plan,
			$ctx,
			$report,
			max(count($states), count($cards)),
			static fn (int $n): string => T::t('Refused to delete %s contacts at once. Check them, then continue with occ googletwowaysync:sync --allow-mass-delete.', [$n]),
			fn (array $p) => $this->execute($p, $col, $abId, $groupNames, $cards, $ctx, $report),
			fn (array $p): string => $this->label($p, $cards),
			fn (): int => $this->writes,
			self::MAX_WRITES_PER_RUN,
		);
		if (!$ctx->preview) {
			// A failed pull keeps the old token (or none on the first sync), so
			// the next run lists those items again instead of skipping them.
			$this->runner->finish($col, $report, $ok, $nextToken);
		}
		return $report;
	}

	/**
	 * @param array<string, mixed> $g
	 * @param array<string, mixed>|null $card
	 * @return array<string, mixed>
	 */
	private function decide(ObjectState $st, array $g, ?array $card): array {
		$googleSide = $g['deleted'] || $g['fp'] !== $st->getGoogleFp();
		$stale = $this->photoStale($st, $g);
		// The card's photo is newer than the one last synced: Google's photo must not replace it
		$photoLocal = $card !== null && $this->photoLocallyChanged($st, $card, $googleSide || $stale);
		$d = SyncDecision::decide(
			$card !== null,
			$g['deleted'],
			$googleSide || ($stale && !$photoLocal),
			$card !== null && ($photoLocal || !self::sameEtag((string)$card['etag'], $st->getNcEtag()) || $this->photoNeverPushed($st, $g, $card)),
			(int)$g['updated'],
			(int)($card['lastmodified'] ?? 0),
		);
		return ['action' => $d['action'], 'uri' => $st->getNcUri(), 'gid' => $st->getGoogleId(), 'group' => $g, 'state' => $st, 'note' => $d['note'] !== '' ? T::t($d['note']) : ''];
	}

	/**
	 * A photo added in Nextcloud before photos were uploaded left no trace in the card etag,
	 * so a state without a recorded photo hash counts as changed when only Nextcloud has one.
	 *
	 * @param array<string, mixed>|null $g the Google side when it is already fetched
	 * @param array<string, mixed> $card
	 */
	private function photoNeverPushed(ObjectState $st, ?array $g, array $card): bool {
		$extra = json_decode((string)$st->getExtra(), true);
		if (!is_array($extra) || array_key_exists('nc', $extra) || !str_contains((string)($card['carddata'] ?? ''), 'PHOTO')) {
			return false;
		}
		return $g === null ? ($extra['photo'] ?? null) === null : ($g['person'] !== null && $this->converter->photoUrl($g['person']) === null);
	}

	private function photoRetry(ObjectState $st): bool {
		$extra = json_decode((string)$st->getExtra(), true);
		return is_array($extra) && !empty($extra['retry']);
	}

	/**
	 * Whether the card's photo is not the one last synced with Google: it was changed in
	 * Nextcloud, or its upload failed. Parsing the card is only done when $deep, that is
	 * when Google also looks changed, because that is when the answer decides who wins.
	 *
	 * @param array<string, mixed> $card
	 */
	private function photoLocallyChanged(ObjectState $st, array $card, bool $deep): bool {
		if ($this->forcePending($st, $card)) {
			return true;
		}
		$extra = json_decode((string)$st->getExtra(), true);
		if (!is_array($extra) || !array_key_exists('nc', $extra)) {
			return false;
		}
		if (!empty($extra['retry'])) {
			return true;
		}
		if (!$deep) {
			return false;
		}
		$vcard = $this->readCard($card['carddata'] ?? null);
		return $vcard !== null && $this->photoHash($vcard) !== $extra['nc'];
	}

	/**
	 * A card whose photo came from another URL (an old thumbnail) is pulled again.
	 *
	 * @param array<string, mixed> $g
	 */
	private function photoStale(ObjectState $st, array $g): bool {
		if ($g['person'] === null) {
			return false;
		}
		$old = json_decode((string)$st->getExtra(), true)['photo'] ?? null;
		return $this->converter->photoUrl($g['person']) !== $old;
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
				$report->add(SyncReport::SKIP, $this->label($p, $cards), T::t('Not uploaded because the contact existed only in Nextcloud before the switch'));
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
				$this->saveState($st, $col, (string)$p['gid'], $g, $uri, $etag, $photo, $card);
				return;

			case SyncReport::ADOPT:
			case SyncReport::PULL_UPDATE:
				$person = $g['person'];
				$card = $this->readCard($cards[$uri]['carddata'] ?? null);
				if ($card === null) {
					throw new \RuntimeException(T::t('Could not read Nextcloud contacts'));
				}
				$google = $this->converter->fromGoogle($person, $groupNames);
				$fields = $this->converter->diff($google, $this->converter->fromVCard($card), array_merge(ContactConverter::FIELDS, ContactConverter::PULL_ONLY));
				$photo = $this->converter->photoUrl($person);
				$oldPhoto = $st !== null ? (json_decode((string)$st->getExtra(), true)['photo'] ?? null) : null;
				// Adopted cards keep the photo the legacy import downloaded
				$keepPhoto = $action === SyncReport::PULL_UPDATE && $st !== null && $this->photoLocallyChanged($st, $cards[$uri], true);
				$photoChanged = $action === SyncReport::PULL_UPDATE && !$keepPhoto && $photo !== $oldPhoto;
				if ($action === SyncReport::ADOPT) {
					$report->add($action, $this->personLabel($person), $fields === [] ? '' : T::t('Nextcloud content updated: %s', [implode(',', $fields)]));
				} elseif ($fields === [] && !$photoChanged) {
					$report->unchanged++;
				} else {
					$report->add($action, $this->personLabel($person), trim($note . ' ' . implode(',', $fields) . ($photoChanged ? ' ' . T::t('photo') : '')));
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
				if ($keepPhoto) {
					// Nextcloud's photo is newer than Google's, so it goes up instead of being replaced
					$sent = $this->pushPhoto($ctx, (string)$p['gid'], $card, $report);
					if ($sent !== null) {
						$this->saveState($st, $col, (string)$p['gid'], self::entry($sent), $uri, $etag, $this->converter->photoUrl($sent), $card, false, $this->forcePhotos);
					} else {
						$this->saveState($st, $col, (string)$p['gid'], $g, $uri, $etag, $oldPhoto, null, true);
					}
					return;
				}
				// An adopted card keeps whatever photo it had, so the first push does not replace Google's
				$this->saveState($st, $col, (string)$p['gid'], $g, $uri, $etag, $photo, $action === SyncReport::ADOPT && $photo !== null ? null : $card);
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
					throw new \RuntimeException(T::t('Could not read Nextcloud contacts'));
				}
				$localC = $this->converter->fromVCard($card);
				$fields = $this->converter->diff($this->converter->fromGoogle($person, $groupNames), $localC, ContactConverter::FIELDS);
				$photoUp = $this->photoUpNeeded($st, $card, $person);
				if ($fields === [] && !$photoUp) {
					$report->unchanged++;
					if (!$ctx->preview) {
						$this->saveState($st, $col, (string)$p['gid'], $g, $uri, (string)$cards[$uri]['etag'], $this->converter->photoUrl($person), $card);
					}
					return;
				}
				$report->add($action, $this->personLabel($person), trim($note . ' ' . implode(',', $fields) . ($photoUp ? ' ' . T::t('photo') : '')));
				if ($ctx->preview) {
					return;
				}
				$updated = $person;
				if ($fields !== []) {
					[$body, $update] = $this->converter->toGoogle($localC, $fields);
					$body['etag'] = $person['etag'] ?? '';
					$this->writes++;
					$updated = $this->google->request($ctx, 'PATCH', GoogleClient::PEOPLE . $p['gid'] . ':updateContact', [
						'updatePersonFields' => implode(',', $update),
						'personFields' => ContactConverter::PERSON_FIELDS,
					], $body);
				}
				$sent = $photoUp ? $this->pushPhoto($ctx, (string)$p['gid'], $card, $report) : null;
				if ($sent !== null) {
					$updated = $sent;
				}
				$this->saveState($st, $col, (string)$p['gid'], self::entry($updated), $uri, (string)$cards[$uri]['etag'], $this->converter->photoUrl($updated), $photoUp && $sent === null ? null : $card, $photoUp && $sent === null, $this->forcePhotos && $sent !== null);
				return;

			case SyncReport::PUSH_CREATE:
				$card = $this->readCard($cards[$uri]['carddata'] ?? null);
				if ($card === null) {
					throw new \RuntimeException(T::t('Could not read Nextcloud contacts'));
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
				$photoUp = $this->converter->photoBytes($card) !== null;
				$sent = $photoUp ? $this->pushPhoto($ctx, (string)$created['resourceName'], $card, $report) : null;
				$created = $sent ?? $created;
				$this->saveState($st, $col, (string)$created['resourceName'], self::entry($created), $uri, (string)$cards[$uri]['etag'], $this->converter->photoUrl($created), $photoUp && $sent === null ? null : $card, $photoUp && $sent === null, $this->forcePhotos && $sent !== null);
				return;
		}
	}

	/**
	 * With --force-photos: the card has a photo that was not yet uploaded by a forced run.
	 * The hash is recorded after a successful upload, so a run cut short by the rate limit
	 * continues where it stopped.
	 *
	 * @param array<string, mixed> $card
	 */
	private function forcePending(ObjectState $st, array $card): bool {
		if (!$this->forcePhotos) {
			return false;
		}
		$vcard = $this->readCard($card['carddata'] ?? null);
		$hash = $vcard !== null ? $this->photoHash($vcard) : null;
		return $hash !== null && (json_decode((string)$st->getExtra(), true)['forced'] ?? null) !== $hash;
	}

	private function photoHash(VCard $card): ?string {
		$bytes = $this->converter->photoBytes($card);
		return $bytes === null ? null : sha1($bytes);
	}

	/**
	 * Whether the card's photo differs from the one last synced. A state without a
	 * recorded photo only uploads when Google has none, so existing contacts keep theirs.
	 *
	 * @param array<string, mixed> $person
	 */
	private function photoUpNeeded(ObjectState $st, VCard $card, array $person): bool {
		$hash = $this->photoHash($card);
		if ($this->forcePhotos && $hash !== null && (json_decode((string)$st->getExtra(), true)['forced'] ?? null) !== $hash) {
			return true;
		}
		$old = json_decode((string)$st->getExtra(), true)['nc'] ?? false;
		if ($old === false) {
			return $hash !== null && $this->converter->photoUrl($person) === null;
		}
		return $old !== $hash;
	}

	/**
	 * Upload (or remove) the card's photo on Google. Returns the person Google answered with,
	 * or null when it failed, which leaves the stored hash alone so the next sync tries again.
	 *
	 * @return array<string, mixed>|null
	 */
	private function pushPhoto(SyncContext $ctx, string $gid, VCard $card, SyncReport $report): ?array {
		$bytes = $this->converter->photoBytes($card);
		$this->writes++;
		try {
			if ($bytes === null) {
				$out = $this->google->request($ctx, 'DELETE', GoogleClient::PEOPLE . $gid . ':deleteContactPhoto', [
					'personFields' => ContactConverter::PERSON_FIELDS,
				]);
			} else {
				$out = $this->google->request($ctx, 'PATCH', GoogleClient::PEOPLE . $gid . ':updateContactPhoto', [], [
					'photoBytes' => base64_encode($bytes),
					'personFields' => ContactConverter::PERSON_FIELDS,
				]);
			}
		} catch (GoogleApiException $e) {
			$report->error(T::t('Could not upload the photo of a contact: %s', [$e->getMessage()]));
			return null;
		}
		return $out['person'] ?? null;
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
	private function saveState(?ObjectState $st, Collection $col, string $rn, array $g, string $uri, string $etag, ?string $photo, ?VCard $card = null, bool $photoRetry = false, bool $forced = false): void {
		$st ??= new ObjectState();
		$st->setCollectionId($col->getId());
		$st->setGoogleId($rn);
		$st->setGoogleUid(null);
		$st->setGoogleFp((string)$g['fp']);
		$st->setGoogleUpdated((int)$g['updated']);
		$st->setNcUri($uri);
		$st->setNcEtag(self::canonicalEtag($etag));
		$st->setIgnored(0);
		$extra = ['photo' => $photo];
		if ($card !== null) {
			$extra['nc'] = $this->photoHash($card);
		} elseif (($old = json_decode((string)$st->getExtra(), true)['nc'] ?? false) !== false) {
			$extra['nc'] = $old;
		}
		if ($forced && $card !== null) {
			$extra['forced'] = $this->photoHash($card);
		} elseif (($oldForced = json_decode((string)$st->getExtra(), true)['forced'] ?? null) !== null) {
			$extra['forced'] = $oldForced;
		}
		if ($photoRetry) {
			$extra['retry'] = true;
		}
		$st->setExtra(json_encode($extra) ?: null);
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
		[$people, $last] = $this->google->listAll($ctx, GoogleClient::PEOPLE . 'people/me/connections', $query, 'connections');
		return [$people, isset($last['nextSyncToken']) ? (string)$last['nextSyncToken'] : null];
	}

	/**
	 * People reads for contacts that changed only on the Nextcloud side, in
	 * batches of BATCH_GET_SIZE. On a rate limit the batches already read are
	 * kept and $limited is set; the rest stay for the next run.
	 * A missing entry means the person was not fetched, not that it was deleted.
	 *
	 * @param list<string> $resourceNames
	 * @return array<string, array<string, mixed>>
	 */
	private function fetchPeople(SyncContext $ctx, array $resourceNames, bool &$limited): array {
		$names = [];
		foreach ($resourceNames as $rn) {
			if ($rn !== '') {
				$names[$rn] = $rn;
			}
		}
		$out = [];
		foreach (array_chunk(array_values($names), self::BATCH_GET_SIZE) as $chunk) {
			try {
				$result = $this->google->request($ctx, 'GET', GoogleClient::PEOPLE . 'people:batchGet', [
					'personFields' => ContactConverter::PERSON_FIELDS,
					'resourceNames' => $chunk,
				]);
			} catch (GoogleApiException $e) {
				if ($e->isRateLimit()) {
					$limited = true;
					break;
				}
				throw $e;
			}
			$out += $this->parseBatch($result);
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $result
	 * @return array<string, array<string, mixed>>
	 */
	private function parseBatch(array $result): array {
		$out = [];
		foreach (($result['responses'] ?? []) as $row) {
			if (!is_array($row)) {
				continue;
			}
			$person = $row['person'] ?? null;
			$rn = (string)($row['requestedResourceName'] ?? (is_array($person) ? ($person['resourceName'] ?? '') : ''));
			if ($rn === '') {
				continue;
			}
			$status = (int)($row['httpStatusCode'] ?? 200);
			if ($status === 404 || $status === 410) {
				$out[$rn] = self::deleted();
				continue;
			}
			if ($status >= 400 || !is_array($person)) {
				continue;
			}
			$out[$rn] = self::entry($person);
		}
		return $out;
	}

	private static function sameEtag(string $a, string $b): bool {
		return self::canonicalEtag($a) === self::canonicalEtag($b);
	}

	/** CardDAV sometimes stores the etag with quotes and sometimes without. */
	private static function canonicalEtag(string $etag): string {
		return trim($etag, '"');
	}

	/**
	 * @return array<string, string> group name by resourceName
	 */
	private function groupNames(SyncContext $ctx): array {
		$out = [];
		[$groups] = $this->google->listAll($ctx, GoogleClient::PEOPLE . 'contactGroups', ['pageSize' => 1000, 'groupFields' => 'name,groupType'], 'contactGroups');
		foreach ($groups as $group) {
			if (($group['groupType'] ?? '') === 'USER_CONTACT_GROUP') {
				$out[(string)$group['resourceName']] = (string)($group['formattedName'] ?? $group['name'] ?? '');
			}
		}
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
			return T::t('(no name)');
		}
		$name = $this->converter->displayName($person);
		if ($name === '') {
			$name = (string)($person['emailAddresses'][0]['value'] ?? $person['phoneNumbers'][0]['value'] ?? $person['resourceName'] ?? T::t('(no name)'));
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
