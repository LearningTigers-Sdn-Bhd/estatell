<?php

namespace App\Http\Resources\Booking\V1;

use App\Services\BookingEngine\BookingOffer;
use App\Services\BookingEngine\OnlinePrice;
use App\Services\BookingEngine\OnlineTaxLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One room or bed on one rate plan. `pay_online` and `pay_at_property` are null when the offer is blocked.
 *
 * @property BookingOffer $resource
 */
class BookingOfferResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $offer = $this->resource;
        $price = $offer->price;
        $policy = $offer->cancellationPolicy;

        return [
            'room_type' => [
                'id' => $offer->roomType->id,
                'name' => $offer->roomType->name,
                'sell_unit' => $offer->roomType->sell_unit->value,
            ],
            'rate_plan' => [
                'id' => $offer->ratePlan->id,
                'name' => $offer->ratePlan->name,
            ],
            'rooms_left' => $offer->roomsLeft,
            'currency' => $price?->onlineTotal?->getCurrency()->getCurrencyCode(),
            'pay_online' => $price === null ? null : $this->payOnline($price),
            'pay_at_property' => $price === null ? null : [
                'taxes' => $this->taxes($price->deskTaxes),
                'total' => (string) $price->deskTotal?->getAmount(),
            ],
            'cancellation_terms' => $policy === null ? null : [
                'charge_mode' => $policy->charge_mode->value,
                'amount' => $policy->amount,
                'percent' => $policy->percent,
                'percent_basis' => $policy->percent_basis?->value,
                'applies_to' => $policy->applies_to->value,
                'free_until_days' => $policy->free_until_days,
                'text_en' => $policy->text_en,
                'text_ms' => $policy->text_ms,
            ],
            'blocked' => $offer->blockedCode === null ? null : [
                'code' => $offer->blockedCode,
                'message' => $offer->blockedMessage,
            ],
        ];
    }

    /**
     * @return array{subtotal: string, taxes: list<array{name: string, amount: string, inclusive: bool}>, total: string}
     */
    private function payOnline(OnlinePrice $price): array
    {
        return [
            'subtotal' => (string) $price->onlineSubtotal?->getAmount(),
            'taxes' => $this->taxes($price->onlineTaxes),
            'total' => (string) $price->onlineTotal?->getAmount(),
        ];
    }

    /**
     * @param  list<OnlineTaxLine>  $lines
     * @return list<array{name: string, amount: string, inclusive: bool}>
     */
    private function taxes(array $lines): array
    {
        return array_map(fn (OnlineTaxLine $line): array => [
            'name' => $line->name,
            'amount' => (string) $line->amount->getAmount(),
            'inclusive' => $line->inclusive,
        ], $lines);
    }
}
