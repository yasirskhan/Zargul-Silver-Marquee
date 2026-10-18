<?php

namespace Tests\Feature;

use App\Services\CentralInstallationIdentity;
use App\Services\CentralVerifiedLicenseDeliveryService;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class CentralVerifiedLicenseDeliveryServiceTest extends TestCase
{
    use RefreshDatabase;

    private static function encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw),'+/','-_'),'=');
    }

    private function enroll(): array
    {
        config()->set('central_control.activation_enabled',true);
        $id=app(CentralInstallationIdentity::class)->initialize();
        $customer=(string)Str::uuid();
        DB::table('central_installation_activation')->insert([
            'singleton_id'=>1,'installation_id'=>$id['installation_id'],
            'customer_id'=>$customer,'approved_domain'=>'hall.example.test',
            'status'=>'registered_pending_license','central_registered_at'=>now(),
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        $pair=sodium_crypto_sign_keypair();
        config()->set('central_control.trusted_signing_public_keys',[
            'signed-test'=>self::encode(sodium_crypto_sign_publickey($pair))
        ]);
        return [$pair,$customer,$id['installation_id']];
    }

    private function signed(array $data,string $pair,string $context): array
    {
        $bytes=json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        return [
            'key_id'=>'signed-test',
            'payload'=>self::encode($bytes),
            'signature'=>self::encode(sodium_crypto_sign_detached(
                $context."\n".$bytes,sodium_crypto_sign_secretkey($pair))),
        ];
    }

    private function evidence(string $pair,string $customer,string $installation,
        string $nonce,int $receiptRevision=1): array
    {
        $grant=[
            'api_version'=>'v1','issuer'=>'HAFZATECH','product'=>'VenueDesk',
            'license_id'=>'e7e8e18a-6bbf-4b88-8a49-5876c0c0ff00',
            'customer_id'=>$customer,'installation_id'=>$installation,
            'revision'=>1,'term_months'=>12,'edition'=>'standard',
            'approved_domains'=>['hall.example.test'],
            'not_before'=>'2026-10-01T00:00:00Z',
            'expires_at'=>'2027-10-01T00:00:00Z',
            'limits'=>['max_halls'=>2,'max_staff'=>10],
            'features'=>['booking'=>true],
        ];
        $receipt=[
            'api_version'=>'v1','issuer'=>'HAFZATECH',
            'installation_id'=>$installation,'customer_id'=>$customer,
            'nonce'=>$nonce,'status'=>'signed_revision_pending_delivery',
            'revision'=>$receiptRevision,'issued_at'=>'2026-10-17T23:00:00Z',
        ];
        return [
            $this->signed($grant,$pair,'HAFZATECH-LICENSE-GRANT-V1'),
            $this->signed($receipt,$pair,'HAFZATECH-INSTALL-STATUS-RECEIPT-V1'),
        ];
    }

    public function test_requires_both_matching_signatures_before_cache_and_verification_clock_move(): void
    {
        [$pair,$customer,$installation]=$this->enroll();
        $nonce=self::encode(random_bytes(32));
        [$grant,$receipt]=$this->evidence($pair,$customer,$installation,$nonce);
        $now=new DateTimeImmutable('2026-10-17T23:00:01Z');
        $svc=app(CentralVerifiedLicenseDeliveryService::class);
        $accepted=$svc->accept($grant,$receipt,$customer,'hall.example.test',$nonce,$now);
        $this->assertTrue($accepted['active']);
        $this->assertTrue($accepted['changed']);
        $this->assertSame(1,$accepted['revision']);
        $this->assertDatabaseCount('central_signed_license_cache',1);
        $this->assertDatabaseCount('central_license_check_state',1);
        $this->assertSame('2026-10-17T23:00:01Z',
            (new DateTimeImmutable(DB::table('central_license_check_state')
                ->value('last_online_verified_at')))->format('Y-m-d\TH:i:s\Z'));
        $this->assertDatabaseCount('finance_journals',0);
    }

    public function test_mismatched_signed_revision_never_moves_cache_or_success_clock(): void
    {
        [$pair,$customer,$installation]=$this->enroll();
        $nonce=self::encode(random_bytes(32));
        [$grant,$receipt]=$this->evidence($pair,$customer,$installation,$nonce,2);
        $svc=app(CentralVerifiedLicenseDeliveryService::class);
        try {
            $svc->accept($grant,$receipt,$customer,'hall.example.test',$nonce,
                new DateTimeImmutable('2026-10-17T23:00:01Z'));
            $this->fail('Mismatched signed grant and receipt accepted.');
        } catch (InvalidArgumentException) {}
        $this->assertDatabaseCount('central_signed_license_cache',0);
        $this->assertDatabaseCount('central_license_check_state',0);
        try {
            $svc->accept($grant,$receipt,$customer,'hall.example.test',
                self::encode(random_bytes(32)),
                new DateTimeImmutable('2026-10-17T23:00:01Z'));
            $this->fail('Unbound signed receipt was accepted.');
        } catch (InvalidArgumentException) {}
        $this->assertDatabaseCount('central_signed_license_cache',0);
    }

    public function test_exact_same_revision_signed_status_refreshes_verification_without_renewing_license(): void
    {
        [$pair,$customer,$installation]=$this->enroll();
        $nonce=self::encode(random_bytes(32));
        [$grant,$receipt]=$this->evidence($pair,$customer,$installation,$nonce);
        // Require immutable bound owner company for a scheduled/manual
        // check to count, never merely a copied license cache.
        $company=(string)Str::uuid();
        DB::table('companies')->insert([
            'id'=>$company,'name'=>'Test License Company','slug'=>'signed-existing-venue',
            'is_active'=>true,'created_at'=>now(),'updated_at'=>now()
        ]);
        DB::table('central_installation_activation')->where('singleton_id',1)
            ->update(['company_id'=>$company]);
        $service=app(CentralVerifiedLicenseDeliveryService::class);
        $first=new DateTimeImmutable('2026-10-17T23:00:01Z');
        $service->accept($grant,$receipt,$customer,'hall.example.test',$nonce,$first);
        $next=new DateTimeImmutable('2026-10-17T23:00:30Z');
        $freshNonce=self::encode(random_bytes(32));
        [, $newReceipt]=$this->evidence($pair,$customer,$installation,$freshNonce);
        $result=$service->verifyExisting($newReceipt,$customer,'hall.example.test',
            $freshNonce,$next);
        $this->assertSame(['revision'=>1,'active'=>true,'changed'=>false],$result);
        $this->assertDatabaseCount('central_signed_license_cache',1);
        $this->assertSame('2026-10-17T23:00:30Z',
            (new DateTimeImmutable(DB::table('central_license_check_state')
                ->value('last_online_verified_at')))->format('Y-m-d\TH:i:s\Z'));
        $before=DB::table('central_signed_license_cache')->value('envelope_sha256');
        try {
            $service->verifyExisting($newReceipt,$customer,'hall.example.test',
                self::encode(random_bytes(32)),new DateTimeImmutable('2026-10-17T23:00:40Z'));
            $this->fail('Unrelated server nonce was accepted.');
        }catch(InvalidArgumentException){}
        $this->assertSame($before,DB::table('central_signed_license_cache')->value('envelope_sha256'));
        $this->assertSame('2026-10-17T23:00:30Z',
            (new DateTimeImmutable(DB::table('central_license_check_state')
                ->value('last_online_verified_at')))->format('Y-m-d\TH:i:s\Z'));
        $this->assertDatabaseCount('finance_journals',0);
    }

    public function test_unenrolled_company_cannot_mark_signed_same_revision_online_success(): void
    {
        [$pair,$customer,$installation]=$this->enroll();
        $nonce=self::encode(random_bytes(32));
        [, $receipt]=$this->evidence($pair,$customer,$installation,$nonce);
        try{
            app(CentralVerifiedLicenseDeliveryService::class)->verifyExisting(
                $receipt,$customer,'hall.example.test',$nonce,
                new DateTimeImmutable('2026-10-17T23:00:01Z'));
            $this->fail('Unbound company was allowed to refresh online status.');
        }catch(InvalidArgumentException){}
        $this->assertDatabaseCount('central_license_check_state',0);
    }

    public function test_default_off_blocks_delivery_even_when_valid_crypto_exists(): void
    {
        [$pair,$customer,$installation]=$this->enroll();
        $nonce=self::encode(random_bytes(32));
        [$grant,$receipt]=$this->evidence($pair,$customer,$installation,$nonce);
        config()->set('central_control.activation_enabled',false);
        $this->expectException(AuthorizationException::class);
        app(CentralVerifiedLicenseDeliveryService::class)->accept(
            $grant,$receipt,$customer,'hall.example.test',$nonce,
            new DateTimeImmutable('2026-10-17T23:00:01Z')
        );
    }
}
