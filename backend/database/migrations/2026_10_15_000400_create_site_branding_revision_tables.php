<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            $table->unsignedInteger('branding_draft_revision')->default(0);
            $table->uuid('branding_published_revision_id')->nullable();
        });

        Schema::create('site_branding_revisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('website_id');
            $table->unsignedInteger('revision_number');
            $table->jsonb('content');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->foreign(['company_id', 'website_id'])
                ->references(['company_id', 'id'])->on('websites')->restrictOnDelete();
            $table->unique(['website_id', 'revision_number']);
            $table->unique(['website_id', 'id']);
        });

        // Published branding can ONLY reference a revision from its own website.
        Schema::table('websites', function (Blueprint $table): void {
            $table->foreign(['id', 'branding_published_revision_id'])
                ->references(['website_id', 'id'])->on('site_branding_revisions')->restrictOnDelete();
        });

        Schema::create('site_branding_publishing_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('website_id');
            $table->uuid('revision_id');
            $table->uuid('previous_revision_id')->nullable();
            $table->string('action', 20);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->foreign(['company_id', 'website_id'])
                ->references(['company_id', 'id'])->on('websites')->restrictOnDelete();
            $table->foreign(['website_id', 'revision_id'])
                ->references(['website_id', 'id'])->on('site_branding_revisions')->restrictOnDelete();
            $table->foreign(['website_id', 'previous_revision_id'])
                ->references(['website_id', 'id'])->on('site_branding_revisions')->restrictOnDelete();
        });

        DB::statement('CREATE TRIGGER immutable_branding_revisions BEFORE UPDATE OR DELETE ON site_branding_revisions FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()');
        DB::statement('CREATE TRIGGER immutable_branding_publishing BEFORE UPDATE OR DELETE ON site_branding_publishing_events FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()');
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            $table->dropForeign(['id', 'branding_published_revision_id']);
        });
        Schema::dropIfExists('site_branding_publishing_events');
        Schema::dropIfExists('site_branding_revisions');
        Schema::table('websites', function (Blueprint $table): void {
            $table->dropColumn(['branding_draft_revision', 'branding_published_revision_id']);
        });
    }
};
