<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds the four Wassimo roles and their permission sets.
 *
 * RULE (ADR-016): permission names are a public contract.
 * Renaming a permission is a breaking change for any service that reads
 * X-User-Permissions. Change the name here only by amending ADR-016 first.
 *
 * Format: <domain>.<action>  — lowercase, dot-separated.
 * Guard:  "api" (every request goes through the API guard; web is unused).
 *
 * Run with:
 *   php artisan db:seed --class=RolePermissionSeeder
 *   php artisan db:seed   (via DatabaseSeeder)
 */
class RolePermissionSeeder extends Seeder
{
    // ------------------------------------------------------------------ //
    // Permission registry                                                  //
    // ------------------------------------------------------------------ //

    /**
     * All permissions, grouped by the service/domain that enforces them.
     * The gateway propagates these verbatim in X-User-Permissions.
     *
     * @var array<string, list<string>>
     */
    private const PERMISSIONS = [

        // ── Catalog (read) ──────────────────────────────────────────────
        // Public browsing is open; these are for operator-level writes.
        'catalog' => [
            'catalog.read',           // browse restaurants / menus (fallback for auth'd routes)
            'menu.write',             // create / update / toggle items (restaurant operators)
            'restaurant.manage',      // create branches, edit restaurant details
        ],

        // ── Cart ────────────────────────────────────────────────────────
        'cart' => [
            'cart.write',             // add / update / remove items
            'cart.read',              // view own cart
        ],

        // ── Orders ──────────────────────────────────────────────────────
        'orders' => [
            'orders.place',           // POST /orders (checkout)
            'orders.read',            // GET /orders/:id — own orders
            'orders.read_all',        // admin / restaurant view of all orders
            'orders.cancel',          // cancel own order
            'orders.accept',          // restaurant: accept incoming order
            'orders.reject',          // restaurant: reject incoming order
            'orders.prepare',         // restaurant: mark as preparing
            'orders.ready',           // restaurant: mark as ready for pickup
        ],

        // ── Delivery ────────────────────────────────────────────────────
        'delivery' => [
            'deliveries.read',        // view own delivery
            'deliveries.accept',      // courier: accept assignment
            'deliveries.pickup',      // courier: mark picked up
            'deliveries.complete',    // courier: mark delivered
            'deliveries.manage',      // admin: view / reassign all deliveries
        ],

        // ── Payments ────────────────────────────────────────────────────
        'payments' => [
            'payments.read',          // view own payment status
            'payments.manage',        // admin: refunds, manual adjustments
        ],

        // ── Identity / user administration ──────────────────────────────
        'users' => [
            'users.read',             // GET /users/:id (own profile via /auth/me is separate)
            'users.list',             // GET /users (paginated list — admin only)
            'users.create',           // POST /users
            'users.delete',           // DELETE /users/:id
            'users.assign_roles',     // PUT /users/:id/roles
            'users.assign_permissions', // PUT /users/:id/permissions (direct grants)
        ],
    ];

    // ------------------------------------------------------------------ //
    // Role → permissions map                                               //
    // ------------------------------------------------------------------ //

    /**
     * What each role can do.
     * Permissions not listed here are denied by default.
     *
     * admin inherits everything via Gate::before in AppServiceProvider —
     * listing permissions here is optional but makes the intent explicit.
     *
     * @var array<string, list<string>>
     */
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
            // Admin is handled by Gate::before (returns null to allow all).
            // We still assign every permission so that admin shows up correctly
            // in permission listings and X-User-Permissions headers.
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

    // ------------------------------------------------------------------ //
    // Seeder                                                               //
    // ------------------------------------------------------------------ //

    public function run(): void
    {
        // Reset the Spatie permission cache so previous runs don't interfere.
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // ── 1. Create all permissions ──────────────────────────────────
        $created = collect();

        foreach (self::PERMISSIONS as $permissions) {
            foreach ($permissions as $name) {
                $created[$name] = Permission::firstOrCreate(
                    ['name' => $name, 'guard_name' => 'api']
                );
            }
        }

        // ── 2. Create roles and assign permissions ─────────────────────
        foreach (self::ROLE_PERMISSIONS as $roleName => $permissionNames) {
            $role = Role::firstOrCreate(
                ['name' => $roleName, 'guard_name' => 'api']
            );

            // syncPermissions replaces the current set — idempotent on re-seed.
            $role->syncPermissions(
                collect($permissionNames)->map(fn ($p) => $created[$p])->all()
            );
        }

        $this->command->info('Roles and permissions seeded.');
        $this->command->table(
            ['Role', 'Permissions'],
            collect(self::ROLE_PERMISSIONS)->map(
                fn ($perms, $role) => [$role, implode(', ', $perms)]
            )->values()->toArray()
        );
    }
}
