-- Separate vendor staff identities. Never share VenueDesk users, sessions, roles or MFA secrets.
CREATE TABLE IF NOT EXISTS central_staff_users (
    id uuid PRIMARY KEY,
    email text NOT NULL CHECK (length(email) BETWEEN 5 AND 254 AND email = lower(email)),
    display_name text NOT NULL CHECK (length(trim(display_name)) > 0),
    password_hash text NOT NULL CHECK (length(password_hash) >= 60),
    mfa_secret_ciphertext bytea,
    mfa_enrolled_at timestamptz,
    status text NOT NULL DEFAULT 'pending_mfa'
        CHECK (status IN ('pending_mfa', 'active', 'suspended', 'disabled')),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX IF NOT EXISTS central_staff_unique_email ON central_staff_users(email);
CREATE TABLE IF NOT EXISTS central_staff_role_grants (
    id uuid PRIMARY KEY,
    staff_id uuid NOT NULL REFERENCES central_staff_users(id) ON DELETE RESTRICT,
    role text NOT NULL CHECK (role IN (
        'super_administrator', 'staff_administrator', 'billing_officer',
        'license_officer', 'license_supervisor', 'plan_administrator',
        'support_agent', 'support_supervisor', 'auditor'
    )),
    customer_scope_id uuid REFERENCES vendor_customers(id) ON DELETE RESTRICT,
    granted_by uuid REFERENCES central_staff_users(id) ON DELETE RESTRICT,
    approved_by uuid REFERENCES central_staff_users(id) ON DELETE RESTRICT,
    reason text NOT NULL CHECK (length(trim(reason)) >= 5),
    created_at timestamptz NOT NULL DEFAULT now(),
    revoked_at timestamptz,
    CONSTRAINT central_staff_no_self_role_grant CHECK (granted_by IS NULL OR granted_by <> staff_id),
    CONSTRAINT central_staff_independent_grant_approval
        CHECK (approved_by IS NULL OR approved_by <> granted_by),
    CONSTRAINT central_staff_grant_unique UNIQUE NULLS NOT DISTINCT (staff_id, role, customer_scope_id, revoked_at)
);
CREATE INDEX IF NOT EXISTS central_staff_grants_active_idx
    ON central_staff_role_grants(staff_id, role) WHERE revoked_at IS NULL;
CREATE TABLE IF NOT EXISTS central_role_capabilities (
    role text NOT NULL,
    capability text NOT NULL,
    PRIMARY KEY (role, capability)
);
-- Fixed least-privilege role template for this release.
INSERT INTO central_role_capabilities(role, capability) VALUES
('super_administrator', 'staff.recovery'), ('super_administrator', 'staff.roles.assign'),
('super_administrator', 'security.exceptions.review'), ('super_administrator', 'audit.read'),
('staff_administrator', 'staff.create'), ('staff_administrator', 'staff.disable'),
('staff_administrator', 'staff.password.reset'), ('staff_administrator', 'staff.roles.assign'),
('billing_officer', 'billing.invoice.issue'), ('billing_officer', 'billing.payment.verify'),
('billing_officer', 'billing.provider.read'), ('billing_officer', 'billing.invoice.read'),
('license_officer', 'license.read_status'), ('license_officer', 'license.issue_paid'),
('license_officer', 'license.renew_paid'),
('license_supervisor', 'license.read_status'), ('license_supervisor', 'license.grace.approve'),
('license_supervisor', 'license.revoke.approve'), ('license_supervisor', 'license.transfer.approve'),
('plan_administrator', 'plan.version.create'), ('plan_administrator', 'plan.read'),
('support_agent', 'support.ticket.read'), ('support_agent', 'support.reply'),
('support_supervisor', 'support.ticket.read'), ('support_supervisor', 'support.reply'),
('support_supervisor', 'support.assign'), ('support_supervisor', 'support.knowledge.approve'),
('auditor', 'audit.read')
ON CONFLICT (role, capability) DO NOTHING;
