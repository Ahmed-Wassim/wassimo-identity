<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    private const PERMISSIONS = [
        'catalog' => [
            'catalog.read',
            'menu.write',
            'restaurant.manage',
        ],
        'cart' => [
            'cart.write',
            'cart.read',
        ],
        'orders' => [
            'orders.place',
            'orders.read',
            'orders.read_all',
            'orders.cancel',
            'orders.accept',
            'orders.reject',
            'orders.prepare',
            'orders.ready',
        ],
        'delivery' => [
            'deliveries.read',
            'deliveries.accept',
            'deliveries.pickup',
            'deliveries.complete',
            'deliveries.manage',
        ],
        'payments' => [
            'payments.read',
            'payments.manage',
        ],
        'users' => [
            'users.read',
            'users.list',
            'users.create',
            'users.delete',
            'users.assign_roles',
            'users.assign_permissions',
        ],
    ];

    private const ROLE_PERMISSIONS = [
        'customer' => [
            'catalog.read',
            'cart.write',
            'cart.read',
            'orders.place',
            'orders.read',
            'orders.cancel',
            'deliveries.read',
            'payments.read',
        ],
        'restaurant' => [
            'catalog.read',
            'menu.write',
            'restaurant.manage',
            'orders.read_all',
            'orders.accept',
            'orders.reject',
            'orders.prepare',
            'orders.ready',
        ],
        'courier' => [
            'catalog.read',
            'deliveries.read',
            'deliveries.accept',
            'deliveries.pickup',
            'deliveries.complete',
        ],
        'admin' => [
            'catalog.read',
            'menu.write',
            'restaurant.manage',
            'cart.write',
            'cart.read',
            'orders.place',
            'orders.read',
            'orders.read_all',
            'orders.cancel',
            'orders.accept',
            'orders.reject',
            'orders.prepare',
            'orders.ready',
            'deliveries.read',
            'deliveries.accept',
            'deliveries.pickup',
            'deliveries.complete',
            'deliveries.manage',
            'payments.read',
            'payments.manage',
            'users.read',
            'users.list',
            'users.create',
            'users.delete',
            'users.assign_roles',
            'users.assign_permissions',
        ],
    ];

    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $created = collect();

        foreach (self::PERMISSIONS as $permissions) {
            foreach ($permissions as $name) {
                $created[$name] = Permission::firstOrCreate([
                    'name'       => $name,
                    'guard_name' => 'api',
                ]);
            }
        }

        foreach (self::ROLE_PERMISSIONS as $roleName => $permissionNames) {
            $role = Role::firstOrCreate([
                'name'       => $roleName,
                'guard_name' => 'api',
            ]);

            $role->syncPermissions(
                collect($permissionNames)->map(fn ($p) => $created[$p])->all()
            );
        }

        $this->command->info('Roles and permissions seeded.');
        $this->command->table(
            ['Role', 'Permissions'],
            collect(self::ROLE_PERMISSIONS)
                ->map(fn ($perms, $role) => [$role, implode(', ', $perms)])
                ->values()
                ->toArray()
        );
    }
}
