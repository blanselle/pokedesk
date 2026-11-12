<?php

namespace App\Repository;

use App\Entity\CardEmbedding;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CardEmbedding>
 */
class CardEmbeddingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CardEmbedding::class);
    }

    /**
     * Active catalogue cards with a local image but no fingerprint for the model yet.
     *
     * @param list<string> $languages
     *
     * @return list<array{id: int, image_path: string}>
     */
    public function findMissing(string $model, array $languages, int $afterId, int $limit): array
    {
        $placeholders = implode(',', array_fill(0, \count($languages), '?'));

        return $this->getEntityManager()->getConnection()->fetchAllAssociative(
            "SELECT c.id, c.image_path FROM card c
             WHERE c.id > ? AND c.language IN ($placeholders) AND c.status = 'active' AND c.image_path IS NOT NULL
               AND NOT EXISTS (SELECT 1 FROM card_embedding e WHERE e.card_id = c.id AND e.model = ?)
             ORDER BY c.id LIMIT ?",
            [$afterId, ...$languages, $model, $limit],
        );
    }

    /** @param list<string> $languages */
    public function countMissing(string $model, array $languages): int
    {
        $placeholders = implode(',', array_fill(0, \count($languages), '?'));

        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM card c
             WHERE c.language IN ($placeholders) AND c.status = 'active' AND c.image_path IS NOT NULL
               AND NOT EXISTS (SELECT 1 FROM card_embedding e WHERE e.card_id = c.id AND e.model = ?)",
            [...$languages, $model],
        );
    }

    /** @param array<int, list<float>> $vectorsByCardId */
    public function save(string $model, array $vectorsByCardId): void
    {
        $connection = $this->getEntityManager()->getConnection();

        foreach ($vectorsByCardId as $cardId => $vector) {
            $connection->executeStatement(
                'INSERT INTO card_embedding (card_id, model, embedding, created_at) VALUES (?, ?, CAST(? AS vector), NOW())
                 ON CONFLICT (card_id, model) DO UPDATE SET embedding = EXCLUDED.embedding, created_at = EXCLUDED.created_at',
                [$cardId, $model, json_encode($vector, \JSON_THROW_ON_ERROR)],
            );
        }
    }

    /** @return list<string> languages for which the catalogue can be searched */
    public function embeddedLanguages(string $model): array
    {
        return $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT DISTINCT c.language FROM card_embedding e JOIN card c ON c.id = e.card_id WHERE e.model = ? ORDER BY c.language',
            [$model],
        );
    }

    public function countForModel(string $model): int
    {
        return (int) $this->getEntityManager()->getConnection()->fetchOne('SELECT COUNT(*) FROM card_embedding WHERE model = ?', [$model]);
    }

    /**
     * Cards closest to a vector, among the active cards of one language that can be proposed.
     *
     * @param list<float> $vector
     *
     * @return list<array{card_id: int, similarity: float}> best first, similarity in [-1, 1]
     */
    public function findNearest(string $model, string $language, array $vector, int $limit): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            "SELECT c.id AS card_id, 1 - (e.embedding <=> CAST(:vector AS vector)) AS similarity
             FROM card_embedding e
             JOIN card c ON c.id = e.card_id
             WHERE e.model = :model AND c.language = :language AND c.status = 'active' AND c.excluded_from_scan = FALSE
             ORDER BY e.embedding <=> CAST(:vector AS vector)
             LIMIT :limit",
            ['vector' => json_encode($vector, \JSON_THROW_ON_ERROR), 'model' => $model, 'language' => $language, 'limit' => $limit],
        );

        return array_map(static fn (array $row) => ['card_id' => (int) $row['card_id'], 'similarity' => (float) $row['similarity']], $rows);
    }
}
