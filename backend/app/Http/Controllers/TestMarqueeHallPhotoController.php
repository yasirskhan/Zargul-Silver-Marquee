<?php
namespace App\Http\Controllers;

use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\SitePublishedHtmlService;
use Illuminate\Support\Facades\DB;

final class TestMarqueeHallPhotoController extends Controller
{
    public function __invoke(SitePublishedHtmlService $html)
    {
        abort_unless(app()->environment('testing')
            && config('public_site.test_fallback') === true, 404);
        $owner = User::query()->where('email', 'browser-e2e-owner@example.test')->firstOrFail();
        $company = CompanyMembership::query()->where('user_id', $owner->id)
            ->where('role', 'owner')->firstOrFail()->company_id;
        $website = DB::table('websites')->where('company_id', $company)
            ->where('name', 'Phase 10 Browser Fixture')->first();
        abort_if($website === null, 404);

        return response($html->render($company, $website->id, '/',
            'https://marquee-browser.localhost/'))
            ->header('Content-Type', 'text/html; charset=utf-8');
    }
}