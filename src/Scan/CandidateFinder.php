<?php

namespace App\Scan;

use App\Repository\CardEmbeddingRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Proposes catalogue cards for a scanned one. A human always makes the final choice. */
final class CandidateFinder
{
    public function __construct(
        private readonly CardEmbeddingRepository $embeddings,
        #[Autowire('%app.vision_model%')]
        private readonly string $model,
        // Below this similarity nothing is proposed: the crop is probably not a card from the catalogue.
        #[Autowire('%app.scan.min_similarity%')]
        private readonly float $minSimilarity,
        #[Autowire('%app.scan.candidates%')]
        private readonly int $limit,
    ) {
    }

    /**
     * @param list<list<float>> $vectors the crop as photographed, then rotated by 180 degrees
     *
     * @return array{0: list<Candidate>, 1: bool} candidates best first, and whether the best one matched the rotated crop
     */
    public function find(array $vectors, string $language): array
    {
        $best = [];
        $upsideDown = false;
        $top = -1.0;

        foreach ($vectors as $orientation => $vector) {
            foreach ($this->embeddings->findNearest($this->model, $language, $vector, $this->limit) as $row) {
                // The same card can match both orientations: keep its best score.
                if (!isset($best[$row['card_id']]) || $row['similarity'] > $best[$row['card_id']]) {
                    $best[$row['card_id']] = $row['similarity'];
                }
                if ($row['similarity'] > $top) {
                    $top = $row['similarity'];
                    $upsideDown = 1 === $orientation;
                }
            }
        }

        arsort($best);
        $candidates = [];
        foreach (\array_slice($best, 0, $this->limit, true) as $cardId => $similarity) {
            if ($similarity >= $this->minSimilarity) {
                $candidates[] = new Candidate((int) $cardId, $similarity);
            }
        }

        return [$candidates, [] !== $candidates && $upsideDown];
    }
}
