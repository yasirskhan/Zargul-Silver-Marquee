<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // This contains only a private local CLIENT identity, never vendor staff,
        // billing, central signer keys, or a replica of the central database.
        Schema::create('central_installation_identity', function (Blueprint $table): void {
            $table->unsignedSmallInteger('singleton_id')->primary();
            $table->uuid('installation_id')->unique();
            $table->string('ed25519_public_key', 43)->unique();
            $table->text('encrypted_private_key');
            $table->timestampsTz();
        });
        DB::statement(
            'ALTER TABLE central_installation_identity ADD CONSTRAINT central_identity_one_installation CHECK (singleton_id = 1)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('central_installation_identity');
    }
};
