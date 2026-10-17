<?php
declare(strict_types=1);

namespace Hafzatech\Signing;

use InvalidArgumentException;

/**
 * Cryptographic codec restricted to an eventual SEPARATE authorized signing
 * worker. Never require this from central/public, installer, staff UI or
 * customer runtime. Private signing keys must come from KMS/HSM or an
 * equivalently protected isolated signer, never committed configuration.
 *
 * This primitive DOES NOT prove settlement, staff authority or plan eligibility.
 * The future worker must verify independent paid invoice, exact installation
 * binding, monotonic license revision and dual-control where required BEFORE
 * calling this primitive.
 */
final class Ed25519GrantCodec
{
    private const FIELDS = [
        'api_version','approved_domains','customer_id','edition','expires_at',
        'features','installation_id','issuer','license_id','limits','not_before',
        'product','revision','term_months',
    ];

    /**
     * @param array<string,mixed> $grant
     * @return array{key_id:string,payload:string,signature:string}
     */
    public static function sign(array $grant, string $keyId, string $privateKey): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $keyId) ||
            strlen($privateKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new InvalidArgumentException('Key id and isolated signer credentials are invalid.');
        }
        $fields=array_keys($grant); sort($fields);
        $expected=self::FIELDS; sort($expected);
        if ($fields !== $expected ||
            ($grant['api_version'] ?? null) !== 'v1' ||
            ($grant['issuer'] ?? null) !== 'HAFZATECH' ||
            ($grant['product'] ?? null) !== 'VenueDesk' ||
            !is_int($grant['revision'] ?? null) || $grant['revision'] < 1 ||
            !in_array($grant['term_months'] ?? null, [6,12], true)) {
            throw new InvalidArgumentException('Unapproved version or grant structure.');
        }
        $raw=json_encode($grant,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        if (strlen($raw)>16384) throw new InvalidArgumentException('Oversized grant payload.');
        $sig=sodium_crypto_sign_detached(
            'HAFZATECH-LICENSE-GRANT-V1'."\n".$raw,$privateKey
        );
        return ['key_id'=>$keyId, 'payload'=>self::url($raw), 'signature'=>self::url($sig)];
    }

    private static function url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes),'+/','-_'),'=');
    }
}
