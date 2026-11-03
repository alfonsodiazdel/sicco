<?php

namespace App\Exports;

use App\Http\Resources\GuiaPendienteResource;
use App\Models\GuiaRemision;
use App\Models\Proyecto;
use App\Support\Volumen;
use Illuminate\Support\Collection;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** CU10 requerimiento especial: la lista de guías sin sector en Excel. */
final class GuiasSinSectorExport
{
    /** @param  Collection<int, GuiaRemision>  $guias  con `withSum('asignaciones', 'volumen_m3')` */
    public static function descargar(Proyecto $proyecto, Collection $guias, string $nombre): StreamedResponse
    {
        return response()->streamDownload(function () use ($proyecto, $guias) {
            $negrita = new Style(fontBold: true);
            $volumen = new Style(format: '0.00');

            $writer = new Writer;
            $writer->openToFile('php://output');
            $writer->addRow(Row::fromValuesWithStyle(["Guías sin sector – {$proyecto->codigo} {$proyecto->nombre}"], $negrita));
            $writer->addRow(Row::fromValues(['Generado el '.now()->format('d/m/Y H:i')]));
            $writer->addRow(Row::fromValues([]));
            $writer->addRow(Row::fromValuesWithStyle([
                'N° de guía', 'Fecha', 'Proveedor', 'Resistencia', 'Volumen (m³)', 'Pendiente (m³)', 'Días sin asignar',
            ], $negrita));

            foreach ($guias as $guia) {
                $pendiente = Volumen::centesimos($guia->volumen_m3) - Volumen::centesimos($guia->asignaciones_sum_volumen_m3);
                $writer->addRow(new Row([
                    Cell::fromValue($guia->numero),
                    Cell::fromValue($guia->fecha->format('d/m/Y')),
                    Cell::fromValue($guia->proveedor->razon_social),
                    Cell::fromValue($guia->resistencia->nombre),
                    Cell::fromValue(Volumen::centesimos($guia->volumen_m3) / 100, $volumen),
                    Cell::fromValue($pendiente / 100, $volumen),
                    Cell::fromValue(GuiaPendienteResource::diasTranscurridos($guia)),
                ]));
            }

            $writer->close();
        }, $nombre, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
}
