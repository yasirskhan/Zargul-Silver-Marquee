-- Independent central pre-auth rate limit counters. HMACs are keyed with an
-- operator-protected secret, not plain email addresses or network addresses.
-- No public login route is exposed by this table.
CREATE TABLE IF NOT EXISTS central_staff_login_rate_limits (
    key_hash bytea PRIMARY KEY CHECK (octet_length(key_hash)=32),
    attempt_count smallint NOT NULL DEFAULT 0 CHECK (attempt_count BETWEEN 0 AND 30),
    window_started timestamptz NOT NULL DEFAULT now(),
    blocked_until timestamptz,
    changed_at timestamptz NOT NULL DEFAULT now()
);
