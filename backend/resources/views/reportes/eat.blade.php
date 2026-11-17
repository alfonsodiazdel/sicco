<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Reporte EAT {{ $encabezado['proyecto'] }}</title>
<style>
    @page { margin: 18mm 12mm; }
    /* 12 columnas en A4 horizontal: 8pt para que los montos no se partan. */
    body { font-family: DejaVu Sans, sans-serif; font-size: 8pt; color: #222; }
    h1 { font-size: 14pt; margin: 0 0 6pt; }
    .encabezado { margin-bottom: 10pt; }
    .encabezado p { margin: 0 0 2pt; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border: 0.5pt solid #999; padding: 3pt 4pt; }
    th { background: #eee; text-align: center; }
    td.monto { text-align: right; white-space: nowrap; }
    tr.grupo td { background: #f5f5f5; font-weight: bold; }
    tr.subtotal td, tr.total td { font-weight: bold; }
    tr.total td { border-top: 1.2pt solid #222; }
    td.sobrecosto { color: #c00; font-weight: bold; }
    tr.aviso td { font-style: italic; }
</style>
</head>
<body>
<h1>Reporte EAT</h1>
<div class="encabezado">
    <p><strong>Proyecto:</strong> {{ $encabezado['proyecto'] }}</p>
    <p><strong>Fecha de corte:</strong> {{ $encabezado['fecha_corte'] }}</p>
    <p>{{ $encabezado['igv'] }}. {{ $encabezado['cargos'] }}</p>
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
            <tr class="{{ $fila['tipo'] }}">
                @if (in_array($fila['tipo'], ['grupo', 'aviso'], true))
                    <td colspan="{{ count($columnas) }}">{{ $fila['elemento'] }}</td>
                @else
                    <td>{{ $fila['elemento'] }}</td>
                    @foreach ($fila['montos'] as $i => $valor)
                        <td class="monto{{ $loop->last && $fila['sobrecosto'] ? ' sobrecosto' : '' }}">{{ $valor === null ? '—' : number_format($valor, 2) }}</td>
                    @endforeach
                @endif
            </tr>
        @endforeach
    </tbody>
</table>
</body>
</html>
