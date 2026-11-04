<?php

namespace App\Services\EInvoice;

use App\Models\ArInvoice;
use App\Models\Property;
use App\Models\PropertyEInvoiceSetting;
use Carbon\CarbonImmutable;

class ArInvoiceDocumentBuilder
{
    public const string INVOICE_TYPE_CODE = '01';

    public const string ACCOMMODATION_CLASS_CODE = '022';

    public const string DEFAULT_CURRENCY = 'MYR';

    public const string ORIGIN_COUNTRY_CODE = 'MYS';

    public const string UNIT_CODE_EACH = 'C62';

    private Property $property;

    private ?PropertyEInvoiceSetting $setting;

    private DocumentSigner $documentSigner;

    public function __construct(
        private ArInvoice $invoice
    ) {
        $this->property = $invoice->property;
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
        $this->validateRequiredData();

        $payload = $this->buildPayload();
        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return [
            'format' => 'JSON',
            'document' => base64_encode($payloadJson),
            'documentHash' => hash('sha256', $payloadJson),
            'codeNumber' => $this->invoice->number,
            'payload' => $payload,
        ];
    }

    private function validateRequiredData(): void
    {
        $tin = $this->setting?->supplier_tin ?: $this->property->tin;
        if (empty($tin)) {
            throw new \InvalidArgumentException('Property TIN is missing. Configure E-Invoice Settings before submitting.');
        }

        $email = $this->setting?->supplier_email ?: $this->property->contact_email;
        if (empty($email)) {
            throw new \InvalidArgumentException('Property contact email is missing. Configure E-Invoice Settings before submitting.');
        }

        $city = $this->setting?->supplier_city ?: $this->property->city;
        if (empty($city)) {
            throw new \InvalidArgumentException('Property city is missing. Configure Property Address before submitting.');
        }

        $buyer = BuyerResolver::forCompany($this->invoice->company, $this->property);

        if (empty($buyer['tin'])) {
            throw new \InvalidArgumentException('Company TIN is required for B2B e-invoicing.');
        }

        if (empty($buyer['city'])) {
            throw new \InvalidArgumentException('Company city is required for LHDN e-invoicing.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function buildPayload(): array
    {
        $subtotal = abs((float) $this->invoice->amount);

        // LHDN reads IssueDate and IssueTime as UTC, so local time here would be 8 hours in the future.
        $issuedAt = now('UTC');

        $body = [
            'ID' => [['_' => $this->invoice->number]],
            'IssueDate' => [['_' => $issuedAt->toDateString()]],
            'IssueTime' => [['_' => $issuedAt->format('H:i:s').'Z']],
            'InvoiceTypeCode' => [['_' => self::INVOICE_TYPE_CODE, 'listVersionID' => $this->documentSigner->documentVersion()]],
            'DocumentCurrencyCode' => [['_' => self::DEFAULT_CURRENCY]],
            'TaxCurrencyCode' => [['_' => self::DEFAULT_CURRENCY]],
            'InvoicePeriod' => [
                [
                    'StartDate' => [['_' => $this->invoice->issued_on?->toDateString() ?: CarbonImmutable::now('Asia/Kuala_Lumpur')->toDateString()]],
                    'EndDate' => [['_' => $this->invoice->due_on?->toDateString() ?: CarbonImmutable::now('Asia/Kuala_Lumpur')->addMonth()->toDateString()]],
                    'Description' => [['_' => 'City Ledger Account Settlement']],
                ],
            ],
            'AccountingSupplierParty' => [['Party' => [$this->supplierParty()]]],
            'AccountingCustomerParty' => [['Party' => [$this->buyerParty()]]],
            'InvoiceLine' => [
                [
                    'ID' => [['_' => '1']],
                    'InvoicedQuantity' => [['_' => 1, 'unitCode' => self::UNIT_CODE_EACH]],
                    'LineExtensionAmount' => [['_' => $subtotal, 'currencyID' => self::DEFAULT_CURRENCY]],
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
                                    'TaxableAmount' => [['_' => $subtotal, 'currencyID' => self::DEFAULT_CURRENCY]],
                                    'TaxAmount' => [['_' => 0.0, 'currencyID' => self::DEFAULT_CURRENCY]],
                                    'TaxCategory' => [
                                        [
                                            'ID' => [['_' => 'E']],
                                            'TaxExemptionReason' => [['_' => 'Direct billing to company ledger']],
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
                                ['ItemClassificationCode' => [['_' => self::ACCOMMODATION_CLASS_CODE, 'listID' => 'CLASS']]],
                            ],
                            'Description' => [['_' => $this->invoice->reason ?: "City Ledger Invoice {$this->invoice->number}"]],
                            'OriginCountry' => [['IdentificationCode' => [['_' => self::ORIGIN_COUNTRY_CODE]]]],
                        ],
                    ],
                    'Price' => [['PriceAmount' => [['_' => $subtotal, 'currencyID' => self::DEFAULT_CURRENCY]]]],
                    'ItemPriceExtension' => [['Amount' => [['_' => $subtotal, 'currencyID' => self::DEFAULT_CURRENCY]]]],
                ],
            ],
            'TaxTotal' => [
                [
                    'TaxAmount' => [['_' => 0.0, 'currencyID' => self::DEFAULT_CURRENCY]],
                    'TaxSubtotal' => [
                        [
                            'TaxableAmount' => [['_' => $subtotal, 'currencyID' => self::DEFAULT_CURRENCY]],
                            'TaxAmount' => [['_' => 0.0, 'currencyID' => self::DEFAULT_CURRENCY]],
                            'TaxCategory' => [
                                [
                                    'ID' => [['_' => 'E']],
                                    'TaxExemptionReason' => [['_' => 'Direct billing to company ledger']],
                                    'TaxScheme' => [['ID' => [['_' => 'OTH', 'schemeID' => 'UN/ECE 5153', 'schemeAgencyID' => '6']]]],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'LegalMonetaryTotal' => [
                [
                    'LineExtensionAmount' => [['_' => $subtotal, 'currencyID' => self::DEFAULT_CURRENCY]],
                    'TaxExclusiveAmount' => [['_' => $subtotal, 'currencyID' => self::DEFAULT_CURRENCY]],
                    'TaxInclusiveAmount' => [['_' => $subtotal, 'currencyID' => self::DEFAULT_CURRENCY]],
                    'AllowanceTotalAmount' => [['_' => 0.0, 'currencyID' => self::DEFAULT_CURRENCY]],
                    'ChargeTotalAmount' => [['_' => 0.0, 'currencyID' => self::DEFAULT_CURRENCY]],
                    'PayableRoundingAmount' => [['_' => 0.0, 'currencyID' => self::DEFAULT_CURRENCY]],
                    'PayableAmount' => [['_' => $subtotal, 'currencyID' => self::DEFAULT_CURRENCY]],
                ],
            ],
        ];

        return $this->documentSigner->apply(array_merge(FolioDocumentBuilder::UBL_NAMESPACES, ['Invoice' => [$body]]));
    }

    /**
     * @return array<string, mixed>
     */
    private function supplierParty(): array
    {
        $tin = $this->setting?->supplier_tin ?: $this->property->tin;
        $brn = $this->property->registration_number ?: 'NA';
        $name = $this->setting?->supplier_name ?: $this->property->legal_name ?: $this->property->name;
        $msic = $this->setting?->supplier_msic_code ?: $this->property->msic_code ?: '55101';
        $desc = $this->setting?->supplier_business_description ?: $this->property->business_description ?: 'Hotel and accommodation services';
        $email = $this->setting?->supplier_email ?: $this->property->contact_email;
        $phone = PhoneFormatter::format($this->setting?->supplier_phone ?: $this->property->contact_phone);

        $addressLine1 = $this->setting?->supplier_address_line1 ?: $this->property->address_line1 ?: 'NA';
        $addressLine2 = $this->setting?->supplier_address_line2 ?: $this->property->address_line2 ?: '';
        $city = $this->setting?->supplier_city ?: $this->property->city;
        $postalCode = $this->setting?->supplier_postal_code ?: $this->property->postal_code ?: '00000';
        $stateCode = $this->setting?->supplier_state_code ?: $this->property->state_code ?: '14';

        $partyIdentifications = [
            ['ID' => [['_' => $tin, 'schemeID' => 'TIN']]],
            ['ID' => [['_' => preg_replace('/[^A-Za-z0-9]/', '', $brn) ?: 'NA', 'schemeID' => 'BRN']]],
        ];

        $sst = $this->setting?->supplier_sst_registration;
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
    private function buyerParty(): array
    {
        $buyer = BuyerResolver::forCompany($this->invoice->company, $this->property);

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
}
