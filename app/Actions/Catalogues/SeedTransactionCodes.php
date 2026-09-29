<?php

namespace App\Actions\Catalogues;

use App\Enums\SystemTransactionCode;
use App\Models\Property;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SeedTransactionCodes
{
    /**
     * Create the system transaction codes available for a property's country.
     * Existing system codes keep every property-owned value when this runs again.
     */
    public function __invoke(Property $property, string $countryCode): void
    {
        if ($countryCode !== 'MY') {
            throw new InvalidArgumentException("Transaction codes are not available for country [{$countryCode}].");
        }

        DB::transaction(function () use ($property): void {
            $existingCodes = $property->transactionCodes()
                ->pluck('code')
                ->map(fn (mixed $code): string => (string) $code);

            foreach (SystemTransactionCode::cases() as $systemCode) {
                if ($property->transactionCodes()->where('system_key', $systemCode->value)->exists()) {
                    continue;
                }

                $code = $this->availableCode($systemCode->code(), $existingCodes);

                $property->transactionCodes()->create([
                    'system_key' => $systemCode->value,
                    'code' => $code,
                    'name' => $systemCode->label(),
                    'kind' => $systemCode->kind(),
                    'category' => $systemCode->category(),
                    'gl_account_code' => null,
                    'is_system' => true,
                    'active' => true,
                ]);

                $existingCodes->push($code);
            }
        });
    }

    /** @param Collection<int, string> $existingCodes */
    private function availableCode(string $canonicalCode, Collection $existingCodes): string
    {
        if (! $existingCodes->containsStrict($canonicalCode)) {
            return $canonicalCode;
        }

        for ($suffix = 2; ; $suffix++) {
            $candidate = $canonicalCode.'_'.$suffix;

            if (! $existingCodes->containsStrict($candidate)) {
                return $candidate;
            }
        }
    }
}
