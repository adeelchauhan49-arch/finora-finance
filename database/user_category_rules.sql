USE finance_ai;

CREATE TABLE user_category_rules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    merchant_normalized VARCHAR(160) NOT NULL,
    category ENUM('Food & dining','Shopping','Transport','Housing','Health','Utilities','Entertainment','Other','Salary') NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_category_rules_owner_merchant (user_id, merchant_normalized),
    CONSTRAINT fk_user_category_rules_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
