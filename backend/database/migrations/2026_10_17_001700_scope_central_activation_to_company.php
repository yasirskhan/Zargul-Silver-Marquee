<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Nullable only for upgrade compatibility. A missing binding must
        // NEVER allow a tenant owner to see global installation licensing.
        Schema::table('central_installation_activation', function (Blueprint $table): void {
            $table->uuid('company_id')->nullable()->unique();
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('central_installation_activation', function (Blueprint $table): void {
            $table->dropForeign(['company_id']);
            $table->dropUnique(['company_id']);
            $table->dropColumn('company_id');
        });
    }
};
