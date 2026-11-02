<?php

namespace App\Models;

use Database\Factories\ProveedorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['ruc', 'razon_social'])]
class Proveedor extends Model
{
    /** @use HasFactory<ProveedorFactory> */
    use HasFactory;

    protected $table = 'proveedores';

    public function productos(): HasMany
    {
        return $this->hasMany(ProductoProveedor::class);
    }

    /** El f'c que indica el código según `patron_codigo`, p. ej. 280 para 70280NB5746 en Holcim. */
    public function fcDelCodigo(?string $codigo): ?int
    {
        if (! $this->patron_codigo || ! $codigo || ! @preg_match("~{$this->patron_codigo}~", $codigo, $m) || ! isset($m['fc'])) {
            return null;
        }

        return (int) $m['fc'];
    }
}
