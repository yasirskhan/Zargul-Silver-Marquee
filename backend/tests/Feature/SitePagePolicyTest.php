<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\SitePage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Tests\TestCase;

class SitePagePolicyTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $slug, string $role, array $rights = []): array
    {
        $company = Company::create(['name' => $slug, 'slug' => $slug]);
        $user = User::create([
            'name' => $slug, 'email' => $slug.'@example.test', 'password' => 'test-only',
        ]);
        $user->markEmailAsVerified();
        CompanyMembership::create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'role' => $role,
            ...$rights,
        ]);
        $websiteId = (string) Str::uuid();
        $pageId = (string) Str::uuid();
        DB::table('websites')->insert([
            'id' => $websiteId, 'company_id' => $company->id, 'name' => $slug,
        ]);
        DB::table('site_pages')->insert([
            'id' => $pageId, 'website_id' => $websiteId,
            'company_id' => $company->id, 'slug' => '/', 'title' => 'Home',
        ]);

        return [$company, $user, SitePage::findOrFail($pageId)];
    }

    public function test_owner_can_view_edit_publish_but_cannot_silently_delete_history(): void
    {
        [, $owner, $page] = $this->fixture('owner-company', 'owner');
        $this->assertTrue(Gate::forUser($owner)->allows('view', $page));
        $this->assertTrue(Gate::forUser($owner)->allows('update', $page));
        $this->assertTrue(Gate::forUser($owner)->allows('publish', $page));
        $this->assertFalse(Gate::forUser($owner)->allows('delete', $page));
    }

    public function test_manager_delegation_separates_draft_and_publish_rights(): void
    {
        [, $editor, $page] = $this->fixture('editor-company', 'manager', ['can_edit_site' => true]);
        $this->assertTrue(Gate::forUser($editor)->allows('update', $page));
        $this->assertFalse(Gate::forUser($editor)->allows('publish', $page));
        $editor->memberships()->first()->update(['can_publish_site' => true]);
        $this->assertTrue(Gate::forUser($editor)->allows('publish', $page));
    }

    public function test_cross_company_and_inactive_website_are_not_authorized(): void
    {
        [, $owner] = $this->fixture('main-company', 'owner');
        [$otherCompany, , $otherPage] = $this->fixture('other-company', 'manager');
        $this->assertFalse(Gate::forUser($owner)->allows('view', $otherPage));
        $this->assertFalse(Gate::forUser($owner)->allows('publish', $otherPage));
        $otherCompany->update(['is_active' => false]);
        $this->assertFalse(Gate::forUser($owner)->allows('update', $otherPage));

        [$company, $rightfulOwner, $page] = $this->fixture('third-company', 'owner');
        $this->assertTrue(Gate::forUser($rightfulOwner)->allows('publish', $page));
        DB::table('websites')->where('id', $page->website_id)->update(['is_active' => false]);
        $this->assertFalse(Gate::forUser($rightfulOwner)->allows('publish', $page));
    }

    public function test_deactivated_member_and_forged_model_are_not_authorized(): void
    {
        [, $owner, $page] = $this->fixture('one-company', 'owner');
        $forged = new SitePage();
        $forged->id = (string) Str::uuid();
        $forged->company_id = $page->company_id;
        $this->assertFalse(Gate::forUser($owner)->allows('update', $forged));
        $owner->memberships()->first()->update(['is_active' => false]);
        $this->assertFalse(Gate::forUser($owner)->allows('publish', $page));
    }

    public function test_unverified_email_cannot_view_or_publish_draft(): void
    {
        [, $owner, $page] = $this->fixture('needs-email', 'owner');
        $owner->forceFill(['email_verified_at' => null])->save();
        $this->assertFalse(Gate::forUser($owner)->allows('view', $page));
        $this->assertFalse(Gate::forUser($owner)->allows('update', $page));
        $this->assertFalse(Gate::forUser($owner)->allows('publish', $page));
    }
}
