<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Fotos leídas (CU06) que el almacenero no llegó a registrar.
Schedule::call(function () {
    $limite = now()->subDay()->getTimestamp();
    foreach (Storage::allFiles('guias/temporal') as $archivo) {
        if (Storage::lastModified($archivo) < $limite) {
            Storage::delete($archivo);
        }
    }
})->daily()->name('guias:limpiar-fotos-temporales');
