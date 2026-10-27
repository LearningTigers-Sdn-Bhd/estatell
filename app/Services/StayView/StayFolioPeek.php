<?php

namespace App\Services\StayView;

use App\Data\StayView\StayFolioData;
use App\Data\StayView\StayFolioLineData;
use App\Enums\FolioStatus;
use App\Models\FolioLine;
use App\Models\Reservation;
use App\Services\Folio\FolioBalance;

/**
 * Reads the latest posted folio lines of a reservation for the Stay View peek sheet. Voided folios are left out. The
 * full folio stays on the reservation page. It never writes.
 */
class StayFolioPeek
{
    /**
     * How many lines the peek lists.
     */
    public const LIMIT = 15;

    public function __construct(private FolioBalance $folioBalance) {}

    public function for(Reservation $reservation): StayFolioData
    {
        $lines = FolioLine::query()
            ->whereIn('folio_id', $reservation->folios()->where('status', '!=', FolioStatus::Voided)->select('id'))
            ->orderByDesc('posting_date')->orderByDesc('id')
            ->limit(self::LIMIT + 1)
            ->get();

        return new StayFolioData(
            balance: $this->folioBalance->forReservations([$reservation->id])[$reservation->id],
            lines: array_values($lines->take(self::LIMIT)->map(fn (FolioLine $line): StayFolioLineData => StayFolioLineData::fromModel($line))->all()),
            hasMore: $lines->count() > self::LIMIT,
        );
    }
}
