<?php

use App\Enums\Rol;
use App\Models\GrupoPartida;
use App\Models\Proyecto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

// Ayudas compartidas por las pruebas del presupuesto (grupos y partidas).

function conRol(Rol $rol): User
{
    return User::factory()->rol($rol)->create();
}

function obra(array $atributos = []): Proyecto
{
    return Proyecto::create([
        'codigo' => 'PRY-001',
        'nombre' => 'Residencial Los Álamos',
        'direccion' => 'Av. Primavera 1250, Santiago de Surco',
        'fecha_inicio' => '2026-03-02',
        'fecha_fin' => '2027-02-26',
        'ingeniero_id' => conRol(Rol::IngenieroProduccion)->id,
        'estado' => 'Activo',
        ...$atributos,
    ]);
}

function datosPartida(array $cambios = []): array
{
    return [
        'grupo_id' => $cambios['grupo_id'] ?? GrupoPartida::firstOrCreate(
            ['nombre' => 'Cimentación'],
            ['partida_control' => '2.02.02', 'orden' => 2],
        )->id,
        'item' => '03.01.02.01',
        'descripcion' => "CONCRETO F'C=210 kg/cm2 - ZAPATAS",
        'elemento' => 'Zapata',
        'resistencia_fc' => "f'c 210 kg/cm²",
        'metrado_m3' => 320,
        'factor_desperdicio' => 5,
        'precio_unitario' => 385,
        ...$cambios,
    ];
}
