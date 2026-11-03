<?php

namespace App\Services\EInvoice;

use App\Enums\TransactionKind;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Property;
use App\Models\PropertyEInvoiceSetting;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

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

    private Property $property;

    private ?PropertyEInvoiceSetting $setting;

    private DocumentSigner $documentSigner;

    /**
     * @param  array<string, mixed>|null  $buyerSnapshot
     */
    public function __construct(
        private Folio $folio,
        private ?array $buyerSnapshot = null
    ) {
        $this->property = $folio->property;
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
        $payload = $this->buildPayload();
        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return [
            'format' => 'JSON',
            'document' => base64_encode($payloadJson),
            'documentHash' => hash('sha256', $payloadJson),
            'codeNumber' => $this->internalId(),
            'payload' => $payload,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildPayload(): array
    {
        $body = [
            'ID' => [['_' => $this->internalId()]],
            'IssueDate' => [['_' => CarbonImmutable::now('Asia/Kuala_Lumpur')->toDateString()]],
            'IssueTime' => [['_' => CarbonImmutable::now('Asia/Kuala_Lumpur')->toTimeString().'Z']],
            'InvoiceTypeCode' => [['_' => self::INVOICE_TYPE_CODE, 'listVersionID' => $this->documentSigner->documentVersion()]],
            'DocumentCurrencyCode' => [['_' => self::DEFAULT_CURRENCY]],
            'TaxCurrencyCode' => [['_' => self::DEFAULT_CURRENCY]],
            'InvoicePeriod' => [$this->invoicePeriod()],
            'AccountingSupplierParty' => [['Party' => [$this->supplierParty()]]],
            'AccountingCustomerParty' => [['Party' => [$this->buyerParty()]]],
            'InvoiceLine' => $this->invoiceLines(),
            'TaxTotal' => [$this->taxTotalBlock()],
            'LegalMonetaryTotal' => [$this->monetaryTotal()],
        ];

        return $this->documentSigner->apply(array_merge(self::UBL_NAMESPACES, ['Invoice' => [$body]]));
    }

    public function internalId(): string
    {
        return $this->folio->invoice_number ?: $this->folio->number;
    }

    /**
     * @return array<string, mixed>
     */
    private function invoicePeriod(): array
    {
        $unit = $this->folio->reservationUnit ?: $this->folio->reservation?->units()->first();
        $startDate = $unit?->arrival_date?->toDateString() ?: CarbonImmutable::now('Asia/Kuala_Lumpur')->toDateString();
        $endDate = $unit?->departure_date?->toDateString() ?: CarbonImmutable::now('Asia/Kuala_Lumpur')->addDay()->toDateString();

        return [
            'StartDate' => [['_' => $startDate]],
            'EndDate' => [['_' => $endDate]],
            'Description' => [['_' => 'Accommodation Period']],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function supplierParty(): array
    {
        $tin = $this->setting?->supplier_tin ?: $this->property->tin ?: 'C0000000000';
        $brn = $this->property->registration_number ?: 'NA';
        $name = $this->setting?->supplier_name ?: $this->property->legal_name ?: $this->property->name;
        $msic = $this->setting?->supplier_msic_code ?: $this->property->msic_code ?: '55101';
        $desc = $this->setting?->supplier_business_description ?: $this->property->business_description ?: 'Hotel and accommodation services';
        $email = $this->setting?->supplier_email ?: $this->property->contact_email ?: 'accounts@hotel.com';
        $phone = PhoneFormatter::format($this->setting?->supplier_phone ?: $this->property->contact_phone);

        $addressLine1 = $this->setting?->supplier_address_line1 ?: $this->property->address_line1 ?: 'NA';
        $addressLine2 = $this->setting?->supplier_address_line2 ?: $this->property->address_line2 ?: '';
        $city = $this->setting?->supplier_city ?: $this->property->city ?: 'Kota Kinabalu';
        $postalCode = $this->setting?->supplier_postal_code ?: $this->property->postal_code ?: '88000';
        $stateCode = $this->setting?->supplier_state_code ?: $this->property->state_code ?: '12';

        $partyIdentifications = [
            ['ID' => [['_' => $tin, 'schemeID' => 'TIN']]],
            ['ID' => [['_' => preg_replace('/[^A-Za-z0-9]/', '', $brn) ?: 'NA', 'schemeID' => 'BRN']]],
        ];

        $sst = $this->setting?->supplier_sst_registration;
        if (! empty($sst)) {
            $partyIdentifications[] = ['ID' => [['_' => $sst, 'schemeID' => 'SST']]];
        }

        $party = [
            'IndustryClassificationCode' => [['_' => $msic, 'name' => $desc]],
            'PartyIdentification' => $partyIdentifications,
            'PostalAddress' => [
                [
                    'CityName' => [['_' => $city]],
                    'PostalZone' => [['_' => $postalCode]],
                    'CountrySubentityCode' => [['_' => $stateCode]],
                    'AddressLine' => [
                        ['Line' => [['_' => $addressLine1]]],
                        ['Line' => [['_' => $addressLine2]]],
                        ['Line' => [['_' => '']]],
                    ],
                    'Country' => [['IdentificationCode' => [['_' => 'MYS']]]],
                ],
            ],
            'PartyLegalEntity' => [['RegistrationName' => [['_' => $name]]]],
            'Contact' => [
                [
                    'Telephone' => [['_' => $phone]],
                    'ElectronicMail' => [['_' => $email]],
                ],
            ],
        ];

        return $party;
    }

    /**
     * @return array<string, mixed>
     */
    private function buyerParty(): array
    {
        $buyer = $this->buyerSnapshot ?? BuyerResolver::forFolio($this->folio);

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
    private function invoiceLines(): array
    {
        $lines = $this->activeChargeLines();
        $formattedLines = [];

        if ($lines->isEmpty()) {
            // Fallback accommodation line for 0-balance folios
            return [
                [
                    'ID' => [['_' => '1']],
                    'InvoicedQuantity' => [['_' => 1, 'unitCode' => self::UNIT_CODE_EACH]],
                    'LineExtensionAmount' => [['_' => 0.0, 'currencyID' => self::DEFAULT_CURRENCY]],
                    'AllowanceCharge' => [
                        [
                            'ChargeIndicator' => [['_' => false]],
                            'Amount' => [['_' => 0.0, 'currencyID' => self::DEFAULT_CURRENCY]],
                        ],
                    ],
                    'TaxTotal' => [
                        [
                            'TaxAmount' => [['_' => 0.0, 'currencyID' => self::DEFAULT_CURRENCY]],
                            'TaxSubtotal' => [$this->exemptTaxSubtotal(0.0)],
                        ],
                    ],
                    'Item' => [
                        [
                            'CommodityClassification' => [
                                ['ItemClassificationCode' => [['_' => self::ACCOMMODATION_CLASS_CODE, 'listID' => 'CLASS']]],
                            ],
                            'Description' => [['_' => 'Hotel Accommodation Services']],
                            'OriginCountry' => [['IdentificationCode' => [['_' => self::ORIGIN_COUNTRY_CODE]]]],
                        ],
                    ],
                    'Price' => [['PriceAmount' => [['_' => 0.0, 'currencyID' => self::DEFAULT_CURRENCY]]]],
                    'ItemPriceExtension' => [['Amount' => [['_' => 0.0, 'currencyID' => self::DEFAULT_CURRENCY]]]],
                ],
            ];
        }

        $idx = 1;
        foreach ($lines as $line) {
            $amount = (float) $line->amount;
            $qty = $line->quantity ?: 1;
            $unitPrice = (float) ($line->unit_amount ?? ($amount / $qty));

            $formattedLines[] = [
                'ID' => [['_' => (string) $idx++]],
                'InvoicedQuantity' => [['_' => $qty, 'unitCode' => self::UNIT_CODE_EACH]],
                'LineExtensionAmount' => [['_' => round($amount, 2), 'currencyID' => self::DEFAULT_CURRENCY]],
                'AllowanceCharge' => [
                    [
                        'ChargeIndicator' => [['_' => false]],
                        'Amount' => [['_' => 0.0, 'currencyID' => self::DEFAULT_CURRENCY]],
                    ],
                ],
                'TaxTotal' => [
                    [
                        'TaxAmount' => [['_' => 0.0, 'currencyID' => self::DEFAULT_CURRENCY]],
                        'TaxSubtotal' => [$this->exemptTaxSubtotal(round($amount, 2))],
                    ],
                ],
                'Item' => [
                    [
                        'CommodityClassification' => [
                            ['ItemClassificationCode' => [['_' => self::ACCOMMODATION_CLASS_CODE, 'listID' => 'CLASS']]],
                        ],
                        'Description' => [['_' => $line->description ?: 'Accommodation / Service']],
                        'OriginCountry' => [['IdentificationCode' => [['_' => self::ORIGIN_COUNTRY_CODE]]]],
                    ],
                ],
                'Price' => [['PriceAmount' => [['_' => round($unitPrice, 4), 'currencyID' => self::DEFAULT_CURRENCY]]]],
                'ItemPriceExtension' => [['Amount' => [['_' => round($amount, 2), 'currencyID' => self::DEFAULT_CURRENCY]]]],
            ];
        }

        return $formattedLines;
    }

    /**
     * @return array<string, mixed>
     */
    private function exemptTaxSubtotal(float $taxableAmount): array
    {
        return [
            'TaxableAmount' => [['_' => $taxableAmount, 'currencyID' => self::DEFAULT_CURRENCY]],
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
     * @return array<string, mixed>
     */
    private function taxTotalBlock(): array
    {
        $taxLines = $this->activeTaxLines();
        $subtotal = $this->chargesSubtotal();

        if ($taxLines->isEmpty()) {
            return [
                'TaxAmount' => [['_' => 0.0, 'currencyID' => self::DEFAULT_CURRENCY]],
                'TaxSubtotal' => [$this->exemptTaxSubtotal($subtotal)],
            ];
        }

        $totalTaxAmount = 0.0;
        $subtotals = [];

        foreach ($taxLines as $taxLine) {
            $taxAmount = (float) $taxLine->amount;
            $totalTaxAmount += $taxAmount;

            $taxCode = $this->resolveTaxCode($taxLine);

            $subtotals[] = [
                'TaxableAmount' => [['_' => $subtotal, 'currencyID' => self::DEFAULT_CURRENCY]],
                'TaxAmount' => [['_' => round($taxAmount, 2), 'currencyID' => self::DEFAULT_CURRENCY]],
                'TaxCategory' => [
                    [
                        'ID' => [['_' => $taxCode]],
                        'TaxExemptionReason' => [['_' => '']],
                        'TaxScheme' => [['ID' => [['_' => 'OTH', 'schemeID' => 'UN/ECE 5153', 'schemeAgencyID' => '6']]]],
                    ],
                ],
            ];
        }

        return [
            'TaxAmount' => [['_' => round($totalTaxAmount, 2), 'currencyID' => self::DEFAULT_CURRENCY]],
            'TaxSubtotal' => $subtotals,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function monetaryTotal(): array
    {
        $subtotal = $this->chargesSubtotal();
        $taxTotal = $this->taxesTotal();
        $grandTotal = round($subtotal + $taxTotal, 2);

        return [
            'LineExtensionAmount' => [['_' => $subtotal, 'currencyID' => self::DEFAULT_CURRENCY]],
            'TaxExclusiveAmount' => [['_' => $subtotal, 'currencyID' => self::DEFAULT_CURRENCY]],
            'TaxInclusiveAmount' => [['_' => $grandTotal, 'currencyID' => self::DEFAULT_CURRENCY]],
            'AllowanceTotalAmount' => [['_' => 0.0, 'currencyID' => self::DEFAULT_CURRENCY]],
            'ChargeTotalAmount' => [['_' => 0.0, 'currencyID' => self::DEFAULT_CURRENCY]],
            'PayableRoundingAmount' => [['_' => 0.0, 'currencyID' => self::DEFAULT_CURRENCY]],
            'PayableAmount' => [['_' => $grandTotal, 'currencyID' => self::DEFAULT_CURRENCY]],
        ];
    }

    private function resolveTaxCode(FolioLine $taxLine): string
    {
        $desc = strtolower($taxLine->description.' '.($taxLine->transactionCode?->name ?? ''));
        if (str_contains($desc, 'sst') || str_contains($desc, 'service tax')) {
            return '02';
        }
        if (str_contains($desc, 'ttx') || str_contains($desc, 'tourism')) {
            return '03';
        }

        return 'OTH';
    }

    /**
     * @return Collection<int, FolioLine>
     */
    private function activeChargeLines(): Collection
    {
        $reversedIds = $this->folio->lines()
            ->whereNotNull('reversal_of_id')
            ->pluck('reversal_of_id')
            ->filter();

        return $this->folio->lines()
            ->whereIn('kind', [TransactionKind::Charge, TransactionKind::Adjustment])
            ->whereNull('reversal_of_id')
            ->whereNotIn('id', $reversedIds)
            ->with('transactionCode')
            ->get();
    }

    /**
     * @return Collection<int, FolioLine>
     */
    private function activeTaxLines(): Collection
    {
        $reversedIds = $this->folio->lines()
            ->whereNotNull('reversal_of_id')
            ->pluck('reversal_of_id')
            ->filter();

        return $this->folio->lines()
            ->where('kind', TransactionKind::Tax)
            ->whereNull('reversal_of_id')
            ->whereNotIn('id', $reversedIds)
            ->with('transactionCode')
            ->get();
    }

    private function chargesSubtotal(): float
    {
        $total = BigDecimal::zero();
        foreach ($this->activeChargeLines() as $line) {
            $total = $total->plus($line->amount);
        }

        return (float) (string) $total->toScale(2);
    }

    private function taxesTotal(): float
    {
        $total = BigDecimal::zero();
        foreach ($this->activeTaxLines() as $line) {
            $total = $total->plus($line->amount);
        }

        return (float) (string) $total->toScale(2);
    }
}
