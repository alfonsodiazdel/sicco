<?php

namespace App\Enums;

/**
 * Estado de la guía frente a los sectores de vaciado (CU09 permite asignar por partes). La
 * facturación es independiente y se deriva de la factura vinculada (arquitectura §6.2).
 */
enum EstadoAsignacion: string
{
    case SinSector = 'sin_sector';
    case Parcial = 'parcial';
    case Asignada = 'asignada';
}
