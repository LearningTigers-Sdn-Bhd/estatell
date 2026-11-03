<?php

namespace App\Services\EInvoice;

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
        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

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
        $body = [
            'ID' => [['_' => $internalId]],
            'IssueDate' => [['_' => CarbonImmutable::now('Asia/Kuala_Lumpur')->toDateString()]],
            'IssueTime' => [['_' => CarbonImmutable::now('Asia/Kuala_Lumpur')->toTimeString().'Z']],
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
        $tin = $this->setting->supplier_tin ?: $this->property->tin ?: 'C0000000000';
        $brn = $this->property->registration_number ?: 'NA';
        $name = $this->setting->supplier_name ?: $this->property->legal_name ?: $this->property->name;
        $msic = $this->setting->supplier_msic_code ?: $this->property->msic_code ?: '55101';
        $desc = $this->setting->supplier_business_description ?: $this->property->business_description ?: 'Hotel and accommodation services';
        $email = $this->setting->supplier_email ?: $this->property->contact_email ?: 'accounts@hotel.com';
        $phone = PhoneFormatter::format($this->setting->supplier_phone ?: $this->property->contact_phone);

        $addressLine1 = $this->setting->supplier_address_line1 ?: $this->property->address_line1 ?: 'NA';
        $addressLine2 = $this->setting->supplier_address_line2 ?: $this->property->address_line2 ?: '';
        $city = $this->setting->supplier_city ?: $this->property->city ?: 'Kota Kinabalu';
        $postalCode = $this->setting->supplier_postal_code ?: $this->property->postal_code ?: '88000';
        $stateCode = $this->setting->supplier_state_code ?: $this->property->state_code ?: '12';

        $partyIdentifications = [
            ['ID' => [['_' => $tin, 'schemeID' => 'TIN']]],
            ['ID' => [['_' => preg_replace('/[^A-Za-z0-9]/', '', $brn) ?: 'NA', 'schemeID' => 'BRN']]],
        ];

        $sst = $this->setting->supplier_sst_registration;
        if (! empty($sst)) {
            $partyIdentifications[] = ['ID' => [['_' => $sst, 'schemeID' => 'SST']]];
        }

        return [
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
                    'Telephone' => [['_' => PhoneFormatter::DEFAULT_PHONE]],
                    'ElectronicMail' => [['_' => 'na@hasil.gov.my']],
                ],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function invoiceLines(): array
    {
        $lines = [];
        $idx = 1;

        foreach ($this->folios as $folio) {
            $amount = (float) $this->folioGrossAmount($folio);
            if ($amount <= 0.0) {
                continue;
            }

            $folioNumber = $folio->invoice_number ?: $folio->number;

            $lines[] = [
                'ID' => [['_' => (string) $idx++]],
                'InvoicedQuantity' => [['_' => 1, 'unitCode' => self::UNIT_CODE_EACH]],
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
                        'TaxSubtotal' => [
                            [
                                'TaxableAmount' => [['_' => round($amount, 2), 'currencyID' => self::DEFAULT_CURRENCY]],
                                'TaxAmount' => [['_' => 0.0, 'currencyID' => self::DEFAULT_CURRENCY]],
                                'TaxCategory' => [
                                    [
                                        'ID' => [['_' => 'E']],
                                        'TaxExemptionReason' => [['_' => 'Consolidated line-level exemption']],
                                        'TaxScheme' => [['ID' => [['_' => 'OTH', 'schemeID' => 'UN/ECE 5153', 'schemeAgencyID' => '6']]]],
                                    ],
                                ],
                            ],
                        ],
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
                'Price' => [['PriceAmount' => [['_' => round($amount, 4), 'currencyID' => self::DEFAULT_CURRENCY]]]],
                'ItemPriceExtension' => [['Amount' => [['_' => round($amount, 2), 'currencyID' => self::DEFAULT_CURRENCY]]]],
            ];
        }

        return $lines;
    }

    /**
     * @return array<string, mixed>
     */
    private function taxTotalBlock(): array
    {
        $subtotal = $this->totalAmount();

        return [
            'TaxAmount' => [['_' => 0.0, 'currencyID' => self::DEFAULT_CURRENCY]],
            'TaxSubtotal' => [
                [
                    'TaxableAmount' => [['_' => $subtotal, 'currencyID' => self::DEFAULT_CURRENCY]],
                    'TaxAmount' => [['_' => 0.0, 'currencyID' => self::DEFAULT_CURRENCY]],
                    'TaxCategory' => [
                        [
                            'ID' => [['_' => 'E']],
                            'TaxExemptionReason' => [['_' => 'Consolidated document']],
                            'TaxScheme' => [['ID' => [['_' => 'OTH', 'schemeID' => 'UN/ECE 5153', 'schemeAgencyID' => '6']]]],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function monetaryTotal(): array
    {
        $total = $this->totalAmount();

        return [
            'LineExtensionAmount' => [['_' => $total, 'currencyID' => self::DEFAULT_CURRENCY]],
            'TaxExclusiveAmount' => [['_' => $total, 'currencyID' => self::DEFAULT_CURRENCY]],
            'TaxInclusiveAmount' => [['_' => $total, 'currencyID' => self::DEFAULT_CURRENCY]],
            'AllowanceTotalAmount' => [['_' => 0.0, 'currencyID' => self::DEFAULT_CURRENCY]],
            'ChargeTotalAmount' => [['_' => 0.0, 'currencyID' => self::DEFAULT_CURRENCY]],
            'PayableRoundingAmount' => [['_' => 0.0, 'currencyID' => self::DEFAULT_CURRENCY]],
            'PayableAmount' => [['_' => $total, 'currencyID' => self::DEFAULT_CURRENCY]],
        ];
    }

    private function folioGrossAmount(Folio $folio): float
    {
        $sum = BigDecimal::zero();
        foreach ($folio->lines as $line) {
            if ($line->reversal_of_id === null && $line->reversal === null) {
                $sum = $sum->plus($line->amount);
            }
        }

        return (float) (string) $sum->toScale(2);
    }

    private function totalAmount(): float
    {
        $sum = BigDecimal::zero();
        foreach ($this->folios as $folio) {
            $amt = $this->folioGrossAmount($folio);
            if ($amt > 0.0 && $amt <= self::MAX_INDIVIDUAL_STAY_AMOUNT) {
                $sum = $sum->plus($amt);
            }
        }

        return (float) (string) $sum->toScale(2);
    }
}
