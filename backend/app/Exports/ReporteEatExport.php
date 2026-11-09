<?php

namespace App\Exports;

use Carbon\CarbonImmutable;
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
        'Elemento', 'Presup. m³', 'Presup. S/', 'Consumido m³', 'Consumido S/', 'Saldo m³', 'Saldo S/', 'EAT m³', 'EAT S/',
    ];

    private const MONTOS = [
        'presupuesto_m3', 'presupuesto_soles', 'consumido_m3', 'consumido_soles',
        'saldo_m3', 'saldo_soles', 'eat_m3', 'eat_soles',
    ];

    /**
     * El reporte de ReporteEatQuery aplanado en el orden de la tabla: por grupo, su título, sus
     * partidas y su subtotal; después las filas aparte y el total.
     *
     * @return list<array{tipo: string, elemento: string, montos: list<?float>, sobrecosto: bool}>
     */
    public static function filas(array $reporte): array
    {
        $fila = fn (string $tipo, string $elemento, array $montos = []) => [
            'tipo' => $tipo,
            'elemento' => $elemento,
            'montos' => $montos === [] ? [] : array_map(fn (string $k) => $montos[$k], self::MONTOS),
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
        ];
    }

    public static function nombreArchivo(array $reporte, string $extension): string
    {
        $corte = CarbonImmutable::parse($reporte['fecha_corte'])->format('Ymd');

        return "reporte-eat-{$reporte['proyecto']['codigo']}-{$corte}.{$extension}";
    }

    public static function excel(array $reporte): StreamedResponse
    {
        return response()->streamDownload(function () use ($reporte) {
            $encabezado = self::encabezado($reporte);
            $negrita = new Style(fontBold: true);
            $monto = new Style(format: '#,##0.00');
            $montoNegrita = new Style(fontBold: true, format: '#,##0.00');
            $sobrecosto = new Style(fontBold: true, fontColor: Color::RED, format: '#,##0.00');

            $writer = new Writer;
            $writer->openToFile('php://output');
            $writer->addRow(Row::fromValuesWithStyle(['Reporte EAT'], $negrita));
            $writer->addRow(Row::fromValues(['Proyecto', $encabezado['proyecto']]));
            $writer->addRow(Row::fromValues(['Fecha de corte', $encabezado['fecha_corte']]));
            $writer->addRow(Row::fromValues([$encabezado['igv']]));
            $writer->addRow(Row::fromValues([]));
            $writer->addRow(Row::fromValuesWithStyle(self::COLUMNAS, $negrita));

            foreach (self::filas($reporte) as $fila) {
                $resaltada = in_array($fila['tipo'], ['grupo', 'subtotal', 'total'], true);
                $celdas = [Cell::fromValue($fila['elemento'], $resaltada ? $negrita : null)];
                foreach ($fila['montos'] as $i => $valor) {
                    $esEatSoles = $i === count(self::MONTOS) - 1;
                    $estilo = $esEatSoles && $fila['sobrecosto'] ? $sobrecosto : ($resaltada ? $montoNegrita : $monto);
                    $celdas[] = $valor === null ? Cell::fromValue('—') : Cell::fromValue($valor, $estilo);
                }
                $writer->addRow(new Row($celdas));
            }

            $writer->close();
        }, self::nombreArchivo($reporte, 'xlsx'), ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
}
