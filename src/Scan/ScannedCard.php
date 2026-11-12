<?php

namespace App\Scan;

/** One card found in the photo, with what the user decided about it. */
final class ScannedCard
{
    /** @param list<Candidate> $candidates best first; empty when nothing resembles the card enough */
    public function __construct(
        public readonly string $crop,
        public readonly array $candidates,
        // The best match was found with the crop rotated by 180 degrees: it is shown upside down.
        public readonly bool $upsideDown,
        public ?int $choice = null,
        public bool $decided = false,
    ) {
    }

    public function isReviewable(): bool
    {
        return [] !== $this->candidates;
    }

    /** The user's pick, or the best candidate while nothing is picked. */
    public function selected(): ?int
    {
        return $this->decided ? $this->choice : ($this->candidates[0]->cardId ?? null);
    }
}
