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
     * A credit or refund note of `$amount` on an e-invoice LHDN accepted, to the original buyer.
     *
     * @return array{format: string, document: string, documentHash: string, codeNumber: string, payload: array<string, mixed>}
     *
     * @throws MissingEInvoiceDetails when the hotel or the original buyer lacks a detail LHDN needs.
     */
    public function build(EInvoiceSubmission $originalSubmission, string $creditNoteNumber, string $amount, string $reason, EInvoiceDocumentType $type = EInvoiceDocumentType::CreditNote): array
    {
        if (empty($originalSubmission->uuid)) {
            throw new RuntimeException('Cannot issue e-invoice Credit Note: original submission does not have an LHDN UUID.');
        }

        $property = $originalSubmission->property;
        MissingEInvoiceDetails::hotel($property);
        $buyer = $this->buyer($originalSubmission, $property);
        $this->checkBuyer($buyer);

        return $this->documentEncoder->encode($this->payload($originalSubmission, $property, $buyer, $creditNoteNumber, $amount, $reason, $type), $creditNoteNumber);
    }

    /**
     * @return array<string, mixed>
     */
    private function buyer(EInvoiceSubmission $originalSubmission, Property $property): array
    {
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
     * @return array<string, mixed>
     */
    private function payload(EInvoiceSubmission $originalSubmission, Property $property, array $buyer, string $creditNoteNumber, string $amount, string $reason, EInvoiceDocumentType $type): array
    {
        $signer = new DocumentSigner($property->eInvoiceSetting);
        $subtotal = LhdnAmount::of(BigDecimal::of($amount)->abs());

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
                            'Description' => [['_' => "Adjustment for {$originalSubmission->internal_id}: {$reason}"]],
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

        return $signer->apply(array_merge(FolioDocumentBuilder::UBL_NAMESPACES, ['Invoice' => [$body]]));
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
