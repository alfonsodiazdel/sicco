<?php

namespace App\Queries;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Precio unitario de una guía al cierre, como el EAT (CU15), el corte de mes (CU16) y el dashboard
 * (CU17): el de su factura; si no, el último precio facturado de su resistencia en su proyecto hasta
 * el cierre; si no, el precio del concreto de esa resistencia en el presupuesto del proyecto.
 */
final class PrecioGuia
{
    /**
     * Expresión SQL correlacionada con la guía `$guia` (alias de guias_remision).
     *
     * @return array{0: string, 1: list<mixed>} SQL y sus bindings
     */
    public static function expresion(string $guia, string $cierre): array
    {
        return self::coalesce(
            self::sub(DB::table('factura_lineas as pf')
                ->join('facturas as ff', 'ff.id', '=', 'pf.factura_id')
                ->whereColumn('pf.factura_id', "{$guia}.factura_id")
                ->whereColumn('pf.resistencia_id', "{$guia}.resistencia_id")
                ->whereColumn('ff.proyecto_id', "{$guia}.proyecto_id")
                ->orderBy('pf.id')
                ->limit(1)
                ->select('pf.precio_unitario')),
            self::sub(DB::table('factura_lineas as uf')
                ->join('facturas as fu', 'fu.id', '=', 'uf.factura_id')
                ->whereColumn('uf.resistencia_id', "{$guia}.resistencia_id")
                ->whereColumn('fu.proyecto_id', "{$guia}.proyecto_id")
                ->where('fu.fecha', '<=', $cierre)
                ->orderByDesc('fu.fecha')->orderByDesc('fu.id')->orderByDesc('uf.id')
                ->limit(1)
                ->select('uf.precio_unitario')),
            self::sub(DB::table('concretos as cm')
                ->whereColumn('cm.proyecto_id', "{$guia}.proyecto_id")
                ->whereColumn('cm.resistencia_id', "{$guia}.resistencia_id")
                ->whereExists(fn ($q) => $q->from('partidas as pm')->whereColumn('pm.concreto_id', 'cm.id'))
                ->select('cm.precio_unitario')),
            ['0', []],
        );
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
