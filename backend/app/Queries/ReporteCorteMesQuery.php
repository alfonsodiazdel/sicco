<?php

namespace App\Queries;

use App\Models\Proyecto;
use App\Support\Volumen;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * CU16 Reporte de corte a fin de mes de un proyecto: lo recibido, vaciado y facturado en el mes, lo
 * pendiente de facturar al cierre y el desperdicio acumulado, en m³ y en soles sin IGV.
 *
 * - Recibido y vaciado son del mes: guías con fecha en el mes y lo que de ellas se asignó a sectores
 *   (las asignaciones no tienen fecha propia: cuentan en el mes de su guía).
 * - Facturado es del mes: facturas con fecha en el mes; su subtotal incluye los cargos que no son
 *   concreto (bombeo, aditivos…), su volumen solo el concreto.
 * - Pendiente es acumulado al cierre, para provisionarlo: guías con fecha hasta el cierre que no
 *   tenían una factura fechada hasta ese día. Se valoriza como el EAT (CU15): al precio de su factura
 *   si ya la tiene; si no, al último precio facturado de su resistencia hasta el cierre; si no, al
 *   precio presupuestado de esa resistencia.
 * - Desperdicio acumulado al cierre: los sectores culminados hasta el cierre y los abiertos que ya
 *   pasaron lo presupuestado. Los demás abiertos aún no tienen desperdicio que medir.
 *
 * El reporte se calcula con los datos del día en que se genera: una guía asignada o facturada
 * después del cierre cambia el corte de ese mes.
 *
 * Montos en céntimos y volúmenes en centésimos enteros, como el EAT, para que los totales cuadren.
 */
final readonly class ReporteCorteMesQuery
{
    private string $inicio;

    private string $cierre;

    public function __construct(private Proyecto $proyecto, private int $anio, private int $mes, private string $hoy)
    {
        $primero = CarbonImmutable::create($anio, $mes, 1);
        $this->inicio = $primero->toDateString();
        $this->cierre = $primero->endOfMonth()->toDateString();
    }

    /** @return array<string, mixed>|null null si el mes no tiene guías ni facturas */
    public function calcular(): ?array
    {
        $guiasDelMes = $this->guias()->whereBetween('g.fecha', [$this->inicio, $this->cierre]);
        $facturasDelMes = DB::table('facturas')
            ->where('proyecto_id', $this->proyecto->id)
            ->whereBetween('fecha', [$this->inicio, $this->cierre]);
        if (! (clone $guiasDelMes)->exists() && ! (clone $facturasDelMes)->exists()) {
            return null;
        }

        $guias = $this->filasGuias();
        $delMes = array_filter($guias, fn (array $g) => $g['del_mes']);
        $pendientes = array_filter($guias, fn (array $g) => $g['estado'] === 'pendiente');
        $facturado = (clone $facturasDelMes)
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(volumen_m3), 0) AS m3, COALESCE(SUM(subtotal), 0) AS soles')
            ->first();

        return [
            'proyecto' => ['id' => $this->proyecto->id, 'codigo' => $this->proyecto->codigo, 'nombre' => $this->proyecto->nombre],
            'anio' => $this->anio,
            'mes' => $this->mes,
            'fecha_inicio' => $this->inicio,
            'fecha_cierre' => $this->cierre,
            // El mes en curso: el corte aún no está cerrado.
            'parcial' => $this->cierre > $this->hoy,
            'resumen' => [
                'guias_recibidas' => count($delMes),
                'recibido_m3' => self::sumar($delMes, 'volumen_m3'),
                'vaciado_m3' => self::sumar($delMes, 'vaciado_m3'),
                'facturas' => (int) $facturado->n,
                'facturado_m3' => Volumen::centesimos($facturado->m3) / 100,
                'facturado_soles' => self::centimos($facturado->soles) / 100,
                'guias_pendientes' => count($pendientes),
                'pendiente_m3' => self::sumar($pendientes, 'volumen_m3'),
                'pendiente_soles' => self::sumar($pendientes, 'importe_soles'),
            ],
            'desperdicio' => $this->desperdicio(),
            'guias' => array_values($guias),
        ];
    }

    /**
     * Las guías del mes y las de meses anteriores que seguían pendientes al cierre, por fecha.
     *
     * @return list<array<string, mixed>>
     */
    private function filasGuias(): array
    {
        [$precio, $bindings] = $this->precioGuia('g');
        $facturada = '(f.id IS NOT NULL AND f.fecha <= ?)';

        $filas = $this->guias()
            ->join('proveedores as pv', 'pv.id', '=', 'g.proveedor_id')
            ->join('resistencias as r', 'r.id', '=', 'g.resistencia_id')
            ->leftJoin('facturas as f', 'f.id', '=', 'g.factura_id')
            ->where('g.fecha', '<=', $this->cierre)
            ->where(fn (Builder $q) => $q
                ->where('g.fecha', '>=', $this->inicio)
                ->orWhereRaw("NOT {$facturada}", [$this->cierre]))
            ->select(['g.id', 'g.numero', 'g.fecha', 'g.volumen_m3', 'pv.razon_social as proveedor', 'r.nombre as resistencia'])
            ->selectRaw('(SELECT COALESCE(SUM(a.volumen_m3), 0) FROM asignaciones a WHERE a.guia_id = g.id) AS vaciado_m3')
            ->selectRaw("CASE WHEN {$facturada} THEN f.numero END AS factura", [$this->cierre])
            ->selectRaw("CASE WHEN {$facturada} THEN f.fecha END AS factura_fecha", [$this->cierre])
            ->selectRaw("ROUND({$precio}, 2) AS precio_unitario", $bindings)
            ->selectRaw("ROUND(g.volumen_m3 * {$precio}, 2) AS importe_soles", $bindings)
            ->orderBy('g.fecha')->orderBy('g.numero')->orderBy('g.id')
            ->get();

        return $filas->map(fn (object $g) => [
            'id' => (int) $g->id,
            'numero' => $g->numero,
            'proveedor' => $g->proveedor,
            'fecha' => (string) $g->fecha,
            'resistencia' => $g->resistencia,
            'volumen_m3' => Volumen::centesimos($g->volumen_m3) / 100,
            'vaciado_m3' => Volumen::centesimos($g->vaciado_m3) / 100,
            'factura' => $g->factura,
            'factura_fecha' => $g->factura_fecha === null ? null : (string) $g->factura_fecha,
            'estado' => $g->factura === null ? 'pendiente' : 'facturada',
            'precio_unitario' => $g->precio_unitario === null ? null : self::centimos($g->precio_unitario) / 100,
            'importe_soles' => self::centimos($g->importe_soles) / 100,
            'del_mes' => $g->fecha >= $this->inicio,
        ])->all();
    }

    /** Desperdicio acumulado al cierre: sectores culminados y abiertos que ya pasaron lo presupuestado. */
    private function desperdicio(): array
    {
        return DesperdicioAcumulado::resumir(DesperdicioAcumulado::sectores([$this->proyecto->id], $this->cierre));
    }

    private function guias(): Builder
    {
        return DB::table('guias_remision as g')->where('g.proyecto_id', $this->proyecto->id);
    }

    /** Precio de una guía: el de su factura; si no, el último facturado al cierre; si no, el presupuestado. */
    private function precioGuia(string $guia): array
    {
        return PrecioGuia::expresion($guia, $this->cierre);
    }

    /** @param array<array<string, mixed>> $filas */
    private static function sumar(array $filas, string $clave): float
    {
        return array_sum(array_map(fn (array $f) => (int) round($f[$clave] * 100), $filas)) / 100;
    }

    private static function centimos(?string $decimal): int
    {
        return (int) round(((float) $decimal) * 100);
    }
}
