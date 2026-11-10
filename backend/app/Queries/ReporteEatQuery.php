<?php

namespace App\Queries;

use App\Enums\EstadoAsignacion;
use App\Models\Proyecto;
use App\Support\Volumen;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * CU15 Reporte EAT a una fecha de corte: presupuesto, consumido, saldo y proyección a término por
 * partida, más las guías sin sector y los cargos de factura. Las guías sin sector se muestran aparte con
 * un aviso y no suman al total: el saldo de las partidas todavía incluye ese volumen y lo contaría dos
 * veces. Los cargos sí suman. Los montos de cada fila se calculan en
 * SQL con DECIMAL exacto y se redondean con ROUND (la mitad hacia arriba); subtotales y total suman
 * esas filas en céntimos enteros, para que la tabla cuadre al céntimo.
 *
 * Los fragmentos SQL van como [sql, bindings] para encadenarlos sin interpolar valores.
 */
final readonly class ReporteEatQuery
{
    public const MONTOS = [
        'presupuesto_m3', 'presupuesto_soles', 'consumido_m3', 'consumido_soles',
        'saldo_m3', 'saldo_soles', 'eat_m3', 'eat_soles',
    ];

    public const AVISO_SIN_SECTOR = 'Asigne un sector a estas guías: mientras no lo tengan, no suman al total.';

    public function __construct(private Proyecto $proyecto, private string $fechaCorte) {}

    /** @return array<string, mixed>|null null si no hay guías ni cargos hasta el corte */
    public function calcular(): ?array
    {
        $cargos = $this->cargos();
        $hayGuias = DB::table('guias_remision')
            ->where('proyecto_id', $this->proyecto->id)
            ->where('fecha', '<=', $this->fechaCorte)
            ->exists();
        if (! $hayGuias && $cargos->isEmpty()) {
            return null;
        }

        $grupos = [];
        foreach ($this->partidas() as $p) {
            $grupos[$p->grupo_id] ??= ['grupo_id' => (int) $p->grupo_id, 'nombre' => $p->grupo, 'filas' => []];
            $grupos[$p->grupo_id]['filas'][] = $this->filaPartida($p);
        }
        $grupos = array_map(fn (array $g) => [...$g, 'subtotal' => self::sumar($g['filas'])], array_values($grupos));

        $aparte = [...$this->filaGuiasSinSector(), ...$cargos->all()];
        $total = self::sumar([...array_column($grupos, 'subtotal'), ...$cargos->all()]);

        return [
            'proyecto' => ['id' => $this->proyecto->id, 'codigo' => $this->proyecto->codigo, 'nombre' => $this->proyecto->nombre],
            'fecha_corte' => $this->fechaCorte,
            'grupos' => array_map(fn (array $g) => [
                ...$g,
                'filas' => array_map(self::salida(...), $g['filas']),
                'subtotal' => self::salida($g['subtotal']),
            ], $grupos),
            'filas_aparte' => array_map(self::salida(...), $aparte),
            'total' => self::salida($total),
        ];
    }

    private function partidas(): Collection
    {
        $saldo = 'GREATEST(p.metrado_m3 - COALESCE(c.m3, 0), 0)';
        [$precio, $bPrecio] = $this->precioPartida('p');
        [$vigente, $bVigente] = $this->precioVigente('p');

        return DB::table('partidas as p')
            ->join('grupos_partida as gp', 'gp.id', '=', 'p.grupo_id')
            ->join('resistencias as r', 'r.id', '=', 'p.resistencia_id')
            ->leftJoinSub($this->consumoPorPartida(), 'c', 'c.presupuesto_id', '=', 'p.id')
            ->where('p.proyecto_id', $this->proyecto->id)
            ->select(['p.id', 'p.item', 'p.elemento', 'r.nombre as resistencia', 'gp.id as grupo_id', 'gp.nombre as grupo', 'p.metrado_m3'])
            ->selectRaw("ROUND(p.metrado_m3 * {$precio}, 2) AS presupuesto_soles", $bPrecio)
            ->selectRaw('COALESCE(c.m3, 0) AS consumido_m3, COALESCE(c.soles, 0) AS consumido_soles')
            ->selectRaw("{$saldo} AS saldo_m3")
            ->selectRaw("ROUND({$saldo} * {$vigente}, 2) AS proyeccion_soles", $bVigente)
            ->orderBy('gp.orden')->orderBy('gp.id')->orderBy('p.item')->orderBy('p.id')
            ->get();
    }

    /** Asignaciones de guías hasta el corte, por partida (las asignaciones no tienen fecha propia). */
    private function consumoPorPartida(): Builder
    {
        [$precio, $bindings] = $this->precioGuia('g', $this->precioPartida('pc'));

        return DB::table('asignaciones as a')
            ->join('guias_remision as g', 'g.id', '=', 'a.guia_id')
            ->join('sectores as s', 's.id', '=', 'a.sector_id')
            ->join('partidas as pc', 'pc.id', '=', 's.presupuesto_id')
            ->where('g.proyecto_id', $this->proyecto->id)
            ->where('g.fecha', '<=', $this->fechaCorte)
            ->groupBy('s.presupuesto_id')
            ->select('s.presupuesto_id')
            ->selectRaw('SUM(a.volumen_m3) AS m3')
            ->selectRaw("ROUND(SUM(a.volumen_m3 * {$precio}), 2) AS soles", $bindings);
    }

    /** Volumen aún sin sector, con el mismo criterio que la lista de guías sin sector. */
    private function filaGuiasSinSector(): array
    {
        [$precio, $bindings] = $this->precioGuia('g', $this->precioPromedioPresupuestado('g'));
        $pendientes = DB::table('guias_remision as g')
            ->where('g.proyecto_id', $this->proyecto->id)
            ->where('g.fecha', '<=', $this->fechaCorte)
            ->where('g.estado_asignacion', '!=', EstadoAsignacion::Asignada->value)
            ->selectRaw('g.volumen_m3 - COALESCE((SELECT SUM(sa.volumen_m3) FROM asignaciones sa WHERE sa.guia_id = g.id), 0) AS m3')
            ->selectRaw("{$precio} AS precio", $bindings);
        $fila = DB::query()->fromSub($pendientes, 't')
            ->selectRaw('COALESCE(SUM(t.m3), 0) AS m3, ROUND(COALESCE(SUM(t.m3 * t.precio), 0), 2) AS soles')
            ->first();

        $m3 = Volumen::centesimos($fila->m3);
        $soles = self::centimos($fila->soles);
        if ($m3 === 0 && $soles === 0) {
            return [];
        }

        return [[
            'tipo' => 'guias_sin_sector', 'concepto' => 'Guías sin sector',
            ...self::montos(0, 0, $m3, $soles, 0, -$soles, $m3, $soles),
            'sobrecosto' => false, 'aviso' => self::AVISO_SIN_SECTOR,
        ]];
    }

    /** Cargos que no son concreto, de facturas hasta el corte: una fila por descripción. */
    private function cargos(): Collection
    {
        return DB::table('factura_cargos as fc')
            ->join('facturas as f', 'f.id', '=', 'fc.factura_id')
            ->where('f.proyecto_id', $this->proyecto->id)
            ->where('f.fecha', '<=', $this->fechaCorte)
            ->groupBy('fc.descripcion')
            ->selectRaw('MIN(fc.descripcion) AS concepto, SUM(fc.importe) AS importe')
            ->orderBy('concepto')
            ->get()
            ->map(function (object $c) {
                $importe = self::centimos($c->importe);

                return [
                    'tipo' => 'cargo', 'concepto' => $c->concepto,
                    ...self::montos(0, 0, null, $importe, null, -$importe, null, $importe),
                    'sobrecosto' => false,
                ];
            })
            ->reject(fn (array $fila) => $fila['consumido_soles'] === 0)
            ->values();
    }

    /** Precio de una guía: el de su factura; si no, el último facturado; si no, el respaldo dado. */
    private function precioGuia(string $guia, array $respaldo): array
    {
        return self::coalesce($this->precioDeSuFactura($guia), $this->ultimoPrecioFacturado("{$guia}.resistencia_id"), $respaldo);
    }

    /** La línea de su propia factura con su misma resistencia, sea cual sea la fecha de la factura. */
    private function precioDeSuFactura(string $guia): array
    {
        return self::sub(DB::table('factura_lineas as pf')
            ->join('facturas as ff', 'ff.id', '=', 'pf.factura_id')
            ->whereColumn('pf.factura_id', "{$guia}.factura_id")
            ->whereColumn('pf.resistencia_id', "{$guia}.resistencia_id")
            ->where('ff.proyecto_id', $this->proyecto->id)
            ->orderBy('pf.id')
            ->limit(1)
            ->select('pf.precio_unitario'));
    }

    /** La línea más reciente de esa resistencia en las facturas del proyecto hasta el corte. */
    private function ultimoPrecioFacturado(string $resistencia): array
    {
        return self::sub(DB::table('factura_lineas as uf')
            ->join('facturas as fu', 'fu.id', '=', 'uf.factura_id')
            ->whereColumn('uf.resistencia_id', $resistencia)
            ->where('fu.proyecto_id', $this->proyecto->id)
            ->where('fu.fecha', '<=', $this->fechaCorte)
            ->orderByDesc('fu.fecha')->orderByDesc('fu.id')->orderByDesc('uf.id')
            ->limit(1)
            ->select('uf.precio_unitario'));
    }

    /** Para lo que falta ejecutar: el último precio facturado o, si no hay, el de la partida. */
    private function precioVigente(string $partida): array
    {
        return self::coalesce($this->ultimoPrecioFacturado("{$partida}.resistencia_id"), $this->precioPartida($partida));
    }

    /** Respaldo de una guía sin sector: precio presupuestado de su resistencia ponderado por metrado; 0 si no está en el presupuesto. */
    private function precioPromedioPresupuestado(string $guia): array
    {
        [$precio, $bindings] = $this->precioPartida('pm');

        // Escala alta: la división no es exacta y el promedio se multiplica antes de redondear.
        return self::coalesce(self::sub(DB::table('partidas as pm')
            ->where('pm.proyecto_id', $this->proyecto->id)
            ->whereColumn('pm.resistencia_id', "{$guia}.resistencia_id")
            ->selectRaw("CAST(SUM(pm.metrado_m3 * {$precio}) AS DECIMAL(30, 12)) / NULLIF(SUM(pm.metrado_m3), 0)", $bindings)), ['0', []]);
    }

    /** El precio unitario de la partida, que el presupuesto guarda sin IGV. */
    private function precioPartida(string $partida): array
    {
        return ["{$partida}.precio_unitario", []];
    }

    private function filaPartida(object $p): array
    {
        $consumidoM3 = Volumen::centesimos($p->consumido_m3);
        $saldoM3 = Volumen::centesimos($p->saldo_m3);
        $presupuesto = self::centimos($p->presupuesto_soles);
        $consumido = self::centimos($p->consumido_soles);
        $montos = self::montos(
            Volumen::centesimos($p->metrado_m3), $presupuesto, $consumidoM3, $consumido,
            $saldoM3, $presupuesto - $consumido, $consumidoM3 + $saldoM3, $consumido + self::centimos($p->proyeccion_soles),
        );

        return [
            'partida_id' => (int) $p->id, 'item' => $p->item, 'etiqueta' => "{$p->elemento} · {$p->resistencia}",
            ...$montos, 'sobrecosto' => $montos['eat_soles'] > $montos['presupuesto_soles'],
        ];
    }

    /** @param list<array<string, mixed>> $filas */
    private static function sumar(array $filas): array
    {
        $suma = array_map(fn (string $k) => array_sum(array_map(fn (array $f) => $f[$k] ?? 0, $filas)), self::MONTOS);
        $montos = array_combine(self::MONTOS, $suma);

        return [...$montos, 'sobrecosto' => $montos['eat_soles'] > $montos['presupuesto_soles']];
    }

    /** Montos en centésimos (m³) y céntimos (S/); null en los m³ de un cargo. */
    private static function montos(?int ...$valores): array
    {
        return array_combine(self::MONTOS, $valores);
    }

    /** A la respuesta: de centésimos y céntimos enteros a números con 2 decimales. */
    private static function salida(array $fila): array
    {
        foreach (self::MONTOS as $k) {
            $fila[$k] = $fila[$k] === null ? null : (float) ($fila[$k] / 100);
        }

        return $fila;
    }

    /** Un DECIMAL de 2 decimales de MySQL a céntimos enteros. */
    private static function centimos(?string $decimal): int
    {
        return (int) round(((float) $decimal) * 100);
    }

    private static function sub(Builder $consulta): array
    {
        return ['('.$consulta->toSql().')', $consulta->getBindings()];
    }

    private static function coalesce(array ...$fragmentos): array
    {
        return ['COALESCE('.implode(', ', array_column($fragmentos, 0)).')', array_merge(...array_column($fragmentos, 1))];
    }
}
