<?php

namespace App\Http\Requests\Pos;

/**
 * A room order also names the outlet the guest orders from.
 */
class PlaceRoomQrOrderRequest extends PlaceQrOrderRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [...parent::rules(), 'outlet_id' => ['required', 'integer']];
    }
}
