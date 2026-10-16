import { createClient } from "https://esm.sh/@supabase/supabase-js@2.49.1";

const corsHeaders = {
  "Access-Control-Allow-Origin": "*",
  "Access-Control-Allow-Headers": "authorization, apikey, content-type, x-client-info",
  "Access-Control-Allow-Methods": "POST, OPTIONS",
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
  if (!authorization || !url || !anonKey || !serviceKey) return json({ ok: false, message: "Finora Pro is not configured." }, 503);
  const userClient = createClient(url, anonKey, {
    global: { headers: { Authorization: authorization } },
    auth: { persistSession: false, autoRefreshToken: false },
  });
  const admin = createClient(url, serviceKey, { auth: { persistSession: false, autoRefreshToken: false } });

  try {
    const { data: authData, error: authError } = await userClient.auth.getUser();
    if (authError || !authData.user?.email_confirmed_at) return json({ ok: false, message: "Sign in with a verified email before purchasing Finora Pro." }, 401);
    const { data: profile, error: profileError } = await admin.from("users").select("id, name, email")
      .eq("auth_user_id", authData.user.id).single();
    if (profileError) throw profileError;
    const userId = Number(profile.id);
    const { data: active, error: activeError } = await admin.from("pro_subscriptions").select("id")
      .eq("user_id", userId).eq("status", "active").gt("ends_at", new Date().toISOString()).limit(1);
    if (activeError) throw activeError;
    if (active?.length) return json({ ok: false, message: "Your Finora Pro plan is already active." }, 409);

    const input = await request.json();
    const promoCode = typeof input.promo_code === "string" ? input.promo_code.trim() : "";
    const configuredPromo = Deno.env.get("FINORA_PRO_PROMO_CODE") || "";
    if (promoCode) {
      if (!configuredPromo || promoCode.toLowerCase() !== configuredPromo.toLowerCase()) {
        return json({ ok: false, message: "That promo code is invalid." }, 422);
      }
      const { data: endsAt, error } = await admin.rpc("finora_redeem_pro_promo", { p_user_id: userId });
      if (error) return json({ ok: false, message: error.message }, 409);
      return json({
        ok: true,
        promo_redeemed: true,
        active: true,
        ends_at: endsAt,
        message: "Promo code accepted. Finora Pro is active for 365 days.",
      }, 201);
    }

    const keyId = Deno.env.get("RAZORPAY_KEY_ID");
    const keySecret = Deno.env.get("RAZORPAY_KEY_SECRET");
    if (!keyId || !keySecret) return json({ ok: false, message: "Finora Pro payments are not configured yet." }, 503);
    const amountPaise = 54900;
    const receipt = `finora_${userId}_${crypto.randomUUID().replaceAll("-", "").slice(0, 16)}`;
    const orderResponse = await fetch("https://api.razorpay.com/v1/orders", {
      method: "POST",
      headers: {
        Authorization: `Basic ${btoa(`${keyId}:${keySecret}`)}`,
        "Content-Type": "application/json",
      },
      body: JSON.stringify({
        amount: amountPaise,
        currency: "INR",
        receipt,
        notes: { user_id: String(userId), plan: "finora-pro-365-days" },
      }),
    });
    const order = await orderResponse.json();
    if (!orderResponse.ok || typeof order.id !== "string" || !/^order_[A-Za-z0-9]+$/.test(order.id)) {
      console.error("Razorpay order request failed with status", orderResponse.status);
      return json({ ok: false, message: "Could not create the payment order. Please try again." }, 502);
    }
    const { error: saveError } = await admin.from("pro_subscriptions").insert({
      user_id: userId,
      razorpay_order_id: order.id,
      amount_paise: amountPaise,
      currency: "INR",
      status: "pending",
    });
    if (saveError) throw saveError;
    return json({
      ok: true,
      key_id: keyId,
      order_id: order.id,
      amount: amountPaise,
      currency: "INR",
      name: "Finora by Adeel",
      description: "Finora Pro annual plan (365 days)",
      prefill: { name: profile.name, email: profile.email },
    }, 201);
  } catch (error) {
    console.error("Pro order creation failed:", error instanceof Error ? error.message : "Unknown error");
    return json({ ok: false, message: "Could not create the payment order. Please try again." }, 500);
  }
});
