<?php

namespace App\Vision;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class HttpVision implements Vision
{
    public function __construct(
        #[Autowire(service: 'vision.client')]
        private readonly HttpClientInterface $client,
    ) {
    }

    public function embed(array $images): Embeddings
    {
        $parts = [];
        foreach (array_values($images) as $index => $content) {
            $parts[] = new DataPart($content, 'image-'.$index, 'application/octet-stream');
        }

        $data = $this->post('/embed', ['images' => $parts]);

        if (!isset($data['model'], $data['vectors']) || \count($data['vectors']) !== \count($images)) {
            throw new VisionException('Unexpected answer from the vision service.');
        }

        return new Embeddings($data['model'], $data['vectors']);
    }

    public function scan(string $photo): ScanResult
    {
        $data = $this->post('/scan', ['image' => new DataPart($photo, 'photo', 'application/octet-stream')]);

        if (!isset($data['model'], $data['cards'])) {
            throw new VisionException('Unexpected answer from the vision service.');
        }

        return new ScanResult($data['model'], array_map(
            static fn (array $card) => new DetectedCard($card['quad'], (string) base64_decode($card['crop'], true), $card['embeddings']),
            $data['cards'],
        ));
    }

    /**
     * @param array<string, DataPart|list<DataPart>> $fields
     *
     * @return array<string, mixed>
     */
    private function post(string $path, array $fields): array
    {
        $body = new FormDataPart($fields);

        try {
            return $this->client->request('POST', $path, [
                'headers' => $body->getPreparedHeaders()->toArray(),
                'body' => $body->bodyToIterable(),
            ])->toArray();
        } catch (ExceptionInterface $e) {
            throw new VisionException('The vision service failed: '.$e->getMessage(), 0, $e);
        }
    }
}
