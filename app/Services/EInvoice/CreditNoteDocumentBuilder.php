<?php

namespace App\Services\EInvoice;

use App\Models\EInvoiceSubmission;
use App\Models\Property;
use App\Models\PropertyEInvoiceSetting;
use RuntimeException;

class CreditNoteDocumentBuilder
{
    public const string INVOICE_TYPE_CODE = '02';

    public const string ACCOMMODATION_CLASS_CODE = '022';

    public const string DEFAULT_CURRENCY = 'MYR';

    public const string ORIGIN_COUNTRY_CODE = 'MYS';

    public const string UNIT_CODE_EACH = 'C62';

    private Property $property;

    private ?PropertyEInvoiceSetting $setting;

    private DocumentSigner $documentSigner;

    public function __construct(
        private EInvoiceSubmission $originalSubmission,
        private string $creditNoteNumber,
        private float $amount,
        private string $reason
    ) {
        $this->property = $originalSubmission->property;
        $this->setting = $this->property->eInvoiceSetting;
        $this->documentSigner = new DocumentSigner($this->setting);

        if (empty($originalSubmission->uuid)) {
            throw new RuntimeException('Cannot issue e-invoice Credit Note: original submission does not have an LHDN UUID.');
        }
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
        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return [
            'format' => 'JSON',
            'document' => base64_encode($payloadJson),
            'documentHash' => hash('sha256', $payloadJson),
            'codeNumber' => $this->creditNoteNumber,
            'payload' => $payload,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveBuyer(): array
    {
        if (! empty($this->originalSubmission->buyer_snapshot)) {
            return $this->originalSubmission->buyer_snapshot;
        }

        if ($this->originalSubmission->folio !== null) {
            return BuyerResolver::forFolio($this->originalSubmission->folio);
        }

        if ($this->originalSubmission->arInvoice !== null) {
            return BuyerResolver::forCompany($this->originalSubmission->arInvoice->company, $this->property);
        }

        throw new \InvalidArgumentException('Original submission buyer details are missing.');
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

        $buyer = $this->resolveBuyer();
        if (empty($buyer['tin'])) {
            throw new \InvalidArgumentException('Buyer TIN is required to issue an e-invoice credit note.');
        }

        if (empty($buyer['city'])) {
            throw new \InvalidArgumentException('Buyer city is required for LHDN e-invoicing.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function buildPayload(): array
    {
        $subtotal = abs($this->amount);
        $buyer = $this->resolveBuyer();

        // LHDN reads IssueDate and IssueTime as UTC, so local time here would be 8 hours in the future.
        $issuedAt = now('UTC');

        $body = [
            'ID' => [['_' => $this->creditNoteNumber]],
            'IssueDate' => [['_' => $issuedAt->toDateString()]],
            'IssueTime' => [['_' => $issuedAt->format('H:i:s').'Z']],
            'InvoiceTypeCode' => [['_' => self::INVOICE_TYPE_CODE, 'listVersionID' => $this->documentSigner->documentVersion()]],
            'DocumentCurrencyCode' => [['_' => self::DEFAULT_CURRENCY]],
            'TaxCurrencyCode' => [['_' => self::DEFAULT_CURRENCY]],
            'BillingReference' => [
                [
                    'InvoiceDocumentReference' => [
                        [
                            'ID' => [['_' => $this->originalSubmission->internal_id]],
                            'UUID' => [['_' => $this->originalSubmission->uuid]],
                        ],
                    ],
                ],
            ],
            'AccountingSupplierParty' => [['Party' => [$this->supplierParty()]]],
            'AccountingCustomerParty' => [['Party' => [$this->buyerParty($buyer)]]],
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
                                            'TaxExemptionReason' => [['_' => 'Credit Note Adjustment']],
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
                            'Description' => [['_' => "Adjustment for {$this->originalSubmission->internal_id}: {$this->reason}"]],
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
                                    'TaxExemptionReason' => [['_' => 'Credit Note Adjustment']],
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
