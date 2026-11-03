<?php

namespace App\Data\Reservations;

use App\Enums\PaymentProofStatus;
use App\Models\PaymentProof;
use App\Models\Reservation;
use App\Services\Reservations\AgentPaymentSchedule;
use Brick\Math\BigDecimal;
use Spatie\LaravelData\Data;

/**
 * The agent payment schedule card of the reservation page and the portal booking: each stage with its amount, due
 * time (ISO, UTC) and status (paid, proof, due or overdue; `proof` when the agent sent a proof staff have not checked
 * yet). Null when the reservation has no schedule.
 */
class PaymentScheduleData extends Data
{
    /**
     * @param  list<array{label: string, amount: string, dueAt: string, status: string}>  $stages
     */
    public function __construct(
        public array $stages,
        public int $depositPercent,
    ) {}

    public static function forReservation(Reservation $reservation, ?ReservationMoneyData $money = null): ?self
    {
        if ($reservation->deposit_due_at === null || $reservation->deposit_percent === null) {
            return null;
        }

        $schedule = app(AgentPaymentSchedule::class);
        $stages = $money === null
            ? $schedule->stages($reservation)
            : $schedule->stagesFrom($reservation, BigDecimal::of($money->total), BigDecimal::of($money->paid));
        $proofSent = PaymentProof::query()->where('reservation_id', $reservation->id)->where('status', PaymentProofStatus::Submitted)->exists();

        return new self(
            array_map(fn (array $stage): array => [
                'label' => match ($stage['kind']) {
                    'deposit' => __('Deposit :percent%', ['percent' => $reservation->deposit_percent]),
                    'balance' => __('Balance'),
                    default => __('Full payment'),
                },
                'amount' => $stage['amount'],
                'dueAt' => $stage['due_at']->toIso8601String(),
                'status' => match (true) {
                    $stage['paid'] => 'paid',
                    $proofSent => 'proof',
                    $stage['overdue'] => 'overdue',
                    default => 'due',
                },
            ], $stages),
            $reservation->deposit_percent,
        );
    }
}
