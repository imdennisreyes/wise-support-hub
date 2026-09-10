-- Wise Support Hub – API Tokens table
-- Run this once against your MySQL database to enable the article REST API.
-- For the SQLite dev environment this table is created automatically via sqlite_schema.sql.

CREATE TABLE IF NOT EXISTS api_tokens (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(120)  NOT NULL COMMENT 'Human-readable label, e.g. "AI Knowledge Base"',
    token_hash  VARCHAR(255)  NOT NULL UNIQUE COMMENT 'SHA-256 hash of the actual bearer token',
    is_active   BOOLEAN       NOT NULL DEFAULT TRUE,
    last_used_at DATETIME     NULL,
    created_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_api_tokens_active (is_active)
) ENGINE=InnoDB COMMENT='Bearer tokens for the read-only article REST API';

-- ─────────────────────────────────────────────────────────────────────────────
-- HOW TO GENERATE AND REGISTER A TOKEN
-- ─────────────────────────────────────────────────────────────────────────────
-- 1. Generate a secure random token on the command line:
--
--      php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"
--      # e.g.  a3f7c2...  (64-hex-char string)
--
-- 2. Insert a row with the SHA-256 hash of that token:
--
--      INSERT INTO api_tokens (name, token_hash)
--      VALUES ('AI Knowledge Base', SHA2('YOUR_GENERATED_TOKEN', 256));
--
-- 3. Use the raw token (NOT the hash) in the Authorization header:
--
--      Authorization: Bearer YOUR_GENERATED_TOKEN
--
-- Keep the raw token server-side (e.g. in an environment variable).
-- The database only stores the hash – a compromised DB dump cannot reveal the token.
-- ─────────────────────────────────────────────────────────────────────────────
