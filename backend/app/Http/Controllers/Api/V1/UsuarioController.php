<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Rol;
use App\Http\Controllers\Controller;
use App\Http\Requests\Usuarios\CambiarEstadoUsuarioRequest;
use App\Http\Requests\Usuarios\GuardarUsuarioRequest;
use App\Http\Resources\UsuarioResource;
use App\Mail\PasswordTemporalMail;
use App\Models\Rol as RolModelo;
use App\Models\User;
use App\Support\PasswordTemporal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/** CU02 Gestionar usuarios y roles. Los usuarios no se eliminan: se desactivan. */
class UsuarioController extends Controller
{
    public function index(): JsonResponse
    {
        $usuarios = User::with('rol')->orderBy('id')->get();
        $proyectos = $this->proyectoIds($usuarios->modelKeys());

        return response()->json($usuarios->map(fn (User $u) => UsuarioResource::make($u, $proyectos[$u->id] ?? [])));
    }

    public function store(GuardarUsuarioRequest $request, PasswordTemporal $passwordTemporal): JsonResponse
    {
        $password = $passwordTemporal->generar();

        try {
            // El correo va dentro de la transacción: si no sale, no queda un usuario que no conoce su contraseña.
            $usuario = DB::transaction(function () use ($request, $password) {
                $usuario = User::create([
                    ...$request->safe()->only(['nombres', 'apellidos', 'email']),
                    'rol_id' => $this->rolId($request->validated('rol')),
                    'password' => $password,
                    'activo' => true,
                    'debe_cambiar_password' => true,
                ]);
                $this->asignarProyectos($usuario, $request->validated('proyecto_ids'));
                Mail::to($usuario->email)->send(new PasswordTemporalMail($usuario, $password));

                return $usuario;
            });
        } catch (TransportExceptionInterface $e) {
            Log::error('No se pudo enviar la contraseña temporal; el alta se revirtió.', ['error' => $e->getMessage()]);

            return response()->json([
                'message' => 'No se pudo enviar la contraseña temporal al correo. El usuario no se registró; inténtelo de nuevo.',
                'code' => 'correo_no_enviado',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return $this->fila($usuario)->response()->setStatusCode(201);
    }

    public function update(GuardarUsuarioRequest $request, User $usuario): UsuarioResource
    {
        $rol = Rol::from($request->validated('rol'));

        DB::transaction(function () use ($request, $usuario, $rol) {
            $administradores = $this->administradoresActivos();

            if ($usuario->is($request->user()) && $usuario->rol->clave !== $rol) {
                throw ValidationException::withMessages(['rol' => 'No puede cambiar su propio rol.']);
            }
            if ($rol !== Rol::Administrador) {
                $this->exigirOtroAdministrador($administradores, $usuario, 'rol');
            }

            $usuario->update([
                ...$request->safe()->only(['nombres', 'apellidos', 'email']),
                'rol_id' => $this->rolId($rol),
            ]);
            $this->asignarProyectos($usuario, $request->validated('proyecto_ids'));
        });

        return $this->fila($usuario);
    }

    public function estado(CambiarEstadoUsuarioRequest $request, User $usuario): UsuarioResource
    {
        $activo = (bool) $request->validated('activo');

        DB::transaction(function () use ($request, $usuario, $activo) {
            $administradores = $this->administradoresActivos();

            if (! $activo) {
                if ($usuario->is($request->user())) {
                    throw ValidationException::withMessages(['activo' => 'No puede desactivar su propio usuario.']);
                }
                $this->exigirOtroAdministrador($administradores, $usuario, 'activo');
            }

            $usuario->update(['activo' => $activo]);
        });

        return $this->fila($usuario);
    }

    /**
     * Ids de los administradores activos, bloqueados hasta el fin de la transacción para que
     * dos administradores no se desactiven o degraden entre sí al mismo tiempo.
     *
     * @return list<int>
     */
    private function administradoresActivos(): array
    {
        return User::query()
            ->where('activo', true)
            ->where('rol_id', $this->rolId(Rol::Administrador))
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id')
            ->all();
    }

    /** @param  list<int>  $administradores */
    private function exigirOtroAdministrador(array $administradores, User $usuario, string $campo): void
    {
        if ($administradores === [$usuario->id]) {
            throw ValidationException::withMessages([$campo => 'Debe quedar al menos un administrador activo.']);
        }
    }

    /** Los ids de los roles no son fijos: se resuelven por clave. */
    private function rolId(Rol|string $rol): int
    {
        return RolModelo::where('clave', $rol)->value('id');
    }

    /** Reemplaza las asignaciones del usuario en USUARIO_PROYECTO. */
    private function asignarProyectos(User $usuario, array $proyectoIds): void
    {
        DB::table('usuario_proyecto')->where('usuario_id', $usuario->id)->delete();
        DB::table('usuario_proyecto')->insert(array_map(
            fn ($id) => ['usuario_id' => $usuario->id, 'proyecto_id' => (int) $id],
            $proyectoIds,
        ));
    }

    /**
     * Proyectos asignados a varios usuarios en una sola consulta.
     *
     * @param  list<int>  $usuarioIds
     * @return array<int, list<int>>
     */
    private function proyectoIds(array $usuarioIds): array
    {
        return DB::table('usuario_proyecto')
            ->whereIn('usuario_id', $usuarioIds)
            ->orderBy('proyecto_id')
            ->get(['usuario_id', 'proyecto_id'])
            ->groupBy('usuario_id')
            ->map(fn ($filas) => $filas->map(fn ($fila) => (int) $fila->proyecto_id)->all())
            ->all();
    }

    private function fila(User $usuario): UsuarioResource
    {
        return UsuarioResource::make($usuario->load('rol'), $this->proyectoIds([$usuario->id])[$usuario->id] ?? []);
    }
}
