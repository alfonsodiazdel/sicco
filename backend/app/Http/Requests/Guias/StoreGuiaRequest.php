<?php

namespace App\Http\Requests\Guias;

use App\Http\Controllers\Api\V1\GuiaController;
use App\Rules\ProveedorActivo;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * CU06 paso 5 y CU08: registro de la guía con los datos revisados. La foto llega como archivo
 * (registro manual sin lectura) o como la referencia que devolvió la lectura, para no subirla dos
 * veces desde la obra. El proveedor es uno registrado o, si el OCR leyó un RUC nuevo, se registra
 * con la guía. El número repetido se valida en el controlador, una vez resuelto el proveedor.
 * «manual» indica que el almacenero descartó la lectura de esa foto y digitó los datos (CU08).
 */
class StoreGuiaRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $texto = fn (string $campo, bool $mayusculas = false) => is_string($this->input($campo))
            ? ($mayusculas ? mb_strtoupper(trim($this->input($campo))) : trim($this->input($campo)))
            : $this->input($campo);

        $this->merge([
            'numero' => is_string($this->numero) ? str_replace(' ', '', $texto('numero', true)) : $this->numero,
            'codigo_producto' => is_string($this->codigo_producto)
                ? (str_replace(' ', '', $texto('codigo_producto', true)) ?: null)
                : $this->codigo_producto,
            'descripcion_producto' => $texto('descripcion_producto') ?: null,
            'placa' => $texto('placa', true) ?: null,
            'proveedor_ruc' => $texto('proveedor_ruc'),
            'proveedor_razon_social' => $texto('proveedor_razon_social'),
            // Llega como texto en el formulario multipart («true», «1»…).
            'manual' => $this->boolean('manual'),
        ]);
    }

    public function rules(): array
    {
        return [
            'proyecto_id' => ['required', 'integer', 'exists:proyectos,id'],
            'foto' => ['required_without:foto_temporal', 'nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'foto_temporal' => ['required_without:foto', 'nullable', 'string', 'regex:'.GuiaController::PATRON_FOTO_TEMPORAL],
            'proveedor_id' => ['required_without:proveedor_ruc', 'nullable', 'integer', 'exists:proveedores,id', new ProveedorActivo],
            'proveedor_ruc' => ['required_without:proveedor_id', 'nullable', 'string', 'regex:/^(10|15|16|17|20)\d{9}$/'],
            'proveedor_razon_social' => ['required_with:proveedor_ruc', 'nullable', 'string', 'max:200'],
            'numero' => ['required', 'string', 'max:20'],
            'fecha' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'resistencia_id' => ['required', 'integer', Rule::exists('resistencias', 'id')->where('activo', true)],
            // El producto de la concretera; sin código la guía se registra igual (CU08).
            'codigo_producto' => ['nullable', 'string', 'max:30'],
            'descripcion_producto' => ['nullable', 'string', 'max:200'],
            // Un mixer lleva unos 8 a 12 m³; el tope solo evita errores de digitación grandes.
            'volumen_m3' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:99.99'],
            'placa' => ['nullable', 'string', 'max:10'],
            'manual' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'required' => 'Este dato es requerido',
            'required_without' => 'Este dato es requerido',
            'required_with' => 'Este dato es requerido',
            'numeric' => 'Este dato es requerido',
            'integer' => 'Este dato es requerido',
            'resistencia_id.exists' => 'La resistencia no existe',
            'max' => 'Debe tener como máximo :max caracteres',
            'foto.file' => __('sicco.guias.foto_invalida'),
            'foto.mimes' => __('sicco.guias.foto_invalida'),
            'foto.max' => __('sicco.guias.foto_invalida'),
            'foto_temporal.regex' => __('sicco.guias.foto_vencida'),
            'proveedor_ruc.regex' => __('sicco.proveedores.ruc_invalido'),
            'fecha.date_format' => 'Ingrese una fecha válida',
            'fecha.before_or_equal' => __('sicco.guias.fecha_futura'),
            'volumen_m3.decimal' => 'Ingrese como máximo dos decimales',
            'volumen_m3.gt' => 'Ingrese un valor mayor a cero',
            'volumen_m3.max' => 'El valor es demasiado grande',
        ];
    }
}
