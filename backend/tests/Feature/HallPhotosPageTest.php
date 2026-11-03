<?php

namespace Tests\Feature;

use App\Filament\Pages\HallPhotos;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class HallPhotosPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_saves_approved_hall_photographs_from_the_page(): void
    {
        [$company, $owner, $hall] = $this->fixture();
        $media = $this->media($company->id, $owner->id);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->be($owner);

        Livewire::test(HallPhotos::class)
            ->set('companyId', $company->id)
            ->set('hallId', $hall->id)
            ->set('selectedMediaIds', [$media])
            ->set('reason', 'Owner assigns hall photograph')
            ->call('save')->assertHasNoErrors()
            ->assertSee('Hall photography saved');

        $this->assertSame([$media], app(\App\Services\HallMediaService::class)
            ->selectedMedia($owner, $company->id, $hall->id));
    }

    private function fixture(): array
    {
        $company = Company::create(['name' => 'Photos Page', 'slug' => 'photos-page']);
        $owner = User::create(['name' => 'Owner', 'email' => 'photos-page@example.test',
            'password' => 'test-only']);
        $owner->markEmailAsVerified();
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $owner->id,
            'role' => 'owner', 'is_active' => true]);
        $hall = Hall::create(['company_id' => $company->id, 'name' => 'Photos Hall',
            'slug' => 'photos-page-hall', 'capacity' => 90, 'is_active' => true]);

        return [$company, $owner, $hall];
    }

    private function media(string $companyId, int $userId): string
    {
        $id = (string) Str::uuid();
        DB::table('site_media')->insert([
            'id' => $id, 'company_id' => $companyId,
            'object_key' => 'site-media/'.$companyId.'/'.$id.'.webp',
            'mime_type' => 'image/webp', 'sha256' => str_repeat('b', 64),
            'alt_text' => 'Approved page image', 'metadata' => '{}', 'status' => 'approved',
            'uploaded_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }
}