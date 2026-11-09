<?php

use App\Jobs\EnviarAlertaConsumo;
use App\Models\Alerta;
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

// CU14 flujo alternativo 1: alertas cuyo correo no salió tras los reintentos de la cola. Se toman
// las de la última semana con más de dos horas, para no cruzarse con los reintentos en curso.
Artisan::command('alertas:reenviar-correos', function () {
    $ids = Alerta::whereNull('correo_enviado_at')
        ->whereBetween('created_at', [now()->subWeek(), now()->subHours(2)])
        ->pluck('id');
    $ids->each(fn (int $id) => EnviarAlertaConsumo::dispatch($id));
    $this->info("Alertas reencoladas: {$ids->count()}");
})->purpose('Vuelve a encolar el correo de las alertas de consumo que no se pudieron enviar');

Schedule::command('alertas:reenviar-correos')->hourly();
