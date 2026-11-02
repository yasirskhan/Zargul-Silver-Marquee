<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hall_media', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignUuid('hall_id')->constrained('halls')->restrictOnDelete();
            $table->foreignUuid('media_id')->constrained('site_media')->restrictOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->unique(['company_id', 'hall_id', 'media_id']);
            $table->unique(['company_id', 'hall_id', 'sort_order']);
            $table->index(['company_id', 'hall_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hall_media');
    }
};