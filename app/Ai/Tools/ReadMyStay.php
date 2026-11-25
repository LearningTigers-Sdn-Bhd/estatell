<?php

namespace App\Ai\Tools;

use App\Enums\UnitStatus;
use App\Models\AiThread;
use App\Services\Concierge\ConciergeBill;
use App\Services\Kiosk\KioskStays;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * The guest's own stay: dates, room, guests and the balance due. It reads only the stay its thread was opened for, which
 * the stay link set; it takes no input, so the model cannot ask for another stay. The room number (of the latest night so far, so
 * after a room move too) is given only to a checked-in guest, as on the stay page. See docs/35-ai-assistant/02-agent-and-tools.md#tools.
 */
class ReadMyStay implements Tool
{
    public function __construct(
        private readonly AiThread $thread,
        private readonly ConciergeBill $bill,
        private readonly KioskStays $stays,
    ) {}

    public function description(): Stringable|string
    {
        return 'Read the guest\'s own stay: arrival and departure dates, room type, room number once checked in, guests, status and the balance still due. Use it before you answer about their booking or bill. Wi-Fi details are on the stay page, not here.';
    }

    public function handle(Request $request): Stringable|string
    {
        $stay = $this->thread->reservationUnit()->with(['reservation', 'roomType', 'property.settings'])->first();

        if ($stay === null) {
            return $this->json(['found' => false, 'note' => 'This chat has no stay. Hand over if the guest asks about a booking.']);
        }

        return $this->json([
            'found' => true,
            'reference' => $stay->reservation->short_code,
            'status' => $stay->status->label(),
            'arrival_date' => $stay->arrival_date->toDateString(),
            'departure_date' => $stay->departure_date->toDateString(),
            'nights' => (int) $stay->arrival_date->diffInDays($stay->departure_date),
            'room_type' => $stay->roomType->name,
            'room_number' => $stay->status === UnitStatus::CheckedIn ? $this->stays->roomNumber($stay, checkOut: true) : null,
            'adults' => $stay->adults,
            'children' => $stay->children,
            'balance_due' => $this->bill->due($stay),
            'currency' => $stay->property->settings->currency->getCurrencyCode(),
            'note' => 'The guest pays or checks out on the stay page.',
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function json(array $data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
