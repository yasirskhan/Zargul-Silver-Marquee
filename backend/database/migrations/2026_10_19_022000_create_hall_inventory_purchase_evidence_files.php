<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up():void {
        Schema::create('hall_inventory_purchase_evidence_files',function(Blueprint $t):void {
            $t->uuid('id')->primary();
            $t->uuid('company_id'); $t->uuid('hall_id');
            $t->uuid('purchase_request_id');
            $t->string('evidence_kind',32);
            $t->char('sha256',64);
            $t->unsignedBigInteger('size_bytes');
            $t->string('storage_path',255)->unique('purchase_evidence_storage_path_unique');
            $t->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->foreign(['company_id','hall_id','purchase_request_id'],'purchase_file_claim_scope_fk')
                ->references(['company_id','hall_id','id'])->on('hall_inventory_purchase_requests')->restrictOnDelete();
            $t->unique(['purchase_request_id','evidence_kind'],'purchase_file_kind_unique');
        });
        DB::statement("ALTER TABLE hall_inventory_purchase_evidence_files ADD CONSTRAINT purchase_evidence_kind_check CHECK (evidence_kind IN ('supplier_document','receiving_photo'))");
        DB::statement('ALTER TABLE hall_inventory_purchase_evidence_files ADD CONSTRAINT purchase_evidence_size_check CHECK (size_bytes > 0 AND size_bytes <= 5242880)');
        DB::statement('CREATE TRIGGER immutable_hall_purchase_evidence_files BEFORE UPDATE OR DELETE ON hall_inventory_purchase_evidence_files FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()');
    }
    public function down():void {
        if(DB::table('hall_inventory_purchase_evidence_files')->exists())
            throw new RuntimeException('Purchase evidence files require archival before rollback.');
        Schema::dropIfExists('hall_inventory_purchase_evidence_files');
    }
};
