USE finance_ai;

ALTER TABLE transactions
    ADD COLUMN transaction_time TIME NULL AFTER transaction_date,
    ADD COLUMN payment_method ENUM('cash','upi','credit_card','debit_card','wallet','bank_transfer') NULL AFTER transaction_time,
    ADD COLUMN location VARCHAR(160) NULL AFTER payment_method,
    ADD COLUMN notes VARCHAR(500) NULL AFTER location,
    ADD COLUMN category_confidence DECIMAL(5,2) NULL AFTER notes,
    ADD INDEX idx_transactions_user_day (user_id, transaction_date, transaction_time);
