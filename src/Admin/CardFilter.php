<?php

namespace App\Admin;

use App\Entity\CardStatus;

/** Search criteria of the administration card list; empty values mean "no filter". */
final class CardFilter
{
    // Name or TCGdex id, partial match.
    public ?string $q = null;
    public ?string $language = null;
    // Set id (exact) or set name (partial).
    public ?string $extension = null;
    // Number in the set (exact).
    public ?string $number = null;
    public ?string $rarity = null;
    public ?CardStatus $status = null;
    public bool $missingImage = false;
}
