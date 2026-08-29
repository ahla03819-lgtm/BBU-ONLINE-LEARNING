<?php

namespace App\Enums;

enum AttendanceRegisterStatus: string
{
    case Draft = 'draft';
    case Finalized = 'finalized';
}
