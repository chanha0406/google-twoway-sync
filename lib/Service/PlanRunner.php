<?php

declare(strict_types=1);

namespace OCA\GoogleTwoWaySync\Service;

use OCA\GoogleTwoWaySync\Db\Collection;
use OCA\GoogleTwoWaySync\Db\CollectionMapper;
use OCA\GoogleTwoWaySync\Exception\GoogleApiException;
use OCA\GoogleTwoWaySync\L10n\T;

/**
 * Runs a sync plan the same way for calendars, contacts and tasks: drops
 * unchanged items, holds back mass deletions, stops pushing after a rate
 * limit or the per-run write limit, and records the result on the collection.
 */
class PlanRunner {
	private const DELETES = [SyncReport::PULL_DELETE, SyncReport::PUSH_DELETE];
	private const PUSHES = [SyncReport::PUSH_CREATE, SyncReport::PUSH_UPDATE, SyncReport::PUSH_DELETE];

	public function __construct(
		private CollectionMapper $collections,
	) {
	}

	/**
	 * @param list<array<string, mixed>> $plan
	 * @param int $known items known before the run, the base for the mass deletion check
	 * @param callable(int): string $refusedDeletes message for the held back deletion count
	 * @param callable(array<string, mixed>): void $execute
	 * @param callable(array<string, mixed>): string $label
	 * @param (callable(): int)|null $writes writes so far; null means no per-run write limit
	 * @param bool $pushFailureFails a failed or held back push also counts as a failure
	 * @return bool false when something failed that the next run has to see again
	 */
	public function run(
		array $plan,
		SyncContext $ctx,
		SyncReport $report,
		int $known,
		callable $refusedDeletes,
		callable $execute,
		callable $label,
		?callable $writes = null,
		int $writeLimit = 0,
		bool $pushFailureFails = false,
	): bool {
		$plan = array_values(array_filter($plan, static function (array $p) use ($report): bool {
			if ($p['action'] === 'none') {
				$report->unchanged++;
				return false;
			}
			return true;
		}));
		$deletions = count(array_filter($plan, static fn (array $p): bool => in_array($p['action'], self::DELETES, true)));
		$blockDeletes = !$ctx->allowMassDelete && SyncReport::tooManyDeletions($deletions, $known);
		if ($blockDeletes) {
			$report->error($refusedDeletes($deletions));
		}

		$ok = true;
		$pushBlocked = false;
		foreach ($plan as $p) {
			$action = $p['action'];
			if ($blockDeletes && in_array($action, self::DELETES, true)) {
				$ok = false;
				continue;
			}
			$isPush = in_array($action, self::PUSHES, true);
			if ($isPush && $writes !== null && !$pushBlocked && $writes() >= $writeLimit && !$ctx->preview) {
				$pushBlocked = true;
				$report->error(T::t('The per-run write limit (%s) was reached. The rest waits for the next sync.', [$writeLimit]));
			}
			if ($isPush && $pushBlocked && !$ctx->preview) {
				if ($pushFailureFails) {
					$ok = false;
				}
				continue;
			}
			try {
				$execute($p);
			} catch (\Throwable $e) {
				$report->error($label($p) . ': ' . $e->getMessage());
				if ($e instanceof GoogleApiException && $e->isRateLimit()) {
					$pushBlocked = true;
				}
				if (!$isPush || $pushFailureFails) {
					$ok = false;
				}
			}
		}
		return $ok;
	}

	/**
	 * Stores the run result. The sync token only moves on when $ok, so items
	 * that failed are listed again next time.
	 */
	public function finish(Collection $col, SyncReport $report, bool $ok, ?string $nextToken): void {
		if ($ok) {
			$col->setSyncToken($nextToken);
		}
		$col->setInitialized(1);
		$col->setLastSync(time());
		$col->setLastSummary($report->summary());
		$col->setLastError($report->errors === [] ? null : implode("\n", array_slice($report->errors, 0, 20)));
		$this->collections->update($col);
	}
}
