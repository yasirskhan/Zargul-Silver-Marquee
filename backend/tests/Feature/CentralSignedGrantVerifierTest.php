<?php

namespace Tests\Feature;

use App\Services\CentralSignedGrantVerifier;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class CentralSignedGrantVerifierTest extends TestCase
{
    use RefreshDatabase;

    private function sample(): array
    {
        $pair=sodium_crypto_sign_keypair();
        $public=rtrim(strtr(base64_encode(sodium_crypto_sign_publickey($pair)), '+/', '-_'), '=');
        config()->set('central_control.trusted_signing_public_keys', ['synthetic-key-1'=>$public]);
        $grant=[
            'api_version'=>'v1','issuer'=>'HAFZATECH','product'=>'VenueDesk',
            'license_id'=>'e7e8e18a-6bbf-4b88-8a49-5876c0c0ff00',
            'customer_id'=>'b86bdadc-afbd-4ffd-823d-bcd47d0d06a7',
            'installation_id'=>'c6815111-7ea3-4345-b513-01cbfda55412',
            'revision'=>2,'term_months'=>12,'edition'=>'standard',
            'approved_domains'=>['venue.example.test'],
            'not_before'=>'2026-10-01T00:00:00Z','expires_at'=>'2027-10-01T00:00:00Z',
            'limits'=>['max_halls'=>3,'max_staff'=>20],
            'features'=>['booking'=>true,'inventory'=>false],
        ];
        return [$pair,$grant];
    }

    private function sign(array $grant,string $pair): array
    {
        $bytes=json_encode($grant,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        $signature=sodium_crypto_sign_detached('HAFZATECH-LICENSE-GRANT-V1'."\n".$bytes,
            sodium_crypto_sign_secretkey($pair));
        return ['key_id'=>'synthetic-key-1',
            'payload'=>rtrim(strtr(base64_encode($bytes),'+/','-_'),'='),
            'signature'=>rtrim(strtr(base64_encode($signature),'+/','-_'),'=')];
    }

    public function test_signed_grant_validates_identity_and_expiry_without_any_central_network(): void
    {
        [$pair,$grant]=$this->sample();
        $service=app(CentralSignedGrantVerifier::class);
        $envelope=$this->sign($grant,$pair);
        $valid=$service->verify($envelope,$grant['customer_id'],$grant['installation_id'],
            'venue.example.test',1,new DateTimeImmutable('2026-11-01T00:00:00Z'));
        $this->assertTrue($valid['active']);
        $this->assertSame(2,$valid['grant']['revision']);
        $expired=$service->verify($envelope,$grant['customer_id'],$grant['installation_id'],
            'venue.example.test',1,new DateTimeImmutable('2027-10-01T00:00:00Z'));
        $this->assertFalse($expired['active']);
        $this->assertSame($grant['license_id'],$expired['grant']['license_id']);
    }

    public function test_tampering_or_rollback_is_never_accepted(): void
    {
        [$pair,$grant]=$this->sample();
        $service=app(CentralSignedGrantVerifier::class);
        $valid=$this->sign($grant,$pair);
        $tampered=$valid;
        $tampered['payload']=rtrim(strtr(base64_encode('{"issuer":"HAFZATECH"}'),'+/','-_'),'=');
        foreach ([
            [$tampered,$grant['customer_id'],$grant['installation_id'],'venue.example.test',1],
            [$valid,$grant['customer_id'],$grant['installation_id'],'foreign.example.test',1],
            [$valid,$grant['customer_id'],$grant['installation_id'],'venue.example.test',2],
            [$valid,$grant['customer_id'],'c6815111-7ea3-4345-b513-01cbfda55413','venue.example.test',1],
        ] as [$envelope,$customer,$installation,$domain,$revision]) {
            try {
                $service->verify($envelope,$customer,$installation,$domain,$revision,
                    new DateTimeImmutable('2026-11-01T00:00:00Z'));
                $this->fail('Forged/foreign/replayed license accepted.');
            } catch (InvalidArgumentException) {}
        }
    }

    public function test_missing_trusted_key_and_malformed_entitlements_fail_closed(): void
    {
        [$pair,$grant]=$this->sample();
        $service=app(CentralSignedGrantVerifier::class);
        $envelope=$this->sign($grant,$pair);
        config()->set('central_control.trusted_signing_public_keys', []);
        try {
            $service->verify($envelope,$grant['customer_id'],$grant['installation_id'],
                'venue.example.test',0,new DateTimeImmutable('2026-11-01T00:00:00Z'));
            $this->fail('Unpinned signer accepted.');
        } catch (InvalidArgumentException) {}
        config()->set('central_control.trusted_signing_public_keys',
            ['synthetic-key-1'=>rtrim(strtr(base64_encode(sodium_crypto_sign_publickey($pair)),'+/','-_'),'=')]);
        $grant['features']['booking']='true';
        $invalid=$this->sign($grant,$pair);
        $this->expectException(InvalidArgumentException::class);
        $service->verify($invalid,$grant['customer_id'],$grant['installation_id'],
            'venue.example.test',0,new DateTimeImmutable('2026-11-01T00:00:00Z'));
    }
    public function test_signed_designer_credit_is_validated_and_cannot_be_forged(): void
    {
        [$pair, $grant] = $this->sample();
        $service = app(CentralSignedGrantVerifier::class);
        $grant['designer_credit'] = [
            'enabled' => true, 'prefix' => 'Website by',
            'label' => 'HAFZATECH', 'url' => 'https://hafzatech.com',
            'alignment' => 'center',
        ];
        $valid = $service->verify($this->sign($grant, $pair), $grant['customer_id'],
            $grant['installation_id'], 'venue.example.test', 0,
            new DateTimeImmutable('2026-11-01T00:00:00Z'));
        $this->assertTrue($valid['grant']['designer_credit']['enabled']);

        foreach ([
            ['url' => 'javascript:alert(1)'],
            ['url' => 'http://insecure.example.test'],
            ['alignment' => 'justify'],
            ['prefix' => '<script>'],
        ] as $override) {
            $forged = $grant;
            $forged['designer_credit'] = array_replace($forged['designer_credit'], $override);
            try {
                $service->verify($this->sign($forged, $pair), $grant['customer_id'],
                    $grant['installation_id'], 'venue.example.test', 0,
                    new DateTimeImmutable('2026-11-01T00:00:00Z'));
                $this->fail('Invalid designer credit was accepted.');
            } catch (InvalidArgumentException) {}
        }

        $grant['designer_credit'] = ['enabled' => false, 'prefix' => '',
            'label' => '', 'url' => '', 'alignment' => 'center'];
        $disabled = $service->verify($this->sign($grant, $pair), $grant['customer_id'],
            $grant['installation_id'], 'venue.example.test', 0,
            new DateTimeImmutable('2026-11-01T00:00:00Z'));
        $this->assertFalse($disabled['grant']['designer_credit']['enabled']);
    }}
