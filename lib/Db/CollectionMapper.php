<?php

declare(strict_types=1);

namespace OCA\GoogleTwoWaySync\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Collection>
 */
class CollectionMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'gtsync_collections', Collection::class);
	}

	/**
	 * @return list<Collection>
	 */
	public function findByUser(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('kind')
			->addOrderBy('id');
		/** @var list<Collection> $rows */
		$rows = $this->findEntities($qb);
		return $rows;
	}

	public function findByGoogleId(string $userId, string $kind, string $googleId): ?Collection {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('kind', $qb->createNamedParameter($kind)))
			->andWhere($qb->expr()->eq('google_hash', $qb->createNamedParameter(self::hash($googleId))));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	public function findForUser(string $userId, int $id): ?Collection {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * @return list<string>
	 */
	public function findUserIdsToSync(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('user_id')
			->from($this->getTableName())
			->where($qb->expr()->eq('enabled', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$ids = [];
		while (($row = $result->fetch()) !== false) {
			$ids[] = (string)$row['user_id'];
		}
		$result->closeCursor();
		return $ids;
	}

	public static function hash(string $googleId): string {
		return hash('sha256', $googleId);
	}
}
