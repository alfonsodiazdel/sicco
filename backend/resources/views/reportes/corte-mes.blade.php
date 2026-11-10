<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Reporte de corte a fin de mes {{ $encabezado['proyecto'] }}</title>
<style>
    @page { margin: 18mm 12mm; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 8pt; color: #222; }
    h1 { font-size: 14pt; margin: 0 0 6pt; }
    .encabezado { margin-bottom: 8pt; }
    .encabezado p { margin: 0 0 2pt; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border: 0.5pt solid #999; padding: 3pt 4pt; }
    th { background: #eee; text-align: center; }
    td.monto { text-align: right; white-space: nowrap; }
    table.resumen { width: auto; margin-bottom: 6pt; }
    table.resumen td:first-child { font-weight: bold; }
    .avisos { margin: 0 0 10pt; font-style: italic; }
    td.pendiente { color: #b45309; font-weight: bold; }
</style>
</head>
<body>
<h1>Reporte de corte a fin de mes</h1>
<div class="encabezado">
    <p><strong>Proyecto:</strong> {{ $encabezado['proyecto'] }}</p>
    <p><strong>Mes:</strong> {{ $encabezado['periodo'] }} · <strong>Cierre:</strong> {{ $encabezado['cierre'] }}</p>
    <p>{{ $encabezado['igv'] }}.</p>
</div>
<table class="resumen">
    @foreach ($encabezado['resumen'] as $linea)
        <tr>
            <td>{{ $linea[0] }}</td>
            <td>{{ $linea[1] }}</td>
            <td class="monto">{{ $linea[2] }}</td>
        </tr>
    @endforeach
</table>
<div class="avisos">
    @foreach ($encabezado['avisos'] as $aviso)
        <p>{{ $aviso }}</p>
    @endforeach
</div>
<table>
    <thead>
        <tr>
            @foreach ($columnas as $columna)
                <th>{{ $columna }}</th>
            @endforeach
        </tr>
    </thead>
    <tbody>
        @foreach ($filas as $fila)
            <tr>
                @foreach ($fila['celdas'] as $i => $valor)
                    @if ($valor === null)
                        <td class="monto">—</td>
                    @elseif (is_string($valor))
                        <td class="{{ $i === 7 && $fila['pendiente'] ? 'pendiente' : '' }}">{{ $valor }}</td>
                    @else
                        <td class="monto">{{ number_format($valor, 2) }}</td>
                    @endif
                @endforeach
            </tr>
        @endforeach
    </tbody>
</table>
</body>
</html>
