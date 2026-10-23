<?php

declare(strict_types=1);

namespace OCA\GoogleTwoWaySync\Service;

/**
 * Nextcloud stores a trashed calendar's deletion time under the Nextcloud
 * namespace. Older code looked only at the ownCloud namespace, which this
 * server does not set, so a trashed calendar stayed linked and kept syncing.
 */
final class CalendarTrash {
	public static function isInTrash(array $calendar): bool {
		return ($calendar['{http://nextcloud.com/ns}deleted-at'] ?? null) !== null
			|| ($calendar['{http://owncloud.org/ns}deleted-at'] ?? null) !== null;
	}
}
