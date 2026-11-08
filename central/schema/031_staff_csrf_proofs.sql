-- Multiple bounded CSRF proofs per live session. Page navigation issues a new
-- proof without invalidating forms in other open tabs. Every proof remains
-- bound to the same server-side session and expires/revokes with it.
CREATE TABLE IF NOT EXISTS central_staff_csrf_proofs (
    session_id uuid NOT NULL REFERENCES central_staff_sessions(id) ON DELETE CASCADE,
    proof_hash bytea NOT NULL CHECK (octet_length(proof_hash)=32),
    created_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (session_id, proof_hash)
);
CREATE INDEX IF NOT EXISTS central_staff_csrf_proofs_created_idx
 ON central_staff_csrf_proofs (session_id, created_at DESC);