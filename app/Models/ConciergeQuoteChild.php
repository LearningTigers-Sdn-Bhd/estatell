<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One child on an AI quote link.
 *
 * @property int $id
 * @property int $concierge_quote_id
 * @property int $age
 */
#[Fillable(['age'])]
class ConciergeQuoteChild extends Model
{
    public $timestamps = false;

    protected $table = 'concierge_quote_children';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['age' => 'integer'];
    }
}
