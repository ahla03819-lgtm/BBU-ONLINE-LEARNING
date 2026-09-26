<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CalendarRangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('meetings.view') || $this->user()->can('assignments.view');
    }

    public function rules(): array
    {
        return [
            'view' => ['required', Rule::in(['month', 'week', 'agenda'])],
            'start' => ['required', 'date'],
            'end' => ['required', 'date', 'after:start'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if ($this->rangeExceedsMaximumDays()) {
                $validator->errors()->add('end', 'Calendar ranges may not exceed '.config('calendar.max_range_days').' days.');
            }
        });
    }

    /**
     * Inclusive range start as a UTC instant.
     *
     * Date-only boundaries (YYYY-MM-DD) mean midnight in the configured
     * academic calendar timezone; explicit ISO values keep their own offset.
     * The raw input is read so this stays safe inside validation callbacks.
     */
    public function startBoundary(): CarbonImmutable
    {
        return $this->toUtcBoundary((string) $this->input('start'));
    }

    /**
     * Exclusive range end as a UTC instant, resolved like the start boundary.
     */
    public function endBoundary(): CarbonImmutable
    {
        return $this->toUtcBoundary((string) $this->input('end'));
    }

    private function toUtcBoundary(string $value): CarbonImmutable
    {
        if ($this->isDateOnly($value)) {
            return CarbonImmutable::parse($value, config('calendar.default_timezone'))->utc();
        }

        return CarbonImmutable::parse($value)->utc();
    }

    private function isDateOnly(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
    }

    /**
     * Date-only ranges are measured in whole calendar days so that a DST
     * transition inside the academic timezone can never turn an allowed
     * 93-day month range into a rejected one. Explicit datetime inputs keep
     * the existing elapsed-instant comparison.
     */
    private function rangeExceedsMaximumDays(): bool
    {
        $start = (string) $this->input('start');
        $end = (string) $this->input('end');

        if ($this->isDateOnly($start) && $this->isDateOnly($end)) {
            $days = CarbonImmutable::createFromFormat('!Y-m-d', $start)
                ->diffInDays(CarbonImmutable::createFromFormat('!Y-m-d', $end));
        } else {
            $days = $this->startBoundary()->diffInDays($this->endBoundary());
        }

        return $days > config('calendar.max_range_days');
    }
}
