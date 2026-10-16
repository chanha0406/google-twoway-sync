<?php

declare(strict_types=1);

namespace OCA\GoogleTwoWaySync\BackgroundJob;

use OCA\GoogleTwoWaySync\Service\SyncService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

class SyncJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private SyncService $sync,
	) {
		parent::__construct($time);
		// Every cron run, including daytime. The instance only runs
		// time-insensitive jobs inside the maintenance window.
		$this->setInterval(1);
		$this->setAllowParallelRuns(false);
	}

	#[\Override]
	protected function run($argument): void {
		$this->sync->syncAll();
	}
}
