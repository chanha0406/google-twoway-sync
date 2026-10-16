<?php

declare(strict_types=1);

namespace OCA\GoogleTwoWaySync\Db;

use OCP\AppFramework\Db\Entity;

/**
 * State of one synchronised object at the end of the last sync: the Google
 * side (event with its exceptions, or person) and the Nextcloud side (calendar
 * object or card).
 *
 * @method void setCollectionId(int $collectionId)
 * @method int getCollectionId()
 * @method void setGoogleId(string $googleId)
 * @method string getGoogleId()
 * @method void setGoogleUid(?string $googleUid)
 * @method string|null getGoogleUid()
 * @method void setGoogleFp(string $googleFp)
 * @method string getGoogleFp()
 * @method void setGoogleUpdated(int $googleUpdated)
 * @method int getGoogleUpdated()
 * @method void setNcUri(string $ncUri)
 * @method string getNcUri()
 * @method void setNcEtag(string $ncEtag)
 * @method string getNcEtag()
 * @method void setIgnored(int $ignored)
 * @method int getIgnored()
 * @method void setExtra(?string $extra)
 * @method string|null getExtra()
 * @method void setLastSync(int $lastSync)
 * @method int getLastSync()
 */
class ObjectState extends Entity {
	protected $collectionId;
	protected $googleId;
	protected $googleUid;
	protected $googleFp;
	protected $googleUpdated;
	protected $ncUri;
	protected $ncEtag;
	protected $ignored;
	protected $extra;
	protected $lastSync;

	public function __construct() {
		$this->addType('collectionId', 'integer');
		$this->addType('googleUpdated', 'integer');
		$this->addType('ignored', 'integer');
		$this->addType('lastSync', 'integer');
	}
}
