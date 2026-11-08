<?php
// Test-only transport shim for the PHP development server. Production vhosts
// supply real HTTPS SERVER variables and never load this file.
if (getenv('CENTRAL_BROWSER_TRANSPORT') === 'test') {
    $_SERVER['HTTPS'] = 'on';
    $_SERVER['SERVER_PORT'] = '443';
    $_SERVER['HTTP_HOST'] = 'lichall.hafzatech.com';
}