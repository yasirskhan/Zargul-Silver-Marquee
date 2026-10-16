<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('site_pages', function (Blueprint $table): void {
            // Current successful unpublish idempotency key. Historical events
            // remain immutable; NULL means an old page or currently online.
            $table->uuid('offline_request_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('site_pages', function (Blueprint $table): void {
            $table->dropColumn('offline_request_id');
        });
    }
};
