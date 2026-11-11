<?php

namespace App\Services\EInvoice;

use App\Enums\TransactionKind;
use App\Models\Folio;
use App\Models\Property;
use App\Models\PropertyEInvoiceSetting;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

class ConsolidatedBatchBuilder
{
    public const string INVOICE_TYPE_CODE = '01';

    public const string CONSOLIDATED_CLASS_CODE = '004';

    public const string GENERAL_CONSUMER_TIN = 'EI00000000010';

    public const string GENERAL_CONSUMER_NAME = 'General Public';

    public const string DEFAULT_CURRENCY = 'MYR';

    public const string ORIGIN_COUNTRY_CODE = 'MYS';

    public const string UNIT_CODE_EACH = 'C62';

    public const int MAX_FOLIOS_PER_DOCUMENT = 100;

    public const float MAX_INDIVIDUAL_STAY_AMOUNT = 10000.0;

    private PropertyEInvoiceSetting $setting;

    /** @var list<array{folio: Folio, net: float, tax: float, taxes: array<string, float>}>|null */
    private ?array $stays = null;

    private DocumentSigner $documentSigner;

    /**
     * @param  Collection<int, Folio>  $folios
     */
    public function __construct(
        private Property $property,
        private Collection $folios,
        private CarbonImmutable $month
    ) {
        if ($this->property->eInvoiceSetting === null) {
            throw new RuntimeException('Property e-invoice settings are not configured.');
        }

        $this->setting = $this->property->eInvoiceSetting;
        $this->documentSigner = new DocumentSigner($this->setting);
    }

    /**
     * @return array{
     *     format: string,
     *     document: string,
     *     documentHash: string,
     *     codeNumber: string,
     *     payload: array<string, mixed>
     * }
     */
    public function build(): array
    {
        $internalId = 'CONS-'.$this->property->id.'-'.$this->month->format('Ym').'-'.strtoupper(Str::random(6));
        $payload = $this->buildPayload($internalId);
        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return [
            'format' => 'JSON',
            'document' => base64_encode($payloadJson),
            'documentHash' => hash('sha256', $payloadJson),
            'codeNumber' => $internalId,
            'payload' => $payload,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildPayload(string $internalId): array
    {
        // LHDN reads IssueDate and IssueTime as UTC, so local time here would be 8 hours in the future.
        $issuedAt = now('UTC');

        $body = [
            'ID' => [['_' => $internalId]],
            'IssueDate' => [['_' => $issuedAt->toDateString()]],
            'IssueTime' => [['_' => $issuedAt->format('H:i:s').'Z']],
            'InvoiceTypeCode' => [['_' => self::INVOICE_TYPE_CODE, 'listVersionID' => $this->documentSigner->documentVersion()]],
            'DocumentCurrencyCode' => [['_' => self::DEFAULT_CURRENCY]],
            'TaxCurrencyCode' => [['_' => self::DEFAULT_CURRENCY]],
            'InvoicePeriod' => [
                [
                    'StartDate' => [['_' => $this->month->startOfMonth()->toDateString()]],
                    'EndDate' => [['_' => $this->month->endOfMonth()->toDateString()]],
                    'Description' => [['_' => 'Monthly Consolidated Stays']],
                ],
            ],
            'AccountingSupplierParty' => [['Party' => [$this->supplierParty()]]],
            'AccountingCustomerParty' => [['Party' => [$this->consolidatedBuyerParty()]]],
            'InvoiceLine' => $this->invoiceLines(),
            'TaxTotal' => [$this->taxTotalBlock()],
            'LegalMonetaryTotal' => [$this->monetaryTotal()],
        ];

        return $this->documentSigner->apply(array_merge(FolioDocumentBuilder::UBL_NAMESPACES, ['Invoice' => [$body]]));
    }

    /**
     * @return array<string, mixed>
     */
    private function supplierParty(): array
    {
        MissingEInvoiceDetails::hotel($this->property);

        return app(SupplierDetails::class)->party($this->property);
    }

    /**
     * @return array<string, mixed>
     */
    private function consolidatedBuyerParty(): array
    {
        return [
            'PartyIdentification' => [
                ['ID' => [['_' => self::GENERAL_CONSUMER_TIN, 'schemeID' => 'TIN']]],
                ['ID' => [['_' => 'NA', 'schemeID' => 'BRN']]],
            ],
            'PostalAddress' => [
                [
                    'CityName' => [['_' => 'NA']],
                    'PostalZone' => [['_' => '00000']],
                    'CountrySubentityCode' => [['_' => MalaysiaStates::NOT_APPLICABLE]],
                    'AddressLine' => [
                        ['Line' => [['_' => 'NA']]],
                        ['Line' => [['_' => '']]],
                        ['Line' => [['_' => '']]],
                    ],
                    'Country' => [['IdentificationCode' => [['_' => self::ORIGIN_COUNTRY_CODE]]]],
                ],
            ],
            'PartyLegalEntity' => [['RegistrationName' => [['_' => self::GENERAL_CONSUMER_NAME]]]],
            'Contact' => [
                [
                    'Telephone' => [['_' => 'NA']],
                    'ElectronicMail' => [['_' => 'NA']],
                ],
            ],
        ];
    }

    /**
     * One line per stay: its charges before tax, and the taxes posted on it by LHDN tax type (SST is Service Tax
     * `02`). A stay without tax is exempt (`E`). The stay's own total, tax included, is what the guest paid.
     *
     * @return list<array<string, mixed>>
     */
    private function invoiceLines(): array
    {
        $lines = [];
        $idx = 1;

        foreach ($this->stays() as $stay) {
            $folioNumber = $stay['folio']->invoice_number ?: $stay['folio']->number;

            $lines[] = [
                'ID' => [['_' => (string) $idx++]],
                'InvoicedQuantity' => [['_' => 1, 'unitCode' => self::UNIT_CODE_EACH]],
                'LineExtensionAmount' => [['_' => $stay['net'], 'currencyID' => self::DEFAULT_CURRENCY]],
                'AllowanceCharge' => [
                    [
                        'ChargeIndicator' => [['_' => false]],
                        'Amount' => [['_' => 0.0, 'currencyID' => self::DEFAULT_CURRENCY]],
                    ],
                ],
                'TaxTotal' => [
                    [
                        'TaxAmount' => [['_' => $stay['tax'], 'currencyID' => self::DEFAULT_CURRENCY]],
                        'TaxSubtotal' => $this->taxSubtotals($stay['taxes'], $stay['net']),
                    ],
                ],
                'Item' => [
                    [
                        'CommodityClassification' => [
                            ['ItemClassificationCode' => [['_' => self::CONSOLIDATED_CLASS_CODE, 'listID' => 'CLASS']]],
                        ],
                        'Description' => [['_' => "Consolidated stay receipt {$folioNumber}"]],
                        'OriginCountry' => [['IdentificationCode' => [['_' => self::ORIGIN_COUNTRY_CODE]]]],
                    ],
                ],
                'Price' => [['PriceAmount' => [['_' => $stay['net'], 'currencyID' => self::DEFAULT_CURRENCY]]]],
                'ItemPriceExtension' => [['Amount' => [['_' => $stay['net'], 'currencyID' => self::DEFAULT_CURRENCY]]]],
            ];
        }

        return $lines;
    }

    /**
     * The document's tax: each tax type's total over the stays that carry it, with those stays' charges as its
     * taxable amount, plus an exempt subtotal for the stays without tax.
     *
     * @return array<string, mixed>
     */
    private function taxTotalBlock(): array
    {
        $byType = [];
        $untaxed = 0.0;
        $total = 0.0;

        foreach ($this->stays() as $stay) {
            $total += $stay['tax'];
            if ($stay['taxes'] === []) {
                $untaxed += $stay['net'];

                continue;
            }

            foreach ($stay['taxes'] as $type => $amount) {
                $byType[$type] ??= ['taxable' => 0.0, 'tax' => 0.0];
                $byType[$type]['taxable'] += $stay['net'];
                $byType[$type]['tax'] += $amount;
            }
        }

        $subtotals = [];
        foreach ($byType as $type => $sums) {
            $subtotals[] = $this->taxSubtotal((string) $type, round($sums['taxable'], 2), round($sums['tax'], 2));
        }
        if ($untaxed > 0.0 || $subtotals === []) {
            $subtotals[] = $this->taxSubtotal('E', round($untaxed, 2), 0.0);
        }

        return [
            'TaxAmount' => [['_' => round($total, 2), 'currencyID' => self::DEFAULT_CURRENCY]],
            'TaxSubtotal' => $subtotals,
        ];
    }

    /**
     * @param  array<string, float>  $taxes  Tax amount by LHDN tax type.
     * @return list<array<string, mixed>>
     */
    private function taxSubtotals(array $taxes, float $net): array
    {
        if ($taxes === []) {
            return [$this->taxSubtotal('E', $net, 0.0)];
        }

        $subtotals = [];
        foreach ($taxes as $type => $amount) {
            $subtotals[] = $this->taxSubtotal((string) $type, $net, $amount);
        }

        return $subtotals;
    }

    /**
     * @return array<string, mixed>
     */
    private function taxSubtotal(string $type, float $taxable, float $tax): array
    {
        return [
            'TaxableAmount' => [['_' => $taxable, 'currencyID' => self::DEFAULT_CURRENCY]],
            'TaxAmount' => [['_' => $tax, 'currencyID' => self::DEFAULT_CURRENCY]],
            'TaxCategory' => [
                [
                    'ID' => [['_' => $type]],
                    'TaxExemptionReason' => [['_' => $type === 'E' ? 'No tax charged on these stays' : '']],
                    'TaxScheme' => [['ID' => [['_' => 'OTH', 'schemeID' => 'UN/ECE 5153', 'schemeAgencyID' => '6']]]],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function monetaryTotal(): array
    {
        $net = 0.0;
        $gross = 0.0;
        foreach ($this->stays() as $stay) {
            $net += $stay['net'];
            $gross += $stay['net'] + $stay['tax'];
        }
        $net = round($net, 2);
        $gross = round($gross, 2);

        return [
            'LineExtensionAmount' => [['_' => $net, 'currencyID' => self::DEFAULT_CURRENCY]],
            'TaxExclusiveAmount' => [['_' => $net, 'currencyID' => self::DEFAULT_CURRENCY]],
            'TaxInclusiveAmount' => [['_' => $gross, 'currencyID' => self::DEFAULT_CURRENCY]],
            'AllowanceTotalAmount' => [['_' => 0.0, 'currencyID' => self::DEFAULT_CURRENCY]],
            'ChargeTotalAmount' => [['_' => 0.0, 'currencyID' => self::DEFAULT_CURRENCY]],
            'PayableRoundingAmount' => [['_' => 0.0, 'currencyID' => self::DEFAULT_CURRENCY]],
            'PayableAmount' => [['_' => $gross, 'currencyID' => self::DEFAULT_CURRENCY]],
        ];
    }

    /**
     * The stays that go in the summary (over RM0 and at most RM10,000), each split into charges and taxes.
     *
     * @return list<array{folio: Folio, net: float, tax: float, taxes: array<string, float>}>
     */
    private function stays(): array
    {
        if ($this->stays !== null) {
            return $this->stays;
        }

        $taxTypes = new LhdnTaxType;
        $this->stays = [];

        foreach ($this->folios as $folio) {
            $gross = self::calculateFolioGrossAmount($folio);
            if ($gross <= 0.0 || $gross > self::MAX_INDIVIDUAL_STAY_AMOUNT) {
                continue;
            }

            $split = self::split($folio, $taxTypes);
            $this->stays[] = ['folio' => $folio, ...$split];
        }

        return $this->stays;
    }

    /**
     * A stay's charges before tax and its taxes by LHDN tax type, as the summary reports them. Expects `lines.reversal`.
     *
     * @return array{net: float, tax: float, taxes: array<string, float>}
     */
    public static function split(Folio $folio, ?LhdnTaxType $taxTypes = null): array
    {
        $taxTypes ??= new LhdnTaxType;
        $net = BigDecimal::zero();
        $tax = BigDecimal::zero();
        $taxes = [];

        foreach ($folio->lines as $line) {
            if ($line->kind === TransactionKind::Payment || $line->reversal_of_id !== null || $line->reversal !== null) {
                continue;
            }

            if ($line->kind === TransactionKind::Tax) {
                $type = $taxTypes->for($line);
                $taxes[$type] = ($taxes[$type] ?? 0.0) + (float) $line->amount;
                $tax = $tax->plus($line->amount);
            } else {
                $net = $net->plus($line->amount);
            }
        }

        return [
            'net' => (float) (string) $net->toScale(2),
            'tax' => (float) (string) $tax->toScale(2),
            'taxes' => array_map(fn (float $amount): float => round($amount, 2), $taxes),
        ];
    }

    /**
     * What the stay cost the guest: charges, adjustments and taxes that were not reversed. Payments are left out,
     * because a closed folio's payments always bring its balance to 0.
     */
    public static function calculateFolioGrossAmount(Folio $folio): float
    {
        $sum = BigDecimal::zero();
        foreach ($folio->lines as $line) {
            if ($line->kind !== TransactionKind::Payment && $line->reversal_of_id === null && $line->reversal === null) {
                $sum = $sum->plus($line->amount);
            }
        }

        return (float) (string) $sum->toScale(2);
    }
}
