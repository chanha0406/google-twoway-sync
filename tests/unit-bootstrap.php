<?php

declare(strict_types=1);

/*
 * Bootstrap for tests/Unit: pure classes only, no Nextcloud instance.
 * Sabre comes from a Nextcloud tree (NC_ROOT, default /nc) for TodoIcs.
 */
spl_autoload_register(static function (string $class): void {
	$prefix = 'OCA\\GoogleTwoWaySync\\';
	if (str_starts_with($class, $prefix)) {
		$file = __DIR__ . '/../lib/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
		if (is_file($file)) {
			require $file;
		}
	}
});
$nc = getenv('NC_ROOT') ?: '/nc';
if (is_file($nc . '/3rdparty/autoload.php')) {
	require_once $nc . '/3rdparty/autoload.php';
}
