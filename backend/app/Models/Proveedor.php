<?php

namespace App\Models;

use Database\Factories\ProveedorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'ruc', 'razon_social', 'contacto_nombre', 'contacto_telefono', 'contacto_correo',
    'correo_facturacion', 'condicion_pago', 'direccion_fiscal', 'planta_despacho', 'activo',
])]
class Proveedor extends Model
{
    /** @use HasFactory<ProveedorFactory> */
    use HasFactory;

    protected $table = 'proveedores';

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    public function productos(): HasMany
    {
        return $this->hasMany(ProductoProveedor::class);
    }

    public function guias(): HasMany
    {
        return $this->hasMany(GuiaRemision::class);
    }

    public function facturas(): HasMany
    {
        return $this->hasMany(Factura::class);
    }

    /** Ya se usó en una guía o factura: solo se puede desactivar, para no perder su trazabilidad. */
    public function enUso(): bool
    {
        return $this->guias()->exists() || $this->facturas()->exists();
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
