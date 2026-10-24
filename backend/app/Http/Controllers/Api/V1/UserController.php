<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * CU-A01: gestión de usuarios y roles (sólo ADMIN).
 */
class UserController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $users = User::query()
            ->when($request->query('role'), fn ($q, $role) => $q->where('role', strtoupper($role)))
            ->orderBy('name')
            ->paginate(20);

        return UserResource::collection($users);
    }

    /**
     * GET /api/v1/technicians — lista de técnicos/admins a quienes escalar un ticket (CU-T01).
     * Accesible para todo el staff, no sólo ADMIN.
     */
    public function technicians(): AnonymousResourceCollection
    {
        return UserResource::collection(
            User::query()->whereIn('role', [Role::TECHNICIAN, Role::ADMIN])->orderBy('name')->get()
        );
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = User::query()->create($request->validated());

        return (new UserResource($user))->response()->setStatusCode(201);
    }

    public function update(StoreUserRequest $request, User $user): UserResource
    {
        $user->fill($request->validated())->save();

        return new UserResource($user);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        abort_if($user->id === $request->user()->id, 422, 'No puede eliminar su propio usuario.');

        $user->delete();

        return response()->json(status: 204);
    }
}
