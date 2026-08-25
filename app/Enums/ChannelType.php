<?php

namespace App\Enums;

enum ChannelType: string
{
    case General = 'general';
    case Announcement = 'announcement';
    case Subject = 'subject';
    case Custom = 'custom';
}
