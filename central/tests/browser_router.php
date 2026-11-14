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
if ($path === '/__test/revoke-billing-role'
    && getenv('CENTRAL_BROWSER_TRANSPORT') === 'test') {
    $db = new PDO(getenv('CENTRAL_DB_DSN'), getenv('CENTRAL_DB_USERNAME'),
        getenv('CENTRAL_DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->prepare("UPDATE central_staff_role_grants SET revoked_at=now()
        WHERE staff_id='dddddddd-dddd-4ddd-8ddd-dddddddddddd'
          AND role='billing_officer' AND revoked_at IS NULL")->execute();
    header('Content-Type: text/plain');
    echo 'revoked';
    return true;
}require dirname(__DIR__).'/public/staff.php';