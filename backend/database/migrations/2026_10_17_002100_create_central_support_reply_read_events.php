<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Append-only support-message read receipts for later vendor/client
        // dispute review. Never store decrypted support bodies in the audit.
        Schema::create('central_support_reply_read_events',function(Blueprint $table):void{
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('message_id');
            $table->uuid('actor_user_id');
            $table->timestampTz('read_at');
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('message_id')->references('id')->on('central_support_reply_inbox')->restrictOnDelete();
            $table->foreign('actor_user_id')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['company_id','message_id'],'support_read_event_once');
            $table->index(['company_id','read_at'],'support_read_audit_scope');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('central_support_reply_read_events');
    }
};
