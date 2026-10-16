<?php

declare(strict_types=1);

namespace OCA\GoogleTwoWaySync\L10n;

use OCA\GoogleTwoWaySync\AppInfo\Application;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Server;

final class T {
	private static ?string $userId = null;

	public static function forUser(?string $userId): void {
		self::$userId = $userId;
	}

	/**
	 * @param array<string, mixed> $parameters
	 */
	public static function t(string $text, array $parameters = []): string {
		$factory = Server::get(IFactory::class);
		$lang = null;
		if (self::$userId !== null) {
			$user = Server::get(IUserManager::class)->get(self::$userId);
			if ($user !== null) {
				$lang = $factory->getUserLanguage($user);
			}
		}
		return $factory->get(Application::APP_ID, $lang)->t($text, $parameters);
	}
}
