<?php

namespace App\Services\EInvoice;

use App\Enums\TaxRateType;
use App\Models\FolioLine;
use App\Models\Tax;
use Brick\Math\BigDecimal;

/**
 * The LHDN tax type of a posted tax line, from the property's tax catalogue (`taxes.code`), not from its wording:
 * `02` Service Tax for SST, `03` Tourism Tax for TTX, and `06` Not applicable for any other levy, such as a council
 * heritage fee. A line whose tax is not in the catalogue falls back to its name. See
 * docs/31-einvoice/01-lhdn-myinvois.md#tax.
 */
class LhdnTaxType
{
    public const string SERVICE_TAX = '02';

    public const string TOURISM_TAX = '03';

    public const string NOT_APPLICABLE = '06';

    /** @var array<int, array{code: string|null, percent: BigDecimal|null}> The tax behind each transaction code id. */
    private array $taxes = [];

    public function for(FolioLine $taxLine): string
    {
        $code = $this->taxCode($taxLine->transaction_code_id);

        return match (true) {
            $code === 'SST' => self::SERVICE_TAX,
            $code === 'TTX' => self::TOURISM_TAX,
            $code !== null => self::NOT_APPLICABLE,
            default => $this->fromName($taxLine),
        };
    }

    /**
     * The rate of a percentage tax from the tax catalogue (8 for SST at 8%), or null for a flat tax such as tourism
     * tax, or a line whose tax is not in the catalogue. An LHDN document carries it as the line's `Percent`.
     */
    public function percent(FolioLine $taxLine): ?BigDecimal
    {
        return $this->tax($taxLine->transaction_code_id)['percent'];
    }

    private function taxCode(?int $transactionCodeId): ?string
    {
        return $this->tax($transactionCodeId)['code'];
    }

    /**
     * @return array{code: string|null, percent: BigDecimal|null}
     */
    private function tax(?int $transactionCodeId): array
    {
        if ($transactionCodeId === null) {
            return ['code' => null, 'percent' => null];
        }

        if (! array_key_exists($transactionCodeId, $this->taxes)) {
            $tax = Tax::query()->where('transaction_code_id', $transactionCodeId)->first(['code', 'rate_type', 'rate']);
            $this->taxes[$transactionCodeId] = [
                'code' => $tax === null ? null : strtoupper((string) $tax->code),
                'percent' => $tax !== null && $tax->rate_type === TaxRateType::Percent ? BigDecimal::of($tax->rate)->toScale(2) : null,
            ];
        }

        return $this->taxes[$transactionCodeId];
    }

    private function fromName(FolioLine $taxLine): string
    {
        $name = strtolower($taxLine->description.' '.($taxLine->transactionCode->name ?? ''));

        return match (true) {
            str_contains($name, 'sst') || str_contains($name, 'service tax') => self::SERVICE_TAX,
            str_contains($name, 'ttx') || str_contains($name, 'tourism') => self::TOURISM_TAX,
            default => self::NOT_APPLICABLE,
        };
    }
}
