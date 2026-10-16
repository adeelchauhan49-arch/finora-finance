USE finance_ai;

SELECT
    COALESCE(SUM(CASE WHEN monthly_income > 0 THEN 1 ELSE 0 END), 0) AS profiles_to_mark_completed,
    COALESCE(SUM(CASE WHEN monthly_income <= 0 OR monthly_income IS NULL THEN 1 ELSE 0 END), 0) AS profiles_left_incomplete
FROM financial_profile;
