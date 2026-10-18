<?php

namespace App\Http\Requests\Reservations;

use App\Enums\BookingSourceIcon;
use App\Enums\BookingSourceType;
use App\Enums\SwatchColor;
use App\Models\BookingSource;
use App\Models\OtaChannel;
use App\Models\Property;
use App\Services\Reservations\BookingSourceUsage;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use LogicException;

/**
 * Creates or updates a booking source. The route-bound `bookingSource` decides the mode: a system row accepts only the
 * badge, and a used source keeps its type and OTA channel. See docs/12-reservations/11-booking-sources.md.
 */
class BookingSourceRequest extends FormRequest
{
    /** The `can:settings.manage` route middleware authorizes this request. */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $letters = trim((string) $this->input('badge_letters'));
        $channel = $this->isUpdate() ? null : OtaChannel::query()->whereKey($this->input('ota_channel_id'))->first();
        $isOther = $this->input('type') === BookingSourceType::Ota->value && $channel?->is_other === true;

        $this->merge([
            'name' => $isOther ? trim((string) $this->input('channel_name')) : trim((string) $this->input('name')),
            'channel_name' => trim((string) $this->input('channel_name')),
            'badge_letters' => $letters === '' ? null : mb_strtoupper($letters),
            'badge_icon' => filled($this->input('badge_icon')) ? $this->input('badge_icon') : null,
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $property = $this->route('property');

        if (! $property instanceof Property) {
            throw new LogicException('The property route binding is missing.');
        }

        $source = $this->route('bookingSource');
        $source = $source instanceof BookingSource ? $source : null;
        $isSystem = $source?->is_system === true;
        $isUsed = $source !== null && app(BookingSourceUsage::class)->isUsed($source);
        $locked = $isSystem || $isUsed;
        $isOtherOnCreate = $source === null && $this->input('type') === BookingSourceType::Ota->value
            && OtaChannel::query()->whereKey($this->input('ota_channel_id'))->where('is_other', true)->exists();

        $nameUnique = function (string $attribute, mixed $value, Closure $fail) use ($property, $source): void {
            $taken = BookingSource::query()
                ->where('property_id', $property->id)
                ->whereRaw('lower(name) = ?', [mb_strtolower((string) $value)])
                ->when($source !== null, fn (Builder $query) => $query->whereKeyNot($source?->id))
                ->exists();

            if ($taken) {
                $fail(__('Another booking source already uses this name.'));
            }
        };

        return [
            'name' => $isSystem ? ['exclude'] : [
                'required', 'string', 'max:60',
                ...($isOtherOnCreate ? [] : [$nameUnique]),
            ],
            'channel_name' => $isOtherOnCreate ? ['required', 'string', 'max:60', $nameUnique] : ['exclude'],
            'type' => $source !== null
                ? ($locked ? ['exclude'] : ['required', Rule::enum(BookingSourceType::class)->only([BookingSourceType::Direct, BookingSourceType::Ota])])
                : ['required', Rule::enum(BookingSourceType::class)->only([BookingSourceType::Direct, BookingSourceType::Ota])],
            'ota_channel_id' => $locked || $this->input('type') === BookingSourceType::Direct->value ? ['exclude'] : [
                'required_if:type,'.BookingSourceType::Ota->value,
                'nullable',
                'integer',
                function (string $attribute, mixed $value, Closure $fail) use ($property, $source): void {
                    if ($this->input('type') !== BookingSourceType::Ota->value) {
                        return;
                    }

                    $channel = OtaChannel::query()->active()->whereKey($value)->first();

                    if ($channel === null) {
                        $fail(__('Choose an OTA channel.'));

                        return;
                    }

                    $taken = ! $channel->is_other && BookingSource::query()
                        ->where('property_id', $property->id)
                        ->where('ota_channel_id', $channel->id)
                        ->when($source !== null, fn (Builder $query) => $query->whereKeyNot($source?->id))
                        ->exists();

                    if ($taken) {
                        $fail(__('This property already has a booking source for :channel.', ['channel' => $channel->name]));
                    }
                },
            ],
            'badge_icon' => ['nullable', Rule::enum(BookingSourceIcon::class), 'required_without:badge_letters', 'prohibits:badge_letters'],
            'badge_letters' => ['nullable', 'regex:/^[A-Z0-9]{1,2}$/', 'required_without:badge_icon', 'prohibits:badge_icon'],
            'badge_color' => ['required', Rule::enum(SwatchColor::class)],
            'is_active' => $source === null || $isSystem ? ['exclude'] : ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => __('Enter a name.'),
            'channel_name.required' => __('Enter the channel name.'),
            'ota_channel_id.required_if' => __('Choose an OTA channel.'),
            'badge_icon.required_without' => __('Choose an icon or type one or two letters.'),
            'badge_icon.prohibits' => __('Choose an icon or type one or two letters.'),
            'badge_letters.required_without' => __('Choose an icon or type one or two letters.'),
            'badge_letters.prohibits' => __('Choose an icon or type one or two letters.'),
            'badge_letters.regex' => __('Letters must be one or two letters or digits.'),
            'badge_color.required' => __('Choose a color.'),
        ];
    }

    private function isUpdate(): bool
    {
        return $this->route('bookingSource') instanceof BookingSource;
    }
}
