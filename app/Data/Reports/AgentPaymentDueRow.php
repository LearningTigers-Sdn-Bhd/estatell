<?php

namespace App\Data\Reports;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * One reservation of the Agent payments due report: the first stage of its payment schedule that is not paid yet.
 * The property names are the column keys of `ReportKey::AgentPayments`. `key` is the reservation id. `due_at` is ISO. `overdue` shows **Release rooms** on the page.
 */
class AgentPaymentDueRow extends Data implements ReportRow
{
    public function __construct(
        public int $key,
        public int $reservation_id,
        public string $reservation,
        public string $company,
        public string $arrival,
        public string $stage,
        public string $amount_due,
        public string $due_at,
        public string $status,
        public string $time_zone,
        public bool $overdue = false,
        public bool $stay_ended = false,
    ) {}

    /**
     * @return array<string, string>
     */
    public function cells(): array
    {
        return [
            'reservation' => $this->reservation,
            'company' => $this->company,
            'arrival' => CarbonImmutable::parse($this->arrival)->format('j M Y'),
            'stage' => $this->stage,
            'amount_due' => $this->amount_due,
            'due_at' => CarbonImmutable::parse($this->due_at)->setTimezone($this->time_zone)->format('j M Y, H:i'),
            'status' => $this->status,
        ];
    }

    /**
     * @return array<string, int|float|string>
     */
    public function values(): array
    {
        return [
            'reservation' => $this->reservation,
            'company' => $this->company,
            'arrival' => $this->arrival,
            'stage' => $this->stage,
            'amount_due' => (float) $this->amount_due,
            'due_at' => $this->due_at,
            'status' => $this->status,
        ];
    }
}
