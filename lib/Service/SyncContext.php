<?php

declare(strict_types=1);

namespace OCA\GoogleTwoWaySync\Service;

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
	) {
		if ($legacy && !$preview) {
			throw new \InvalidArgumentException('기존 앱 토큰은 미리보기에만 쓸 수 있습니다.');
		}
	}
}
