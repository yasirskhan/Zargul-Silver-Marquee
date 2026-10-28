<?php

use App\Models\User;
use App\Services\VenueInvoicePaymentService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $ownerId, $companyId, $invoiceId, $paymentId, $amount, $readyFile, $startFile, $resultFile]
    = $argv;

$owner = User::query()->findOrFail($ownerId);
Auth::login($owner);

file_put_contents($readyFile, (string) getmypid());
$deadline = microtime(true) + 15;
while (! is_file($startFile)) {
    if (microtime(true) > $deadline) {
        file_put_contents($resultFile, json_encode(['status' => 'timeout']));
        exit(3);
    }
    usleep(10_000);
}

try {
    $receipt = app(VenueInvoicePaymentService::class)->record(
        $owner, $companyId, $invoiceId, $paymentId,
        'cash', (int) $amount, null, 'Concurrent competing cash deposit',
    );
    file_put_contents($resultFile, json_encode([
        'status' => 'paid',
        'receipt' => $receipt['receiptNumber'],
    ], JSON_THROW_ON_ERROR));
    exit(0);
} catch (\Symfony\Component\HttpKernel\Exception\ConflictHttpException $e) {
    file_put_contents($resultFile, json_encode([
        'status' => 'rejected',
        'message' => $e->getMessage(),
    ], JSON_THROW_ON_ERROR));
    exit(0);
} catch (\Throwable $e) {
    file_put_contents($resultFile, json_encode([
        'status' => 'error',
        'class' => $e::class,
        'message' => $e->getMessage(),
    ], JSON_THROW_ON_ERROR));
    exit(2);
}
