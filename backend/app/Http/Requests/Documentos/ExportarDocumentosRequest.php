<?php

namespace App\Http\Requests\Documentos;

use App\Enums\EstadoAsignacion;
use App\Http\Controllers\Api\V1\DocumentoController;
use App\Http\Controllers\Concerns\AutorizaProyecto;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * CU18: filtros del listado y del ZIP, o la selección (`ids`), que manda sobre los filtros. El
 * acceso al proyecto se revisa antes de validar, para que los errores no revelen datos ajenos.
 */
class ExportarDocumentosRequest extends FormRequest
{
    use AutorizaProyecto;

    public function authorize(): bool
    {
        $this->autorizarProyecto($this, $this->route('proyecto'));

        return true;
    }

    public function rules(): array
    {
        return [
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'proveedor_id' => ['nullable', 'integer'],
            'estado' => ['nullable', Rule::enum(EstadoAsignacion::class)],
            // `?ids=` llega como null: con `filled` (implícita) se rechaza igual que `ids=[]`.
            'ids' => ['bail', 'filled', 'array', 'max:'.DocumentoController::TOPE],
            'ids.*' => ['integer'],
        ];
    }

    public function messages(): array
    {
        return [
            'date_format' => 'Ingrese una fecha válida',
            'hasta.after_or_equal' => __('sicco.guias.rango_invalido'),
            'proveedor_id.integer' => 'Seleccione un proveedor válido',
            'estado.enum' => 'Seleccione un estado válido',
            'ids.filled' => 'Seleccione al menos un documento',
            'ids.array' => 'La selección no es válida',
            'ids.max' => 'Se pueden exportar hasta '.DocumentoController::TOPE.' documentos a la vez.',
            'ids.*.integer' => 'La selección no es válida',
        ];
    }
}
