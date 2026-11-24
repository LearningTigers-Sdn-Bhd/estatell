<?php

namespace App\Actions\Concierge;

use App\Data\Kiosk\KioskIdentityData;
use App\Data\Kiosk\KioskWalkInData;
use App\Events\ConciergeQuoteBooked;
use App\Models\ConciergeQuote;
use App\Models\Property;
use App\Services\Kiosk\KioskWalkIn;
use App\Services\Kiosk\KioskWalkInOffer;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Book a quote link once the guest has given who they are, the same way as a Concierge walk-in (`ConfirmConciergeWalkIn`)
 * with the quote's room type, plan and dates. The quote is locked, so it books once. Refused, writing nothing, with
 * `quote_used`, `quote_expired`, `no_room_available` (the plan is no longer sold online or the room has gone), or any
 * walk-in refusal. See docs/34-ai-assistant/03-places-and-routes.md#quote-links.
 */
class ConfirmConciergeQuote
{
    public function __construct(private ConfirmConciergeWalkIn $confirm, private KioskWalkIn $walkIn) {}

    public function withIdentity(ConciergeQuote $quote, KioskIdentityData $data, ?UploadedFile $front = null, ?UploadedFile $back = null): ConciergeWalkInBooked
    {
        return $this->book($quote, fn (Property $property, KioskWalkInData $search, ConciergeQuote $locked): ConciergeWalkInBooked => $this->confirm->withIdentity($property, $search, $locked->room_type_id, $data, $front, $back, $locked->rate_plan_id, quoted: true));
    }

    public function returning(ConciergeQuote $quote, string $contact): ConciergeWalkInBooked
    {
        return $this->book($quote, fn (Property $property, KioskWalkInData $search, ConciergeQuote $locked): ConciergeWalkInBooked => $this->confirm->returning($property, $search, $locked->room_type_id, $contact, $locked->rate_plan_id, quoted: true));
    }

    /**
     * @param  Closure(Property, KioskWalkInData, ConciergeQuote): ConciergeWalkInBooked  $confirm
     */
    private function book(ConciergeQuote $quote, Closure $confirm): ConciergeWalkInBooked
    {
        $result = DB::transaction(function () use ($quote, $confirm): ConciergeWalkInBooked {
            $locked = ConciergeQuote::query()->whereKey($quote->id)->lockForUpdate()->firstOrFail();

            if ($locked->reservation_id !== null) {
                return ConciergeWalkInBooked::refused('quote_used');
            }

            if (! $locked->expires_at->isFuture()) {
                return ConciergeWalkInBooked::refused('quote_expired');
            }

            $property = Property::query()->with('settings')->findOrFail($locked->property_id);
            $search = new KioskWalkInData($locked->arrival_date, (int) $locked->arrival_date->diffInDays($locked->departure_date), $locked->adults);
            $sold = array_find($this->walkIn->quotable($property, $search, $locked->room_type_id, $locked->rate_plan_id), fn (KioskWalkInOffer $offer): bool => $offer->available);

            if ($sold === null) {
                return ConciergeWalkInBooked::refused('no_room_available');
            }

            $booked = $confirm($property, $search, $locked);

            if ($booked->stay !== null) {
                $locked->update(['reservation_id' => $booked->stay->reservation_id]);
                $quote->setRawAttributes($locked->getAttributes(), true);
            }

            return $booked;
        });

        if ($result->stay !== null) {
            ConciergeQuoteBooked::dispatch($quote->property_id, $result->stay->reservation_id, $quote->ai_turn_id);
        }

        return $result;
    }
}
