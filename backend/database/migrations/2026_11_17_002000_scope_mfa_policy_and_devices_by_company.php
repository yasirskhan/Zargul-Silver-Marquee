<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void{
  Schema::create('company_user_mfa_policies',function(Blueprint $t):void{
   $t->uuid('id')->primary();$t->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
   $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();$t->boolean('mfa_required');
   $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();$t->timestampsTz();
   $t->unique(['company_id','user_id']);
  });
  Schema::table('trusted_devices',function(Blueprint $t):void{
   $t->foreignUuid('company_id')->nullable()->after('user_id')->constrained('companies')->restrictOnDelete();
  });
 }
 public function down():void{
  Schema::table('trusted_devices',fn(Blueprint $t)=>$t->dropConstrainedForeignId('company_id'));
  Schema::dropIfExists('company_user_mfa_policies');
 }
};
