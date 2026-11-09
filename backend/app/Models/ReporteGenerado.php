<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un reporte generado en pantalla o descargado (CU15, CU16). */
#[Fillable(['usuario_id', 'proyecto_id', 'tipo', 'parametros', 'formato'])]
class ReporteGenerado extends Model
{
    public const UPDATED_AT = null;

    public const TIPO_EAT = 'eat';

    public const FORMATO_PANTALLA = 'pantalla';

    protected $table = 'reportes_generados';

    protected function casts(): array
    {
        return ['parametros' => 'array'];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function proyecto(): BelongsTo
    {
        return $this->belongsTo(Proyecto::class);
    }
}
