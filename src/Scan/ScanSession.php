<?php

namespace App\Scan;

/** A scan in progress. It only lives in the cache, for as long as the user keeps working on it. */
final class ScanSession
{
    /** @param list<ScannedCard> $cards in reading order */
    public function __construct(
        public readonly string $token,
        public readonly int $userId,
        public readonly string $language,
        public array $cards,
    ) {
    }

    /** @return list<int> positions of the cards the user is asked about */
    public function reviewable(): array
    {
        return array_keys(array_filter($this->cards, static fn (ScannedCard $card) => $card->isReviewable()));
    }

    public function firstUndecided(): ?int
    {
        foreach ($this->reviewable() as $position) {
            if (!$this->cards[$position]->decided) {
                return $position;
            }
        }

        return null;
    }

    /** @return array<int, int> number of validated copies per catalogue card id */
    public function validated(): array
    {
        $counts = [];
        foreach ($this->cards as $card) {
            if ($card->decided && null !== $card->choice) {
                $counts[$card->choice] = ($counts[$card->choice] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /** @return list<int> positions of the cards that will not be added: nothing recognised, or passed */
    public function notValidated(): array
    {
        return array_keys(array_filter($this->cards, static fn (ScannedCard $card) => !$card->decided || null === $card->choice));
    }

    /** @return list<int> every catalogue card id proposed or chosen, to load them at once */
    public function cardIds(): array
    {
        $ids = [];
        foreach ($this->cards as $card) {
            foreach ($card->candidates as $candidate) {
                $ids[$candidate->cardId] = true;
            }
        }

        return array_keys($ids);
    }
}
