<?php

/*
 * Only the settings Estatell changes. Sanctum's defaults fill in the rest. Estatell uses API tokens only, never Sanctum's
 * cookie sign-in for a single-page app. See docs/28-api-access/01-api-tokens.md.
 */
return [

    // A staff session never stands in for a token.
    'guard' => [],

    // No CSRF cookie route, because only tokens are used.
    'routes' => false,

    // RecordKioskUse stamps last use on the kiosk, at most once a minute.
    'last_used_at' => false,

    'expiration' => null,

    // Secret scanners can spot a leaked token.
    'token_prefix' => 'est_live_',

];
