<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Admin two-factor
    |--------------------------------------------------------------------------
    |
    | When true, platform admins must confirm two-factor (or a passkey) before
    | they can use admin actions such as impersonation or platform settings.
    | Signing in and viewing /admin stay open. On by default.
    |
    */

    'admin_require_two_factor' => (bool) env('ADMIN_REQUIRE_2FA', true),

];
