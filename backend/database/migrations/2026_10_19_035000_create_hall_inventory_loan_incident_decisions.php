<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up():void {
        Schema::create('hall_inventory_loan_incident_decisions', function(Blueprint $t):void {
            $t->uuid('id')->primary();
            $t->uuid('company_id');
            $t->uuid('report_id');
            $t->string('decision',32);
            $t->string('reason',500);
            $t->char('decision_fingerprint',64);
            $t->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->foreign(['company_id','report_id'])
                ->references(['company_id','id'])->on('hall_inventory_loan_incident_reports')->restrictOnDelete();
            $t->unique(['company_id','report_id'],'loan_incident_review_once');
            $t->unique(['company_id','id'],'loan_incident_decision_scope');
        });
        DB::statement("ALTER TABLE hall_inventory_loan_incident_decisions ADD CONSTRAINT loan_incident_decision_kind CHECK (decision IN ('substantiated','unsubstantiated','requires_more_evidence'))");
        DB::statement('CREATE TRIGGER immutable_hall_inventory_loan_incident_decisions BEFORE UPDATE OR DELETE ON hall_inventory_loan_incident_decisions FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()');
    }
    public function down():void {
        if(DB::table('hall_inventory_loan_incident_decisions')->exists())
            throw new RuntimeException('Immutable incident decisions must be archived before rollback.');
        Schema::dropIfExists('hall_inventory_loan_incident_decisions');
    }
};
