USE finance_ai;

SELECT 'users' AS source_table, COUNT(*) AS rows_to_migrate FROM users
UNION ALL
SELECT 'verified_users', COUNT(*) FROM users WHERE email_verified_at IS NOT NULL
UNION ALL
SELECT 'unverified_users', COUNT(*) FROM users WHERE email_verified_at IS NULL
UNION ALL
SELECT 'transactions', COUNT(*) FROM transactions
UNION ALL
SELECT 'financial_profile', COUNT(*) FROM financial_profile
UNION ALL
SELECT 'investment_plan', COUNT(*) FROM investment_plan
UNION ALL
SELECT 'savings_goals', COUNT(*) FROM savings_goals
UNION ALL
SELECT 'savings_goal_contributions', COUNT(*) FROM savings_goal_contributions
UNION ALL
SELECT 'user_category_rules', COUNT(*) FROM user_category_rules
UNION ALL
SELECT 'pro_subscriptions', COUNT(*) FROM pro_subscriptions;
