<?php

namespace App\Services\EInvoice;

use App\Actions\EInvoice\EInvoiceResult;
use App\Enums\EInvoiceDocumentType;
use App\Models\EInvoiceSubmission;
use App\Models\Property;
use App\Models\PropertyEInvoiceSetting;
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

    private Property $property;

    private ?PropertyEInvoiceSetting $setting;

    private DocumentSigner $documentSigner;

    public function __construct(
        private EInvoiceSubmission $originalSubmission,
        private string $creditNoteNumber,
        private float $amount,
        private string $reason,
        private EInvoiceDocumentType $type = EInvoiceDocumentType::CreditNote,
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

        throw new MissingEInvoiceDetails(EInvoiceResult::REFUSAL_BUYER_DETAILS_INCOMPLETE, __('The original e-invoice has no buyer details, so no credit note can be made for it.'));
    }

    private function validateRequiredData(): void
    {
        MissingEInvoiceDetails::hotel($this->property);

        $buyer = $this->resolveBuyer();
        if (empty($buyer['tin'])) {
            throw new MissingEInvoiceDetails(EInvoiceResult::REFUSAL_BUYER_TIN_REQUIRED, __('The original e-invoice has no buyer tax number, so no credit note can be made for it.'));
        }

        if (empty($buyer['city'])) {
            throw new MissingEInvoiceDetails(EInvoiceResult::REFUSAL_BUYER_DETAILS_INCOMPLETE, __('The original e-invoice has no buyer city, so no credit note can be made for it.'));
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
            'InvoiceTypeCode' => [['_' => $this->type->value, 'listVersionID' => $this->documentSigner->documentVersion()]],
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
        return app(SupplierDetails::class)->party($this->property);
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
