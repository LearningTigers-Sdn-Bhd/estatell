<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Support two-factor
    |--------------------------------------------------------------------------
    |
    | When true, platform admins must confirm two-factor (or a passkey) before
    | they can use admin actions such as impersonation or platform settings.
    | Signing in and viewing /admin stay open. Off by default: two-factor is
    | optional and admins see a dismissible reminder instead.
    |
    */

    'support_require_two_factor' => (bool) env('SUPPORT_REQUIRE_2FA', false),

    /*
    |--------------------------------------------------------------------------
    | Demo environment
    |--------------------------------------------------------------------------
    |
    | demo.estatell.com, reset every night. The demo uses the password to seed
    | its staff accounts. Production shows the link, the Owner logins and the
    | password on the partner Demo access card, only when the URL is set.
    | See docs/deployment/demo_environment.md.
    |
    */

    'demo' => [
        'url' => env('DEMO_URL'),
        'password' => env('DEMO_PASSWORD'),
        'logins' => [
            ['property' => 'Pearlside View Resort', 'email' => 'pearlside@demo.estatell.com'],
            ['property' => 'Boreno Hotel', 'email' => 'boreno@demo.estatell.com'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User docs
    |--------------------------------------------------------------------------
    |
    | The staff user guide (source in the estatell-docs repo). Staff pages link
    | to its English pages for now, even though it has Malay and Chinese too.
    |
    */

    'docs_url' => env('DOCS_URL', 'https://docs.estatell.com'),

    /*
    |--------------------------------------------------------------------------
    | Booking website address
    |--------------------------------------------------------------------------
    |
    | The base address of the booking microsite. A property's site is at this
    | address plus its slug. See docs/29-booking-engine/08-microsite.md.
    |
    */

    'booking_site_url' => env('BOOKING_SITE_URL', 'https://booking.estatell.com'),

    /*
    |--------------------------------------------------------------------------
    | MyKad Reader download
    |--------------------------------------------------------------------------
    |
    | The released version that /downloads/mykad-reader/{platform} sends staff
    | to. The check-in chip and the user docs both use that link. Tag the
    | release first. See docs/11-guests/05-mykad-reader.md.
    |
    */

    'mykad_reader_version' => env('MYKAD_READER_VERSION', '2.0.0'),

];
