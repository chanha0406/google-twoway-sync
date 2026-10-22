<?php

declare(strict_types=1);

namespace OCA\GoogleTwoWaySync\Service;

use OCA\GoogleTwoWaySync\L10n\T;

/**
 * One sync run of one user.
 */
class SyncContext {
	public int $requests = 0;

	public function __construct(
		public readonly string $userId,
		/** Plan only: neither Google nor Nextcloud is written */
		public readonly bool $preview,
		/** Use the read-only token of Google Synchronization, previews only */
		public readonly bool $legacy = false,
		public readonly bool $allowMassDelete = false,
		/** Upload every Nextcloud contact photo to Google once, even where it looks synced */
		public readonly bool $forcePhotos = false,
	) {
		if ($legacy && !$preview) {
			throw new \InvalidArgumentException(T::t('The legacy app token can only be used for previews.'));
		}
	}
}
