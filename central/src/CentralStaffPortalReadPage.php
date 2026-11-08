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
            .'<link rel="stylesheet" href="/staff.css">'
            .'<title>'.$e($title).' · HAFZATECH Central</title>'
            .'</head><body><div class="shell"><aside><h2>HAFZATECH</h2><p>Central Control Center</p><nav>'
            .$nav('home','/staff/home','Control Center')
            .$nav('customers','/staff/customers/directory','Customers & installations')
            .$nav('plans','/staff/plans','Subscriptions and plans')
            .$nav('team','/staff/team','Staff and permissions')
            .$nav('audit','/staff/audit','Security and audit')
            .'</nav><form method="post" action="/staff/session/logout">'
            .'<input type="hidden" name="staff_csrf" value="'.$proof.'">'
            .'<button type="submit">Sign out</button></form></aside>'
            .'<main><h1>'.$e($title).'</h1>'.$content.'</main></div></body></html>';
    }
}