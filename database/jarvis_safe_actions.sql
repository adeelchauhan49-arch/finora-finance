USE finance_ai;

ALTER TABLE financial_profile
    ADD COLUMN jarvis_auto_add_enabled TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN jarvis_auto_add_limit DECIMAL(12,2) NOT NULL DEFAULT 5000.00;

ALTER TABLE transactions
    ADD COLUMN source VARCHAR(24) NOT NULL DEFAULT 'manual';

CREATE TABLE jarvis_action_proposals (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    action_type VARCHAR(32) NOT NULL,
    payload JSON NOT NULL,
    status ENUM('pending','confirmed','cancelled','expired') NOT NULL DEFAULT 'pending',
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    confirmed_at DATETIME NULL,
    INDEX idx_jarvis_proposal_owner_status (user_id, status, expires_at),
    CONSTRAINT fk_jarvis_proposals_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE jarvis_action_rate_limits (
    user_id INT UNSIGNED NOT NULL PRIMARY KEY,
    window_started_at DATETIME NOT NULL,
    request_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    CONSTRAINT fk_jarvis_action_rate_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE jarvis_action_audit (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    action_type VARCHAR(32) NOT NULL,
    target_id BIGINT UNSIGNED NULL,
    amount DECIMAL(12,2) NULL,
    action_date DATE NULL,
    outcome VARCHAR(24) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_jarvis_audit_owner_created (user_id, created_at),
    CONSTRAINT fk_jarvis_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE jarvis_action_undo_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    transaction_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_jarvis_undo_owner_expiry (user_id, expires_at, used_at),
    CONSTRAINT fk_jarvis_undo_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_jarvis_undo_transaction FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE
) ENGINE=InnoDB;
