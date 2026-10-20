<?php

namespace App\Http\Requests\Onboarding;

use App\Enums\CompanyStatus;
use App\Enums\OnboardingStep;
use App\Http\Requests\CityLedger\CompanyRules;
use App\Models\Company;
use App\Models\Property;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;
use LogicException;

/**
 * The Companies table of onboarding. Each row has the fields of the add company sheet plus the tax numbers, with the
 * bounds of `CompanyRules`. A row id must be an Active company of this property. No rows is a valid save.
 */
class SaveCompaniesRequest extends OnboardingStepRequest
{
    public const MAX_ROWS = 200;

    /**
     * The row fields that go to the company, taken from `CompanyRules`.
     *
     * @var list<string>
     */
    private const array FIELDS = [
        'type', 'contact_email', 'contact_phone', 'credit_limit', 'payment_terms_days', 'direct_bill_enabled',
        'brn', 'tin', 'sst_registration_number', 'portal_email', 'portal_cancel_cutoff_days',
    ];

    protected function step(): OnboardingStep
    {
        return OnboardingStep::Companies;
    }

    /**
     * The `onboarding.only` middleware limits who can reach this route.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $rows = $this->input('rows');

        if (! is_array($rows)) {
            return;
        }

        $this->merge(['rows' => array_map(
            fn (mixed $row): mixed => is_array($row) ? [
                ...$row,
                'name' => trim((string) ($row['name'] ?? '')),
                'contact_email' => filled($row['contact_email'] ?? null) ? mb_strtolower(trim((string) $row['contact_email'])) : null,
                'portal_email' => filled($row['portal_email'] ?? null) ? mb_strtolower(trim((string) $row['portal_email'])) : null,
                'direct_bill_enabled' => filter_var($row['direct_bill_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ] : $row,
            array_values($rows),
        )]);
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

        $rules = [
            'rows' => ['present', 'array', 'max:'.self::MAX_ROWS],
            'rows.*.id' => [
                'nullable', 'integer', 'distinct',
                Rule::exists('companies', 'id')->where(
                    fn (Builder $query) => $query
                        ->where('property_id', $property->id)
                        ->where('status', CompanyStatus::Active->value),
                ),
            ],
            'rows.*.name' => [
                'required', 'string', 'max:150',
                fn (string $attribute, mixed $value, Closure $fail) => $this->checkName($property, $attribute, (string) $value, $fail),
            ],
        ];

        $fieldRules = CompanyRules::for($property);

        foreach (self::FIELDS as $field) {
            $rules["rows.*.{$field}"] = $fieldRules[$field];
        }

        $rules['rows.*.portal_email'] = [
            'nullable', 'email:rfc', 'max:255', 'distinct:ignore_case',
            fn (string $attribute, mixed $value, Closure $fail) => CompanyRules::portalEmailIsFree($property, $this->companyOfRow($property, $attribute))($attribute, $value, $fail),
        ];

        return $rules;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rows(): array
    {
        $property = $this->route('property');

        if (! $property instanceof Property) {
            throw new LogicException('The property route binding is missing.');
        }

        return array_map(function (array $row) use ($property): array {
            $fields = [];

            foreach (self::FIELDS as $field) {
                $value = $row[$field] ?? null;
                $fields[$field] = $value === '' ? null : $value;
            }

            return [
                'id' => isset($row['id']) ? (int) $row['id'] : null,
                'name' => $row['name'],
                ...CompanyRules::normalized($fields, $property),
                'direct_bill_enabled' => (bool) $row['direct_bill_enabled'],
            ];
        }, array_values($this->validated('rows')));
    }

    /**
     * The saved company of the row, or null for a new row.
     */
    private function companyOfRow(Property $property, string $attribute): ?Company
    {
        $id = data_get((array) $this->input('rows'), explode('.', $attribute)[1].'.id');

        return filled($id) ? $this->findCompany($property, $id) : null;
    }

    private function findCompany(Property $property, int|string $id): ?Company
    {
        return $property->companies()->find($id);
    }

    /**
     * The name must differ from the other rows and from every saved company that has no row of its own (a suspended
     * one), ignoring case.
     */
    private function checkName(Property $property, string $attribute, string $name, Closure $fail): void
    {
        $index = (int) explode('.', $attribute)[1];
        $lower = mb_strtolower($name);
        $rows = array_values((array) $this->input('rows'));

        foreach ($rows as $position => $row) {
            if ($position !== $index && mb_strtolower(trim((string) ($row['name'] ?? ''))) === $lower) {
                $fail(__('Another company in this table uses this name.'));

                return;
            }
        }

        $keptIds = array_filter(array_column($rows, 'id'));

        $taken = $property->companies()
            ->whereRaw('lower(name) = ?', [$lower])
            ->whereNotIn('id', $keptIds)
            ->exists();

        if ($taken) {
            $fail(__('Another company already uses this name.'));
        }
    }
}
