grant usage on schema public to authenticated;
grant execute on function public.finora_user_id() to authenticated;
create extension if not exists pgcrypto with schema extensions;

create policy users_select_own_authenticated on public.users
    for select to authenticated
    using (auth_user_id = (select auth.uid()));
create policy users_update_own_authenticated on public.users
    for update to authenticated
    using (auth_user_id = (select auth.uid()))
    with check (auth_user_id = (select auth.uid()));
grant select, update (name) on public.users to authenticated;

do $$
declare
    table_name text;
begin
    foreach table_name in array array[
        'transactions', 'financial_profile', 'investment_plan', 'savings_goals',
        'savings_goal_contributions', 'user_category_rules'
    ]
    loop
        execute format(
            'create policy %I on public.%I for all to authenticated using (user_id = public.finora_user_id()) with check (user_id = public.finora_user_id())',
            table_name || '_authenticated_owner_all',
            table_name
        );
    end loop;
end
$$;

grant select, insert, update, delete on public.transactions to authenticated;
grant select, insert, update, delete on public.financial_profile to authenticated;
grant select, insert, delete on public.investment_plan to authenticated;
grant select, insert, update, delete on public.savings_goals to authenticated;
grant select, insert, delete on public.savings_goal_contributions to authenticated;
grant select, insert, update, delete on public.user_category_rules to authenticated;
grant usage, select on sequence
    public.transactions_id_seq,
    public.financial_profile_id_seq,
    public.investment_plan_id_seq,
    public.savings_goals_id_seq,
    public.savings_goal_contributions_id_seq,
    public.user_category_rules_id_seq
to authenticated;

create or replace function public.finora_save_onboarding(
    p_name text,
    p_profile jsonb,
    p_investments jsonb
)
returns void
language plpgsql
set search_path = ''
as $$
declare
    app_user_id bigint := public.finora_user_id();
    monthly_income numeric(12,2);
begin
    if app_user_id is null
        or jsonb_typeof(p_profile) <> 'object'
        or jsonb_typeof(p_investments) <> 'array'
        or length(trim(coalesce(p_name, ''))) > 120
        or length(trim(coalesce(p_name, ''))) = 1 then
        raise exception 'Invalid onboarding profile.';
    end if;

    monthly_income :=
        coalesce(nullif(p_profile ->> 'monthly_salary', '')::numeric, 0)
        + coalesce(nullif(p_profile ->> 'business_income', '')::numeric, 0)
        + coalesce(nullif(p_profile ->> 'freelance_income', '')::numeric, 0)
        + coalesce(nullif(p_profile ->> 'other_income', '')::numeric, 0);

    if monthly_income < 0
        or monthly_income > 9999999999.99
        or coalesce(nullif(p_profile ->> 'savings_percentage', '')::numeric, 0) not between 0 and 100
        or coalesce(nullif(p_profile ->> 'currency', ''), 'INR') !~ '^[A-Z]{3}$'
        or coalesce(nullif(p_profile ->> 'lifestyle', ''), 'working_professional')
            not in ('student', 'working_professional', 'self_employed', 'freelancer')
        or coalesce(nullif(p_profile ->> 'spending_style', ''), 'balanced')
            not in ('saver', 'balanced', 'spender') then
        raise exception 'Invalid onboarding values.';
    end if;

    if length(trim(coalesce(p_name, ''))) >= 2 then
        update public.users set name = trim(p_name) where id = app_user_id;
    end if;

    insert into public.financial_profile (
        user_id, age, occupation, city, currency, monthly_salary, business_income,
        freelance_income, other_income, monthly_income, monthly_savings_goal,
        monthly_investment_target, savings_percentage, emergency_fund_target,
        goal_deadline, dream_goal, lifestyle, spending_style, budget_allocations,
        onboarding_completed, onboarding_completed_at
    ) values (
        app_user_id,
        nullif(p_profile ->> 'age', '')::smallint,
        nullif(p_profile ->> 'occupation', ''),
        nullif(p_profile ->> 'city', ''),
        coalesce(nullif(p_profile ->> 'currency', ''), 'INR'),
        coalesce(nullif(p_profile ->> 'monthly_salary', '')::numeric, 0),
        coalesce(nullif(p_profile ->> 'business_income', '')::numeric, 0),
        coalesce(nullif(p_profile ->> 'freelance_income', '')::numeric, 0),
        coalesce(nullif(p_profile ->> 'other_income', '')::numeric, 0),
        monthly_income,
        coalesce(nullif(p_profile ->> 'monthly_savings_goal', '')::numeric, 0),
        coalesce(nullif(p_profile ->> 'monthly_investment_target', '')::numeric, 0),
        coalesce(nullif(p_profile ->> 'savings_percentage', '')::numeric, 0),
        coalesce(nullif(p_profile ->> 'emergency_fund_target', '')::numeric, 0),
        nullif(p_profile ->> 'goal_deadline', '')::date,
        nullif(p_profile ->> 'dream_goal', ''),
        coalesce(nullif(p_profile ->> 'lifestyle', ''), 'working_professional'),
        coalesce(nullif(p_profile ->> 'spending_style', ''), 'balanced'),
        coalesce(p_profile -> 'budget_allocations', '{}'::jsonb),
        true,
        now()
    )
    on conflict (user_id) do update set
        age = excluded.age,
        occupation = excluded.occupation,
        city = excluded.city,
        currency = excluded.currency,
        monthly_salary = excluded.monthly_salary,
        business_income = excluded.business_income,
        freelance_income = excluded.freelance_income,
        other_income = excluded.other_income,
        monthly_income = excluded.monthly_income,
        monthly_savings_goal = excluded.monthly_savings_goal,
        monthly_investment_target = excluded.monthly_investment_target,
        savings_percentage = excluded.savings_percentage,
        emergency_fund_target = excluded.emergency_fund_target,
        goal_deadline = excluded.goal_deadline,
        dream_goal = excluded.dream_goal,
        lifestyle = excluded.lifestyle,
        spending_style = excluded.spending_style,
        budget_allocations = excluded.budget_allocations,
        onboarding_completed = true,
        onboarding_completed_at = now();

    delete from public.investment_plan where user_id = app_user_id;
    insert into public.investment_plan (user_id, monthly_amount, investment_type)
    select app_user_id,
        greatest(0, coalesce(nullif(p_profile ->> 'monthly_investment_amount', '')::numeric, 0)),
        left(value, 40)
    from jsonb_array_elements_text(p_investments) as investment(value);
end;
$$;

revoke all on function public.finora_save_onboarding(text, jsonb, jsonb) from public, anon;
grant execute on function public.finora_save_onboarding(text, jsonb, jsonb) to authenticated;

create or replace function public.finora_consume_rate_limit(p_for_action boolean default false)
returns integer
language plpgsql
security definer
set search_path = ''
as $$
declare
    app_user_id bigint := public.finora_user_id();
    current_count integer;
begin
    if app_user_id is null then
        raise exception 'Authentication required.';
    end if;

    if p_for_action then
        insert into public.jarvis_action_rate_limits (user_id, window_started_at, request_count)
        values (app_user_id, now(), 1)
        on conflict (user_id) do update set
            request_count = case
                when jarvis_action_rate_limits.window_started_at <= now() - interval '1 minute' then 1
                else least(jarvis_action_rate_limits.request_count + 1, 32767)
            end,
            window_started_at = case
                when jarvis_action_rate_limits.window_started_at <= now() - interval '1 minute' then now()
                else jarvis_action_rate_limits.window_started_at
            end
        returning request_count into current_count;
    else
        insert into public.jarvis_rate_limits (user_id, window_started_at, request_count)
        values (app_user_id, now(), 1)
        on conflict (user_id) do update set
            request_count = case
                when jarvis_rate_limits.window_started_at <= now() - interval '1 minute' then 1
                else least(jarvis_rate_limits.request_count + 1, 32767)
            end,
            window_started_at = case
                when jarvis_rate_limits.window_started_at <= now() - interval '1 minute' then now()
                else jarvis_rate_limits.window_started_at
            end
        returning request_count into current_count;
    end if;

    return current_count;
end;
$$;

revoke all on function public.finora_consume_rate_limit(boolean) from public, anon;
grant execute on function public.finora_consume_rate_limit(boolean) to authenticated;

create or replace function public.finora_confirm_jarvis_action(p_token_hash text)
returns jsonb
language plpgsql
security definer
set search_path = ''
as $$
declare
    app_user_id bigint := public.finora_user_id();
    proposal public.jarvis_action_proposals%rowtype;
    action jsonb;
    action_type text;
    target_id bigint;
    action_amount numeric(12,2);
    action_date date;
    undo_token text;
begin
    if app_user_id is null or p_token_hash !~ '^[a-f0-9]{64}$' then
        raise exception 'This action preview is invalid or expired.';
    end if;

    select * into proposal
    from public.jarvis_action_proposals
    where user_id = app_user_id
      and token_hash = p_token_hash
      and status = 'pending'
      and expires_at > now()
    for update;
    if not found then
        raise exception 'This action preview is invalid or expired.';
    end if;

    action := proposal.payload;
    action_type := action ->> 'type';
    if action_type in ('add_expense', 'add_income') then
        action_amount := (action ->> 'amount')::numeric;
        action_date := (action ->> 'date')::date;
        if action_amount <= 0 or action_amount > 9999999999.99
            or action_date < current_date - interval '10 years'
            or action_date > current_date
            or length(coalesce(action ->> 'merchant', '')) not between 1 and 160
            or action ->> 'category' not in ('Food & dining', 'Shopping', 'Transport', 'Housing', 'Health', 'Utilities', 'Entertainment', 'Other', 'Salary')
            or (action ->> 'category' = 'Salary' and action_type <> 'add_income') then
            raise exception 'Enter valid transaction details.';
        end if;
        insert into public.transactions (
            user_id, type, merchant, category, amount, transaction_date,
            transaction_time, notes, category_confidence, source
        ) values (
            app_user_id,
            case when action_type = 'add_income' then 'income' else 'expense' end,
            action ->> 'merchant',
            action ->> 'category',
            action_amount,
            action_date,
            localtime,
            'Added via confirmed Jarvis action',
            100,
            'jarvis_confirmed'
        ) returning id into target_id;
        undo_token := encode(extensions.gen_random_bytes(32), 'hex');
        insert into public.jarvis_action_undo_tokens (user_id, transaction_id, token_hash, expires_at)
        values (
            app_user_id, target_id,
            encode(extensions.digest(undo_token, 'sha256'), 'hex'),
            now() + interval '2 minutes'
        );
    elsif action_type = 'edit_transaction' then
        action_amount := (action #>> '{transaction,amount}')::numeric;
        action_date := (action #>> '{transaction,date}')::date;
        target_id := (action #>> '{transaction,id}')::bigint;
        if action_amount <= 0 or action_amount > 9999999999.99
            or action_date < current_date - interval '10 years'
            or action_date > current_date
            or length(coalesce(action #>> '{transaction,merchant}', '')) not between 1 and 160 then
            raise exception 'Enter valid transaction details.';
        end if;
        update public.transactions set
            merchant = action #>> '{transaction,merchant}',
            category = action #>> '{transaction,category}',
            amount = action_amount,
            transaction_date = action_date
        where id = target_id and user_id = app_user_id;
        if not found then raise exception 'That transaction was not found in your account.'; end if;
    elsif action_type = 'add_goal_contribution' then
        action_amount := (action ->> 'amount')::numeric;
        action_date := (action ->> 'date')::date;
        if action_amount <= 0 or action_amount > 9999999999.99
            or action_date < current_date - interval '10 years'
            or action_date > current_date then
            raise exception 'Enter a valid contribution date and amount.';
        end if;
        insert into public.savings_goal_contributions (user_id, goal_id, amount, contribution_date)
        values (app_user_id, (action ->> 'goal_id')::bigint, action_amount, action_date)
        returning id into target_id;
    elsif action_type = 'set_budget' then
        action_amount := (action ->> 'amount')::numeric;
        if action_amount <= 0 or action_amount > 9999999999.99 then
            raise exception 'Enter a valid monthly budget.';
        end if;
        insert into public.financial_profile (user_id, monthly_budget)
        values (app_user_id, action_amount)
        on conflict (user_id) do update set monthly_budget = excluded.monthly_budget;
    else
        raise exception 'That action is not available through Jarvis.';
    end if;

    update public.jarvis_action_proposals
    set status = 'confirmed', confirmed_at = now()
    where id = proposal.id and user_id = app_user_id;
    insert into public.jarvis_action_audit (user_id, action_type, target_id, amount, action_date, outcome)
    values (app_user_id, action_type, target_id, action_amount, action_date, 'confirmed');

    return jsonb_build_object(
        'action_type', action_type,
        'transaction_id', target_id,
        'undo_token', undo_token,
        'message', 'Confirmed Jarvis action applied.'
    );
end;
$$;

create or replace function public.finora_undo_jarvis_action(p_token_hash text)
returns jsonb
language plpgsql
security definer
set search_path = ''
as $$
declare
    app_user_id bigint := public.finora_user_id();
    undo_row public.jarvis_action_undo_tokens%rowtype;
    transaction_row public.transactions%rowtype;
begin
    if app_user_id is null or p_token_hash !~ '^[a-f0-9]{64}$' then
        raise exception 'This Undo link is invalid or expired.';
    end if;

    select * into undo_row
    from public.jarvis_action_undo_tokens
    where user_id = app_user_id
      and token_hash = p_token_hash
      and used_at is null
      and expires_at > now()
    for update;
    if not found then raise exception 'This Undo link is invalid or expired.'; end if;

    select * into transaction_row
    from public.transactions
    where id = undo_row.transaction_id
      and user_id = app_user_id
      and source in ('jarvis_auto', 'jarvis_confirmed')
      and notes = 'Added via confirmed Jarvis action'
    for update;
    if not found then raise exception 'This transaction can no longer be undone.'; end if;

    update public.jarvis_action_undo_tokens set used_at = now()
    where id = undo_row.id and used_at is null;
    delete from public.transactions where id = transaction_row.id and user_id = app_user_id;
    insert into public.jarvis_action_audit (user_id, action_type, target_id, amount, action_date, outcome)
    values (
        app_user_id, 'undo_add_' || transaction_row.type, transaction_row.id,
        transaction_row.amount, transaction_row.transaction_date, 'undone'
    );
    return jsonb_build_object('target_id', transaction_row.id);
end;
$$;

revoke all on function public.finora_confirm_jarvis_action(text) from public, anon;
revoke all on function public.finora_undo_jarvis_action(text) from public, anon;
grant execute on function public.finora_confirm_jarvis_action(text) to authenticated;
grant execute on function public.finora_undo_jarvis_action(text) to authenticated;

create or replace function public.finora_redeem_pro_promo(p_user_id bigint)
returns timestamptz
language plpgsql
security definer
set search_path = ''
as $$
declare
    subscription_end timestamptz;
begin
    perform 1 from public.users where id = p_user_id for update;
    if not found then raise exception 'Your account could not be found.'; end if;
    if exists (
        select 1 from public.pro_subscriptions
        where user_id = p_user_id and status = 'active' and ends_at > now()
    ) then raise exception 'Your Finora Pro plan is already active.'; end if;
    if exists (
        select 1 from public.pro_subscriptions where user_id = p_user_id and amount_paise = 0
    ) then raise exception 'This promo code has already been redeemed on your account.'; end if;

    subscription_end := now() + interval '365 days';
    insert into public.pro_subscriptions (user_id, amount_paise, currency, status, starts_at, ends_at)
    values (p_user_id, 0, 'INR', 'active', now(), subscription_end);
    return subscription_end;
end;
$$;

create or replace function public.finora_activate_pro_subscription(
    p_user_id bigint,
    p_order_id text,
    p_payment_id text
)
returns boolean
language plpgsql
security definer
set search_path = ''
as $$
begin
    update public.pro_subscriptions
    set razorpay_payment_id = p_payment_id,
        status = 'active',
        starts_at = now(),
        ends_at = now() + interval '365 days'
    where user_id = p_user_id
      and razorpay_order_id = p_order_id
      and status = 'pending';
    return found;
end;
$$;

revoke all on function public.finora_redeem_pro_promo(bigint) from public, anon, authenticated;
revoke all on function public.finora_activate_pro_subscription(bigint, text, text) from public, anon, authenticated;
grant execute on function public.finora_redeem_pro_promo(bigint) to service_role;
grant execute on function public.finora_activate_pro_subscription(bigint, text, text) to service_role;

create or replace function public.finora_adjust_goal_total()
returns trigger
language plpgsql
set search_path = ''
as $$
begin
    update public.savings_goals
    set current_amount = current_amount + new.amount
    where id = new.goal_id and user_id = new.user_id;
    return new;
end;
$$;

create trigger finora_goal_contribution_total
after insert on public.savings_goal_contributions
for each row execute function public.finora_adjust_goal_total();

create or replace function public.finora_delete_goal_contribution(p_contribution_id bigint)
returns boolean
language plpgsql
security definer
set search_path = ''
as $$
declare
    app_user_id bigint := public.finora_user_id();
    contribution public.savings_goal_contributions%rowtype;
begin
    if app_user_id is null then raise exception 'Authentication required.'; end if;

    select * into contribution
    from public.savings_goal_contributions
    where id = p_contribution_id and user_id = app_user_id
    for update;
    if not found then return false; end if;

    perform 1 from public.savings_goals
    where id = contribution.goal_id and user_id = app_user_id
    for update;
    if not found then return false; end if;

    delete from public.savings_goal_contributions
    where id = contribution.id and user_id = app_user_id;
    update public.savings_goals
    set current_amount = greatest(0, current_amount - contribution.amount)
    where id = contribution.goal_id and user_id = app_user_id;
    return true;
end;
$$;

revoke all on function public.finora_delete_goal_contribution(bigint) from public, anon;
grant execute on function public.finora_delete_goal_contribution(bigint) to authenticated;
