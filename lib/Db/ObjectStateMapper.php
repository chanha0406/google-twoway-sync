<?php

declare(strict_types=1);

namespace OCA\GoogleTwoWaySync\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<ObjectState>
 */
class ObjectStateMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'gtsync_objects', ObjectState::class);
	}

	/**
	 * @return list<ObjectState>
	 */
	public function findByCollection(int $collectionId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('collection_id', $qb->createNamedParameter($collectionId, IQueryBuilder::PARAM_INT)));
		/** @var list<ObjectState> $rows */
		$rows = $this->findEntities($qb);
		return $rows;
	}

	public function deleteByCollection(int $collectionId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('collection_id', $qb->createNamedParameter($collectionId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}
}
