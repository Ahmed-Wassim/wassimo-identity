<?php

namespace App\Support;

use App\Models\User;

class UserResource
{
    public static function make(User $user): array
    {
        return [
            'id'          => $user->id,
            'email'       => $user->email,
            'roles'       => $user->getRoleNames()->values()->all(),
            'permissions' => $user->getAllPermissions()->pluck('name')->values()->all(),
            'created_at'  => $user->created_at?->toIso8601String(),
        ];
    }
}
