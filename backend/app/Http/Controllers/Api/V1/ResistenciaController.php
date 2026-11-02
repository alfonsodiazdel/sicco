<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ResistenciaResource;
use App\Models\Resistencia;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Catálogo de resistencias f'c, para los selectores del presupuesto y de la guía. */
class ResistenciaController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return ResistenciaResource::collection(Resistencia::where('activo', true)->orderBy('fc_kg_cm2')->get());
    }
}
