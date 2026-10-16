<?php

declare(strict_types=1);

namespace OCA\GoogleTwoWaySync\Service;

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
		self::PULL_CREATE => '구글→NC 추가',
		self::PULL_UPDATE => '구글→NC 갱신',
		self::PULL_DELETE => '구글→NC 삭제',
		self::PUSH_CREATE => 'NC→구글 추가',
		self::PUSH_UPDATE => 'NC→구글 갱신',
		self::PUSH_DELETE => 'NC→구글 삭제',
		self::ADOPT => '이어받음',
		self::SKIP => '건너뜀',
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
		$this->lines[] = '[' . self::LABELS[$action] . '] ' . $label . ($detail !== '' ? ' (' . $detail . ')' : '');
	}

	public function error(string $message): void {
		$this->errors[] = $message;
	}

	public function summary(): string {
		$parts = [];
		foreach (self::LABELS as $action => $label) {
			if (($this->counts[$action] ?? 0) > 0) {
				$parts[] = $label . ' ' . $this->counts[$action];
			}
		}
		$parts[] = '유지 ' . $this->unchanged;
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
			$lines[] = '… ' . (count($this->lines) - $maxLines) . '건 더';
		}
		return [
			'name' => $this->name,
			'summary' => $this->summary(),
			'errors' => $this->errors,
			'lines' => $lines,
		];
	}
}
