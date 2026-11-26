<?php

namespace App\Services\Pos;

use App\Models\PosCategory;
use App\Models\PosDiningOption;
use App\Models\PosItem;
use App\Models\PosOutlet;
use App\Models\Property;
use App\Services\Pos\Calculation\PosCalculatorContext;
use App\Services\Pos\Calculation\PosCalculatorLine;
use App\Services\Pos\Calculation\PosCalculatorResult;
use Brick\Math\BigDecimal;

/**
 * Prices one unit of every variant in an offline menu with `PosOrderCalculator`, under the default dining option for a
 * walk-in guest, so the offline till can show service charge and tax without doing tax maths itself. The tablet only
 * multiplies and adds, so a larger sale can differ by a sen or so. The server still prices the sale when it syncs. A
 * variant the calculator refuses gets no quote. Reads only. See docs/34-native-pos/06-kitchen-kds-and-qr.md.
 */
class PosOfflineEstimate
{
    public function __construct(private PosOrderCalculator $calculator, private PosTaxResolver $taxResolver) {}

    /**
     * @param  array<string, mixed>  $menu  An `OutletMenuSnapshot` payload.
     * @return array<string, mixed> The same menu with `quote` on each variant, plus `packagingQuote` and `cashRounding`.
     */
    public function __invoke(Property $property, PosOutlet $outlet, array $menu): array
    {
        $dining = PosDiningOption::query()->where('pos_outlet_id', $outlet->id)->where('active', true)->orderByDesc('is_default')->orderBy('name')->first();
        $items = PosItem::query()->where('property_id', $property->id)->get()->keyBy('id');
        $categoryCodes = PosCategory::query()->where('property_id', $property->id)->pluck('transaction_code_id', 'id');
        $sstRegistered = $this->taxResolver->sstRegistrationNumber($outlet, $property) !== '';
        $taxes = [];
        $packaging = null;

        foreach ($menu['categories'] as $c => $category) {
            foreach ($category['items'] as $i => $row) {
                $item = $items->get($row['id']);
                $codeId = (int) (($item === null ? null : $item->transaction_code_id) ?? $categoryCodes->get($category['id']));

                foreach ($row['variants'] as $v => $variant) {
                    $quote = null;

                    if ($dining !== null && $codeId > 0) {
                        $line = new PosCalculatorLine(
                            key: 1,
                            unitPrice: (string) $variant['price'],
                            quantity: '1',
                            priceIncludesTax: (bool) $row['priceIncludesTax'],
                            taxes: $taxes[$codeId] ??= $this->taxResolver->forTransactionCode($property, $codeId),
                            serviceChargeExempt: (bool) $row['serviceChargeExempt'],
                        );
                        $result = $this->price($line, $dining, $sstRegistered, '0');

                        if (! $result->isRefused()) {
                            $quote = ['serviceCharge' => $result->serviceChargeTotal, 'tax' => $result->taxTotal, 'total' => $result->grandTotal];
                            $packaging ??= $this->packaging($property, $outlet, $line, $dining, $sstRegistered, $result->grandTotal);
                        }
                    }

                    $menu['categories'][$c]['items'][$i]['variants'][$v]['quote'] = $quote;
                }
            }
        }

        return [...$menu, 'packagingQuote' => $packaging ?? '0.00', 'cashRounding' => PosOutlet::query()->findOrFail($outlet->id)->rounding_mode->value];
    }

    /** What the packaging fee and its taxes add to one sale, or null when there is no fee to add. */
    private function packaging(Property $property, PosOutlet $outlet, PosCalculatorLine $line, PosDiningOption $dining, bool $sstRegistered, string $without): ?string
    {
        if (! BigDecimal::of($dining->packaging_fee)->isPositive() || $outlet->packaging_transaction_code_id === null) {
            return null;
        }

        $with = $this->price($line, $dining, $sstRegistered, $dining->packaging_fee, $this->taxResolver->forTransactionCode($property, $outlet->packaging_transaction_code_id));

        return $with->isRefused() ? null : (string) BigDecimal::of($with->grandTotal)->minus($without);
    }

    /**
     * @param  list<Calculation\PosCalculatorTax>  $packagingTaxes
     */
    private function price(PosCalculatorLine $line, PosDiningOption $dining, bool $sstRegistered, string $packagingFee, array $packagingTaxes = []): PosCalculatorResult
    {
        return $this->calculator->calculate([$line], new PosCalculatorContext(
            applyServiceCharge: $dining->apply_service_charge,
            serviceChargeRate: $dining->service_charge_rate,
            serviceChargeTaxable: $dining->service_charge_taxable,
            packagingFee: $packagingFee,
            packagingTaxes: $packagingTaxes,
            sstRegistered: $sstRegistered,
        ));
    }
}
