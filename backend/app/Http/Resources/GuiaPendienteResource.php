<?php

namespace App\Http\Resources;

use App\Models\GuiaRemision;
use App\Support\Volumen;
use Illuminate\Http\Request;

/**
 * Guía con volumen por asignar (CU10 paso 3). Necesita `withSum('asignaciones', 'volumen_m3')`.
 *
 * @mixin GuiaRemision
 */
class GuiaPendienteResource extends GuiaResource
{
    /** CU10 paso 4: se resaltan en rojo las guías con más de estos días sin asignar. */
    public const DIAS_TOLERANCIA = 2;

    public function toArray(Request $request): array
    {
        $asignado = Volumen::centesimos($this->asignaciones_sum_volumen_m3);
        $dias = self::diasTranscurridos($this->resource);

        return [
            ...parent::toArray($request),
            'volumen_asignado_m3' => $asignado / 100,
            'volumen_pendiente_m3' => (Volumen::centesimos($this->volumen_m3) - $asignado) / 100,
            'dias_transcurridos' => $dias,
            'atrasada' => $dias > self::DIAS_TOLERANCIA,
            // El almacenero puede eliminarla (proyecto equivocado) mientras no se use en otro lado.
            'eliminable' => $asignado === 0 && $this->factura_id === null,
        ];
    }

    /** Días desde la fecha de la guía (el día que llegó el concreto a la obra) hasta hoy. */
    public static function diasTranscurridos(GuiaRemision $guia): int
    {
        return max(0, (int) $guia->fecha->diffInDays(today()));
    }
}
