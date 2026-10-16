# Finance-AI configuration

Install the PHP dependencies from the project directory:

```powershell
composer install
```

Copy `.env.example` to `.env` and set values for your mail provider. Use SMTP with TLS on port 587 (or SSL on port 465 if required by your provider). Set `SMTP_FROM_ADDRESS` to a sender address that the provider has authorized. Password reset fails with a generic error if delivery is not configured; OTP values are never returned in production.

To enable AI replies, set `JARVIS_API_KEY` to an API key for OpenAI and choose a supported chat model in `JARVIS_MODEL`. The PHP server sends the request to OpenAI; the browser never receives the key. Without a key, Jarvis continues to answer using its built-in help responses.

An optional Pro promotion code can be configured with `FINORA_PRO_PROMO_CODE`. It is disabled when the setting is blank (the default); do not add the code to application source or client-side files.

Finora Pro features and payment endpoints are controlled by `FINORA_PRO_ENABLED`. It defaults to `false`, which makes the product features available without Pro and disables Pro order, verification, and status endpoints. Set it to `true` to restore Pro subscription checks and payment flows.

Run `database/jarvis_rate_limits.sql` against the `finance_ai` database to enable Jarvis's per-user request limit. Jarvis builds its answers from the signed-in user's transactions and monthly budget; without an API key it uses the built-in data-grounded response engine.

Run `database/jarvis_safe_actions.sql` after the existing transaction, financial-profile, savings-goal, and contribution migrations to enable confirmed Jarvis actions, action auditing/rate limits, and the default-off auto-add setting. Jarvis actions remain restricted to active Pro accounts. Auto-add is disabled by default; when explicitly enabled in Settings, only expense/income entries at or below the user's configured limit are added automatically and can be undone for 10 minutes.

Before importing `database/onboarding_completed.sql`, run the read-only `database/onboarding_completed_preview.sql` in phpMyAdmin against `finance_ai` and review both counts. The migration marks a profile completed only when `monthly_income > 0`; profiles with income `0` or NULL remain incomplete, including auto-created rows with the default monthly budget. Import `database/onboarding_completed.sql` in phpMyAdmin only after reviewing the preview results. Do not treat the preview query as the migration.

For local OTP testing only, set `APP_ENV=local` and `ALLOW_DEBUG_OTP=true`. This enables the response to include the OTP when SMTP delivery fails. Keep `ALLOW_DEBUG_OTP=false` in every deployed environment.

## Supabase Auth and database

The app uses Supabase Auth for signup, sign-in, email confirmation, password recovery, and email/password changes. Its PHP data endpoints connect to Supabase Postgres over PDO and keep user ownership scoped with row-level security. The browser receives only the Supabase project URL and publishable key; the database password and service-role key must remain server-side.

1. In your private `.env` (do not share it), configure `SUPABASE_URL`, `SUPABASE_PUBLISHABLE_KEY`, `SUPABASE_SITE_URL`, and `SUPABASE_DB_URL` from the Supabase project dashboard. Add `SUPABASE_SERVICE_ROLE_KEY` only for the one-time existing-data migration.
2. Enable the PHP `pdo_pgsql` extension in XAMPP's `php.ini`, restart Apache, and confirm `php -m` lists `pdo_pgsql`.
3. Review and apply `supabase/migrations/20261014000000_finora_supabase.sql` in the Supabase SQL editor. This creates the app schema, Auth-to-profile trigger, owner policies, and a restricted PHP API role. Do not run the app against the hosted database before this migration completes.
4. Configure Supabase Auth's Site URL and redirect URLs for the deployed app. Keep email confirmations enabled, and configure the confirmation and recovery templates to support the Supabase code/link flow.
5. Before moving existing data, back up MySQL and run `database/supabase_mysql_migration_preview.sql` against `finance_ai`. Review the row counts. Then populate the `LEGACY_DB_*` settings in `.env` and run the migration script without `--apply` to review its dry-run counts.
6. Only after reviewing the preview and verifying email delivery, run `php scripts/migrate_mysql_to_supabase.php --apply` from the project directory and type `IMPORT` at its confirmation prompt. The importer links legacy numeric user IDs to Supabase UUID identities, remaps owned rows, preserves pending email changes, and sends verified users a password-recovery link. Old password hashes are intentionally not imported. Unverified users remain unverified and receive a Supabase confirmation link. Ephemeral OTPs, rate limits, undo tokens, and pending Jarvis proposals are not transferred.
7. Keep the MySQL backup until verifying the Supabase row counts and owner isolation. The importer never deletes or modifies source MySQL data. Auth accounts created before an interrupted import cannot be rolled back automatically; resolve the printed conflict before retrying.

The Supabase VS Code extension message “Could not connect to local Supabase project. Make sure you've run `supabase start`!” refers to the *local* stack, not your hosted project. This repository now has `supabase/config.toml`; to run a local stack, install the Supabase CLI and Docker Desktop, then run `supabase start`. For the hosted project, use the dashboard SQL editor or link the CLI to the hosted project; do not run `supabase start` to connect to the hosted database.