<?php

namespace App\Catalog;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Reads the TCGdex REST API. Answers are cached (24h, see the cache.tcgdex pool) because
 * the API forbids HTTP caching and its data changes at most daily.
 */
final class TcgdexClient
{
    // Requests started together; real parallelism is capped by http_client.max_host_connections.
    private const CHUNK_SIZE = 16;

    public function __construct(
        #[Autowire(service: 'tcgdex.client')]
        private readonly HttpClientInterface $client,
        #[Autowire(service: 'cache.tcgdex')]
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws ExceptionInterface
     */
    public function sets(string $language, bool $refresh = false): array
    {
        return $this->fetch($language, 'sets', '', $refresh);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ExceptionInterface
     */
    public function set(string $language, string $setId, bool $refresh = false): array
    {
        return $this->fetch($language, 'sets', $setId, $refresh);
    }

    /**
     * Fetches card details, several at a time. A card that cannot be fetched yields null.
     *
     * @param list<string> $ids
     *
     * @return \Generator<string, array<string, mixed>|null>
     */
    public function cards(string $language, array $ids, bool $refresh = false): \Generator
    {
        foreach (array_chunk($ids, self::CHUNK_SIZE) as $chunk) {
            $results = [];
            $pending = [];

            foreach ($chunk as $id) {
                $item = $this->cache->getItem($this->key($language, 'cards', $id));

                if (!$refresh && $item->isHit()) {
                    $results[$id] = $item->get();
                } else {
                    $pending[$id] = $this->client->request('GET', $this->path($language, 'cards', $id));
                }
            }

            foreach ($pending as $id => $response) {
                try {
                    $results[$id] = $response->toArray();
                    $this->store($this->key($language, 'cards', $id), $results[$id]);
                } catch (ExceptionInterface $e) {
                    $this->logger->warning('TCGdex card {language}/{id} could not be fetched: {error}', ['language' => $language, 'id' => $id, 'error' => $e->getMessage()]);
                    $results[$id] = null;
                }
            }

            foreach ($chunk as $id) {
                yield $id => $results[$id];
            }
        }
    }

    /**
     * @return array<mixed>
     *
     * @throws ExceptionInterface
     */
    private function fetch(string $language, string $type, string $id, bool $refresh): array
    {
        $key = $this->key($language, $type, $id);
        $item = $this->cache->getItem($key);

        if (!$refresh && $item->isHit()) {
            return $item->get();
        }

        $data = $this->client->request('GET', $this->path($language, $type, $id))->toArray();
        $this->store($key, $data);

        return $data;
    }

    /** @param array<mixed> $data */
    private function store(string $key, array $data): void
    {
        $this->cache->save($this->cache->getItem($key)->set($data));
    }

    private function path(string $language, string $type, string $id): string
    {
        return $language.'/'.$type.('' === $id ? '' : '/'.rawurlencode($id));
    }

    private function key(string $language, string $type, string $id): string
    {
        // PSR-6 keys cannot contain "/", ":" or "@", which TCGdex ids may.
        return 'tcgdex.'.hash('xxh3', $this->path($language, $type, $id));
    }
}
