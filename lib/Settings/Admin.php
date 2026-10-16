<?php

declare(strict_types=1);

namespace OCA\GoogleTwoWaySync\Settings;

use OCA\GoogleTwoWaySync\AppInfo\Application;
use OCA\GoogleTwoWaySync\Service\TokenService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;
use OCP\Util;

class Admin implements ISettings {
	public function __construct(
		private TokenService $tokens,
	) {
	}

	public function getForm(): TemplateResponse {
		Util::addScript(Application::APP_ID, 'admin');
		Util::addStyle(Application::APP_ID, 'admin');
		return new TemplateResponse(Application::APP_ID, 'admin', [
			'state' => $this->tokens->adminCredentialState(),
		]);
	}

	public function getSection(): string {
		return Application::APP_ID;
	}

	public function getPriority(): int {
		return 10;
	}
}
