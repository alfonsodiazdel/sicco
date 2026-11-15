<?php

namespace App\Rules;

use App\Models\Proveedor;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Un proveedor inactivo no se ofrece para documentos nuevos; los que ya existen lo conservan. */
class ProveedorActivo implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (Proveedor::whereKey($value)->where('activo', false)->exists()) {
            $fail(__('sicco.proveedores.inactivo'));
        }
    }
}
