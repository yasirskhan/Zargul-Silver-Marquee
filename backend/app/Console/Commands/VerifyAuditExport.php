<?php

namespace App\Console\Commands;

use App\Services\AuditExportService;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Recovery-side verification of an encrypted audit archive. Does not restore
 * or publish data; it proves the archive decrypts and its structure/checksum
 * are intact before an approved recovery procedure uses it.
 */
final class VerifyAuditExport extends Command
{
    protected $signature = 'venuedesk:verify-audit-export
        {path : Path to the encrypted .enc archive}
        {--expected-sha256= : Optional expected plaintext SHA-256}';

    protected $description = 'Decrypt and verify a VenueDesk encrypted audit export for recovery';

    public function handle(AuditExportService $service): int
    {
        $path = (string) $this->argument('path');
        if (! is_file($path) || ! is_readable($path)) {
            $this->error('Audit export file is missing or unreadable.');

            return self::FAILURE;
        }
        $ciphertext = file_get_contents($path);
        if (! is_string($ciphertext) || $ciphertext === '') {
            $this->error('Audit export file is empty.');

            return self::FAILURE;
        }

        try {
            $result = $service->verify(
                $ciphertext,
                $this->option('expected-sha256') ?: null,
            );
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line('Audit export verified.');
        $this->line('Export ID: '.$result['exportId']);
        $this->line('Company ID: '.$result['companyId']);
        $this->line('Entries: '.$result['entryCount']);
        $this->line('Exported at: '.($result['exportedAt'] ?? 'unknown'));
        $this->line('Plaintext SHA-256: '.$result['sha256']);

        return self::SUCCESS;
    }
}
