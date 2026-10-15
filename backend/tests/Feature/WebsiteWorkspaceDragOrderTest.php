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

class WebsiteWorkspaceDragOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_drag_order_repositions_sections_and_desktop_columns_without_changing_mobile_order(): void
    {
        $company = Company::create(['name' => 'Silver', 'slug' => 'silver']);
        $owner = User::create(['name' => 'Owner', 'email' => 'drag@example.test', 'password' => 'test-only']);
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

        $component = Livewire::test(WebsiteWorkspace::class)
            ->call('addSection', 'hero')
            ->call('addColumns', 3)
            ->call('setDesktopWidths', 1, 'wideCenter')
            ->call('moveMobileColumn', 1, 0, 'down')
            ->call('moveDesktopColumn', 1, 0, 2)
            ->call('moveSectionTo', 1, 0)
            ->set('reason', 'Place hall layout first')
            ->call('saveDraft')
            ->assertHasNoErrors();

        $content = json_decode(DB::table('site_page_revisions')->where('page_id', $page)->value('content'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['columns', 'hero'], array_column($content['sections'], 'type'));
        $columns = $content['sections'][0]['columns'];
        $this->assertSame([6, 3, 3], array_column($columns, 'widthDesktop'));
        $this->assertSame([1, 3, 2], array_column($columns, 'mobileOrder'));
        $this->assertSame(['Column 2', 'Column 3', 'Column 1'], array_map(
            fn (array $column): string => $column['blocks'][0]['data']['title'], $columns
        ));
        $this->assertSame(1, DB::table('audit_events')->where('action', 'website.draft_saved')->count());
    }
}
