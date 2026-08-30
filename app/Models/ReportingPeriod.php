<?php

namespace App\Models;

use App\Enums\ReportingPeriodStatus;
use Database\Factories\ReportingPeriodFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['academic_year_id', 'parent_id', 'name', 'code', 'sequence', 'starts_on', 'ends_on', 'status'])]
class ReportingPeriod extends Model
{
    /** @use HasFactory<ReportingPeriodFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'status' => ReportingPeriodStatus::class, 'sequence' => 'integer'];
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sequence');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class);
    }
}
