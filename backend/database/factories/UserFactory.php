<?php

namespace Database\Factories;

use App\Enums\Rol as RolEnum;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'rol_id' => fn () => self::rolId(RolEnum::Almacenero),
            'nombres' => fake()->firstName(),
            'apellidos' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'activo' => true,
            'debe_cambiar_password' => false,
            'ultimo_acceso_at' => null,
            'remember_token' => Str::random(10),
        ];
    }

    public function rol(RolEnum $rol): static
    {
        return $this->state(fn () => ['rol_id' => self::rolId($rol)]);
    }

    public function inactivo(): static
    {
        return $this->state(fn () => ['activo' => false]);
    }

    public function conPasswordTemporal(): static
    {
        return $this->state(fn () => ['debe_cambiar_password' => true]);
    }

    private static function rolId(RolEnum $rol): int
    {
        return Rol::firstOrCreate(
            ['clave' => $rol->value],
            ['nombre' => $rol->nombre(), 'descripcion' => $rol->descripcion()],
        )->id;
    }
}
