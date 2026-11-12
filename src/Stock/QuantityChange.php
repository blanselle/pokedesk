<?php

namespace App\Stock;

use Symfony\Component\Validator\Constraints as Assert;

final class QuantityChange
{
    public const MAX = 9999;

    public function __construct(
        /** The quantity the user was looking at: the change is applied relatively to it. */
        #[Assert\Range(min: 1, max: self::MAX)]
        public int $previous = 1,
        #[Assert\Range(min: 0, max: self::MAX)]
        public int $quantity = 1,
    ) {
    }
}
