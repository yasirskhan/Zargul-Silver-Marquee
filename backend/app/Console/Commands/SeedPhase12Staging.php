<?php

namespace App\Console\Commands;

use App\Models\{Company,CompanyMembership,Hall,HallAssignment,User};
use App\Services\HallInventoryCatalogService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{Auth,DB};
use Illuminate\Support\Str;

/**
 * A strictly opt-in, isolated staging acceptance bootstrap.
 *
 * This never posts owned/borrowed stock, loans, money or purported physical
 * signatures. Owners must witness opening count through actual role-separated
 * UI actions, and infrastructure must preserve the matching file store.
 */
final class SeedPhase12Staging extends Command
{
    protected $signature = 'venuedesk:seed-phase12-staging';
    protected $description = 'Bootstrap synthetic Phase 12 staging users, two halls and zero-stock catalog only';

    private const USERS = [
        ['owner-a','owner','Staging Owner A','PHASE12_STAGING_OWNER_A_PASSWORD'],
        ['owner-b','owner','Staging Independent Owner B','PHASE12_STAGING_OWNER_B_PASSWORD'],
        ['owner-c','owner','Staging Independent Owner C','PHASE12_STAGING_OWNER_C_PASSWORD'],
        ['admin','admin','Staging Administrator','PHASE12_STAGING_ADMIN_PASSWORD'],
        ['manager-a','manager','Staging Source Manager A','PHASE12_STAGING_MANAGER_A_PASSWORD'],
        ['manager-b','manager','Staging Borrower Manager B','PHASE12_STAGING_MANAGER_B_PASSWORD'],
    ];

    public function handle():int
    {
        $testing=app()->environment('testing');
        $staging=app()->environment('staging');
        if ((!$testing&&!$staging) ||
            getenv('VENUE_PHASE12_STAGING_SEED_ALLOWED')!=='1') {
            $this->error('Phase 12 fixture refused: isolated testing/staging and explicit seed authorization required.');
            return self::FAILURE;
        }
        $url=(string)config('app.url');
        $host=(string)(parse_url($url,PHP_URL_HOST) ?: '');
        if ($staging && (!str_starts_with($url,'https://') ||
            !preg_match('/(staging|stage|uat|test)/i',$host))) {
            $this->error('Phase 12 fixture refused: staging requires an HTTPS stage/test/uat host.');
            return self::FAILURE;
        }
        $db=(string)DB::connection()->getDatabaseName();
        if ($db==='' || !preg_match('/(staging|stage|uat|test)/i',$db)) {
            $this->error('Phase 12 fixture refused: isolated staging/test PostgreSQL database is required.');
            return self::FAILURE;
        }

        $passwords=[];
        foreach(self::USERS as [$key,$role,$label,$secretName]) {
            $password=getenv($secretName);
            if (!is_string($password)||strlen($password)<16||strlen($password)>256) {
                $this->error('Phase 12 fixture refused: unique 16+ character staff passwords must come from secret environment configuration.');
                return self::FAILURE;
            }
            $passwords[$key]=$password;
        }
        if(count(array_unique($passwords))!==count($passwords)) {
            $this->error('Phase 12 fixture refused: separate account passwords must be unique.');
            return self::FAILURE;
        }
        $slug='phase12-synthetic-acceptance';
        if(Company::query()->where('slug',$slug)->exists()) {
            $this->error('Phase 12 fixture already exists. Reset only through an authorized paired DB/document snapshot restore.');
            return self::FAILURE;
        }
        try {
            DB::transaction(function()use($slug,$passwords):void {
                $company=Company::create(['name'=>'Phase 12 SYNTHETIC acceptance only',
                    'slug'=>$slug,'is_active'=>true]);
                $halls=[];
                foreach([['a','Hall A — Synthetic Source',250],['b','Hall B — Synthetic Borrower',250]]
                    as [$key,$name,$capacity]) {
                    $halls[$key]=Hall::create(['company_id'=>$company->id,
                        'slug'=>'phase12-synthetic-hall-'.$key,'name'=>$name,
                        'capacity'=>$capacity,'is_active'=>true]);
                }
                $users=[];
                foreach(self::USERS as [$key,$role,$label,$secretName]) {
                    $actor=User::create(['name'=>$label,
                        'email'=>'phase12-'.$key.'@example.test',
                        'password'=>$passwords[$key],'is_active'=>true]);
                    $actor->markEmailAsVerified();
                    $member=CompanyMembership::create(['company_id'=>$company->id,
                        'user_id'=>$actor->id,'role'=>$role,'is_active'=>true]);
                    if($key==='manager-a'||$key==='manager-b') {
                        HallAssignment::create(['company_id'=>$company->id,
                            'membership_id'=>$member->id,
                            'hall_id'=>$halls[$key==='manager-a'?'a':'b']->id]);
                    }
                    $users[$key]=$actor;
                }
                Auth::login($users['owner-a']);
                try {
                    foreach ($halls as $hall) {
                        foreach([
                            ['Chairs','Furniture',15],
                            ['Tables','Furniture',5],
                            ['Serving Trays','Catering',8],
                            ['Stage Lights','Equipment',2]
                        ] as [$name,$category,$minimum]) {
                            app(HallInventoryCatalogService::class)->create(
                                $users['owner-a'],$company->id,$hall->id,
                                (string)Str::uuid(),$name,$category,'piece',$minimum,
                                'Isolated synthetic catalog only; no opening stock counted');
                        }
                    }
                } finally {
                    Auth::logout();
                }
            },3);
        } catch (\Throwable $error) {
            $this->error('Phase 12 staging bootstrap rolled back. Inspect private application logs; no credentials are printed.');
            return self::FAILURE;
        }

        $this->info('Created synthetic Phase 12 company, two halls, six scoped staff identities and eight ZERO-STOCK items.');
        $this->line('Accounts: phase12-owner-a, owner-b, owner-c, admin, manager-a, manager-b @example.test.');
        $this->line('No passwords or MFA secrets are printed. Configure actual MFA privately at first login.');
        $this->warn('Opening stock, loans and payments remain unposted until independent authorized UAT; production release flags unchanged.');
        return self::SUCCESS;
    }
}
