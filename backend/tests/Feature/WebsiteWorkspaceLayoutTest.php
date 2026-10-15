<?php

namespace Tests\Feature;

use App\Filament\Pages\WebsiteWorkspace;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class WebsiteWorkspaceLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_three_column_draft_keeps_desktop_widths_and_independent_phone_order(): void
    {
        $company = Company::create(['name' => 'Silver', 'slug' => 'silver']);
        $owner = User::create([
            'name' => 'Owner', 'email' => 'layout-ui@example.test', 'password' => 'test-only',
        ]);
        $owner->markEmailAsVerified();
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $owner->id, 'role' => 'owner']);
        $website = (string) Str::uuid();
        $page = (string) Str::uuid();
        DB::table('websites')->insert(['id' => $website, 'company_id' => $company->id, 'name' => 'Silver']);
        DB::table('site_pages')->insert([
            'id' => $page, 'company_id' => $company->id, 'website_id' => $website,
            'slug' => '/', 'title' => 'Home',
        ]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->be($owner);

        Livewire::test(WebsiteWorkspace::class)
            ->call('addColumns', 3)
            ->call('setDesktopWidths', 0, 'wideCenter')
            ->call('moveMobileColumn', 0, 0, 'down')
            ->set('sections.0.columns.1.blocks.0.data.title', 'Center hall')
            ->call('setPreviewMode', 'phone')
            ->assertSet('previewMode', 'phone')
            ->set('reason', 'Create responsive hall columns')
            ->call('saveDraft')
            ->assertHasNoErrors();

        $content = json_decode(DB::table('site_page_revisions')->where('page_id', $page)->value('content'), true, 512, JSON_THROW_ON_ERROR);
        $cols = $content['sections'][0]['columns'];
        $this->assertSame([3, 6, 3], array_column($cols, 'widthDesktop'));
        $this->assertSame([2, 1, 3], array_column($cols, 'mobileOrder'));
        $this->assertSame('Center hall', $cols[1]['blocks'][0]['data']['title']);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'website.draft_saved')->count());
    }

    public function test_two_column_section_can_move_as_a_whole_before_text(): void
    {
        $company = Company::create(['name' => 'Second', 'slug' => 'second']);
        $owner = User::create(['name' => 'Owner', 'email' => 'layout2@example.test', 'password' => 'test-only']);
        $owner->markEmailAsVerified();
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $owner->id, 'role' => 'owner']);
        $website = (string) Str::uuid();
        $page = (string) Str::uuid();
        DB::table('websites')->insert(['id' => $website, 'company_id' => $company->id, 'name' => 'Second']);
        DB::table('site_pages')->insert([
            'id' => $page, 'company_id' => $company->id, 'website_id' => $website,
            'slug' => '/', 'title' => 'Home',
        ]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->be($owner);

        Livewire::test(WebsiteWorkspace::class)
            ->call('addSection', 'text')
            ->call('addColumns', 2)
            ->call('moveSection', 1, 'up')
            ->call('setDesktopWidths', 0, 'wideLeft')
            ->set('reason', 'Reorder home sections')
            ->call('saveDraft')->assertHasNoErrors();

        $content = json_decode(DB::table('site_page_revisions')->where('page_id', $page)->value('content'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['columns', 'text'], array_column($content['sections'], 'type'));
        $this->assertSame([8, 4], array_column($content['sections'][0]['columns'], 'widthDesktop'));
    }
}
