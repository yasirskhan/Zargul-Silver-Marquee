<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void {
        Schema::create('hall_inventory_variance_review_acknowledgments',function(Blueprint $t):void {
            $t->uuid('id')->primary();
            $t->uuid('company_id');$t->uuid('hall_id');$t->uuid('stocktake_id');
            $t->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
            $t->string('review_reason',500);$t->char('request_fingerprint',64);
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['stocktake_id'],'variance_review_one_per_count');
            $t->foreign(['company_id','hall_id','stocktake_id'],'variance_review_stocktake_fk')
                ->references(['company_id','hall_id','id'])->on('hall_inventory_stocktakes')->restrictOnDelete();
        });
        DB::statement('CREATE TRIGGER immutable_hall_variance_review BEFORE UPDATE OR DELETE ON hall_inventory_variance_review_acknowledgments FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()');
    }
    public function down():void {
        if(DB::table('hall_inventory_variance_review_acknowledgments')->exists())
            throw new RuntimeException('Variance review audit must be archived before rollback.');
        Schema::dropIfExists('hall_inventory_variance_review_acknowledgments');
    }
};
