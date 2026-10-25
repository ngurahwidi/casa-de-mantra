<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Initial Admin User
    |--------------------------------------------------------------------------
    |
    | Credentials for the first admin account created by AdminUserSeeder.
    | The password is never hardcoded: set ADMIN_PASSWORD in .env.
    |
    */

    'name' => env('ADMIN_NAME', 'Admin'),

    'email' => env('ADMIN_EMAIL'),

    'password' => env('ADMIN_PASSWORD'),

];
