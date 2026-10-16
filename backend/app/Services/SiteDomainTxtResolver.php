<?php

namespace App\Services;

/**
 * DNS TXT lookups are isolated for deterministic PostgreSQL acceptance tests.
 * This service never makes an HTTP request to a customer-provided host.
 */
class SiteDomainTxtResolver
{
    public function lookupTxt(string $name): array
    {
        $records = @dns_get_record($name, DNS_TXT);
        if (! is_array($records)) {
            return [];
        }
        $values = [];
        foreach ($records as $record) {
            if (isset($record['entries']) && is_array($record['entries'])) {
                $values[] = implode('', $record['entries']);
            } elseif (isset($record['txt']) && is_string($record['txt'])) {
                $values[] = $record['txt'];
            }
        }

        return $values;
    }
}
