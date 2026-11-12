<?php

namespace App\Vision;

/** The vision service: the only place that turns pixels into vectors and finds cards in photos. */
interface Vision
{
    /**
     * Fingerprints of catalogue images, in the same order.
     *
     * @param list<string> $images raw image files
     *
     * @throws VisionException
     */
    public function embed(array $images): Embeddings;

    /**
     * Detects the cards of a photo.
     *
     * @throws VisionException
     */
    public function scan(string $photo): ScanResult;
}
