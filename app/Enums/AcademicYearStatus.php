<?php

namespace App\Enums;

enum AcademicYearStatus: string
{
    case Planned = 'planned';
    case Active = 'active';
    case Closed = 'closed';
}
