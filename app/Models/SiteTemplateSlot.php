<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\SiteTemplateSlotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One image a template shows (hero, logo, about image). Owners fill a slot on the Design tab.
 *
 * @property int $id
 * @property int $site_template_id
 * @property string $key
 * @property string $label
 * @property bool $required
 * @property int $position
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['site_template_id', 'key', 'label', 'required', 'position'])]
class SiteTemplateSlot extends Model
{
    /** @use HasFactory<SiteTemplateSlotFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'required' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<SiteTemplate, $this>
     */
    public function siteTemplate(): BelongsTo
    {
        return $this->belongsTo(SiteTemplate::class);
    }
}
