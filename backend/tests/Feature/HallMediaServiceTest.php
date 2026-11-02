<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\User;
use App\Services\HallMediaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class HallMediaServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_assigns_only_approved_company_media_and_cross_tenant_is_rejected(): void
    {
        $company = Company::create(['name' => 'Photos Venue', 'slug' => 'photos-venue']);
        $other = Company::create(['name' => 'Other Venue', 'slug' => 'other-venue']);
        $owner = User::create(['name' => 'Owner', 'email' => 'photos-owner@example.test',
            'password' => 'test-only']);
        $owner->markEmailAsVerified();
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $owner->id,
            'role' => 'owner', 'is_active' => true]);
        $hall = Hall::create(['company_id' => $company->id, 'name' => 'Photo Hall',
            'slug' => 'photo-hall', 'capacity' => 100, 'is_active' => true]);
        $foreignHall = Hall::create(['company_id' => $other->id, 'name' => 'Foreign Hall',
            'slug' => 'foreign-hall', 'capacity' => 100, 'is_active' => true]);
        $first = $this->media($company->id, $owner->id, 'First approved hall image');
        $second = $this->media($company->id, $owner->id, 'Second approved hall image');
        $foreign = $this->media($other->id, $owner->id, 'Foreign image');

        $service = app(HallMediaService::class);
        $service->replace($owner, $company->id, $hall->id, [$first, $second], 'Owner selects hall photos');
        $this->assertSame([$first, $second], $service->selectedMedia($company->id, $hall->id));
        $this->assertSame(1, DB::table('audit_events')
            ->where('action', 'website.hall_photos_updated')->count());

        $this->expectException(\InvalidArgumentException::class);
        $service->replace($owner, $company->id, $hall->id, [$foreign], 'Attempt foreign image');
        $this->assertNotSame($foreign, $service->selectedMedia($other->id, $foreignHall->id));
    }

    private function media(string $companyId, int $userId, string $alt): string
    {
        $id = (string) Str::uuid();
        DB::table('site_media')->insert([
            'id' => $id, 'company_id' => $companyId,
            'object_key' => 'site-media/'.$companyId.'/'.$id.'.webp',
            'mime_type' => 'image/webp', 'sha256' => str_repeat('a', 64),
            'alt_text' => $alt, 'metadata' => '{}', 'status' => 'approved',
            'uploaded_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }
}