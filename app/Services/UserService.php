<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class UserService
{
    public function search(?string $q, ?string $role, int $perPage = 20): LengthAwarePaginator
    {
        $query = User::with(['roles', 'permissions']);

        if ($q) {
            $query->where('email', 'like', '%'.$q.'%');
        }

        if ($role) {
            $query->role($role, 'api');
        }

        return $query->orderBy('id')->paginate($perPage);
    }

    public function find(int $id): User
    {
        return User::with(['roles', 'permissions'])->findOrFail($id);
    }

    public function create(array $data): User
    {
        $user = User::create([
            'email' => $data['email'],
            'password' => $data['password'],
        ]);

        if (! empty($data['roles'])) {
            $user->syncRoles($data['roles']);
        }

        return $user;
    }

    public function delete(int $id): void
    {
        $user = User::findOrFail($id);
        $user->tokens()->delete();
        $user->delete();
    }

    public function syncRoles(int $id, array $roles): User
    {
        $user = User::findOrFail($id);
        $user->syncRoles($roles);

        return $user->fresh(['roles', 'permissions']);
    }

    public function syncPermissions(int $id, array $permissions): User
    {
        $user = User::findOrFail($id);
        $user->syncPermissions($permissions);

        return $user->fresh(['roles', 'permissions']);
    }

    public function listRoles(): Collection
    {
        return \Spatie\Permission\Models\Role::where('guard_name', 'api')
            ->with('permissions')
            ->orderBy('name')
            ->get();
    }

    public function listPermissions(): Collection
    {
        return \Spatie\Permission\Models\Permission::where('guard_name', 'api')
            ->orderBy('name')
            ->get();
    }
}
