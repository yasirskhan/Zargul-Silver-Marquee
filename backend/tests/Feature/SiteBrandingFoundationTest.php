<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Services\SiteBrandingValidator;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class SiteBrandingFoundationTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $slug): array
    {
        $company = Company::create(['slug' => $slug, 'name' => $slug]);
        $user = User::create([
            'name' => $slug, 'email' => $slug.'@example.test', 'password' => 'test-only',
        ]);
        $websiteId = (string) Str::uuid();
        DB::table('websites')->insert([
            'id' => $websiteId, 'company_id' => $company->id, 'name' => $slug,
        ]);

        return [$company, $user, $websiteId];
    }

    private function branding(): array
    {
        return [
            'siteName' => 'Zargul Silver Marquee',
            'tagline' => 'A beautiful place to celebrate',
            'themePreset' => 'silver',
            'fontPreset' => 'classic',
            'logoMediaId' => null,
            'faviconMediaId' => null,
        ];
    }

    private function revision(string $companyId, int $actor, string $siteId, int $number): string
    {
        $id = (string) Str::uuid();
        DB::table('site_branding_revisions')->insert([
            'id' => $id, 'company_id' => $companyId, 'website_id' => $siteId,
            'revision_number' => $number, 'created_by' => $actor,
            'content' => json_encode($this->branding(), JSON_THROW_ON_ERROR),
        ]);
        return $id;
    }

    public function test_approved_typed_branding_and_rejected_unsafe_options(): void
    {
        $validator = app(SiteBrandingValidator::class);
        $validator->validate($this->branding());
        $this->assertTrue(true);

        foreach ([
            ['siteName' => '<script>attack</script>'],
            ['tagline' => 'javascript:alert(1)'],
            ['themePreset' => 'url(https://evil.test)'],
            ['fontPreset' => 'external-css'],
            ['logoMediaId' => 'cross-tenant-alias'],
            ['faviconMediaId' => 123],
            ['customCss' => 'body { color: red }'],
        ] as $change) {
            try {
                $validator->validate(array_replace($this->branding(), $change));
                $this->fail('Unapproved branding property must fail validation.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_branding_draft_changes_cannot_alter_published_pointer(): void
    {
        [$company, $user, $site] = $this->fixture('site-one');
        $published = $this->revision($company->id, $user->id, $site, 1);
        DB::table('websites')->where('id', $site)->update([
            'branding_draft_revision' => 1, 'branding_published_revision_id' => $published,
        ]);
        $this->revision($company->id, $user->id, $site, 2);
        DB::table('websites')->where('id', $site)->update(['branding_draft_revision' => 2]);

        $website = DB::table('websites')->where('id', $site)->first();
        $this->assertSame($published, $website->branding_published_revision_id);
        $this->assertSame(2, $website->branding_draft_revision);
    }

    public function test_cross_tenant_branding_history_is_rejected_by_postgresql(): void
    {
        [$company, $user, $site] = $this->fixture('site-a');
        [$foreign] = $this->fixture('site-b');
        $this->expectException(QueryException::class);
        $this->revision($foreign->id, $user->id, $site, 1);
    }

    public function test_cannot_publish_branding_from_another_website(): void
    {
        [$company, $user, $siteA] = $this->fixture('site-c');
        [, , $siteB] = $this->fixture('site-d');
        $revision = $this->revision($company->id, $user->id, $siteA, 1);
        $this->expectException(QueryException::class);
        DB::table('websites')->where('id', $siteB)
            ->update(['branding_published_revision_id' => $revision]);
    }

    public function test_branding_revisions_are_immutable(): void
    {
        [$company, $user, $site] = $this->fixture('site-e');
        $revision = $this->revision($company->id, $user->id, $site, 1);
        $this->expectException(QueryException::class);
        DB::table('site_branding_revisions')->where('id', $revision)
            ->update(['content' => '{}']);
    }

    public function test_branding_publication_history_cannot_be_removed(): void
    {
        [$company, $user, $site] = $this->fixture('site-f');
        $revision = $this->revision($company->id, $user->id, $site, 1);
        $eventId = (string) Str::uuid();
        DB::table('site_branding_publishing_events')->insert([
            'id' => $eventId, 'company_id' => $company->id,
            'website_id' => $site, 'revision_id' => $revision,
            'action' => 'publish', 'actor_id' => $user->id,
        ]);
        $this->expectException(QueryException::class);
        DB::table('site_branding_publishing_events')->where('id', $eventId)->delete();
    }
}
