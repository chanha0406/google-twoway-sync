<?php

declare(strict_types=1);

namespace OCA\GoogleTwoWaySync\Service;

use OCA\GoogleTwoWaySync\L10n\T;

/**
 * What one collection sync did, or would do in a preview.
 */
class SyncReport {
	public const PULL_CREATE = 'pull_create';
	public const PULL_UPDATE = 'pull_update';
	public const PULL_DELETE = 'pull_delete';
	public const PUSH_CREATE = 'push_create';
	public const PUSH_UPDATE = 'push_update';
	public const PUSH_DELETE = 'push_delete';
	public const ADOPT = 'adopt';
	public const SKIP = 'skip';

	private const LABELS = [
		self::PULL_CREATE => 'Google → Nextcloud added',
		self::PULL_UPDATE => 'Google → Nextcloud updated',
		self::PULL_DELETE => 'Google → Nextcloud deleted',
		self::PUSH_CREATE => 'Nextcloud → Google added',
		self::PUSH_UPDATE => 'Nextcloud → Google updated',
		self::PUSH_DELETE => 'Nextcloud → Google deleted',
		self::ADOPT => 'Adopted',
		self::SKIP => 'Skipped',
	];

	/** @var array<string, int> */
	public array $counts = [];
	/** @var list<string> */
	public array $lines = [];
	/** @var list<string> */
	public array $errors = [];
	public int $unchanged = 0;

	public function __construct(
		public readonly string $name,
	) {
	}

	public function add(string $action, string $label, string $detail = ''): void {
		$this->counts[$action] = ($this->counts[$action] ?? 0) + 1;
		$actionLabel = T::t(self::LABELS[$action]);
		$this->lines[] = '[' . $actionLabel . '] ' . $label . ($detail !== '' ? ' (' . $detail . ')' : '');
	}

	public function error(string $message): void {
		$this->errors[] = $message;
	}

	public function summary(): string {
		$parts = [];
		foreach (self::LABELS as $action => $label) {
			if (($this->counts[$action] ?? 0) > 0) {
				$parts[] = T::t($label) . ' ' . $this->counts[$action];
			}
		}
		$parts[] = T::t('Unchanged %s', [$this->unchanged]);
		return implode(', ', $parts);
	}

	/**
	 * Refuses many deletions in one run, which is more likely a glitch (an
	 * empty answer from Google, an emptied calendar on a phone) than intended.
	 */
	public static function tooManyDeletions(int $deletions, int $total): bool {
		return $deletions >= 50 || ($deletions >= 10 && $deletions * 5 >= max($total, 1));
	}

	/**
	 * @return array{name: string, summary: string, errors: list<string>, lines: list<string>}
	 */
	public function toArray(int $maxLines = 200): array {
		$lines = array_slice($this->lines, 0, $maxLines);
		if (count($this->lines) > $maxLines) {
			$lines[] = T::t('… %s more', [count($this->lines) - $maxLines]);
		}
		return [
			'name' => $this->name,
			'summary' => $this->summary(),
			'errors' => $this->errors,
			'lines' => $lines,
		];
	}
}
