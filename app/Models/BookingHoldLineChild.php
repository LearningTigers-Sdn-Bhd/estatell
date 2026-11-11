<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One child on a hold line.
 *
 * @property int $id
 * @property int $booking_hold_line_id
 * @property int $age
 */
#[Fillable(['age'])]
class BookingHoldLineChild extends Model
{
    public $timestamps = false;

    protected $table = 'booking_hold_line_children';
}
