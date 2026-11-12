<?php

namespace App\Catalog;

final readonly class ImportOptions
{
    public function __construct(
        public bool $images = true,
        // Ignores cached API answers (they are still refreshed in the cache).
        public bool $refresh = false,
    ) {
    }
}
