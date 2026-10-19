-- Internal signed-evidence check receipt, NOT an identity-verification
-- decision, credential-enrollment grant, or authorization to activate.
-- One durable check per exact nonce/challenge prevents replay of the SAME
-- externally signed attestation. No raw assertions or provider secrets saved.
CREATE TABLE IF NOT EXISTS central_staff_enrollment_evidence_checks (
    id uuid PRIMARY KEY,
    challenge_id uuid NOT NULL UNIQUE REFERENCES central_staff_enrollment_challenges(id)
      ON DELETE RESTRICT,
    reviewed_by_staff_id uuid NOT NULL REFERENCES central_staff_users(id)
      ON DELETE RESTRICT,
    signature_sha256 text NOT NULL CHECK (signature_sha256 ~ '^[a-f0-9]{64}$'),
    decision text NOT NULL CHECK (decision='signature_checked_only'),
    recorded_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS central_staff_evidence_reviewer_idx
 ON central_staff_enrollment_evidence_checks(reviewed_by_staff_id,recorded_at DESC);
CREATE OR REPLACE FUNCTION reject_central_staff_enrollment_evidence_mutation()
RETURNS trigger LANGUAGE plpgsql AS $guard$
BEGIN
  RAISE EXCEPTION 'HAFZATECH staff enrollment evidence journal immutable'
    USING ERRCODE='23514';
END;
$guard$;
DROP TRIGGER IF EXISTS central_staff_evidence_immutable
 ON central_staff_enrollment_evidence_checks;
CREATE TRIGGER central_staff_evidence_immutable
 BEFORE UPDATE OR DELETE ON central_staff_enrollment_evidence_checks
 FOR EACH ROW EXECUTE FUNCTION reject_central_staff_enrollment_evidence_mutation();
