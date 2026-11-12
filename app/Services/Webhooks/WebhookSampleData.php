<?php

namespace App\Services\Webhooks;

use App\Enums\WebhookEvent;

/**
 * Made-up `data` in the real shape of each event, for **Send test event**. It never holds a real guest, so a receiver
 * can map fields before the first real event. Keep it in step with the payload builders in `Payloads/`.
 */
class WebhookSampleData
{
    /**
     * @return array<string, mixed>
     */
    public function for(WebhookEvent $event): array
    {
        return match ($event) {
            WebhookEvent::BookingCreated, WebhookEvent::BookingUpdated, WebhookEvent::BookingCancelled, WebhookEvent::BookingNoShow => ['booking' => $this->booking()],
            WebhookEvent::StayCheckedIn, WebhookEvent::StayCheckedOut => ['booking' => $this->booking(), 'unit_id' => 501],
            WebhookEvent::PaymentReceived, WebhookEvent::PaymentRefunded => ['payment' => $this->payment($event), 'booking' => $this->booking()],
            WebhookEvent::FolioClosed => ['folio' => ['id' => 701, 'number' => 'F-000123', 'type' => 'unit', 'status' => 'closed', 'invoice_number' => 'INV-000123', 'unit_id' => 501, 'balance' => '0.00', 'closed_at' => now()->toIso8601String()], 'booking' => $this->booking()],
            WebhookEvent::GuestCreated, WebhookEvent::GuestUpdated => ['guest' => $this->guest()],
            WebhookEvent::GuestMerged => ['guest' => $this->guest(), 'removed_guest_id' => 302],
            WebhookEvent::GuestAnonymized => ['guest' => ['id' => 301]],
            WebhookEvent::RoomCleaned, WebhookEvent::RoomMaintenanceFlagged => ['room' => ['id' => 11, 'number' => '101', 'room_type' => 'Deluxe King', 'housekeeping_status' => 'clean', 'needs_maintenance' => $event === WebhookEvent::RoomMaintenanceFlagged, 'remark' => null]],
            WebhookEvent::NightAuditCompleted => ['night_audit' => ['id' => 81, 'business_date' => now()->subDay()->toDateString(), 'status' => 'completed', 'trigger' => 'scheduled', 'forced' => false]],
            WebhookEvent::ChannelAlert => ['alert' => ['id' => 91, 'event' => 'non_acked_booking', 'label' => 'Not acknowledged booking', 'external_booking_id' => 'BDC-4455', 'revision_id' => 'rev-1', 'created_at' => now()->toIso8601String()]],
        };
    }

    /**
     * @return array{id: int, name: string, email: string, phone: string, country_code: string}
     */
    private function guest(): array
    {
        return ['id' => 301, 'name' => 'Test Guest', 'email' => 'guest@example.com', 'phone' => '+60120000000', 'country_code' => 'MY'];
    }

    /**
     * @return array<string, mixed>
     */
    private function booking(): array
    {
        $arrival = now()->addDays(7);

        return [
            'id' => 401,
            'reference' => 'TEST-0001',
            'short_code' => 'TST001',
            'status' => 'confirmed',
            'source' => 'Phone',
            'external_reference' => null,
            'group_name' => null,
            'company' => null,
            'booker' => $this->guest(),
            'currency' => 'MYR',
            'total' => '360.00',
            'balance' => '360.00',
            'units' => [[
                'id' => 501,
                'status' => 'confirmed',
                'stay_type' => 'overnight',
                'room_type' => 'Deluxe King',
                'rate_plan' => 'Standard',
                'arrival_date' => $arrival->toDateString(),
                'departure_date' => $arrival->addDays(2)->toDateString(),
                'arrival_time' => '15:00',
                'departure_time' => '12:00',
                'adults' => 2,
                'children' => 0,
                'rooms' => ['101'],
                'checked_in_at' => null,
                'checked_out_at' => null,
                'guests' => [[...$this->guest(), 'is_primary' => true]],
            ]],
            'created_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payment(WebhookEvent $event): array
    {
        $refund = $event === WebhookEvent::PaymentRefunded;

        return [
            'id' => $refund ? 602 : 601,
            'amount' => $refund ? '-100.00' : '100.00',
            'purpose' => 'settlement',
            'status' => 'completed',
            'method' => 'Cash',
            'reference' => null,
            'receipt_number' => 'RC-000045',
            'received_on' => now()->toDateString(),
            'received_at' => now()->toIso8601String(),
            'refund_of_id' => $refund ? 601 : null,
            'refund_reason' => $refund ? 'Test refund' : null,
            'voided' => false,
            'folio_number' => 'F-000123',
        ];
    }
}
