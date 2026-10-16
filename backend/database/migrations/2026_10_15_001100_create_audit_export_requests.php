<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Internal request idempotency registry only. This is NOT an off-site
        // archive, public download, independent key store or retention plan.
        Schema::create('audit_export_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->char('request_hash', 64);
            $table->unsignedInteger('entry_count');
            $table->char('sha256', 64);
            $table->longText('encrypted_payload');
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->index(['company_id', 'created_at']);
        });
        DB::statement('ALTER TABLE audit_export_requests ADD CONSTRAINT audit_export_entry_count_limit CHECK (entry_count <= 500)');
        DB::statement('ALTER TABLE audit_export_requests ADD CONSTRAINT audit_export_hash_sizes CHECK (char_length(request_hash) = 64 AND char_length(sha256) = 64)');
        DB::statement('CREATE TRIGGER immutable_audit_export_requests BEFORE UPDATE OR DELETE ON audit_export_requests FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()');
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_export_requests');
    }
};
