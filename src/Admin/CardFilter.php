<?php

namespace App\Admin;

use App\Catalog\CardCriteria;
use App\Entity\CardStatus;

/** Search criteria of the administration card list: the shared ones plus the catalogue-only ones. */
final class CardFilter extends CardCriteria
{
    public ?CardStatus $status = null;
    public bool $missingImage = false;
}
