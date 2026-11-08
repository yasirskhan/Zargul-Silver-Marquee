<?php
if (getenv('CENTRAL_BROWSER_TRANSPORT') === 'test') {
    $_SERVER['HTTPS'] = 'on';
    $_SERVER['SERVER_PORT'] = '443';
    $_SERVER['HTTP_HOST'] = 'lichall.hafzatech.com';
    $_SERVER['HTTP_ORIGIN'] = 'https://lichall.hafzatech.com';
    $_SERVER['HTTP_SEC_FETCH_SITE'] = 'same-origin';
}
$path = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if ($path === '/staff.css') {
    header('Content-Type: text/css; charset=utf-8');
    header('Cache-Control: public, max-age=300');
    readfile(dirname(__DIR__).'/public/staff.css');
    return true;
}
require dirname(__DIR__).'/public/staff.php';