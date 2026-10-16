USE finance_ai;

CREATE TABLE IF NOT EXISTS pro_subscriptions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    razorpay_order_id VARCHAR(64) NULL UNIQUE,
    razorpay_payment_id VARCHAR(64) NULL UNIQUE,
    amount_paise INT UNSIGNED NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'INR',
    status ENUM('pending', 'active') NOT NULL DEFAULT 'pending',
    starts_at DATETIME NULL,
    ends_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_pro_subscriptions_user_status (user_id, status, ends_at),
    CONSTRAINT fk_pro_subscriptions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
