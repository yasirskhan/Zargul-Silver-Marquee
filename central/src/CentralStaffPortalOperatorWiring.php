<?php
declare(strict_types=1);

namespace Hafzatech\Central;

use Closure;
use PDO;

/**
 * INTERNAL operator-only DI factory. Secrets supplied by an APPROVED external
 * vault/KMS runtime, never by the customer application or this repository.
 * No public PHP entrypoint, routes or signing private keys are installed here.
 */
final class CentralStaffPortalOperatorWiring
{
    /**
     * @param Closure(string):string $decryptStaffTotp
     * @param Closure(string):string $encryptSupportReply
     * @param Closure(string):void $writeHeader
     * @param Closure(string,string,array):bool $writeCookie
     * @param Closure(string):void $writeBody
     * @param Closure():bool $headersSent
     */
    public static function build(
        PDO $db,string $throttleHmacKey,
        Closure $decryptStaffTotp,Closure $encryptSupportReply,
        Closure $writeHeader,Closure $writeCookie,
        Closure $writeBody,Closure $headersSent,
        bool $operatorApproved=false
    ):CentralStaffPortalOperatorApp
    {
        if(!$operatorApproved ||
            getenv('CENTRAL_STAFF_BOOTSTRAP_ENABLED')!=='true') {
            throw new RegistrationError('staff_bootstrap_not_enabled',503);
        }
        if(strlen($throttleHmacKey)<32 ||
            $db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='pgsql' ||
            !str_starts_with((string)$db->query(
                'SELECT current_database()')->fetchColumn(),'hafzatech_central')) {
            throw new RegistrationError('staff_operator_configuration_denied',503);
        }
        // The factory never generates a real staff identity or signing key,
        // decrypts passwords, or trusts a posted MFA Boolean.
        $passwords=new CentralStaffPasswordVerifier($db);
        $totp=new CentralStaffMfaChallenge($db,$decryptStaffTotp);
        $credentials=new CentralStaffCredentialVerifier($passwords,$totp);
        $sessions=new CentralStaffSessionService($db,$credentials,
            new CentralStaffLoginRateLimiter($db,$throttleHmacKey));
        $access=new CentralStaffAccess($db);
        $auth=new CentralStaffSessionAuthorization($sessions,$access);
        $csrf=new CentralStaffCsrfTokenService($db,$sessions);
        $boundary=new CentralStaffProtectedHttpBoundary(
            $sessions,$csrf,$auth,true);
        $billing=new CentralStaffPaymentEvidenceHttpAdapter(
            $db,$boundary,
            new CentralStaffPaymentEvidenceQueue($db,$auth),
            new CentralStaffPaymentEvidenceReviewService($db,$sessions,$auth),
            new CentralStaffInvoicePaymentOverview($db,$sessions,$auth),true);
        $customers=new CentralStaffCustomerLookup($db,$auth);
        $invoiceIssuer=new CentralStaffVendorInvoiceIssuer($db,$sessions,$auth,true);
        $dashboard=new CentralStaffPortalDashboard($auth,$customers,$invoiceIssuer);
        $support=new CentralStaffSupportTicketQueue($db,$auth);
        $audit=new CentralStaffPortalAuditHistoryPage(
            new CentralStaffSessionAuditReader($db,$auth),
            new CentralStaffAccountActionReader($db,$auth),
            new CentralStaffProvisionAuditReader($db,$auth));
        $reply=new CentralStaffSessionSupportReply($db,$sessions,$auth,
            new SupportAgentReplyService($db,$access,$encryptSupportReply),true);
        $router=new CentralStaffPortalHttpRouter(
            $boundary,$billing,true,
            new CentralStaffLicenseSigningQueue($db,$auth),
            $customers,$dashboard,$support,$audit,
            new CentralStaffAccountDirectory($db,$auth),
            new CentralStaffRoleGrantDirectory($db,$auth),
            $reply,
            new CentralStaffPortalOwnPassword(
                new CentralStaffOwnPasswordChange($db,$sessions),true),
            new CentralStaffPortalTeamDashboard($auth),
            new CentralStaffProvisioningService(
                $db,$sessions,$access,true),
            new CentralStaffPortalAuditorDashboard($auth),
            new CentralStaffRoleReviewQueue($db,$sessions,$auth),
            new CentralStaffRoleDecisionService($db,$sessions,$access,true),
            new CentralStaffRoleRequestService($db,$sessions,$access,true),
            new CentralStaffActivationReadiness($db,$sessions,$access,true),
            $invoiceIssuer);
        return new CentralStaffPortalOperatorApp(
            new CentralStaffPortalOperatorKernel(
                $router,new CentralStaffPortalPrivateResponse(true),true),
            new CentralStaffPortalResponseEmitter(
                $writeHeader,$writeCookie,$writeBody,$headersSent,true));
    }
}
