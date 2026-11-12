<?php

namespace App\Services\EInvoice;

use App\Models\Folio;
use App\Models\Property;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Builds one part of a monthly summary (`01` to LHDN's general public buyer): one line per stay with its charges
 * before tax and its taxes by LHDN tax type, and the totals by tax type. Every amount is summed exactly
 * (`ReportableLines`) and becomes a JSON number only at the end (`LhdnAmount`). It reads only.
 */
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

    /** The description of each stay's line; `SubmissionView` reads the folio number back from it. */
    public const string LINE_DESCRIPTION = 'Consolidated stay receipt';

    /**
     * The buyer of every monthly summary, as LHDN gets it: the general public. A note on a summary names the same
     * buyer (LHDN refuses a different one, `DR308`).
     *
     * @return array{name: string, tin: string, id_type: string, id_value: string, sst_registration: null, email: string, phone: string, address_line1: string, address_line2: string, city: string, postal_code: string, state_code: string, country_code: string}
     */
    public static function generalPublicBuyer(): array
    {
        return [
            'name' => self::GENERAL_CONSUMER_NAME,
            'tin' => self::GENERAL_CONSUMER_TIN,
            'id_type' => 'BRN',
            'id_value' => 'NA',
            'sst_registration' => null,
            'email' => 'NA',
            'phone' => 'NA',
            'address_line1' => 'NA',
            'address_line2' => '',
            'city' => 'NA',
            'postal_code' => '00000',
            'state_code' => MalaysiaStates::NOT_APPLICABLE,
            'country_code' => self::ORIGIN_COUNTRY_CODE,
        ];
    }

    public function __construct(
        private ReportableLines $reportableLines,
        private SupplierDetails $supplierDetails,
        private DocumentEncoder $documentEncoder,
        private StaysNeedingOwnEInvoice $staysNeedingOwnEInvoice,
    ) {}

    /**
     * Whether a stay belongs in a monthly summary: it cost something, and not more than RM10,000.
     */
    public function fitsInSummary(Folio $folio): bool
    {
        return $this->reportableLines->gross($folio)->isPositive() && ! $this->staysNeedingOwnEInvoice->isOverLimit($folio);
    }

    /**
     * @param  Collection<int, Folio>  $folios  At most `MAX_FOLIOS_PER_DOCUMENT`; a stay that does not fit is left out.
     * @return array{format: string, document: string, documentHash: string, codeNumber: string, payload: array<string, mixed>, lines: array<int, int>}
     *                                                                                                                                                  `lines` is the line number of each stay in the document, by folio id.
     *
     * @throws MissingEInvoiceDetails when the hotel lacks a detail LHDN needs.
     */
    public function build(Property $property, Collection $folios, CarbonImmutable $month): array
    {
        if ($property->eInvoiceSetting === null) {
            throw new RuntimeException('Property e-invoice settings are not configured.');
        }

        MissingEInvoiceDetails::hotel($property);

        $signer = new DocumentSigner($property->eInvoiceSetting);
        $internalId = 'CONS-'.$property->id.'-'.$month->format('Ym').'-'.strtoupper(Str::random(6));
        $stays = $this->stays($folios);

        // LHDN reads IssueDate and IssueTime as UTC, so local time here would be 8 hours in the future.
        $issuedAt = now('UTC');

        $body = [
            'ID' => [['_' => $internalId]],
            'IssueDate' => [['_' => $issuedAt->toDateString()]],
            'IssueTime' => [['_' => $issuedAt->format('H:i:s').'Z']],
            'InvoiceTypeCode' => [['_' => self::INVOICE_TYPE_CODE, 'listVersionID' => $signer->documentVersion()]],
            'DocumentCurrencyCode' => [['_' => self::DEFAULT_CURRENCY]],
            'TaxCurrencyCode' => [['_' => self::DEFAULT_CURRENCY]],
            'InvoicePeriod' => [
                [
                    'StartDate' => [['_' => $month->startOfMonth()->toDateString()]],
                    'EndDate' => [['_' => $month->endOfMonth()->toDateString()]],
                    'Description' => [['_' => 'Monthly Consolidated Stays']],
                ],
            ],
            'AccountingSupplierParty' => [['Party' => [$this->supplierDetails->party($property)]]],
            'AccountingCustomerParty' => [['Party' => [$this->consolidatedBuyerParty()]]],
            'InvoiceLine' => $this->invoiceLines($stays),
            'TaxTotal' => [$this->taxTotalBlock($stays)],
            'LegalMonetaryTotal' => [$this->monetaryTotal($stays)],
        ];

        $payload = $signer->apply(array_merge(FolioDocumentBuilder::UBL_NAMESPACES, ['Invoice' => [$body]]));

        $lines = [];
        foreach ($stays as $index => $stay) {
            $lines[$stay['folio']->id] = $index + 1;
        }

        return [...$this->documentEncoder->encode($payload, $internalId), 'lines' => $lines];
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
     * @param  list<array{folio: Folio, net: BigDecimal, tax: BigDecimal, taxes: array<string, BigDecimal>, taxable: array<string, BigDecimal>, percent: array<string, BigDecimal|null>, untaxed: BigDecimal}>  $stays
     * @return list<array<string, mixed>>
     */
    private function invoiceLines(array $stays): array
    {
        $lines = [];

        foreach ($stays as $index => $stay) {
            $folioNumber = $stay['folio']->invoice_number ?: $stay['folio']->number;
            $net = LhdnAmount::of($stay['net']);

            $lines[] = [
                'ID' => [['_' => (string) ($index + 1)]],
                'InvoicedQuantity' => [['_' => 1, 'unitCode' => self::UNIT_CODE_EACH]],
                'LineExtensionAmount' => [['_' => $net, 'currencyID' => self::DEFAULT_CURRENCY]],
                'AllowanceCharge' => [
                    [
                        'ChargeIndicator' => [['_' => false]],
                        'Amount' => [['_' => 0.0, 'currencyID' => self::DEFAULT_CURRENCY]],
                    ],
                ],
                'TaxTotal' => [
                    [
                        'TaxAmount' => [['_' => LhdnAmount::of($stay['tax']), 'currencyID' => self::DEFAULT_CURRENCY]],
                        'TaxSubtotal' => $this->taxSubtotals($stay),
                    ],
                ],
                'Item' => [
                    [
                        'CommodityClassification' => [
                            ['ItemClassificationCode' => [['_' => self::CONSOLIDATED_CLASS_CODE, 'listID' => 'CLASS']]],
                        ],
                        'Description' => [['_' => self::LINE_DESCRIPTION." {$folioNumber}"]],
                        'OriginCountry' => [['IdentificationCode' => [['_' => self::ORIGIN_COUNTRY_CODE]]]],
                    ],
                ],
                'Price' => [['PriceAmount' => [['_' => $net, 'currencyID' => self::DEFAULT_CURRENCY]]]],
                'ItemPriceExtension' => [['Amount' => [['_' => $net, 'currencyID' => self::DEFAULT_CURRENCY]]]],
            ];
        }

        return $lines;
    }

    /**
     * The document's tax: each tax type's total over the stays, with the charges it is on as its taxable amount, plus
     * an exempt subtotal for the charges with no tax.
     *
     * @param  list<array{folio: Folio, net: BigDecimal, tax: BigDecimal, taxes: array<string, BigDecimal>, taxable: array<string, BigDecimal>, percent: array<string, BigDecimal|null>, untaxed: BigDecimal}>  $stays
     * @return array<string, mixed>
     */
    private function taxTotalBlock(array $stays): array
    {
        $byType = [];
        $untaxed = BigDecimal::zero();
        $total = BigDecimal::zero();

        foreach ($stays as $stay) {
            $total = $total->plus($stay['tax']);
            $untaxed = $untaxed->plus($stay['untaxed']);

            foreach ($stay['taxes'] as $type => $amount) {
                $byType[$type] ??= ['taxable' => BigDecimal::zero(), 'tax' => BigDecimal::zero()];
                $byType[$type]['taxable'] = $byType[$type]['taxable']->plus($stay['taxable'][$type]);
                $byType[$type]['tax'] = $byType[$type]['tax']->plus($amount);
            }
        }

        $subtotals = [];
        foreach ($byType as $type => $sums) {
            $subtotals[] = $this->taxSubtotal((string) $type, $sums['taxable'], $sums['tax']);
        }
        if ($untaxed->isPositive() || $subtotals === []) {
            $subtotals[] = $this->taxSubtotal('E', $untaxed, BigDecimal::zero());
        }

        return [
            'TaxAmount' => [['_' => LhdnAmount::of($total), 'currencyID' => self::DEFAULT_CURRENCY]],
            'TaxSubtotal' => $subtotals,
        ];
    }

    /**
     * A stay line's tax: each tax type on the charges it is on, with its rate when it is a percentage, and the
     * charges with no tax as exempt.
     *
     * @param  array{net: BigDecimal, taxes: array<string, BigDecimal>, taxable: array<string, BigDecimal>, percent: array<string, BigDecimal|null>, untaxed: BigDecimal}  $stay
     * @return list<array<string, mixed>>
     */
    private function taxSubtotals(array $stay): array
    {
        $subtotals = [];
        foreach ($stay['taxes'] as $type => $amount) {
            $subtotals[] = $this->taxSubtotal((string) $type, $stay['taxable'][$type], $amount, $stay['percent'][$type] ?? null);
        }

        if ($stay['untaxed']->isPositive() || $subtotals === []) {
            $subtotals[] = $this->taxSubtotal('E', $stay['untaxed'], BigDecimal::zero());
        }

        return $subtotals;
    }

    /**
     * A tax subtotal; `$percent` is the rate of a percentage tax, carried on a line only (`cbc:Percent`).
     *
     * @return array<string, mixed>
     */
    private function taxSubtotal(string $type, BigDecimal $taxable, BigDecimal $tax, ?BigDecimal $percent = null): array
    {
        return [
            'TaxableAmount' => [['_' => LhdnAmount::of($taxable), 'currencyID' => self::DEFAULT_CURRENCY]],
            'TaxAmount' => [['_' => LhdnAmount::of($tax), 'currencyID' => self::DEFAULT_CURRENCY]],
            ...($percent === null ? [] : ['Percent' => [['_' => LhdnAmount::of($percent)]]]),
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
     * @param  list<array{folio: Folio, net: BigDecimal, tax: BigDecimal, taxes: array<string, BigDecimal>, taxable: array<string, BigDecimal>, percent: array<string, BigDecimal|null>, untaxed: BigDecimal}>  $stays
     * @return array<string, mixed>
     */
    private function monetaryTotal(array $stays): array
    {
        $net = BigDecimal::zero();
        $gross = BigDecimal::zero();
        foreach ($stays as $stay) {
            $net = $net->plus($stay['net']);
            $gross = $gross->plus($stay['net'])->plus($stay['tax']);
        }

        return [
            'LineExtensionAmount' => [['_' => LhdnAmount::of($net), 'currencyID' => self::DEFAULT_CURRENCY]],
            'TaxExclusiveAmount' => [['_' => LhdnAmount::of($net), 'currencyID' => self::DEFAULT_CURRENCY]],
            'TaxInclusiveAmount' => [['_' => LhdnAmount::of($gross), 'currencyID' => self::DEFAULT_CURRENCY]],
            'AllowanceTotalAmount' => [['_' => 0.0, 'currencyID' => self::DEFAULT_CURRENCY]],
            'ChargeTotalAmount' => [['_' => 0.0, 'currencyID' => self::DEFAULT_CURRENCY]],
            'PayableRoundingAmount' => [['_' => 0.0, 'currencyID' => self::DEFAULT_CURRENCY]],
            'PayableAmount' => [['_' => LhdnAmount::of($gross), 'currencyID' => self::DEFAULT_CURRENCY]],
        ];
    }

    /**
     * The stays that go in the summary, each split into charges and taxes.
     *
     * @param  Collection<int, Folio>  $folios
     * @return list<array{folio: Folio, net: BigDecimal, tax: BigDecimal, taxes: array<string, BigDecimal>, taxable: array<string, BigDecimal>, percent: array<string, BigDecimal|null>, untaxed: BigDecimal}>
     */
    private function stays(Collection $folios): array
    {
        $taxTypes = new LhdnTaxType;
        $stays = [];

        foreach ($folios as $folio) {
            if ($this->fitsInSummary($folio)) {
                $stays[] = ['folio' => $folio, ...$this->reportableLines->split($folio, $taxTypes)];
            }
        }

        return $stays;
    }
}
