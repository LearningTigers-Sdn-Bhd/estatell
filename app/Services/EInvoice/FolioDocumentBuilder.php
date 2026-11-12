<?php

namespace App\Services\EInvoice;

use App\Actions\EInvoice\EInvoiceResult;
use App\Models\Folio;
use App\Models\FolioLine;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Builds the LHDN e-invoice (`01`) of one closed folio: one line per standing charge with its own taxes, the tax
 * totals by LHDN tax type, and the buyer from the paying guest or company (or what staff entered). Every amount is
 * summed exactly (`ReportableLines`) and becomes a JSON number only at the end (`LhdnAmount`). It reads only.
 */
class FolioDocumentBuilder
{
    public const string INVOICE_TYPE_CODE = '01';

    public const string ACCOMMODATION_CLASS_CODE = '022';

    public const string DEFAULT_CURRENCY = 'MYR';

    public const string ORIGIN_COUNTRY_CODE = 'MYS';

    public const string UNIT_CODE_EACH = 'C62';

    public const array UBL_NAMESPACES = [
        '_D' => 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2',
        '_A' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2',
        '_B' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
    ];

    public function __construct(
        private ReportableLines $reportableLines,
        private SupplierDetails $supplierDetails,
        private DocumentEncoder $documentEncoder,
        private StaysNeedingOwnEInvoice $staysNeedingOwnEInvoice,
    ) {}

    /**
     * @param  array<string, mixed>|null  $buyerSnapshot  What staff entered in the Issue e-invoice form; null reads the folio's payer.
     * @param  string|null  $internalId  The document's own number; null takes the folio's invoice number.
     * @return array{format: string, document: string, documentHash: string, codeNumber: string, payload: array<string, mixed>, buyer: array<string, mixed>}
     *
     * @throws MissingEInvoiceDetails when the hotel or the buyer lacks a detail LHDN needs.
     */
    public function build(Folio $folio, ?array $buyerSnapshot = null, ?string $internalId = null): array
    {
        $property = $folio->property;
        $buyer = $this->buyer($folio, $buyerSnapshot);

        MissingEInvoiceDetails::hotel($property);
        $this->checkBuyer($folio, $buyer);

        $signer = new DocumentSigner($property->eInvoiceSetting);
        $internalId ??= $this->internalId($folio);
        $taxTypes = new LhdnTaxType;

        // LHDN reads IssueDate and IssueTime as UTC, so local time here would be 8 hours in the future.
        $issuedAt = now('UTC');

        $body = [
            'ID' => [['_' => $internalId]],
            'IssueDate' => [['_' => $issuedAt->toDateString()]],
            'IssueTime' => [['_' => $issuedAt->format('H:i:s').'Z']],
            'InvoiceTypeCode' => [['_' => self::INVOICE_TYPE_CODE, 'listVersionID' => $signer->documentVersion()]],
            'DocumentCurrencyCode' => [['_' => self::DEFAULT_CURRENCY]],
            'TaxCurrencyCode' => [['_' => self::DEFAULT_CURRENCY]],
            'InvoicePeriod' => [$this->invoicePeriod($folio)],
            'AccountingSupplierParty' => [['Party' => [$this->supplierDetails->party($property)]]],
            'AccountingCustomerParty' => [['Party' => [$this->buyerParty($buyer)]]],
            'InvoiceLine' => $this->invoiceLines($folio, $taxTypes),
            'TaxTotal' => [$this->taxTotalBlock($folio, $taxTypes)],
            'LegalMonetaryTotal' => [$this->monetaryTotal($folio)],
        ];

        $payload = $signer->apply(array_merge(self::UBL_NAMESPACES, ['Invoice' => [$body]]));

        return [...$this->documentEncoder->encode($payload, $internalId), 'buyer' => $buyer];
    }

    public function internalId(Folio $folio): string
    {
        return $folio->invoice_number ?: $folio->number;
    }

    /**
     * @param  array<string, mixed>  $buyer
     */
    private function checkBuyer(Folio $folio, array $buyer): void
    {
        $isCompany = strtoupper((string) ($buyer['id_type'] ?? '')) === 'BRN';
        $hasId = ! in_array(strtoupper((string) ($buyer['id_value'] ?? '')), ['', 'NA'], true);
        $isDomestic = ($buyer['country_code'] ?? 'MYS') === 'MYS';

        if (empty($buyer['city'])) {
            throw new MissingEInvoiceDetails(EInvoiceResult::REFUSAL_BUYER_DETAILS_INCOMPLETE, $isCompany
                ? __("Add the company's billing city on the company record, then send again.")
                : __("Enter the guest's city."));
        }

        if ($isDomestic && empty($buyer['tin'])) {
            throw new MissingEInvoiceDetails(EInvoiceResult::REFUSAL_BUYER_TIN_REQUIRED, match (true) {
                $isCompany => __("Add the company's tax number (TIN) on the company record, then send again."),
                $this->staysNeedingOwnEInvoice->isOverLimit($folio) => __("This stay is over RM10,000, so LHDN needs its own e-invoice with the guest's own tax number. Enter the IC and press Check with LHDN to find it."),
                default => __("Enter the guest's tax number (TIN). A guest who has none does not need their own e-invoice: the stay goes into the monthly summary."),
            });
        }

        if ($isDomestic && ! $hasId) {
            throw new MissingEInvoiceDetails(EInvoiceResult::REFUSAL_BUYER_DETAILS_INCOMPLETE, $isCompany
                ? __("Add the company's registration number on the company record, then send again. LHDN checks it against the tax number.")
                : __("Enter the guest's IC number. LHDN checks it against the tax number."));
        }

        if (! $isDomestic && ! $hasId) {
            throw new MissingEInvoiceDetails(EInvoiceResult::REFUSAL_PASSPORT_REQUIRED, __("Enter the guest's passport number."));
        }
    }

    /**
     * The buyer as LHDN gets it: what staff entered, else the folio's payer, with a three-letter country and LHDN's
     * general foreign number for a foreign buyer with none.
     *
     * @param  array<string, mixed>|null  $buyerSnapshot
     * @return array<string, mixed>
     */
    private function buyer(Folio $folio, ?array $buyerSnapshot): array
    {
        $optionalFields = ['tin' => '', 'sst_registration' => null, 'email' => '', 'phone' => '', 'address_line1' => 'NA', 'address_line2' => '', 'postal_code' => '', 'state_code' => ''];
        $buyer = array_merge($optionalFields, array_filter($buyerSnapshot ?? BuyerResolver::forFolio($folio), fn ($value): bool => $value !== null));
        $countryCode = BuyerResolver::lhdnCountryCode($buyer['country_code'] ?? null);

        $tin = trim((string) ($buyer['tin'] ?? ''));
        if ($tin === '' && $countryCode !== 'MYS') {
            $tin = BuyerResolver::FOREIGN_BUYER_TIN;
        }

        $buyer['country_code'] = $countryCode;
        $buyer['tin'] = $tin;

        return $buyer;
    }

    /**
     * @return array<string, mixed>
     */
    private function invoicePeriod(Folio $folio): array
    {
        $unit = $folio->reservationUnit ?: $folio->reservation?->units()->first();
        $startDate = $unit?->arrival_date?->toDateString() ?: CarbonImmutable::now('Asia/Kuala_Lumpur')->toDateString();
        $endDate = $unit?->departure_date?->toDateString() ?: CarbonImmutable::now('Asia/Kuala_Lumpur')->addDay()->toDateString();

        return [
            'StartDate' => [['_' => $startDate]],
            'EndDate' => [['_' => $endDate]],
            'Description' => [['_' => 'Accommodation Period']],
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
            ['ID' => [['_' => $buyer['id_value'], 'schemeID' => $buyer['id_type']]]],
        ];

        if (! empty($buyer['sst_registration'])) {
            $partyIdentifications[] = ['ID' => [['_' => $buyer['sst_registration'], 'schemeID' => 'SST']]];
        }

        return [
            'PartyIdentification' => $partyIdentifications,
            'PostalAddress' => [
                [
                    'CityName' => [['_' => $buyer['city']]],
                    'PostalZone' => [['_' => $buyer['postal_code']]],
                    'CountrySubentityCode' => [['_' => $buyer['state_code']]],
                    'AddressLine' => [
                        ['Line' => [['_' => $buyer['address_line1']]]],
                        ['Line' => [['_' => $buyer['address_line2']]]],
                        ['Line' => [['_' => '']]],
                    ],
                    'Country' => [['IdentificationCode' => [['_' => $buyer['country_code']]]]],
                ],
            ],
            'PartyLegalEntity' => [['RegistrationName' => [['_' => $buyer['name']]]]],
            'Contact' => [
                [
                    'Telephone' => [['_' => $buyer['phone']]],
                    'ElectronicMail' => [['_' => $buyer['email']]],
                ],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function invoiceLines(Folio $folio, LhdnTaxType $taxTypes): array
    {
        $charges = $this->reportableLines->charges($folio);

        if ($charges->isEmpty()) {
            // A folio with nothing standing still needs one line.
            return [$this->line('1', 1, BigDecimal::zero(), BigDecimal::zero(), BigDecimal::zero(), [$this->exemptTaxSubtotal(BigDecimal::zero())], 'Hotel Accommodation Services')];
        }

        $taxes = $this->reportableLines->taxes($folio);
        $lines = [];

        foreach ($charges->values() as $index => $charge) {
            $amount = BigDecimal::of($charge->amount);
            $quantity = $charge->quantity ?: 1;
            $unitPrice = BigDecimal::of($charge->unit_amount);
            $childTaxes = $taxes->where('parent_line_id', $charge->id);

            $subtotals = $childTaxes->isEmpty()
                ? [$this->exemptTaxSubtotal($amount)]
                : array_values($childTaxes->map(fn (FolioLine $tax): array => $this->taxSubtotal($amount, BigDecimal::of($tax->amount), $taxTypes->for($tax), $taxTypes->percent($tax)))->all());

            $lines[] = $this->line((string) ($index + 1), $quantity, $amount, $unitPrice, $this->reportableLines->sum($childTaxes), $subtotals, $charge->description ?: 'Accommodation / Service');
        }

        return $lines;
    }

    /**
     * @param  list<array<string, mixed>>  $taxSubtotals
     * @return array<string, mixed>
     */
    private function line(string $id, int|float|string $quantity, BigDecimal $amount, BigDecimal $unitPrice, BigDecimal $taxAmount, array $taxSubtotals, string $description): array
    {
        return [
            'ID' => [['_' => $id]],
            'InvoicedQuantity' => [['_' => $quantity, 'unitCode' => self::UNIT_CODE_EACH]],
            'LineExtensionAmount' => [['_' => LhdnAmount::of($amount), 'currencyID' => self::DEFAULT_CURRENCY]],
            'AllowanceCharge' => [
                [
                    'ChargeIndicator' => [['_' => false]],
                    'Amount' => [['_' => 0.0, 'currencyID' => self::DEFAULT_CURRENCY]],
                ],
            ],
            'TaxTotal' => [
                [
                    'TaxAmount' => [['_' => LhdnAmount::of($taxAmount), 'currencyID' => self::DEFAULT_CURRENCY]],
                    'TaxSubtotal' => $taxSubtotals,
                ],
            ],
            'Item' => [
                [
                    'CommodityClassification' => [
                        ['ItemClassificationCode' => [['_' => self::ACCOMMODATION_CLASS_CODE, 'listID' => 'CLASS']]],
                    ],
                    'Description' => [['_' => $description]],
                    'OriginCountry' => [['IdentificationCode' => [['_' => self::ORIGIN_COUNTRY_CODE]]]],
                ],
            ],
            'Price' => [['PriceAmount' => [['_' => LhdnAmount::of($unitPrice, 4), 'currencyID' => self::DEFAULT_CURRENCY]]]],
            'ItemPriceExtension' => [['Amount' => [['_' => LhdnAmount::of($amount), 'currencyID' => self::DEFAULT_CURRENCY]]]],
        ];
    }

    /**
     * A tax subtotal. `$percent` is the rate of a percentage tax, which LHDN takes on a line (`cbc:Percent`) and a
     * Service Tax invoice must show (Service Tax Regulations 2018); it is left out for a flat tax and on the document
     * totals.
     *
     * @return array<string, mixed>
     */
    private function taxSubtotal(BigDecimal $taxable, BigDecimal $tax, string $taxType, ?BigDecimal $percent = null): array
    {
        return [
            'TaxableAmount' => [['_' => LhdnAmount::of($taxable), 'currencyID' => self::DEFAULT_CURRENCY]],
            'TaxAmount' => [['_' => LhdnAmount::of($tax), 'currencyID' => self::DEFAULT_CURRENCY]],
            ...($percent === null ? [] : ['Percent' => [['_' => LhdnAmount::of($percent)]]]),
            'TaxCategory' => [
                [
                    'ID' => [['_' => $taxType]],
                    'TaxExemptionReason' => [['_' => '']],
                    'TaxScheme' => [['ID' => [['_' => 'OTH', 'schemeID' => 'UN/ECE 5153', 'schemeAgencyID' => '6']]]],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function exemptTaxSubtotal(BigDecimal $taxable): array
    {
        return [
            'TaxableAmount' => [['_' => LhdnAmount::of($taxable), 'currencyID' => self::DEFAULT_CURRENCY]],
            'TaxAmount' => [['_' => 0.0, 'currencyID' => self::DEFAULT_CURRENCY]],
            'TaxCategory' => [
                [
                    'ID' => [['_' => 'E']],
                    'TaxExemptionReason' => [['_' => 'Not subject to tax at line level']],
                    'TaxScheme' => [['ID' => [['_' => 'OTH', 'schemeID' => 'UN/ECE 5153', 'schemeAgencyID' => '6']]]],
                ],
            ],
        ];
    }

    /**
     * The tax totals by LHDN tax type. Each type's taxable amount is the charges its tax lines sit on; a tax with no
     * charge under it is taken on all the charges.
     *
     * @return array<string, mixed>
     */
    private function taxTotalBlock(Folio $folio, LhdnTaxType $taxTypes): array
    {
        $taxes = $this->reportableLines->taxes($folio);
        $charges = $this->reportableLines->charges($folio);
        $subtotal = $this->reportableLines->sum($charges);

        if ($taxes->isEmpty()) {
            return [
                'TaxAmount' => [['_' => 0.0, 'currencyID' => self::DEFAULT_CURRENCY]],
                'TaxSubtotal' => [$this->exemptTaxSubtotal($subtotal)],
            ];
        }

        $subtotals = [];
        /** @var Collection<string, Collection<int, FolioLine>> $byType */
        $byType = $taxes->groupBy(fn (FolioLine $line): string => $taxTypes->for($line));

        foreach ($byType as $taxType => $lines) {
            $parentIds = $lines->pluck('parent_line_id')->filter();
            $taxable = $parentIds->isNotEmpty() ? $this->reportableLines->sum($charges->whereIn('id', $parentIds)) : $subtotal;

            $subtotals[] = $this->taxSubtotal($taxable->isZero() ? $subtotal : $taxable, $this->reportableLines->sum($lines), (string) $taxType);
        }

        return [
            'TaxAmount' => [['_' => LhdnAmount::of($this->reportableLines->sum($taxes)), 'currencyID' => self::DEFAULT_CURRENCY]],
            'TaxSubtotal' => $subtotals,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function monetaryTotal(Folio $folio): array
    {
        $subtotal = $this->reportableLines->sum($this->reportableLines->charges($folio));
        $total = $subtotal->plus($this->reportableLines->sum($this->reportableLines->taxes($folio)));

        return [
            'LineExtensionAmount' => [['_' => LhdnAmount::of($subtotal), 'currencyID' => self::DEFAULT_CURRENCY]],
            'TaxExclusiveAmount' => [['_' => LhdnAmount::of($subtotal), 'currencyID' => self::DEFAULT_CURRENCY]],
            'TaxInclusiveAmount' => [['_' => LhdnAmount::of($total), 'currencyID' => self::DEFAULT_CURRENCY]],
            'AllowanceTotalAmount' => [['_' => 0.0, 'currencyID' => self::DEFAULT_CURRENCY]],
            'ChargeTotalAmount' => [['_' => 0.0, 'currencyID' => self::DEFAULT_CURRENCY]],
            'PayableRoundingAmount' => [['_' => 0.0, 'currencyID' => self::DEFAULT_CURRENCY]],
            'PayableAmount' => [['_' => LhdnAmount::of($total), 'currencyID' => self::DEFAULT_CURRENCY]],
        ];
    }
}
