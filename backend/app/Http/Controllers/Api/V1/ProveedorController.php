<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Rol;
use App\Http\Controllers\Controller;
use App\Http\Requests\ProveedorRequest;
use App\Http\Resources\ProveedorDetalleResource;
use App\Http\Resources\ProveedorResource;
use App\Models\Proveedor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;

class ProveedorController extends Controller
{
    /** Los datos de contacto son personales: solo los ven quienes gestionan proveedores. */
    private function recurso(Request $request): string
    {
        return $request->user()->tieneRol(Rol::OficinaTecnica, Rol::Administrador)
            ? ProveedorDetalleResource::class
            : ProveedorResource::class;
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $buscar = trim((string) $request->query('buscar'));

        $proveedores = Proveedor::query()
            ->when($buscar !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('ruc', 'like', "%{$buscar}%")
                ->orWhere('razon_social', 'like', "%{$buscar}%")))
            ->when($request->boolean('activo'), fn ($q) => $q->where('activo', true))
            ->orderBy('razon_social')
            ->paginate($request->integer('per_page', 15));

        return $this->recurso($request)::collection($proveedores);
    }

    public function store(ProveedorRequest $request): JsonResource
    {
        return $this->recurso($request)::make(Proveedor::create($request->validated())->refresh());
    }

    public function show(Request $request, Proveedor $proveedor): JsonResource
    {
        return $this->recurso($request)::make($proveedor);
    }

    public function update(ProveedorRequest $request, Proveedor $proveedor): JsonResource
    {
        $proveedor->update($request->validated());

        return $this->recurso($request)::make($proveedor);
    }

    public function destroy(Proveedor $proveedor): Response
    {
        $proveedor->delete();

        return response()->noContent();
    }
}
