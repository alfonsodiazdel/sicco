<?php

namespace App\Queries;

use App\Enums\EstadoAsignacion;
use App\Models\Proyecto;
use App\Support\Volumen;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * CU15 Reporte EAT a una fecha de corte: cuánto costará el concreto al término de la obra.
 *
 * Por partida: EAT = costo real de los sectores culminados al corte + proyección de lo que falta.
 * Lo que falta es el metrado neto menos el neto de los sectores culminados, con su desperdicio y al
 * precio vigente; incluye los sectores abiertos, aunque ya tengan concreto, y el metrado aún sin
 * sector. Si un sector abierto ya pasó su límite (neto × (1 + desperdicio)), el exceso se suma a la
 * proyección: el EAT nunca queda debajo de lo ya vaciado. El presupuesto meta lleva el desperdicio,
 * igual que el CU04.
 *
 * Aparte: los cargos de factura (bombeo, aditivos…) suman solo lo facturado hasta el corte; no se
 * proyectan. Las guías sin sector se muestran con un aviso y no suman: ese concreto acabará en un
 * sector abierto, ya proyectado, o en uno culminado, como costo real.
 *
 * Los montos de cada fila se calculan en SQL con DECIMAL exacto y se redondean con ROUND (la mitad
 * hacia arriba); subtotales y total suman esas filas en céntimos enteros, para que la tabla cuadre al
 * céntimo. Los fragmentos SQL van como [sql, bindings] para encadenarlos sin interpolar valores.
 */
final readonly class ReporteEatQuery
{
    public const MONTOS = [
        'presupuesto_m3', 'presupuesto_soles', 'consumido_m3', 'consumido_soles', 'saldo_soles',
        'real_m3', 'real_soles', 'proyectado_m3', 'proyectado_soles', 'eat_m3', 'eat_soles', 'variacion_soles',
    ];

    public const AVISO_CARGOS = 'Los cargos que no son concreto incluyen solo lo facturado hasta la fecha de corte.';

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
        // Volumen por (1 + desperdicio): el factor se guarda en % (5.00 es 5 %).
        $conDesperdicio = fn (string $m3) => "{$m3} * (100 + p.factor_desperdicio) / 100";
        $proyectado = 'ROUND('.$conDesperdicio('GREATEST(p.metrado_m3 - COALESCE(c.neto_culminado, 0), 0)').', 2)'
            .' + COALESCE(c.exceso_abiertos, 0)';
        [$precio, $bPrecio] = $this->precioPartida('cp');
        [$vigente, $bVigente] = $this->precioVigente('cp');

        return DB::table('partidas as p')
            ->join('grupos_partida as gp', 'gp.id', '=', 'p.grupo_id')
            ->join('concretos as cp', 'cp.id', '=', 'p.concreto_id')
            ->join('resistencias as r', 'r.id', '=', 'cp.resistencia_id')
            ->leftJoinSub($this->consumoPorPartida(), 'c', 'c.presupuesto_id', '=', 'p.id')
            ->where('p.proyecto_id', $this->proyecto->id)
            ->select(['p.id', 'p.item', 'p.elemento', 'r.nombre as resistencia', 'gp.id as grupo_id', 'gp.nombre as grupo'])
            ->selectRaw('ROUND('.$conDesperdicio('p.metrado_m3').', 2) AS presupuesto_m3')
            // Como el CU04: el producto exacto, redondeado una vez.
            ->selectRaw('ROUND('.$conDesperdicio('p.metrado_m3')." * {$precio}, 2) AS presupuesto_soles", $bPrecio)
            ->selectRaw('COALESCE(c.m3, 0) AS consumido_m3, ROUND(COALESCE(c.soles, 0), 2) AS consumido_soles')
            ->selectRaw('COALESCE(c.real_m3, 0) AS real_m3, ROUND(COALESCE(c.real_soles, 0), 2) AS real_soles')
            ->selectRaw("{$proyectado} AS proyectado_m3")
            ->selectRaw("ROUND(({$proyectado}) * {$vigente}, 2) AS proyectado_soles", $bVigente)
            ->orderBy('gp.orden')->orderBy('gp.id')->orderBy('p.item')->orderBy('p.id')
            ->get();
    }

    /**
     * Por partida, desde sus sectores: lo vaciado hasta el corte (todo y en los culminados), el neto de
     * los culminados al corte y lo que los abiertos ya pasaron de su límite. Los soles van sin
     * redondear: se redondean una vez por fila.
     */
    private function consumoPorPartida(): Builder
    {
        $culminado = '(s.fecha_culminacion IS NOT NULL AND s.fecha_culminacion <= ?)';
        $limite = 'ROUND(s.volumen_presup_m3 * (100 + ps.factor_desperdicio) / 100, 2)';
        $m3 = 'COALESCE(v.m3, 0)';
        $soles = 'COALESCE(v.soles, 0)';
        $corte = [$this->fechaCorte];

        return DB::table('sectores as s')
            ->join('partidas as ps', 'ps.id', '=', 's.presupuesto_id')
            ->leftJoinSub($this->vaciadoPorSector(), 'v', 'v.sector_id', '=', 's.id')
            ->where('ps.proyecto_id', $this->proyecto->id)
            ->groupBy('s.presupuesto_id')
            ->select('s.presupuesto_id')
            ->selectRaw("SUM({$m3}) AS m3, SUM({$soles}) AS soles")
            ->selectRaw("SUM(CASE WHEN {$culminado} THEN {$m3} ELSE 0 END) AS real_m3", $corte)
            ->selectRaw("SUM(CASE WHEN {$culminado} THEN {$soles} ELSE 0 END) AS real_soles", $corte)
            ->selectRaw("SUM(CASE WHEN {$culminado} THEN s.volumen_presup_m3 ELSE 0 END) AS neto_culminado", $corte)
            ->selectRaw("SUM(CASE WHEN {$culminado} THEN 0 ELSE GREATEST({$m3} - {$limite}, 0) END) AS exceso_abiertos", $corte);
    }

    /** Asignaciones de guías hasta el corte, por sector (las asignaciones no tienen fecha propia). */
    private function vaciadoPorSector(): Builder
    {
        [$precio, $bindings] = $this->precioGuia('g', $this->precioPartida('cc'));

        return DB::table('asignaciones as a')
            ->join('guias_remision as g', 'g.id', '=', 'a.guia_id')
            ->join('sectores as sv', 'sv.id', '=', 'a.sector_id')
            ->join('partidas as pc', 'pc.id', '=', 'sv.presupuesto_id')
            ->join('concretos as cc', 'cc.id', '=', 'pc.concreto_id')
            ->where('g.proyecto_id', $this->proyecto->id)
            ->where('g.fecha', '<=', $this->fechaCorte)
            ->groupBy('a.sector_id')
            ->select('a.sector_id')
            ->selectRaw('SUM(a.volumen_m3) AS m3')
            ->selectRaw("SUM(a.volumen_m3 * {$precio}) AS soles", $bindings);
    }

    /** Volumen aún sin sector, con el mismo criterio que la lista de guías sin sector. */
    private function filaGuiasSinSector(): array
    {
        [$precio, $bindings] = $this->precioGuia('g', $this->precioPresupuestado('g'));
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

        // Solo lo recibido: no entra al EAT ni al total, así que el resto queda vacío.
        return [[
            'tipo' => 'guias_sin_sector', 'concepto' => 'Guías sin sector',
            ...self::montos(
                presupuesto_m3: 0, presupuesto_soles: 0, consumido_m3: $m3, consumido_soles: $soles, saldo_soles: null,
                real_m3: null, real_soles: null, proyectado_m3: null, proyectado_soles: null,
                eat_m3: null, eat_soles: null, variacion_soles: null,
            ),
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

                // Sin volumen y sin proyección: lo facturado es todo su EAT.
                return [
                    'tipo' => 'cargo', 'concepto' => $c->concepto,
                    ...self::montos(
                        presupuesto_m3: 0, presupuesto_soles: 0, consumido_m3: null, consumido_soles: $importe,
                        saldo_soles: -$importe, real_m3: null, real_soles: $importe, proyectado_m3: null,
                        proyectado_soles: 0, eat_m3: null, eat_soles: $importe, variacion_soles: -$importe,
                    ),
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

    /** Para lo que falta ejecutar: el último precio facturado o, si no hay, el del concreto de la partida. */
    private function precioVigente(string $concreto): array
    {
        return self::coalesce($this->ultimoPrecioFacturado("{$concreto}.resistencia_id"), $this->precioPartida($concreto));
    }

    /** Respaldo de una guía sin sector: precio del concreto de su resistencia en el presupuesto; 0 si no está en el presupuesto. */
    private function precioPresupuestado(string $guia): array
    {
        return self::coalesce(self::sub(DB::table('concretos as cm')
            ->where('cm.proyecto_id', $this->proyecto->id)
            ->whereColumn('cm.resistencia_id', "{$guia}.resistencia_id")
            ->whereExists(fn ($q) => $q->from('partidas as pm')->whereColumn('pm.concreto_id', 'cm.id'))
            ->select('cm.precio_unitario')), ['0', []]);
    }

    /** El precio unitario de la partida: el de su concreto, que el presupuesto guarda sin IGV. */
    private function precioPartida(string $concreto): array
    {
        return ["{$concreto}.precio_unitario", []];
    }

    private function filaPartida(object $p): array
    {
        $presupuesto = self::centimos($p->presupuesto_soles);
        $consumido = self::centimos($p->consumido_soles);
        $realM3 = Volumen::centesimos($p->real_m3);
        $real = self::centimos($p->real_soles);
        $proyectadoM3 = Volumen::centesimos($p->proyectado_m3);
        $proyectado = self::centimos($p->proyectado_soles);
        $montos = self::montos(
            presupuesto_m3: Volumen::centesimos($p->presupuesto_m3), presupuesto_soles: $presupuesto,
            consumido_m3: Volumen::centesimos($p->consumido_m3), consumido_soles: $consumido,
            saldo_soles: $presupuesto - $consumido,
            real_m3: $realM3, real_soles: $real, proyectado_m3: $proyectadoM3, proyectado_soles: $proyectado,
            eat_m3: $realM3 + $proyectadoM3, eat_soles: $real + $proyectado,
            variacion_soles: $presupuesto - $real - $proyectado,
        );

        return [
            'partida_id' => (int) $p->id, 'item' => $p->item, 'etiqueta' => "{$p->elemento} · {$p->resistencia}",
            ...$montos, 'sobrecosto' => $montos['variacion_soles'] < 0,
        ];
    }

    /** @param list<array<string, mixed>> $filas */
    private static function sumar(array $filas): array
    {
        $suma = array_map(fn (string $k) => array_sum(array_map(fn (array $f) => $f[$k] ?? 0, $filas)), self::MONTOS);
        $montos = array_combine(self::MONTOS, $suma);

        return [...$montos, 'sobrecosto' => $montos['variacion_soles'] < 0];
    }

    /**
     * Montos en centésimos (m³) y céntimos (S/), por nombre y en el orden de MONTOS; null donde no
     * aplica (los m³ de un cargo, el EAT de las guías sin sector).
     */
    private static function montos(?int ...$valores): array
    {
        $faltan = array_diff(self::MONTOS, array_keys($valores));
        if ($faltan !== [] || count($valores) !== count(self::MONTOS)) {
            throw new \LogicException('Montos del reporte EAT incompletos: '.implode(', ', $faltan));
        }

        return array_map(fn (string $k) => $valores[$k], array_combine(self::MONTOS, self::MONTOS));
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
