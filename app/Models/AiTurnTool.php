<?php

namespace App\Models;

use App\Enums\AiTool;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A tool one AI assistant turn called, and whether it worked.
 *
 * @property int $id
 * @property int $ai_turn_id
 * @property AiTool $tool
 * @property bool $succeeded
 */
#[Fillable(['ai_turn_id', 'tool', 'succeeded'])]
class AiTurnTool extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['tool' => AiTool::class, 'succeeded' => 'boolean'];
    }

    /**
     * @return BelongsTo<AiTurn, $this>
     */
    public function turn(): BelongsTo
    {
        return $this->belongsTo(AiTurn::class);
    }
}
