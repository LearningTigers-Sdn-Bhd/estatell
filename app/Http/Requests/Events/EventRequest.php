<?php

namespace App\Http\Requests\Events;

use App\Data\Facilities\FacilityBookingInputData;
use App\Data\Guests\IdentityDocumentInputData;
use App\Enums\CompanyStatus;
use App\Enums\EventStatus;
use App\Enums\IdentityDocumentType;
use App\Enums\PermissionEnum;
use App\Http\Requests\Guests\GuestProfileRules;
use App\Models\Property;
use App\Services\Guests\CountryOptions;
use App\Services\Guests\IdentityNumber;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Validator;

/**
 * New event and Edit event. The `can:events.manage` route middleware authorizes it. The payer is a guest or an Active
 * company (with `company.view`). New event sends the guest as a `guest` row like the booker of the booking form: a
 * picked guest (`guest.guest_id`, with its profile only when `guest.update_profile` is on) or a new guest (the profile
 * fields, with `guests.manage`). Edit event sends `payer_guest_id`. New event may send facility lines (`bookings`, with
 * `facilities.book`); `CreateEvent::withBookings()` runs their state checks.
 * See docs/33-facilities/03-events-and-event-folios.md.
 */
class EventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The ID number of the guest is stored without spaces or dashes, like the guest form.
     */
    protected function prepareForValidation(): void
    {
        $guest = $this->input('guest');

        if (is_array($guest) && is_string($guest['identity_number'] ?? null)) {
            $guest['identity_number'] = app(IdentityNumber::class)->normalize($guest['identity_number']);
            $this->merge(['guest' => $guest]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $property = $this->route('property');
        $propertyId = $property instanceof Property ? $property->id : null;
        $isNew = $this->isNew();
        $activeGuest = Rule::exists('guests', 'id')->where('property_id', $propertyId)->whereNull('merged_into_id')->whereNull('anonymized_at');

        return [
            'name' => ['required', 'string', 'max:150'],
            'payer_type' => ['required', Rule::in($this->user()->can(PermissionEnum::CompanyView->value) ? ['guest', 'company'] : ['guest'])],
            'payer_guest_id' => [Rule::excludeIf($isNew), 'exclude_unless:payer_type,guest', 'required', 'integer', $activeGuest],
            ...($isNew ? $this->guestRules($activeGuest) : []),
            'payer_company_id' => [
                'exclude_unless:payer_type,company',
                'required',
                'integer',
                Rule::exists('companies', 'id')->where('property_id', $propertyId)->where('status', CompanyStatus::Active->value),
            ],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'status' => [Rule::excludeIf(! $isNew), 'required', Rule::in([EventStatus::Tentative->value, EventStatus::Confirmed->value])],
            'notes' => ['nullable', 'string', 'max:2000'],
            'bookings' => [Rule::excludeIf(! $isNew), Rule::prohibitedIf(! $this->user()->can(PermissionEnum::FacilitiesBook->value)), 'array', 'max:30'],
            'bookings.*.facility_id' => [Rule::excludeIf(! $isNew), 'required', 'integer', Rule::exists('facilities', 'id')->where('property_id', $propertyId)],
            'bookings.*.booking_date' => [Rule::excludeIf(! $isNew), 'required', 'date_format:Y-m-d', 'after_or_equal:starts_on', 'before_or_equal:ends_on'],
            'bookings.*.starts_at' => [Rule::excludeIf(! $isNew), 'required', 'date_format:H:i'],
            'bookings.*.ends_at' => [Rule::excludeIf(! $isNew), 'required', 'date_format:H:i', 'after:bookings.*.starts_at'],
            'bookings.*.quantity' => [Rule::excludeIf(! $isNew), 'required', 'integer', 'min:1', 'max:9999'],
            'bookings.*.unit_amount' => [Rule::excludeIf(! $isNew), 'nullable', 'decimal:0,2', 'min:0', 'max:9999999999'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'payer_guest_id.required' => __('Choose a guest.'),
            'guest.required' => __('Enter the guest\'s name or pick an existing guest.'),
            'guest.guest_id.exists' => __('This guest was merged or removed. Search for the guest again.'),
            'payer_company_id.required' => __('Choose a company.'),
            'ends_on.after_or_equal' => __('The last day cannot be before the first day.'),
            'bookings.*.facility_id.required' => __('Choose a facility.'),
            'bookings.*.booking_date.after_or_equal' => __('The date must be within the event\'s dates.'),
            'bookings.*.booking_date.before_or_equal' => __('The date must be within the event\'s dates.'),
            'bookings.*.starts_at.required' => __('Enter the start time, or choose a session.'),
            'bookings.*.ends_at.required' => __('Enter the end time.'),
            'bookings.*.ends_at.after' => __('The end time must be after the start time. A booking cannot cross midnight.'),
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->checkGuest($validator)];
    }

    /**
     * The validated fields in the shape `CreateEvent` and `UpdateEvent` take. New event sends the guest row as
     * `guest`, which `CreateEvent::withBookings()` resolves to the payer.
     *
     * @return array{name: string, payer_guest_id: ?int, payer_company_id: ?int, guest: array{guest_id: ?int, profile: array<string, mixed>, update_profile: bool, identity: ?IdentityDocumentInputData}|null, starts_on: string, ends_on: string, status: EventStatus, notes: ?string}
     */
    public function eventAttributes(): array
    {
        $type = $this->string('payer_type')->toString();
        $byGuestRow = $type === 'guest' && $this->isNew();

        return [
            'name' => trim($this->string('name')->toString()),
            'payer_guest_id' => $type === 'guest' && ! $byGuestRow ? $this->integer('payer_guest_id') : null,
            'payer_company_id' => $type === 'company' ? $this->integer('payer_company_id') : null,
            'guest' => $byGuestRow ? $this->guestRow() : null,
            'starts_on' => $this->string('starts_on')->toString(),
            'ends_on' => $this->string('ends_on')->toString(),
            'status' => EventStatus::tryFrom($this->string('status')->toString()) ?? EventStatus::Confirmed,
            'notes' => $this->filled('notes') ? trim($this->string('notes')->toString()) : null,
        ];
    }

    /**
     * The facility lines of New event, in page order.
     *
     * @return list<FacilityBookingInputData>
     */
    public function bookingInputs(): array
    {
        return array_values(array_map(fn (array $line): FacilityBookingInputData => new FacilityBookingInputData(
            facilityId: (int) $line['facility_id'],
            bookingDate: (string) $line['booking_date'],
            startsAt: (string) $line['starts_at'],
            endsAt: (string) $line['ends_at'],
            quantity: (int) $line['quantity'],
            unitAmount: filled($line['unit_amount'] ?? null) ? (string) $line['unit_amount'] : null,
            notes: null,
        ), (array) $this->validated('bookings', [])));
    }

    private function isNew(): bool
    {
        return $this->route('event') === null;
    }

    /**
     * The rules of the New event guest row, the same fields as a guest row of the booking form. They apply only when
     * a guest pays.
     *
     * @return array<string, array<mixed>>
     */
    private function guestRules(Exists $activeGuest): array
    {
        $rules = [
            'guest' => ['required', 'array'],
            'guest.guest_id' => ['nullable', 'integer', $activeGuest],
            'guest.update_profile' => ['nullable', 'boolean'],
            ...GuestProfileRules::prefixed('guest.', $this->phoneRegion(), nameRequired: false),
            'guest.identity_type' => ['nullable', 'required_with:guest.identity_number', Rule::enum(IdentityDocumentType::class)],
            'guest.identity_number' => ['nullable', 'string', 'min:4', 'max:40'],
            'guest.identity_country_code' => ['nullable', Rule::in(app(CountryOptions::class)->codes())],
        ];

        return array_map(fn (array $fieldRules): array => ['exclude_unless:payer_type,guest', ...$fieldRules], $rules);
    }

    /**
     * A new guest needs a name. Creating a guest or changing a profile needs `guests.manage`, and an ID for a new guest
     * needs `guests.view-documents`, the same checks as the booking form.
     */
    private function checkGuest(Validator $validator): void
    {
        if (! $this->isNew() || $this->input('payer_type') !== 'guest' || ! is_array($this->input('guest'))) {
            return;
        }

        $guest = (array) $this->input('guest');
        $picked = ! empty($guest['guest_id']);

        if (! $picked && trim((string) ($guest['full_name'] ?? '')) === '') {
            $validator->errors()->add('guest.full_name', __('Enter the guest\'s name or pick an existing guest.'));

            return;
        }

        if (! $this->user()->can(PermissionEnum::GuestsManage->value)) {
            if (! $picked) {
                $validator->errors()->add('guest.full_name', __('Pick an existing guest.'));
            } elseif (! empty($guest['update_profile'])) {
                $validator->errors()->add('guest.update_profile', __('You may not change a guest\'s profile.'));
            }

            return;
        }

        if ($picked || blank($guest['identity_number'] ?? null)) {
            return;
        }

        if (! $this->user()->can(PermissionEnum::GuestsViewDocuments->value)) {
            $validator->errors()->add('guest.identity_number', __('You may not add an ID.'));
        } elseif (($guest['identity_type'] ?? null) === IdentityDocumentType::MyKad->value && ! preg_match('/^\d{12}$/', (string) $guest['identity_number'])) {
            $validator->errors()->add('guest.identity_number', __('A MyKad number has 12 digits.'));
        }
    }

    /**
     * The validated guest row: a picked guest, with its profile only when it is updated, or a new guest's profile and
     * ID.
     *
     * @return array{guest_id: ?int, profile: array<string, mixed>, update_profile: bool, identity: ?IdentityDocumentInputData}
     */
    private function guestRow(): array
    {
        /** @var array<string, mixed> $row */
        $row = $this->validated('guest');
        $guestId = empty($row['guest_id']) ? null : (int) $row['guest_id'];
        $updateProfile = $guestId !== null && (bool) ($row['update_profile'] ?? false);

        return [
            'guest_id' => $guestId,
            'profile' => $guestId === null || $updateProfile ? GuestProfileRules::attributes($row, $this->phoneRegion()) : [],
            'update_profile' => $updateProfile,
            'identity' => $guestId === null && filled($row['identity_number'] ?? null)
                ? new IdentityDocumentInputData(IdentityDocumentType::from($row['identity_type']), $row['identity_number'], ($row['identity_country_code'] ?? null) ?: null, null)
                : null,
        ];
    }

    private function phoneRegion(): string
    {
        $property = $this->route('property');

        return $property instanceof Property ? ($property->country_code ?? 'MY') : 'MY';
    }
}
