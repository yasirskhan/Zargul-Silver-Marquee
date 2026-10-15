<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->boolean('is_active')->default(true);
            $table->rememberToken();
            $table->timestampsTz();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('companies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('slug')->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        Schema::create('halls', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('slug');
            $table->string('name');
            $table->unsignedInteger('capacity');
            $table->unsignedInteger('included_chairs')->default(0);
            $table->unsignedInteger('included_tables')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->unique(['company_id', 'slug']);
            // Supports a composite FK that prevents assigning a hall across companies.
            $table->unique(['company_id', 'id']);
        });

        Schema::create('company_memberships', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('role', 24);
            $table->boolean('is_active')->default(true);
            $table->boolean('can_edit_site')->default(false);
            $table->boolean('can_publish_site')->default(false);
            $table->timestampsTz();
            $table->unique(['company_id', 'user_id']);
            $table->unique(['company_id', 'id']);
        });

        Schema::create('hall_assignments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('company_id');
            $table->unsignedBigInteger('membership_id');
            $table->uuid('hall_id');
            $table->unique(['membership_id', 'hall_id']);
            $table->foreign(['company_id', 'membership_id'])
                ->references(['company_id', 'id'])->on('company_memberships')->restrictOnDelete();
            $table->foreign(['company_id', 'hall_id'])
                ->references(['company_id', 'id'])->on('halls')->restrictOnDelete();
        });

        // Constraints run against PostgreSQL in CI. Do not silently rely on UI validation.
        DB::statement('ALTER TABLE halls ADD CONSTRAINT halls_capacity_positive CHECK (capacity > 0)');
        DB::statement('ALTER TABLE halls ADD CONSTRAINT halls_seating_nonnegative CHECK (included_chairs >= 0 AND included_tables >= 0)');
        DB::statement("ALTER TABLE company_memberships ADD CONSTRAINT memberships_role_allowed CHECK (role IN ('owner', 'admin', 'manager', 'reception', 'cashier', 'operations'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('hall_assignments');
        Schema::dropIfExists('company_memberships');
        Schema::dropIfExists('halls');
        Schema::dropIfExists('companies');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
