<?php

namespace App\Queries;

use App\Support\Volumen;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Desperdicio acumulado al cierre (CU16 S1.5, CU17): los sectores culminados hasta el cierre y los
 * abiertos que ya pasaron lo presupuestado; los demás abiertos aún no tienen desperdicio que medir.
 * El vaciado cuenta las asignaciones de guías con fecha hasta el cierre.
 */
final class DesperdicioAcumulado
{
    /**
     * @param  list<int>  $proyectos
     * @return Collection<int, object{proyecto_id: int, volumen_presup_m3: string, factor_desperdicio: string, vaciado_m3: string}>
     */
    public static function sectores(array $proyectos, string $cierre): Collection
    {
        $vaciado = DB::table('asignaciones as a')
            ->join('guias_remision as g', 'g.id', '=', 'a.guia_id')
            ->whereIn('g.proyecto_id', $proyectos)
            ->where('g.fecha', '<=', $cierre)
            ->groupBy('a.sector_id')
            ->select('a.sector_id')
            ->selectRaw('SUM(a.volumen_m3) AS m3');

        return DB::table('sectores as s')
            ->join('partidas as p', 'p.id', '=', 's.presupuesto_id')
            ->leftJoinSub($vaciado, 'v', 'v.sector_id', '=', 's.id')
            ->whereIn('p.proyecto_id', $proyectos)
            ->where(fn ($q) => $q
                ->where('s.fecha_culminacion', '<=', $cierre)
                ->orWhereRaw('COALESCE(v.m3, 0) > s.volumen_presup_m3'))
            ->select(['p.proyecto_id', 's.volumen_presup_m3', 'p.factor_desperdicio'])
            ->selectRaw('COALESCE(v.m3, 0) AS vaciado_m3')
            ->get();
    }

    /**
     * Suma los sectores: el % es ponderado por volumen, Σ(vaciado − presupuestado) / Σ presupuestado.
     *
     * @param  Collection<int, object>  $sectores
     */
    public static function resumir(Collection $sectores): array
    {
        $presupuestado = $sectores->sum(fn (object $s) => Volumen::centesimos($s->volumen_presup_m3));
        $vaciado = $sectores->sum(fn (object $s) => Volumen::centesimos($s->vaciado_m3));
        // El mismo límite por sector que CU09, CU13 y CU14: presupuestado × (1 + factor).
        $limite = $sectores->sum(fn (object $s) => (int) round(
            Volumen::centesimos($s->volumen_presup_m3) * (100 + (float) $s->factor_desperdicio) / 100));

        return [
            'sectores' => $sectores->count(),
            'presupuestado_m3' => $presupuestado / 100,
            'vaciado_m3' => $vaciado / 100,
            'diferencia_m3' => ($vaciado - $presupuestado) / 100,
            // Como el CU13: vaciar menos de lo presupuestado no es desperdicio negativo.
            'desperdicio_pct' => $presupuestado > 0 && $vaciado > 0
                ? max(0, round(($vaciado - $presupuestado) / $presupuestado * 100, 2))
                : null,
            'limite_m3' => $limite / 100,
            'supera_limite' => $vaciado > $limite,
        ];
    }
}
