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
        $expected=self::FIELDS;
        if (array_key_exists('designer_credit',$grant)) {
            $expected[]='designer_credit';
        }
        sort($expected);
        if ($fields !== $expected ||
            ($grant['api_version'] ?? null) !== 'v1' ||
            ($grant['issuer'] ?? null) !== 'HAFZATECH' ||
            ($grant['product'] ?? null) !== 'VenueDesk' ||
            !is_int($grant['revision'] ?? null) || $grant['revision'] < 1 ||
            !in_array($grant['term_months'] ?? null, [6,12], true)) {
            throw new InvalidArgumentException('Unapproved version or grant structure.');
        }
        if (array_key_exists('designer_credit',$grant)
            && !self::validCredit($grant['designer_credit'])) {
            throw new InvalidArgumentException('Unapproved designer credit claim.');
        }
        $raw=json_encode($grant,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        if (strlen($raw)>16384) throw new InvalidArgumentException('Oversized grant payload.');
        $sig=sodium_crypto_sign_detached(
            'HAFZATECH-LICENSE-GRANT-V1'."\n".$raw,$privateKey
        );
        return ['key_id'=>$keyId, 'payload'=>self::url($raw), 'signature'=>self::url($sig)];
    }

    private static function validCredit(mixed $credit): bool
    {
        if (!is_array($credit) || array_is_list($credit)) {
            return false;
        }
        $keys=array_keys($credit); sort($keys);
        if ($keys !== ['alignment','enabled','label','prefix','url'] ||
            !is_bool($credit['enabled'] ?? null) ||
            !is_string($credit['prefix'] ?? null) ||
            !is_string($credit['label'] ?? null) ||
            !is_string($credit['url'] ?? null) ||
            !in_array($credit['alignment'] ?? null, ['left','center','right'], true)) {
            return false;
        }
        foreach (['prefix','label','url'] as $field) {
            $max=$field==='url'?500:($field==='prefix'?40:120);
            if (preg_match('/[<>\x00-\x1F\x7F]/u',$credit[$field])===1 ||
                strlen($credit[$field])>$max) {
                return false;
            }
        }
        if (mb_strlen($credit['prefix'])>40 || mb_strlen($credit['label'])>120) {
            return false;
        }
        if ($credit['enabled']) {
            $parts=parse_url($credit['url']);
            $host=is_array($parts)?($parts['host']??''):'';
            if (trim($credit['label'])==='' || !is_array($parts) ||
                ($parts['scheme']??'')!=='https' ||
                isset($parts['user']) || isset($parts['pass']) ||
                !is_string($host) || strlen($host)>253 ||
                !str_contains($host,'.') || str_contains($host,'..') ||
                preg_match('/\A[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\z/D',$host)!==1 ||
                (isset($parts['port']) && ($parts['port']<1 || $parts['port']>65535))) {
                return false;
            }
        } elseif ($credit['prefix']!=='' || $credit['label']!=='' || $credit['url']!=='') {
            return false;
        }

        return true;
    }
    private static function url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes),'+/','-_'),'=');
    }
}
