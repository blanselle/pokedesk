<?php

namespace App\Repository;

use App\Admin\CardFilter;
use App\Entity\Card;
use App\Entity\CardStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

use function Symfony\Component\String\u;

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

        if (null !== $term = $this->normalize($filter->q)) {
            $qb->andWhere('LOWER(UNACCENT(c.name)) LIKE :q OR LOWER(c.tcgdexId) LIKE :q')
                ->setParameter('q', '%'.addcslashes($term, '%_\\').'%');
        }

        if (null !== $language = $this->normalize($filter->language)) {
            $qb->andWhere('c.language = :language')->setParameter('language', $language);
        }

        if (null !== $extension = $this->normalize($filter->extension)) {
            $qb->andWhere('(LOWER(s.tcgdexId) = :extensionId OR LOWER(UNACCENT(s.name)) LIKE :extensionName)')
                ->setParameter('extensionId', $extension)
                ->setParameter('extensionName', '%'.addcslashes($extension, '%_\\').'%');
        }

        if (null !== $number = $this->normalize($filter->number)) {
            $qb->andWhere('LOWER(c.localId) = :number')->setParameter('number', $number);
        }

        if (null !== $filter->rarity && '' !== $filter->rarity) {
            $qb->andWhere('c.rarity = :rarity')->setParameter('rarity', $filter->rarity);
        }

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

    // Lower-case, accent-free and trimmed, to match LOWER(UNACCENT(column)).
    private function normalize(?string $value): ?string
    {
        $value = u((string) $value)->trim()->ascii()->lower()->toString();

        return '' === $value ? null : $value;
    }
}
