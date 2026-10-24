<?php

namespace App\Services;

use finfo;
use InvalidArgumentException;
use RuntimeException;

/**
 * Strict image decoder and private WebP encoder, not a public file endpoint.
 * Re-encoding strips ancillary input chunks (including EXIF and metadata).
 */
final class SiteMediaVariantService
{
    public function webp1200(string $source, string $expectedMime): array
    {
        return $this->webpFit($source, $expectedMime, 1200);
    }

    /** Decode arbitrary approved/staged image bytes and emit a private WebP fit within bounds. */
    public function webpFit(string $source, string $expectedMime, int $maxDimension): array
    {
        if ($maxDimension < 1 || $maxDimension > 6000) {
            throw new InvalidArgumentException('Invalid image variant bound.');
        }
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagewebp')) {
            throw new RuntimeException('GD WebP support is required for image approval.');
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($source);
        $dimensions = @getimagesizefromstring($source);
        if ($mime !== $expectedMime || ! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)
            || ! is_array($dimensions) || ($dimensions['mime'] ?? null) !== $mime
            || $dimensions[0] < 1 || $dimensions[1] < 1
            || $dimensions[0] > 6000 || $dimensions[1] > 6000
            || $dimensions[0] * $dimensions[1] > 20_000_000) {
            throw new InvalidArgumentException('Original image no longer passes binary validation.');
        }

        $image = @imagecreatefromstring($source);
        if (! $image) {
            throw new InvalidArgumentException('Original image cannot be safely decoded.');
        }

        try {
            $scale = min(1, $maxDimension / max(imagesx($image), imagesy($image)));
            $width = max(1, (int) round(imagesx($image) * $scale));
            $height = max(1, (int) round(imagesy($image) * $scale));
            $target = imagecreatetruecolor($width, $height);
            if (! $target) {
                throw new RuntimeException('Could not allocate image variant.');
            }
            try {
                imagealphablending($target, false);
                imagesavealpha($target, true);
                $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
                imagefill($target, 0, 0, $transparent);
                if (! imagecopyresampled($target, $image, 0, 0, 0, 0, $width, $height, imagesx($image), imagesy($image))) {
                    throw new RuntimeException('Could not resize image variant.');
                }

                ob_start();
                try {
                    $success = imagewebp($target, null, 82);
                    $bytes = ob_get_contents();
                } finally {
                    ob_end_clean();
                }
                if (! $success || ! is_string($bytes) || $bytes === ''
                    || (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes) !== 'image/webp') {
                    throw new RuntimeException('WebP image encoding failed.');
                }

                return ['bytes' => $bytes, 'width' => $width, 'height' => $height];
            } finally {
                imagedestroy($target);
            }
        } finally {
            imagedestroy($image);
        }
    }
}
