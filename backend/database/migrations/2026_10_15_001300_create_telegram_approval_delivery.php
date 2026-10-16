<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // The one-time pairing secret is NEVER persisted in plaintext.
        Schema::create('telegram_pair_challenges', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['company_id', 'owner_id', 'created_at']);
        });

        Schema::create('telegram_owner_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->text('chat_id_encrypted');
            $table->char('chat_fingerprint', 64);
            $table->timestampTz('verified_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
            $table->unique(['company_id', 'owner_id']);
            $table->unique(['company_id', 'chat_fingerprint']);
        });

        // Durable webhook update replay protection. No received message content.
        Schema::create('telegram_webhook_updates', function (Blueprint $table): void {
            $table->bigInteger('update_id')->primary();
            $table->timestampTz('created_at')->useCurrent();
        });

        // Composite foreign key requires unique (company_id, id) on the parent.
        // Outbox was created before Telegram transport; add the scope key here.
        Schema::table('approval_alert_outbox', function (Blueprint $table): void {
            $table->unique(['company_id', 'id']);
        });

        // Separate mutable delivery state from the immutable internal approval outbox.
        // Local de-duplication is at-least-once across process crashes, because
        // Bot API sendMessage provides no idempotency key.
        Schema::create('telegram_approval_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('alert_id');
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('sent_at')->nullable();
            $table->bigInteger('telegram_message_id')->nullable();
            $table->smallInteger('last_http_status')->nullable();
            $table->timestampsTz();
            $table->unique(['alert_id', 'owner_id']);
            $table->foreign(['company_id', 'alert_id'])
                ->references(['company_id', 'id'])->on('approval_alert_outbox')->restrictOnDelete();
            $table->index(['company_id', 'sent_at']);
        });
        DB::statement('ALTER TABLE telegram_approval_deliveries ADD CONSTRAINT telegram_delivery_attempts_check CHECK (attempts <= 3)');
        DB::statement('CREATE TRIGGER immutable_telegram_webhook_updates BEFORE UPDATE OR DELETE ON telegram_webhook_updates FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()');
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_approval_deliveries');
        Schema::dropIfExists('telegram_webhook_updates');
        Schema::dropIfExists('telegram_owner_links');
        Schema::dropIfExists('telegram_pair_challenges');
        Schema::table('approval_alert_outbox', function (Blueprint $table): void {
            $table->dropUnique(['company_id', 'id']);
        });
    }
};
