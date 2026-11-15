<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/** Proveedor con sus datos de contacto y condiciones comerciales (solo Oficina técnica y administrador). */
class ProveedorDetalleResource extends ProveedorResource
{
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'contacto_nombre' => $this->contacto_nombre,
            'contacto_telefono' => $this->contacto_telefono,
            'contacto_correo' => $this->contacto_correo,
            'correo_facturacion' => $this->correo_facturacion,
            'condicion_pago' => $this->condicion_pago,
            'direccion_fiscal' => $this->direccion_fiscal,
            'planta_despacho' => $this->planta_despacho,
        ];
    }
}
