<?php

use App\Enums\Rol;
use App\Models\Proveedor;
use App\Models\Resistencia;
use Database\Seeders\ConcreteraSeeder;
use Database\Seeders\ResistenciaSeeder;

it('lista las resistencias activas ordenadas por f\'c para cualquier rol', function () {
    resistencia(280);
    resistencia(175);
    Resistencia::create(['fc_kg_cm2' => 140, 'nombre' => "f'c 140 kg/cm²", 'activo' => false]);

    $this->actingAs(conRol(Rol::Almacenero))->getJson('/api/v1/resistencias')
        ->assertOk()
        ->assertExactJson([
            ['id' => resistencia(175)->id, 'fc_kg_cm2' => 175, 'nombre' => "f'c 175 kg/cm²"],
            ['id' => resistencia(280)->id, 'fc_kg_cm2' => 280, 'nombre' => "f'c 280 kg/cm²"],
        ]);
});

it('carga las concreteras con sus productos sin pisar lo registrado', function () {
    Proveedor::factory()->create(['ruc' => '20380289360', 'razon_social' => 'Holcim (editado)']);

    $this->seed([ResistenciaSeeder::class, ConcreteraSeeder::class, ConcreteraSeeder::class]);

    $holcim = Proveedor::where('ruc', '20380289360')->sole();
    expect($holcim->razon_social)->toBe('Holcim (editado)')
        ->and($holcim->productos()->pluck('codigo')->all())->toEqualCanonicalizing(['70280NB5746', '70210NB5746'])
        ->and($holcim->fcDelCodigo('70350NB5746'))->toBe(350)
        ->and($holcim->fcDelCodigo('10088930'))->toBeNull()
        ->and(Proveedor::where('ruc', '20612985546')->sole()->fcDelCodigo('22259'))->toBeNull();
});
