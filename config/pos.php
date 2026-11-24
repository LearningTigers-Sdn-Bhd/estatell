<?php

return [
    /*
     | Secret mixed into every staff POS PIN before hashing. It is not APP_KEY, so rotating one never touches the other.
     */
    'pin_pepper' => env('POS_PIN_PEPPER'),

    /*
     | PIN length rules. Standard staff use 4 to 6 digits. Anyone holding a permission in `PosPinPolicy::ELEVATED` needs
     | at least `elevated_min_length` digits, checked when the PIN is saved and on every verification.
     */
    'pin_min_length' => 4,
    'pin_max_length' => 6,
    'elevated_min_length' => 6,

    /*
     | Brute-force limits for PIN switching and Manager PIN overrides. A device lock counts failures per
     | (property, user, terminal device). The user lock counts them per (property, user) across every device.
     */
    /*
     | A blind count within this much of the expected cash (either way) is final. Further off, a manager may allow one recount.
     */
    'cash_variance_tolerance' => '0.00',

    'throttle' => [
        'device_attempts' => 5,
        'device_window_minutes' => 5,
        'device_lock_minutes' => 15,
        'user_attempts' => 12,
        'user_window_minutes' => 15,
        'user_lock_minutes' => 30,
    ],
];
