<?php

namespace App\Http\Controllers\Payouts;

use App\Data\Commissions\PayoutVoucherRowData;
use App\Http\Controllers\Controller;
use App\Models\PayoutVoucher;
use App\Models\Property;
use App\Services\Commissions\PayoutVoucherDirectory;
use App\Services\Commissions\PropertyPayouts;
use App\Services\Property\PrintedPdf;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\LaravelData\PaginatedDataCollection;

/**
 * The property Payouts tab: its vouchers, one voucher read-only, and its PDF. Draft vouchers answer 404.
 * See docs/30-commission-fees/06-property-payouts.md#payouts-tab.
 */
class PayoutVoucherController extends Controller
{
    public function index(Property $property, PropertyPayouts $payouts): Response
    {
        return Inertia::render('finance/payouts/vouchers/index', [
            'vouchers' => PayoutVoucherRowData::collect($payouts->vouchers($property), PaginatedDataCollection::class),
        ]);
    }

    public function show(Property $property, PayoutVoucher $payoutVoucher, PayoutVoucherDirectory $directory): Response
    {
        abort_unless($payoutVoucher->status->isVisibleToProperty(), 404);

        return Inertia::render('finance/payouts/vouchers/show', [
            'voucher' => $directory->detail($payoutVoucher, forProperty: true),
        ]);
    }

    public function pdf(Property $property, PayoutVoucher $payoutVoucher, PayoutVoucherDirectory $directory, PrintedPdf $printedPdf): HttpResponse
    {
        abort_unless($payoutVoucher->status->isVisibleToProperty(), 404);

        return $printedPdf->make($property, 'pdf.payout-voucher', ['voucher' => $directory->detail($payoutVoucher, forProperty: true)])
            ->stream($payoutVoucher->number.'.pdf');
    }
}
