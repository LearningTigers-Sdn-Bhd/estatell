<?php

namespace App\Http\Requests\Portal;

use App\Data\Portal\NewPortalLineData;
use App\Models\CompanyUser;
use App\Services\Portal\PortalBookingRules;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * A search on the portal book page: the dates and up to 20 lines, the same limit as the staff booking form. Each child
 * needs an age from 0 to 17. A dorm line sends its beds. The `portal` middleware has already checked the company user.
 */
class PortalOffersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'arrival_date' => ['required', 'date_format:Y-m-d'],
            'departure_date' => ['required', 'date_format:Y-m-d', 'after:arrival_date'],
            'lines' => ['required', 'array', 'min:1', 'max:20'],
            'lines.*.room_type_id' => ['required', 'integer'],
            'lines.*.adults' => ['required', 'integer', 'min:1', 'max:99'],
            'lines.*.child_ages' => ['nullable', 'array', 'max:20'],
            'lines.*.child_ages.*' => ['required', 'integer', 'between:0,17'],
            'lines.*.beds' => ['nullable', 'integer', 'min:1', 'max:99'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'departure_date.after' => __('Departure must be after arrival.'),
            'lines.*.child_ages.*.required' => __('Enter the age of every child.'),
            'lines.*.child_ages.*.integer' => __('Enter the age of every child.'),
            'lines.*.child_ages.*.between' => __('A child age must be from 0 to 17.'),
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('arrival_date')) {
                return;
            }

            $today = app(PortalBookingRules::class)->businessDate($this->link()->property);

            if (CarbonImmutable::parse($this->input('arrival_date'))->lt($today)) {
                $validator->errors()->add('arrival_date', __('Arrival cannot be before today.'));
            }
        }];
    }

    public function link(): CompanyUser
    {
        /** @var CompanyUser $link */
        $link = $this->attributes->get('portalLink');

        return $link;
    }

    public function arrival(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->validated('arrival_date'));
    }

    public function departure(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->validated('departure_date'));
    }

    /**
     * @return list<NewPortalLineData>
     */
    public function lines(): array
    {
        return array_values(array_map(fn (array $line): NewPortalLineData => new NewPortalLineData(
            roomTypeId: (int) $line['room_type_id'],
            adults: (int) $line['adults'],
            childAges: array_values(array_map(intval(...), $line['child_ages'] ?? [])),
            beds: (int) ($line['beds'] ?? 1),
            ratePlanId: isset($line['rate_plan_id']) ? (int) $line['rate_plan_id'] : null,
            guestName: $line['guest_name'] ?? null,
            guestPhone: $line['guest_phone'] ?? null,
        ), $this->validated('lines')));
    }
}
