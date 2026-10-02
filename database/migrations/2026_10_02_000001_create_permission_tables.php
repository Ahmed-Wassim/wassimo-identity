<?php

// Spatie permission tables — hand-written equivalent of the published migration.
//
// WHY hand-written instead of `php artisan vendor:publish`:
//   The published version uses varchar(255) on indexed columns, which hits
//   MySQL 8's 3072-byte index key limit with utf8mb4 (255 × 4 bytes × 3 cols).
//   Schema::defaultStringLength(191) in AppServiceProvider already fixes this
//   globally, but writing the migration explicitly makes the intention visible
//   and prevents the "why did artisan publish break?" confusion. ADR-014.
//
// Column widths here deliberately match the 191 cap.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableNames   = config('permission.table_names');
        $columnNames  = config('permission.column_names');
        $pivotRole    = $columnNames['role_pivot_key']       ?? 'role_id';
        $pivotPermission = $columnNames['permission_pivot_key'] ?? 'permission_id';

        Schema::create($tableNames['permissions'], function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 191);        // e.g. "orders.read"
            $table->string('guard_name', 191);  // always "api" for this service
            $table->timestamps();

            $table->unique(['name', 'guard_name']);
        });

        Schema::create($tableNames['roles'], function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 191);        // e.g. "customer"
            $table->string('guard_name', 191);
            $table->timestamps();

            $table->unique(['name', 'guard_name']);
        });

        Schema::create($tableNames['model_has_permissions'], function (Blueprint $table) use ($tableNames, $columnNames, $pivotPermission) {
            $table->unsignedBigInteger($pivotPermission);
            $table->string('model_type', 191);
            $table->unsignedBigInteger($columnNames['model_morph_key']);

            $table->index([$columnNames['model_morph_key'], 'model_type'], 'model_has_permissions_model_id_model_type_index');

            $table->foreign($pivotPermission)
                  ->references('id')
                  ->on($tableNames['permissions'])
                  ->onDelete('cascade');

            $table->primary([$pivotPermission, $columnNames['model_morph_key'], 'model_type'],
                            'model_has_permissions_permission_model_type_primary');
        });

        Schema::create($tableNames['model_has_roles'], function (Blueprint $table) use ($tableNames, $columnNames, $pivotRole) {
            $table->unsignedBigInteger($pivotRole);
            $table->string('model_type', 191);
            $table->unsignedBigInteger($columnNames['model_morph_key']);

            $table->index([$columnNames['model_morph_key'], 'model_type'], 'model_has_roles_model_id_model_type_index');

            $table->foreign($pivotRole)
                  ->references('id')
                  ->on($tableNames['roles'])
                  ->onDelete('cascade');

            $table->primary([$pivotRole, $columnNames['model_morph_key'], 'model_type'],
                            'model_has_roles_role_model_type_primary');
        });

        Schema::create($tableNames['role_has_permissions'], function (Blueprint $table) use ($tableNames, $pivotRole, $pivotPermission) {
            $table->unsignedBigInteger($pivotPermission);
            $table->unsignedBigInteger($pivotRole);

            $table->foreign($pivotPermission)
                  ->references('id')
                  ->on($tableNames['permissions'])
                  ->onDelete('cascade');

            $table->foreign($pivotRole)
                  ->references('id')
                  ->on($tableNames['roles'])
                  ->onDelete('cascade');

            $table->primary([$pivotPermission, $pivotRole], 'role_has_permissions_permission_id_role_id_primary');
        });

        // Bust the Spatie permission cache so the first request after migration
        // gets a fresh graph rather than a stale pre-migration snapshot.
        app('cache')
            ->store(config('permission.cache.store') !== 'default' ? config('permission.cache.store') : null)
            ->forget(config('permission.cache.key'));
    }

    public function down(): void
    {
        $tableNames = config('permission.table_names');

        Schema::drop($tableNames['role_has_permissions']);
        Schema::drop($tableNames['model_has_roles']);
        Schema::drop($tableNames['model_has_permissions']);
        Schema::drop($tableNames['roles']);
        Schema::drop($tableNames['permissions']);
    }
};
