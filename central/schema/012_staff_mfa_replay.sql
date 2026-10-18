-- Durable, independent HAFZATECH staff MFA replay + per-account lockout state.
-- Not a login/session store; private TOTP secret remains in protected staff
-- identity row and must only be decrypted in the trusted vendor auth process.
CREATE TABLE IF NOT EXISTS central_staff_mfa_replay_state (
    staff_id uuid PRIMARY KEY REFERENCES central_staff_users(id) ON DELETE RESTRICT,
    last_accepted_counter bigint NOT NULL DEFAULT -1
        CHECK (last_accepted_counter >= -1),
    failed_attempts integer NOT NULL DEFAULT 0
        CHECK (failed_attempts BETWEEN 0 AND 5),
    locked_until timestamptz,
    updated_at timestamptz NOT NULL DEFAULT now()
);
