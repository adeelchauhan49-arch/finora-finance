import { createClient } from "https://esm.sh/@supabase/supabase-js@2.49.1";

const corsHeaders = {
  "Access-Control-Allow-Origin": "*",
  "Access-Control-Allow-Headers": "authorization, apikey, content-type, x-client-info",
  "Access-Control-Allow-Methods": "GET, POST, OPTIONS",
};
const json = (body: unknown, status = 200) => new Response(JSON.stringify(body), {
  status,
  headers: { ...corsHeaders, "Content-Type": "application/json; charset=utf-8" },
});

Deno.serve(async (request) => {
  if (request.method === "OPTIONS") return new Response("ok", { headers: corsHeaders });
  if (request.method !== "POST") return json({ ok: false, message: "Method not allowed." }, 405);
  if (Deno.env.get("FINORA_PRO_ENABLED") !== "true") return json({ error: "Not available" }, 404);

  const authorization = request.headers.get("Authorization");
  const url = Deno.env.get("SUPABASE_URL");
  const anonKey = Deno.env.get("SUPABASE_ANON_KEY") || Deno.env.get("SUPABASE_PUBLISHABLE_KEY");
  const serviceKey = Deno.env.get("SUPABASE_SERVICE_ROLE_KEY");
  if (!authorization || !url || !anonKey || !serviceKey) {
    return json({ ok: false, message: "Finora Pro is not configured." }, 503);
  }
  const userClient = createClient(url, anonKey, {
    global: { headers: { Authorization: authorization } },
    auth: { persistSession: false, autoRefreshToken: false },
  });
  const admin = createClient(url, serviceKey, { auth: { persistSession: false, autoRefreshToken: false } });

  try {
    const { data: authData, error: authError } = await userClient.auth.getUser();
    if (authError || !authData.user?.email_confirmed_at) return json({ ok: false, message: "Sign in with a verified email." }, 401);
    const { data: profile, error: profileError } = await admin.from("users").select("id")
      .eq("auth_user_id", authData.user.id).single();
    if (profileError) throw profileError;
    const { data, error } = await admin.from("pro_subscriptions").select("ends_at")
      .eq("user_id", profile.id).eq("status", "active").gt("ends_at", new Date().toISOString())
      .order("ends_at", { ascending: false }).limit(1);
    if (error) throw error;
    return json({ ok: true, active: Boolean(data?.length), ends_at: data?.[0]?.ends_at || null });
  } catch (error) {
    console.error("Pro status lookup failed:", error instanceof Error ? error.message : "Unknown error");
    return json({ ok: false, message: "Could not load Finora Pro status." }, 500);
  }
});
