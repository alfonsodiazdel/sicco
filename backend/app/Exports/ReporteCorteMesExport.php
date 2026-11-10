<?php

namespace App\Exports;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** CU16 el reporte de corte a fin de mes en Excel y en PDF, con los mismos datos que la pantalla. */
final class ReporteCorteMesExport
{
    public const MESES = [
        1 => 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
        'Julio', 'Agosto', 'Setiembre', 'Octubre', 'Noviembre', 'Diciembre',
    ];

    public const COLUMNAS = [
        'N.° guía', 'Proveedor', 'Fecha', 'Resistencia', 'Volumen m³', 'Vaciado m³',
        'Factura', 'Estado al cierre', 'Precio S/', 'Importe S/',
    ];

    public const AVISO_PENDIENTE = 'Pendiente de facturar: guías hasta el cierre sin factura fechada hasta ese día, al precio de su factura, al último facturado o al presupuestado.';

    public const AVISO_DESPERDICIO = 'Desperdicio acumulado: sectores culminados hasta el cierre y abiertos que ya superan lo presupuestado.';

    /** Los datos del encabezado y del resumen, ya como texto. */
    public static function encabezado(array $reporte): array
    {
        $r = $reporte['resumen'];
        $d = $reporte['desperdicio'];
        $mes = self::MESES[$reporte['mes']].' '.$reporte['anio'];

        return [
            'proyecto' => "{$reporte['proyecto']['codigo']} {$reporte['proyecto']['nombre']}",
            'periodo' => $reporte['parcial'] ? "{$mes} (mes en curso: corte parcial)" : $mes,
            'cierre' => CarbonImmutable::parse($reporte['fecha_cierre'])->format('d/m/Y'),
            'igv' => 'Montos sin IGV',
            'resumen' => [
                ['Recibido en el mes', "{$r['guias_recibidas']} guías", self::m3($r['recibido_m3'])],
                ['Vaciado en el mes', '', self::m3($r['vaciado_m3'])],
                ['Facturado en el mes', "{$r['facturas']} facturas", self::m3($r['facturado_m3']).' · S/ '.self::numero($r['facturado_soles'])],
                ['Pendiente de facturar al cierre', "{$r['guias_pendientes']} guías", self::m3($r['pendiente_m3']).' · S/ '.self::numero($r['pendiente_soles'])],
                ['Desperdicio acumulado', "{$d['sectores']} sectores",
                    $d['desperdicio_pct'] === null ? '—' : self::numero($d['desperdicio_pct']).' % ('.self::m3($d['diferencia_m3']).')'],
            ],
            'avisos' => [self::AVISO_PENDIENTE, self::AVISO_DESPERDICIO],
        ];
    }

    /** @return list<array{celdas: list<string|float|null>, pendiente: bool}> */
    public static function filas(array $reporte): array
    {
        return array_map(fn (array $g) => [
            'celdas' => [
                $g['numero'], $g['proveedor'], CarbonImmutable::parse($g['fecha'])->format('d/m/Y'), $g['resistencia'],
                $g['volumen_m3'], $g['vaciado_m3'], $g['factura'] ?? '—',
                $g['estado'] === 'facturada' ? 'Facturada' : 'Pendiente', $g['precio_unitario'], $g['importe_soles'],
            ],
            'pendiente' => $g['estado'] === 'pendiente',
        ], $reporte['guias']);
    }

    public static function nombreArchivo(array $reporte, string $extension): string
    {
        // El código es texto libre: una «/» o «\» hace fallar la cabecera Content-Disposition.
        $codigo = preg_replace('/[^A-Za-z0-9._-]+/', '-', Str::ascii($reporte['proyecto']['codigo']));

        return sprintf('reporte-corte-mes-%s-%04d%02d.%s', $codigo, $reporte['anio'], $reporte['mes'], $extension);
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
        $archivo = tempnam(sys_get_temp_dir(), 'corte');
        try {
            $encabezado = self::encabezado($reporte);
            $negrita = new Style(fontBold: true);
            $monto = new Style(format: '#,##0.00');
            $pendiente = new Style(fontColor: Color::rgb(180, 83, 9));

            $writer = app(Writer::class);
            $writer->openToFile($archivo);
            $writer->addRow(Row::fromValuesWithStyle(['Reporte de corte a fin de mes'], $negrita));
            $writer->addRow(Row::fromValues(['Proyecto', self::texto($encabezado['proyecto'])]));
            $writer->addRow(Row::fromValues(['Mes', $encabezado['periodo']]));
            $writer->addRow(Row::fromValues(['Cierre', $encabezado['cierre']]));
            $writer->addRow(Row::fromValues([$encabezado['igv']]));
            $writer->addRow(Row::fromValues([]));
            foreach ($encabezado['resumen'] as $linea) {
                $writer->addRow(new Row([Cell::fromValue($linea[0], $negrita), Cell::fromValue($linea[1]), Cell::fromValue($linea[2])]));
            }
            foreach ($encabezado['avisos'] as $aviso) {
                $writer->addRow(Row::fromValues([$aviso]));
            }
            $writer->addRow(Row::fromValues([]));
            $writer->addRow(Row::fromValuesWithStyle(self::COLUMNAS, $negrita));

            foreach (self::filas($reporte) as $fila) {
                $celdas = [];
                foreach ($fila['celdas'] as $i => $valor) {
                    $celdas[] = match (true) {
                        $valor === null => Cell::fromValue('—'),
                        is_string($valor) => Cell::fromValue(self::texto($valor), $i === 7 && $fila['pendiente'] ? $pendiente : null),
                        default => Cell::fromValue($valor, $monto),
                    };
                }
                $writer->addRow(new Row($celdas));
            }

            $writer->close();

            return file_get_contents($archivo);
        } finally {
            unlink($archivo);
        }
    }

    private static function m3(float $valor): string
    {
        return self::numero($valor).' m³';
    }

    private static function numero(float $valor): string
    {
        return number_format($valor, 2);
    }

    /** Los números de guía y los nombres vienen del OCR o de usuarios: Excel evaluaría un «=1+1». */
    private static function texto(string $valor): string
    {
        return in_array(substr($valor, 0, 1), ['=', '+', '-', '@'], true) ? "'{$valor}" : $valor;
    }
}
