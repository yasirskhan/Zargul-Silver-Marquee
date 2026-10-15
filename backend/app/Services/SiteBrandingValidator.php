<?php

namespace App\Services;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The only accepted tenant branding tokens. Never store executable styles or
 * user-defined CSS/JavaScript in public theme configuration.
 */
final class SiteBrandingValidator
{
    private const PRESETS = ['silver', 'ivory', 'midnight', 'sage'];
    private const FONTS = ['classic', 'modern'];

    public function validate(array $branding): void
    {
        $required = ['siteName', 'tagline', 'themePreset', 'fontPreset', 'logoMediaId', 'faviconMediaId'];
        if (array_is_list($branding) || array_diff(array_keys($branding), $required)
            || array_diff($required, array_keys($branding))) {
            throw new InvalidArgumentException('Unsupported or missing site branding properties.');
        }
        $this->text($branding['siteName'], 1, 120, 'Venue name');
        $this->text($branding['tagline'], 0, 240, 'Venue tagline');

        if (! is_string($branding['themePreset']) || ! in_array($branding['themePreset'], self::PRESETS, true)
            || ! is_string($branding['fontPreset']) || ! in_array($branding['fontPreset'], self::FONTS, true)) {
            throw new InvalidArgumentException('Choose an approved venue theme and typography preset.');
        }
        foreach (['logoMediaId', 'faviconMediaId'] as $field) {
            if (! is_null($branding[$field]) && (! is_string($branding[$field])
                || ! Str::isUuid($branding[$field]))) {
                throw new InvalidArgumentException('Branding images must reference staged website assets.');
            }
        }
    }

    private function text(mixed $value, int $min, int $max, string $label): void
    {
        if (! is_string($value) || mb_strlen(trim($value)) < $min
            || mb_strlen($value) > $max
            || preg_match('/[<>\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F]/', $value)
            || preg_match('/javascript\\s*:|\\bon\\w+\\s*=/i', $value)) {
            throw new InvalidArgumentException($label.' contains invalid or unsafe characters.');
        }
    }
}
