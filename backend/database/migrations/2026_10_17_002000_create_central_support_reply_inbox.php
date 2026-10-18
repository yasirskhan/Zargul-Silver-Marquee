<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('central_support_reply_cursor',function(Blueprint $table):void{
            $table->unsignedSmallInteger('singleton_id')->primary();
            $table->uuid('company_id');
            $table->uuid('installation_id');
            $table->unsignedBigInteger('last_event_id')->default(0);
            $table->timestampsTz();
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('installation_id')->references('installation_id')
                ->on('central_installation_identity')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE central_support_reply_cursor ADD CONSTRAINT support_reply_cursor_singleton CHECK (singleton_id=1)');
        Schema::create('central_support_reply_inbox',function(Blueprint $table):void{
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('installation_id');
            $table->unsignedBigInteger('vendor_event_id');
            $table->uuid('client_ticket_id');
            $table->uuid('vendor_message_id');
            $table->string('vendor_ticket_number',32);
            $table->text('encrypted_body');
            $table->timestampTz('vendor_occurred_at');
            $table->timestampTz('read_at')->nullable();
            $table->timestampsTz();
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('installation_id')->references('installation_id')
                ->on('central_installation_identity')->restrictOnDelete();
            $table->unique(['company_id','installation_id','vendor_event_id'],'support_reply_unique_vendor_event');
            $table->unique(['company_id','installation_id','vendor_message_id'],'support_reply_unique_vendor_message');
            $table->index(['company_id','read_at','vendor_event_id'],'support_reply_unread_index');
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('central_support_reply_inbox');
        Schema::dropIfExists('central_support_reply_cursor');
    }
};
