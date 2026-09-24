<?php

namespace App\Enums;

enum MeetingRecurrenceType: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case SelectedWeekdays = 'selected_weekdays';
}
