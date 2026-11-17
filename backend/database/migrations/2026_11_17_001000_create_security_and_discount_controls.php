<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void{
  Schema::table('users',function(Blueprint $t):void{
   $t->boolean('mfa_required')->nullable()->after('email_verified_at');
  });
  Schema::create('tenant_security_settings',function(Blueprint $t):void{
   $t->uuid('id')->primary();$t->foreignUuid('company_id')->unique()->constrained('companies')->restrictOnDelete();
   $t->boolean('mfa_required')->default(true);$t->unsignedSmallInteger('trusted_device_days')->default(30);
   $t->unsignedInteger('revision')->default(1);$t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();$t->timestampsTz();
  });
  Schema::create('trusted_devices',function(Blueprint $t):void{
   $t->uuid('id')->primary();$t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
   $t->string('token_hash',64)->unique();$t->string('user_agent_hash',64);$t->timestampTz('expires_at');
   $t->timestampTz('last_used_at')->nullable();$t->timestampTz('revoked_at')->nullable();$t->timestampsTz();
   $t->index(['user_id','expires_at']);
  });
  Schema::create('tenant_discount_limits',function(Blueprint $t):void{
   $t->uuid('id')->primary();$t->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
   $t->string('role',24);$t->unsignedSmallInteger('max_percent_bps')->default(0);
   $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();$t->timestampsTz();
   $t->unique(['company_id','role']);
  });
 }
 public function down():void{
  Schema::dropIfExists('tenant_discount_limits');Schema::dropIfExists('trusted_devices');
  Schema::dropIfExists('tenant_security_settings');
  Schema::table('users',fn(Blueprint $t)=>$t->dropColumn('mfa_required'));
 }
};
