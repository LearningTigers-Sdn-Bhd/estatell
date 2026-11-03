<?php

namespace App\Http\Controllers\Finance;

use App\Actions\EInvoice\CancelEInvoice;
use App\Actions\EInvoice\ConsolidateMonthlyEInvoices;
use App\Actions\EInvoice\IssueEInvoiceCreditNote;
use App\Actions\EInvoice\RefreshEInvoiceStatus;
use App\Enums\EInvoiceScenario;
use App\Enums\EInvoiceStatus;
use App\Features\EInvoicing;
use App\Http\Controllers\Controller;
use App\Models\EInvoiceSubmission;
use App\Models\Property;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Pennant\Feature;

class EInvoiceSubmissionsController extends Controller
{
    public function index(Property $property, Request $request): Response
    {
        if (! Feature::for($property)->active(EInvoicing::class)) {
            abort(404);
        }

        $query = $property->eInvoiceSubmissions()
            ->with(['folio.reservation', 'arInvoice.company', 'originalSubmission'])
            ->recentFirst();

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('scenario')) {
            $query->where('scenario', $request->string('scenario'));
        }

        if ($request->filled('search')) {
            $search = $request->string('search');
            $query->where(function ($q) use ($search) {
                $q->where('internal_id', 'ilike', "%{$search}%")
                    ->orWhere('uuid', 'ilike', "%{$search}%")
                    ->orWhere('submission_uid', 'ilike', "%{$search}%");
            });
        }

        $submissions = $query->paginate(25)->withQueryString()->through(fn (EInvoiceSubmission $sub): array => [
            'id' => $sub->id,
            'internal_id' => $sub->internal_id,
            'uuid' => $sub->uuid,
            'submission_uid' => $sub->submission_uid,
            'document_type' => $sub->document_type->value,
            'document_type_label' => $sub->document_type->label(),
            'document_type_short' => $sub->document_type->shortLabel(),
            'scenario' => $sub->scenario->value,
            'scenario_label' => $sub->scenario->label(),
            'status' => $sub->status->value,
            'status_label' => $sub->status->label(),
            'status_tone' => $sub->status->tone()->value,
            'is_consolidated' => $sub->is_consolidated,
            'requested_by_guest' => $sub->requested_by_guest,
            'is_cancellable' => $sub->isCancellable(),
            'is_retryable' => $sub->isRetryable(),
            'cancellation_deadline' => $sub->cancellationDeadline()?->toIso8601String(),
            'validation_url' => $sub->validationUrl(),
            'buyer_name' => $sub->buyer_snapshot['name'] ?? 'Buyer',
            'buyer_tin' => $sub->buyer_snapshot['tin'] ?? '',
            'submitted_at' => $sub->submitted_at?->toIso8601String(),
            'validated_at' => $sub->validated_at?->toIso8601String(),
            'cancelled_at' => $sub->cancelled_at?->toIso8601String(),
            'created_at' => $sub->created_at?->toIso8601String(),
            'errors' => $sub->errorMessages(),
            'folio_number' => $sub->folio?->number,
            'ar_invoice_number' => $sub->arInvoice?->number,
        ]);

        $stats = [
            'total' => $property->eInvoiceSubmissions()->count(),
            'valid' => $property->eInvoiceSubmissions()->where('status', EInvoiceStatus::Valid)->count(),
            'invalid' => $property->eInvoiceSubmissions()->where('status', EInvoiceStatus::Invalid)->count(),
            'pending' => $property->eInvoiceSubmissions()->whereIn('status', [EInvoiceStatus::Pending, EInvoiceStatus::Submitted])->count(),
        ];

        return Inertia::render('finance/e-invoices/index', [
            'submissions' => $submissions,
            'filters' => $request->only(['status', 'scenario', 'search']),
            'sort' => $request->string('sort')->toString(),
            'statusOptions' => EInvoiceStatus::options(),
            'scenarioOptions' => EInvoiceScenario::options(),
            'stats' => $stats,
            'setting' => [
                'enabled' => $property->eInvoiceSetting?->enabled ?? false,
                'environment' => $property->eInvoiceSetting?->api_environment?->value ?? 'sandbox',
                'consolidation_enabled' => $property->eInvoiceSetting?->consolidation_enabled ?? false,
            ],
        ]);
    }

    public function show(Property $property, EInvoiceSubmission $submission): Response
    {
        if (! Feature::for($property)->active(EInvoicing::class) || $submission->property_id !== $property->id) {
            abort(404);
        }

        $submission->load(['folio.reservation', 'arInvoice.company', 'originalSubmission', 'adjustments']);

        return Inertia::render('finance/e-invoices/show', [
            'submission' => [
                'id' => $submission->id,
                'internal_id' => $submission->internal_id,
                'uuid' => $submission->uuid,
                'submission_uid' => $submission->submission_uid,
                'long_id' => $submission->long_id,
                'document_type' => $submission->document_type->value,
                'document_type_label' => $submission->document_type->label(),
                'scenario' => $submission->scenario->value,
                'scenario_label' => $submission->scenario->label(),
                'status' => $submission->status->value,
                'status_label' => $submission->status->label(),
                'status_tone' => $submission->status->tone()->value,
                'is_consolidated' => $submission->is_consolidated,
                'requested_by_guest' => $submission->requested_by_guest,
                'cancel_reason' => $submission->cancel_reason,
                'is_cancellable' => $submission->isCancellable(),
                'cancellation_deadline' => $submission->cancellationDeadline()?->toIso8601String(),
                'validation_url' => $submission->validationUrl(),
                'buyer_snapshot' => $submission->buyer_snapshot,
                'document_payload' => $submission->document_payload,
                'raw_response' => $submission->raw_response,
                'error_details' => $submission->error_details,
                'error_messages' => $submission->errorMessages(),
                'submitted_at' => $submission->submitted_at?->toIso8601String(),
                'validated_at' => $submission->validated_at?->toIso8601String(),
                'cancelled_at' => $submission->cancelled_at?->toIso8601String(),
                'created_at' => $submission->created_at?->toIso8601String(),
                'folio_number' => $submission->folio?->number,
                'ar_invoice_number' => $submission->arInvoice?->number,
                'original_submission' => $submission->originalSubmission ? [
                    'id' => $submission->originalSubmission->id,
                    'internal_id' => $submission->originalSubmission->internal_id,
                    'uuid' => $submission->originalSubmission->uuid,
                ] : null,
                'adjustments' => $submission->adjustments->map(fn ($adj) => [
                    'id' => $adj->id,
                    'internal_id' => $adj->internal_id,
                    'uuid' => $adj->uuid,
                    'status' => $adj->status->value,
                    'status_label' => $adj->status->label(),
                ]),
            ],
        ]);
    }

    public function refresh(Property $property, EInvoiceSubmission $submission, RefreshEInvoiceStatus $refreshAction): RedirectResponse
    {
        if (! Feature::for($property)->active(EInvoicing::class) || $submission->property_id !== $property->id) {
            abort(404);
        }

        $refreshAction($submission);

        return back()->with('success', __('E-invoice status refreshed from LHDN MyInvois.'));
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
            return back()->with('error', $result->message ?? __('Unable to cancel e-invoice.'));
        }

        return back()->with('success', __('E-invoice has been successfully cancelled on LHDN.'));
    }

    public function creditNote(
        Property $property,
        EInvoiceSubmission $submission,
        Request $request,
        IssueEInvoiceCreditNote $creditNoteAction
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
            return back()->with('error', $result->message ?? __('Unable to issue e-invoice credit note.'));
        }

        return back()->with('success', __('E-invoice credit note has been submitted to LHDN.'));
    }

    public function consolidate(Property $property, Request $request, ConsolidateMonthlyEInvoices $consolidateAction): RedirectResponse
    {
        if (! Feature::for($property)->active(EInvoicing::class)) {
            abort(404);
        }

        $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
        ]);

        $monthDate = $request->filled('month')
            ? CarbonImmutable::createFromFormat('Y-m', $request->string('month'))
            : CarbonImmutable::now('Asia/Kuala_Lumpur')->subMonth();

        $submissions = $consolidateAction($property, $monthDate, $request->user());

        if (empty($submissions)) {
            return back()->with('info', __('No unconsolidated folios were found for this period.'));
        }

        $count = count($submissions);

        return back()->with('success', __("Successfully created {$count} consolidated e-invoice submission(s)."));
    }
}
