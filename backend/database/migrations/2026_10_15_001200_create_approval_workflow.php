<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Internal proposed exceptions only; no price, invoice, refund or booking mutation.
        Schema::create('approval_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->uuid('booking_id')->nullable();
            $table->string('category', 32);
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('reason', 500);
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('status', 16)->default('pending');
            $table->unsignedInteger('revision')->default(1);
            $table->timestampsTz();
            $table->unique(['company_id', 'id']);
            $table->foreign(['company_id', 'booking_id'])->references(['company_id', 'id'])
                ->on('booking_drafts')->restrictOnDelete();
            $table->index(['company_id', 'status', 'created_at']);
        });
        DB::statement("ALTER TABLE approval_requests ADD CONSTRAINT approval_request_category_check CHECK (category IN ('discount', 'refund', 'overtime_waiver', 'complimentary_service', 'price_override', 'expense'))");
        DB::statement("ALTER TABLE approval_requests ADD CONSTRAINT approval_request_status_check CHECK (status IN ('pending', 'approved', 'rejected'))");
        DB::statement('ALTER TABLE approval_requests ADD CONSTRAINT approval_request_amount_check CHECK (amount_minor > 0 AND amount_minor <= 100000000000)');
        DB::statement("ALTER TABLE approval_requests ADD CONSTRAINT approval_request_currency_check CHECK (currency ~ '^[A-Z]{3}$')");
        DB::statement('ALTER TABLE approval_requests ADD CONSTRAINT approval_request_revision_check CHECK (revision >= 1)');

        Schema::create('approval_delegations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('granted_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('delegated_to')->constrained('users')->restrictOnDelete();
            $table->jsonb('categories');
            $table->bigInteger('ceiling_minor');
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->string('reason', 500);
            $table->timestampsTz();
            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'delegated_to', 'starts_at', 'ends_at']);
        });
        DB::statement('ALTER TABLE approval_delegations ADD CONSTRAINT approval_delegation_dates_check CHECK (ends_at > starts_at)');
        DB::statement('ALTER TABLE approval_delegations ADD CONSTRAINT approval_delegation_ceiling_check CHECK (ceiling_minor > 0 AND ceiling_minor <= 100000000000)');
        DB::statement('ALTER TABLE approval_delegations ADD CONSTRAINT approval_delegation_not_self_check CHECK (granted_by <> delegated_to)');
        DB::statement("ALTER TABLE approval_delegations ADD CONSTRAINT approval_delegation_categories_check CHECK (jsonb_typeof(categories) = 'array' AND jsonb_array_length(categories) BETWEEN 1 AND 6)");

        Schema::create('approval_decisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('approval_id');
            $table->foreignId('decided_by')->constrained('users')->restrictOnDelete();
            $table->uuid('delegation_id')->nullable();
            $table->string('decision', 16);
            $table->string('reason', 500);
            $table->timestampTz('created_at')->useCurrent();
            $table->unique('approval_id');
            $table->foreign(['company_id', 'approval_id'])->references(['company_id', 'id'])
                ->on('approval_requests')->restrictOnDelete();
            $table->foreign(['company_id', 'delegation_id'])->references(['company_id', 'id'])
                ->on('approval_delegations')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE approval_decisions ADD CONSTRAINT approval_decision_allowed_check CHECK (decision IN ('approved', 'rejected'))");

        Schema::create('approval_delegation_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('delegation_id');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 16);
            $table->string('reason', 500);
            $table->timestampTz('created_at')->useCurrent();
            $table->foreign(['company_id', 'delegation_id'])->references(['company_id', 'id'])
                ->on('approval_delegations')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE approval_delegation_events ADD CONSTRAINT approval_delegation_action_check CHECK (action IN ('granted', 'revoked'))");

        // Tenant-private, durable in-app alert references; NO Telegram recipient or raw contact.
        Schema::create('approval_alert_outbox', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('approval_id');
            $table->string('event_type', 16);
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['approval_id', 'event_type']);
            $table->foreign(['company_id', 'approval_id'])->references(['company_id', 'id'])
                ->on('approval_requests')->restrictOnDelete();
            $table->index(['company_id', 'created_at']);
        });
        DB::statement("ALTER TABLE approval_alert_outbox ADD CONSTRAINT approval_alert_type_check CHECK (event_type IN ('requested', 'decided'))");

        foreach (['approval_decisions', 'approval_delegation_events', 'approval_alert_outbox'] as $name) {
            DB::statement("CREATE TRIGGER immutable_{$name} BEFORE UPDATE OR DELETE ON {$name} FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_alert_outbox');
        Schema::dropIfExists('approval_delegation_events');
        Schema::dropIfExists('approval_decisions');
        Schema::dropIfExists('approval_delegations');
        Schema::dropIfExists('approval_requests');
    }
};
