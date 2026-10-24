<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use PHPOpenSourceSaver\JWTAuth\JWTGuard;

class AuthController extends Controller
{
    /**
     * POST /api/v1/auth/login — autentica y devuelve el JWT.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $token = Auth::guard('api')->attempt($request->validated());

        if (! $token) {
            throw ValidationException::withMessages([
                'email' => 'Las credenciales no son válidas.',
            ]);
        }

        return $this->respondWithToken($token);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    public function refresh(): JsonResponse
    {
        return $this->respondWithToken(Auth::guard('api')->refresh());
    }

    /**
     * Invalida el token (se agrega a la blacklist almacenada en Redis).
     */
    public function logout(): JsonResponse
    {
        Auth::guard('api')->logout();

        return response()->json(['message' => 'Sesión cerrada correctamente.']);
    }

    private function respondWithToken(string $token): JsonResponse
    {
        /** @var JWTGuard $guard */
        $guard = Auth::guard('api');

        return response()->json([
            'accessToken' => $token,
            'tokenType' => 'Bearer',
            'expiresIn' => $guard->factory()->getTTL() * 60,
            'user' => new UserResource($guard->user()),
        ]);
    }
}
