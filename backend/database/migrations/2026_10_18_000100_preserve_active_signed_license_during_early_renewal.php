<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Retain a cryptographically verifiable active grant while its newer,
        // already-paid renewal is signed but not yet in effect. Never replace
        // the active entitlement with a future-start revision until its date.
        Schema::table('central_signed_license_cache',function(Blueprint $table):void {
            $table->unsignedBigInteger('previous_revision')->nullable();
            $table->char('previous_envelope_sha256',64)->nullable();
            $table->text('previous_encrypted_signed_envelope')->nullable();
        });
        DB::statement("ALTER TABLE central_signed_license_cache ADD CONSTRAINT
            central_cache_previous_complete CHECK (
                (previous_revision IS NULL AND previous_envelope_sha256 IS NULL
                    AND previous_encrypted_signed_envelope IS NULL)
                OR (previous_revision IS NOT NULL AND previous_envelope_sha256 IS NOT NULL
                    AND previous_encrypted_signed_envelope IS NOT NULL
                    AND previous_revision > 0 AND previous_revision < revision)
            )");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE central_signed_license_cache
            DROP CONSTRAINT IF EXISTS central_cache_previous_complete');
        Schema::table('central_signed_license_cache',function(Blueprint $table):void {
            $table->dropColumn(['previous_revision','previous_envelope_sha256',
                'previous_encrypted_signed_envelope']);
        });
    }
};
