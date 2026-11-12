<?php

namespace App\Scan;

use App\Entity\User;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Temporary storage of scans: nothing about a scan, photos included, outlives its cache entry. */
final class ScanStore
{
    public function __construct(
        #[Autowire(service: 'cache.scan')]
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * Starts a scan for the user, replacing the one in progress if any.
     *
     * @param list<ScannedCard> $cards
     */
    public function create(User $user, string $language, array $cards): ScanSession
    {
        $this->discardCurrent($user);

        $session = new ScanSession(bin2hex(random_bytes(16)), (int) $user->getId(), $language, $cards);
        $this->save($session);
        $this->cache->save($this->cache->getItem($this->currentKey($user))->set($session->token));

        return $session;
    }

    /** Only the owner can read a scan. */
    public function get(string $token, User $user): ?ScanSession
    {
        if (!preg_match('/^[0-9a-f]{32}$/', $token)) {
            return null;
        }

        $item = $this->cache->getItem($this->key($token));
        $session = $item->isHit() ? $item->get() : null;

        return $session instanceof ScanSession && $session->userId === $user->getId() ? $session : null;
    }

    public function save(ScanSession $session): void
    {
        $this->cache->save($this->cache->getItem($this->key($session->token))->set($session));
    }

    public function delete(ScanSession $session): void
    {
        $this->cache->deleteItem($this->key($session->token));
    }

    private function discardCurrent(User $user): void
    {
        $item = $this->cache->getItem($this->currentKey($user));

        if ($item->isHit()) {
            $this->cache->deleteItem($this->key((string) $item->get()));
        }
    }

    private function key(string $token): string
    {
        return 'scan.'.$token;
    }

    private function currentKey(User $user): string
    {
        return 'scan_current.'.$user->getId();
    }
}
