<?php

namespace App\Http\Requests\Rooms;

use App\Models\Property;
use App\Models\RoomGroup;
use App\Models\RoomType;
use App\Services\Rooms\RoomNumberGenerator;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class GenerateRoomsRequest extends FormRequest
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
        $this->merge([
            'room_group_id' => filled($this->input('room_group_id')) ? $this->input('room_group_id') : null,
            'prefix' => (string) $this->input('prefix', ''),
            'suffix' => (string) $this->input('suffix', ''),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Property $property */
        $property = $this->route('property');
        $floors = $this->input('mode') === RoomNumberGenerator::MODE_FLOORS;

        return [
            'mode' => ['required', Rule::in([RoomNumberGenerator::MODE_RANGE, RoomNumberGenerator::MODE_FLOORS])],
            'room_type_id' => [
                'required', 'integer',
                function (string $attribute, mixed $value, Closure $fail) use ($property): void {
                    $active = RoomType::query()->where('property_id', $property->id)->active()->whereKey($value)->exists();

                    if (! $active) {
                        $fail(__('Choose an active room type.'));
                    }
                },
            ],
            'room_group_id' => ['nullable', 'integer', Rule::exists(RoomGroup::class, 'id')->where('property_id', $property->id)],
            'prefix' => ['string', 'max:10'],
            'suffix' => ['string', 'max:10'],
            'from' => [$floors ? 'exclude' : 'required', 'regex:/^\d{1,6}$/'],
            'to' => [$floors ? 'exclude' : 'required', 'regex:/^\d{1,6}$/'],
            'floor_from' => [$floors ? 'required' : 'exclude', 'integer', 'min:1', 'max:99'],
            'floor_to' => [$floors ? 'required' : 'exclude', 'integer', 'min:1', 'max:99'],
            'room_from' => [$floors ? 'required' : 'exclude', 'regex:/^\d{1,4}$/'],
            'room_to' => [$floors ? 'required' : 'exclude', 'regex:/^\d{1,4}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            '*.regex' => __('Use digits only.'),
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $input = $this->generatorInput();
                $floors = $this->mode() === RoomNumberGenerator::MODE_FLOORS;
                [$first, $last, $errorKey] = $floors
                    ? [(int) $input['room_from'], (int) $input['room_to'], 'room_to']
                    : [(int) $input['from'], (int) $input['to'], 'to'];

                if ($last < $first) {
                    $validator->errors()->add($errorKey, __('The last number must not be lower than the first.'));

                    return;
                }

                if ($floors && (int) $input['floor_to'] < (int) $input['floor_from']) {
                    $validator->errors()->add('floor_to', __('The last floor must not be lower than the first.'));

                    return;
                }

                if (app(RoomNumberGenerator::class)->size($this->mode(), $input) > RoomNumberGenerator::MAX_ROOMS) {
                    $validator->errors()->add($errorKey, __('Create at most :max rooms at a time.', ['max' => RoomNumberGenerator::MAX_ROOMS]));
                }
            },
        ];
    }

    public function mode(): string
    {
        return (string) $this->validated('mode');
    }

    /**
     * @return array<string, mixed>
     */
    public function generatorInput(): array
    {
        return $this->safe()->except(['mode', 'room_type_id', 'room_group_id']);
    }
}
