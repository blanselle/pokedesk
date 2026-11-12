<?php

namespace App\Repository;

use App\Entity\StockItem;
use App\Entity\User;
use App\Stock\StockCriteria;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<StockItem>
 */
class StockItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StockItem::class);
    }

    /**
     * Adds copies to a user's stock: existing lines are incremented, new ones created, all or nothing.
     * The increment happens in SQL, so concurrent additions never lose a quantity.
     *
     * @param array<int, int> $quantitiesByCardId
     */
    public function addQuantities(User $user, array $quantitiesByCardId): void
    {
        $connection = $this->getEntityManager()->getConnection();

        $connection->transactional(static function () use ($connection, $user, $quantitiesByCardId): void {
            foreach ($quantitiesByCardId as $cardId => $quantity) {
                if ($quantity < 1) {
                    continue;
                }

                $connection->executeStatement(
                    'INSERT INTO stock_item (user_id, card_id, quantity, updated_at) VALUES (?, ?, ?, NOW())
                     ON CONFLICT (user_id, card_id) DO UPDATE SET quantity = stock_item.quantity + EXCLUDED.quantity, updated_at = NOW()',
                    [$user->getId(), $cardId, $quantity],
                );
            }
        });
    }

    /**
     * Changes a line's quantity by $delta in a single statement, so concurrent changes add up.
     * Returns false when the line is not the user's or the result would be below 1 (use deleteOwned() for that).
     */
    public function adjustQuantity(User $user, int $itemId, int $delta): bool
    {
        return 1 === $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE stock_item SET quantity = quantity + ?::int, updated_at = NOW() WHERE id = ? AND user_id = ? AND quantity + ?::int >= 1',
            [$delta, $itemId, $user->getId(), $delta],
        );
    }

    /**
     * @param list<int> $ids
     *
     * @return list<StockItem> the user's lines among $ids, with card and set
     */
    public function findOwned(User $user, array $ids): array
    {
        return $this->createQueryBuilder('s')
            ->innerJoin('s.card', 'c')
            ->innerJoin('c.set', 'cs')
            ->addSelect('c', 'cs')
            ->where('s.user = :user')
            ->andWhere('s.id IN (:ids)')
            ->setParameter('user', $user)
            ->setParameter('ids', $ids)
            ->orderBy('c.name')
            ->addOrderBy('s.id')
            ->getQuery()
            ->getResult();
    }

    /** @param list<int> $ids */
    public function deleteOwned(User $user, array $ids): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM stock_item WHERE user_id = ? AND id IN (?)',
            [$user->getId(), $ids],
            [ParameterType::INTEGER, ArrayParameterType::INTEGER],
        );
    }

    /** @return Paginator<StockItem> */
    public function findPageForUser(User $user, StockCriteria $criteria, int $page, int $perPage): Paginator
    {
        $query = $this->createQueryBuilder('s')
            ->innerJoin('s.card', 'c')
            ->innerJoin('c.set', 'cs')
            ->addSelect('c', 'cs')
            ->where('s.user = :user')
            ->setParameter('user', $user)
            ->orderBy('s.updatedAt', 'DESC')
            ->addOrderBy('s.id', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);
        $this->applyCriteria($query, $criteria);

        return new Paginator($query, fetchJoinCollection: false);
    }

    /** Number of copies in the user's stock, restricted to the criteria when given. */
    public function totalCopies(User $user, ?StockCriteria $criteria = null): int
    {
        $query = $this->createQueryBuilder('s')
            ->select('COALESCE(SUM(s.quantity), 0)')
            ->innerJoin('s.card', 'c')
            ->innerJoin('c.set', 'cs')
            ->where('s.user = :user')
            ->setParameter('user', $user);
        if (null !== $criteria) {
            $this->applyCriteria($query, $criteria);
        }

        return (int) $query->getQuery()->getSingleScalarResult();
    }

    /**
     * Copies and lines of the user's whole stock, grouped by language, extension, rarity and category,
     * most copies first. A null key is a card without that information.
     *
     * @return array<'language'|'extension'|'rarity'|'category', list<array{key: string|null, label: string|null, copies: int, lines: int}>>
     */
    public function breakdowns(User $user): array
    {
        return [
            'language' => $this->groupBy($user, 'c.language', 'c.language'),
            'extension' => $this->groupBy($user, 'cs.tcgdexId', 'MIN(cs.name)'),
            'rarity' => $this->groupBy($user, 'c.rarity', 'c.rarity'),
            'category' => $this->groupBy($user, 'c.category', 'c.category'),
        ];
    }

    /** @return list<array{key: string|null, label: string|null, copies: int, lines: int}> */
    private function groupBy(User $user, string $key, string $label): array
    {
        $rows = $this->createQueryBuilder('s')
            ->select($key.' AS k', $label.' AS label', 'SUM(s.quantity) AS copies', 'COUNT(s.id) AS lines')
            ->innerJoin('s.card', 'c')
            ->innerJoin('c.set', 'cs')
            ->where('s.user = :user')
            ->setParameter('user', $user)
            ->groupBy($key)
            ->orderBy('copies', 'DESC')
            ->addOrderBy('label', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row) => ['key' => $row['k'], 'label' => $row['label'], 'copies' => (int) $row['copies'], 'lines' => (int) $row['lines']], $rows);
    }

    private function applyCriteria(QueryBuilder $query, StockCriteria $criteria): void
    {
        $criteria->apply($query, 'c', 'cs');

        if (null !== $criteria->minQuantity) {
            $query->andWhere('s.quantity >= :minQuantity')->setParameter('minQuantity', $criteria->minQuantity);
        }
        if (null !== $criteria->maxQuantity) {
            $query->andWhere('s.quantity <= :maxQuantity')->setParameter('maxQuantity', $criteria->maxQuantity);
        }
    }
}
