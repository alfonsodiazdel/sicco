<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\RolResource;
use App\Models\Rol;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RolController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return RolResource::collection(Rol::orderBy('id')->get());
    }
}
