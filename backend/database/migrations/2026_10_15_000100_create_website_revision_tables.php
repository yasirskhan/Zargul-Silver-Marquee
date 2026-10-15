<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('websites', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->jsonb('theme_tokens')->default('{}');
            $table->timestampsTz();
            $table->unique(['company_id', 'id']);
        });

        Schema::create('site_media', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('object_key')->unique();
            $table->string('mime_type', 100);
            $table->string('sha256', 64);
            $table->string('alt_text')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->string('status', 20)->default('pending');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['company_id', 'id']);
        });

        Schema::create('site_pages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('website_id');
            $table->string('slug', 190);
            $table->string('title');
            $table->unsignedInteger('draft_revision')->default(0);
            $table->uuid('published_revision_id')->nullable();
            $table->timestampsTz();
            $table->foreign(['company_id', 'website_id'])
                ->references(['company_id', 'id'])->on('websites')->restrictOnDelete();
            $table->unique(['website_id', 'slug']);
            $table->unique(['company_id', 'id']);
        });

        Schema::create('site_page_revisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('page_id');
            $table->unsignedInteger('revision_number');
            $table->jsonb('content');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->foreign(['company_id', 'page_id'])
                ->references(['company_id', 'id'])->on('site_pages')->restrictOnDelete();
            $table->unique(['page_id', 'revision_number']);
            $table->unique(['page_id', 'id']);
        });

        Schema::table('site_pages', function (Blueprint $table): void {
            // Prevent a page from publishing a revision belonging to another page.
            $table->foreign(['id', 'published_revision_id'])
                ->references(['page_id', 'id'])->on('site_page_revisions')->restrictOnDelete();
        });

        Schema::create('site_publishing_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('page_id');
            $table->uuid('revision_id');
            $table->uuid('previous_revision_id')->nullable();
            $table->string('action', 20);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->foreign(['company_id', 'page_id'])
                ->references(['company_id', 'id'])->on('site_pages')->restrictOnDelete();
            $table->foreign(['page_id', 'revision_id'])
                ->references(['page_id', 'id'])->on('site_page_revisions')->restrictOnDelete();
        });

        Schema::create('audit_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->string('entity_type', 64);
            $table->string('entity_id', 80);
            $table->jsonb('before_values')->nullable();
            $table->jsonb('after_values')->nullable();
            $table->jsonb('context')->default('{}');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['company_id', 'created_at']);
        });

        // PostgreSQL enforces immutable versions and audit events for ordinary roles.
        // Production will additionally use separate database roles and secured log export.
        DB::unprepared(<<<'SQL'
CREATE FUNCTION venuedesk_immutable_history() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'VenueDesk history entries cannot be updated or deleted';
END;
$$;
SQL);
        DB::statement('CREATE TRIGGER immutable_site_page_revisions BEFORE UPDATE OR DELETE ON site_page_revisions FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()');
        DB::statement('CREATE TRIGGER immutable_site_publishing_events BEFORE UPDATE OR DELETE ON site_publishing_events FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()');
        DB::statement('CREATE TRIGGER immutable_audit_events BEFORE UPDATE OR DELETE ON audit_events FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()');
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
        Schema::dropIfExists('site_publishing_events');
        Schema::dropIfExists('site_page_revisions');
        Schema::dropIfExists('site_pages');
        Schema::dropIfExists('site_media');
        Schema::dropIfExists('websites');
        DB::statement('DROP FUNCTION IF EXISTS venuedesk_immutable_history()');
    }
};
