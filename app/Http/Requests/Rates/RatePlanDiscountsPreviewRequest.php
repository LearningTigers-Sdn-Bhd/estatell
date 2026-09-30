<?php

namespace App\Http\Requests\Rates;

use App\Enums\StayDiscountType;
use App\Models\RatePlan;
use App\Models\RatePlanStayDiscount;
use App\Services\Rates\StayDiscountPreview;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * The unsaved rules of the Discounts tab and the sample stay. Rules that are not valid yet are left out of the
 * preview instead of failing the request, because staff are still typing them.
 */
class RatePlanDiscountsPreviewRequest extends FormRequest
{
    /**
     * The `can:settings.manage` route middleware authorizes this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var RatePlan $ratePlan */
        $ratePlan = $this->route('ratePlan');

        return [
            'discounts' => ['present', 'array', 'max:20'],
            'discounts.*' => ['array'],
            'arrival' => ['required', 'date_format:Y-m-d'],
            'nights' => ['nullable', 'integer', 'between:1,'.StayDiscountPreview::MAX_NIGHTS],
            'adults' => ['nullable', 'integer', 'between:1,50'],
            'children' => ['array'],
            'children.*.age_group_id' => [
                'required', 'integer', 'distinct',
                Rule::exists('property_age_groups', 'id')->where('property_id', $ratePlan->property_id),
            ],
            'children.*.count' => ['required', 'integer', 'between:0,20'],
        ];
    }

    /**
     * The valid rules as unsaved models. The first rule wins when two share the same minimum nights.
     *
     * @return Collection<int, RatePlanStayDiscount>
     */
    public function validRules(): Collection
    {
        return collect($this->validated('discounts'))
            ->filter(fn (array $row): bool => $this->isValidRule($row))
            ->unique(fn (array $row): int => (int) $row['min_nights'])
            ->map(fn (array $row): RatePlanStayDiscount => new RatePlanStayDiscount([
                'min_nights' => (int) $row['min_nights'],
                'discount_type' => $row['discount_type'],
                'value' => (string) $row['value'],
                'from_night' => (int) $row['from_night'],
            ]))
            ->values();
    }

    /**
     * How many rows were left out because they are not valid yet.
     */
    public function ignoredRuleCount(): int
    {
        return count($this->validated('discounts')) - $this->validRules()->count();
    }

    public function arrival(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d', $this->validated('arrival'))->startOfDay();
    }

    /**
     * The By night stay length, or null for the default from the rules.
     */
    public function nights(): ?int
    {
        $nights = $this->validated('nights');

        return $nights === null ? null : (int) $nights;
    }

    public function adults(): ?int
    {
        $adults = $this->validated('adults');

        return $adults === null ? null : (int) $adults;
    }

    /**
     * @return array<int, int> Children per age group id
     */
    public function childrenByAgeGroup(): array
    {
        return collect($this->validated('children') ?? [])
            ->mapWithKeys(fn (array $child): array => [(int) $child['age_group_id'] => (int) $child['count']])
            ->all();
    }

    /**
     * The same limits as `RatePlanStayDiscountsRequest` and the table checks.
     *
     * @param  array<string, mixed>  $row
     */
    private function isValidRule(array $row): bool
    {
        $minNights = filter_var($row['min_nights'] ?? null, FILTER_VALIDATE_INT);
        $fromNight = filter_var($row['from_night'] ?? null, FILTER_VALIDATE_INT);
        $type = StayDiscountType::tryFrom((string) ($row['discount_type'] ?? ''));
        $value = $row['value'] ?? null;

        if ($minNights === false || $minNights < 2 || $minNights > 365) {
            return false;
        }

        if ($fromNight === false || $fromNight < 1 || $fromNight > $minNights) {
            return false;
        }

        if ($type === null || ! is_numeric($value) || ! preg_match('/^\d+(\.\d{1,2})?$/', (string) $value) || $value <= 0) {
            return false;
        }

        return $type === StayDiscountType::Percent ? $value <= 100 : $value <= 9999999999;
    }
}
