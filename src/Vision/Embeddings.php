<?php

namespace App\Vision;

final readonly class Embeddings
{
    /** @param list<list<float>> $vectors */
    public function __construct(
        public string $model,
        public array $vectors,
    ) {
    }
}
