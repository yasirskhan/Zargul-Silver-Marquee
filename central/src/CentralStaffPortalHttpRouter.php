<?php
declare(strict_types=1);

namespace Hafzatech\Central;

/**
 * INTERNAL staff request composition for a future trusted operator controller.
 * This is NOT registered in central/public and defaults OFF independently of
 * installation API, boundary and billing-adapter release switches.
 *
 * The future HTTPS operator front controller MUST supply parsed host-only
 * Secure/HttpOnly cookies and server-validated HTTPS/Origin information.
 * Do not JSON-serialize the login result: it contains private cookie and
 * session-bound CSRF material for a trusted response writer only.
 */
final class CentralStaffPortalHttpRouter
{
    public function __construct(
        private readonly CentralStaffProtectedHttpBoundary $boundary,
        private readonly CentralStaffPaymentEvidenceHttpAdapter $billing,
        private readonly bool $enabled = false,
        private readonly ?CentralStaffLicenseSigningQueue $licenseQueue = null,
        private readonly ?CentralStaffCustomerLookup $customers = null,
        private readonly ?CentralStaffPortalDashboard $dashboard = null,
        private readonly ?CentralStaffSupportTicketQueue $supportQueue = null,
        private readonly ?CentralStaffPortalAuditHistoryPage $auditHistory = null,
        private readonly ?CentralStaffAccountDirectory $staffDirectory = null,
        private readonly ?CentralStaffRoleGrantDirectory $staffRoles = null,
        private readonly ?CentralStaffSessionSupportReply $supportReply = null,
        private readonly ?CentralStaffPortalOwnPassword $ownPassword = null,
        private readonly ?CentralStaffPortalTeamDashboard $teamDashboard = null,
        private readonly ?CentralStaffProvisioningService $provisioner = null,
        private readonly ?CentralStaffPortalAuditorDashboard $auditorHome = null,
        private readonly ?CentralStaffRoleReviewQueue $roleReviewQueue = null,
        private readonly ?CentralStaffRoleDecisionService $roleDecisions = null,
        private readonly ?CentralStaffRoleRequestService $roleRequestWriter = null,
        private readonly ?CentralStaffActivationReadiness $activationReview = null,
        private readonly ?CentralStaffVendorInvoiceIssuer $invoiceIssuer = null,
        private readonly ?CentralStaffVendorInvoiceDirectory $invoiceDirectory = null,
        private readonly ?CentralStaffVendorPlanPublisher $planPublisher = null,
        private readonly ?CentralStaffVendorPlanCatalogReader $planCatalog = null,
        private readonly ?CentralStaffPortalHome $staffHome = null
    ) {}

    /**
     * @param array<string,mixed> $server
     * @param array<string,mixed> $cookies Trusted server-parsed cookie bag only
     * @param array<string,mixed> $fields Trusted bounded decoded request fields
     * @return array<string,mixed> INTERNAL only, never serialize wholesale
     */
    public function dispatch(array $server,array $cookies,array $fields,
        ?string $csrfHeader): array
    {
        if (!$this->enabled) {
            throw new RegistrationError('staff_portal_not_enabled',503);
        }
        $uri=$server['REQUEST_URI']??null;
        if (($server['REQUEST_METHOD']??null)!=='POST' ||
            !CentralStaffHttpPolicy::staffMutationAllowed($server) ||
            !is_string($uri) || strlen($uri)>256 ||
            !str_starts_with($uri,'/staff/') ||
            str_contains($uri,'?') || str_contains($uri,'#')) {
            throw new RegistrationError('staff_portal_denied');
        }

        if ($uri==='/staff/session/login') {
            if (!$this->exactFields($fields,['email','password','totp']) ||
                !is_string($fields['email']) ||
                !is_string($fields['password']) ||
                !is_string($fields['totp']) ||
                strlen($fields['email'])>254 ||
                strlen($fields['password'])>1024 ||
                preg_match('/^[0-9]{6}$/D',$fields['totp'])!==1) {
                throw new RegistrationError('staff_login_denied');
            }
            $credentials=$this->boundary->authenticate(
                $server,$fields['email'],$fields['password'],$fields['totp']);
            if ($credentials===null) {
                throw new RegistrationError('staff_login_denied');
            }
            // Never send this array as JSON; only the trusted response writer
            // may set the host-only cookie and furnish protected CSRF material.
            return ['kind'=>'internal_login','credentials'=>$credentials];
        }

        $bearer=$cookies[CentralStaffHttpPolicy::COOKIE_NAME]??null;
        if (!is_string($bearer) ||
            preg_match('/^[a-f0-9]{64}$/D',$bearer)!==1 ||
            !is_string($csrfHeader) ||
            preg_match('/^[a-f0-9]{64}$/D',$csrfHeader)!==1) {
            throw new RegistrationError('staff_portal_denied');
        }
        if ($uri==='/staff/session/logout') {
            if ($fields!==[] ||
                !$this->boundary->signOut($server,$bearer,$csrfHeader)) {
                throw new RegistrationError('staff_portal_denied');
            }
            return [
                'kind'=>'internal_logout',
                'clear_cookie_name'=>CentralStaffHttpPolicy::COOKIE_NAME,
                'clear_cookie_options'=>array_replace(
                    CentralStaffHttpPolicy::cookieOptions(time()),['expires'=>1])
            ];
        }
        if($uri==='/staff/home' && $this->staffHome!==null) {
            if($fields!==[]) {
                throw new RegistrationError('invalid_staff_home_request',422);
            }
            if(!$this->boundary->permitsOwnAccountMutation(
                    $server,$bearer,$csrfHeader)) {
                throw new RegistrationError('staff_portal_denied');
            }
            return ['kind'=>'private_staff_home_html',
                'html'=>$this->staffHome->render($bearer,$csrfHeader)];
        }
        if ($uri==='/staff/billing/invoices/issue' && $this->invoiceIssuer!==null) {
            if (!$this->exactFields($fields,
                    ['customer_id','plan_version_id','receiving_account_id','request_id']) ||
                !is_string($fields['customer_id']??null) ||
                !is_string($fields['plan_version_id']??null) ||
                !is_string($fields['receiving_account_id']??null) ||
                !is_string($fields['request_id']??null) ||
                !self::uuid($fields['customer_id']) ||
                !self::uuid($fields['plan_version_id']) ||
                !self::uuid($fields['receiving_account_id']) ||
                !self::uuid($fields['request_id'])) {
                throw new RegistrationError('invalid_vendor_invoice_request',422);
            }
            if (!$this->boundary->permitsMutation($server,$bearer,$csrfHeader,
                    'billing.invoice.issue',$fields['customer_id'])) {
                throw new RegistrationError('staff_portal_denied');
            }
            $issued=$this->invoiceIssuer->issue($bearer,
                $fields['customer_id'],$fields['plan_version_id'],
                $fields['receiving_account_id'],$fields['request_id']);
            return ['kind'=>'private_vendor_invoice_issued_html',
                'html'=>CentralStaffVendorInvoiceReceiptRenderer::render($issued)];
        }
        if($uri==='/staff/billing/invoices/recent' &&
           $this->invoiceDirectory!==null) {
            if(!$this->exactFields($fields,['customer_id']) ||
               !is_string($fields['customer_id']??null) ||
               !self::uuid($fields['customer_id'])) {
                throw new RegistrationError('invalid_invoice_directory_request',422);
            }
            if(!$this->boundary->permitsMutation($server,$bearer,$csrfHeader,
                    'billing.invoice.read',$fields['customer_id'])) {
                throw new RegistrationError('staff_portal_denied');
            }
            $rows=$this->invoiceDirectory->listForCustomer(
                $bearer,$fields['customer_id'],25);
            return ['kind'=>'private_vendor_invoice_directory_html',
                'html'=>CentralStaffVendorInvoiceDirectoryRenderer::render(
                    $rows,$fields['customer_id'],$csrfHeader)];
        }

        if($uri==='/staff/plans' && $this->planPublisher!==null) {
            if($fields!==[]) {
                throw new RegistrationError('invalid_vendor_plan_request',422);
            }
            if(!$this->boundary->permitsMutation(
                    $server,$bearer,$csrfHeader,'plan.version.create',null)) {
                throw new RegistrationError('staff_portal_denied');
            }
            return ['kind'=>'private_vendor_plan_catalog_html',
                'html'=>CentralStaffVendorPlanCatalogRenderer::form(
                    $csrfHeader,$this->planCatalog?->recent($bearer,25)??[])];
        }
        if($uri==='/staff/plans/publish' && $this->planPublisher!==null) {
            if(!$this->exactFields($fields,[
                    'request_id','plan_code','term_months','currency',
                    'price_minor','max_halls','max_staff','features_json']) ||
                !is_string($fields['request_id']??null) ||
                !is_string($fields['plan_code']??null) ||
                !is_string($fields['term_months']??null) ||
                !is_string($fields['currency']??null) ||
                !is_string($fields['price_minor']??null) ||
                !is_string($fields['max_halls']??null) ||
                !is_string($fields['max_staff']??null) ||
                !is_string($fields['features_json']??null) ||
                !self::uuid($fields['request_id']) ||
                !in_array($fields['term_months'],['6','12'],true) ||
                preg_match('/^[1-9][0-9]{0,5}$/D',$fields['max_halls'])!==1 ||
                preg_match('/^[1-9][0-9]{0,5}$/D',$fields['max_staff'])!==1 ||
                strlen($fields['features_json'])>2048) {
                throw new RegistrationError('invalid_vendor_plan_request',422);
            }
            try{
                $features=json_decode($fields['features_json'],
                    true,16,JSON_THROW_ON_ERROR);
            }catch(\JsonException){
                throw new RegistrationError('invalid_vendor_plan_request',422);
            }
            if(!is_array($features)) {
                throw new RegistrationError('invalid_vendor_plan_request',422);
            }
            if(!$this->boundary->permitsMutation($server,$bearer,$csrfHeader,
                    'plan.version.create',null)) {
                throw new RegistrationError('staff_portal_denied');
            }
            $result=$this->planPublisher->publish(
                $bearer,$fields['request_id'],$fields['plan_code'],
                (int)$fields['term_months'],$fields['currency'],
                $fields['price_minor'],(int)$fields['max_halls'],
                (int)$fields['max_staff'],$features);
            return ['kind'=>'private_vendor_plan_published_html',
                'html'=>CentralStaffVendorPlanCatalogRenderer::receipt($result,$csrfHeader)];
        }
        if ($uri==='/staff/billing/overview') {
            if (!$this->exactFields($fields,['customer_id','invoice_id']) ||
                !is_string($fields['customer_id']) ||
                !is_string($fields['invoice_id']) ||
                !self::uuid($fields['customer_id']) ||
                !self::uuid($fields['invoice_id'])) {
                throw new RegistrationError('invalid_billing_request',422);
            }
            $html=$this->billing->billingDashboard(
                $server,$bearer,$csrfHeader,
                $fields['customer_id'],$fields['invoice_id']);
            return [
                'kind'=>'private_html',
                'headers'=>[
                    'Content-Type'=>'text/html; charset=utf-8',
                    'Cache-Control'=>'no-store, private',
                    'X-Content-Type-Options'=>'nosniff',
                    'Referrer-Policy'=>'no-referrer',
                    'Content-Security-Policy'=>
                        "default-src 'none'; base-uri 'none'; frame-ancestors 'none'"
                ],
                'html'=>$html
            ];
        }
        if ($uri==='/staff/licenses/pending' && $this->licenseQueue!==null) {
            if (!$this->exactFields($fields,['customer_id']) ||
                !is_string($fields['customer_id']) ||
                !self::uuid($fields['customer_id'])) {
                throw new RegistrationError('invalid_license_request',422);
            }
            if (!$this->boundary->permitsMutation(
                $server,$bearer,$csrfHeader,'license.read_status',
                $fields['customer_id'])) {
                throw new RegistrationError('staff_portal_denied');
            }
            $rows=$this->licenseQueue->listPending(
                $bearer,$fields['customer_id'],0,25);
            return [
                'kind'=>'private_license_html',
                'html'=>CentralStaffLicenseQueueHtmlRenderer::render($rows)
            ];
        }
        if ($uri==='/staff/customers/lookup' && $this->customers!==null) {
            if (!$this->exactFields($fields,['customer_id']) ||
                !is_string($fields['customer_id']) ||
                !self::uuid($fields['customer_id'])) {
                throw new RegistrationError('invalid_customer_lookup',422);
            }
            $customerId=$fields['customer_id'];
            if (!$this->boundary->permitsMutation(
                    $server,$bearer,$csrfHeader,'license.read_status',$customerId) &&
                !$this->boundary->permitsMutation(
                    $server,$bearer,$csrfHeader,'billing.invoice.read',$customerId) &&
                !$this->boundary->permitsMutation(
                    $server,$bearer,$csrfHeader,'support.ticket.read',$customerId)) {
                throw new RegistrationError('staff_portal_denied');
            }
            $record=$this->customers->find($bearer,$customerId);
            if ($record===null) {
                throw new RegistrationError('customer_not_found');
            }
            return ['kind'=>'private_customer_html',
                'html'=>CentralStaffCustomerLookup::html($record)];
        }
        if ($uri==='/staff/dashboard' && $this->dashboard!==null) {
            if (!$this->exactFields($fields,['customer_id']) ||
                !is_string($fields['customer_id']) ||
                !self::uuid($fields['customer_id'])) {
                throw new RegistrationError('invalid_customer_lookup',422);
            }
            $customerId=$fields['customer_id'];
            if (!$this->boundary->permitsMutation(
                    $server,$bearer,$csrfHeader,'license.read_status',$customerId) &&
                !$this->boundary->permitsMutation(
                    $server,$bearer,$csrfHeader,'billing.invoice.read',$customerId) &&
                !$this->boundary->permitsMutation(
                    $server,$bearer,$csrfHeader,'support.ticket.read',$customerId)) {
                throw new RegistrationError('staff_portal_denied');
            }
            return ['kind'=>'private_dashboard_html',
                'html'=>$this->dashboard->render($bearer,$customerId,$csrfHeader)];
        }
        if ($uri==='/staff/support/tickets' && $this->supportQueue!==null) {
            if (!$this->exactFields($fields,['customer_id']) ||
                !is_string($fields['customer_id']) ||
                !self::uuid($fields['customer_id'])) {
                throw new RegistrationError('invalid_support_queue_request',422);
            }
            if (!$this->boundary->permitsMutation(
                    $server,$bearer,$csrfHeader,'support.ticket.read',
                    $fields['customer_id'])) {
                throw new RegistrationError('staff_portal_denied');
            }
            $rows=$this->supportQueue->listForCustomer(
                $bearer,$fields['customer_id'],0,25);
            return ['kind'=>'private_support_html',
                'html'=>CentralStaffSupportQueueHtmlRenderer::render($rows)];
        }
        if ($uri==='/staff/audit/staff' && $this->auditHistory!==null) {
            if (!$this->exactFields($fields,['subject_staff_id']) ||
                !is_string($fields['subject_staff_id']) ||
                !self::uuid($fields['subject_staff_id'])) {
                throw new RegistrationError('invalid_staff_audit_request',422);
            }
            if (!$this->boundary->permitsMutation(
                $server,$bearer,$csrfHeader,'audit.read',null)) {
                throw new RegistrationError('staff_portal_denied');
            }
            return ['kind'=>'private_audit_html',
                'html'=>$this->auditHistory->render(
                    $bearer,$fields['subject_staff_id'])];
        }
        if ($uri==='/staff/team/accounts' && $this->staffDirectory!==null) {
            if($fields!==[]) {
                throw new RegistrationError('invalid_staff_directory_request',422);
            }
            if(!$this->boundary->permitsMutation(
                $server,$bearer,$csrfHeader,'staff.roles.assign',null)) {
                throw new RegistrationError('staff_portal_denied');
            }
            return ['kind'=>'private_staff_directory_html',
                'html'=>CentralStaffAccountDirectory::html(
                    $this->staffDirectory->listStaff($bearer,0,25))];
        }
        if ($uri==='/staff/team/roles' && $this->staffRoles!==null) {
            if (!$this->exactFields($fields,['subject_staff_id']) ||
                !is_string($fields['subject_staff_id']) ||
                !self::uuid($fields['subject_staff_id'])) {
                throw new RegistrationError('invalid_staff_roles_request',422);
            }
            if (!$this->boundary->permitsMutation(
                $server,$bearer,$csrfHeader,'staff.roles.assign',null)) {
                throw new RegistrationError('staff_portal_denied');
            }
            return ['kind'=>'private_staff_roles_html',
                'html'=>CentralStaffRoleGrantDirectory::html(
                    $this->staffRoles->listForStaff(
                        $bearer,$fields['subject_staff_id']))];
        }
        if ($uri==='/staff/support/reply' && $this->supportReply!==null) {
            if(!$this->exactFields($fields,
                    ['customer_id','ticket_id','message_id','body']) ||
                !is_string($fields['customer_id']) ||
                !is_string($fields['ticket_id']) ||
                !is_string($fields['message_id']) ||
                !is_string($fields['body']) ||
                !self::uuid($fields['customer_id']) ||
                !self::uuid($fields['ticket_id']) ||
                !self::uuid($fields['message_id'])) {
                throw new RegistrationError('invalid_support_reply_request',422);
            }
            if(!$this->boundary->permitsMutation($server,$bearer,$csrfHeader,
                'support.reply',$fields['customer_id'])) {
                throw new RegistrationError('staff_portal_denied');
            }
            $receipt=$this->supportReply->reply(
                $bearer,$fields['customer_id'],$fields['ticket_id'],
                $fields['message_id'],$fields['body']);
            return ['kind'=>'private_support_reply_html',
                'html'=>CentralStaffSessionSupportReply::html($receipt)];
        }
        if ($uri==='/staff/account/password/change' &&
            $this->ownPassword!==null) {
            if(!$this->exactFields($fields,['current_password','new_password']) ||
                !is_string($fields['current_password']) ||
                !is_string($fields['new_password']) ||
                !$this->boundary->permitsOwnAccountMutation(
                    $server,$bearer,$csrfHeader)) {
                throw new RegistrationError('staff_portal_denied');
            }
            $this->ownPassword->change(
                $bearer,$fields['current_password'],$fields['new_password']);
            // Core password change revokes this AND all other staff sessions.
            // Emit the existing strictly host-only cookie clear response.
            return ['kind'=>'internal_logout',
                'clear_cookie_name'=>CentralStaffHttpPolicy::COOKIE_NAME,
                'clear_cookie_options'=>array_replace(
                    CentralStaffHttpPolicy::cookieOptions(time()),['expires'=>1])
            ];
        }
        if ($uri==='/staff/team/dashboard' && $this->teamDashboard!==null) {
            if($fields!==[]) {
                throw new RegistrationError('invalid_staff_dashboard',422);
            }
            if(!$this->boundary->permitsMutation(
                $server,$bearer,$csrfHeader,'staff.roles.assign',null)) {
                throw new RegistrationError('staff_portal_denied');
            }
            return ['kind'=>'private_team_dashboard_html',
                'html'=>$this->teamDashboard->render($bearer,$csrfHeader)];
        }
        if ($uri==='/staff/team/activation-review' &&
            $this->activationReview!==null) {
            if (!$this->exactFields($fields,['target_staff_id']) ||
                !is_string($fields['target_staff_id']) ||
                !self::uuid($fields['target_staff_id'])) {
                throw new RegistrationError('invalid_staff_activation_review',422);
            }
            if (!$this->boundary->permitsMutation(
                $server,$bearer,$csrfHeader,'staff.create',null)) {
                throw new RegistrationError('staff_portal_denied');
            }
            $record=$this->activationReview->inspect(
                $bearer,$fields['target_staff_id']);
            return ['kind'=>'private_staff_activation_readiness_html',
                'html'=>CentralStaffActivationReadiness::html($record)];
        }
        if ($uri==='/staff/team/create-pending' &&
            $this->provisioner!==null) {
            if(!$this->exactFields($fields,
                ['email','display_name','reason']) ||
                !is_string($fields['email']) ||
                !is_string($fields['display_name']) ||
                !is_string($fields['reason'])) {
                throw new RegistrationError('invalid_staff_provision_request',422);
            }
            if(!$this->boundary->permitsMutation(
                $server,$bearer,$csrfHeader,'staff.create',null)) {
                throw new RegistrationError('staff_portal_denied');
            }
            $record=$this->provisioner->createPending(
                $bearer,$fields['email'],$fields['display_name'],
                $fields['reason']);
            return ['kind'=>'private_staff_provisioned_html',
                'html'=>CentralStaffProvisioningService::html($record)];
        }
        if($uri==='/staff/audit/dashboard' && $this->auditorHome!==null) {
            if($fields!==[]) {
                throw new RegistrationError('invalid_staff_auditor_dashboard',422);
            }
            if(!$this->boundary->permitsMutation(
                $server,$bearer,$csrfHeader,'audit.read',null)) {
                throw new RegistrationError('staff_portal_denied');
            }
            return ['kind'=>'private_auditor_home_html',
                'html'=>$this->auditorHome->render($bearer,$csrfHeader)];
        }
        if($uri==='/staff/team/role-requests' &&
            $this->roleReviewQueue!==null) {
            if($fields!==[]) {
                throw new RegistrationError('invalid_staff_review_queue',422);
            }
            if(!$this->boundary->permitsMutation(
                $server,$bearer,$csrfHeader,'staff.roles.assign',null)) {
                throw new RegistrationError('staff_portal_denied');
            }
            return ['kind'=>'private_role_review_html',
                'html'=>CentralStaffRoleReviewQueue::html(
                    $this->roleReviewQueue->listPending($bearer,25),$csrfHeader)];
        }
        if($uri==='/staff/team/role-decisions' &&
            $this->roleDecisions!==null) {
            if(!$this->exactFields($fields,
                    ['request_id','decision_id','outcome','reason']) ||
                !is_string($fields['request_id']) ||
                !is_string($fields['decision_id']) ||
                !is_string($fields['outcome']) ||
                !is_string($fields['reason']) ||
                !self::uuid($fields['request_id']) ||
                !self::uuid($fields['decision_id'])) {
                throw new RegistrationError('invalid_staff_role_decision',422);
            }
            if(!$this->boundary->permitsMutation(
                $server,$bearer,$csrfHeader,'staff.roles.assign',null)) {
                throw new RegistrationError('staff_portal_denied');
            }
            $decision=$this->roleDecisions->decide(
                $bearer,$fields['request_id'],$fields['decision_id'],
                $fields['outcome'],$fields['reason']);
            return ['kind'=>'private_staff_role_decision_html',
                'html'=>CentralStaffRoleDecisionService::html($decision)];
        }
        if($uri==='/staff/team/role-request' &&
            $this->roleRequestWriter!==null) {
            if(!$this->exactFields($fields,[
                'request_id','target_staff_id','role',
                'customer_scope_id','reason']) ||
                !is_string($fields['request_id']) ||
                !is_string($fields['target_staff_id']) ||
                !is_string($fields['role']) ||
                !is_string($fields['customer_scope_id']) ||
                !is_string($fields['reason']) ||
                !self::uuid($fields['request_id']) ||
                !self::uuid($fields['target_staff_id']) ||
                ($fields['customer_scope_id']!=='' &&
                    !self::uuid($fields['customer_scope_id']))) {
                throw new RegistrationError('invalid_staff_role_request',422);
            }
            if(!$this->boundary->permitsMutation(
                $server,$bearer,$csrfHeader,'staff.roles.assign',null)) {
                throw new RegistrationError('staff_portal_denied');
            }
            $result=$this->roleRequestWriter->request(
                $bearer,$fields['request_id'],$fields['target_staff_id'],
                $fields['role'],$fields['customer_scope_id']===''
                    ?null:$fields['customer_scope_id'],$fields['reason']);
            return ['kind'=>'private_role_request_receipt_html',
                'html'=>CentralStaffRoleRequestService::html($result)];
        }
        throw new RegistrationError('staff_route_not_found',404);
    }

    /** @param array<string,mixed> $fields
     *  @param array<int,string> $names
     */
    private function exactFields(array $fields,array $names): bool
    {
        $actual=array_keys($fields);
        sort($actual,SORT_STRING);
        sort($names,SORT_STRING);
        return $actual===$names;
    }

    private static function uuid(string $id): bool
    {
        return preg_match(
            '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',
            $id)===1;
    }
}
