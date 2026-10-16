USE finance_ai;

ALTER TABLE financial_profile
    ADD COLUMN monthly_budget DECIMAL(12,2) NOT NULL DEFAULT 2400.00 AFTER monthly_income;
