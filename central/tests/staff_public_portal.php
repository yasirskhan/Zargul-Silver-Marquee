<?php
declare(strict_types=1);

/**
 * Entrypoint safety and login UI smoke test. It never starts a public server.
 * Approved operator HTTPS/KMS/database integration is exercised by the
 * existing staff portal wiring/session tests; this test proves the new front
 * controller stays disabled by default and only renders login for the trusted
 * HTTPS host.
 */
$root = dirname(__DIR__);
$script = $root.'/public/staff.php';
if (!is_file($script)) throw new RuntimeException('staff front controller missing');

function runStaff(string $code, array $env = []): array
{
    $base = getenv();
    foreach ($env as $key => $value) $base[$key] = $value;
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open(
        [PHP_BINARY, '-r', $code],
        $descriptors,
        $pipes,
        null,
        $base,
    );
    if (!is_resource($proc)) throw new RuntimeException('cannot start staff smoke process');
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($proc);
    return [$exit, $stdout, $stderr];
}

$include = 'include '.var_export($root.'/public/staff.php', true).';';
[$exit, $out] = runStaff($include, ['CENTRAL_STAFF_PUBLIC_PORTAL_ENABLED' => 'false']);
if ($exit !== 0 || !str_contains($out, 'Central staff portal is not enabled')) {
    throw new RuntimeException('disabled portal did not fail closed');
}

$trustedGet = '$_SERVER["HTTP_HOST"]="lichall.hafzatech.com";'
    .'$_SERVER["HTTPS"]="on";$_SERVER["SERVER_PORT"]="443";'
    .'$_SERVER["REQUEST_METHOD"]="GET";$_SERVER["REQUEST_URI"]="/staff/login";'
    .$include;
[$exit, $out] = runStaff($trustedGet, [
    'CENTRAL_STAFF_PUBLIC_PORTAL_ENABLED' => 'true',
]);
if ($exit !== 0 || !str_contains($out, 'HAFZATECH staff sign in')
    || !str_contains($out, 'action="/staff/session/login"')
    || !str_contains($out, 'name="totp"')) {
    throw new RuntimeException('trusted login page missing or invalid');
}
if (str_contains($out, 'cookie') || str_contains($out, 'csrf')) {
    throw new RuntimeException('login page leaked secret material');
}

$wrongHost = '$_SERVER["HTTP_HOST"]="evil.example";'
    .'$_SERVER["HTTPS"]="on";$_SERVER["REQUEST_METHOD"]="GET";'
    .'$_SERVER["REQUEST_URI"]="/staff/login";'.$include;
[$exit, $out] = runStaff($wrongHost, [
    'CENTRAL_STAFF_PUBLIC_PORTAL_ENABLED' => 'true',
]);
if ($exit !== 0 || !str_contains($out, 'Trusted HTTPS HAFZATECH host required')) {
    throw new RuntimeException('wrong host was not denied');
}

$postWithoutConfig = '$_SERVER["HTTP_HOST"]="lichall.hafzatech.com";'
    .'$_SERVER["HTTPS"]="on";$_SERVER["REQUEST_METHOD"]="POST";'
    .'$_SERVER["REQUEST_URI"]="/staff/session/login";'.$include;
[$exit, $out] = runStaff($postWithoutConfig, [
    'CENTRAL_STAFF_PUBLIC_PORTAL_ENABLED' => 'true',
]);
if ($exit !== 0 || !str_contains($out, 'Staff KMS bootstrap is unavailable')) {
    throw new RuntimeException('POST without external KMS bootstrap was not denied');
}

echo "PASS: central staff front controller default-off, trusted host, login UI, no secret output, KMS fail-closed\n";
