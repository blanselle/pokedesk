<?php

namespace App\Scan;

use App\Entity\User;
use App\Vision\Vision;
use App\Vision\VisionException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ScanService
{
    public function __construct(
        private readonly Vision $vision,
        private readonly CandidateFinder $finder,
        private readonly ScanStore $store,
        #[Autowire('%app.vision_model%')]
        private readonly string $model,
    ) {
    }

    /**
     * Finds the cards of a photo and proposes catalogue cards for each. The photo itself is not kept.
     *
     * @return ScanSession|null null when no card was found in the photo
     *
     * @throws VisionException
     */
    public function start(User $user, string $language, string $photo): ?ScanSession
    {
        $result = $this->vision->scan($photo);

        if ($result->model !== $this->model) {
            throw new VisionException(\sprintf('The vision service runs "%s" but the application expects "%s".', $result->model, $this->model));
        }

        if ([] === $result->cards) {
            return null;
        }

        $cards = [];
        foreach ($result->cards as $detected) {
            [$candidates, $upsideDown] = $this->finder->find($detected->embeddings, $language);
            $cards[] = new ScannedCard($detected->crop, $candidates, $upsideDown);
        }

        return $this->store->create($user, $language, $cards);
    }
}
