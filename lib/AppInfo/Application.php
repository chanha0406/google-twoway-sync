<?php

declare(strict_types=1);

namespace OCA\GoogleTwoWaySync\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
	public const APP_ID = 'googletwowaysync';
	public const LEGACY_APP_ID = 'google_synchronization';

	public const SCOPES = [
		'https://www.googleapis.com/auth/calendar',
		'https://www.googleapis.com/auth/contacts',
		'https://www.googleapis.com/auth/tasks',
	];

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
	}

	public function boot(IBootContext $context): void {
	}
}
