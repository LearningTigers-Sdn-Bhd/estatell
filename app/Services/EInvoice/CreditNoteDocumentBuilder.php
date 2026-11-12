<?php

namespace App\Services\EInvoice;

use App\Actions\EInvoice\EInvoiceResult;
use App\Enums\EInvoiceDocumentType;
use App\Models\EInvoiceSubmission;
use App\Models\Property;
use Brick\Math\BigDecimal;
use RuntimeException;

/**
 * Builds a credit note (`02`, the amount is lowered) or a refund note (`04`, money paid back to the buyer) against an
 * e-invoice LHDN accepted. Both take the original buyer and reference the original UUID.
 */
class CreditNoteDocumentBuilder
{
    public const string INVOICE_TYPE_CODE = '02';

    public const string ACCOMMODATION_CLASS_CODE = '022';

    public const string DEFAULT_CURRENCY = 'MYR';

    public const string ORIGIN_COUNTRY_CODE = 'MYS';

    public const string UNIT_CODE_EACH = 'C62';

    public function __construct(
        private SupplierDetails $supplierDetails,
        private DocumentEncoder $documentEncoder,
    ) {}

    /**
     * A credit or refund note on an e-invoice LHDN accepted, to the original buyer, with the amount split as
     * `CreditNoteSplit` splits it: one line for each SST rate with its rate and tax, one for untaxed charges, and the
     * levies when the whole remaining amount is credited.
     *
     * @param  array{sst: array<string, array{net: BigDecimal, tax: BigDecimal, percent: BigDecimal}>, untaxed: BigDecimal, levies: array<string, BigDecimal>}  $split
     * @return array{format: string, document: string, documentHash: string, codeNumber: string, payload: array<string, mixed>}
     *
     * @throws MissingEInvoiceDetails when the hotel or the original buyer lacks a detail LHDN needs.
     */
    public function build(EInvoiceSubmission $originalSubmission, string $creditNoteNumber, array $split, string $reason, EInvoiceDocumentType $type = EInvoiceDocumentType::CreditNote): array
    {
        if (empty($originalSubmission->uuid)) {
            throw new RuntimeException('Cannot issue e-invoice Credit Note: original submission does not have an LHDN UUID.');
        }

        $property = $originalSubmission->property;
        MissingEInvoiceDetails::hotel($property);
        $buyer = $this->buyer($originalSubmission, $property);
        $this->checkBuyer($buyer);

        return $this->documentEncoder->encode($this->payload($originalSubmission, $property, $buyer, $creditNoteNumber, $split, $reason, $type), $creditNoteNumber);
    }

    /**
     * @return array<string, mixed>
     */
    private function buyer(EInvoiceSubmission $originalSubmission, Property $property): array
    {
        // A monthly summary's buyer is always the general public, whatever its stored snapshot holds.
        if ($originalSubmission->is_consolidated) {
            return ConsolidatedBatchBuilder::generalPublicBuyer();
        }

        if (! empty($originalSubmission->buyer_snapshot)) {
            return $originalSubmission->buyer_snapshot;
        }

        if ($originalSubmission->folio !== null) {
            return BuyerResolver::forFolio($originalSubmission->folio);
        }

        if ($originalSubmission->arInvoice !== null) {
            return BuyerResolver::forCompany($originalSubmission->arInvoice->company, $property);
        }

        throw new MissingEInvoiceDetails(EInvoiceResult::REFUSAL_BUYER_DETAILS_INCOMPLETE, __('The original e-invoice has no buyer details, so no credit note can be made for it.'));
    }

    /**
     * @param  array<string, mixed>  $buyer
     */
    private function checkBuyer(array $buyer): void
    {
        if (empty($buyer['tin'])) {
            throw new MissingEInvoiceDetails(EInvoiceResult::REFUSAL_BUYER_TIN_REQUIRED, __('The original e-invoice has no buyer tax number, so no credit note can be made for it.'));
        }

        if (empty($buyer['city'])) {
            throw new MissingEInvoiceDetails(EInvoiceResult::REFUSAL_BUYER_DETAILS_INCOMPLETE, __('The original e-invoice has no buyer city, so no credit note can be made for it.'));
        }
    }

    /**
     * @param  array<string, mixed>  $buyer
     * @param  array{sst: array<string, array{net: BigDecimal, tax: BigDecimal, percent: BigDecimal}>, untaxed: BigDecimal, levies: array<string, BigDecimal>}  $split
     * @return array<string, mixed>
     */
    private function payload(EInvoiceSubmission $originalSubmission, Property $property, array $buyer, string $creditNoteNumber, array $split, string $reason, EInvoiceDocumentType $type): array
    {
        $signer = new DocumentSigner($property->eInvoiceSetting);
        $description = "Adjustment for {$originalSubmission->internal_id}: {$reason}";
        // A note on a monthly summary keeps its general public buyer, which LHDN allows only with the consolidated
        // classification `004` (ERR236, CV317, seen on preprod).
        $classification = $originalSubmission->is_consolidated ? ConsolidatedBatchBuilder::CONSOLIDATED_CLASS_CODE : self::ACCOMMODATION_CLASS_CODE;
        $lines = $this->lines($split, $description, $classification);

        // LHDN reads IssueDate and IssueTime as UTC, so local time here would be 8 hours in the future.
        $issuedAt = now('UTC');

        $body = [
            'ID' => [['_' => $creditNoteNumber]],
            'IssueDate' => [['_' => $issuedAt->toDateString()]],
            'IssueTime' => [['_' => $issuedAt->format('H:i:s').'Z']],
            'InvoiceTypeCode' => [['_' => $type->value, 'listVersionID' => $signer->documentVersion()]],
            'DocumentCurrencyCode' => [['_' => self::DEFAULT_CURRENCY]],
            'TaxCurrencyCode' => [['_' => self::DEFAULT_CURRENCY]],
            'BillingReference' => [
                [
                    'InvoiceDocumentReference' => [
                        [
                            'ID' => [['_' => $originalSubmission->internal_id]],
                            'UUID' => [['_' => $originalSubmission->uuid]],
                        ],
                    ],
                ],
            ],
            'AccountingSupplierParty' => [['Party' => [$this->supplierDetails->party($property)]]],
            'AccountingCustomerParty' => [['Party' => [$this->buyerParty($buyer)]]],
            'InvoiceLine' => array_map(fn (array $line): array => $line['document'], $lines),
            'TaxTotal' => [$this->taxTotal($lines)],
            'LegalMonetaryTotal' => [$this->monetaryTotal($lines)],
        ];

        return $signer->apply(array_merge(FolioDocumentBuilder::UBL_NAMESPACES, ['Invoice' => [$body]]));
    }

    /**
     * The note's lines, each with its amounts kept for the totals.
     *
     * @param  array{sst: array<string, array{net: BigDecimal, tax: BigDecimal, percent: BigDecimal}>, untaxed: BigDecimal, levies: array<string, BigDecimal>}  $split
     * @return list<array{net: BigDecimal, subtotals: list<array{type: string, taxable: BigDecimal, tax: BigDecimal}>, document: array<string, mixed>}>
     */
    private function lines(array $split, string $description, string $classification): array
    {
        $parts = [];
        foreach ($split['sst'] as $part) {
            if ($part['net']->isPositive() || $part['tax']->isPositive()) {
                $parts[] = ['net' => $part['net'], 'subtotals' => [['type' => LhdnTaxType::SERVICE_TAX, 'taxable' => $part['net'], 'tax' => $part['tax'], 'percent' => $part['percent']]]];
            }
        }
        if ($split['untaxed']->isPositive() || $parts === []) {
            $parts[] = ['net' => $split['untaxed'], 'subtotals' => [['type' => 'E', 'taxable' => $split['untaxed'], 'tax' => BigDecimal::zero(), 'percent' => null]]];
        }

        // Levies come off with the whole remaining amount; they ride on the first line, as on the e-invoice.
        foreach ($split['levies'] as $type => $amount) {
            $parts[0]['subtotals'][] = ['type' => (string) $type, 'taxable' => $parts[0]['net'], 'tax' => $amount, 'percent' => null];
        }

        $lines = [];
        foreach ($parts as $index => $part) {
            $tax = array_reduce($part['subtotals'], fn (BigDecimal $sum, array $subtotal): BigDecimal => $sum->plus($subtotal['tax']), BigDecimal::zero());
            $rate = $part['subtotals'][0]['percent'];
            $net = LhdnAmount::of($part['net']);

            $lines[] = [
                'net' => $part['net'],
                'subtotals' => array_map(fn (array $subtotal): array => ['type' => $subtotal['type'], 'taxable' => $subtotal['taxable'], 'tax' => $subtotal['tax']], $part['subtotals']),
                'document' => [
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
                            'TaxAmount' => [['_' => LhdnAmount::of($tax), 'currencyID' => self::DEFAULT_CURRENCY]],
                            'TaxSubtotal' => array_map(fn (array $subtotal): array => $this->taxSubtotal($subtotal['type'], $subtotal['taxable'], $subtotal['tax'], $subtotal['percent']), $part['subtotals']),
                        ],
                    ],
                    'Item' => [
                        [
                            'CommodityClassification' => [
                                ['ItemClassificationCode' => [['_' => $classification, 'listID' => 'CLASS']]],
                            ],
                            'Description' => [['_' => $rate === null ? $description : "{$description} (SST {$rate}%)"]],
                            'OriginCountry' => [['IdentificationCode' => [['_' => self::ORIGIN_COUNTRY_CODE]]]],
                        ],
                    ],
                    'Price' => [['PriceAmount' => [['_' => $net, 'currencyID' => self::DEFAULT_CURRENCY]]]],
                    'ItemPriceExtension' => [['Amount' => [['_' => $net, 'currencyID' => self::DEFAULT_CURRENCY]]]],
                ],
            ];
        }

        return $lines;
    }

    /**
     * The note's tax by type over its lines.
     *
     * @param  list<array{net: BigDecimal, subtotals: list<array{type: string, taxable: BigDecimal, tax: BigDecimal}>, document: array<string, mixed>}>  $lines
     * @return array<string, mixed>
     */
    private function taxTotal(array $lines): array
    {
        $byType = [];
        $total = BigDecimal::zero();
        foreach ($lines as $line) {
            foreach ($line['subtotals'] as $subtotal) {
                $byType[$subtotal['type']] ??= ['taxable' => BigDecimal::zero(), 'tax' => BigDecimal::zero()];
                $byType[$subtotal['type']]['taxable'] = $byType[$subtotal['type']]['taxable']->plus($subtotal['taxable']);
                $byType[$subtotal['type']]['tax'] = $byType[$subtotal['type']]['tax']->plus($subtotal['tax']);
                $total = $total->plus($subtotal['tax']);
            }
        }

        $subtotals = [];
        foreach ($byType as $type => $sums) {
            $subtotals[] = $this->taxSubtotal((string) $type, $sums['taxable'], $sums['tax']);
        }

        return [
            'TaxAmount' => [['_' => LhdnAmount::of($total), 'currencyID' => self::DEFAULT_CURRENCY]],
            'TaxSubtotal' => $subtotals,
        ];
    }

    /**
     * @param  list<array{net: BigDecimal, subtotals: list<array{type: string, taxable: BigDecimal, tax: BigDecimal}>, document: array<string, mixed>}>  $lines
     * @return array<string, mixed>
     */
    private function monetaryTotal(array $lines): array
    {
        $net = BigDecimal::zero();
        $total = BigDecimal::zero();
        foreach ($lines as $line) {
            $net = $net->plus($line['net']);
            $total = $total->plus($line['net']);
            foreach ($line['subtotals'] as $subtotal) {
                $total = $total->plus($subtotal['tax']);
            }
        }

        return [
            'LineExtensionAmount' => [['_' => LhdnAmount::of($net), 'currencyID' => self::DEFAULT_CURRENCY]],
            'TaxExclusiveAmount' => [['_' => LhdnAmount::of($net), 'currencyID' => self::DEFAULT_CURRENCY]],
            'TaxInclusiveAmount' => [['_' => LhdnAmount::of($total), 'currencyID' => self::DEFAULT_CURRENCY]],
            'AllowanceTotalAmount' => [['_' => 0.0, 'currencyID' => self::DEFAULT_CURRENCY]],
            'ChargeTotalAmount' => [['_' => 0.0, 'currencyID' => self::DEFAULT_CURRENCY]],
            'PayableRoundingAmount' => [['_' => 0.0, 'currencyID' => self::DEFAULT_CURRENCY]],
            'PayableAmount' => [['_' => LhdnAmount::of($total), 'currencyID' => self::DEFAULT_CURRENCY]],
        ];
    }

    /**
     * A tax subtotal; `$percent` is the SST rate, which a credit note must show (Service Tax Regulations 2018).
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
                    'TaxExemptionReason' => [['_' => $type === 'E' ? 'Credit Note Adjustment' : '']],
                    'TaxScheme' => [['ID' => [['_' => 'OTH', 'schemeID' => 'UN/ECE 5153', 'schemeAgencyID' => '6']]]],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $buyer
     * @return array<string, mixed>
     */
    private function buyerParty(array $buyer): array
    {
        $partyIdentifications = [
            ['ID' => [['_' => $buyer['tin'], 'schemeID' => 'TIN']]],
            ['ID' => [['_' => $buyer['id_value'] ?? 'NA', 'schemeID' => $buyer['id_type'] ?? 'BRN']]],
        ];

        if (! empty($buyer['sst_registration'])) {
            $partyIdentifications[] = ['ID' => [['_' => $buyer['sst_registration'], 'schemeID' => 'SST']]];
        }

        return [
            'PartyIdentification' => $partyIdentifications,
            'PostalAddress' => [
                [
                    'CityName' => [['_' => $buyer['city']]],
                    'PostalZone' => [['_' => $buyer['postal_code'] ?? '00000']],
                    'CountrySubentityCode' => [['_' => $buyer['state_code'] ?? '14']],
                    'AddressLine' => [
                        ['Line' => [['_' => $buyer['address_line1'] ?? 'NA']]],
                        ['Line' => [['_' => $buyer['address_line2'] ?? '']]],
                        ['Line' => [['_' => '']]],
                    ],
                    'Country' => [['IdentificationCode' => [['_' => BuyerResolver::lhdnCountryCode($buyer['country_code'] ?? null)]]]],
                ],
            ],
            'PartyLegalEntity' => [['RegistrationName' => [['_' => $buyer['name'] ?? 'Buyer']]]],
            'Contact' => [
                [
                    'Telephone' => [['_' => $buyer['phone'] ?? PhoneFormatter::DEFAULT_PHONE]],
                    'ElectronicMail' => [['_' => $buyer['email'] ?? '']],
                ],
            ],
        ];
    }
}
