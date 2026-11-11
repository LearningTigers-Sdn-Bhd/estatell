<?php

namespace App\Services\EInvoice;

use App\Models\FolioLine;
use App\Models\Tax;

/**
 * The LHDN tax type of a posted tax line, from the property's tax catalogue (`taxes.code`), not from its wording:
 * `02` Service Tax for SST, `03` Tourism Tax for TTX, and `06` Not applicable for any other levy, such as a council
 * heritage fee. A line whose tax is not in the catalogue falls back to its name. See
 * docs/30-einvoice/01-lhdn-myinvois.md#tax.
 */
class LhdnTaxType
{
    public const string SERVICE_TAX = '02';

    public const string TOURISM_TAX = '03';

    public const string NOT_APPLICABLE = '06';

    /** @var array<int, string|null> Tax code by transaction code id. */
    private array $codes = [];

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

    private function taxCode(?int $transactionCodeId): ?string
    {
        if ($transactionCodeId === null) {
            return null;
        }

        if (! array_key_exists($transactionCodeId, $this->codes)) {
            $code = Tax::query()->where('transaction_code_id', $transactionCodeId)->value('code');
            $this->codes[$transactionCodeId] = $code === null ? null : strtoupper((string) $code);
        }

        return $this->codes[$transactionCodeId];
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
