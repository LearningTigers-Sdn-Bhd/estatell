<?php

namespace App\Models;

use App\Enums\ColumnAlign;
use Database\Factories\ReportLayoutColumnFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One column of a stored report layout: its key, place, visibility and alignment override.
 *
 * @property int $id
 * @property int $report_layout_id
 * @property string $column_key
 * @property int $position
 * @property bool $is_visible
 * @property ColumnAlign|null $align Null means the code default.
 */
#[Fillable(['column_key', 'position', 'is_visible', 'align'])]
class ReportLayoutColumn extends Model
{
    /** @use HasFactory<ReportLayoutColumnFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_visible' => 'boolean',
            'align' => ColumnAlign::class,
        ];
    }

    /**
     * @return BelongsTo<ReportLayout, $this>
     */
    public function reportLayout(): BelongsTo
    {
        return $this->belongsTo(ReportLayout::class);
    }
}
