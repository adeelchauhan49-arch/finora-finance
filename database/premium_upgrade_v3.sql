USE finance_ai;

ALTER TABLE financial_profile
    ADD COLUMN age TINYINT UNSIGNED NULL AFTER user_id,
    ADD COLUMN occupation VARCHAR(120) NULL AFTER age,
    ADD COLUMN city VARCHAR(120) NULL AFTER occupation,
    ADD COLUMN currency CHAR(3) NOT NULL DEFAULT 'INR' AFTER city,
    ADD COLUMN monthly_salary DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER currency,
    ADD COLUMN business_income DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER monthly_salary,
    ADD COLUMN freelance_income DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER business_income,
    ADD COLUMN other_income DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER freelance_income,
    ADD COLUMN monthly_investment_target DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER monthly_savings_goal,
    ADD COLUMN dream_goal VARCHAR(160) NULL AFTER goal_deadline;
