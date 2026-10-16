<?php

declare(strict_types=1);

namespace OCA\GoogleTwoWaySync\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method void setUserId(string $userId)
 * @method string getUserId()
 * @method void setKind(string $kind)
 * @method string getKind()
 * @method void setGoogleId(string $googleId)
 * @method string getGoogleId()
 * @method void setGoogleHash(string $googleHash)
 * @method string getGoogleHash()
 * @method void setDisplayName(string $displayName)
 * @method string getDisplayName()
 * @method void setAccessRole(string $accessRole)
 * @method string getAccessRole()
 * @method void setColor(?string $color)
 * @method string|null getColor()
 * @method void setNcId(int $ncId)
 * @method int getNcId()
 * @method void setEnabled(int $enabled)
 * @method int getEnabled()
 * @method void setInitialized(int $initialized)
 * @method int getInitialized()
 * @method void setSyncToken(?string $syncToken)
 * @method string|null getSyncToken()
 * @method void setLastSync(int $lastSync)
 * @method int getLastSync()
 * @method void setLastError(?string $lastError)
 * @method string|null getLastError()
 * @method void setLastSummary(?string $lastSummary)
 * @method string|null getLastSummary()
 */
class Collection extends Entity {
	public const KIND_CALENDAR = 'calendar';
	public const KIND_CONTACTS = 'contacts';
	public const KIND_TASKS = 'tasks';

	protected $userId;
	protected $kind;
	protected $googleId;
	protected $googleHash;
	protected $displayName;
	protected $accessRole;
	protected $color;
	protected $ncId;
	protected $enabled;
	protected $initialized;
	protected $syncToken;
	protected $lastSync;
	protected $lastError;
	protected $lastSummary;

	public function __construct() {
		$this->addType('ncId', 'integer');
		$this->addType('enabled', 'integer');
		$this->addType('initialized', 'integer');
		$this->addType('lastSync', 'integer');
	}

	/**
	 * Holiday, solar term and moon phase calendars, and calendars shared without
	 * write access, can only be read through the API.
	 */
	public function isWritable(): bool {
		return $this->kind === self::KIND_CONTACTS
			|| $this->kind === self::KIND_TASKS
			|| in_array($this->accessRole, ['owner', 'writer'], true);
	}
}
