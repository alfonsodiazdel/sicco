<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\EstadoProyecto;
use App\Enums\Rol;
use App\Http\Controllers\Controller;
use App\Http\Requests\Proyectos\GuardarProyectoRequest;
use App\Http\Resources\ProyectoResource;
use App\Models\Proyecto;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/** CU03 Gestionar proyectos. */
class ProyectoController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return ProyectoResource::collection(Proyecto::orderBy('codigo')->get());
    }

    /** Los proyectos a los que tiene acceso el usuario de la sesión (USUARIO_PROYECTO). */
    public function asignados(Request $request): AnonymousResourceCollection
    {
        $proyectos = Proyecto::whereHas('usuarios', fn ($q) => $q->whereKey($request->user()->id))
            ->orderBy('codigo')
            ->get();

        return ProyectoResource::collection($proyectos);
    }

    public function store(GuardarProyectoRequest $request): JsonResponse
    {
        // Quien lo crea y su responsable quedan asignados: sin eso no lo ven en el encabezado.
        $proyecto = DB::transaction(function () use ($request) {
            $proyecto = Proyecto::create([
                ...$request->safe()->except('estado'),
                'estado' => EstadoProyecto::Activo,
            ]);
            $proyecto->usuarios()->syncWithoutDetaching([$request->user()->id, $proyecto->ingeniero_id]);

            return $proyecto;
        });

        return ProyectoResource::make($proyecto)->response()->setStatusCode(201);
    }

    public function update(GuardarProyectoRequest $request, Proyecto $proyecto): ProyectoResource
    {
        // El responsable anterior conserva su acceso: quitarlo lo decide el administrador en Usuarios.
        DB::transaction(function () use ($request, $proyecto) {
            $proyecto->update($request->validated());
            $proyecto->usuarios()->syncWithoutDetaching([$proyecto->ingeniero_id]);
        });

        return ProyectoResource::make($proyecto);
    }

    /**
     * Responsables posibles: ingenieros de producción activos. `conservar` agrega al responsable
     * actual de un proyecto aunque esté inactivo; no sirve para leer a cualquier usuario.
     */
    public function ingenieros(Request $request): JsonResponse
    {
        $conservar = filter_var($request->query('conservar'), FILTER_VALIDATE_INT) ?: null;

        $ingenieros = User::query()
            ->where(fn ($q) => $q
                ->where('activo', true)
                ->whereHas('rol', fn ($q) => $q->where('clave', Rol::IngenieroProduccion)))
            ->when($conservar, fn ($q) => $q->orWhere(fn ($q) => $q
                ->whereKey($conservar)
                ->whereIn('id', Proyecto::select('ingeniero_id'))))
            ->orderBy('nombres')
            ->orderBy('apellidos')
            ->get(['id', 'nombres', 'apellidos']);

        return response()->json($ingenieros->map(fn (User $u) => ['id' => $u->id, 'nombre' => $u->nombre_completo]));
    }

    /** Solo id y nombre, para asignar proyectos a los usuarios (CU02). */
    public function opciones(): JsonResponse
    {
        $proyectos = Proyecto::orderBy('codigo')->get(['id', 'nombre']);

        return response()->json($proyectos->map(fn (Proyecto $p) => ['id' => $p->id, 'nombre' => $p->nombre]));
    }
}
