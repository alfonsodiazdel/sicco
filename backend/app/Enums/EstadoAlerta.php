<?php

namespace App\Enums;

/** Estado de una alerta de consumo (CU14 paso 5). */
enum EstadoAlerta: string
{
    case Pendiente = 'pendiente';
    case Revisada = 'revisada';
}
