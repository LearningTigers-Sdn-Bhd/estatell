<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Commissions\AddVoucherAdjustment;
use App\Actions\Commissions\ApprovePayoutVoucher;
use App\Actions\Commissions\MarkPayoutVoucherPaid;
use App\Data\Commissions\PayoutVoucherRowData;
use App\Enums\PayoutVoucherStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AddVoucherAdjustmentRequest;
use App\Http\Requests\Admin\MarkPayoutVoucherPaidRequest;
use App\Models\PayoutVoucher;
use App\Services\Commissions\PayoutVoucherDirectory;
use App\Services\Property\PrintedPdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\LaravelData\PaginatedDataCollection;

/**
 * The admin payout vouchers: the list, one voucher, its actions and its PDF. See docs/30-commission-fees/05-platform-pages.md.
 */
class PayoutVoucherController extends Controller
{
    public function index(Request $request, PayoutVoucherDirectory $directory): Response
    {
        return Inertia::render('admin/commissions/vouchers/index', [
            'vouchers' => PayoutVoucherRowData::collect($directory->paginate($request), PaginatedDataCollection::class),
            'filters' => [
                'cycle' => $request->string('filter.cycle')->toString(),
                'status' => $request->string('filter.status')->toString(),
                'property' => $request->string('filter.property')->toString(),
            ],
            'sort' => $request->string('sort', '-period_end')->toString(),
            'cycles' => $directory->cycleOptions(),
            'statuses' => PayoutVoucherStatus::options(),
            'properties' => $directory->propertyOptions(),
        ]);
    }

    public function show(PayoutVoucher $voucher, PayoutVoucherDirectory $directory): Response
    {
        return Inertia::render('admin/commissions/vouchers/show', [
            'voucher' => $directory->detail($voucher),
        ]);
    }

    public function approve(Request $request, PayoutVoucher $voucher, ApprovePayoutVoucher $approvePayoutVoucher): RedirectResponse
    {
        $refusal = $approvePayoutVoucher($request->user(), $voucher);

        Inertia::flash('toast', match ($refusal) {
            null => ['type' => 'success', 'message' => __('Voucher approved.')],
            ApprovePayoutVoucher::REFUSAL_NO_BANK_ACCOUNT => ['type' => 'error', 'message' => __('Add an active bank account for this property before approving.')],
            default => ['type' => 'error', 'message' => __('This voucher is no longer a draft.')],
        });

        return to_route('admin.commissions.vouchers.show', $voucher);
    }

    public function markPaid(MarkPayoutVoucherPaidRequest $request, PayoutVoucher $voucher, MarkPayoutVoucherPaid $markPayoutVoucherPaid): RedirectResponse
    {
        $paid = $markPayoutVoucherPaid($request->user(), $voucher, $request->paidOn(), $request->validated('paid_reference'));

        Inertia::flash('toast', $paid
            ? ['type' => 'success', 'message' => __('Voucher marked as paid.')]
            : ['type' => 'error', 'message' => __('Only an approved voucher can be marked as paid.')]);

        return to_route('admin.commissions.vouchers.show', $voucher);
    }

    public function storeAdjustment(AddVoucherAdjustmentRequest $request, PayoutVoucher $voucher, AddVoucherAdjustment $addVoucherAdjustment): RedirectResponse
    {
        $added = $addVoucherAdjustment($request->user(), $voucher, (string) $request->validated('amount'), $request->validated('reason'), $request->validated('idempotency_key'));

        Inertia::flash('toast', $added
            ? ['type' => 'success', 'message' => __('Adjustment added.')]
            : ['type' => 'error', 'message' => __('Only a draft voucher can be adjusted.')]);

        return to_route('admin.commissions.vouchers.show', $voucher);
    }

    public function pdf(PayoutVoucher $voucher, PayoutVoucherDirectory $directory, PrintedPdf $printedPdf): HttpResponse
    {
        return $printedPdf->make($voucher->property, 'pdf.payout-voucher', ['voucher' => $directory->detail($voucher)])
            ->stream($voucher->number.'.pdf');
    }
}
