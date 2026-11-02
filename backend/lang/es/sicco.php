<?php

// Mensajes propios de SICCO, tomados de las especificaciones de casos de uso.
return [
    'auth' => [
        'credenciales' => 'Correo o contraseña incorrectos.',
        'inactivo' => 'Usuario inactivo, comuníquese con el administrador.',
        'throttle' => 'Demasiados intentos de inicio de sesión. Intente nuevamente en :seconds segundos.',
        'sin_permiso' => 'No tiene permiso para realizar esta acción.',
        'debe_cambiar_password' => 'Debe cambiar su contraseña temporal antes de continuar.',
        'password_actualizada' => 'Contraseña actualizada correctamente.',
    ],
    'proveedores' => [
        'ruc_invalido' => 'El RUC debe tener 11 dígitos y empezar con 10, 15, 16, 17 o 20.',
        'ruc_duplicado' => 'Ya existe un proveedor con este RUC.',
    ],
    'grupos' => [
        'duplicado' => 'El grupo ya existe',
        'en_uso' => 'El grupo tiene partidas registradas; reasígnelas antes de eliminarlo.',
    ],
    'proyectos' => [
        'sin_acceso' => 'No tiene acceso a este proyecto.',
    ],
    'partidas' => [
        'metrado_repartido' => 'El metrado es menor al volumen ya repartido en sectores (:repartido m³)',
        'confirmar_desperdicio' => 'El factor de desperdicio es :valor %, mayor a 15 %. ¿Confirma el valor?',
    ],
    'niveles' => [
        'duplicado' => 'El nivel ya existe en el proyecto',
    ],
    'sectores' => [
        'duplicado' => 'El sector ya existe en el nivel',
        'excede' => 'El volumen excede lo presupuestado para el elemento',
        'partida_ajena' => 'La partida no pertenece al proyecto del nivel',
    ],
];
