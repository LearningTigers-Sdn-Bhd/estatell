<?php

namespace App\Data\Reservations;

use Spatie\LaravelData\Data;

/**
 * What a travel agent booking costs and who pays each part, for the agent and for staff. The agent's part is what lands
 * on a folio a company pays, and the guest's part is what lands on the guest's own folio, so the deposit and balance
 * never ask the agent for money the desk also collects. See docs/15-city-ledger/06-agent-payment-schedule.md#breakdown.
 */
class AgentBillData extends Data
{
    /**
     * @param  list<array{label: string, quantity: int, amount: string}>  $agentLines
     * @param  list<array{label: string, quantity: int, amount: string}>  $guestLines
     */
    public function __construct(
        public array $agentLines,
        public string $agentTotal,
        public array $guestLines,
        public string $guestTotal,
        public string $total,
        public bool $nationalityMissing = false,
    ) {}
}
