<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Detecta consultas N+1 y atributos inexistentes durante el desarrollo.
        Model::shouldBeStrict(! $this->app->isProduction());

        JsonResource::withoutWrapping();

        Password::defaults(fn () => Password::min(8)->letters()->numbers());
    }
}
