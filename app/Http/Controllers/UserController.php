<?php

namespace App\Http\Controllers;

use App\Services\UserService;
use App\Support\UserResource;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function __construct(private UserService $users) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', 'string', 'max:64'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paginated = $this->users->search(
            $request->input('q'),
            $request->input('role'),
            (int) $request->input('per_page', 20),
        );

        return response()->json([
            'users' => collect($paginated->items())->map(fn ($u) => UserResource::make($u)),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'last_page' => $paginated->lastPage(),
            ],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(['user' => UserResource::make($this->users->find($id))]);
    }

    public function store(Request $request): JsonResponse
    {
        // Same 409 rule as AuthController@register: `unique` fails as 422.
        $data = $request->validate([
            'email' => ['required', 'email', 'max:191'],
            'password' => ['required', 'string', 'min:8', 'max:72'],
            'roles' => ['nullable', 'array'],
            'roles.*' => ['string', 'exists:roles,name'],
        ]);

        if (\App\Models\User::where('email', $data['email'])->exists()) {
            return response()->json(['error' => 'email already registered'], 409);
        }

        try {
            $user = $this->users->create($data);
        } catch (QueryException $e) {
            if ((string) $e->getCode() === '23000') {
                return response()->json(['error' => 'email already registered'], 409);
            }
            throw $e;
        }

        return response()->json(['user' => UserResource::make($user)], 201);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->users->delete($id);

        return response()->json(null, 204);
    }

    public function syncRoles(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'roles' => ['required', 'array'],
            'roles.*' => ['string', 'exists:roles,name'],
        ]);

        return response()->json(['user' => UserResource::make($this->users->syncRoles($id, $request->roles))]);
    }

    public function syncPermissions(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'permissions' => ['required', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        return response()->json(['user' => UserResource::make($this->users->syncPermissions($id, $request->permissions))]);
    }

    public function roles(): JsonResponse
    {
        $roles = $this->users->listRoles()->map(fn ($r) => [
            'id' => $r->id,
            'name' => $r->name,
            'permissions' => $r->permissions->pluck('name')->values()->all(),
        ]);

        return response()->json(['roles' => $roles]);
    }

    public function permissions(): JsonResponse
    {
        $permissions = $this->users->listPermissions()->map(fn ($p) => [
            'id' => $p->id,
            'name' => $p->name,
        ]);

        return response()->json(['permissions' => $permissions]);
    }
}
