<?php

namespace App\Actions\Folio;

use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\ReservationUnit;
use App\Models\UnitNight;
use App\Models\User;
use App\Services\Folio\NightQuote;
use App\Services\Folio\QuotedDiscount;

class PostNightLines
{
    public function __construct(
        private CreateFolio $createFolio,
        private PostFolioCharge $postFolioCharge,
    ) {}

    /**
     * Post the charges of a checked night quote, with no checks. Each charge goes to its folio in `$targets` (keyed by
     * the charge key, as `FolioRouter` chose it), or to the unit folio, which is created when the unit has none.
     * `$roomDescription` replaces the description of the room line (the first charge). A discount with no code of its
     * own takes `$stayDiscountCodeId`. The caller checks the quote, the folios and the codes first, inside its own
     * transaction: `PostRoomCharges` and `ChangeNightPrice`. See docs/13-folio/02-posting-charges.md.
     *
     * @param  array<string, Folio|null>  $targets
     * @return list<FolioLine>
     */
    public function __invoke(?User $actor, ReservationUnit $unit, UnitNight $night, NightQuote $quote, array $targets, string $postingDate, ?int $stayDiscountCodeId, ?string $roomDescription = null): array
    {
        $unitFolio = null;
        $result = [];

        foreach ($quote->charges as $index => $charge) {
            $folio = $targets[$charge->key] ?? $unitFolio ??= ($this->createFolio)($unit->reservation, $unit);
            $discounts = array_map(
                fn (QuotedDiscount $discount): QuotedDiscount => new QuotedDiscount($discount->key, $discount->description, $discount->amount, $discount->transactionCodeId ?? $stayDiscountCodeId),
                $charge->discounts,
            );
            $lines = ($this->postFolioCharge)(
                $actor,
                $folio,
                $charge->code,
                $index === 0 && $roomDescription !== null ? $roomDescription : $charge->description,
                1,
                $charge->unitAmount,
                $charge->tax,
                $charge->key,
                $postingDate,
                $night,
                $charge->lineAmount,
                $discounts,
            );
            array_push($result, ...$lines);
        }

        return $result;
    }
}
