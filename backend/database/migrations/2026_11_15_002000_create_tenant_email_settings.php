<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void {
        Schema::create('tenant_email_settings',function(Blueprint $table):void{
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->unique()->constrained('companies')->restrictOnDelete();
            $table->jsonb('config');
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });
    }
    public function down():void { Schema::dropIfExists('tenant_email_settings'); }
};
