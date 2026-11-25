<?php
namespace Tests\Feature;

use App\Filament\Pages\StaffManagement;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\User;
use App\Services\StaffDirectoryService;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class StaffManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_updates_role_and_hall_assignments_with_audit():void
    {
        $company=Company::create(['name'=>'Staff Venue','slug'=>'staff-venue']);
        $hall=Hall::create(['company_id'=>$company->id,'name'=>'Staff Hall','slug'=>'staff-hall','capacity'=>80]);
        $owner=User::create(['name'=>'Staff Owner','email'=>'staff-owner@example.test','password'=>'test-only']);
        $manager=User::create(['name'=>'Staff Manager','email'=>'staff-manager@example.test','password'=>'test-only']);
        foreach([[$owner,'owner'],[$manager,'manager']] as [$user,$role]){
            $user->markEmailAsVerified();
            CompanyMembership::create(['company_id'=>$company->id,'user_id'=>$user->id,'role'=>$role]);
        }
        $membership=CompanyMembership::where('user_id',$manager->id)->first();
        $this->be($owner);
        $service=app(StaffDirectoryService::class);
        $this->assertCount(2,$service->staff($owner,$company->id));
        $this->assertCount(1,$service->staff($owner,$company->id,'manager@example'));
        $service->update($owner,$company->id,(string)$membership->id,'admin',[$hall->id],'Promote after owner review');
        $this->assertSame('admin',DB::table('company_memberships')->where('id',$membership->id)->value('role'));
        $this->assertSame($hall->id,DB::table('hall_assignments')->where('membership_id',$membership->id)->value('hall_id'));
        $this->assertSame(1,DB::table('audit_events')->where('action','staff.membership_updated')
            ->where('entity_id',(string)$membership->id)->count());
        $service->revoke($owner,$company->id,(string)$membership->id,'Access no longer required');
        $this->assertFalse((bool)DB::table('company_memberships')->where('id',$membership->id)->value('is_active'));
        $this->assertSame(0,DB::table('hall_assignments')->where('membership_id',$membership->id)->count());
        $this->assertSame(1,DB::table('audit_events')->where('action','staff.membership_revoked')->count());
        $this->be($manager);
        $this->expectException(AuthorizationException::class);
        $service->update($manager,$company->id,(string)$membership->id,'manager',[],'Self escalation attempt');
    }


    public function test_cross_company_revoked_owner_self_owner_and_stale_guards(): void
    {
        $company=Company::create(['name'=>'Guard Venue','slug'=>'guard-venue']);
        $other=Company::create(['name'=>'Other Guard Venue','slug'=>'other-guard-venue']);
        $owner=User::create(['name'=>'Guard Owner','email'=>'guard-owner@example.test','password'=>'test-only']);
        $manager=User::create(['name'=>'Guard Manager','email'=>'guard-manager@example.test','password'=>'test-only']);
        foreach([[$owner,'owner'],[$manager,'manager']] as [$user,$role]){
            $user->markEmailAsVerified();
            CompanyMembership::create(['company_id'=>$company->id,'user_id'=>$user->id,'role'=>$role]);
        }
        $otherOwner=User::create(['name'=>'Other Owner','email'=>'other-guard-owner@example.test','password'=>'test-only']);
        $otherOwner->markEmailAsVerified();
        $otherMembership=CompanyMembership::create(['company_id'=>$other->id,'user_id'=>$otherOwner->id,'role'=>'owner']);
        $managerMembership=CompanyMembership::where('user_id',$manager->id)->first();
        $ownerMembership=CompanyMembership::where('user_id',$owner->id)->first();
        $service=app(StaffDirectoryService::class);
        $this->be($owner);
        try {
            $service->update($owner,$company->id,(string)$otherMembership->id,'admin',[],'Cross company attempt');
            $this->fail('Cross company update was allowed.');
        } catch (\InvalidArgumentException) {
            $this->assertSame('manager',DB::table('company_memberships')->where('id',$managerMembership->id)->value('role'));
        }
        $service->update($owner,$company->id,(string)$managerMembership->id,'admin',[],'Promote once','manager');
        try {
            $service->update($owner,$company->id,(string)$managerMembership->id,'reception',[],'Stale role attempt','manager');
            $this->fail('Stale role update was allowed.');
        } catch (\InvalidArgumentException) {
            $this->assertSame('admin',DB::table('company_memberships')->where('id',$managerMembership->id)->value('role'));
        }
        try {
            $service->revoke($owner,$company->id,(string)$ownerMembership->id,'Owner protection');
            $this->fail('Owner membership was revoked.');
        } catch (AuthorizationException) {
            $this->assertTrue((bool)DB::table('company_memberships')->where('id',$ownerMembership->id)->value('is_active'));
        }
        DB::table('company_memberships')->where('id',$ownerMembership->id)->update(['is_active'=>false]);
        try {
            $service->staff($owner,$company->id);
            $this->fail('Revoked owner retained staff access.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
    }

    public function test_staff_management_screen_lists_members_and_owner_can_open_editor():void
    {
        $company=Company::create(['name'=>'Staff Screen Venue','slug'=>'staff-screen-venue']);
        $hall=Hall::create(['company_id'=>$company->id,'name'=>'Screen Hall','slug'=>'screen-hall','capacity'=>60]);
        $owner=User::create(['name'=>'Screen Owner','email'=>'screen-owner@example.test','password'=>'test-only']);
        $manager=User::create(['name'=>'Screen Manager','email'=>'screen-manager@example.test','password'=>'test-only']);
        foreach([[$owner,'owner'],[$manager,'manager']] as [$user,$role]){
            $user->markEmailAsVerified();
            CompanyMembership::create(['company_id'=>$company->id,'user_id'=>$user->id,'role'=>$role]);
        }
        $membership=CompanyMembership::where('user_id',$manager->id)->first();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->be($owner);
        Livewire::test(StaffManagement::class)
            ->assertSee('Screen Manager')
            ->call('selectStaff',(string)$membership->id)
            ->assertSee('Save staff access')
            ->set('staffRole','reception')
            ->set('staffHallIds',[$hall->id])
            ->set('staffReason','Change duties after staffing review')
            ->call('saveStaff')
            ->assertHasNoErrors();
        $this->assertSame('reception',DB::table('company_memberships')->where('id',$membership->id)->value('role'));
    }
}
