<!DOCTYPE html>
<html lang="es">
<body style="font-family: Arial, sans-serif; color: #1f2937; line-height: 1.5;">
    <p>El sector <strong>{{ $sector->nivel->nombre }} – {{ $sector->nombre }}</strong> del proyecto
        <strong>{{ $proyecto->codigo }} {{ $proyecto->nombre }}</strong> superó lo presupuestado más su
        desperdicio en <strong>{{ number_format((float) $alerta->exceso_m3, 2) }} m³</strong>.</p>

    <table cellpadding="4" style="border-collapse: collapse;">
        <tr><td>Elemento</td><td>{{ $elemento }}</td></tr>
        <tr><td>Presupuestado</td><td>{{ number_format((float) $sector->volumen_presup_m3, 2) }} m³</td></tr>
        <tr><td>Factor de desperdicio</td><td>{{ number_format((float) $sector->partida->factor_desperdicio, 2) }} %</td></tr>
        <tr><td>Límite</td><td>{{ number_format($limite, 2) }} m³</td></tr>
        <tr><td>Vaciado</td><td>{{ number_format((float) $sector->volumen_vaciado_m3, 2) }} m³</td></tr>
        <tr><td>Fecha</td><td>{{ $alerta->fecha->copy()->timezone('America/Lima')->format('d/m/Y H:i') }}</td></tr>
    </table>

    <p>Revise la alerta en <a href="{{ $url }}">{{ $url }}</a> y márquela como revisada.</p>
</body>
</html>
