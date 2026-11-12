<?php

namespace App\Catalog;

use App\Entity\Card;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Stores catalogue card images on disk, once per card. */
final class CardImageDownloader
{
    // Only fetch from the TCGdex asset host, whatever URL the API answer contains.
    private const ALLOWED_PREFIX = 'https://assets.tcgdex.net/';
    private const IMAGE_SUFFIX = '/high.webp';
    private const CHUNK_SIZE = 16;

    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly Filesystem $filesystem,
        #[Autowire('%app.catalog_images_dir%')]
        private readonly string $directory,
    ) {
    }

    /** @param list<Card> $cards */
    public function download(array $cards, ImportReport $report): void
    {
        $todo = [];

        foreach ($cards as $card) {
            $url = $card->getImageUrl();

            if (null === $url || !str_starts_with($url, self::ALLOWED_PREFIX)) {
                continue;
            }

            $relative = $this->relativePath($card);

            if ($this->filesystem->exists($this->directory.'/'.$relative)) {
                $card->setImagePath($relative);
                ++$report->imagesSkipped;
                continue;
            }

            $todo[] = [$card, $url.self::IMAGE_SUFFIX, $relative];
        }

        foreach (array_chunk($todo, self::CHUNK_SIZE) as $chunk) {
            $responses = [];
            foreach ($chunk as $i => [, $url]) {
                $responses[$i] = $this->client->request('GET', $url);
            }

            foreach ($chunk as $i => [$card, , $relative]) {
                try {
                    $response = $responses[$i];
                    $contentType = $response->getHeaders(false)['content-type'][0] ?? '';

                    if (200 !== $response->getStatusCode() || !str_starts_with($contentType, 'image/')) {
                        ++$report->imagesMissing;
                        continue;
                    }

                    $this->filesystem->dumpFile($this->directory.'/'.$relative, $response->getContent());
                    $card->setImagePath($relative);
                    ++$report->imagesDownloaded;
                } catch (ExceptionInterface|IOExceptionInterface $e) {
                    $report->error(\sprintf('Image of %s/%s: %s', $card->getLanguage(), $card->getTcgdexId(), $e->getMessage()));
                }
            }
        }
    }

    private function relativePath(Card $card): string
    {
        return \sprintf(
            '%s/%s/%s.webp',
            $this->safe($card->getLanguage()),
            $this->safe($card->getSet()->getTcgdexId()),
            $this->safe($card->getLocalId()),
        );
    }

    // Path segments come from the API: keep them free of separators and dots.
    private function safe(string $segment): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_-]/', '_', $segment);

        return '' === $safe ? '_' : $safe;
    }
}
