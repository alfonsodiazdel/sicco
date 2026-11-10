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
    'guias' => [
        'registrada' => 'Guía registrada correctamente',
        'duplicada' => 'La guía ya fue registrada',
        'no_leida' => 'No se pudo leer la guía',
        'foto_vencida' => 'La foto ya no está disponible; tómela de nuevo.',
        'fecha_futura' => 'La fecha no puede ser posterior a hoy',
        'foto_invalida' => 'Adjunte una foto JPG, PNG o WebP de hasta 10 MB',
        'resistencia_producto' => 'El producto :codigo está registrado como :resistencia',
        'foto_no_disponible' => 'La foto de la guía no está disponible',
        'rango_invalido' => 'La fecha final debe ser igual o posterior a la inicial',
    ],
    'asignaciones' => [
        'guardada' => 'Guía asignada correctamente',
        'excede_guia' => 'El volumen asignado supera el de la guía',
        'sin_sectores' => 'Agregue al menos un sector',
        'sector_repetido' => 'El sector ya está en la asignación',
        'sector_ajeno' => 'El sector no pertenece al proyecto de la guía',
        'sector_culminado' => 'El sector :sector está culminado; reábralo para cambiar sus guías',
    ],
    'facturas' => [
        'registrada' => 'Factura registrada correctamente',
        'duplicada' => 'La factura ya fue registrada',
        'volumen_no_coincide' => 'El volumen facturado no coincide con las guías',
        'sin_guias' => 'Vincule al menos una guía',
        'sin_pendientes' => 'No hay guías pendientes del proveedor',
        'guia_no_disponible' => 'La guía :numero ya está facturada o no es del proveedor y proyecto',
        'resistencia_repetida' => 'La resistencia ya está en la factura',
        'archivo_invalido' => 'Adjunte una imagen (JPG, PNG o WebP) o un PDF de hasta 10 MB',
        'archivo_no_disponible' => 'El archivo de la factura no está disponible',
        'archivo_vencido' => 'El archivo ya no está disponible; adjúntelo de nuevo.',
        'no_leida' => 'No se pudo leer la factura',
        'citada_no_registrada' => 'La factura cita la guía :numero, que no está registrada',
        'citada_otro_proyecto' => 'La factura cita la guía :numero, que es de otro proyecto',
        'citada_facturada' => 'La factura cita la guía :numero, que ya está facturada',
    ],
    'sectores' => [
        'duplicado' => 'El sector ya existe en el nivel',
        'excede' => 'El volumen excede lo presupuestado para el elemento',
        'partida_ajena' => 'La partida no pertenece al proyecto del nivel',
        'culminado' => 'El sector está culminado; reábralo para editarlo',
        'ya_culminado' => 'El sector ya está culminado',
        'no_culminado' => 'El sector no está culminado',
        'culminado_ok' => 'Sector culminado correctamente',
        'reabierto_ok' => 'Sector reabierto correctamente',
    ],
    'alertas' => [
        'revisada' => 'Alerta marcada como revisada',
        'ya_revisada' => 'La alerta ya fue revisada',
        'registrada' => 'Se registró una alerta de consumo y se avisará por correo al ingeniero responsable y a gerencia',
    ],
];
