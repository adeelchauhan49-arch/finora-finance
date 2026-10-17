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

const dateText = (date: Date) => date.toISOString().slice(0, 10);

Deno.serve(async (request) => {
  if (request.method === "OPTIONS") return new Response("ok", { headers: corsHeaders });
  if (request.method !== "POST") return json({ ok: false, message: "Method not allowed." }, 405);

  const authorization = request.headers.get("Authorization");
  const url = Deno.env.get("SUPABASE_URL");
  const publishableKey = Deno.env.get("SUPABASE_ANON_KEY") || Deno.env.get("SUPABASE_PUBLISHABLE_KEY");
  if (!authorization || !url || !publishableKey) {
    return json({ ok: false, message: "Jarvis is not configured." }, 503);
  }

  const supabase = createClient(url, publishableKey, {
    global: { headers: { Authorization: authorization } },
    auth: { persistSession: false, autoRefreshToken: false },
  });

  try {
    const { data: authData, error: authError } = await supabase.auth.getUser();
    if (authError || !authData.user?.email_confirmed_at) {
      return json({ ok: false, message: "Sign in with a verified email to use Jarvis." }, 401);
    }

    const input = await request.json();
    const question = typeof input.question === "string" ? input.question.trim() : "";
    const period = input.period;
    if (!question || question.length > 1000 || !["this-month", "last-month", "three-months"].includes(period)) {
      return json({ ok: false, message: "Send a valid question and date range to Jarvis." }, 422);
    }
    const history = Array.isArray(input.history)
      ? input.history
        .filter((turn: { role?: unknown; text?: unknown } | null) =>
          turn !== null
          && (turn.role === "user" || turn.role === "model")
          && typeof turn.text === "string"
          && turn.text.trim() !== "")
        .slice(-12)
        .map((turn: { role: "user" | "model"; text: string }) => ({
          role: turn.role,
          parts: [{ text: turn.text.trim().slice(0, 1200) }],
        }))
      : [];

    const { data: limit, error: limitError } = await supabase.rpc("finora_consume_rate_limit", { p_for_action: false });
    if (limitError) throw new Error("Jarvis rate limiting is not available. Apply the latest Supabase migration.");
    if (Number(limit) > 15) return json({ ok: false, message: "Please wait a moment before asking Jarvis again." }, 429);

    const { data: profile, error: profileError } = await supabase
      .from("users")
      .select("id")
      .eq("auth_user_id", authData.user.id)
      .single();
    if (profileError) throw profileError;

    const now = new Date();
    const firstOfThisMonth = new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), 1));
    const monthOffsets = period === "last-month" ? 1 : period === "three-months" ? 2 : 0;
    const start = new Date(Date.UTC(firstOfThisMonth.getUTCFullYear(), firstOfThisMonth.getUTCMonth() - monthOffsets, 1));
    const end = period === "last-month"
      ? firstOfThisMonth
      : new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth() + 1, 1));
    const [{ data: transactions, error: transactionError }, { data: financialProfile, error: financialProfileError }] = await Promise.all([
      supabase.from("transactions")
        .select("type, category, amount, transaction_date")
        .eq("user_id", profile.id)
        .gte("transaction_date", dateText(start))
        .lt("transaction_date", dateText(end)),
      supabase.from("financial_profile")
        .select("currency, monthly_budget")
        .eq("user_id", profile.id)
        .maybeSingle(),
    ]);
    if (transactionError) throw transactionError;
    if (financialProfileError) throw financialProfileError;

    const totals = { income: 0, expenses: 0, expenseCount: 0, categories: {} as Record<string, number> };
    for (const transaction of transactions || []) {
      const amount = Number(transaction.amount);
      if (transaction.type === "income") totals.income += amount;
      if (transaction.type === "expense") {
        totals.expenses += amount;
        totals.expenseCount += 1;
        totals.categories[transaction.category] = (totals.categories[transaction.category] || 0) + amount;
      }
    }
    const snapshot = {
      period,
      start_date: dateText(start),
      end_date_exclusive: dateText(end),
      transaction_count: (transactions || []).length,
      income_total: Number(totals.income.toFixed(2)),
      expense_total: Number(totals.expenses.toFixed(2)),
      net_total: Number((totals.income - totals.expenses).toFixed(2)),
      expense_transaction_count: totals.expenseCount,
      expense_by_category: Object.fromEntries(Object.entries(totals.categories).map(([category, amount]) => [category, Number(amount.toFixed(2))])),
      monthly_budget: Number(financialProfile?.monthly_budget || 0),
      currency: financialProfile?.currency || "INR",
      data_is_limited: (transactions || []).length < 3,
    };

    const apiKey = Deno.env.get("JARVIS_API_KEY");
    if (!apiKey) return json({ ok: false, message: "Jarvis AI is not configured. Set the JARVIS_API_KEY Edge Function secret." }, 503);
    const configuredModel = (Deno.env.get("JARVIS_MODEL") || "gemini-2.5-flash").replace(/:generateContent$/, "");
    const model = /^gemini-[a-z0-9.-]+$/i.test(configuredModel) ? configuredModel : "gemini-2.5-flash";
    const response = await fetch(
      `https://generativelanguage.googleapis.com/v1beta/models/${encodeURIComponent(model)}:generateContent?key=${encodeURIComponent(apiKey)}`,
      {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          systemInstruction: {
            parts: [{
              text: `You are Jarvis, a concise personal finance assistant. Reply in the same language as the user's question (English or Hinglish). Use the conversation history only to understand references and maintain continuity; the current financial snapshot is the source of truth for financial facts. Do not invent transaction details or claim access to individual transactions. Only use numeric values present in the current snapshot or arithmetic directly derived from them. A monthly_budget value of 0 means no budget has been set; do not describe it as a real budget. ${snapshot.data_is_limited ? "The current snapshot has fewer than three transactions; explain that financial data is limited and do not make confident financial claims." : ""} Never request passwords, OTPs, or payment details.`,
            }],
          },
          contents: [
            ...history,
            { role: "user", parts: [{ text: JSON.stringify({ question, financial_snapshot: snapshot }) }] },
          ],
          generationConfig: { maxOutputTokens: 350, temperature: 0.2 },
        }),
      },
    );
    if (!response.ok) {
      console.error("Jarvis provider returned HTTP", response.status);
      return json({ ok: false, message: "Jarvis could not answer right now. Please try again." }, 502);
    }
    const providerResponse = await response.json();
    const reply = providerResponse?.candidates?.[0]?.content?.parts
      ?.map((part: { text?: string }) => part.text || "")
      .join("")
      .trim();
    if (!reply) return json({ ok: false, message: "Jarvis did not return a usable answer. Please try again." }, 502);
    return json({ ok: true, reply, snapshot });
  } catch (error) {
    console.error("Jarvis request failed:", error instanceof Error ? error.message : "Unknown error");
    return json({ ok: false, message: "Jarvis could not answer right now. Please try again." }, 500);
  }
});
