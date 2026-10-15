<?php

namespace Tests\Feature;

use App\Services\SiteContentValidator;
use InvalidArgumentException;
use Tests\TestCase;

class SiteContentValidatorTest extends TestCase
{
    private function valid(): array
    {
        return ['sections' => [
            ['id' => 'hero_1', 'type' => 'hero', 'data' => ['title' => 'Celebrate here', 'buttonHref' => '#contact']],
            ['id' => 'columns_1', 'type' => 'columns', 'data' => [], 'columns' => [
                ['id' => 'col_a', 'widthDesktop' => 5, 'mobileOrder' => 2, 'blocks' => [
                    ['id' => 'image_a', 'type' => 'image', 'data' => ['imageId' => 'safe-media', 'alt' => 'Grand hall']],
                ]],
                ['id' => 'col_b', 'widthDesktop' => 7, 'mobileOrder' => 1, 'blocks' => [
                    ['id' => 'text_a', 'type' => 'text', 'data' => ['title' => 'Our venue']],
                ]],
            ]],
        ]];
    }

    public function test_accepts_typed_responsive_layout(): void
    {
        $this->app->make(SiteContentValidator::class)->validate($this->valid());
        $this->assertTrue(true);
    }

    public function test_rejects_scripts_unsafe_urls_and_unknown_properties(): void
    {
        foreach ([
            function (&$c): void { $c['sections'][0]['data']['title'] = '<script>alert(1)</script>'; },
            function (&$c): void { $c['sections'][0]['data']['buttonHref'] = 'javascript:alert(1)'; },
            function (&$c): void { $c['sections'][0]['onClick'] = 'alert(1)'; },
            function (&$c): void { $c['sections'][0]['data']['markup'] = 'unexpected'; },
            function (&$c): void { $c['sections'][0]['data']['buttonHref'] = '//evil.test'; },
        ] as $mutate) {
            $payload = $this->valid();
            $mutate($payload);
            try {
                $this->app->make(SiteContentValidator::class)->validate($payload);
                $this->fail('Malformed or unsafe content should be rejected');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_rejects_duplicates_bad_column_widths_and_fake_blocks(): void
    {
        foreach ([
            function (&$c): void { $c['sections'][1]['columns'][0]['id'] = 'hero_1'; },
            function (&$c): void { $c['sections'][1]['columns'][0]['widthDesktop'] = 6; },
            function (&$c): void { $c['sections'][1]['columns'][0]['mobileOrder'] = 1; },
            function (&$c): void { $c['sections'][0]['type'] = 'arbitrary-html'; },
        ] as $mutate) {
            $payload = $this->valid();
            $mutate($payload);
            $this->expectValidationFailure($payload);
        }
    }

    private function expectValidationFailure(array $content): void
    {
        try {
            $this->app->make(SiteContentValidator::class)->validate($content);
            $this->fail('Unsafe CMS content accepted');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }
    }

    public function test_limits_sections_columns_and_nested_content(): void
    {
        $content = $this->valid();
        $content['sections'] = array_fill(0, 61, $content['sections'][0]);
        $this->expectValidationFailure($content);

        $content = $this->valid();
        $content['sections'][1]['columns'][0]['blocks'] = array_fill(0, 21, $content['sections'][0]);
        $this->expectValidationFailure($content);

        $content = $this->valid();
        $content['sections'][1]['columns'][1]['blocks'][0]['id'] = 'hero_1';
        $this->expectValidationFailure($content);
    }
}
