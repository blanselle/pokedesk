<?php

namespace App\Entity;

enum CardStatus: string
{
    case Active = 'active';
    // Card no longer returned by the TCGdex API; kept so existing stock lines stay valid.
    case Obsolete = 'obsolete';
}
