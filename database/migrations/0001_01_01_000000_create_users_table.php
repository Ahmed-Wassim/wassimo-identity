<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Intentionally minimal: identity owns credentials and tokens, nothing more.
     *
     * Removed from the Laravel default:
     *   - name          → not an API concern; no display name needed here
     *   - remember_token → session-based auth only; we use Sanctum bearer tokens
     *   - sessions table  → SESSION_DRIVER=file (API is stateless)
     *   - password_reset_tokens → deferred to a later slice (ADR-017 Group B)
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('password');           // stored as bcrypt hash via Eloquent cast
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
