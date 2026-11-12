<?php

namespace App\Stock;

use Symfony\Component\Validator\Constraints as Assert;

final class StockDeletion
{
    /** @var list<int> ids of the stock lines to remove */
    #[Assert\Count(min: 1, max: 500, minMessage: 'Sélectionnez au moins une carte.', maxMessage: 'Sélectionnez {{ limit }} cartes au maximum à la fois.')]
    #[Assert\All([new Assert\Positive()])]
    public array $ids = [];
}
