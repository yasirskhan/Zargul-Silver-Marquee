<?php
declare(strict_types=1);
namespace Hafzatech\Central;

/** Safe renewal preparation receipt; never a signed license or activation. */
final class CentralStaffLicensePreparationRenderer
{
    public static function receipt(array $result):string
    {
        $state=(string)($result['state']??'');
        $revision=$result['revision']??null;
        $id=(string)($result['revision_id']??'');
        if($state!=='awaiting_isolated_signer' || !is_int($revision) || $revision<1 ||
            preg_match('/^[a-f0-9-]{36}$/D',$id)!==1) {
            throw new RegistrationError('invalid_license_preparation_receipt');
        }
        return '<section aria-label="Private HAFZATECH license preparation receipt">'
            .'<h2>Paid license renewal prepared</h2>'
            .'<p>Revision '.htmlspecialchars((string)$revision,ENT_QUOTES)
            .' is awaiting the isolated signer. No license was signed, delivered or activated.</p>'
            .'</section>';
    }
}