<?php

namespace App\Enums;

enum SchoolClassStatus: string
{
    case Planned = 'planned';
    case Active = 'active';
    case Closed = 'closed';
}
