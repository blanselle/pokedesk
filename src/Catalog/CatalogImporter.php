<?php

namespace App\Catalog;

use App\Entity\Card;
use App\Entity\CardSet;
use App\Repository\CardRepository;
use App\Repository\CardSetRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

/** Upserts catalogue sets and cards from TCGdex, one set at a time. */
final class CatalogImporter
{
    // Digital-only cards of the Pokémon TCG Pocket mobile game: never imported, whatever the options.
    private const EXCLUDED_SERIES = ['tcgp'];

    public function __construct(
        private readonly TcgdexClient $tcgdex,
        private readonly CardImageDownloader $images,
        private readonly EntityManagerInterface $entityManager,
        private readonly CardRepository $cards,
        private readonly CardSetRepository $sets,
    ) {
    }

    /** @param int $maxCards Stops after this many cards of the set (used to run partial imports) */
    public function importSet(string $language, string $setId, ImportOptions $options, int $maxCards = \PHP_INT_MAX): ImportReport
    {
        $report = new ImportReport();

        try {
            $setData = $this->tcgdex->set($language, $setId, $options->refresh);
        } catch (ExceptionInterface $e) {
            $report->error(\sprintf('Set %s/%s: %s', $language, $setId, $e->getMessage()));

            return $report;
        }

        if (\in_array($setData['serie']['id'] ?? null, self::EXCLUDED_SERIES, true)) {
            ++$report->setsExcluded;

            return $report;
        }

        $set = $this->sets->findOneBy(['tcgdexId' => $setId, 'language' => $language]) ?? new CardSet($setId, $language);
        $set->refreshFrom($setData);
        $this->entityManager->persist($set);

        $ids = array_slice(array_column($setData['cards'] ?? [], 'id'), 0, $maxCards);
        $existing = $this->cards->findByTcgdexIds($language, $ids);
        $seenAt = new \DateTimeImmutable();
        $imported = [];

        foreach ($this->tcgdex->cards($language, $ids, $options->refresh) as $id => $detail) {
            ++$report->processed;

            if (null === $detail) {
                $report->error(\sprintf('Card %s/%s could not be fetched', $language, $id));
                continue;
            }

            $card = $existing[$id] ?? null;

            if (null === $card) {
                $card = new Card($id, $language, $set);
                ++$report->created;
            } else {
                ++$report->updated;
            }

            $card->refreshFrom($detail, $set, $seenAt);
            $this->entityManager->persist($card);
            $imported[] = $card;
        }

        if ($options->images) {
            $this->images->download($imported, $report);
        }

        $this->entityManager->flush();
        // Keeps memory flat over thousands of cards.
        $this->entityManager->clear();

        return $report;
    }

    /** Only call after a complete, error-free import of the language: unseen cards are marked obsolete. */
    public function markNotSeenSinceAsObsolete(string $language, \DateTimeImmutable $before): int
    {
        return $this->cards->markNotSeenSinceAsObsolete($language, $before);
    }
}
