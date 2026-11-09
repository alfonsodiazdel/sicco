<?php

namespace App\Services\Alertas;

use App\Enums\EstadoAlerta;
use App\Jobs\EnviarAlertaConsumo;
use App\Models\Alerta;
use App\Models\Sector;
use App\Services\Desperdicio\CalculadoraDesperdicio;
use App\Support\Volumen;

/**
 * CU14 pasos 1–3: registra la alerta de un sector que pasó su límite y encola el correo.
 *
 * Se llama dentro de la transacción de la asignación (CU09), con el sector ya bloqueado y su
 * volumen vaciado actualizado. Hay a lo más una alerta pendiente por sector (arquitectura §6.6):
 * - Si ya hay una pendiente, solo se actualiza su exceso; no se repite el correo.
 * - Si no la hay, se crea solo cuando esta asignación subió el vaciado del sector. Bajar el
 *   vaciado (una corrección) no genera alertas nuevas.
 * - Si una corrección deja al sector bajo el límite, la alerta pendiente se conserva: es el
 *   registro de que pasó, y la cierra el usuario al marcarla como revisada.
 */
class RegistrarAlertaConsumo
{
    /** @param  bool  $subioVaciado  esta asignación aumentó el volumen vaciado del sector */
    public function evaluar(Sector $sector, bool $subioVaciado): ?Alerta
    {
        $sector->loadMissing(['partida', 'nivel']);
        $exceso = self::excesoCentesimos($sector);
        if ($exceso <= 0) {
            return null;
        }

        $pendiente = Alerta::where('sector_id', $sector->id)->pendientes()->first();
        if ($pendiente) {
            $pendiente->update(['exceso_m3' => $exceso / 100]);

            return $pendiente;
        }

        if (! $subioVaciado) {
            return null;
        }

        $alerta = Alerta::create([
            'proyecto_id' => $sector->nivel->proyecto_id,
            'sector_id' => $sector->id,
            'exceso_m3' => $exceso / 100,
            'fecha' => now(),
            'estado' => EstadoAlerta::Pendiente,
        ]);

        // El correo sale solo si la asignación se confirma.
        EnviarAlertaConsumo::dispatch($alerta->id)->afterCommit();

        return $alerta;
    }

    /** m³ (en centésimos) que el vaciado pasa del límite presupuestado × (1 + factor). */
    public static function excesoCentesimos(Sector $sector): int
    {
        return Volumen::centesimos($sector->volumen_vaciado_m3) - CalculadoraDesperdicio::limiteCentesimos($sector);
    }
}
