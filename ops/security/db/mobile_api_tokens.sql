CREATE TABLE IF NOT EXISTS mobile_api_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    account_type VARCHAR(20) NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    last_used_at DATETIME NULL,
    revoked_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_mobile_api_token_hash (token_hash),
    KEY idx_mobile_api_user (account_type, user_id),
    KEY idx_mobile_api_expiry (expires_at),
    KEY idx_mobile_api_revoked (revoked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
