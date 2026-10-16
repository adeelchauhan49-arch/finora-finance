# Finora setup

Finora is a static HTML/CSS/JavaScript frontend. Sign-in and user-owned data use Supabase directly; there is no PHP app server or local database.

## Frontend

1. Serve the project root over HTTP with a static web server, or use XAMPP Apache to serve the HTML assets. PHP is not required.
2. Set the Supabase project URL and publishable key in `assets/js/supabase-config.js`. The publishable key is designed for browser use; never put a service-role key or provider secret in this file.
3. Set the Supabase Auth Site URL and allowed redirect URLs to the local and deployed frontend URLs. Keep email confirmations enabled and configure Supabase Auth email templates for the confirmation and password-recovery code/link flows.
4. Apply the migrations in `supabase/migrations` in timestamp order. The second migration enables browser access only to owner-scoped tables through RLS. Do not enable direct access to service-only tables or disable RLS.

## Supabase Edge Functions

Deploy the functions using the Supabase CLI:

```powershell
supabase functions deploy jarvis
supabase functions deploy jarvis-action
supabase functions deploy pro-status
supabase functions deploy pro-create-order
supabase functions deploy pro-verify-payment
```

Configure secrets in the Supabase project, not in this frontend directory:

- `JARVIS_API_KEY` and optionally `JARVIS_MODEL` for Jarvis responses.
- `FINORA_PRO_ENABLED=true` to enable Pro. It is disabled unless explicitly enabled.
- `RAZORPAY_KEY_ID` and `RAZORPAY_KEY_SECRET` for Pro payments.
- `FINORA_PRO_PROMO_CODE` optionally enables one-time Pro promotion-code redemption.

The Edge Functions use the Supabase service-role key only inside the hosted function runtime; it must never be put in frontend code, checked into source control, or sent to a browser. Configure and deploy secrets after rotating any credentials that were previously stored in `.env`.

## Tests

Run the frontend and migration contract tests with:

```powershell
python -m unittest discover -s tests
```
