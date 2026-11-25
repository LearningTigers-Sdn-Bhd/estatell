<?php

namespace App\Services\Pos;

use App\Models\PosOutlet;
use App\Models\Property;
use App\Models\Tax;
use App\Services\EInvoice\LhdnTaxType;
use App\Services\EInvoice\SupplierDetails;
use App\Services\Pos\Calculation\PosCalculatorTax;

/**
 * Finds the taxes a POS line owes from the property's tax catalogue, so no rate is ever written in POS code, and the
 * SST registration number that lets an outlet charge SST. It reads only.
 */
class PosTaxResolver
{
    public function __construct(private SupplierDetails $supplierDetails) {}

    /**
     * The enabled taxes linked to a transaction code, in a stable order, with their LHDN type (the tax's own type, else by its code).
     *
     * @return list<PosCalculatorTax>
     */
    public function forTransactionCode(Property $property, int $transactionCodeId): array
    {
        $taxes = $property->taxes()
            ->where('enabled', true)
            ->whereHas('applicableTransactionCodes', fn ($codes) => $codes->whereKey($transactionCodeId))
            ->orderBy('id')
            ->get()
            ->map(fn (Tax $tax): PosCalculatorTax => new PosCalculatorTax(
                taxId: $tax->id,
                transactionCodeId: $tax->transaction_code_id,
                code: $tax->code,
                name: $tax->name,
                lhdnTaxType: $tax->lhdn_tax_type ?? match (strtoupper($tax->code)) {
                    'SST' => LhdnTaxType::SERVICE_TAX,
                    'TTX' => LhdnTaxType::TOURISM_TAX,
                    default => null,
                },
                rateType: $tax->rate_type,
                rate: $tax->rate,
                foreignGuestsOnly: $tax->foreign_guests_only,
            ))
            ->all();

        return array_values($taxes);
    }

    /**
     * The SST registration number the outlet sells under: its own, else the hotel's e-invoice setting, else the one on an
     * SST tax. Blank when there is none, and then a sale with SST is refused.
     */
    public function sstRegistrationNumber(PosOutlet $outlet, Property $property): string
    {
        $own = trim((string) $outlet->sst_registration_number);

        if ($own !== '') {
            return $own;
        }

        $setting = $this->supplierDetails->for($property)['sst'];

        if ($setting !== '') {
            return $setting;
        }

        return trim((string) $property->taxes()
            ->where('enabled', true)
            ->where(fn ($query) => $query->where('lhdn_tax_type', LhdnTaxType::SERVICE_TAX)->orWhere(fn ($fallback) => $fallback->whereNull('lhdn_tax_type')->whereRaw("upper(code) = 'SST'")))
            ->whereNotNull('registration_number')
            ->orderBy('id')
            ->value('registration_number'));
    }
}
