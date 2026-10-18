<?php
declare(strict_types=1);

namespace Hafzatech\Central;

/**
 * Strict, bounded form decoder for a future vendor-only HTTPS front
 * controller. Never use parse_str(): duplicate keys and bracket parameters
 * could silently replace security-critical customer/CSRF fields.
 * This class does NOT read php://input or register public routes.
 */
final class CentralStaffPortalFormRequest
{
    /** @return array<string,string> */
    public static function decode(array $server,string $raw): array
    {
        if (($server['REQUEST_METHOD']??null)!=='POST' ||
            !CentralStaffHttpPolicy::staffMutationAllowed($server)) {
            throw new RegistrationError('staff_form_denied');
        }
        $type=strtolower(trim((string)($server['CONTENT_TYPE']??'')));
        if (!in_array($type,[
                'application/x-www-form-urlencoded',
                'application/x-www-form-urlencoded; charset=utf-8'
            ],true)) {
            throw new RegistrationError('staff_form_required',415);
        }
        if (strlen($raw)>4096) {
            throw new RegistrationError('staff_form_too_large',413);
        }
        if ($raw==='') return [];
        $parts=explode('&',$raw);
        if(count($parts)>8) {
            throw new RegistrationError('invalid_staff_form',422);
        }
        $fields=[];
        foreach($parts as $part) {
            if($part==='' || !str_contains($part,'=')) {
                throw new RegistrationError('invalid_staff_form',422);
            }
            [$key,$value]=explode('=',$part,2);
            $key=urldecode($key);
            $value=urldecode($value);
            if(preg_match('/^[a-z_]{1,32}$/D',$key)!==1 ||
               array_key_exists($key,$fields) ||
               strlen($value)>1024 ||
               str_contains($value,"\0")) {
                throw new RegistrationError('invalid_staff_form',422);
            }
            $fields[$key]=$value;
        }
        return $fields;
    }
}
