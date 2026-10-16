USE finance_ai;

ALTER TABLE financial_profile
    ADD COLUMN onboarding_completed_at DATETIME NULL DEFAULT NULL AFTER onboarding_completed;

UPDATE financial_profile
SET onboarding_completed_at = CURRENT_TIMESTAMP
WHERE monthly_income > 0
  AND onboarding_completed_at IS NULL;
