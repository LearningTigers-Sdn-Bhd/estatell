<?php

namespace App\Actions\Onboarding\Wastays;

use App\Actions\CityLedger\CreateCompany;
use App\Actions\CityLedger\EnableCityLedger;
use App\Actions\CityLedger\SuspendCompany;
use App\Actions\Rates\AddRatePlanCompanies;
use App\Enums\CompanyMarket;
use App\Enums\CompanyType;
use App\Http\Requests\CityLedger\CompanyRules;
use App\Models\Company;
use App\Models\Property;
use App\Models\RatePlan;
use App\Services\Onboarding\Wastays\ImportLinks;
use App\Services\Onboarding\Wastays\WastaysImportTarget;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Writes the companies of a wastays hotel (its `hotel_corporate_accounts` with the account name) through CreateCompany,
 * one `import_links` row per wastays company. Two wastays companies with the same name become one estatell company,
 * because a property keeps one company per name. No portal login is created, so nobody gets an invitation. The
 * companies named by `rate_plan_agency_rules` are added to the plans the setup import limited to companies.
 * See docs/migration-plan/sand-bay-resort-semporna.md "Companies".
 */
class ImportWastaysCompanies
{
    public function __construct(
        private CreateCompany $createCompany,
        private SuspendCompany $suspendCompany,
        private EnableCityLedger $enableCityLedger,
        private AddRatePlanCompanies $addRatePlanCompanies,
        private RecordImportLink $recordImportLink,
        private ImportLinks $links,
    ) {}

    /**
     * Counts only. A dry run does everything and rolls back.
     *
     * @return array<string, int>
     *
     * @throws InvalidArgumentException when the bundle has no companies file
     * @throws RuntimeException with a sentence for the console when a plan cannot take its companies
     */
    public function __invoke(WastaysImportTarget $target, bool $dryRun = false): array
    {
        $counts = [
            'created' => 0,
            'already linked' => 0,
            'same name as another company' => 0,
            'skipped without a name' => 0,
            'invalid emails dropped' => 0,
            'unreadable phones dropped' => 0,
            'suspended' => 0,
            'plan company links' => 0,
        ];

        DB::beginTransaction();

        try {
            $this->companies($target, $counts);
            $this->planCompanies($target, $counts);

            $dryRun ? DB::rollBack() : DB::commit();
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        return $counts;
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function companies(WastaysImportTarget $target, array &$counts): void
    {
        $property = $target->property;
        $names = $target->bundle->rows('accounts')->mapWithKeys(fn (array $row): array => [(string) $row['id'] => trim((string) $row['name'])])->all();
        $created = 0;

        foreach ($target->bundle->rows('hotel_corporate_accounts')->sortBy(fn (array $row): int => (int) $row['id']) as $row) {
            $id = (string) $row['id'];

            if ($this->links->has($property, 'hotel_corporate_accounts', $id)) {
                $counts['already linked']++;

                continue;
            }

            $name = $names[(string) $row['corporate_account_id']] ?? '';

            if ($name === '') {
                $counts['skipped without a name']++;

                continue;
            }

            $existing = $property->companies()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first();

            if ($existing instanceof Company) {
                ($this->recordImportLink)($property, 'hotel_corporate_accounts', $id, $existing);
                $counts['same name as another company']++;

                continue;
            }

            $company = ($this->createCompany)($target->actor, $property, $this->attributes($property, $row, $name, $counts));
            ($this->recordImportLink)($property, 'hotel_corporate_accounts', $id, $company);
            $counts['created']++;
            $created++;

            if (($row['status'] ?? 'active') === 'suspended' && ($this->suspendCompany)($target->actor, $company)) {
                $counts['suspended']++;
            }
        }

        if ($created > 0) {
            ($this->enableCityLedger)($property, $target->actor);
        }
    }

    /**
     * @param  array<string, string|null>  $row
     * @param  array<string, int>  $counts
     * @return array<string, mixed>
     */
    private function attributes(Property $property, array $row, string $name, array &$counts): array
    {
        $email = filled($row['contact_email'] ?? null) ? mb_strtolower(trim((string) $row['contact_email'])) : null;

        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $email = null;
            $counts['invalid emails dropped']++;
        }

        $phone = $this->phone($property, $row['contact_phone'] ?? null);

        if (filled($row['contact_phone'] ?? null) && $phone === null) {
            $counts['unreadable phones dropped']++;
        }

        return array_filter([
            'name' => mb_substr($name, 0, 150),
            'type' => $this->type((string) ($row['account_type'] ?? '')),
            'market' => CompanyMarket::tryFrom((string) ($row['market'] ?? ''))?->value,
            'contact_email' => $email,
            'contact_phone' => $phone,
            'credit_limit' => filled($row['credit_limit'] ?? null) ? number_format((float) $row['credit_limit'], 2, '.', '') : null,
            'payment_terms_days' => filled($row['payment_terms_days'] ?? null) ? min(365, max(0, (int) $row['payment_terms_days'])) : null,
            'direct_bill_enabled' => ($row['relationship_type'] ?? null) === 'direct_bill',
            'brn' => $this->limited($row['brn'] ?? null, 30),
            'tin' => $this->limited($row['tin'] ?? null, 30),
            'sst_registration_number' => $this->limited($row['sst_registration_number'] ?? null, 30),
            'billing_address_line1' => $this->limited($row['billing_address_line1'] ?? null, 150),
            'billing_address_line2' => $this->limited($row['billing_address_line2'] ?? null, 150),
            'billing_city' => $this->limited($row['billing_city'] ?? null, 100),
            'billing_postal_code' => $this->limited($row['billing_postal_code'] ?? null, 20),
        ], fn (mixed $value): bool => $value !== null);
    }

    private function type(string $accountType): CompanyType
    {
        return match ($accountType) {
            'travel_agent' => CompanyType::TravelAgent,
            'government' => CompanyType::Government,
            default => CompanyType::Corporate,
        };
    }

    private function limited(?string $value, int $length): ?string
    {
        return filled($value) ? mb_substr(trim($value), 0, $length) : null;
    }

    private function phone(Property $property, ?string $phone): ?string
    {
        if (blank($phone)) {
            return null;
        }

        try {
            return CompanyRules::normalized(['contact_phone' => trim($phone)], $property)['contact_phone'];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Add the companies the wastays agency rules name to the plan that merged the wastays plan copy.
     *
     * @param  array<string, int>  $counts
     */
    private function planCompanies(WastaysImportTarget $target, array &$counts): void
    {
        $property = $target->property;
        $byPlan = [];

        foreach ($target->bundle->rows('rate_plan_agency_rules') as $rule) {
            $plan = $this->links->target($property, 'rate_plans', (string) $rule['rate_plan_id']);
            $companyId = $this->links->targetId($property, 'hotel_corporate_accounts', (string) $rule['hotel_corporate_account_id']);

            if ($plan instanceof RatePlan && $companyId !== null) {
                $byPlan[$plan->id]['plan'] = $plan;
                $byPlan[$plan->id]['companies'][] = $companyId;
            }
        }

        foreach ($byPlan as $entry) {
            $companyIds = array_values(array_unique($entry['companies']));
            $before = $entry['plan']->companies()->count();

            if (($this->addRatePlanCompanies)($target->actor, $entry['plan'], $companyIds) !== null) {
                throw new RuntimeException("The companies could not be added to {$entry['plan']->name}.");
            }

            $counts['plan company links'] += $entry['plan']->companies()->count() - $before;
        }
    }
}
