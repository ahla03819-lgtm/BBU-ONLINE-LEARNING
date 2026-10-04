<?php

namespace App\Http\Requests\Concerns;

use Carbon\CarbonImmutable;

/**
 * Normalizes browser meeting schedule input to UTC.
 *
 * <input type="datetime-local"> submits "YYYY-MM-DDTHH:mm" with no offset,
 * representing wall-clock time in the academic calendar timezone. The
 * application timezone is UTC, so an offset-less datetime-local value would
 * otherwise be stored as if the user meant UTC and land +07:00 from their
 * intent in Asia/Phnom_Penh.
 *
 * Only that exact shape is converted. Values that already carry an offset, and
 * the space-separated forms used by series and seed data, are left untouched so
 * no existing caller changes meaning.
 */
trait NormalizesAcademicSchedule
{
    /**
     * @param  list<string>  $fields
     */
    protected function normalizeAcademicSchedule(array $fields): void
    {
        $timezone = (string) config('calendar.default_timezone');

        foreach ($fields as $field) {
            $value = $this->input($field);

            if (! is_string($value) || ! $this->isOffsetLessDateTimeLocal($value)) {
                continue;
            }

            $this->merge([
                $field => CarbonImmutable::createFromFormat('Y-m-d\TH:i', $value, $timezone)
                    ->utc()->format('Y-m-d\TH:i:s\Z'),
            ]);
        }
    }

    private function isOffsetLessDateTimeLocal(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value) === 1;
    }
}
