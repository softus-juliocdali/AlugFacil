-- Additive only. No changes to usuarios, Web sessions, or financial tables.
BEGIN;
CREATE TABLE mobile_sessions (
    id CHAR(32) PRIMARY KEY,
    usuario_id INTEGER NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
    credential_stamp CHAR(64) NOT NULL,
    device_name VARCHAR(120),
    created_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
    last_used_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
    expires_at TIMESTAMPTZ NOT NULL,
    revoked_at TIMESTAMPTZ,
    revocation_reason VARCHAR(40)
);
CREATE INDEX mobile_sessions_user_idx ON mobile_sessions(usuario_id);
CREATE TABLE mobile_access_tokens (
    id BIGSERIAL PRIMARY KEY,
    session_id CHAR(32) NOT NULL REFERENCES mobile_sessions(id) ON DELETE CASCADE,
    token_hash CHAR(64) NOT NULL UNIQUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
    expires_at TIMESTAMPTZ NOT NULL,
    revoked_at TIMESTAMPTZ
);
CREATE INDEX mobile_access_session_idx ON mobile_access_tokens(session_id);
CREATE TABLE mobile_refresh_tokens (
    id BIGSERIAL PRIMARY KEY,
    session_id CHAR(32) NOT NULL REFERENCES mobile_sessions(id) ON DELETE CASCADE,
    token_hash CHAR(64) NOT NULL UNIQUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
    expires_at TIMESTAMPTZ NOT NULL,
    used_at TIMESTAMPTZ,
    revoked_at TIMESTAMPTZ,
    successor_id BIGINT REFERENCES mobile_refresh_tokens(id)
);
CREATE INDEX mobile_refresh_session_idx ON mobile_refresh_tokens(session_id);
CREATE TABLE mobile_auth_rate_limits (
    bucket_hash CHAR(64) PRIMARY KEY,
    attempts INTEGER NOT NULL,
    expires_at TIMESTAMPTZ NOT NULL
);
CREATE INDEX mobile_auth_rate_expiry_idx ON mobile_auth_rate_limits(expires_at);
COMMIT;
