<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One category or one item a menu schedule covers.
 *
 * @property int $id
 * @property int $pos_menu_schedule_id
 * @property int|null $pos_category_id
 * @property int|null $pos_item_id
 */
class PosMenuScheduleItem extends Model
{
    public $timestamps = false;

    protected $guarded = [];
}
