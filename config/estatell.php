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

];
