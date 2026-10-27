<?php

use Illuminate\Support\Facades\Route;

// El frontend es una SPA independiente (frontend/). Aquí solo se expone la API.
Route::get('/', fn () => ['app' => config('app.name'), 'api' => url('/api/v1')]);
