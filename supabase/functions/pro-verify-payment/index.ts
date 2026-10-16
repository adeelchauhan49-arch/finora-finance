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
const toHex = (bytes: ArrayBuffer) => Array.from(new Uint8Array(bytes), (value) => value.toString(16).padStart(2, "0")).join("");

Deno.serve(async (request) => {
  if (request.method === "OPTIONS") return new Response("ok", { headers: corsHeaders });
  if (request.method !== "POST") return json({ ok: false, message: "Method not allowed." }, 405);
  if (Deno.env.get("FINORA_PRO_ENABLED") !== "true") return json({ error: "Not available" }, 404);

  const authorization = request.headers.get("Authorization");
  const url = Deno.env.get("SUPABASE_URL");
  const anonKey = Deno.env.get("SUPABASE_ANON_KEY") || Deno.env.get("SUPABASE_PUBLISHABLE_KEY");
  const serviceKey = Deno.env.get("SUPABASE_SERVICE_ROLE_KEY");
  const keyId = Deno.env.get("RAZORPAY_KEY_ID");
  const keySecret = Deno.env.get("RAZORPAY_KEY_SECRET");
  if (!authorization || !url || !anonKey || !serviceKey || !keyId || !keySecret) {
    return json({ ok: false, message: "Razorpay is not configured on the server." }, 503);
  }
  const userClient = createClient(url, anonKey, {
    global: { headers: { Authorization: authorization } },
    auth: { persistSession: false, autoRefreshToken: false },
  });
  const admin = createClient(url, serviceKey, { auth: { persistSession: false, autoRefreshToken: false } });

  try {
    const { data: authData, error: authError } = await userClient.auth.getUser();
    if (authError || !authData.user?.email_confirmed_at) return json({ ok: false, message: "Sign in with a verified email before verifying a payment." }, 401);
    const { data: profile, error: profileError } = await admin.from("users").select("id")
      .eq("auth_user_id", authData.user.id).single();
    if (profileError) throw profileError;

    const input = await request.json();
    const orderId = typeof input.razorpay_order_id === "string" ? input.razorpay_order_id : "";
    const paymentId = typeof input.razorpay_payment_id === "string" ? input.razorpay_payment_id : "";
    const signature = typeof input.razorpay_signature === "string" ? input.razorpay_signature : "";
    if (!/^order_[A-Za-z0-9]+$/.test(orderId) || !/^pay_[A-Za-z0-9]+$/.test(paymentId) || !/^[a-f0-9]{64}$/i.test(signature)) {
      return json({ ok: false, message: "Razorpay returned invalid payment details." }, 422);
    }

    const { data: subscription, error: subscriptionError } = await admin.from("pro_subscriptions")
      .select("id, amount_paise, currency, status, razorpay_payment_id")
      .eq("user_id", profile.id).eq("razorpay_order_id", orderId).maybeSingle();
    if (subscriptionError) throw subscriptionError;
    if (!subscription) return json({ ok: false, message: "Payment order was not found for this account." }, 404);
    if (subscription.status === "active") {
      if (subscription.razorpay_payment_id === paymentId) return json({ ok: true, message: "Finora Pro is active for one year." });
      return json({ ok: false, message: "This order has already been used." }, 409);
    }

    const hmacKey = await crypto.subtle.importKey(
      "raw",
      new TextEncoder().encode(keySecret),
      { name: "HMAC", hash: "SHA-256" },
      false,
      ["sign"],
    );
    const expected = toHex(await crypto.subtle.sign("HMAC", hmacKey, new TextEncoder().encode(`${orderId}|${paymentId}`)));
    if (expected.toLowerCase() !== signature.toLowerCase()) {
      return json({ ok: false, message: "Payment verification failed. No subscription was activated." }, 400);
    }

    const paymentResponse = await fetch(`https://api.razorpay.com/v1/payments/${encodeURIComponent(paymentId)}`, {
      headers: { Authorization: `Basic ${btoa(`${keyId}:${keySecret}`)}` },
    });
    const payment = await paymentResponse.json();
    if (!paymentResponse.ok) {
      console.error("Razorpay payment lookup failed with status", paymentResponse.status);
      return json({ ok: false, message: "Could not confirm payment with Razorpay. Please try again." }, 502);
    }
    if (payment.order_id !== orderId || payment.status !== "captured"
      || Number(payment.amount) !== Number(subscription.amount_paise) || payment.currency !== subscription.currency) {
      return json({ ok: false, message: "Razorpay has not confirmed the expected payment. No subscription was activated." }, 400);
    }

    const { data: activated, error: activationError } = await admin.rpc("finora_activate_pro_subscription", {
      p_user_id: Number(profile.id),
      p_order_id: orderId,
      p_payment_id: paymentId,
    });
    if (activationError) throw activationError;
    if (!activated) {
      const { data: current, error } = await admin.from("pro_subscriptions").select("status, razorpay_payment_id")
        .eq("user_id", profile.id).eq("razorpay_order_id", orderId).single();
      if (error) throw error;
      if (current.status === "active" && current.razorpay_payment_id === paymentId) {
        return json({ ok: true, message: "Finora Pro is active for one year." });
      }
      return json({ ok: false, message: "This order has already been used." }, 409);
    }
    return json({ ok: true, message: "Payment verified. Finora Pro is active for 365 days." });
  } catch (error) {
    console.error("Pro payment verification failed:", error instanceof Error ? error.message : "Unknown error");
    return json({ ok: false, message: "Could not verify this payment. Please try again." }, 500);
  }
});
