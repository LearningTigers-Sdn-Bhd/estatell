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
use App\Services\EInvoice\SubmissionToast;
use App\Services\EInvoice\SubmissionView;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Pennant\Feature;

class EInvoiceSubmissionsController extends Controller
{
    public function index(Property $property, Request $request, SubmissionView $view): Response
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

        $submissions = $query->paginate(25)->withQueryString()->through(fn (EInvoiceSubmission $sub): array => $view->row($sub));

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
                ['value' => 'summary', 'label' => __('Monthly summary')],
            ],
            'statuses' => array_values(array_filter(EInvoiceStatus::options(), fn (array $option): bool => $option['value'] !== EInvoiceStatus::Draft->value)),
            'rejected' => $property->eInvoiceSubmissions()->where('status', EInvoiceStatus::Invalid)->count(),
            'canRunSummary' => ($property->eInvoiceSetting->consolidation_enabled ?? false) && $request->user()->can(PermissionEnum::EInvoiceManage->value),
            'currency' => CurrencyData::forProperty($property),
        ]);
    }

    public function show(Property $property, EInvoiceSubmission $submission, SubmissionView $view): Response
    {
        if (! Feature::for($property)->active(EInvoicing::class) || $submission->property_id !== $property->id) {
            abort(404);
        }

        return Inertia::render('finance/e-invoices/show', [
            'submission' => $view->for($submission),
            'currency' => CurrencyData::forProperty($property),
            'canEditEmail' => request()->user()->can(PermissionEnum::SettingsManage->value),
        ]);
    }

    public function refresh(Property $property, EInvoiceSubmission $submission, RefreshEInvoiceStatus $refreshAction): RedirectResponse
    {
        if (! Feature::for($property)->active(EInvoicing::class) || $submission->property_id !== $property->id) {
            abort(404);
        }

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
        abort_unless($submission->property_id === $property->id, 404);

        $validated = $request->validate(['to' => ['required', 'email:rfc', 'max:255']]);
        $result = $emailAction($submission, $validated['to'], $request->user());

        Inertia::flash('toast', $result->ok
            ? ['type' => 'success', 'message' => __('E-invoice emailed to :email.', ['email' => $validated['to']])]
            : ['type' => 'error', 'message' => $result->message ?? __('The e-invoice was not emailed.')]);

        return back();
    }

    public function cancel(Property $property, EInvoiceSubmission $submission, Request $request, CancelEInvoice $cancelAction): RedirectResponse
    {
        if (! Feature::for($property)->active(EInvoicing::class) || $submission->property_id !== $property->id) {
            abort(404);
        }

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
        if (! Feature::for($property)->active(EInvoicing::class) || $submission->property_id !== $property->id) {
            abort(404);
        }

        $validated = $request->validate([
            'credit_note_number' => ['required', 'string', 'max:50'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $result = $creditNoteAction(
            $submission,
            $validated['credit_note_number'],
            (float) $validated['amount'],
            $validated['reason'],
            $request->user()
        );

        if (! $result->ok) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $result->message ?? __('The credit note was not sent.')]);

            return back();
        }

        Inertia::flash('toast', $submissionToast->for($result->submission));

        return back();
    }

    public function consolidate(Property $property, Request $request, ConsolidateMonthlyEInvoices $consolidateAction, SubmissionToast $submissionToast): RedirectResponse
    {
        if (! Feature::for($property)->active(EInvoicing::class)) {
            abort(404);
        }

        $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
        ]);

        $monthDate = $request->filled('month')
            ? CarbonImmutable::createFromFormat('!Y-m', $request->string('month'), 'Asia/Kuala_Lumpur')
            : CarbonImmutable::now('Asia/Kuala_Lumpur')->subMonth();

        try {
            $submissions = $consolidateAction($property, $monthDate, $request->user());
        } catch (MissingEInvoiceDetails $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('The monthly summary was not sent.'), 'description' => $e->getMessage()]);

            return back();
        }

        if (empty($submissions)) {
            Inertia::flash('toast', ['type' => 'info', 'message' => __('No stays to send for that month. They are filed already, or none closed.')]);

            return back();
        }

        $count = count($submissions);
        $rejected = collect($submissions)->filter(fn (EInvoiceSubmission $submission): bool => $submission->status === EInvoiceStatus::Invalid);

        Inertia::flash('toast', match (true) {
            $count === 1 => $submissionToast->for($submissions[0]),
            $rejected->isNotEmpty() => ['type' => 'error', 'message' => __('LHDN rejected :rejected of the :count parts of the monthly summary.', ['rejected' => $rejected->count(), 'count' => $count]), 'description' => __('Open them in the list to see why.')],
            default => ['type' => 'success', 'message' => __('Monthly summary sent to LHDN in :count parts.', ['count' => $count])],
        });

        return back();
    }
}
