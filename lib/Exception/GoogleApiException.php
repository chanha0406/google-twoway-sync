<?php

declare(strict_types=1);

namespace OCA\GoogleTwoWaySync\Exception;

class GoogleApiException extends \RuntimeException {
	public function __construct(
		string $message,
		private int $status,
		private string $reason = '',
	) {
		parent::__construct($message, $status);
	}

	public function getStatus(): int {
		return $this->status;
	}

	public function getReason(): string {
		return $this->reason;
	}

	public function isRateLimit(): bool {
		return $this->status === 429
			|| in_array($this->reason, ['rateLimitExceeded', 'userRateLimitExceeded', 'RESOURCE_EXHAUSTED'], true);
	}

	public function isNotFound(): bool {
		return $this->status === 404 || $this->status === 410;
	}

	/**
	 * Calendar answers 410 to an expired sync token, People 400 or 410 with
	 * EXPIRED_SYNC_TOKEN.
	 */
	public function isExpiredSyncToken(): bool {
		return $this->status === 410
			|| ($this->status === 400 && stripos($this->getMessage() . ' ' . $this->reason, 'sync token') !== false)
			|| str_contains($this->reason, 'EXPIRED_SYNC_TOKEN');
	}
}
