<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProveedorRequest;
use App\Http\Resources\ProveedorResource;
use App\Models\Proveedor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ProveedorController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $buscar = trim((string) $request->query('buscar'));

        $proveedores = Proveedor::query()
            ->when($buscar !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('ruc', 'like', "%{$buscar}%")
                ->orWhere('razon_social', 'like', "%{$buscar}%")))
            ->orderBy('razon_social')
            ->paginate($request->integer('per_page', 15));

        return ProveedorResource::collection($proveedores);
    }

    public function store(ProveedorRequest $request): ProveedorResource
    {
        return new ProveedorResource(Proveedor::create($request->validated()));
    }

    public function show(Proveedor $proveedor): ProveedorResource
    {
        return new ProveedorResource($proveedor);
    }

    public function update(ProveedorRequest $request, Proveedor $proveedor): ProveedorResource
    {
        $proveedor->update($request->validated());

        return new ProveedorResource($proveedor);
    }

    public function destroy(Proveedor $proveedor): Response
    {
        $proveedor->delete();

        return response()->noContent();
    }
}
