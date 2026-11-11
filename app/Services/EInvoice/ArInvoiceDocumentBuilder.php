<?php

namespace App\Services\EInvoice;

use App\Actions\EInvoice\EInvoiceResult;
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
        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

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
        MissingEInvoiceDetails::hotel($this->property);

        $buyer = BuyerResolver::forCompany($this->invoice->company, $this->property);

        if (empty($buyer['tin'])) {
            throw new MissingEInvoiceDetails(EInvoiceResult::REFUSAL_BUYER_TIN_REQUIRED, __("Add the company's tax number (TIN) on the company record, then send again."));
        }

        if (in_array(strtoupper((string) $buyer['id_value']), ['', 'NA'], true)) {
            throw new MissingEInvoiceDetails(EInvoiceResult::REFUSAL_BUYER_DETAILS_INCOMPLETE, __("Add the company's registration number on the company record, then send again. LHDN checks it against the tax number."));
        }

        if (empty($buyer['city'])) {
            throw new MissingEInvoiceDetails(EInvoiceResult::REFUSAL_BUYER_DETAILS_INCOMPLETE, __("Add the company's billing city on the company record, then send again."));
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
                    'StartDate' => [['_' => $this->invoice->issued_on->toDateString()]],
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
        return app(SupplierDetails::class)->party($this->property);
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
