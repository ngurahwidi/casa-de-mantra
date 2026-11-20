<?php

namespace App\Listeners;

use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\DB;

/**
 * Makes the /up health route answer 500 when the database cannot be reached, so an uptime
 * monitor pointed at /up notices a database outage as well as a server one (T12.4).
 *
 * Laravel's health route catches the exception, reports it and renders the "down" page.
 */
class CheckDatabaseOnHealthRoute
{
    public function handle(DiagnosingHealth $event): void
    {
        DB::select('select 1');
    }
}
