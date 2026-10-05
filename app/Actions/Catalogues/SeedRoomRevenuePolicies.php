<?php

namespace App\Actions\Catalogues;

use App\Enums\PolicyChargeMode;
use App\Enums\PolicyScope;
use App\Enums\SystemTransactionCode;
use App\Models\Property;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SeedRoomRevenuePolicies
{
    /**
     * Create the four policy rows as Free, each with a short default text in English and Malay.
     * A row that already exists keeps every value it has, so this is safe to run again.
     */
    public function __invoke(Property $property): void
    {
        DB::transaction(function () use ($property): void {
            foreach (SystemTransactionCode::policyCases() as $code) {
                [$scope, $textEn, $textMs] = $this->defaultsFor($code);

                $property->roomRevenuePolicies()->firstOrCreate(
                    ['code' => $code->value],
                    [
                        'charge_mode' => PolicyChargeMode::Free,
                        'applies_to' => $scope,
                        'text_en' => $textEn,
                        'text_ms' => $textMs,
                    ],
                );
            }
        });
    }

    /**
     * @return array{PolicyScope, string, string}
     */
    private function defaultsFor(SystemTransactionCode $code): array
    {
        return match ($code) {
            SystemTransactionCode::NoShowRevenue => [PolicyScope::FirstNight, 'No charge for a no-show.', 'Tiada caj untuk tidak hadir.'],
            SystemTransactionCode::CancellationRevenue => [PolicyScope::FirstNight, 'No charge for cancellation.', 'Tiada caj untuk pembatalan.'],
            SystemTransactionCode::EarlyDepartureRevenue => [PolicyScope::UnusedNights, 'No charge for early departure.', 'Tiada caj untuk berlepas awal.'],
            SystemTransactionCode::LateCheckoutRevenue => [PolicyScope::OneCharge, 'No charge for late check-out.', 'Tiada caj untuk daftar keluar lewat.'],
            default => throw new InvalidArgumentException("[{$code->value}] has no room revenue policy."),
        };
    }
}
