<?php

namespace App\Http\Requests\Rooms;

use App\Models\Bed;
use App\Models\Room;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class BedRequest extends FormRequest
{
    /**
     * The `can:rooms.manage` route middleware authorizes this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['label' => trim((string) $this->input('label'))]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Room $room */
        $room = $this->route('room');
        $current = $this->route('bed');

        return [
            'label' => [
                'required', 'string', 'max:30',
                function (string $attribute, mixed $value, Closure $fail) use ($room, $current): void {
                    $taken = $room->beds()
                        ->active()
                        ->whereRaw('lower(label) = ?', [mb_strtolower((string) $value)])
                        ->when($current instanceof Bed, fn ($query) => $query->whereKeyNot($current->id))
                        ->exists();

                    if ($taken) {
                        $fail(__('Another active bed in this room already uses this label.'));
                    }
                },
            ],
        ];
    }
}
