<!DOCTYPE html>
<html lang="es">
<body style="font-family: Arial, sans-serif; color: #1f2937; line-height: 1.5;">
    <p>Estimado(a) {{ $usuario->nombre_completo }}:</p>

    <p>El administrador de SICCO registró su usuario. Estos son sus datos de acceso:</p>

    <p>
        Correo: <strong>{{ $usuario->email }}</strong><br>
        Contraseña temporal: <strong>{{ $password }}</strong>
    </p>

    <p>Ingrese en <a href="{{ $url }}">{{ $url }}</a>. Al iniciar sesión, el sistema le pedirá cambiar esta contraseña temporal por una propia.</p>

    <p>Si usted no esperaba este correo, comuníquese con el administrador.</p>
</body>
</html>
