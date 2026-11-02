<?php

namespace Database\Seeders;

use App\Models\Proveedor;
use App\Models\Resistencia;
use Illuminate\Database\Seeder;

/**
 * Concreteras con las que trabaja la empresa, sus productos y dónde indican la resistencia en sus
 * guías (tomado de las guías y facturas del proyecto Larco). Se ejecuta también en producción y
 * necesita ResistenciaSeeder; no pisa lo que ya esté registrado.
 */
class ConcreteraSeeder extends Seeder
{
    private const CONCRETERAS = [
        [
            'ruc' => '20380289360',
            'razon_social' => 'HOLCIM PERU S.A.',
            'patron_codigo' => '^70(?<fc>\d{3})',
            'indicaciones_ocr' => 'La resistencia está en el código del producto, en los tres dígitos después del 70: '
                ."70280NB5746 es f'c 280 y 70210NB5746 es f'c 210. La descripción solo dice «HOLCIM CLASICO». "
                .'El código de 8 dígitos de la columna «Código» (p. ej. 10088930) es el código de material, no el del producto.',
            'productos' => [
                ['70280NB5746', '10088930', 'HOLCIM CLASICO', 280],
                ['70210NB5746', '10088898', 'HOLCIM CLASICO', 210],
            ],
        ],
        [
            'ruc' => '20612985546',
            'razon_social' => 'ARQUITECTURA CONSTRUIDA S.A.C.',
            'patron_codigo' => null,
            'indicaciones_ocr' => 'La resistencia está en la descripción del producto: «CONCRETO PREMEZCLADO FC= 280 TIPO I H57 SLUMP 4-6» '
                ."es f'c 280. El código del producto (p. ej. 22259) es un correlativo interno.",
            'productos' => [
                ['22259', null, 'CONCRETO PREMEZCLADO FC= 280 TIPO I H57 SLUMP 4-6', 280],
                ['22260', null, 'CONCRETO PREMEZCLADO FC= 210 TIPO I H57 SLUMP 4-6', 210],
            ],
        ],
    ];

    public function run(): void
    {
        foreach (self::CONCRETERAS as $datos) {
            $proveedor = Proveedor::firstOrCreate(['ruc' => $datos['ruc']], ['razon_social' => $datos['razon_social']]);
            $proveedor->patron_codigo ??= $datos['patron_codigo'];
            $proveedor->indicaciones_ocr ??= $datos['indicaciones_ocr'];
            $proveedor->save();

            foreach ($datos['productos'] as [$codigo, $material, $descripcion, $fc]) {
                $proveedor->productos()->firstOrCreate(['codigo' => $codigo], [
                    'codigo_material' => $material,
                    'descripcion' => $descripcion,
                    'resistencia_id' => Resistencia::where('fc_kg_cm2', $fc)->firstOrFail()->id,
                ]);
            }
        }
    }
}
