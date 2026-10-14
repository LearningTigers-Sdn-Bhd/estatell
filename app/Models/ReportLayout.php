<?php

namespace App\Models;

use App\Enums\PageOrientation;
use App\Enums\ReportKey;
use Carbon\CarbonImmutable;
use Database\Factories\ReportLayoutFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The orientation and column order of one report for one property, shared by every user. No row means the code
 * defaults. See docs/22-reports/01-core-reports.md.
 *
 * @property int $id
 * @property int $property_id
 * @property ReportKey $report_key
 * @property PageOrientation $orientation
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['report_key', 'orientation'])]
class ReportLayout extends Model
{
    /** @use HasFactory<ReportLayoutFactory> */
    use HasFactory;

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'report_key' => ReportKey::class,
            'orientation' => PageOrientation::class,
        ];
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * @return HasMany<ReportLayoutColumn, $this>
     */
    public function columns(): HasMany
    {
        return $this->hasMany(ReportLayoutColumn::class)->orderBy('position');
    }
}
