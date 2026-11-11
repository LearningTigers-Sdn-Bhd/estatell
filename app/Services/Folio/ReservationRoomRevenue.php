<?php

namespace App\Services\Folio;

use App\Enums\SystemTransactionCode;
use App\Enums\TransactionKind;
use App\Models\FolioLine;
use App\Models\Reservation;
use Brick\Math\BigDecimal;

/**
 * A reservation's room-related revenue before tax, across its folios: room nights, cancellation and no-show fees and
 * early departure charges, after their discounts and reversals. Tax lines never count. Commission fees uses it as the
 * commission base. See docs/30-commission-fees/02-commission-rules.md#services.
 */
class ReservationRoomRevenue
{
    public const array CODES = [
        SystemTransactionCode::RoomRevenue,
        SystemTransactionCode::CancellationRevenue,
        SystemTransactionCode::NoShowRevenue,
        SystemTransactionCode::EarlyDepartureRevenue,
    ];

    public function forReservation(Reservation $reservation): string
    {
        $charges = FolioLine::query()
            ->select('folio_lines.id')
            ->join('folios', 'folios.id', '=', 'folio_lines.folio_id')
            ->join('transaction_codes', 'transaction_codes.id', '=', 'folio_lines.transaction_code_id')
            ->where('folios.property_id', $reservation->property_id)
            ->where('folios.reservation_id', $reservation->id)
            ->where('folio_lines.kind', TransactionKind::Charge)
            ->whereIn('transaction_codes.system_key', array_map(fn (SystemTransactionCode $code): string => $code->value, self::CODES));

        $sum = FolioLine::query()
            ->where(fn ($query) => $query
                ->whereIn('id', $charges)
                ->orWhere(fn ($discounts) => $discounts
                    ->where('kind', TransactionKind::Adjustment)
                    ->whereIn('parent_line_id', $charges)))
            ->sum('amount');

        return (string) BigDecimal::of((string) $sum)->toScale(2);
    }
}
