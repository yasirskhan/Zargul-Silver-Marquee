<?php

namespace App\Services;

use InvalidArgumentException;

/**
 * Backend authority for the typed visual CMS layout. Never accept executable HTML,
 * arbitrary component keys, unsafe URLs, or unconstrained nested layouts.
 */
final class SiteContentValidator
{
    private const FIELDS = [
        'hero' => ['eyebrow', 'title', 'subtitle', 'buttonLabel', 'buttonHref', 'imageId'],
        'text' => ['title', 'body'],
        'image' => ['imageId', 'alt', 'caption'],
        'gallery' => ['title', 'mediaIds'],
        'halls' => ['title', 'description'],
        'packages' => ['title', 'description'],
        'services' => ['title', 'description'],
        'cta' => ['title', 'body', 'buttonLabel', 'buttonHref'],
        'enquiry' => ['title', 'description'],
        'columns' => [],
        'faq' => ['title', 'description'],
    ];

    public function validate(array $content): void
    {
        $this->keys($content, ['sections']);
        $sections = $content['sections'] ?? null;
        $this->require(is_array($sections) && array_is_list($sections) && count($sections) <= 60, 'Invalid page sections');

        $usedIds = [];
        $blockCount = 0;
        foreach ($sections as $section) {
            $this->block($section, 0, $usedIds, $blockCount);
        }
    }

    private function block(mixed $block, int $depth, array &$usedIds, int &$blockCount): void
    {
        $this->require(is_array($block) && ! array_is_list($block), 'Invalid CMS block');
        $this->keys($block, ['id', 'type', 'data', 'columns']);
        $this->id($block['id'] ?? null, $usedIds);
        $type = $block['type'] ?? null;
        $this->require(is_string($type) && array_key_exists($type, self::FIELDS), 'Unsupported CMS block type');
        $this->require($depth <= 2 && ++$blockCount <= 500, 'CMS layout is too deeply nested or large');

        $data = $block['data'] ?? [];
        $this->require(is_array($data) && (count($data) === 0 || ! array_is_list($data)), 'Invalid CMS block data');
        $this->keys($data, self::FIELDS[$type]);
        foreach ($data as $field => $value) {
            if ($field === 'mediaIds') {
                $this->require(is_array($value) && array_is_list($value) && count($value) <= 50, 'Invalid media list');
                foreach ($value as $id) {
                    $this->require(is_string($id) && strlen($id) >= 1 && strlen($id) <= 128, 'Invalid media reference');
                }
                continue;
            }
            $this->require(is_string($value) && mb_strlen($value) <= 5000, 'Invalid CMS text');
            // Strictly reject HTML; public rendering must additionally escape every field.
            $this->require(! str_contains($value, '<') && ! str_contains($value, '>') && ! preg_match('/javascript\s*:|\bon\w+\s*=/i', $value), 'Unsafe CMS text');
            if ($field === 'buttonHref') {
                $this->require($this->safeHref($value), 'Unsafe CMS link');
            }
        }

        if ($type !== 'columns') {
            $this->require(! array_key_exists('columns', $block), 'Unexpected nested columns');
            return;
        }

        $columns = $block['columns'] ?? null;
        $this->require(is_array($columns) && array_is_list($columns) && count($columns) >= 1 && count($columns) <= 3, 'Invalid columns');
        $sum = 0;
        $mobileOrders = [];
        foreach ($columns as $column) {
            $this->require(is_array($column) && ! array_is_list($column), 'Invalid column');
            $this->keys($column, ['id', 'widthDesktop', 'mobileOrder', 'blocks']);
            $this->id($column['id'] ?? null, $usedIds);
            $width = $column['widthDesktop'] ?? null;
            $order = $column['mobileOrder'] ?? null;
            $this->require(is_int($width) && $width >= 1 && $width <= 12, 'Invalid desktop column width');
            $this->require(is_int($order) && $order >= 1 && $order <= count($columns) && ! isset($mobileOrders[$order]), 'Invalid mobile column order');
            $mobileOrders[$order] = true;
            $sum += $width;
            $blocks = $column['blocks'] ?? null;
            $this->require(is_array($blocks) && array_is_list($blocks) && count($blocks) <= 20, 'Invalid column blocks');
            foreach ($blocks as $child) {
                $this->block($child, $depth + 1, $usedIds, $blockCount);
            }
        }
        $this->require($sum === 12, 'Desktop column widths must total 12');
    }

    private function id(mixed $id, array &$usedIds): void
    {
        $this->require(is_string($id) && preg_match('/\A[A-Za-z0-9_-]{1,80}\z/', $id) === 1 && ! isset($usedIds[$id]), 'Invalid or duplicate CMS ID');
        $usedIds[$id] = true;
    }

    private function keys(array $data, array $allowed): void
    {
        $this->require(array_diff(array_keys($data), $allowed) === [], 'Unknown CMS property');
    }

    private function safeHref(string $href): bool
    {
        if (preg_match('/\A#[A-Za-z0-9_-]+\z/', $href)) {
            return true;
        }
        if (preg_match('~\A/(?!/)[A-Za-z0-9/_#?&=%+.\-]*\z~', $href)) {
            return true;
        }

        return str_starts_with($href, 'https://')
            && filter_var($href, FILTER_VALIDATE_URL) !== false
            && parse_url($href, PHP_URL_USER) === null
            && parse_url($href, PHP_URL_PASS) === null
            && ! preg_match('/[\s<>"\x27]/', $href);
    }

    private function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new InvalidArgumentException($message);
        }
    }
}
