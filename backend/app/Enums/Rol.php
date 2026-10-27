<?php

namespace App\Enums;

/**
 * Roles del sistema definidos en el documento SICCO (Tabla 11 y Tabla 12).
 * El valor es la clave guardada en la tabla `roles`.
 */
enum Rol: string
{
    case Administrador = 'administrador';
    case Almacenero = 'almacenero';
    case IngenieroProduccion = 'ingeniero_produccion';
    case OficinaTecnica = 'oficina_tecnica';
    case Gerencia = 'gerencia';

    public function nombre(): string
    {
        return match ($this) {
            self::Administrador => 'Administrador',
            self::Almacenero => 'Almacenero',
            self::IngenieroProduccion => 'Ingeniero de producción',
            self::OficinaTecnica => 'Oficina técnica',
            self::Gerencia => 'Gerencia',
        };
    }

    public function descripcion(): string
    {
        return match ($this) {
            self::Administrador => 'Gestiona los usuarios del sistema y sus roles.',
            self::Almacenero => 'Recibe el concreto en obra y registra las guías de remisión desde el celular.',
            self::IngenieroProduccion => 'Gestiona niveles y sectores, asigna guías a sectores de vaciado y controla el desperdicio.',
            self::OficinaTecnica => 'Gestiona proyectos y presupuestos, registra facturas y genera los reportes de costos.',
            self::Gerencia => 'Consulta el dashboard, el control de desperdicio y los reportes para la toma de decisiones.',
        };
    }
}
