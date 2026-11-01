<?php

use App\Services\PublicBookingConfirmationService;
use App\Services\PublicBookingHoldService;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config()->set('public_booking.enabled', true);
config()->set('public_booking.holds_enabled', true);
config()->set('public_booking.synthetic_test_boundary', true);
config()->set('public_booking.synthetic_verifier_secret', str_repeat('s', 32));

[, $mode, $company, $hall, $quoteFile, $value, $resultFile, $readyFile, $startFile] = $argv;
file_put_contents($readyFile, (string) getmypid());
$deadline = microtime(true) + 15;
while (! is_file($startFile)) {
    if (microtime(true) > $deadline) { file_put_contents($resultFile, 'timeout'); exit(3); }
    usleep(10_000);
}
try {
    if ($mode === 'hold') {
        $quote = json_decode((string) file_get_contents($quoteFile), true, 64, JSON_THROW_ON_ERROR);
        app(PublicBookingHoldService::class)->create($company, $quote, $hall,
            ['name' => 'Concurrent Customer '.$value, 'email' => 'customer'.$value.'@example.test'],
            $value);
    } else {
        app(PublicBookingConfirmationService::class)->confirm($company, $value);
    }
    file_put_contents($resultFile, 'ok');
    exit(0);
} catch (\Symfony\Component\HttpKernel\Exception\ConflictHttpException) {
    file_put_contents($resultFile, 'conflict');
    exit(0);
} catch (\Throwable $e) {
    file_put_contents($resultFile, get_class($e).': '.$e->getMessage());
    exit(2);
}