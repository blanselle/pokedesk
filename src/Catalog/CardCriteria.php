<?php

namespace App\Catalog;

use Doctrine\ORM\QueryBuilder;

use function Symfony\Component\String\u;

/** Criteria shared by the card lists (administration, stock); an empty value means "no filter". */
class CardCriteria
{
    // Name or TCGdex id, partial match.
    public ?string $q = null;
    public ?string $language = null;
    // Set id (exact) or set name (partial).
    public ?string $extension = null;
    // Number in the set (exact).
    public ?string $number = null;
    public ?string $rarity = null;
    public ?string $category = null;

    public function isEmpty(): bool
    {
        foreach ([$this->q, $this->language, $this->extension, $this->number, $this->rarity, $this->category] as $value) {
            if ('' !== trim((string) $value)) {
                return false;
            }
        }

        return true;
    }

    /** Restricts a query on cards (alias $card) joined with their sets (alias $set). */
    public function apply(QueryBuilder $qb, string $card = 'c', string $set = 's'): void
    {
        if (null !== $term = self::normalize($this->q)) {
            $qb->andWhere(\sprintf('(LOWER(UNACCENT(%1$s.name)) LIKE :q OR LOWER(%1$s.tcgdexId) LIKE :q)', $card))
                ->setParameter('q', '%'.addcslashes($term, '%_\\').'%');
        }

        if (null !== $language = self::normalize($this->language)) {
            $qb->andWhere($card.'.language = :language')->setParameter('language', $language);
        }

        if (null !== $extension = self::normalize($this->extension)) {
            $qb->andWhere(\sprintf('(LOWER(%1$s.tcgdexId) = :extensionId OR LOWER(UNACCENT(%1$s.name)) LIKE :extensionName)', $set))
                ->setParameter('extensionId', $extension)
                ->setParameter('extensionName', '%'.addcslashes($extension, '%_\\').'%');
        }

        if (null !== $number = self::normalize($this->number)) {
            $qb->andWhere(\sprintf('LOWER(%s.localId) = :number', $card))->setParameter('number', $number);
        }

        if ('' !== trim((string) $this->rarity)) {
            $qb->andWhere($card.'.rarity = :rarity')->setParameter('rarity', $this->rarity);
        }

        if ('' !== trim((string) $this->category)) {
            $qb->andWhere($card.'.category = :category')->setParameter('category', $this->category);
        }
    }

    // Lower-case, accent-free and trimmed, to match LOWER(UNACCENT(column)).
    private static function normalize(?string $value): ?string
    {
        $value = u((string) $value)->trim()->ascii()->lower()->toString();

        return '' === $value ? null : $value;
    }
}
