<?php

namespace App\Command;

use App\Catalog\CatalogImporter;
use App\Catalog\ImportOptions;
use App\Catalog\ImportReport;
use App\Catalog\TcgdexClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

#[AsCommand(name: 'app:catalog:import', description: 'Imports the TCGdex catalogue (sets, cards, images) into the database')]
final class CatalogImportCommand
{
    public function __construct(
        private readonly TcgdexClient $tcgdex,
        private readonly CatalogImporter $importer,
    ) {
    }

    /**
     * @param list<string> $lang
     * @param list<string> $set
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Language to import, repeatable (e.g. fr, en, ja)', shortcut: 'l')]
        array $lang = ['fr', 'en'],
        #[Option(description: 'Only import this set id, repeatable (e.g. base1)')]
        array $set = [],
        #[Option(description: 'Maximum number of cards to import per language')]
        ?int $limit = null,
        #[Option(description: 'Do not download card images')]
        bool $noImages = false,
        #[Option(description: 'Ignore the 24h cache of TCGdex answers')]
        bool $refresh = false,
    ): int {
        foreach ($lang as $language) {
            if (!preg_match('/^[a-z]{2}(-[a-z]{2})?$/', $language)) {
                $io->error(\sprintf('Invalid language "%s" (expected a code such as fr, en or zh-tw).', $language));

                return Command::INVALID;
            }
        }

        if (null !== $limit && $limit < 1) {
            $io->error('--limit must be a positive number.');

            return Command::INVALID;
        }

        $options = new ImportOptions(images: !$noImages, refresh: $refresh);
        $failed = false;

        foreach (array_unique($lang) as $language) {
            $io->section(\sprintf('Language: %s', $language));
            $report = $this->importLanguage($io, $language, $set, $limit, $options);
            $failed = $failed || $report->hasErrors();

            $io->definitionList(
                ['Cards processed' => $report->processed],
                ['Sets excluded (Pokémon TCG Pocket)' => $report->setsExcluded],
                ['Created' => $report->created],
                ['Updated' => $report->updated],
                ['Images downloaded' => $report->imagesDownloaded],
                ['Images already present' => $report->imagesSkipped],
                ['Images unavailable' => $report->imagesMissing],
                ['Marked obsolete' => $report->obsolete],
                ['Errors' => $report->errorCount],
            );

            if ($report->hasErrors()) {
                $io->warning($report->errors());
            }
        }

        if ($failed) {
            $io->error('The import finished with errors.');

            return Command::FAILURE;
        }

        $io->success('Import finished.');

        return Command::SUCCESS;
    }

    /** @param list<string> $onlySets */
    private function importLanguage(SymfonyStyle $io, string $language, array $onlySets, ?int $limit, ImportOptions $options): ImportReport
    {
        $report = new ImportReport();
        $startedAt = new \DateTimeImmutable();

        try {
            $setIds = array_column($this->tcgdex->sets($language, $options->refresh), 'id');
        } catch (ExceptionInterface $e) {
            $report->error(\sprintf('Could not list the sets: %s', $e->getMessage()));

            return $report;
        }

        if ([] !== $onlySets) {
            if ([] !== $unknown = array_diff($onlySets, $setIds)) {
                $io->warning(\sprintf('Unknown set(s) for "%s": %s', $language, implode(', ', $unknown)));
            }
            $setIds = array_values(array_intersect($setIds, $onlySets));
        }

        $remaining = $limit ?? \PHP_INT_MAX;

        foreach ($io->progressIterate($setIds) as $setId) {
            if ($remaining <= 0) {
                break;
            }

            $setReport = $this->importer->importSet($language, $setId, $options, $remaining);
            $remaining -= $setReport->processed;
            $report->merge($setReport);
        }

        // A partial or faulty run cannot tell which cards disappeared from the API.
        if ([] === $onlySets && null === $limit && !$report->hasErrors()) {
            $report->obsolete = $this->importer->markNotSeenSinceAsObsolete($language, $startedAt);
        } else {
            $io->note('Obsolete cards were not checked (partial run or errors).');
        }

        return $report;
    }
}
