<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Platform admin
    |--------------------------------------------------------------------------
    |
    | The first platform admin created by `php artisan db:seed`. The seeder
    | skips the admin when either value is empty.
    |
    */

    'admin_email' => env('ADMIN_SEED_EMAIL'),
    'admin_password' => env('ADMIN_SEED_PASSWORD'),

];
