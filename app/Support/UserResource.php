<?php

namespace App\Support;

use App\Models\User;

/**
 * Frozen response shape for a User.
 *
 * Using a dedicated mapper (not $user->toArray() or a JsonResource) means
 * new columns added to the users table never appear in API responses until
 * they are explicitly listed here. ADR-013 v2.
 *
 * Roles and permissions are included so the gateway can extract them for the
 * X-User-Roles / X-User-Permissions headers without a second call.
 */
class UserResource
{
    /**
     * Serialize a user to the API response shape.
     *
     * @return array<string, mixed>
     */
    public static function make(User $user): array
    {
        // getAllPermissions() merges role permissions + any direct grants.
        // Both are needed: a user with a direct permission beyond their role
        // must have it propagated to downstream services.
        return [
            'id'          => $user->id,
            'email'       => $user->email,
            'roles'       => $user->getRoleNames()->values()->all(),
            'permissions' => $user->getAllPermissions()->pluck('name')->values()->all(),
            'created_at'  => $user->created_at?->toIso8601String(),
        ];
    }
}
