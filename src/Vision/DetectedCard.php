<?php

namespace App\Vision;

final readonly class DetectedCard
{
    /**
     * @param list<list<float>> $quad       corners in the photo, top-left first, clockwise
     * @param string            $crop       JPEG preview of the card
     * @param list<list<float>> $embeddings vector of the crop as is, then rotated by 180 degrees
     */
    public function __construct(
        public array $quad,
        public string $crop,
        public array $embeddings,
    ) {
    }
}
