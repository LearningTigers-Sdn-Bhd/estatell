<?php

namespace App\Http\Controllers\Finance;

use App\Actions\EInvoice\ResubmitWithTaxNumber;
use App\Http\Controllers\Controller;
use App\Models\EInvoiceSubmission;
use App\Models\Property;
use App\Services\EInvoice\SubmissionToast;
use App\Services\EInvoice\TaxNumberCheck;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Checks a buyer's tax number with LHDN: from the Issue e-invoice form before sending, or for a rejected e-invoice,
 * which can then go again with the tax number LHDN holds. See docs/31-einvoice/01-lhdn-myinvois.md#checking-a-tax-number.
 */
class EInvoiceTaxNumberController extends Controller
{
    public function check(Request $request, Property $property, TaxNumberCheck $taxNumberCheck): JsonResponse
    {
        $validated = $request->validate([
            'tin' => ['nullable', 'string', 'max:50'],
            'id_type' => ['required', 'string', 'in:NRIC,PASSPORT,BRN,ARMY'],
            'id_value' => ['required', 'string', 'max:50'],
        ], [
            'id_value.required' => __('Enter the ID number first.'),
        ]);

        return response()->json($taxNumberCheck->check($property, $validated['tin'] ?? null, $validated['id_type'], $validated['id_value']));
    }

    public function find(Property $property, EInvoiceSubmission $submission, TaxNumberCheck $taxNumberCheck): JsonResponse
    {
        abort_unless($submission->property_id === $property->id, 404);

        $buyer = $submission->buyer_snapshot ?? [];
        $idValue = (string) ($buyer['id_value'] ?? '');
        abort_if($idValue === '' || strtoupper($idValue) === 'NA', 422, __('This e-invoice was sent without an ID number, so there is nothing to look up.'));

        return response()->json($taxNumberCheck->check($property, (string) ($buyer['tin'] ?? ''), (string) ($buyer['id_type'] ?? 'NRIC'), $idValue));
    }

    public function resubmit(Request $request, Property $property, EInvoiceSubmission $submission, ResubmitWithTaxNumber $resubmit, SubmissionToast $submissionToast): RedirectResponse
    {
        abort_unless($submission->property_id === $property->id, 404);

        $validated = $request->validate(['tin' => ['required', 'string', 'max:50']]);
        $result = $resubmit($submission, $request->user(), $validated['tin']);

        if (! $result->ok) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $result->message ?? __('The e-invoice was not sent.')]);

            return back();
        }

        Inertia::flash('toast', $submissionToast->for($result->submission));

        return to_route('finance.e-invoices.show', ['property' => $property, 'submission' => $result->submission]);
    }
}
