<?php

namespace App\Http\Resources;

use App\Models\Alerta;
use App\Services\Desperdicio\CalculadoraDesperdicio;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Alerta de consumo (CU14) con el detalle del paso 5: el sector, su límite y su vaciado actual.
 * Necesita `proyecto`, `sector.nivel`, `sector.partida.resistencia` y `revisor`.
 *
 * @mixin Alerta
 */
class AlertaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $sector = $this->sector;
        $partida = $sector->partida;

        return [
            'id' => $this->id,
            'fecha' => $this->fecha->toIso8601String(),
            'estado' => $this->estado->value,
            'exceso_m3' => (float) $this->exceso_m3,
            'proyecto' => [
                'id' => $this->proyecto->id,
                'codigo' => $this->proyecto->codigo,
                'nombre' => $this->proyecto->nombre,
            ],
            'sector' => [
                'id' => $sector->id,
                'nombre' => $sector->nombre,
                'nivel' => $sector->nivel->nombre,
                'elemento' => $partida->elemento.' · '.$partida->resistencia->nombre,
                'presupuestado_m3' => (float) $sector->volumen_presup_m3,
                'factor_desperdicio' => (float) $partida->factor_desperdicio,
                'limite_m3' => CalculadoraDesperdicio::limiteCentesimos($sector) / 100,
                // El vaciado de hoy: puede haber cambiado desde que se registró la alerta.
                'vaciado_m3' => (float) $sector->volumen_vaciado_m3,
            ],
            'correo_enviado_at' => $this->correo_enviado_at?->toIso8601String(),
            'revisada_at' => $this->revisada_at?->toIso8601String(),
            'revisada_por' => $this->revisor?->nombre_completo,
        ];
    }
}
