<?php

// Spatie Laravel Permission — published config
// Only the values we've intentionally changed from defaults are documented here.
// Full option reference: vendor/spatie/laravel-permission/config/permission.php

return [

    'models' => [
        // Use the default Eloquent models. Override here if you ever need custom
        // Role or Permission models (e.g. to add a `description` column).
        'permission' => Spatie\Permission\Models\Permission::class,
        'role'       => Spatie\Permission\Models\Role::class,
    ],

    'table_names' => [
        'roles'                 => 'roles',
        'permissions'           => 'permissions',
        'model_has_permissions' => 'model_has_permissions',
        'model_has_roles'       => 'model_has_roles',
        'role_has_permissions'  => 'role_has_permissions',
    ],

    'column_names' => [
        // The FK column Spatie adds to model_has_roles / model_has_permissions.
        // Must match the users primary key type (bigIncrements → unsignedBigInteger).
        'role_pivot_key'       => null,   // null = use default 'role_id'
        'permission_pivot_key' => null,   // null = use default 'permission_id'
        'model_morph_key'      => 'model_id',
        'team_foreign_key'     => 'team_id',
    ],

    // Teams support is OFF. We have no multi-tenancy requirement today.
    // Enabling this later is a migration, not just a config toggle — plan it.
    'teams' => false,

    // Cache the permission graph so can() doesn't hit the DB on every request.
    // Redis is the cache store (ADR-015), so this is a shared, expiring cache —
    // not a per-process in-memory map that goes stale across restarts.
    'cache' => [
        'expiration_time' => \DateInterval::createFromDateString('24 hours'),
        'key'             => 'spatie.permission.cache',
        'store'           => 'default',   // resolves to CACHE_STORE in .env → redis
    ],

];
