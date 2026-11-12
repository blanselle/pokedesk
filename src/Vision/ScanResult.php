<?php

namespace App\Vision;

final readonly class ScanResult
{
    /** @param list<DetectedCard> $cards in reading order */
    public function __construct(
        public string $model,
        public array $cards,
    ) {
    }
}
