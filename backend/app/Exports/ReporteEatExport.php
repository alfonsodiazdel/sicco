<?php

namespace App\Exports;

use App\Queries\ReporteEatQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** CU15 el Reporte EAT en Excel y en PDF, con las mismas filas y columnas que la pantalla. */
final class ReporteEatExport
{
    public const COLUMNAS = [
        'Elemento', 'Presup. m³', 'Presup. S/', 'Consumido m³', 'Consumido S/', 'Saldo S/',
        'Real culminado S/', 'Proyectado m³', 'PU proyectado S/', 'Proyectado S/', 'EAT S/', 'Variación S/',
    ];

    /**
     * El monto de cada columna, en el mismo orden. La última (variación) va en rojo si hay sobrecosto.
     * El PU proyectado solo lo tienen las partidas: en subtotales, total y filas aparte sale «—».
     */
    private const MONTOS = [
        'presupuesto_m3', 'presupuesto_soles', 'consumido_m3', 'consumido_soles', 'saldo_soles',
        'real_soles', 'proyectado_m3', 'pu_proyectado', 'proyectado_soles', 'eat_soles', 'variacion_soles',
    ];

    /**
     * El reporte de ReporteEatQuery aplanado en el orden de la tabla: por grupo, su título, sus
     * partidas y su subtotal; después las filas aparte, con el aviso de las guías sin sector en una fila
     * propia debajo de la suya, y el total, que trae ReporteEatQuery ya sin las guías sin sector.
     *
     * @return list<array{tipo: string, elemento: string, montos: list<?float>, sobrecosto: bool}>
     */
    public static function filas(array $reporte): array
    {
        $fila = fn (string $tipo, string $elemento, array $montos = []) => [
            'tipo' => $tipo,
            'elemento' => $elemento,
            'montos' => $montos === [] ? [] : array_map(fn (string $k) => $montos[$k] ?? null, self::MONTOS),
            'sobrecosto' => $montos['sobrecosto'] ?? false,
        ];

        $filas = [];
        foreach ($reporte['grupos'] as $grupo) {
            $filas[] = $fila('grupo', $grupo['nombre']);
            foreach ($grupo['filas'] as $partida) {
                $filas[] = $fila('partida', "{$partida['item']} {$partida['etiqueta']}", $partida);
            }
            $filas[] = $fila('subtotal', "Subtotal {$grupo['nombre']}", $grupo['subtotal']);
        }
        foreach ($reporte['filas_aparte'] as $aparte) {
            $filas[] = $fila('aparte', $aparte['concepto'], $aparte);
            if (isset($aparte['aviso'])) {
                $filas[] = $fila('aviso', $aparte['aviso']);
            }
        }
        $filas[] = $fila('total', 'Total', $reporte['total']);

        return $filas;
    }

    /** Los datos del encabezado: proyecto, fecha de corte en d/m/Y y la aclaración del IGV. */
    public static function encabezado(array $reporte): array
    {
        return [
            'proyecto' => "{$reporte['proyecto']['codigo']} {$reporte['proyecto']['nombre']}",
            'fecha_corte' => CarbonImmutable::parse($reporte['fecha_corte'])->format('d/m/Y'),
            'igv' => 'Montos sin IGV',
            'cargos' => ReporteEatQuery::AVISO_CARGOS,
        ];
    }

    public static function nombreArchivo(array $reporte, string $extension): string
    {
        $corte = CarbonImmutable::parse($reporte['fecha_corte'])->format('Ymd');
        // El código es texto libre: una «/» o «\» hace fallar la cabecera Content-Disposition.
        $codigo = preg_replace('/[^A-Za-z0-9._-]+/', '-', Str::ascii($reporte['proyecto']['codigo']));

        return "reporte-eat-{$codigo}-{$corte}.{$extension}";
    }

    /** Genera el archivo completo antes de responder, para que un error no deje una descarga a medias. */
    public static function excel(array $reporte): StreamedResponse
    {
        $contenido = self::xlsx($reporte);

        return response()->streamDownload(function () use ($contenido) {
            echo $contenido;
        }, self::nombreArchivo($reporte, 'xlsx'), ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    private static function xlsx(array $reporte): string
    {
        $archivo = tempnam(sys_get_temp_dir(), 'eat');
        try {
            $encabezado = self::encabezado($reporte);
            $negrita = new Style(fontBold: true);
            $monto = new Style(format: '#,##0.00');
            $montoNegrita = new Style(fontBold: true, format: '#,##0.00');
            $sobrecosto = new Style(fontBold: true, fontColor: Color::RED, format: '#,##0.00');

            $writer = app(Writer::class);
            $writer->openToFile($archivo);
            $writer->addRow(Row::fromValuesWithStyle(['Reporte EAT'], $negrita));
            $writer->addRow(Row::fromValues(['Proyecto', self::texto($encabezado['proyecto'])]));
            $writer->addRow(Row::fromValues(['Fecha de corte', $encabezado['fecha_corte']]));
            $writer->addRow(Row::fromValues([$encabezado['igv']]));
            $writer->addRow(Row::fromValues([$encabezado['cargos']]));
            $writer->addRow(Row::fromValues([]));
            $writer->addRow(Row::fromValuesWithStyle(self::COLUMNAS, $negrita));

            foreach (self::filas($reporte) as $fila) {
                $resaltada = in_array($fila['tipo'], ['grupo', 'subtotal', 'total'], true);
                $celdas = [Cell::fromValue(self::texto($fila['elemento']), $resaltada ? $negrita : null)];
                foreach ($fila['montos'] as $i => $valor) {
                    $esVariacion = $i === count(self::MONTOS) - 1;
                    $estilo = $esVariacion && $fila['sobrecosto'] ? $sobrecosto : ($resaltada ? $montoNegrita : $monto);
                    $celdas[] = $valor === null ? Cell::fromValue('—') : Cell::fromValue($valor, $estilo);
                }
                $writer->addRow(new Row($celdas));
            }

            $writer->close();

            return file_get_contents($archivo);
        } finally {
            unlink($archivo);
        }
    }

    /** Los nombres los escriben los usuarios o vienen del OCR: Excel evaluaría como fórmula un «=1+1». */
    private static function texto(string $valor): string
    {
        return in_array(substr($valor, 0, 1), ['=', '+', '-', '@'], true) ? "'{$valor}" : $valor;
    }
}
