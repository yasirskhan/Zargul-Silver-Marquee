<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('hall_inventory_loan_incident_reports', function (Blueprint $t):void {
            $t->uuid('id')->primary();
            $t->uuid('company_id');
            $t->uuid('loan_id');
            $t->uuid('source_hall_id');
            $t->uuid('source_item_id');
            $t->string('kind',16);
            $t->unsignedInteger('quantity');
            $t->string('reason',500);
            $t->char('request_fingerprint',64);
            $t->foreignId('reported_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->foreign(['company_id','loan_id'])
                ->references(['company_id','id'])->on('hall_inventory_loan_drafts')->restrictOnDelete();
            $t->foreign(['company_id','source_hall_id','source_item_id'])
                ->references(['company_id','hall_id','id'])->on('hall_inventory_items')->restrictOnDelete();
            $t->unique(['company_id','id'],'loan_incident_request_scope');
            $t->index(['company_id','loan_id','created_at'],'loan_incident_history');
        });
        DB::statement("ALTER TABLE hall_inventory_loan_incident_reports ADD CONSTRAINT loan_incident_valid CHECK (kind IN ('damage','missing','dispute') AND quantity > 0 AND quantity <= 100000000)");
        DB::statement('CREATE TRIGGER immutable_hall_inventory_loan_incident_reports BEFORE UPDATE OR DELETE ON hall_inventory_loan_incident_reports FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()');
    }
    public function down(): void {
        if (DB::table('hall_inventory_loan_incident_reports')->exists()) {
            throw new RuntimeException('Immutable inventory incident reports require archival before rollback.');
        }
        Schema::dropIfExists('hall_inventory_loan_incident_reports');
    }
};
