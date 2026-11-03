<?php

namespace App\Data\Reservations;

use App\Models\Reservation;
use App\Services\Reservations\AgentPaymentSchedule;
use Brick\Math\BigDecimal;
use Spatie\LaravelData\Data;

/**
 * The agent payment schedule card of the reservation page: each stage with its amount, due time (ISO, UTC) and
 * status (paid, due or overdue). Null when the reservation has no schedule.
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

    public static function forReservation(Reservation $reservation, ReservationMoneyData $money): ?self
    {
        if ($reservation->deposit_due_at === null || $reservation->deposit_percent === null) {
            return null;
        }

        $stages = app(AgentPaymentSchedule::class)->stagesFrom($reservation, BigDecimal::of($money->total), BigDecimal::of($money->paid));

        return new self(
            array_map(fn (array $stage): array => [
                'label' => match ($stage['kind']) {
                    'deposit' => __('Deposit :percent%', ['percent' => $reservation->deposit_percent]),
                    'balance' => __('Balance'),
                    default => __('Full payment'),
                },
                'amount' => $stage['amount'],
                'dueAt' => $stage['due_at']->toIso8601String(),
                'status' => $stage['paid'] ? 'paid' : ($stage['overdue'] ? 'overdue' : 'due'),
            ], $stages),
            $reservation->deposit_percent,
        );
    }
}
