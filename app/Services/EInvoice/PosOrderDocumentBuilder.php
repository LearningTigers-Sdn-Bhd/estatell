<?php

namespace App\Services\EInvoice;

use App\Models\PosOrder;
use Brick\Math\BigDecimal;

/**
 * Builds the LHDN e-invoice (`01`) of one walk-in POS order to a named buyer: one line for the walk-in share (before tax)
 * with its taxes by LHDN tax type. Amounts come from `PosOrderReportLines` and become JSON numbers only at the end
 * (`LhdnAmount`). It reads only.
 */
class PosOrderDocumentBuilder
{
    public const string CLASS_CODE = '022';

    public function __construct(
        private PosOrderReportLines $reportLines,
        private FolioDocumentBuilder $folioDocumentBuilder,
        private SupplierDetails $supplierDetails,
        private DocumentEncoder $documentEncoder,
    ) {}

    /**
     * @param  array<string, mixed>  $buyerSnapshot  What staff entered in the Issue e-invoice form.
     * @return array{format: string, document: string, documentHash: string, codeNumber: string, payload: array<string, mixed>, buyer: array<string, mixed>}
     *
     * @throws MissingEInvoiceDetails when the hotel or the buyer lacks a detail LHDN needs.
     */
    public function build(PosOrder $order, array $buyerSnapshot): array
    {
        $property = $order->property;
        $buyer = $this->folioDocumentBuilder->buyer(null, $buyerSnapshot);

        MissingEInvoiceDetails::hotel($property);
        $this->folioDocumentBuilder->checkBuyer($order, $buyer);

        $signer = new DocumentSigner($property->eInvoiceSetting);
        $internalId = (string) $order->order_number;
        $split = $this->reportLines->split($order);
        $currency = FolioDocumentBuilder::DEFAULT_CURRENCY;
        $issuedAt = now('UTC');

        $subtotals = [];
        foreach ($split['taxes'] as $type => $amount) {
            $subtotals[] = $this->taxSubtotal((string) $type, $split['taxable'][$type], $amount, $split['percent'][$type] ?? null);
        }
        if ($split['untaxed']->isPositive() || $subtotals === []) {
            $subtotals[] = $this->taxSubtotal('E', $split['untaxed'], BigDecimal::zero());
        }

        $net = LhdnAmount::of($split['net']);
        $gross = LhdnAmount::of($split['net']->plus($split['tax']));
        $money = fn (float $amount): array => [['_' => $amount, 'currencyID' => $currency]];

        $body = [
            'ID' => [['_' => $internalId]],
            'IssueDate' => [['_' => $issuedAt->toDateString()]],
            'IssueTime' => [['_' => $issuedAt->format('H:i:s').'Z']],
            'InvoiceTypeCode' => [['_' => FolioDocumentBuilder::INVOICE_TYPE_CODE, 'listVersionID' => $signer->documentVersion()]],
            'DocumentCurrencyCode' => [['_' => $currency]],
            'TaxCurrencyCode' => [['_' => $currency]],
            'AccountingSupplierParty' => [['Party' => [$this->supplierDetails->party($property)]]],
            'AccountingCustomerParty' => [['Party' => [$this->folioDocumentBuilder->buyerParty($buyer)]]],
            'InvoiceLine' => [[
                'ID' => [['_' => '1']],
                'InvoicedQuantity' => [['_' => 1, 'unitCode' => FolioDocumentBuilder::UNIT_CODE_EACH]],
                'LineExtensionAmount' => $money($net),
                'AllowanceCharge' => [['ChargeIndicator' => [['_' => false]], 'Amount' => $money(0.0)]],
                'TaxTotal' => [['TaxAmount' => $money(LhdnAmount::of($split['tax'])), 'TaxSubtotal' => $subtotals]],
                'Item' => [[
                    'CommodityClassification' => [['ItemClassificationCode' => [['_' => self::CLASS_CODE, 'listID' => 'CLASS']]]],
                    'Description' => [['_' => 'Food and beverage '.$internalId]],
                    'OriginCountry' => [['IdentificationCode' => [['_' => FolioDocumentBuilder::ORIGIN_COUNTRY_CODE]]]],
                ]],
                'Price' => [['PriceAmount' => $money($net)]],
                'ItemPriceExtension' => [['Amount' => $money($net)]],
            ]],
            'TaxTotal' => [['TaxAmount' => $money(LhdnAmount::of($split['tax'])), 'TaxSubtotal' => $subtotals]],
            'LegalMonetaryTotal' => [[
                'LineExtensionAmount' => $money($net),
                'TaxExclusiveAmount' => $money($net),
                'TaxInclusiveAmount' => $money($gross),
                'AllowanceTotalAmount' => $money(0.0),
                'ChargeTotalAmount' => $money(0.0),
                'PayableRoundingAmount' => $money(0.0),
                'PayableAmount' => $money($gross),
            ]],
        ];

        $payload = $signer->apply(array_merge(FolioDocumentBuilder::UBL_NAMESPACES, ['Invoice' => [$body]]));

        return [...$this->documentEncoder->encode($payload, $internalId), 'buyer' => $buyer];
    }

    /**
     * @return array<string, mixed>
     */
    private function taxSubtotal(string $type, BigDecimal $taxable, BigDecimal $tax, ?BigDecimal $percent = null): array
    {
        $currency = FolioDocumentBuilder::DEFAULT_CURRENCY;

        return [
            'TaxableAmount' => [['_' => LhdnAmount::of($taxable), 'currencyID' => $currency]],
            'TaxAmount' => [['_' => LhdnAmount::of($tax), 'currencyID' => $currency]],
            ...($percent === null ? [] : ['Percent' => [['_' => LhdnAmount::of($percent)]]]),
            'TaxCategory' => [[
                'ID' => [['_' => $type]],
                'TaxExemptionReason' => [['_' => $type === 'E' ? 'No tax charged on this sale' : '']],
                'TaxScheme' => [['ID' => [['_' => 'OTH', 'schemeID' => 'UN/ECE 5153', 'schemeAgencyID' => '6']]]],
            ]],
        ];
    }
}
