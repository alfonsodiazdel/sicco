<?php

namespace App\Queries;

use App\Models\Proyecto;
use App\Support\Volumen;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * CU17 Dashboard gerencial: los indicadores de los proyectos activos del usuario en un rango de meses.
 *
 * - Vaciado: lo asignado a sectores de las guías con fecha en el rango (las asignaciones no tienen
 *   fecha propia: cuentan en el mes de su guía, como en el CU16).
 * - Costo: el concreto recibido en el rango valorizado sin IGV como el CU16 (PrecioGuia): no espera a
 *   que llegue la factura.
 * - Desperdicio: acumulado al cierre del rango (DesperdicioAcumulado); el global es ponderado por
 *   volumen, para que una obra pequeña no pese lo mismo que una grande.
 *
 * Se calcula en cada consulta, sin caché: refleja cada guía, asignación o factura ya registrada.
 * Montos en céntimos y volúmenes en centésimos enteros, como los reportes.
 */
final readonly class DashboardQuery
{
    /** @param Collection<int, Proyecto> $proyectos */
    public function __construct(private Collection $proyectos, private string $inicio, private string $cierre) {}

    /** @return array{indicadores: array<string, mixed>, proyectos: list<array<string, mixed>>} */
    public function calcular(): array
    {
        $ids = $this->proyectos->modelKeys();
        $movimientos = $this->movimientos($ids);
        $sectores = DesperdicioAcumulado::sectores($ids, $this->cierre);
        $porProyecto = $sectores->groupBy('proyecto_id');

        $filas = $this->proyectos->map(function (Proyecto $p) use ($movimientos, $porProyecto) {
            $m = $movimientos->get($p->id);
            $desperdicio = DesperdicioAcumulado::resumir($porProyecto->get($p->id, collect()));

            return [
                'id' => $p->id,
                'codigo' => $p->codigo,
                'nombre' => $p->nombre,
                'guias' => (int) ($m->guias ?? 0),
                'recibido_m3' => Volumen::centesimos($m->recibido_m3 ?? 0) / 100,
                'vaciado_m3' => Volumen::centesimos($m->vaciado_m3 ?? 0) / 100,
                'costo_soles' => self::centimos($m->costo_soles ?? 0) / 100,
                'desperdicio' => [...$desperdicio, 'limite_pct' => self::limitePct($desperdicio)],
            ];
        })->values();

        $global = DesperdicioAcumulado::resumir($sectores);

        return [
            'indicadores' => [
                'proyectos_activos' => $filas->count(),
                'vaciado_m3' => $filas->sum(fn (array $f) => (int) round($f['vaciado_m3'] * 100)) / 100,
                'costo_soles' => $filas->sum(fn (array $f) => (int) round($f['costo_soles'] * 100)) / 100,
                'desperdicio_pct' => $global['desperdicio_pct'],
                'limite_pct' => self::limitePct($global),
                'supera_limite' => $global['supera_limite'],
            ],
            'proyectos' => $filas->all(),
        ];
    }

    /**
     * Guías, recibido, vaciado y costo del rango por proyecto.
     *
     * @param  list<int>  $ids
     * @return Collection<int, object>
     */
    private function movimientos(array $ids): Collection
    {
        [$precio, $bindings] = PrecioGuia::expresion('g', $this->cierre);
        $asignado = DB::table('asignaciones')
            ->groupBy('guia_id')
            ->select('guia_id')
            ->selectRaw('SUM(volumen_m3) AS m3');

        return DB::table('guias_remision as g')
            ->leftJoinSub($asignado, 'a', 'a.guia_id', '=', 'g.id')
            ->whereIn('g.proyecto_id', $ids)
            ->whereBetween('g.fecha', [$this->inicio, $this->cierre])
            ->groupBy('g.proyecto_id')
            ->select('g.proyecto_id')
            ->selectRaw('COUNT(*) AS guias')
            ->selectRaw('SUM(g.volumen_m3) AS recibido_m3')
            ->selectRaw('COALESCE(SUM(a.m3), 0) AS vaciado_m3')
            // Cada guía se redondea a céntimos antes de sumar, como en el corte de mes.
            ->selectRaw("SUM(ROUND(g.volumen_m3 * {$precio}, 2)) AS costo_soles", $bindings)
            ->get()
            ->keyBy('proyecto_id');
    }

    /** El límite en %: el factor de desperdicio ponderado de los sectores medidos. */
    private static function limitePct(array $desperdicio): ?float
    {
        if ($desperdicio['presupuestado_m3'] <= 0) {
            return null;
        }

        return round(($desperdicio['limite_m3'] - $desperdicio['presupuestado_m3']) / $desperdicio['presupuestado_m3'] * 100, 2);
    }

    private static function centimos(string|int|float|null $decimal): int
    {
        return (int) round(((float) $decimal) * 100);
    }
}
