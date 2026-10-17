alter table public.financial_profile
    alter column monthly_budget drop default,
    alter column monthly_budget drop not null;
