<?php

namespace App\Http\Controllers\Finance;

use App\Actions\EInvoice\CancelEInvoice;
use App\Actions\EInvoice\ConsolidateMonthlyEInvoices;
use App\Actions\EInvoice\EmailEInvoiceToGuest;
use App\Actions\EInvoice\IssueEInvoiceCreditNote;
use App\Actions\EInvoice\RefreshEInvoiceStatus;
use App\Data\Property\CurrencyData;
use App\Enums\EInvoiceDocumentType;
use App\Enums\EInvoiceStatus;
use App\Enums\PermissionEnum;
use App\Features\EInvoicing;
use App\Http\Controllers\Controller;
use App\Models\EInvoiceSubmission;
use App\Models\Property;
use App\Services\EInvoice\MissingEInvoiceDetails;
use App\Services\EInvoice\MyInvoisApiException;
use App\Services\EInvoice\StaysNeedingOwnEInvoice;
use App\Services\EInvoice\SubmissionToast;
use App\Services\EInvoice\SubmissionView;
use App\Services\EInvoice\SummaryMonth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * The Finance e-invoice pages and their actions. The route group answers 404 while e-invoicing is off (`einvoicing`)
 * and finds `{submission}` only among the property's own (scoped bindings), so the methods do not check either again.
 */
class EInvoiceSubmissionsController extends Controller
{
    public function index(Property $property, Request $request, SubmissionView $view, StaysNeedingOwnEInvoice $staysNeedingOwnEInvoice): Response
    {
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'type' => (string) $request->query('type', ''),
            'status' => (string) $request->query('status', ''),
        ];

        $query = $property->eInvoiceSubmissions()
            ->with(['property.settings', 'folio.reservation', 'arInvoice.company'])
            ->withCount('folios')
            ->recentFirst();

        if (EInvoiceStatus::tryFrom($filters['status']) !== null) {
            $query->where('status', $filters['status']);
        }

        match ($filters['type']) {
            'summary' => $query->where('is_consolidated', true),
            'credit_note' => $query->where('document_type', EInvoiceDocumentType::CreditNote),
            'refund_note' => $query->where('document_type', EInvoiceDocumentType::RefundNote),
            'invoice' => $query->where('is_consolidated', false)->where('document_type', EInvoiceDocumentType::Invoice),
            default => null,
        };

        if ($filters['search'] !== '') {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $filters['search']).'%';
            $query->where(fn ($q) => $q
                ->where('internal_id', 'ilike', $like)
                ->orWhereRaw("buyer_snapshot->>'name' ilike ?", [$like])
                ->orWhereHas('folio.reservation', fn ($r) => $r->where('reference', 'ilike', $like))
                ->orWhereHas('arInvoice', fn ($a) => $a->where('number', 'ilike', $like)));
        }

        $submissions = $view->rows($query->paginate(25)->withQueryString());

        return Inertia::render('finance/e-invoices/index', [
            'submissions' => [
                'data' => $submissions->items(),
                'links' => $submissions->linkCollection()->toArray(),
                'meta' => Arr::except($submissions->toArray(), ['data', 'links']),
            ],
            'filters' => $filters,
            'types' => [
                ['value' => 'invoice', 'label' => __('E-invoice')],
                ['value' => 'credit_note', 'label' => __('Credit note')],
                ['value' => 'refund_note', 'label' => __('Refund note')],
                ['value' => 'summary', 'label' => __('Monthly summary')],
            ],
            'statuses' => array_values(array_filter(EInvoiceStatus::options(), fn (array $option): bool => $option['value'] !== EInvoiceStatus::Draft->value)),
            'rejected' => $view->rejectedToFix($property->id),
            'needOwnEInvoice' => array_slice($staysNeedingOwnEInvoice->for($property), 0, 20),
            'canRunSummary' => ($property->eInvoiceSetting->consolidation_enabled ?? false) && $request->user()->can(PermissionEnum::EInvoiceManage->value),
            'currency' => CurrencyData::forProperty($property),
        ]);
    }

    public function show(Property $property, EInvoiceSubmission $submission, SubmissionView $view): Response
    {
        return Inertia::render('finance/e-invoices/show', [
            'submission' => $view->for($submission, request()->user()->can(PermissionEnum::GuestsViewDocuments->value)),
            'currency' => CurrencyData::forProperty($property),
            'canEditEmail' => request()->user()->can(PermissionEnum::SettingsManage->value),
        ]);
    }

    public function refresh(Property $property, EInvoiceSubmission $submission, RefreshEInvoiceStatus $refreshAction): RedirectResponse
    {
        try {
            $refreshed = $refreshAction($submission);
        } catch (MyInvoisApiException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('LHDN did not answer. Try again in a minute.'), 'description' => $e->getMessage()]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('E-invoice status: :status.', ['status' => $refreshed->status->label()])]);

        return back();
    }

    public function email(Property $property, EInvoiceSubmission $submission, Request $request, EmailEInvoiceToGuest $emailAction): RedirectResponse
    {
        $validated = $request->validate(['to' => ['required', 'email:rfc', 'max:255']]);
        $result = $emailAction($submission, $validated['to'], $request->user());

        Inertia::flash('toast', $result->ok
            ? ['type' => 'success', 'message' => __('E-invoice emailed to :email.', ['email' => $validated['to']])]
            : ['type' => 'error', 'message' => $result->message ?? __('The e-invoice was not emailed.')]);

        return back();
    }

    public function cancel(Property $property, EInvoiceSubmission $submission, Request $request, CancelEInvoice $cancelAction): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $result = $cancelAction($submission, $validated['reason'], $request->user());

        if (! $result->ok) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $result->message ?? __('The e-invoice was not cancelled.')]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('E-invoice cancelled.')]);

        return back();
    }

    public function creditNote(
        Property $property,
        EInvoiceSubmission $submission,
        Request $request,
        IssueEInvoiceCreditNote $creditNoteAction,
        SubmissionToast $submissionToast,
    ): RedirectResponse {
        $validated = $request->validate([
            'credit_note_number' => ['required', 'string', 'max:50'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'reason' => ['required', 'string', 'max:500'],
            'kind' => ['nullable', 'in:credit_note,refund_note'],
        ]);

        $result = $creditNoteAction(
            $submission,
            $validated['credit_note_number'],
            (string) $validated['amount'],
            $validated['reason'],
            $request->user(),
            ($validated['kind'] ?? null) === 'refund_note' ? EInvoiceDocumentType::RefundNote : EInvoiceDocumentType::CreditNote,
        );

        if (! $result->ok) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $result->message ?? __('The credit note was not sent.')]);

            return back();
        }

        Inertia::flash('toast', $submissionToast->for($result->submission));

        return back();
    }

    public function consolidate(Property $property, Request $request, ConsolidateMonthlyEInvoices $consolidateAction, SubmissionToast $submissionToast, StaysNeedingOwnEInvoice $staysNeedingOwnEInvoice, SummaryMonth $summaryMonth): RedirectResponse
    {
        $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
        ]);

        $month = $request->filled('month') ? $summaryMonth->parse($property, $request->string('month')->toString()) : $summaryMonth->previous($property);

        try {
            $submissions = $consolidateAction($property, $month, $request->user());
        } catch (MissingEInvoiceDetails $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('The monthly summary was not sent.'), 'description' => $e->getMessage()]);

            return back();
        } catch (Throwable $e) {
            // Nothing was saved or sent: the summary is built in full before any part goes to LHDN.
            report($e);
            Inertia::flash('toast', ['type' => 'error', 'message' => __('The monthly summary was not sent.'), 'description' => __('Something went wrong while building it, so nothing went to LHDN. Try again. If it happens again, contact support.')]);

            return back();
        }

        Inertia::flash('toast', $submissionToast->forMonthlySummary($submissions, count($staysNeedingOwnEInvoice->for($property))));

        return back();
    }
}
