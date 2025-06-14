<?php

namespace App\Providers;

use Illuminate\Support\Facades\DB; // Aggiungi questo use statement
use Illuminate\Support\Facades\Log; // Aggiungi questo use statement
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        //
        DB::listen(function ($query) {
            Log::debug($query->sql, $query->bindings);
        });
    }
}
