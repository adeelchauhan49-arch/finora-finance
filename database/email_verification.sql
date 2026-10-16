USE finance_ai;

ALTER TABLE users
    ADD COLUMN email_verified_at DATETIME NULL DEFAULT NULL AFTER password_hash,
    ADD COLUMN pending_email VARCHAR(190) NULL DEFAULT NULL AFTER email_verified_at,
    ADD UNIQUE KEY uq_users_pending_email (pending_email);

UPDATE users
SET email_verified_at = CURRENT_TIMESTAMP
WHERE email_verified_at IS NULL;

CREATE TABLE email_verification_otps (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    target_email VARCHAR(190) NOT NULL,
    otp_hash VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    consumed_at DATETIME NULL,
    invalidated_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_email_verification_owner_email (user_id, target_email, id),
    INDEX idx_email_verification_rate (target_email, created_at),
    CONSTRAINT fk_email_verification_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
