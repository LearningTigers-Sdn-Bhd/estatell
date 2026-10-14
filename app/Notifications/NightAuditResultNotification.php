<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Models\NightAudit;
use App\Services\NightAudit\AuditResultSummary;
use App\Services\NightAudit\AuditSummary;
use App\Services\NightAudit\ReadinessAdvice;
use Brick\Money\Money;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * The shared body of the night audit result messages: the headline figures, revenue by code, payments by method,
 * the meals to prepare and the exceptions to review, with a link to the night audit sheet.
 */
abstract class NightAuditResultNotification extends PropertyNotification
{
    public function __construct(public NightAudit $audit, NotificationType $type)
    {
        parent::__construct($type);
    }

    abstract protected function subject(AuditSummary $summary): string;

    public function toMail(object $notifiable): MailMessage
    {
        $summary = app(AuditResultSummary::class)->forAudit($this->audit);
        $mail = (new MailMessage)->subject($this->subject($summary));
        $mail->markdown('notifications::email', [
            'mailTitle' => match ($this->type) {
                NotificationType::NightAuditFailed => __('Night audit failed'),
                NotificationType::NightAuditExceptions => trans_choice(':count exception to review|:count exceptions to review', $summary->exceptions->count(), ['count' => $summary->exceptions->count()]),
                default => __('Night audit completed'),
            },
            'mailEyebrow' => __('Night audit').' · '.$summary->businessDate->toFormattedDateString(),
            'propertyName' => $this->audit->property->name,
            'auditSummary' => $summary,
            'auditFailed' => $this->type === NotificationType::NightAuditFailed,
            'auditExceptions' => $this->exceptionLines($summary),
            'auditFigures' => $this->figures($summary),
        ]);

        foreach ($this->lines($summary) as $line) {
            $mail->line($line);
        }

        return $mail->action(__('View night audit'), $this->url());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $summary = app(AuditResultSummary::class)->forAudit($this->audit);

        return [
            'type' => $this->type->value,
            'title' => $this->subject($summary),
            'night_audit_id' => $this->audit->id,
            'business_date' => $summary->businessDate->toDateString(),
            'open_exceptions' => $summary->exceptions->count(),
            'url' => $this->url(),
        ];
    }

    /**
     * The lines of the message, in order.
     *
     * @return list<string>
     */
    protected function lines(AuditSummary $summary): array
    {
        return [...$this->figures($summary), ...$this->revenueLines($summary), ...$this->paymentLines($summary), ...$this->mealLines($summary), ...$this->exceptionLines($summary)];
    }

    /**
     * @return list<string>
     */
    protected function figures(AuditSummary $summary): array
    {
        $statistics = $summary->statistics;

        if ($statistics === null) {
            return [];
        }

        return [
            __('Occupancy: :occupancy%. ADR: :adr. RevPAR: :revpar.', [
                'occupancy' => $statistics->occupancy,
                'adr' => $this->money($summary, $statistics->adr),
                'revpar' => $this->money($summary, $statistics->revpar),
            ]),
            __('Arrivals: :arrivals. Departures: :departures. No-shows: :noShows. In-house guests: :guests.', [
                'arrivals' => $statistics->arrivals,
                'departures' => $statistics->departures,
                'noShows' => $statistics->no_shows,
                'guests' => $statistics->in_house_guests,
            ]),
        ];
    }

    /**
     * @return list<string>
     */
    protected function revenueLines(AuditSummary $summary): array
    {
        if ($summary->revenue === []) {
            return [];
        }

        return [
            __('Revenue by code:'),
            ...array_map(fn (array $row): string => '- '.$row['name'].': '.$this->money($summary, $row['amount']), $summary->revenue),
        ];
    }

    /**
     * @return list<string>
     */
    protected function paymentLines(AuditSummary $summary): array
    {
        $lines = array_map(fn (array $row): string => '- '.$row['name'].': '.$this->money($summary, $row['amount']), $summary->payments);

        if (! $summary->refunds->isZero()) {
            $lines[] = '- '.__('Refunds').': '.$this->money($summary, $summary->refunds);
        }

        return $lines === [] ? [] : [__('Payments by method:'), ...$lines];
    }

    /**
     * @return list<string>
     */
    protected function mealLines(AuditSummary $summary): array
    {
        if ($summary->meals->isEmpty()) {
            return [];
        }

        return [
            __('Meals to prepare on :date:', ['date' => $summary->mealDate->toFormattedDateString()]),
            ...$summary->meals->map(fn ($meal): string => '- '.$meal->mealPeriodName.': '.$meal->guests)->all(),
        ];
    }

    /**
     * @return list<string>
     */
    protected function exceptionLines(AuditSummary $summary): array
    {
        if ($summary->exceptions->isEmpty()) {
            return [];
        }

        $advice = app(ReadinessAdvice::class);

        return [
            __('Exceptions to review:'),
            ...$summary->exceptions->map(fn ($exception): string => '- '.($exception->reservationUnit?->reservation->reference ?? __('Property')).': '.$exception->type->label().'. '.$advice->reason($exception->message))->all(),
        ];
    }

    protected function money(AuditSummary $summary, Money|string $amount): string
    {
        return $summary->currency.' '.($amount instanceof Money ? $amount->getAmount() : $amount);
    }

    protected function url(): string
    {
        return route('night-audit.show', $this->audit->property->code);
    }
}
