<?php

namespace Tests\Feature;

use App\Services\HallInventoryNoncleanFinalClosureService;
use Tests\TestCase;

/**
 * Certifies that large non-clean reconciliations produce readable, complete
 * multi-page immutable PDF snapshots, not truncated single-page evidence.
 */
final class HallInventoryNoncleanPdfPaginationTest extends TestCase
{
    public function test_numbered_final_pdf_preserves_every_reconciliation_line_across_pages(): void
    {
        $items = [];
        for ($i = 0; $i < 105; $i++) {
            $items[] = [
                'itemId' => sprintf('asset-%03d', $i),
                'dispatched' => 5,
                'good' => 3,
                'damaged' => 2,
                'writtenOffMissing' => 0,
                'writtenOffDamaged' => 2,
            ];
        }
        $snapshot = [
            'items' => $items,
            'obligations' => [
                'claim-final' => [
                    'currency' => 'USD', 'paidMinor' => 500,
                    'waivedMinor' => 250, 'remainingMinor' => 0,
                ],
            ],
        ];
        $service = app(HallInventoryNoncleanFinalClosureService::class);
        $render = new \ReflectionMethod($service, 'renderPdf');
        $pdf = $render->invoke($service, 'company-test', 'hall-test', 'loan-test', 42,
            str_repeat('a', 64), $snapshot);

        $this->assertStringStartsWith("%PDF-1.4\n", $pdf);
        $this->assertStringEndsWith("%%EOF\n", $pdf);
        $this->assertStringContainsString('Immutable receipt NCL-42', $pdf);
        $this->assertStringContainsString('Obligation claim-final USD paid 500 waived 250 remaining 0', $pdf);
        $this->assertSame(3, substr_count($pdf, '/Type /Page /Parent'));
        $this->assertStringContainsString('/Count 3', $pdf);
        foreach (array_keys($items) as $i) {
            $this->assertStringContainsString(sprintf('Item asset-%03d dispatched 5', $i), $pdf);
        }

        // Every referenced PDF object has a corresponding xref entry.
        preg_match('/xref\n0 (\d+)\n/', $pdf, $match);
        $this->assertNotEmpty($match);
        $this->assertSame((int)$match[1], substr_count($pdf, " 0 obj\n") + 1);
        $this->assertSame(hash('sha256', $pdf), hash('sha256', $render->invoke(
            $service, 'company-test', 'hall-test', 'loan-test', 42,
            str_repeat('a', 64), $snapshot
        )));
    }
}
