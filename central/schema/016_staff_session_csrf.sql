-- A separate CSRF proof is bound to the server-side vendor staff session.
-- No public route or cookie issuance is activated by this additive migration.
ALTER TABLE central_staff_sessions ADD COLUMN IF NOT EXISTS csrf_hash
 bytea CHECK (csrf_hash IS NULL OR octet_length(csrf_hash)=32);
