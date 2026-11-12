<?php

namespace App\Scan;

final readonly class Candidate
{
    public function __construct(
        public int $cardId,
        // Cosine similarity with the scanned card, in [-1, 1].
        public float $similarity,
    ) {
    }
}
