<?php

namespace App\Services\Desperdicio;

use App\Models\Proyecto;
use App\Models\Sector;
use App\Support\Volumen;
use Illuminate\Support\Collection;

/**
 * CU13: presupuestado frente a vaciado por elemento, nivel o sector.
 * Desperdicio % = (vaciado − presupuestado) / presupuestado × 100.
 * Límite de un sector = presupuestado × (1 + factor de su partida): el mismo para CU09, CU13 y CU14.
 * Se opera en centésimos enteros, como el resto de volúmenes (ver Volumen).
 */
class CalculadoraDesperdicio
{
    public const DETALLES = ['elemento', 'nivel', 'sector'];

    /** Límite del sector en centésimos de m³; el factor está en % y el medio centésimo sube. */
    public static function limiteCentesimos(Sector $sector): int
    {
        $factor = (float) $sector->partida->factor_desperdicio;

        return (int) round(Volumen::centesimos($sector->volumen_presup_m3) * (100 + $factor) / 100);
    }

    /** El sector ya pasa lo presupuestado más el desperdicio (CU09 flujo alternativo 2, CU14). */
    public static function excede(Sector $sector): bool
    {
        return Volumen::centesimos($sector->volumen_vaciado_m3) > self::limiteCentesimos($sector);
    }

    /**
     * @return array{detalle: string, hay_vaciados: bool, filas: list<array<string, mixed>>}
     */
    public function calcular(Proyecto $proyecto, string $detalle): array
    {
        $sectores = Sector::query()
            ->whereHas('nivel', fn ($q) => $q->where('proyecto_id', $proyecto->id))
            ->with(['nivel', 'partida.resistencia'])
            ->get()
            ->sortBy(fn (Sector $s) => sprintf('%05d-%010d-%010d', $s->nivel->orden, $s->nivel_id, $s->id))
            ->values();

        $grupos = match ($detalle) {
            'elemento' => $sectores->groupBy('presupuesto_id'),
            'nivel' => $sectores->groupBy('nivel_id'),
            default => $sectores->map(fn (Sector $s) => collect([$s])),
        };

        return [
            'detalle' => $detalle,
            'hay_vaciados' => $sectores->contains(fn (Sector $s) => Volumen::centesimos($s->volumen_vaciado_m3) > 0),
            'filas' => $grupos->map(fn (Collection $g) => $this->fila($g, $detalle))->values()->all(),
        ];
    }

    /** @param Collection<int, Sector> $sectores */
    private function fila(Collection $sectores, string $detalle): array
    {
        $primero = $sectores->first();
        $partida = $primero->partida;
        $presupuestado = $sectores->sum(fn (Sector $s) => Volumen::centesimos($s->volumen_presup_m3));
        $vaciado = $sectores->sum(fn (Sector $s) => Volumen::centesimos($s->volumen_vaciado_m3));
        // Agrupado, el límite es la suma de los límites de sus sectores.
        $limite = $sectores->sum(fn (Sector $s) => self::limiteCentesimos($s));

        $elemento = $partida->elemento.' · '.$partida->resistencia->nombre;

        return [
            // Los nombres se repiten (un «Sector A» por nivel, dos partidas del mismo elemento y f'c).
            'id' => match ($detalle) {
                'elemento' => "elemento-{$partida->id}",
                'nivel' => "nivel-{$primero->nivel_id}",
                default => "sector-{$primero->id}",
            },
            'etiqueta' => match ($detalle) {
                'elemento' => $elemento,
                'nivel' => $primero->nivel->nombre,
                default => $primero->nombre,
            },
            'nivel' => $detalle === 'elemento' ? null : $primero->nivel->nombre,
            'elemento' => $detalle === 'nivel' ? null : $elemento,
            // El ítem es opcional; la descripción distingue a la partida cuando falta.
            'item' => $detalle === 'nivel' ? null : $partida->item,
            'descripcion' => $detalle === 'nivel' ? null : $partida->descripcion,
            'presupuestado_m3' => $presupuestado / 100,
            'vaciado_m3' => $vaciado / 100,
            'diferencia_m3' => ($vaciado - $presupuestado) / 100,
            // Sin vaciados aún no hay desperdicio que medir: evita mostrar un −100 % engañoso.
            // Vaciar menos de lo presupuestado no es desperdicio negativo: el % parte de 0.
            'desperdicio_pct' => $vaciado > 0 && $presupuestado > 0
                ? max(0, round(($vaciado - $presupuestado) / $presupuestado * 100, 2))
                : null,
            'limite_m3' => $limite / 100,
            'supera_limite' => $vaciado > $limite,
        ];
    }
}
