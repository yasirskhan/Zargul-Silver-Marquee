<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('site_domain_claims', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('website_id');
            $table->string('domain', 253)->unique();
            $table->char('token_hash', 64);
            $table->string('status', 16)->default('pending');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('expires_at');
            $table->timestampTz('verified_at')->nullable();
            $table->timestampsTz();
            $table->foreign(['company_id', 'website_id'])
                ->references(['company_id', 'id'])->on('websites')->restrictOnDelete();
            $table->index(['company_id', 'website_id', 'status']);
        });
        DB::statement("ALTER TABLE site_domain_claims ADD CONSTRAINT domain_claim_status_valid CHECK (status IN ('pending', 'verified'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('site_domain_claims');
    }
};
