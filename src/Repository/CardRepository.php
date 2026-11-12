<?php

namespace App\Repository;

use App\Admin\CardFilter;
use App\Entity\Card;
use App\Entity\CardStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Card>
 */
class CardRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Card::class);
    }

    /**
     * @param list<string> $tcgdexIds
     *
     * @return array<string, Card> indexed by TCGdex id
     */
    public function findByTcgdexIds(string $language, array $tcgdexIds): array
    {
        $cards = [];
        foreach ($this->findBy(['language' => $language, 'tcgdexId' => $tcgdexIds]) as $card) {
            $cards[$card->getTcgdexId()] = $card;
        }

        return $cards;
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, Card> indexed by id
     */
    public function findByIdsIndexed(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $cards = [];
        foreach ($this->createQueryBuilder('c')
            ->addSelect('s')
            ->join('c.set', 's')
            ->where('c.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult() as $card) {
            $cards[$card->getId()] = $card;
        }

        return $cards;
    }

    /** Marks active cards not seen since $before as obsolete and returns how many were changed. */
    public function markNotSeenSinceAsObsolete(string $language, \DateTimeImmutable $before): int
    {
        return (int) $this->getEntityManager()
            ->createQuery('UPDATE '.Card::class.' c SET c.status = :obsolete WHERE c.language = :language AND c.status = :active AND c.lastSeenAt < :before')
            ->setParameter('obsolete', CardStatus::Obsolete)
            ->setParameter('active', CardStatus::Active)
            ->setParameter('language', $language)
            ->setParameter('before', $before)
            ->execute();
    }

    /**
     * One page of cards matching the filter, ordered by language, set and number.
     *
     * @return Paginator<Card>
     */
    public function search(CardFilter $filter, int $page, int $perPage): Paginator
    {
        $qb = $this->createQueryBuilder('c')
            ->innerJoin('c.set', 's')
            ->addSelect('s')
            ->orderBy('c.language', 'ASC')
            ->addOrderBy('s.tcgdexId', 'ASC')
            // Length first so that number 2 sorts before 10.
            ->addOrderBy('LENGTH(c.localId)', 'ASC')
            ->addOrderBy('c.localId', 'ASC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        $filter->apply($qb, 'c', 's');

        if (null !== $filter->status) {
            $qb->andWhere('c.status = :status')->setParameter('status', $filter->status);
        }

        if ($filter->missingImage) {
            $qb->andWhere('c.imagePath IS NULL');
        }

        return new Paginator($qb, fetchJoinCollection: false);
    }

    /**
     * The same card (same TCGdex id) in the other languages.
     *
     * @return list<Card>
     */
    public function findOtherLanguages(Card $card): array
    {
        return $this->createQueryBuilder('c')
            ->innerJoin('c.set', 's')
            ->addSelect('s')
            ->where('c.tcgdexId = :tcgdexId')
            ->andWhere('c.id != :id')
            ->setParameter('tcgdexId', $card->getTcgdexId())
            ->setParameter('id', $card->getId())
            ->orderBy('c.language')
            ->getQuery()
            ->getResult();
    }

    /** @return list<string> */
    public function distinctLanguages(): array
    {
        return $this->createQueryBuilder('c')->select('DISTINCT c.language')->orderBy('c.language')->getQuery()->getSingleColumnResult();
    }

    /** @return list<string> */
    public function distinctRarities(): array
    {
        return $this->createQueryBuilder('c')->select('DISTINCT c.rarity')->where('c.rarity IS NOT NULL')->orderBy('c.rarity')->getQuery()->getSingleColumnResult();
    }

    /** @return list<string> */
    public function distinctCategories(): array
    {
        return $this->createQueryBuilder('c')->select('DISTINCT c.category')->where('c.category IS NOT NULL')->orderBy('c.category')->getQuery()->getSingleColumnResult();
    }
}
