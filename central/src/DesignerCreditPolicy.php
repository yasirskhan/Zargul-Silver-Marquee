<?php
declare(strict_types=1);

namespace Hafzatech\Central;

/** Strict server-side contract for the centrally signed designer credit. */
final class DesignerCreditPolicy
{
    /** @return array{enabled:bool,prefix:string,label:string,url:string,alignment:string} */
    public static function validate(mixed $credit): array
    {
        if (!is_array($credit) || array_is_list($credit)) {
            throw new RegistrationError('invalid_designer_credit',422);
        }
        $keys=array_keys($credit); sort($keys);
        if ($keys!==['alignment','enabled','label','prefix','url'] ||
            !is_bool($credit['enabled']??null) ||
            !is_string($credit['prefix']??null) ||
            !is_string($credit['label']??null) ||
            !is_string($credit['url']??null) ||
            !in_array($credit['alignment']??null,['left','center','right'],true)) {
            throw new RegistrationError('invalid_designer_credit',422);
        }
        foreach (['prefix','label','url'] as $field) {
            if (preg_match('/[<>\x00-\x1F\x7F]/u',$credit[$field])===1 ||
                strlen($credit[$field])>($field==='url'?500:($field==='prefix'?40:120))) {
                throw new RegistrationError('invalid_designer_credit',422);
            }
        }
        if (mb_strlen($credit['prefix'])>40 || mb_strlen($credit['label'])>120) {
            throw new RegistrationError('invalid_designer_credit',422);
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
                throw new RegistrationError('invalid_designer_credit',422);
            }
        } elseif ($credit['prefix']!=='' || $credit['label']!=='' || $credit['url']!=='') {
            throw new RegistrationError('invalid_designer_credit',422);
        }
        return ['enabled'=>$credit['enabled'],'prefix'=>$credit['prefix'],
            'label'=>$credit['label'],'url'=>$credit['url'],
            'alignment'=>$credit['alignment']];
    }
}