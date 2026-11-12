<?php

namespace App\Tests\Support;

use App\Entity\CardEmbedding;
use App\Vision\DetectedCard;
use App\Vision\Embeddings;
use App\Vision\ScanResult;
use App\Vision\Vision;
use App\Vision\VisionException;

/** Stands in for the vision service in tests: vectors are derived from a seed, unrelated seeds are almost orthogonal. */
final class FakeVision implements Vision
{
    public const MODEL = 'clip-b32';

    /** @var list<list<string>> contents of each embed() call */
    public array $embedCalls = [];

    public ?ScanResult $scanResult = null;
    public bool $unavailable = false;

    /** @return list<float> */
    public static function vector(string $seed): array
    {
        $values = [];
        for ($i = 0; \count($values) < CardEmbedding::DIMENSION; ++$i) {
            foreach (str_split(hash('sha256', $seed.$i, true)) as $byte) {
                $values[] = \ord($byte) - 127.5;
            }
        }
        $values = \array_slice($values, 0, CardEmbedding::DIMENSION);
        $norm = sqrt(array_sum(array_map(static fn (float $v) => $v * $v, $values)));

        return array_map(static fn (float $v) => $v / $norm, $values);
    }

    /** A card as the service would return it: the vectors are those of the given seeds (upright, upside-down). */
    public static function detected(string $upright, string $flipped = 'upside-down-noise'): DetectedCard
    {
        return new DetectedCard([[0, 0], [100, 0], [100, 140], [0, 140]], 'jpeg-bytes', [self::vector($upright), self::vector($flipped)]);
    }

    public function embed(array $images): Embeddings
    {
        $this->embedCalls[] = array_values($images);

        if ($this->unavailable) {
            throw new VisionException('unavailable');
        }

        foreach ($images as $content) {
            if ('corrupt' === $content) {
                throw new VisionException('Unreadable image.');
            }
        }

        return new Embeddings(self::MODEL, array_map(static fn (string $content) => self::vector($content), array_values($images)));
    }

    public function scan(string $photo): ScanResult
    {
        if ($this->unavailable) {
            throw new VisionException('unavailable');
        }

        return $this->scanResult ?? new ScanResult(self::MODEL, []);
    }
}
