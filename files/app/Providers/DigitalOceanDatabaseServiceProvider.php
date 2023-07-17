<?php

namespace App\Providers;

use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class DigitalOceanDatabaseServiceProvider extends ServiceProvider
{
    public function boot()
    {
        Event::listen(MigrationsStarted::class, function () {
            if (config('database.allow_disabled_pk')) {
                DB::statement('SET SESSION sql_require_primary_key=0');
            }
        });

        Event::listen(MigrationsEnded::class, function () {
            if (config('database.allow_disabled_pk')) {
                DB::statement('SET SESSION sql_require_primary_key=1');
            }
        });
    }
}
