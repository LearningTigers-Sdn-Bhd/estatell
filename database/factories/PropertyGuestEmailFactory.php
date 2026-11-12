<?php

namespace Database\Factories;

use App\Enums\GuestEmail;
use App\Models\Property;
use App\Models\PropertyGuestEmail;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PropertyGuestEmail>
 */
class PropertyGuestEmailFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'email' => GuestEmail::EInvoice,
            'subject' => 'Your e-invoice from {hotel_name}',
            'body' => "Hello {guest_name},\n\nHere is your e-invoice {invoice_number}.",
        ];
    }
}
