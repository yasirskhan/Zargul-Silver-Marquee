-- Opaque, vendor-only staff session records. Not a public authentication route.
CREATE TABLE IF NOT EXISTS central_staff_sessions (
    id uuid PRIMARY KEY,
    staff_id uuid NOT NULL REFERENCES central_staff_users(id) ON DELETE RESTRICT,
    token_hash bytea NOT NULL UNIQUE CHECK (octet_length(token_hash) = 32),
    authorization_snapshot text NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    expires_at timestamptz NOT NULL,
    idle_until timestamptz NOT NULL,
    revoked_at timestamptz,
    CHECK (expires_at > created_at AND idle_until > created_at)
);
CREATE INDEX IF NOT EXISTS central_staff_sessions_live_idx
 ON central_staff_sessions(staff_id, expires_at) WHERE revoked_at IS NULL;
