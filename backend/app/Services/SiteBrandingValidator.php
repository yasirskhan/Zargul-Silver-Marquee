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

    // Only fixed, operator-routed website pages can be menu entries.
    // No arbitrary URL or cross-tenant links may be saved by editors.
    public const NAVIGATION_PATHS = [
        '/', '/halls', '/packages', '/services', '/gallery',
        '/about', '/contact', '/privacy', '/terms',
    ];

    public function validate(array $branding): void
    {
        $required = ['siteName', 'tagline', 'themePreset', 'fontPreset', 'logoMediaId', 'faviconMediaId'];
        // Older immutable branding revisions have no footerText field.
        // An optional, strictly plain-text addition preserves their validity.
        $allowed = [...$required, 'footerText', 'navigationOrder'];
        if (array_is_list($branding) || array_diff(array_keys($branding), $allowed)
            || array_diff($required, array_keys($branding))) {
            throw new InvalidArgumentException('Unsupported or missing site branding properties.');
        }
        $this->text($branding['siteName'], 1, 120, 'Venue name');
        $this->text($branding['tagline'], 0, 240, 'Venue tagline');
        if (array_key_exists('footerText', $branding)) {
            $this->text($branding['footerText'], 0, 240, 'Footer message');
        }
        if (array_key_exists('navigationOrder', $branding)) {
            $paths = $branding['navigationOrder'];
            if (! is_array($paths) || ! array_is_list($paths)
                || count($paths) < 1 || count($paths) > count(self::NAVIGATION_PATHS)
                || ! in_array('/', $paths, true)) {
                throw new InvalidArgumentException('Choose an approved website menu including Home.');
            }
            foreach ($paths as $path) {
                if (! is_string($path) || ! in_array($path, self::NAVIGATION_PATHS, true)) {
                    throw new InvalidArgumentException('Only approved venue paths can be in the menu.');
                }
            }
            if (count($paths) !== count(array_unique($paths))) {
                throw new InvalidArgumentException('Website menu paths must be unique.');
            }
        }

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
