<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Internal, tenant-scoped customer identity storage foundation.
        // Encrypted fields are opaque to PostgreSQL and may only be populated
        // through an authenticated, audited service (not a public form).
        Schema::create('customer_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->text('name_ciphertext');
            $table->text('email_ciphertext')->nullable();
            $table->text('phone_ciphertext')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'is_active']);
        });

        DB::statement('ALTER TABLE customer_profiles ADD CONSTRAINT customer_revision_positive CHECK (revision > 0)');
        DB::statement('ALTER TABLE customer_profiles ADD CONSTRAINT customer_name_nonempty CHECK (length(name_ciphertext) > 20)');

        // Store redacted change summaries, never cleartext personal contact
        // details in append-only events. Business services must implement
        // audited CAS updates and explicit retention/anonymization policy.
        Schema::create('customer_profile_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('customer_id');
            $table->string('action', 24);
            $table->unsignedInteger('revision');
            $table->jsonb('before_values')->nullable();
            $table->jsonb('after_values')->nullable();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('reason', 500);
            $table->timestampTz('created_at')->useCurrent();
            $table->foreign(['company_id', 'customer_id'])
                ->references(['company_id', 'id'])->on('customer_profiles')->restrictOnDelete();
            $table->unique(['customer_id', 'revision']);
            $table->index(['company_id', 'created_at']);
        });

        DB::statement('ALTER TABLE customer_profile_events ADD CONSTRAINT customer_event_revision_positive CHECK (revision > 0)');
        DB::statement("ALTER TABLE customer_profile_events ADD CONSTRAINT customer_event_action_allowed CHECK (action IN ('created', 'updated', 'deactivated'))");
        DB::statement('CREATE TRIGGER immutable_customer_profile_events BEFORE UPDATE OR DELETE ON customer_profile_events FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()');
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_profile_events');
        Schema::dropIfExists('customer_profiles');
    }
};
