-- A challenge is NOT identity evidence, credential enrollment, or activation.
-- Dedicated vendor-only internal immutable challenge issue log. The approved
-- provider public key is pinned in operator wiring, never supplied by HTTP.
CREATE TABLE IF NOT EXISTS central_staff_enrollment_challenges (
    id uuid PRIMARY KEY,
    target_staff_id uuid NOT NULL REFERENCES central_staff_users(id) ON DELETE RESTRICT,
    issued_by_staff_id uuid NOT NULL REFERENCES central_staff_users(id) ON DELETE RESTRICT,
    provider_key_id text NOT NULL CHECK (provider_key_id ~ '^[A-Za-z0-9_-]{1,64}$'),
    email_sha256 text NOT NULL CHECK (email_sha256 ~ '^[a-f0-9]{64}$'),
    nonce_sha256 text NOT NULL CHECK (nonce_sha256 ~ '^[a-f0-9]{64}$'),
    issued_at timestamptz NOT NULL DEFAULT now(),
    expires_at timestamptz NOT NULL,
    CHECK (issued_by_staff_id <> target_staff_id),
    CHECK (expires_at > issued_at AND expires_at <= issued_at + interval '5 minutes'),
    UNIQUE (target_staff_id,nonce_sha256)
);
CREATE INDEX IF NOT EXISTS central_staff_enrollment_target_idx
 ON central_staff_enrollment_challenges(target_staff_id,expires_at DESC);

CREATE OR REPLACE FUNCTION reject_central_staff_enrollment_challenge_mutation()
RETURNS trigger LANGUAGE plpgsql AS $guard$
BEGIN
    RAISE EXCEPTION 'HAFZATECH staff enrollment challenges are immutable'
      USING ERRCODE='23514';
END;
$guard$;
DROP TRIGGER IF EXISTS central_staff_enrollment_challenge_immutable
 ON central_staff_enrollment_challenges;
CREATE TRIGGER central_staff_enrollment_challenge_immutable
 BEFORE UPDATE OR DELETE ON central_staff_enrollment_challenges
 FOR EACH ROW EXECUTE FUNCTION reject_central_staff_enrollment_challenge_mutation();
