<?php

namespace Database\Seeders;

use App\Enums\DefaultRoleEnum;

/**
 * Seeds Pearlside View Resort for the demo environment: the Sandbay Resort data under another name, address and
 * logins. The Owner signs in as `pearlside@demo.estatell.com`, and every staff account uses `DEMO_PASSWORD`. See
 * docs/01-platform/06-demo-properties.md.
 */
class PearlsideSeeder extends SandbaySeeder
{
    protected const string NAME = 'Pearlside View Resort';

    protected function propertyDetails(): array
    {
        return [
            'address_line1' => 'Jalan Pantai Kalampunian',
            'city' => 'Kudat',
            'state_code' => 'Sabah',
            'postal_code' => '89050',
            'contact_email' => 'hello@pearlside.test',
            'contact_phone' => '+6088612345',
        ];
    }

    protected function staffEmail(DefaultRoleEnum $role): string
    {
        return DemoSeeder::loginEmail('pearlside', $role);
    }

    protected function staffPassword(): string
    {
        return DemoSeeder::password();
    }

    protected function printedPassword(): string
    {
        return '(DEMO_PASSWORD)';
    }
}
