<?php

namespace App\Stock;

use App\Catalog\CardCriteria;
use Symfony\Component\Validator\Constraints as Assert;

/** Card criteria plus the quantity range of a stock line. */
final class StockCriteria extends CardCriteria
{
    #[Assert\Positive]
    public ?int $minQuantity = null;
    #[Assert\Positive]
    public ?int $maxQuantity = null;

    public function isEmpty(): bool
    {
        return parent::isEmpty() && null === $this->minQuantity && null === $this->maxQuantity;
    }
}
