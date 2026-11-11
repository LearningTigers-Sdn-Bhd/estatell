<?php

namespace App\Http\Requests\Keyport;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Staff add an eLock lock. The number is what the encoder writes, `building.floor.room[.subroom]`, with the ranges of the RF57 card.
 */
class StoreElockLockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'lock_no' => ['required', 'string', 'max:40', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! preg_match('/^(\d{1,3})\.(\d{1,3})\.(\d{1,6})(?:\.(\d{1,3}))?$/', (string) $value, $m)
                    || $m[1] < 1 || $m[1] > 255 || $m[2] < 1 || $m[2] > 255 || (isset($m[4]) && $m[4] > 127)) {
                    $fail(__('Use building.floor.room, for example 1.2.203. Building and floor are 1 to 255.'));
                }
            }],
            'room_id' => ['nullable', 'integer'],
        ];
    }
}
