<?php
declare(strict_types=1);
namespace Hafzatech\Central;

/** Private authenticated read-only Central chrome. Mutations remain POST+CSRF. */
final class CentralStaffPortalReadPage
{
    public static function render(string $title,string $content,string $csrf,
        string $active='home'):string
    {
        $e=static fn(string $v):string=>htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $proof=$e($csrf);
        $nav=static function(string $key,string $href,string $label)use($active,$e):string{
            $current=$key===$active?' aria-current="page"':'';
            return '<a href="'.$href.'"'.$current.'>'.$e($label).'</a>';
        };
        return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<title>'.$e($title).' · HAFZATECH Central</title>'
            .'<style>body{margin:0;font-family:system-ui,sans-serif;background:#f6f1e7;color:#18352b}'
            .'.shell{display:grid;grid-template-columns:250px 1fr;min-height:100vh}'
            .'aside{background:#123b2d;color:#f8f3e7;padding:24px}aside a{display:block;color:#f8f3e7;'
            .'text-decoration:none;padding:10px 12px;border-radius:8px;margin:4px 0}'
            .'aside a[aria-current]{background:#c8a55b;color:#123b2d;font-weight:700}'
            .'main{padding:32px;max-width:1200px}h1{margin-top:0;font-family:Georgia,serif}'
            .'section{background:#fff;border:1px solid #d9cfbc;border-radius:14px;padding:18px;margin:16px 0}'
            .'table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:10px;border-bottom:1px solid #eee}'
            .'form{display:inline}button{background:#123b2d;color:#fff;border:0;border-radius:8px;padding:9px 13px;cursor:pointer}'
            .'input,select{padding:9px;border:1px solid #bfb4a1;border-radius:8px}'
            .'.central-metrics{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px}'
            .'.metric-card{background:#f6f1e7;border-radius:12px;padding:14px;display:grid;gap:4px}'
            .'@media(max-width:760px){.shell{grid-template-columns:1fr}aside{position:static}main{padding:18px}}'
            .'</style></head><body><div class="shell"><aside><h2>HAFZATECH</h2><p>Central Control Center</p><nav>'
            .$nav('home','/staff/home','Control Center')
            .$nav('customers','/staff/customers/directory','Customers & installations')
            .'</nav><form method="post" action="/staff/session/logout">'
            .'<input type="hidden" name="staff_csrf" value="'.$proof.'">'
            .'<button type="submit">Sign out</button></form></aside>'
            .'<main><h1>'.$e($title).'</h1>'.$content.'</main></div></body></html>';
    }
}