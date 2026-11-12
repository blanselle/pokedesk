<?php

namespace App\Command;

use App\Repository\CardEmbeddingRepository;
use App\Vision\Vision;
use App\Vision\VisionException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(name: 'app:catalog:embed', description: 'Computes the visual fingerprints of the catalogue images that have none yet')]
final class CatalogEmbedCommand
{
    public function __construct(
        private readonly Vision $vision,
        private readonly CardEmbeddingRepository $embeddings,
        #[Autowire('%app.vision_model%')]
        private readonly string $model,
        #[Autowire('%app.catalog_images_dir%')]
        private readonly string $imagesDir,
    ) {
    }

    /** @param list<string> $lang */
    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Language to process, repeatable (e.g. fr, en)', shortcut: 'l')]
        array $lang = ['fr', 'en'],
        #[Option(description: 'Maximum number of images to process in this run')]
        ?int $limit = null,
        #[Option(description: 'Images sent to the vision service at once (1 to 64)')]
        int $batch = 32,
    ): int {
        foreach ($lang as $language) {
            if (!preg_match('/^[a-z]{2}(-[a-z]{2})?$/', $language)) {
                $io->error(\sprintf('Invalid language "%s" (expected a code such as fr, en or zh-tw).', $language));

                return Command::INVALID;
            }
        }

        if ($batch < 1 || $batch > 64 || (null !== $limit && $limit < 1)) {
            $io->error('--batch must be between 1 and 64 and --limit a positive number.');

            return Command::INVALID;
        }

        $languages = array_values(array_unique($lang));
        $total = $this->embeddings->countMissing($this->model, $languages);
        $remaining = null === $limit ? $total : min($limit, $total);
        $io->text(\sprintf('%d image(s) without fingerprint for model "%s"; processing %d.', $total, $this->model, $remaining));

        $progress = $io->createProgressBar($remaining);
        $errors = [];
        $done = 0;
        $afterId = 0;

        while ($remaining > 0 && [] !== $rows = $this->embeddings->findMissing($this->model, $languages, $afterId, min($batch, $remaining))) {
            $afterId = (int) end($rows)['id'];
            $remaining -= \count($rows);

            $files = [];
            foreach ($rows as $row) {
                $path = $this->imagesDir.'/'.$row['image_path'];
                if (str_contains($row['image_path'], '..') || !is_file($path)) {
                    $errors[] = \sprintf('Card %d: image file not found (%s)', $row['id'], $row['image_path']);
                    continue;
                }
                $files[(int) $row['id']] = (string) file_get_contents($path);
            }

            $done += $this->embedFiles($files, $errors);
            $progress->advance(\count($rows));
        }

        $progress->finish();
        $io->newLine(2);
        $io->definitionList(['Fingerprints computed' => $done], ['Errors' => \count($errors)]);

        if ([] !== $errors) {
            $io->warning(\array_slice($errors, 0, 20));

            return Command::FAILURE;
        }

        $io->success('Done.');

        return Command::SUCCESS;
    }

    /**
     * @param array<int, string> $files   card id => image content
     * @param list<string>       $errors
     */
    private function embedFiles(array $files, array &$errors): int
    {
        if ([] === $files) {
            return 0;
        }

        try {
            $embeddings = $this->vision->embed(array_values($files));
            $this->assertModel($embeddings->model);
            $this->embeddings->save($this->model, array_combine(array_keys($files), $embeddings->vectors));

            return \count($files);
        } catch (VisionException $e) {
            if (1 === \count($files)) {
                $errors[] = \sprintf('Card %d: %s', array_key_first($files), $e->getMessage());

                return 0;
            }
        }

        // One unreadable image fails the whole batch: retry one by one to isolate it.
        $done = 0;
        foreach ($files as $cardId => $content) {
            $done += $this->embedFiles([$cardId => $content], $errors);
        }

        return $done;
    }

    private function assertModel(string $served): void
    {
        if ($served !== $this->model) {
            throw new \LogicException(\sprintf('The vision service runs "%s" but the application expects "%s": fix VISION_MODEL or app.vision_model.', $served, $this->model));
        }
    }
}
