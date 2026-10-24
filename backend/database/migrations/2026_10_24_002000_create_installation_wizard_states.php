<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installation_wizard_states', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->unique();
            $table->unsignedSmallInteger('step')->default(1);
            $table->unsignedInteger('revision')->default(1);
            $table->jsonb('data');
            $table->string('status', 16)->default('draft');
            $table->timestampTz('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->index(['status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installation_wizard_states');
    }
};
