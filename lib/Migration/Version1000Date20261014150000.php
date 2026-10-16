<?php

declare(strict_types=1);

namespace OCA\GoogleTwoWaySync\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1000Date20261014150000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;

		if (!$schema->hasTable('gtsync_collections')) {
			$table = $schema->createTable('gtsync_collections');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			// calendar | contacts
			$table->addColumn('kind', Types::STRING, ['notnull' => true, 'length' => 16]);
			$table->addColumn('google_id', Types::STRING, ['notnull' => true, 'length' => 512]);
			$table->addColumn('google_hash', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('display_name', Types::STRING, ['notnull' => true, 'length' => 512, 'default' => '']);
			$table->addColumn('access_role', Types::STRING, ['notnull' => true, 'length' => 32, 'default' => '']);
			$table->addColumn('color', Types::STRING, ['notnull' => false, 'length' => 16, 'default' => null]);
			$table->addColumn('nc_id', Types::BIGINT, ['notnull' => true, 'default' => 0, 'unsigned' => true]);
			$table->addColumn('enabled', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			// 0 until the first real sync matched the existing objects on both sides
			$table->addColumn('initialized', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$table->addColumn('sync_token', Types::TEXT, ['notnull' => false]);
			$table->addColumn('last_sync', Types::BIGINT, ['notnull' => true, 'default' => 0, 'unsigned' => true]);
			$table->addColumn('last_error', Types::TEXT, ['notnull' => false]);
			$table->addColumn('last_summary', Types::TEXT, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['user_id', 'kind', 'google_hash'], 'gtsync_col_uniq');
			$table->addIndex(['enabled'], 'gtsync_col_enabled');
			$changed = true;
		}

		if (!$schema->hasTable('gtsync_objects')) {
			$table = $schema->createTable('gtsync_objects');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('collection_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			// event id of the master event, or person resourceName. Empty for ignored objects
			$table->addColumn('google_id', Types::STRING, ['notnull' => true, 'length' => 512, 'default' => '']);
			$table->addColumn('google_uid', Types::TEXT, ['notnull' => false]);
			// hash of the etags of the master event and its exceptions, or the person etag
			$table->addColumn('google_fp', Types::STRING, ['notnull' => true, 'length' => 128, 'default' => '']);
			$table->addColumn('google_updated', Types::BIGINT, ['notnull' => true, 'default' => 0, 'unsigned' => true]);
			$table->addColumn('nc_uri', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('nc_etag', Types::STRING, ['notnull' => true, 'length' => 128, 'default' => '']);
			// 1: local object that is never synchronised (cards that predate the cutover)
			$table->addColumn('ignored', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$table->addColumn('extra', Types::TEXT, ['notnull' => false]);
			$table->addColumn('last_sync', Types::BIGINT, ['notnull' => true, 'default' => 0, 'unsigned' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['collection_id', 'nc_uri'], 'gtsync_obj_uri');
			$table->addIndex(['collection_id', 'google_id'], 'gtsync_obj_gid');
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
