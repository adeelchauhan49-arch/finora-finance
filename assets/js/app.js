document.addEventListener('DOMContentLoaded', () => {
  const supabaseConfig = window.FINORA_SUPABASE_CONFIG || {};
  const proEnabled = supabaseConfig.proEnabled === true;
  const supabaseClient = window.supabase?.createClient
    && typeof supabaseConfig.url === 'string'
    && typeof supabaseConfig.publishableKey === 'string'
    && supabaseConfig.url.startsWith('https://')
    && supabaseConfig.publishableKey !== ''
    ? window.supabase.createClient(supabaseConfig.url, supabaseConfig.publishableKey, {
      auth: { autoRefreshToken: true, persistSession: true, detectSessionInUrl: true }
    })
    : null;
  const storageKey = 'finora-transactions';
  const periodStorageKey = 'finora-selected-period';
  const periodOptions = ['this-month', 'last-month', 'three-months'];
  const storedPeriod = localStorage.getItem(periodStorageKey);
  let selectedDateRange = periodOptions.includes(storedPeriod) ? storedPeriod : 'this-month';
  const reportCustomRangeStorageKey = 'finora-report-custom-range';
  let storedReportCustomRange = null;
  try { storedReportCustomRange = JSON.parse(localStorage.getItem(reportCustomRangeStorageKey) || 'null'); } catch (error) { storedReportCustomRange = null; }
  let reportUsesCustomRange = Boolean(storedReportCustomRange?.start && storedReportCustomRange?.end);
  const currencySymbols = { INR: 'Rs', USD: '$', EUR: '€' };
  const currencyConfig = { code: 'INR', symbol: currencySymbols.INR };
  const defaultBudgetLimit = 2400;
  const defaultCategoryBudgetAmounts = { Housing: 1200, Dining: 400, 'Food & dining': 400, Shopping: 300, Transport: 300, Health: 200, Utilities: 250, Entertainment: 200, Other: 200 };
  const categoryBudgetAllocationKeys = { 'Food & dining': 'Food', Housing: 'Rent', Transport: 'Travel', Utilities: 'Bills', Other: 'Others' };
  const demoBudgetStorageKey = 'finora-budget-preferences';
  let budgetLimit = defaultBudgetLimit;
  let categoryBudgetAllocations = {};
  let cashflowChart;
  let reportsCategoryChart;
  let reportsTrendChart;
  const seededTransactions = [
    { id: 1, merchant: 'Harvest Kitchen', category: 'Food & dining', amount: 42.8, type: 'expense', date: '2026-09-16' },
    { id: 2, merchant: 'Acme Inc.', category: 'Salary', amount: 4850, type: 'income', date: '2026-09-15' },
    { id: 3, merchant: 'Metro Transit', category: 'Transport', amount: 36, type: 'expense', date: '2026-09-14' },
    { id: 4, merchant: 'Northstar Market', category: 'Shopping', amount: 128.46, type: 'expense', date: '2026-09-13' }
  ];
  const sidebar = document.getElementById('sidebar');
  const menuToggle = document.getElementById('menuToggle');
  const themeToggle = document.getElementById('themeToggle');
  const body = document.body;
  const todayDate = new Date();
  const formatLocalDate = (date) => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
  const formatLocalTime = (date) => `${String(date.getHours()).padStart(2, '0')}:${String(date.getMinutes()).padStart(2, '0')}`;
  const currentLocalDateTime = () => {
    const now = new Date();
    return { date: formatLocalDate(now), time: formatLocalTime(now) };
  };
  const today = formatLocalDate(todayDate);
  const applyCurrency = (code) => {
    if (!Object.prototype.hasOwnProperty.call(currencySymbols, code)) return;
    currencyConfig.code = code;
    currencyConfig.symbol = currencySymbols[code];
    document.getElementById('currencySymbol').textContent = currencyConfig.symbol;
    const setupCurrency = document.getElementById('setupCurrency');
    if (setupCurrency) setupCurrency.value = code;
    const settingsCurrency = document.getElementById('settingsCurrency');
    if (settingsCurrency) settingsCurrency.value = code;
  };
  const getSelectedDateRange = () => {
    const currentMonth = new Date(todayDate.getFullYear(), todayDate.getMonth(), 1);
    let start;
    let end;
    let monthCount;
    let label;
    if (selectedDateRange === 'last-month') {
      start = new Date(todayDate.getFullYear(), todayDate.getMonth() - 1, 1);
      end = currentMonth;
      monthCount = 1;
      label = 'Last month';
    } else if (selectedDateRange === 'three-months') {
      start = new Date(todayDate.getFullYear(), todayDate.getMonth() - 2, 1);
      end = new Date(todayDate.getFullYear(), todayDate.getMonth() + 1, 1);
      monthCount = 3;
      label = 'Last 3 months';
    } else {
      start = currentMonth;
      end = new Date(todayDate.getFullYear(), todayDate.getMonth() + 1, 1);
      monthCount = 1;
      label = 'This month';
    }
    return { start: formatLocalDate(start), end: formatLocalDate(end), monthCount, label };
  };
  const storedTransactions = localStorage.getItem(storageKey);
  const localTransactionsBackup = storedTransactions ? JSON.parse(storedTransactions) : [];
  let transactions = storedTransactions ? JSON.parse(storedTransactions) : seededTransactions;
  let monthEndForecast = null;
  let activeUser = null;
  let supabaseUserAuthenticated = false;
  const updateProAvailability = (enabled) => {
    const upgradeCard = document.querySelector('.upgrade-card');
    if (upgradeCard) upgradeCard.hidden = enabled !== true;
  };
  let savingsGoals = [];
  let goalsLoadedFor = null;
  const storedProfile = location.protocol === 'file:'
    ? JSON.parse(localStorage.getItem('finora-profile') || 'null')
    : null;
  try {
    const storedDemoBudget = JSON.parse(localStorage.getItem(demoBudgetStorageKey) || 'null');
    if (storedDemoBudget && Number(storedDemoBudget.monthly_budget) >= 1 && storedDemoBudget.budget_allocations && typeof storedDemoBudget.budget_allocations === 'object') {
      budgetLimit = Number(storedDemoBudget.monthly_budget);
      categoryBudgetAllocations = storedDemoBudget.budget_allocations;
      if (currencySymbols[storedDemoBudget.currency]) currencyConfig.code = storedDemoBudget.currency;
      currencyConfig.symbol = currencySymbols[currencyConfig.code];
    }
  } catch (error) {
    console.warn('Saved demo budget could not be loaded.', error);
  }
  document.getElementById('transactionDate').value = today;
  applyCurrency(currencyConfig.code);
  document.querySelector('.eyebrow').textContent = todayDate.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });

  const money = (value) => `${currencyConfig.symbol} ${Number(value || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
  const readFiniteInput = (input) => {
    if (input.validity.badInput || input.value.trim() === '') return null;
    const value = Number(input.value);
    return Number.isFinite(value) ? value : null;
  };
  const calculateSavingsPercentage = (amount, income) => {
    if (!Number.isFinite(amount) || amount < 0 || !Number.isFinite(income) || income <= 0) return null;
    return Math.min(100, Math.round((amount / income * 100) * 10) / 10);
  };
  const calculateSavingsAmount = (percentage, income) => {
    if (!Number.isFinite(percentage) || !Number.isFinite(income) || income <= 0) return null;
    const clampedPercentage = Math.min(100, Math.max(0, percentage));
    return Math.round(income * clampedPercentage / 100);
  };
  const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[character]);
  const dateLabel = (value) => {
    const date = new Date(`${value}T12:00:00`);
    const yesterday = new Date(`${today}T12:00:00`);
    yesterday.setDate(yesterday.getDate() - 1);
    if (value === today) return 'Today';
    if (value === yesterday.toISOString().slice(0, 10)) return 'Yesterday';
    return date.toLocaleDateString('en-US', { day: 'numeric', month: 'long', year: 'numeric' });
  };
  const timeLabel = (value) => {
    if (!value) return '';
    const [hours, minutes] = value.split(':').map(Number);
    const date = new Date(2000, 0, 1, hours, minutes);
    return date.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
  };
  const iconFor = (category) => ({ 'Food & dining': 'utensils', Shopping: 'bag-shopping', Transport: 'train-subway', Salary: 'building-columns', Other: 'receipt' }[category] || 'receipt');
  let transactionSeries = 'all';
  let transactionsExpanded = false;
  const seriesBar = document.createElement('div');
  seriesBar.className = 'transaction-series';
  seriesBar.setAttribute('role', 'tablist');
  seriesBar.setAttribute('aria-label', 'Transaction series');
  seriesBar.innerHTML = '<button class="series-btn active" data-series="all">All</button><button class="series-btn" data-series="expense">Expenses</button><button class="series-btn" data-series="income">Income</button>';
  document.querySelector('.transactions-panel .panel-heading').after(seriesBar);
  const transactionList = document.getElementById('transactionList');
  const transactionListToggle = document.createElement('button');
  transactionListToggle.type = 'button';
  transactionListToggle.className = 'transaction-list-toggle';
  transactionListToggle.hidden = true;
  transactionListToggle.setAttribute('aria-expanded', 'false');
  transactionList.after(transactionListToggle);
  const classifyMerchant = (merchant) => {
    const terms = {
      'Food & dining': ['swiggy', 'zomato', 'blinkit', 'zepto', 'bigbasket', 'big basket', 'dmart', 'dm art', 'grocery', 'groceries', 'food', 'restaurant', 'dominos', 'domino', 'pizza hut', 'mcdonalds', 'mcdonald', 'kfc', 'subway', 'dunzo', 'instamart', 'jiomart', 'kirana', 'rashan', 'ration', 'sabzi', 'khana', 'nashta'],
      Transport: ['uber', 'ola', 'rapido', 'irctc', 'petrol', 'diesel', 'metro', 'bus', 'auto', 'fuel', 'transport', 'travel', 'taxi', 'train', 'railway', 'namma metro', 'safar', 'gaadi'],
      Utilities: ['jio', 'airtel', 'electricity', 'electric bill', 'water bill', 'utility', 'utilities', 'internet', 'broadband', 'recharge', 'mobile bill', 'gas bill', 'phone bill', 'bijli', 'bijli bill'],
      Housing: ['rent', 'landlord', 'housing', 'home rent', 'maintenance', 'kiraya'],
      Entertainment: ['netflix', 'hotstar', 'disney hotstar', 'movie', 'cinema', 'spotify', 'prime video', 'pvr', 'inox', 'bookmyshow', 'gaming', 'youtube premium', 'picture'],
      Shopping: ['amazon', 'flipkart', 'myntra', 'shopping', 'clothing', 'clothes', 'fashion', 'ajio', 'nykaa', 'meesho', 'croma', 'reliance digital', 'kapde'],
      Health: ['pharmacy', 'doctor', 'clinic', 'hospital', 'medicine', 'medical', 'apollo pharmacy', 'netmeds', '1mg', 'tata 1mg', 'diagnostic', 'dawai', 'dawa']
    };
    const normalize = (text) => String(text).toLocaleLowerCase().replace(/[\p{N}\p{P}\p{S}]+/gu, ' ').replace(/\s+/g, ' ').trim();
    const value = normalize(merchant);
    if (!value) return { category: 'Other', uncertain: true };
    const matchingCategories = Object.entries(terms).filter(([, aliases]) => aliases.some((term) => (` ${value} `).includes(` ${normalize(term)} `))).map(([category]) => category);
    if (matchingCategories.length === 1) return { category: matchingCategories[0], uncertain: false };
    if (matchingCategories.length > 1) return { category: 'Other', uncertain: true };
    const distance = (left, right) => {
      const previous = Array.from({ length: right.length + 1 }, (_, index) => index);
      for (let row = 1; row <= left.length; row += 1) {
        let diagonal = previous[0];
        previous[0] = row;
        for (let column = 1; column <= right.length; column += 1) {
          const above = previous[column];
          previous[column] = Math.min(previous[column] + 1, previous[column - 1] + 1, diagonal + (left[row - 1] === right[column - 1] ? 0 : 1));
          diagonal = above;
        }
      }
      return previous[right.length];
    };
    const candidates = new Set();
    value.split(' ').forEach((word) => {
      if (!/^[a-z]+$/i.test(word) || word.length < 5) return;
      Object.entries(terms).forEach(([category, aliases]) => aliases.forEach((term) => {
        const normalizedTerm = normalize(term);
        if (normalizedTerm.includes(' ') || Math.abs(word.length - normalizedTerm.length) > 2) return;
        const edits = distance(word, normalizedTerm);
        if (edits > 0 && edits <= 2 && edits / Math.max(word.length, normalizedTerm.length) <= 0.4) candidates.add(category);
      }));
    });
    return candidates.size === 1 ? { category: [...candidates][0], source: 'fuzzy', uncertain: false } : { category: 'Other', uncertain: true };
  };
  const getAppUser = async () => {
    if (!supabaseClient) return null;
    const { data: { session }, error: sessionError } = await supabaseClient.auth.getSession();
    if (sessionError) throw sessionError;
    if (!session?.user) return null;
    const { data, error } = await supabaseClient
      .from('users')
      .select('id, name, email, pending_email, email_verified_at')
      .eq('auth_user_id', session.user.id)
      .single();
    if (error) throw new Error(`Could not load your Supabase profile: ${error.message}`);
    return {
      id: Number(data.id),
      name: data.name,
      email: data.email,
      pending_email: data.pending_email,
      email_verified_at: session.user.email_confirmed_at || session.user.confirmed_at || null
    };
  };
  const requireAppUser = async () => {
    const user = await getAppUser();
    if (!user) throw new Error('Sign in to continue.');
    if (!user.email_verified_at) throw new Error('Please verify your email first.');
    return user;
  };
  const unwrapSupabase = ({ data, error }) => {
    if (error) throw error;
    return data;
  };
  const normalizeMerchant = (merchant) => merchant.toLowerCase()
    .replace(/[\p{N}\p{P}\p{S}]+/gu, ' ')
    .replace(/\s+/g, ' ')
    .trim();
  const isValidIsoDate = (value) => {
    if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(value)) return false;
    const date = new Date(`${value}T00:00:00Z`);
    return Number.isFinite(date.getTime()) && date.toISOString().slice(0, 10) === value;
  };
  const forecastMonthEnd = (items, profile) => {
    const now = new Date();
    const today = formatLocalDate(now);
    const monthStart = `${today.slice(0, 8)}01`;
    const dayCount = now.getDate();
    const daysInMonth = new Date(now.getFullYear(), now.getMonth() + 1, 0).getDate();
    const currentExpenses = items.filter((item) => item.type === 'expense' && item.date >= monthStart && item.date <= today);
    const currentSpending = currentExpenses.reduce((sum, item) => sum + Number(item.amount || 0), 0);
    const historyStart = new Date(now.getFullYear(), now.getMonth(), now.getDate() - 89);
    const dailyTotals = new Map();
    items.forEach((item) => {
      if (item.type !== 'expense' || item.date < formatLocalDate(historyStart) || item.date > today) return;
      dailyTotals.set(item.date, (dailyTotals.get(item.date) || 0) + Number(item.amount || 0));
    });
    const dailyValues = [];
    for (let offset = 89; offset >= 0; offset -= 1) {
      const date = new Date(now.getFullYear(), now.getMonth(), now.getDate() - offset);
      dailyValues.push(dailyTotals.get(formatLocalDate(date)) || 0);
    }
    const average = dailyValues.reduce((sum, value) => sum + value, 0) / 90;
    const variance = dailyValues.reduce((sum, value) => sum + (value - average) ** 2, 0) / 90;
    const remainingDays = daysInMonth - dayCount;
    const point = currentSpending + average * remainingDays;
    const uncertainty = Math.sqrt(variance) * Math.sqrt(remainingDays);
    const monthlyBudget = Number(profile?.monthly_budget) || defaultBudgetLimit;
    const currency = profile?.currency || 'INR';
    const symbol = currencySymbols[currency] || currency;
    const difference = Math.abs(point - monthlyBudget);
    const available = dayCount >= 14 && currentExpenses.length >= 10;
    return {
      available,
      insufficient_data_message: 'A month-end forecast needs at least 14 days and 10 expenses this month.',
      low: Math.max(0, point - 1.96 * uncertainty),
      high: point + 1.96 * uncertainty,
      point,
      monthly_budget: monthlyBudget,
      days_elapsed: dayCount,
      expense_transaction_count: currentExpenses.length,
      confidence: dayCount >= 27 && currentExpenses.length >= 40 ? 'High' : dayCount >= 21 && currentExpenses.length >= 20 ? 'Medium' : 'Low',
      budget_comparison_text: point > monthlyBudget
        ? `At this pace, spending is projected to be ${symbol} ${difference.toFixed(2)} over the monthly budget of ${symbol} ${monthlyBudget.toFixed(2)}.`
        : `At this pace, about ${symbol} ${difference.toFixed(2)} of the monthly budget of ${symbol} ${monthlyBudget.toFixed(2)} may remain.`
    };
  };
  const invokeSupabaseFunction = async (name, body) => {
    const { data, error } = await supabaseClient.functions.invoke(name, { body });
    if (error) {
      let message = error.message || `The ${name} function failed.`;
      if (error.context?.json) {
        const payload = await error.context.json().catch(() => null);
        if (payload?.message) message = payload.message;
      }
      throw new Error(message);
    }
    if (!data?.ok) throw new Error(data?.message || `The ${name} function returned an invalid response.`);
    return data;
  };
  const supabaseRequest = async (resource, options = {}) => {
    const method = options.method || 'GET';
    const body = typeof options.body === 'string' ? JSON.parse(options.body) : (options.body || {});
    if (!supabaseClient && location.protocol !== 'file:') throw new Error('Supabase is not configured.');

    if (resource === 'session') {
      const user = await getAppUser();
      if (!user) return { ok: true, user: null, pro_enabled: proEnabled };
      if (!user.email_verified_at) {
        return { ok: true, user: null, requires_verification: true, email: user.email, pro_enabled: proEnabled };
      }
      return { ok: true, user, pro_enabled: proEnabled };
    }

    if (resource === 'jarvis') return invokeSupabaseFunction('jarvis', body);
    if (resource === 'jarvis-action') return invokeSupabaseFunction('jarvis-action', body);
    const user = await requireAppUser();

    if (resource === 'category-suggestion') {
      const merchant = String(body.merchant || '').trim();
      if (!merchant || merchant.length > 160) throw new Error('Enter a valid merchant or transaction title.');
      const normalized = normalizeMerchant(merchant);
      const { data, error } = await supabaseClient.from('user_category_rules')
        .select('category').eq('user_id', user.id).eq('merchant_normalized', normalized).maybeSingle();
      if (error) throw error;
      const suggestion = data
        ? { category: data.category, source: 'personal', uncertain: false }
        : classifyMerchant(merchant);
      return { ok: true, suggestion: { ...suggestion, learning_available: true } };
    }

    if (resource === 'transactions') {
      if (method === 'GET') {
        const [transactions, profile] = await Promise.all([
          supabaseClient.from('transactions')
            .select('id, type, merchant, category, amount, transaction_date, transaction_time, payment_method, location, notes, category_confidence')
            .eq('user_id', user.id)
            .order('transaction_date', { ascending: false })
            .order('transaction_time', { ascending: false })
            .order('id', { ascending: false }),
          supabaseClient.from('financial_profile').select('monthly_budget, currency').eq('user_id', user.id).maybeSingle()
        ]);
        const rows = unwrapSupabase(transactions);
        const profileData = unwrapSupabase(profile);
        const items = rows.map((item) => ({
          id: Number(item.id),
          type: item.type,
          merchant: item.merchant,
          category: item.category,
          amount: Number(item.amount),
          date: item.transaction_date,
          time: item.transaction_time || '',
          payment_method: item.payment_method || '',
          location: item.location || '',
          notes: item.notes || '',
          category_confidence: Number(item.category_confidence || 0)
        }));
        return { ok: true, transactions: items, month_end_forecast: forecastMonthEnd(items, profileData) };
      }
      if (method === 'POST') {
        const categoryConfidence = Number(body.category_confidence || 0);
        const validDate = isValidIsoDate(body.date);
        const values = {
          user_id: user.id,
          type: body.type,
          merchant: String(body.merchant || '').trim(),
          category: String(body.category || 'Other').trim() || 'Other',
          amount: Number(body.amount),
          transaction_date: body.date,
          transaction_time: body.time || null,
          payment_method: body.payment_method || null,
          location: String(body.location || '').trim() || null,
          notes: String(body.notes || '').trim() || null,
          category_confidence: Math.max(0, Math.min(100, categoryConfidence)),
          source: 'manual'
        };
        if (!['income', 'expense'].includes(values.type)
          || !values.merchant || values.merchant.length > 160
          || !['Food & dining', 'Shopping', 'Transport', 'Housing', 'Health', 'Utilities', 'Entertainment', 'Other', 'Salary'].includes(values.category)
          || (values.category === 'Salary' && values.type !== 'income')
          || !Number.isFinite(values.amount) || values.amount <= 0 || values.amount > 9999999999.99
          || !validDate
          || (values.transaction_time && !/^([01]\d|2[0-3]):[0-5]\d$/.test(values.transaction_time))
          || (values.payment_method && !['cash', 'upi', 'credit_card', 'debit_card', 'wallet', 'bank_transfer'].includes(values.payment_method))
          || (values.location && values.location.length > 160)
          || (values.notes && values.notes.length > 500)
          || !Number.isFinite(categoryConfidence)
          || !Number.isFinite(values.category_confidence)) {
          throw new Error('Enter valid transaction details.');
        }
        const { data, error } = await supabaseClient.from('transactions')
          .insert(values)
          .select('id, type, merchant, category, amount, transaction_date, transaction_time, payment_method, location, notes, category_confidence')
          .single();
        if (error) throw error;
        let categoryLearning = null;
        if (body.category_explicit === true) {
          try {
            const normalized = normalizeMerchant(values.merchant);
            const { data: existingRule, error: existingError } = await supabaseClient.from('user_category_rules')
              .select('id').eq('user_id', user.id).eq('merchant_normalized', normalized).maybeSingle();
            if (existingError) throw existingError;
            const { count, error: countError } = existingRule
              ? { count: 0, error: null }
              : await supabaseClient.from('user_category_rules')
                .select('id', { count: 'exact', head: true }).eq('user_id', user.id);
            if (countError) throw countError;
            if (!existingRule && count >= 500) {
              categoryLearning = { saved: false, reason: 'limit_reached' };
            } else {
              const { error: ruleError } = await supabaseClient.from('user_category_rules').upsert({
                user_id: user.id, merchant_normalized: normalized, category: values.category
              }, { onConflict: 'user_id,merchant_normalized' });
              if (ruleError) throw ruleError;
              categoryLearning = { saved: true, reason: 'saved' };
            }
          } catch (error) {
            console.warn('Transaction saved, but the merchant preference could not be saved.', error);
            categoryLearning = { saved: false, reason: 'save_failed' };
          }
        }
        return {
          ok: true,
          transaction: { ...values, id: Number(data.id), amount: Number(data.amount), date: data.transaction_date, time: data.transaction_time || '' },
          category_learning: categoryLearning
        };
      }
      if (method === 'DELETE') {
        const { data, error } = await supabaseClient.from('transactions').delete()
          .eq('id', body.id).eq('user_id', user.id).select('id');
        if (error) throw error;
        if (!data.length) throw new Error('Transaction was not found.');
        return { ok: true, message: 'Transaction deleted.' };
      }
    }

    if (resource === 'onboarding') {
      if (method === 'GET') {
        const [profile, investments, goals] = await Promise.all([
          supabaseClient.from('financial_profile').select('*').eq('user_id', user.id).maybeSingle(),
          supabaseClient.from('investment_plan').select('monthly_amount, investment_type').eq('user_id', user.id).order('id'),
          supabaseClient.from('savings_goals').select('id, title, target_amount, current_amount, deadline')
            .eq('user_id', user.id).order('deadline', { ascending: true, nullsFirst: false }).limit(20)
        ]);
        return {
          ok: true,
          profile: unwrapSupabase(profile),
          investment_plans: unwrapSupabase(investments) || [],
          savings_goals: unwrapSupabase(goals) || []
        };
      }
      if (method === 'POST') {
        const { error } = await supabaseClient.rpc('finora_save_onboarding', {
          p_name: body.full_name || '',
          p_profile: body,
          p_investments: body.investment_types || []
        });
        if (error) throw error;
        return { ok: true, message: 'Your financial setup is saved.' };
      }
    }

    if (resource === 'settings') {
      if (method === 'GET') {
        const [profile, userRecord] = await Promise.all([
          supabaseClient.from('financial_profile').select('*').eq('user_id', user.id).maybeSingle(),
          supabaseClient.from('users').select('id, name, email, pending_email').eq('id', user.id).single()
        ]);
        const settings = unwrapSupabase(profile) || {};
        return {
          ok: true,
          user: unwrapSupabase(userRecord),
          settings: {
            currency: settings.currency || 'INR',
            monthly_budget: Number(settings.monthly_budget || defaultBudgetLimit),
            budget_allocations: settings.budget_allocations || {},
            jarvis_auto_add_enabled: settings.jarvis_auto_add_enabled === true,
            jarvis_auto_add_limit: Number(settings.jarvis_auto_add_limit || 5000),
            monthly_salary: Number(settings.monthly_salary || 0),
            business_income: Number(settings.business_income || 0),
            freelance_income: Number(settings.freelance_income || 0),
            other_income: Number(settings.other_income || 0),
            monthly_income: Number(settings.monthly_income || 0),
            monthly_savings_goal: Number(settings.monthly_savings_goal || 0),
            savings_percentage: Number(settings.savings_percentage || 0)
          }
        };
      }
      if (method === 'POST') {
        if (body.action === 'profile') {
          const name = String(body.name || '').trim();
          if (name.length < 2 || name.length > 120) throw new Error('Enter a valid name.');
          if (String(body.email || '').toLowerCase() !== user.email.toLowerCase()) {
            throw new Error('Change your email through Supabase Auth, then save your profile name.');
          }
          const { error } = await supabaseClient.from('users').update({ name }).eq('id', user.id);
          if (error) throw error;
          return { ok: true, user: { ...user, name }, message: 'Saved successfully.', verification_required: false };
        }
        if (body.action === 'financial_profile') {
          const fields = ['monthly_salary', 'business_income', 'freelance_income', 'other_income'];
          const values = Object.fromEntries(fields.map((key) => [key, Number(body[key])]));
          const savingsGoal = Number(body.monthly_savings_goal);
          const savingsPercentage = Number(body.savings_percentage);
          if (Object.values(values).some((value) => !Number.isFinite(value) || value < 0 || value > 9999999999.99)
            || !Number.isFinite(savingsGoal) || savingsGoal < 0 || savingsGoal > 9999999999.99
            || !Number.isFinite(savingsPercentage) || savingsPercentage < 0 || savingsPercentage > 100) {
            throw new Error('Enter a valid savings goal and savings percentage from 0 to 100.');
          }
          const monthlyIncome = Object.values(values).reduce((sum, value) => sum + value, 0);
          const { error } = await supabaseClient.from('financial_profile').upsert({
            user_id: user.id, ...values, monthly_income: monthlyIncome,
            monthly_savings_goal: savingsGoal, savings_percentage: savingsPercentage
          }, { onConflict: 'user_id' });
          if (error) throw error;
          return { ok: true, settings: { ...values, monthly_income: monthlyIncome, monthly_savings_goal: savingsGoal, savings_percentage: savingsPercentage }, message: 'Financial profile saved successfully.' };
        }
        if (body.action === 'preferences') {
          const currency = String(body.currency || '').toUpperCase();
          const monthlyBudget = Number(body.monthly_budget);
          const categoryLimits = body.category_limits;
          if (!['INR', 'USD', 'EUR'].includes(currency) || !Number.isFinite(monthlyBudget)
            || monthlyBudget < 1 || monthlyBudget > 9999999999.99
            || !categoryLimits || typeof categoryLimits !== 'object') {
            throw new Error('Enter a valid currency and monthly budget.');
          }
          const allocations = Object.fromEntries(Object.entries(categoryLimits).map(([category, amount]) => {
            const limit = Number(amount);
            if (!category.trim() || category.length > 80 || !Number.isFinite(limit) || limit < 0.01 || limit > 9999999999.99) {
              throw new Error('Enter valid limits for every category.');
            }
            return [category, (limit / monthlyBudget) * 100];
          }));
          const { error } = await supabaseClient.from('financial_profile').upsert({
            user_id: user.id, currency, monthly_budget: monthlyBudget, budget_allocations: allocations
          }, { onConflict: 'user_id' });
          if (error) throw error;
          return { ok: true, settings: { currency, monthly_budget: monthlyBudget, budget_allocations: allocations }, message: 'Saved successfully.' };
        }
        if (body.action === 'jarvis_actions') {
          const enabled = body.jarvis_auto_add_enabled;
          const limit = Number(body.jarvis_auto_add_limit);
          if (typeof enabled !== 'boolean' || !Number.isFinite(limit) || limit < 1 || limit > 9999999999.99) {
            throw new Error('Choose whether to enable auto-add and enter a valid transaction limit.');
          }
          const { error } = await supabaseClient.from('financial_profile').upsert({
            user_id: user.id, jarvis_auto_add_enabled: enabled, jarvis_auto_add_limit: limit
          }, { onConflict: 'user_id' });
          if (error) throw error;
          return { ok: true, settings: { jarvis_auto_add_enabled: enabled, jarvis_auto_add_limit: limit }, message: 'Jarvis action settings saved.' };
        }
      }
    }

    if (resource === 'goals') {
      if (method === 'GET') {
        const { data: goals, error } = await supabaseClient.from('savings_goals')
          .select('id, title, target_amount, current_amount, deadline')
          .eq('user_id', user.id).order('deadline', { ascending: true, nullsFirst: false }).order('id', { ascending: false });
        if (error) throw error;
        const { data: contributions, error: contributionError } = await supabaseClient.from('savings_goal_contributions')
          .select('id, goal_id, amount, contribution_date').eq('user_id', user.id)
          .order('contribution_date', { ascending: false }).order('id', { ascending: false });
        if (contributionError) throw contributionError;
        const grouped = new Map();
        contributions.forEach((item) => {
          const key = Number(item.goal_id);
          if (!grouped.has(key)) grouped.set(key, []);
          grouped.get(key).push({ ...item, id: Number(item.id), goal_id: key, amount: Number(item.amount) });
        });
        return {
          ok: true,
          goals: goals.map((goal) => ({
            ...goal, id: Number(goal.id), target_amount: Number(goal.target_amount),
            current_amount: Number(goal.current_amount), contributions: grouped.get(Number(goal.id)) || []
          }))
        };
      }
      if (method === 'POST' && (body.action || 'goal') === 'goal') {
        const title = String(body.title || '').trim();
        const target = Number(body.target_amount);
        const starting = Number(body.starting_amount || 0);
        if (!title || title.length > 120 || !Number.isFinite(target) || target <= 0 || target > 9999999999.99
          || !Number.isFinite(starting) || starting < 0 || starting > target || !isValidIsoDate(body.deadline)) {
          throw new Error('Enter a valid goal name, target, starting amount, and target date.');
        }
        const { data, error } = await supabaseClient.from('savings_goals').insert({
          user_id: user.id, title, target_amount: target, current_amount: starting, deadline: body.deadline
        }).select('id').single();
        if (error) throw error;
        return { ok: true, goal_id: Number(data.id), message: 'Savings goal created.' };
      }
      if (method === 'POST' && body.action === 'contribution') {
        const amount = Number(body.amount);
        if (!Number.isInteger(Number(body.goal_id)) || Number(body.goal_id) < 1
          || !Number.isFinite(amount) || amount <= 0 || amount > 9999999999.99
          || !isValidIsoDate(body.date)) {
          throw new Error('Enter a valid contribution amount and date.');
        }
        const { data, error } = await supabaseClient.from('savings_goal_contributions').insert({
          user_id: user.id, goal_id: body.goal_id, amount, contribution_date: body.date
        }).select('id').single();
        if (error) throw error;
        return { ok: true, contribution_id: Number(data.id), message: 'Contribution added.' };
      }
      if (method === 'PATCH') {
        const { data, error } = await supabaseClient.from('savings_goals').update({
          title: String(body.title || '').trim(), target_amount: Number(body.target_amount), deadline: body.deadline
        }).eq('id', body.id).eq('user_id', user.id).select('id');
        if (error) throw error;
        if (!data.length) throw new Error('Savings goal was not found.');
        return { ok: true, message: 'Savings goal updated.' };
      }
      if (method === 'DELETE') {
        if (body.action === 'contribution') {
          const { data, error } = await supabaseClient.rpc('finora_delete_goal_contribution', { p_contribution_id: Number(body.id) });
          if (error) throw error;
          if (!data) throw new Error('Contribution was not found.');
        } else {
          const { data, error } = await supabaseClient.from('savings_goals').delete()
            .eq('id', body.id).eq('user_id', user.id).select('id');
          if (error) throw error;
          if (!data.length) throw new Error('Savings goal was not found.');
        }
        return { ok: true, message: body.action === 'contribution' ? 'Contribution deleted.' : 'Savings goal deleted.' };
      }
    }

    throw new Error(`Unsupported Supabase operation: ${resource}.`);
  };
  const getMerchantSuggestion = async (merchant) => {
    if (!supabaseUserAuthenticated || location.protocol === 'file:') return classifyMerchant(merchant);
    try {
      const result = await supabaseRequest('category-suggestion', { method: 'POST', body: JSON.stringify({ merchant }) });
      return result.suggestion;
    } catch (error) {
      console.warn('Personalized category suggestion unavailable; using the local dictionary.', error.message);
      return classifyMerchant(merchant);
    }
  };
  const setProfile = (user) => {
    activeUser = user;
    document.getElementById('userAvatar').textContent = user.name.split(' ').map((part) => part[0]).join('').slice(0, 2).toUpperCase();
    document.getElementById('profileDropdownName').textContent = user.name;
    document.getElementById('profileDropdownEmail').textContent = user.email;
    const greeting = document.querySelector('.topbar h1');
    const wave = document.createElement('span');
    wave.className = 'wave';
    wave.textContent = '✦';
    greeting.replaceChildren(document.createTextNode(`Good morning, ${user.name.split(' ')[0]} `), wave);
    document.getElementById('sidebarUserName').textContent = user.name;
    document.querySelector('.signin-btn').textContent = 'Sign out';
    document.querySelector('.signin-btn').removeAttribute('data-bs-toggle');
    document.querySelector('.signin-btn').removeAttribute('data-bs-target');
  };
  const onboardingModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('onboardingModal'));
  const wizardSteps = [...document.querySelectorAll('.wizard-step')];
  let wizardIndex = 0;
  let allocationChart;
  const allocationNames = ['Food', 'Travel', 'Shopping', 'Rent', 'Bills', 'Entertainment', 'Education', 'Health', 'Family', 'Others'];
  const investmentNames = ['SIP', 'Mutual Funds', 'Stocks', 'Gold', 'Crypto', 'Fixed Deposit', 'PPF'];
  const firstWizardStep = document.querySelector('.wizard-step[data-step="1"]');
  const profileFields = document.createElement('div');
  profileFields.className = 'wizard-profile-fields';
  profileFields.innerHTML = '<div class="row g-3 mb-3"><div class="col-md-6"><label class="form-label" for="setupFullName">Full name</label><input class="form-control" id="setupFullName" placeholder="Your full name"></div><div class="col-md-3"><label class="form-label" for="setupAge">Age</label><input class="form-control" id="setupAge" type="number" min="13" max="120" placeholder="Age"></div><div class="col-md-3"><label class="form-label" for="setupCurrency">Currency</label><select class="form-select" id="setupCurrency"><option value="INR">INR Rs</option><option value="USD">USD $</option><option value="EUR">EUR €</option></select></div><div class="col-md-6"><label class="form-label" for="setupOccupation">Occupation</label><input class="form-control" id="setupOccupation" placeholder="e.g. Product designer"></div><div class="col-md-6"><label class="form-label" for="setupCity">City</label><input class="form-control" id="setupCity" placeholder="e.g. Mumbai"></div></div><hr class="wizard-divider">';
  firstWizardStep.insertBefore(profileFields, firstWizardStep.querySelector('.row'));
  const goalStep = document.querySelector('.wizard-step[data-step="2"]');
  const goalFields = document.createElement('div');
  goalFields.className = 'wizard-goal-fields';
  goalFields.innerHTML = '<div class="row g-3 mb-3"><div class="col-md-6"><label class="form-label" for="setupInvestmentTarget">Monthly investment target</label><input class="form-control" id="setupInvestmentTarget" type="number" min="0" step="0.01" value="0"></div><div class="col-md-6"><label class="form-label" for="setupDreamGoal">Dream goal</label><input class="form-control" id="setupDreamGoal" placeholder="e.g. Car, vacation, phone"></div></div>';
  goalStep.insertBefore(goalFields, goalStep.querySelector('.row'));
  const investmentShareHint = document.createElement('small');
  investmentShareHint.className = 'onboarding-live-copy';
  investmentShareHint.id = 'setupInvestmentShareHint';
  investmentShareHint.setAttribute('aria-live', 'polite');
  goalFields.querySelector('#setupInvestmentTarget').insertAdjacentElement('afterend', investmentShareHint);
  const savingsFeedback = document.createElement('div');
  savingsFeedback.className = 'onboarding-savings-feedback';
  const savingsIncomeHint = document.createElement('small');
  savingsIncomeHint.className = 'onboarding-live-copy';
  savingsIncomeHint.id = 'setupSavingsIncomeHint';
  savingsIncomeHint.textContent = 'Enter your monthly income in step 1 to calculate this automatically.';
  savingsIncomeHint.hidden = true;
  const savingsSummary = document.createElement('small');
  savingsSummary.className = 'onboarding-live-copy';
  savingsSummary.id = 'setupSavingsSummary';
  savingsSummary.setAttribute('aria-live', 'polite');
  const incomeGoalWarning = document.createElement('small');
  incomeGoalWarning.className = 'onboarding-warning';
  incomeGoalWarning.id = 'setupIncomeGoalWarning';
  incomeGoalWarning.textContent = 'Your savings and investment goals are more than your income.';
  incomeGoalWarning.hidden = true;
  const emergencyFundWarning = document.createElement('small');
  emergencyFundWarning.className = 'onboarding-warning';
  emergencyFundWarning.id = 'setupEmergencyFundWarning';
  emergencyFundWarning.hidden = true;
  savingsFeedback.append(savingsIncomeHint, savingsSummary, incomeGoalWarning, emergencyFundWarning);
  goalFields.nextElementSibling.insertAdjacentElement('afterend', savingsFeedback);
  document.querySelector('.choice-grid').innerHTML = investmentNames.map((name) => `<label><input type="checkbox" value="${name}"><span>${name}</span></label>`).join('');
  document.querySelector('.allocation-grid').innerHTML = allocationNames.map((name) => `<label><span>${name}</span><input class="allocation-value" data-category="${name}" type="number" min="0" max="100" value="10"></label>`).join('');
  const refreshAllocationChart = () => {
    const values = [...document.querySelectorAll('.allocation-value')].map((input) => Number(input.value) || 0);
    if (allocationChart) allocationChart.destroy();
    allocationChart = new Chart(document.getElementById('allocationChart'), { type: 'doughnut', data: { labels: allocationNames, datasets: [{ data: values, backgroundColor: ['#b8e951', '#a9efcf', '#ffb876', '#ddd3ff', '#9bc9ed', '#f2b2d6', '#f6dc85', '#b7d7bf', '#dfc29a', '#bcc5cc'], borderWidth: 0 }] }, options: { cutout: '68%', plugins: { legend: { position: 'bottom', labels: { boxWidth: 8, font: { size: 9 } } } } } });
  };
  const showWizardStep = (index) => {
    wizardIndex = Math.max(0, Math.min(wizardSteps.length - 1, index));
    wizardSteps.forEach((step, stepIndex) => step.classList.toggle('active', stepIndex === wizardIndex));
    document.getElementById('wizardProgress').style.width = `${((wizardIndex + 1) / wizardSteps.length) * 100}%`;
    document.getElementById('wizardStepLabel').textContent = `${wizardIndex + 1} of ${wizardSteps.length}`;
    document.getElementById('wizardBack').classList.toggle('invisible', wizardIndex === 0);
    document.getElementById('wizardNext').classList.toggle('d-none', wizardIndex === wizardSteps.length - 1);
    document.getElementById('wizardSave').classList.toggle('d-none', wizardIndex !== wizardSteps.length - 1);
    if (wizardIndex === 1) updateOnboardingGoalHints();
    if (wizardIndex === 3) refreshAllocationChart();
  };
  const finishSetupCard = document.getElementById('finishSetupCard');
  const finishSetupDescription = finishSetupCard.querySelector('p');
  const onboardingPromptedKey = (userId) => `finora-onboarding-prompted-${userId}`;
  const finishSetupDismissedKey = (userId) => `finora-onboarding-card-dismissed-${userId}`;
  let onboardingIsComplete = false;
  let shouldShowFinishSetupCard = false;
  const applyOnboardingProfile = (profile, investmentPlans = []) => {
    const saved = profile || {};
    document.getElementById('setupFullName').value = activeUser?.name || '';
    document.getElementById('setupAge').value = saved.age || '';
    document.getElementById('setupOccupation').value = saved.occupation || '';
    document.getElementById('setupCity').value = saved.city || '';
    applyCurrency(saved.currency || currencyConfig.code);
    const detailedIncome = Number(saved.monthly_salary || 0) + Number(saved.business_income || 0)
      + Number(saved.freelance_income || 0) + Number(saved.other_income || 0);
    const legacyIncome = Number(saved.monthly_income || 0);
    document.getElementById('setupSalary').value = detailedIncome === 0 && legacyIncome > 0
      ? legacyIncome
      : (saved.monthly_salary ?? 0);
    document.getElementById('setupBusiness').value = saved.business_income ?? 0;
    document.getElementById('setupFreelance').value = saved.freelance_income ?? 0;
    document.getElementById('setupOther').value = saved.other_income ?? 0;
    document.getElementById('setupSavings').value = saved.monthly_savings_goal ?? 0;
    const savedIncome = detailedIncome === 0 && legacyIncome > 0 ? legacyIncome : detailedIncome;
    document.getElementById('setupSavingsPct').value = savedIncome > 0 ? (saved.savings_percentage ?? '') : '';
    document.getElementById('setupEmergency').value = saved.emergency_fund_target ?? 0;
    document.getElementById('setupDeadline').value = saved.goal_deadline || '';
    document.getElementById('setupDreamGoal').value = saved.dream_goal || '';
    document.getElementById('setupInvestmentTarget').value = saved.monthly_investment_target ?? 0;
    document.getElementById('setupInvestment').value = investmentPlans[0]?.monthly_amount ?? 0;
    const selectedInvestments = new Set(investmentPlans.map((plan) => plan.investment_type));
    document.querySelectorAll('.choice-grid input').forEach((input) => { input.checked = selectedInvestments.has(input.value); });
    document.querySelectorAll('.allocation-value').forEach((input) => {
      if (saved.budget_allocations && Object.prototype.hasOwnProperty.call(saved.budget_allocations, input.dataset.category)) {
        input.value = saved.budget_allocations[input.dataset.category];
      }
    });
    document.getElementById('setupLifestyle').value = saved.lifestyle || 'working_professional';
    document.querySelectorAll('input[name="setupStyle"]').forEach((input) => {
      input.checked = input.value === (saved.spending_style || 'balanced');
    });
    lastSavingsSource = 'amount';
    document.getElementById('setupIncomeTotal').textContent = money(savedIncome);
    updateOnboardingGoalHints();
  };
  const fetchOnboardingData = async () => {
    const result = await supabaseRequest('onboarding');
    applyOnboardingProfile(result.profile, result.investment_plans || []);
    if (result.profile) {
      budgetLimit = Number(result.profile.monthly_budget) > 0 ? Number(result.profile.monthly_budget) : defaultBudgetLimit;
      categoryBudgetAllocations = result.profile.budget_allocations && typeof result.profile.budget_allocations === 'object'
        ? result.profile.budget_allocations
        : {};
      updateSummary();
    }
    return result;
  };
  const openFinancialProfile = async () => {
    if (!activeUser || !supabaseUserAuthenticated) return;
    try {
      shouldShowFinishSetupCard = false;
      finishSetupCard.hidden = true;
      await fetchOnboardingData();
      showWizardStep(0);
      onboardingModal.show();
    } catch (error) {
      alert(error.message || 'Could not load your financial profile.');
    }
  };
  const showOnboardingIfNeeded = async () => {
    if (!activeUser || !supabaseUserAuthenticated || location.protocol === 'file:') return;
    try {
      const result = await fetchOnboardingData();
      onboardingIsComplete = Boolean(result.profile?.onboarding_completed_at);
      finishSetupDescription.textContent = 'Your setup is not complete yet. You can continue whenever you’re ready.';
      if (onboardingIsComplete) {
        shouldShowFinishSetupCard = false;
        finishSetupCard.hidden = true;
        return;
      }
      if (localStorage.getItem(onboardingPromptedKey(activeUser.id))) {
        shouldShowFinishSetupCard = !sessionStorage.getItem(finishSetupDismissedKey(activeUser.id));
        finishSetupCard.hidden = !shouldShowFinishSetupCard || Boolean(location.hash && location.hash !== '#dashboard');
        return;
      }
      localStorage.setItem(onboardingPromptedKey(activeUser.id), 'shown');
      shouldShowFinishSetupCard = false;
      finishSetupCard.hidden = true;
      showWizardStep(0);
      onboardingModal.show();
    } catch (error) {
      console.error('Could not determine onboarding completion status.', error);
      finishSetupDescription.textContent = 'Your financial profile could not be loaded. Check your Supabase migrations and try again.';
      shouldShowFinishSetupCard = true;
      finishSetupCard.hidden = Boolean(location.hash && location.hash !== '#dashboard');
    }
  };
  const loadSupabaseTransactions = async () => {
    const result = await supabaseRequest('transactions');
    transactions = result.transactions;
    monthEndForecast = result.month_end_forecast;
    renderTransactions();
    updateSummary();
    if (location.hash === '#settings' && settingsLoadedUserId === activeUser?.id) renderSettingsCategoryLimits();
  };
  const syncLocalTransactions = async () => {
    const pendingTransactions = localStorage.getItem(storageKey) ? JSON.parse(localStorage.getItem(storageKey)) : localTransactionsBackup;
    if (!activeUser || location.protocol === 'file:' || !pendingTransactions.length || localStorage.getItem(`finora-sync-${activeUser.id}`)) return;
    for (const item of pendingTransactions) {
      await supabaseRequest('transactions', { method: 'POST', body: JSON.stringify({ type: item.type, merchant: item.merchant, category: item.category || 'Other', amount: item.amount, date: item.date, time: item.time || '', payment_method: item.payment_method || '', location: item.location || '', notes: item.notes || '', category_confidence: item.category_confidence || 0 }) });
    }
    localStorage.setItem(`finora-sync-${activeUser.id}`, 'complete');
    localStorage.removeItem(storageKey);
    await loadSupabaseTransactions();
  };

  const renderTransactions = () => {
    const range = getSelectedDateRange();
    const visibleTransactions = transactions.filter((item) => item.date >= range.start && item.date < range.end && (transactionSeries === 'all' || item.type === transactionSeries)).sort((a, b) => b.date.localeCompare(a.date) || (b.time || '').localeCompare(a.time || '') || b.id - a.id);
    const shownTransactions = transactionsExpanded ? visibleTransactions : visibleTransactions.slice(0, 5);
    const grouped = shownTransactions.reduce((groups, item) => {
      (groups[item.date] ||= []).push(item);
      return groups;
    }, {});

    transactionList.replaceChildren();
    if (!visibleTransactions.length) {
      const emptyState = document.createElement('div');
      emptyState.className = 'timeline-empty';
      const icon = document.createElement('i');
      icon.className = 'fa-solid fa-receipt';
      icon.setAttribute('aria-hidden', 'true');
      const message = document.createElement('span');
      message.textContent = 'No transactions this period.';
      emptyState.append(icon, message);
      transactionList.append(emptyState);
    } else {
      Object.entries(grouped).forEach(([date, items]) => {
        const dateGroup = document.createElement('div');
        dateGroup.className = 'date-group';
        const dateLabelElement = document.createElement('div');
        dateLabelElement.className = 'date-group-label';
        const dateText = document.createElement('span');
        dateText.textContent = dateLabel(date);
        const dateIcon = document.createElement('i');
        dateIcon.className = 'fa-solid fa-chevron-down';
        dateIcon.setAttribute('aria-hidden', 'true');
        dateLabelElement.append(dateText, dateIcon);
        dateGroup.append(dateLabelElement);

        items.forEach((item) => {
          const row = document.createElement('div');
          row.className = 'transaction-row timeline-transaction';
          const iconWrap = document.createElement('span');
          iconWrap.className = `transaction-icon ${item.type === 'income' ? 'income-icon' : 'food'}`;
          const icon = document.createElement('i');
          icon.className = `fa-solid fa-${iconFor(item.category)}`;
          icon.setAttribute('aria-hidden', 'true');
          iconWrap.append(icon);

          const nameWrap = document.createElement('div');
          nameWrap.className = 'transaction-name';
          const name = document.createElement('strong');
          const type = document.createElement('span');
          type.className = `transaction-type ${item.type}`;
          type.textContent = item.type === 'income' ? 'Income' : 'Expense';
          name.append(type, document.createTextNode(item.merchant));
          const details = document.createElement('small');
          details.textContent = `${item.category}${item.time ? ` · ${timeLabel(item.time)}` : ''}${item.notes ? ` · ${item.notes}` : ''}`;
          nameWrap.append(name, details);

          const amount = document.createElement('strong');
          amount.className = `amount ${item.type === 'income' ? 'income-amount' : 'expense-amount'}`;
          amount.textContent = `${item.type === 'income' ? '+' : '-'}${money(item.amount)}`;
          const deleteButton = document.createElement('button');
          deleteButton.className = 'delete-transaction';
          deleteButton.type = 'button';
          deleteButton.dataset.transactionId = String(item.id);
          deleteButton.setAttribute('aria-label', `Delete ${item.merchant}`);
          deleteButton.title = 'Delete transaction';
          const deleteIcon = document.createElement('i');
          deleteIcon.className = 'fa-solid fa-trash-can';
          deleteIcon.setAttribute('aria-hidden', 'true');
          deleteButton.append(deleteIcon);
          row.append(iconWrap, nameWrap, amount, deleteButton);
          dateGroup.append(row);
        });
        transactionList.append(dateGroup);
      });
    }

    transactionListToggle.hidden = visibleTransactions.length <= 5;
    transactionListToggle.setAttribute('aria-expanded', String(transactionsExpanded));
    transactionListToggle.textContent = transactionsExpanded ? 'Show less' : `View all (${visibleTransactions.length})`;
  };
  transactionListToggle.addEventListener('click', () => {
    transactionsExpanded = !transactionsExpanded;
    renderTransactions();
    if (!transactionsExpanded) {
      document.querySelector('.transactions-panel').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  });

  const categoryLimitFor = (category, monthCount) => {
    const legacyKey = categoryBudgetAllocationKeys[category];
    const allocationKey = [category, legacyKey].find((key) => key && Object.prototype.hasOwnProperty.call(categoryBudgetAllocations, key));
    if (allocationKey) {
      const allocation = Number(categoryBudgetAllocations[allocationKey]);
      if (Number.isFinite(allocation) && allocation >= 0) return budgetLimit * allocation / 100 * monthCount;
    }
    const defaultAmount = defaultCategoryBudgetAmounts[category] ?? defaultBudgetLimit * 0.1;
    return (budgetLimit * defaultAmount / defaultBudgetLimit) * monthCount;
  };
  const getBudgetCategories = () => [...new Set([
    'Housing', 'Food & dining', 'Transport', 'Shopping', 'Utilities', 'Entertainment', 'Health', 'Other',
    ...transactions.map((item) => String(item.category || 'Other').trim() || 'Other')
  ])].sort((left, right) => left.localeCompare(right));

  const categorySpendingThisMonth = () => {
    const monthPrefix = today.slice(0, 7);
    return transactions.reduce((totals, transaction) => {
      if (transaction.type !== 'expense' || !String(transaction.date || '').startsWith(monthPrefix)) return totals;
      const category = String(transaction.category || 'Other').trim() || 'Other';
      totals[category] = (totals[category] || 0) + Number(transaction.amount || 0);
      return totals;
    }, {});
  };

  const renderBudgetCategories = (categoryTotals) => {
    const container = document.getElementById('budgetCategoriesList');
    const categories = Object.entries(categoryTotals).sort(([left], [right]) => left.localeCompare(right));
    if (!categories.length) {
      const emptyState = document.createElement('div');
      emptyState.className = 'budget-category-empty';
      emptyState.textContent = 'No expense categories this month.';
      container.replaceChildren(emptyState);
      return;
    }

    const rows = categories.map(([category, total]) => {
      const row = document.createElement('div');
      const name = document.createElement('span');
      name.className = 'category-name';
      name.textContent = category;
      const amount = document.createElement('span');
      const limit = categoryLimitFor(category, getSelectedDateRange().monthCount);
      const overage = total - limit;
      if (overage > 0.005) {
        row.className = 'over-budget-category';
        amount.className = 'category-budget-overrun';
        amount.textContent = `${money(total)} / ${money(limit)} · Over by ${money(overage)}`;
      } else {
        amount.textContent = `${money(total)} / ${money(limit)}`;
      }
      row.append(name, amount);
      return row;
    });
    container.replaceChildren(...rows);
  };

  const refreshCashflowChart = (periodTransactions, range) => {
    if (!cashflowChart) return;
    const emptyState = document.getElementById('cashflowEmptyState');
    const chartCanvas = document.getElementById('cashflowChart');
    const hasTransactions = periodTransactions.length > 0;
    emptyState.classList.toggle('d-none', hasTransactions);
    chartCanvas.hidden = !hasTransactions;
    const dailyTotals = new Map();
    periodTransactions.forEach((item) => {
      const totals = dailyTotals.get(item.date) || { income: 0, expense: 0 };
      totals[item.type] += Number(item.amount) || 0;
      dailyTotals.set(item.date, totals);
    });
    const dates = [...dailyTotals.keys()].sort();
    cashflowChart.data.labels = dates.map(dateLabel);
    cashflowChart.data.datasets[0].data = dates.map((date) => dailyTotals.get(date).income);
    cashflowChart.data.datasets[1].data = dates.map((date) => dailyTotals.get(date).expense);
    cashflowChart.options.scales.x.title.text = range.label;
    cashflowChart.update('none');
  };

  const updateSummary = () => {
    const range = getSelectedDateRange();
    const periodTransactions = transactions.filter((item) => item.date >= range.start && item.date < range.end);
    refreshCashflowChart(periodTransactions, range);
    const income = periodTransactions.filter((item) => item.type === 'income').reduce((sum, item) => sum + item.amount, 0);
    const spending = periodTransactions.filter((item) => item.type === 'expense').reduce((sum, item) => sum + item.amount, 0);
    const balance = transactions.filter((item) => item.date <= today && item.date < range.end).reduce((total, item) => total + (item.type === 'income' ? Number(item.amount) : -Number(item.amount)), 0);
    const savingsRate = income ? Math.max(0, ((income - spending) / income) * 100) : 0;
    const activeBudgetLimit = budgetLimit * range.monthCount;
    document.querySelector('#monthlyIncome').closest('.stat-card').querySelector('.stat-label').textContent = `Income · ${range.label}`;
    document.querySelector('#monthlySpending').closest('.stat-card').querySelector('.stat-label').textContent = `Spending · ${range.label}`;
    document.getElementById('budgetPeriodLabel').textContent = range.label;
    document.getElementById('timelinePeriodLabel').textContent = range.label;
    document.getElementById('totalBalance').innerHTML = money(balance);
    document.getElementById('monthlyIncome').innerHTML = money(income);
    document.getElementById('monthlySpending').innerHTML = money(spending);
    document.getElementById('savingsRate').innerHTML = `${savingsRate.toFixed(1)}<span class="decimal">%</span>`;
    const percent = activeBudgetLimit > 0 ? (spending / activeBudgetLimit) * 100 : 0;
    const overBudget = spending > activeBudgetLimit;
    const overage = Math.max(0, spending - activeBudgetLimit);
    document.getElementById('budgetUsed').innerHTML = `${money(spending)} <small>/ ${money(activeBudgetLimit)}</small>`;
    const roundedPercent = Math.round(percent);
    document.getElementById('budgetPercent').innerHTML = roundedPercent > 999 ? '999%+' : `${roundedPercent}<small>%</small>`;
    const budgetProgress = document.getElementById('budgetProgress');
    budgetProgress.style.width = `${Math.min(100, percent)}%`;
    budgetProgress.classList.toggle('over-budget', overBudget);
    document.getElementById('budgetRing').classList.toggle('over-budget', overBudget);
    const budgetOverrun = document.getElementById('budgetOverrun');
    budgetOverrun.textContent = overBudget ? `Over budget by ${money(overage)}` : '';
    budgetOverrun.classList.toggle('d-none', !overBudget);
    document.getElementById('budgetRemaining').innerHTML = overBudget
      ? `Over by ${money(overage)}`
      : `${money(activeBudgetLimit - spending)} remaining`;

    const expenseTransactions = periodTransactions.filter((item) => item.type === 'expense');
    const predictionAmount = document.getElementById('predictionAmount');
    const predictionCopy = document.getElementById('predictionCopy');
    const predictionConfidence = document.getElementById('predictionConfidence');
    const budgetInsightTitle = document.getElementById('budgetInsightTitle');
    const budgetInsightCopy = document.getElementById('budgetInsightCopy');
    if (!monthEndForecast || !monthEndForecast.available) {
      predictionAmount.textContent = 'Not enough data';
      predictionCopy.textContent = monthEndForecast?.insufficient_data_message || 'A month-end forecast needs more data.';
      predictionConfidence.textContent = 'Insufficient data';
      budgetInsightTitle.textContent = 'Forecast unavailable';
      budgetInsightCopy.textContent = monthEndForecast?.insufficient_data_message || 'A month-end forecast needs more data.';
    } else {
      predictionAmount.textContent = `${money(monthEndForecast.low)} – ${money(monthEndForecast.high)}`;
      predictionCopy.textContent = `Current month-end range · ${monthEndForecast.days_elapsed} days · ${monthEndForecast.expense_transaction_count} expenses`;
      predictionConfidence.textContent = `${monthEndForecast.confidence} confidence`;
      if (monthEndForecast.point > monthEndForecast.monthly_budget) {
        budgetInsightTitle.textContent = 'Projected over budget';
        budgetInsightCopy.textContent = monthEndForecast.budget_comparison_text;
      } else {
        budgetInsightTitle.textContent = 'Projected within budget';
        budgetInsightCopy.textContent = monthEndForecast.budget_comparison_text;
      }
    }
    const categorySpending = expenseTransactions.reduce((totals, item) => {
      const category = String(item.category || 'Other').trim() || 'Other';
      totals[category] = (totals[category] || 0) + Number(item.amount || 0);
      return totals;
    }, {});
    renderBudgetCategories(categorySpending);
    const topCategory = Object.entries(categorySpending).sort((left, right) => right[1] - left[1])[0];
    document.getElementById('topCategoryTitle').textContent = topCategory ? `Highest spending: ${topCategory[0]}` : 'No category spending yet';
    document.getElementById('topCategoryCopy').textContent = topCategory ? `${money(topCategory[1])} recorded this month` : 'Your expense categories will appear here.';
    document.getElementById('monthlyRecordsCopy').textContent = `${expenseTransactions.length} expense records · ${money(spending)} total this month`;
    const periodExpenses = expenseTransactions;
    const periodExpenseTotal = periodExpenses.reduce((sum, item) => sum + item.amount, 0);
    document.getElementById('todayTotal').textContent = money(periodExpenseTotal);
    document.getElementById('timelineList').innerHTML = periodExpenses.length ? periodExpenses.slice().sort((a, b) => b.date.localeCompare(a.date) || (a.time || '').localeCompare(b.time || '')).map((item) => `<div class="timeline-item"><span class="timeline-time">${dateLabel(item.date)}${item.time ? ` · ${escapeHtml(item.time)}` : ''}</span><span class="timeline-dot"></span><div><strong>${escapeHtml(item.merchant)}</strong><small>${escapeHtml(item.category)}</small></div><b>${money(item.amount)}</b></div>`).join('') : '<div class="timeline-empty"><i class="fa-solid fa-sun"></i><span>No transactions this period.</span></div>';
    const score = Math.max(0, Math.min(100, Math.round(55 + Math.min(25, savingsRate / 3) + Math.max(0, 20 - (spending / activeBudgetLimit) * 20))));
    const grade = score >= 85 ? 'Excellent' : score >= 70 ? 'Good' : score >= 50 ? 'Average' : 'Needs work';
    document.getElementById('healthScore').textContent = score;
    document.getElementById('healthGrade').textContent = grade;
    document.getElementById('healthGauge').style.strokeDashoffset = `${302 - (302 * score / 100)}`;
    document.getElementById('healthReasons').innerHTML = `<span><i class="fa-solid fa-${savingsRate >= 20 ? 'check' : 'triangle-exclamation'}"></i> ${savingsRate >= 20 ? 'Savings rate is healthy' : 'Increase your savings rate'}</span><span><i class="fa-solid fa-${spending <= budgetLimit ? 'check' : 'triangle-exclamation'}"></i> ${spending <= budgetLimit ? 'Spending is within budget' : 'Budget needs attention'}</span><span><i class="fa-solid fa-lightbulb"></i> ${income > 0 ? 'Keep building your emergency fund' : 'Add income to unlock more insights'}</span>`;
  };

  let settingsLoadedUserId = null;
  const showSettingsMessage = (id, message, isError = false) => {
    const element = document.getElementById(id);
    element.textContent = message;
    element.classList.toggle('error', isError);
  };
  const settingsIncomeInputs = [
    document.getElementById('settingsMonthlySalary'),
    document.getElementById('settingsBusinessIncome'),
    document.getElementById('settingsFreelanceIncome'),
    document.getElementById('settingsOtherIncome')
  ];
  const settingsSavingsGoalInput = document.getElementById('settingsMonthlySavingsGoal');
  const settingsSavingsPercentageInput = document.getElementById('settingsSavingsPercentage');
  let settingsSavingsSource = 'amount';
  const settingsIncomeTotal = () => settingsIncomeInputs.reduce((total, input) => total + (Number(input.value) || 0), 0);
  const syncSettingsFinancialProfile = (source = settingsSavingsSource) => {
    const income = settingsIncomeTotal();
    document.getElementById('settingsIncomeTotal').value = income.toFixed(2);
    if (source === 'amount') {
      const amount = Number(settingsSavingsGoalInput.value);
      if (settingsSavingsGoalInput.value === '' || !Number.isFinite(amount) || amount < 0) return;
      settingsSavingsPercentageInput.value = income > 0
        ? (Math.min(100, Math.round(amount / income * 1000) / 10)).toFixed(1)
        : '';
      return;
    }
    const percentage = Number(settingsSavingsPercentageInput.value);
    if (settingsSavingsPercentageInput.value === '' || !Number.isFinite(percentage)) return;
    const clamped = Math.min(100, Math.max(0, percentage));
    settingsSavingsPercentageInput.value = String(clamped);
    if (income > 0) settingsSavingsGoalInput.value = String(Math.round(income * clamped / 100));
  };
  const populateSettingsFinancialProfile = (settings) => {
    settingsIncomeInputs[0].value = settings.monthly_salary ?? 0;
    settingsIncomeInputs[1].value = settings.business_income ?? 0;
    settingsIncomeInputs[2].value = settings.freelance_income ?? 0;
    settingsIncomeInputs[3].value = settings.other_income ?? 0;
    if (settingsIncomeTotal() === 0 && Number(settings.monthly_income) > 0) {
      settingsIncomeInputs[0].value = Number(settings.monthly_income);
    }
    settingsSavingsGoalInput.value = settings.monthly_savings_goal ?? 0;
    settingsSavingsPercentageInput.value = settingsIncomeTotal() > 0 ? (settings.savings_percentage ?? 0) : '';
    settingsSavingsSource = 'amount';
    document.getElementById('settingsIncomeTotal').value = settingsIncomeTotal().toFixed(2);
    document.getElementById('settingsFinancialProfilePanel').hidden = false;
  };
  settingsIncomeInputs.forEach((input) => input.addEventListener('input', () => syncSettingsFinancialProfile()));
  settingsSavingsGoalInput.addEventListener('input', () => {
    settingsSavingsSource = 'amount';
    syncSettingsFinancialProfile('amount');
  });
  settingsSavingsPercentageInput.addEventListener('input', () => {
    settingsSavingsSource = 'percentage';
    syncSettingsFinancialProfile('percentage');
  });

  const renderSettingsCategoryLimits = () => {
    const container = document.getElementById('settingsCategoryLimits');
    const categories = getBudgetCategories();
    container.replaceChildren();
    categories.forEach((category, index) => {
      const wrapper = document.createElement('div');
      wrapper.className = 'settings-category-row';
      const label = document.createElement('label');
      const inputId = `settingsCategory-${index}`;
      label.className = 'form-label';
      label.htmlFor = inputId;
      label.textContent = category;
      const input = document.createElement('input');
      input.className = 'form-control';
      input.id = inputId;
      input.type = 'number';
      input.min = '0.01';
      input.max = '9999999999.99';
      input.step = '0.01';
      input.required = true;
      input.dataset.settingsCategory = category;
      input.value = categoryLimitFor(category, 1).toFixed(2);
      wrapper.append(label, input);
      container.append(wrapper);
    });
  };

  const renderEditBudgetCategories = () => {
    const container = document.getElementById('editBudgetCategoryLimits');
    const spending = categorySpendingThisMonth();
    container.replaceChildren();
    getBudgetCategories().forEach((category, index) => {
      const wrapper = document.createElement('div');
      wrapper.className = 'budget-edit-category';
      const heading = document.createElement('div');
      heading.className = 'budget-edit-category-heading';
      const label = document.createElement('label');
      const inputId = `editBudgetCategory-${index}`;
      label.className = 'form-label';
      label.htmlFor = inputId;
      label.textContent = category;
      const spent = document.createElement('small');
      spent.className = 'budget-edit-spent';
      spent.textContent = `Spent: ${money(spending[category] || 0)}`;
      heading.append(label, spent);
      const input = document.createElement('input');
      input.className = 'form-control';
      input.id = inputId;
      input.type = 'number';
      input.min = '0.01';
      input.max = '9999999999.99';
      input.step = '0.01';
      input.required = true;
      input.dataset.editBudgetCategory = category;
      input.value = categoryLimitFor(category, 1).toFixed(2);
      wrapper.append(heading, input);
      container.append(wrapper);
    });
    updateBudgetAllocationSummary();
  };

  const updateBudgetAllocationSummary = () => {
    const monthlyBudget = Number(document.getElementById('editMonthlyBudget').value);
    const inputs = [...document.querySelectorAll('[data-edit-budget-category]')];
    const validValues = inputs.map((input) => Number(input.value)).filter((value) => Number.isFinite(value) && value >= 0);
    const total = validValues.reduce((sum, value) => sum + value, 0);
    const summary = document.getElementById('editBudgetAllocationSummary');
    const warning = document.getElementById('editBudgetAllocationWarning');
    summary.textContent = `Category limits ka total ${money(total)} hai, monthly budget ${money(monthlyBudget)} hai`;
    const exceeds = Number.isFinite(monthlyBudget) && monthlyBudget > 0 && total > monthlyBudget;
    warning.textContent = exceeds ? 'Category limits ka total monthly budget se zyada hai.' : '';
    warning.classList.toggle('d-none', !exceeds);
  };

  const resetEditBudgetCategoryDefaults = () => {
    document.getElementById('editBudgetCategoryLimits').querySelectorAll('[data-edit-budget-category]').forEach((input) => {
      const defaultAmount = defaultCategoryBudgetAmounts[input.dataset.editBudgetCategory] ?? defaultBudgetLimit * 0.1;
      const monthlyBudget = Number(document.getElementById('editMonthlyBudget').value);
      input.value = Number.isFinite(monthlyBudget) && monthlyBudget > 0
        ? (monthlyBudget * defaultAmount / defaultBudgetLimit).toFixed(2)
        : '';
    });
    updateBudgetAllocationSummary();
  };

  const syncBudgetSettingsFields = () => {
    const monthlyInput = document.getElementById('settingsMonthlyBudget');
    if (monthlyInput) monthlyInput.value = budgetLimit.toFixed(2);
    const currencyInput = document.getElementById('settingsCurrency');
    if (currencyInput) currencyInput.value = currencyConfig.code;
    document.getElementById('settingsJarvisCurrency').textContent = currencyConfig.symbol;
    renderSettingsCategoryLimits();
  };

  const persistDemoBudget = (settings) => {
    try {
      localStorage.setItem(demoBudgetStorageKey, JSON.stringify({
        currency: settings.currency,
        monthly_budget: settings.monthly_budget,
        budget_allocations: settings.budget_allocations
      }));
    } catch (error) {
      throw new Error('Could not save budget in this browser. Check local storage and try again.');
    }
  };

  const saveBudgetPreferences = async (monthlyBudget, categoryLimits, currency) => {
    if (!Number.isFinite(monthlyBudget) || monthlyBudget < 1 || monthlyBudget > 9999999999.99) {
      throw new Error('Enter a monthly budget from 1 to 9,999,999,999.99.');
    }
    const categoryEntries = Object.entries(categoryLimits);
    if (!categoryEntries.length || categoryEntries.some(([category, amount]) => (
      !category.trim()
      || !Number.isFinite(amount)
      || amount < 0.01
      || amount > 9999999999.99
    ))) {
      throw new Error('Enter a positive, valid limit for every category.');
    }

    let savedSettings;
    let message = 'Saved successfully.';
    if (supabaseUserAuthenticated && location.protocol !== 'file:') {
      const result = await supabaseRequest('settings', {
        method: 'POST',
        body: JSON.stringify({ action: 'preferences', currency, monthly_budget: monthlyBudget, category_limits: categoryLimits })
      });
      savedSettings = result.settings;
      message = result.message || message;
    } else {
      savedSettings = {
        currency,
        monthly_budget: monthlyBudget,
        budget_allocations: Object.fromEntries(categoryEntries.map(([category, amount]) => [category, (amount / monthlyBudget) * 100]))
      };
      persistDemoBudget(savedSettings);
    }

    applyCurrency(savedSettings.currency || currency);
    budgetLimit = Number(savedSettings.monthly_budget);
    categoryBudgetAllocations = savedSettings.budget_allocations;
    syncBudgetSettingsFields();
    if (supabaseUserAuthenticated && location.protocol !== 'file:') {
      updateSummary();
      await loadSupabaseTransactions();
    } else {
      updateSummary();
    }
    return message;
  };

  const editBudgetModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('editBudgetModal'));
  document.getElementById('editBudgetButton').addEventListener('click', () => {
    document.getElementById('editMonthlyBudget').value = budgetLimit.toFixed(2);
    document.getElementById('editBudgetCurrencyPrefix').textContent = currencyConfig.symbol;
    document.getElementById('editBudgetMessage').textContent = '';
    document.getElementById('editBudgetMessage').classList.remove('error');
    renderEditBudgetCategories();
    editBudgetModal.show();
  });
  document.getElementById('editMonthlyBudget').addEventListener('input', updateBudgetAllocationSummary);
  document.getElementById('editBudgetCategoryLimits').addEventListener('input', updateBudgetAllocationSummary);
  document.getElementById('resetBudgetDefaults').addEventListener('click', resetEditBudgetCategoryDefaults);
  document.getElementById('editBudgetForm').addEventListener('submit', async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    if (!form.reportValidity()) return;
    const saveButton = document.getElementById('saveBudgetButton');
    saveButton.disabled = true;
    showSettingsMessage('editBudgetMessage', '');
    const categoryLimits = Object.fromEntries(
      [...document.querySelectorAll('[data-edit-budget-category]')].map((input) => [input.dataset.editBudgetCategory, Number(input.value)])
    );
    try {
      const message = await saveBudgetPreferences(
        Number(document.getElementById('editMonthlyBudget').value),
        categoryLimits,
        currencyConfig.code
      );
      showSettingsMessage('editBudgetMessage', message);
      editBudgetModal.hide();
      const toastElement = document.getElementById('liveToast');
      toastElement.querySelector('.toast-body').textContent = message;
      bootstrap.Toast.getOrCreateInstance(toastElement).show();
    } catch (error) {
      showSettingsMessage('editBudgetMessage', error.message || 'Could not save the budget.', true);
    } finally {
      saveButton.disabled = false;
    }
  });

  const loadSettingsData = async () => {
    const status = document.getElementById('settingsLoadMessage');
    status.textContent = 'Loading settings...';
    status.classList.remove('error');
    try {
      const result = await supabaseRequest('settings');
      setProfile(result.user);
      applyCurrency(result.settings.currency || 'INR');
      budgetLimit = Number(result.settings.monthly_budget) > 0 ? Number(result.settings.monthly_budget) : defaultBudgetLimit;
      categoryBudgetAllocations = result.settings.budget_allocations && typeof result.settings.budget_allocations === 'object'
        ? result.settings.budget_allocations
        : {};
      document.getElementById('settingsName').value = result.user.name;
      document.getElementById('settingsEmail').value = result.user.email;
      document.getElementById('settingsMonthlyBudget').value = budgetLimit.toFixed(2);
      populateSettingsFinancialProfile(result.settings);
      document.getElementById('settingsJarvisAutoAdd').checked = result.settings.jarvis_auto_add_enabled === true;
      document.getElementById('settingsJarvisAutoAddLimit').value = Number(result.settings.jarvis_auto_add_limit || 5000).toFixed(2);
      document.getElementById('settingsJarvisCurrency').textContent = currencyConfig.symbol;
      renderSettingsCategoryLimits();

      const notificationKey = `finora-notifications-${result.user.id}`;
      let notifications = {};
      try { notifications = JSON.parse(localStorage.getItem(notificationKey) || '{}'); } catch (error) { notifications = {}; }
      document.getElementById('settingsBudgetAlerts').checked = notifications.budgetAlerts ?? true;
      document.getElementById('settingsWeeklySummary').checked = notifications.weeklySummary ?? false;
      settingsLoadedUserId = result.user.id;
      status.textContent = '';
      await loadSupabaseTransactions();
    } catch (error) {
      status.textContent = error.message || 'Could not load settings.';
      status.classList.add('error');
    }
  };

  const presetReportRange = () => {
    const range = getSelectedDateRange();
    const lastDay = new Date(`${range.end}T12:00:00`);
    lastDay.setDate(lastDay.getDate() - 1);
    const periodEnd = formatLocalDate(lastDay);
    return { start: range.start, end: periodEnd > today ? today : periodEnd, label: range.label };
  };

  const getReportsRange = () => {
    if (!reportUsesCustomRange) return presetReportRange();
    const start = document.getElementById('reportsStartDate').value;
    const end = document.getElementById('reportsEndDate').value;
    if (!start || !end) return { error: 'Choose both a start date and an end date.' };
    if (start > end) return { error: 'Start date must be on or before the end date.' };
    return { start, end, label: `${start} to ${end}` };
  };

  const renderReportsRanking = (container, entries, emptyMessage) => {
    container.replaceChildren();
    if (!entries.length) {
      const empty = document.createElement('li');
      empty.className = 'reports-ranking-empty';
      empty.textContent = emptyMessage;
      container.append(empty);
      return;
    }
    entries.slice(0, 5).forEach(([name, amount]) => {
      const item = document.createElement('li');
      const label = document.createElement('span');
      label.textContent = name;
      const value = document.createElement('strong');
      value.textContent = money(amount);
      item.append(label, value);
      container.append(item);
    });
  };

  const ensureReportsCharts = () => {
    if (!reportsCategoryChart) {
      const categoryCanvas = document.getElementById('reportsCategoryChart');
      const categoryEmpty = document.createElement('div');
      categoryEmpty.className = 'reports-chart-empty';
      categoryEmpty.id = 'reportsCategoryEmpty';
      categoryEmpty.textContent = 'No expense data for this range.';
      categoryEmpty.hidden = true;
      categoryCanvas.after(categoryEmpty);
      reportsCategoryChart = new Chart(categoryCanvas, {
        type: 'doughnut',
        data: { labels: [], datasets: [{ data: [], backgroundColor: ['#a9d953', '#ffb876', '#a9efcf', '#9bc9ed', '#f2b2d6', '#d9c98e', '#9eabd7', '#d99b8d'], borderWidth: 0 }] },
        options: { responsive: true, maintainAspectRatio: false, cutout: '58%', plugins: { legend: { position: 'bottom', labels: { boxWidth: 9, font: { size: 10 } } }, tooltip: { callbacks: { label: (context) => ` ${context.label}: ${money(context.parsed)}` } } } }
      });
    }
    if (!reportsTrendChart) {
      reportsTrendChart = new Chart(document.getElementById('reportsTrendChart'), {
        type: 'line',
        data: { labels: [], datasets: [{ label: 'Income', data: [], borderColor: '#7fbe7e', backgroundColor: 'rgba(169,239,207,.18)', fill: true, tension: .35, pointRadius: 2, borderWidth: 2 }, { label: 'Expenses', data: [], borderColor: '#e7a467', backgroundColor: 'rgba(255,184,118,.08)', fill: true, tension: .35, pointRadius: 2, borderWidth: 2 }] },
        options: { responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false }, plugins: { legend: { position: 'bottom', labels: { boxWidth: 9, font: { size: 10 } } }, tooltip: { callbacks: { label: (context) => ` ${context.dataset.label}: ${money(context.parsed.y)}` } } }, scales: { x: { grid: { display: false }, ticks: { color: '#8f999f', maxTicksLimit: 8 } }, y: { beginAtZero: true, grid: { color: '#edf0ef' }, ticks: { color: '#8f999f', callback: (value) => `${currencyConfig.symbol} ${Number(value).toLocaleString()}` } } } }
      });
    }
  };

  const renderReports = () => {
    if (location.hash !== '#reports') return;
    const range = getReportsRange();
    const rangeMessage = document.getElementById('reportsRangeMessage');
    const emptyState = document.getElementById('reportsEmptyState');
    const content = document.getElementById('reportsContent');
    const exportButton = document.getElementById('reportsCsvExport');
    if (range.error) {
      rangeMessage.textContent = range.error;
      emptyState.hidden = true;
      content.hidden = true;
      exportButton.disabled = true;
      return;
    }

    rangeMessage.textContent = '';
    const filtered = transactions.filter((item) => item.date >= range.start && item.date <= range.end);
    const hasData = filtered.length > 0;
    emptyState.hidden = hasData;
    content.hidden = !hasData;
    exportButton.disabled = !hasData;
    if (!hasData) return;

    ensureReportsCharts();
    const expenses = filtered.filter((item) => item.type === 'expense');
    const categoryTotals = expenses.reduce((totals, item) => {
      const category = String(item.category || 'Other').trim() || 'Other';
      totals[category] = (totals[category] || 0) + Number(item.amount || 0);
      return totals;
    }, {});
    const merchantTotals = expenses.reduce((totals, item) => {
      const merchant = String(item.merchant || 'Unknown merchant').trim() || 'Unknown merchant';
      totals[merchant] = (totals[merchant] || 0) + Number(item.amount || 0);
      return totals;
    }, {});
    const categories = Object.entries(categoryTotals).sort((left, right) => right[1] - left[1]);
    const merchants = Object.entries(merchantTotals).sort((left, right) => right[1] - left[1]);
    renderReportsRanking(document.getElementById('reportsTopCategories'), categories, 'No expenses in this range.');
    renderReportsRanking(document.getElementById('reportsTopMerchants'), merchants, 'No merchant spending in this range.');

    reportsCategoryChart.data.labels = categories.map(([name]) => name);
    reportsCategoryChart.data.datasets[0].data = categories.map(([, amount]) => amount);
    document.getElementById('reportsCategoryEmpty').hidden = categories.length > 0;
    document.getElementById('reportsCategoryChart').hidden = categories.length === 0;
    reportsCategoryChart.update('none');

    const dailyTotals = new Map();
    filtered.forEach((item) => {
      const totals = dailyTotals.get(item.date) || { income: 0, expense: 0 };
      totals[item.type] += Number(item.amount || 0);
      dailyTotals.set(item.date, totals);
    });
    const dates = [...dailyTotals.keys()].sort();
    reportsTrendChart.data.labels = dates.map(dateLabel);
    reportsTrendChart.data.datasets[0].data = dates.map((date) => dailyTotals.get(date).income);
    reportsTrendChart.data.datasets[1].data = dates.map((date) => dailyTotals.get(date).expense);
    reportsTrendChart.update('none');
    requestAnimationFrame(() => {
      reportsCategoryChart.resize();
      reportsTrendChart.resize();
    });
  };

  const exportReportsCsv = () => {
    const range = getReportsRange();
    if (range.error) {
      document.getElementById('reportsRangeMessage').textContent = range.error;
      return;
    }
    const filtered = transactions.filter((item) => item.date >= range.start && item.date <= range.end);
    if (!filtered.length) return;
    const csvCell = (value) => {
      let text = String(value ?? '');
      if (/^[\s]*[=+\-@]/.test(text)) text = `'${text}`;
      return `"${text.replace(/"/g, '""')}"`;
    };
    const columns = ['Date', 'Type', 'Merchant', 'Category', 'Amount', 'Payment method', 'Location', 'Notes'];
    const rows = filtered.map((item) => [item.date, item.type, item.merchant, item.category, item.amount, item.payment_method, item.location, item.notes]);
    const csv = [columns, ...rows].map((row) => row.map(csvCell).join(',')).join('\r\n');
    const url = URL.createObjectURL(new Blob([`\uFEFF${csv}`], { type: 'text/csv;charset=utf-8' }));
    const link = document.createElement('a');
    link.href = url;
    link.download = `finora-report-${range.start}-to-${range.end}.csv`;
    document.body.append(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
  };

  const updateReportsDateInputs = () => {
    if (!reportUsesCustomRange) {
      const range = presetReportRange();
      document.getElementById('reportsStartDate').value = range.start;
      document.getElementById('reportsEndDate').value = range.end;
    } else if (storedReportCustomRange) {
      document.getElementById('reportsStartDate').value = storedReportCustomRange.start;
      document.getElementById('reportsEndDate').value = storedReportCustomRange.end;
    }
  };

  document.querySelectorAll('.report-range-btn').forEach((button) => button.addEventListener('click', () => {
    reportUsesCustomRange = false;
    selectedDateRange = button.dataset.reportPeriod;
    localStorage.setItem(periodStorageKey, selectedDateRange);
    localStorage.removeItem(reportCustomRangeStorageKey);
    storedReportCustomRange = null;
    document.querySelectorAll('.report-range-btn').forEach((item) => item.classList.toggle('active', item === button));
    document.querySelectorAll('.period-btn').forEach((item) => item.classList.toggle('active', item.dataset.period === selectedDateRange));
    updateReportsDateInputs();
    renderTransactions();
    updateSummary();
    renderReports();
  }));

  ['reportsStartDate', 'reportsEndDate'].forEach((id) => document.getElementById(id).addEventListener('change', () => {
    reportUsesCustomRange = true;
    storedReportCustomRange = {
      start: document.getElementById('reportsStartDate').value,
      end: document.getElementById('reportsEndDate').value
    };
    if (storedReportCustomRange.start && storedReportCustomRange.end) localStorage.setItem(reportCustomRangeStorageKey, JSON.stringify(storedReportCustomRange));
    document.querySelectorAll('.report-range-btn').forEach((button) => button.classList.remove('active'));
    renderReports();
  }));
  document.getElementById('reportsCsvExport').addEventListener('click', exportReportsCsv);

  document.getElementById('settingsProfileForm').addEventListener('submit', async (event) => {
    event.preventDefault();
    showSettingsMessage('settingsProfileMessage', '');
    try {
      const requestedEmail = document.getElementById('settingsEmail').value.trim();
      let emailChangeRequested = requestedEmail.toLowerCase() !== String(activeUser?.email || '').toLowerCase();
      if (emailChangeRequested) {
        if (!supabaseClient) throw new Error('Supabase is not configured.');
        const { error } = await supabaseClient.auth.updateUser({ email: requestedEmail });
        if (error) throw error;
      }
      const result = await supabaseRequest('settings', {
        method: 'POST',
        body: JSON.stringify({
          action: 'profile',
          name: document.getElementById('settingsName').value.trim(),
          email: activeUser?.email || requestedEmail
        })
      });
      setProfile(result.user);
      localStorage.setItem('finora-profile', JSON.stringify(result.user));
      showSettingsMessage('settingsProfileMessage', emailChangeRequested
        ? 'Name saved. Confirm the email-change request using the message Supabase sent.'
        : (result.message || 'Saved successfully.'));
    } catch (error) {
      showSettingsMessage('settingsProfileMessage', error.message || 'Could not save profile settings.', true);
    }
  });

  document.getElementById('settingsPreferencesForm').addEventListener('submit', async (event) => {
    event.preventDefault();
    showSettingsMessage('settingsPreferencesMessage', '');
    const categoryLimits = Object.fromEntries([...document.querySelectorAll('[data-settings-category]')].map((input) => [input.dataset.settingsCategory, Number(input.value)]));
    try {
      const message = await saveBudgetPreferences(
        Number(document.getElementById('settingsMonthlyBudget').value),
        categoryLimits,
        document.getElementById('settingsCurrency').value
      );
      showSettingsMessage('settingsPreferencesMessage', message);
    } catch (error) {
      showSettingsMessage('settingsPreferencesMessage', error.message || 'Could not save preferences.', true);
    }
  });

  document.getElementById('settingsFinancialProfileForm').addEventListener('submit', async (event) => {
    event.preventDefault();
    showSettingsMessage('settingsFinancialProfileMessage', '');
    try {
      const result = await supabaseRequest('settings', {
        method: 'POST',
        body: JSON.stringify({
          action: 'financial_profile',
          monthly_salary: Number(settingsIncomeInputs[0].value),
          business_income: Number(settingsIncomeInputs[1].value),
          freelance_income: Number(settingsIncomeInputs[2].value),
          other_income: Number(settingsIncomeInputs[3].value),
          monthly_income: settingsIncomeTotal(),
          monthly_savings_goal: Number(settingsSavingsGoalInput.value),
          savings_percentage: Number(settingsSavingsPercentageInput.value) || 0
        })
      });
      populateSettingsFinancialProfile(result.settings);
      showSettingsMessage('settingsFinancialProfileMessage', result.message || 'Financial profile saved successfully.');
    } catch (error) {
      showSettingsMessage('settingsFinancialProfileMessage', error.message || 'Could not save your financial profile.', true);
    }
  });

  document.getElementById('settingsJarvisActionsForm').addEventListener('submit', async (event) => {
    event.preventDefault();
    showSettingsMessage('settingsJarvisActionsMessage', '');
    try {
      const result = await supabaseRequest('settings', {
        method: 'POST',
        body: JSON.stringify({
          action: 'jarvis_actions',
          jarvis_auto_add_enabled: document.getElementById('settingsJarvisAutoAdd').checked,
          jarvis_auto_add_limit: Number(document.getElementById('settingsJarvisAutoAddLimit').value)
        })
      });
      showSettingsMessage('settingsJarvisActionsMessage', result.message || 'Jarvis action settings saved.');
    } catch (error) {
      showSettingsMessage('settingsJarvisActionsMessage', error.message || 'Could not save Jarvis settings.', true);
    }
  });

  document.getElementById('settingsPasswordForm').addEventListener('submit', async (event) => {
    event.preventDefault();
    const newPassword = document.getElementById('settingsNewPassword').value;
    const confirmPassword = document.getElementById('settingsConfirmPassword').value;
    if (newPassword.length < 8) {
      showSettingsMessage('settingsPasswordMessage', 'New password must be at least 8 characters.', true);
      return;
    }
    if (newPassword !== confirmPassword) {
      showSettingsMessage('settingsPasswordMessage', 'Password confirmation does not match.', true);
      return;
    }
    try {
      if (!supabaseClient) throw new Error('Supabase is not configured.');
      const { error } = await supabaseClient.auth.updateUser({ password: newPassword });
      if (error) throw error;
      document.getElementById('settingsPasswordForm').reset();
      showSettingsMessage('settingsPasswordMessage', 'Password changed successfully.');
    } catch (error) {
      showSettingsMessage('settingsPasswordMessage', error.message || 'Could not change password.', true);
    }
  });

  document.getElementById('settingsNotificationsForm').addEventListener('submit', (event) => {
    event.preventDefault();
    if (!activeUser?.id) {
      showSettingsMessage('settingsNotificationsMessage', 'Sign in to save notification preferences.', true);
      return;
    }
    localStorage.setItem(`finora-notifications-${activeUser.id}`, JSON.stringify({
      budgetAlerts: document.getElementById('settingsBudgetAlerts').checked,
      weeklySummary: document.getElementById('settingsWeeklySummary').checked
    }));
    showSettingsMessage('settingsNotificationsMessage', 'Saved successfully.');
  });

  const demoGoalsStorageKey = 'finora-savings-goals';
  const isDemoGoalsMode = () => location.protocol === 'file:' || !supabaseUserAuthenticated || !activeUser;
  const goalsOwnerKey = () => isDemoGoalsMode() ? 'demo' : `user:${activeUser.id}`;
  const setGoalsMessage = (message, isError = false) => {
    const element = document.getElementById('goalsLoadMessage');
    element.textContent = message;
    element.classList.toggle('error', isError);
  };
  const makeGoalElement = (tagName, className = '', text) => {
    const element = document.createElement(tagName);
    if (className) element.className = className;
    if (text !== undefined) element.textContent = text;
    return element;
  };
  const formatGoalDate = (date) => new Date(`${date}T12:00:00`).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
  const goalDayNumber = (date) => {
    const [year, month, day] = date.split('-').map(Number);
    return Date.UTC(year, month - 1, day) / 86400000;
  };
  const setGoalAction = (button, action, goalId, contributionId = '') => {
    button.type = 'button';
    button.dataset.goalAction = action;
    button.dataset.goalId = String(goalId);
    if (contributionId) button.dataset.contributionId = String(contributionId);
  };

  const renderSavingsGoals = () => {
    const list = document.getElementById('goalsList');
    const emptyState = document.getElementById('goalsEmptyState');
    list.replaceChildren();
    emptyState.hidden = savingsGoals.length > 0;

    savingsGoals.forEach((goal) => {
      const goalId = String(goal.id);
      const target = Number(goal.target_amount);
      const saved = Number(goal.current_amount);
      const remaining = Math.max(0, target - saved);
      const completed = saved >= target;
      const percentage = target > 0 ? Math.min(100, Math.max(0, (saved / target) * 100)) : 0;
      const card = makeGoalElement('article', 'panel goal-card');
      const heading = makeGoalElement('div', 'goal-card-heading');
      const titleGroup = makeGoalElement('div', 'goal-title-group');
      titleGroup.append(makeGoalElement('h3', 'goal-title', goal.title));
      if (completed) titleGroup.append(makeGoalElement('span', 'goal-completed-badge', 'Completed'));
      heading.append(titleGroup);
      const actions = makeGoalElement('div', 'goal-card-actions');
      const editButton = makeGoalElement('button', 'goal-icon-button', 'Edit');
      editButton.setAttribute('aria-label', `Edit ${goal.title}`);
      setGoalAction(editButton, 'edit-goal', goalId);
      const deleteButton = makeGoalElement('button', 'goal-icon-button goal-delete-button', 'Delete');
      deleteButton.setAttribute('aria-label', `Delete ${goal.title}`);
      setGoalAction(deleteButton, 'delete-goal', goalId);
      actions.append(editButton, deleteButton);
      heading.append(actions);
      card.append(heading);

      const targetDate = goal.deadline ? new Date(`${goal.deadline}T12:00:00`) : null;
      const daysLeft = targetDate ? goalDayNumber(goal.deadline) - goalDayNumber(today) : null;
      const deadlineText = !targetDate ? 'No target date' : daysLeft < 0 ? `${Math.abs(daysLeft)} days overdue` : `${daysLeft} days left`;
      const summary = makeGoalElement('div', 'goal-summary');
      summary.append(
        makeGoalElement('span', 'goal-saved-amount', `${money(saved)} saved`),
        makeGoalElement('span', 'goal-target-amount', `of ${money(target)}`)
      );
      card.append(summary);

      const progress = makeGoalElement('div', 'progress goal-progress');
      progress.setAttribute('role', 'progressbar');
      progress.setAttribute('aria-label', `Progress for ${goal.title}`);
      progress.setAttribute('aria-valuemin', '0');
      progress.setAttribute('aria-valuemax', '100');
      progress.setAttribute('aria-valuenow', String(Math.round(percentage)));
      const progressBar = makeGoalElement('div', `progress-bar${completed ? ' goal-progress-complete' : ''}`);
      progressBar.style.width = `${percentage}%`;
      progress.append(progressBar);
      card.append(progress);

      const goalMeta = makeGoalElement('div', 'goal-meta');
      goalMeta.append(
        makeGoalElement('span', '', `${money(remaining)} to go`),
        makeGoalElement('span', '', deadlineText)
      );
      card.append(goalMeta);

      if (!completed && targetDate) {
        const hint = makeGoalElement('p', 'goal-monthly-hint');
        if (daysLeft < 0) {
          hint.textContent = `Target date passed. ${money(remaining)} is still needed.`;
          hint.classList.add('goal-hint-overdue');
        } else if (daysLeft === 0) {
          hint.textContent = `Save ${money(remaining)} today to reach your target.`;
        } else {
          hint.textContent = `To reach your target by ${formatGoalDate(goal.deadline)}, save about ${money(remaining * 30.4375 / daysLeft)} per month.`;
        }
        card.append(hint);
      }

      const editForm = makeGoalElement('form', 'goal-edit-form');
      editForm.hidden = true;
      editForm.dataset.goalId = goalId;
      const editFields = makeGoalElement('div', 'goal-edit-grid');
      const editTitle = makeGoalElement('input', 'form-control');
      editTitle.type = 'text';
      editTitle.maxLength = 120;
      editTitle.required = true;
      editTitle.value = goal.title;
      editTitle.setAttribute('aria-label', 'Goal name');
      const editTarget = makeGoalElement('input', 'form-control');
      editTarget.type = 'number';
      editTarget.min = '0.01';
      editTarget.max = '9999999999.99';
      editTarget.step = '0.01';
      editTarget.required = true;
      editTarget.value = target.toFixed(2);
      editTarget.setAttribute('aria-label', 'Target amount');
      const editDeadline = makeGoalElement('input', 'form-control');
      editDeadline.type = 'date';
      editDeadline.required = true;
      editDeadline.value = goal.deadline || '';
      editDeadline.setAttribute('aria-label', 'Target date');
      editFields.append(editTitle, editTarget, editDeadline);
      const editSubmit = makeGoalElement('button', 'btn btn-primary btn-sm', 'Save changes');
      editSubmit.type = 'submit';
      editForm.append(editFields, editSubmit);
      card.append(editForm);

      const contributionDetails = makeGoalElement('details', 'goal-contributions');
      contributionDetails.append(makeGoalElement('summary', '', `Contributions (${(goal.contributions || []).length})`));
      const contributionForm = makeGoalElement('form', 'goal-contribution-form');
      contributionForm.dataset.goalId = goalId;
      const contributionAmount = makeGoalElement('input', 'form-control');
      contributionAmount.type = 'number';
      contributionAmount.min = '0.01';
      contributionAmount.max = '9999999999.99';
      contributionAmount.step = '0.01';
      contributionAmount.required = true;
      contributionAmount.placeholder = 'Amount';
      contributionAmount.setAttribute('aria-label', 'Contribution amount');
      const contributionDate = makeGoalElement('input', 'form-control');
      contributionDate.type = 'date';
      contributionDate.required = true;
      contributionDate.value = today;
      contributionDate.setAttribute('aria-label', 'Contribution date');
      const contributionSubmit = makeGoalElement('button', 'btn btn-primary btn-sm', 'Add contribution');
      contributionSubmit.type = 'submit';
      contributionForm.append(contributionAmount, contributionDate, contributionSubmit);
      contributionDetails.append(contributionForm);

      const contributions = goal.contributions || [];
      if (contributions.length) {
        const contributionList = makeGoalElement('ul', 'goal-contribution-list');
        contributions.forEach((contribution) => {
          const item = makeGoalElement('li');
          item.dataset.contributionId = String(contribution.id);
          const details = makeGoalElement('span', '', `${formatGoalDate(contribution.contribution_date)} · ${money(contribution.amount)}`);
          const remove = makeGoalElement('button', 'goal-contribution-delete', 'Delete');
          remove.setAttribute('aria-label', `Delete contribution of ${money(contribution.amount)}`);
          setGoalAction(remove, 'delete-contribution', goalId, contribution.id);
          item.append(details, remove);
          contributionList.append(item);
        });
        contributionDetails.append(contributionList);
      }
      card.append(contributionDetails);
      list.append(card);
    });
  };

  const loadSavingsGoals = async (force = false) => {
    const ownerKey = goalsOwnerKey();
    if (!force && goalsLoadedFor === ownerKey) return;
    setGoalsMessage('Loading savings goals...');
    try {
      if (isDemoGoalsMode()) {
        savingsGoals = JSON.parse(localStorage.getItem(demoGoalsStorageKey) || '[]');
      } else {
        const result = await supabaseRequest('goals');
        savingsGoals = result.goals;
      }
      goalsLoadedFor = ownerKey;
      setGoalsMessage('');
      renderSavingsGoals();
    } catch (error) {
      savingsGoals = [];
      goalsLoadedFor = null;
      renderSavingsGoals();
      setGoalsMessage(error.message || 'Could not load savings goals.', true);
    }
  };

  const saveDemoGoals = () => localStorage.setItem(demoGoalsStorageKey, JSON.stringify(savingsGoals));
  const refreshSavingsGoals = () => {
    goalsLoadedFor = null;
    return loadSavingsGoals(true);
  };
  const showGoalCreateForm = () => {
    document.getElementById('goalCreatePanel').hidden = false;
    document.getElementById('goalTitle').focus();
  };
  document.getElementById('goalCreateToggle').addEventListener('click', showGoalCreateForm);
  document.getElementById('goalCreateFirst').addEventListener('click', showGoalCreateForm);
  document.getElementById('goalCreateForm').addEventListener('submit', async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    const title = document.getElementById('goalTitle').value.trim();
    const targetAmount = Number(document.getElementById('goalTarget').value);
    const deadline = document.getElementById('goalDeadline').value;
    const startingAmount = Number(document.getElementById('goalStartingAmount').value || 0);
    const message = document.getElementById('goalCreateMessage');
    message.textContent = '';
    message.classList.remove('error');
    try {
      if (!title || title.length > 120 || !Number.isFinite(targetAmount) || targetAmount <= 0 || !deadline || !Number.isFinite(startingAmount) || startingAmount < 0 || startingAmount > targetAmount) {
        throw new Error('Enter a valid goal name, target, starting amount, and target date.');
      }
      if (isDemoGoalsMode()) {
        savingsGoals.push({ id: `demo-${Date.now()}`, title, target_amount: targetAmount, current_amount: startingAmount, deadline, contributions: [] });
        saveDemoGoals();
      } else {
        await supabaseRequest('goals', { method: 'POST', body: JSON.stringify({ title, target_amount: targetAmount, deadline, starting_amount: startingAmount }) });
      }
      form.reset();
      document.getElementById('goalStartingAmount').value = '0';
      document.getElementById('goalCreatePanel').hidden = true;
      await refreshSavingsGoals();
    } catch (error) {
      message.textContent = error.message || 'Could not create this goal.';
      message.classList.add('error');
    }
  });

  document.getElementById('goalsList').addEventListener('click', async (event) => {
    const button = event.target.closest('[data-goal-action]');
    if (!button) return;
    const goalId = button.dataset.goalId;
    const action = button.dataset.goalAction;
    const goal = savingsGoals.find((item) => String(item.id) === goalId);
    if (action === 'edit-goal') {
      const form = button.closest('.goal-card').querySelector('.goal-edit-form');
      form.hidden = !form.hidden;
      return;
    }
    if (action === 'delete-goal') {
      if (!goal || !window.confirm(`Delete the savings goal "${goal.title}" and its contributions?`)) return;
      button.disabled = true;
      try {
        if (isDemoGoalsMode()) {
          savingsGoals = savingsGoals.filter((item) => String(item.id) !== goalId);
          saveDemoGoals();
        } else {
          await supabaseRequest('goals', { method: 'DELETE', body: JSON.stringify({ id: goalId, action: 'goal' }) });
        }
        await refreshSavingsGoals();
      } catch (error) {
        button.disabled = false;
        setGoalsMessage(error.message || 'Could not delete this goal.', true);
      }
      return;
    }
    if (action === 'delete-contribution') {
      if (!window.confirm('Delete this contribution and adjust the saved amount?')) return;
      button.disabled = true;
      try {
        const contributionId = button.dataset.contributionId;
        if (isDemoGoalsMode()) {
          const contribution = goal.contributions.find((item) => String(item.id) === contributionId);
          if (contribution) goal.current_amount = Math.max(0, Number(goal.current_amount) - Number(contribution.amount));
          goal.contributions = goal.contributions.filter((item) => String(item.id) !== contributionId);
          saveDemoGoals();
        } else {
          await supabaseRequest('goals', { method: 'DELETE', body: JSON.stringify({ id: contributionId, action: 'contribution' }) });
        }
        await refreshSavingsGoals();
      } catch (error) {
        button.disabled = false;
        setGoalsMessage(error.message || 'Could not delete this contribution.', true);
      }
    }
  });

  document.getElementById('goalsList').addEventListener('submit', async (event) => {
    const form = event.target;
    if (!form.matches('.goal-edit-form, .goal-contribution-form')) return;
    event.preventDefault();
    const goalId = form.dataset.goalId;
    const goal = savingsGoals.find((item) => String(item.id) === goalId);
    if (!goal) return;
    const submitButton = form.querySelector('button[type="submit"]');
    submitButton.disabled = true;
    try {
      if (form.matches('.goal-edit-form')) {
        const inputs = form.querySelectorAll('input');
        const updatedGoal = { title: inputs[0].value.trim(), target_amount: Number(inputs[1].value), deadline: inputs[2].value };
        if (isDemoGoalsMode()) {
          Object.assign(goal, updatedGoal);
          saveDemoGoals();
        } else {
          await supabaseRequest('goals', { method: 'PATCH', body: JSON.stringify({ id: goalId, ...updatedGoal }) });
        }
      } else {
        const amount = Number(form.querySelector('input[type="number"]').value);
        const date = form.querySelector('input[type="date"]').value;
        if (isDemoGoalsMode()) {
          goal.current_amount = Number(goal.current_amount) + amount;
          goal.contributions.push({ id: `demo-${Date.now()}`, amount, contribution_date: date });
          saveDemoGoals();
        } else {
          await supabaseRequest('goals', { method: 'POST', body: JSON.stringify({ action: 'contribution', goal_id: goalId, amount, date }) });
        }
      }
      await refreshSavingsGoals();
    } catch (error) {
      setGoalsMessage(error.message || 'Could not save this goal change.', true);
      submitButton.disabled = false;
    }
  });

  const renderSettingsPage = () => {
    const currentView = location.hash === '#settings' ? 'settings' : location.hash === '#reports' ? 'reports' : location.hash === '#goals' ? 'goals' : 'dashboard';
    document.querySelectorAll('.main-content > :not(#settings):not(#reports):not(#goals):not(#finishSetupCard)').forEach((section) => { section.hidden = currentView !== 'dashboard'; });
    finishSetupCard.hidden = currentView !== 'dashboard' || !shouldShowFinishSetupCard;
    document.getElementById('settings').hidden = currentView !== 'settings';
    document.getElementById('reports').hidden = currentView !== 'reports';
    document.getElementById('goals').hidden = currentView !== 'goals';
    document.querySelectorAll('.nav-link').forEach((link) => link.classList.toggle('active', link.getAttribute('href') === (location.hash || '#dashboard')));
    if (currentView === 'settings' && settingsLoadedUserId !== activeUser?.id) loadSettingsData();
    if (currentView === 'goals') loadSavingsGoals();
    if (currentView === 'reports') {
      document.querySelectorAll('.report-range-btn').forEach((button) => button.classList.toggle('active', !reportUsesCustomRange && button.dataset.reportPeriod === selectedDateRange));
      updateReportsDateInputs();
      requestAnimationFrame(renderReports);
    }
  };
  window.addEventListener('hashchange', renderSettingsPage);
  renderSettingsPage();

  document.getElementById('transactionList').addEventListener('click', async (event) => {
    const button = event.target.closest('.delete-transaction');
    if (!button) return;

    const transactionId = button.dataset.transactionId;
    const transaction = transactions.find((item) => String(item.id) === transactionId);
    if (!transaction || !window.confirm(`Are you sure you want to delete "${transaction.merchant}"?`)) return;

    button.disabled = true;
    try {
      if (activeUser && location.protocol !== 'file:') {
        await supabaseRequest('transactions', {
          method: 'DELETE',
          body: JSON.stringify({ id: transactionId })
        });
        await loadSupabaseTransactions();
      } else {
        localStorage.setItem(storageKey, JSON.stringify(transactions.filter((item) => String(item.id) !== transactionId)));
        transactions = transactions.filter((item) => String(item.id) !== transactionId);
        renderTransactions();
        updateSummary();
      }
      document.getElementById('liveToast').querySelector('.toast-body').innerHTML = '<i class="fa-solid fa-circle-check me-2"></i>Transaction deleted.';
      bootstrap.Toast.getOrCreateInstance(document.getElementById('liveToast')).show();
    } catch (error) {
      button.disabled = false;
      alert(error.message || 'Could not delete this transaction.');
    }
  });

  renderTransactions();
  updateSummary();
  if (storedProfile) {
    setProfile(storedProfile);
  }
  if (location.protocol !== 'file:' && supabaseClient) supabaseRequest('session').then(async (result) => {
    updateProAvailability(result.pro_enabled);
    if (result.requires_verification) {
      openEmailVerification(result.email, 'Please verify your email first.', true);
      return;
    }
    if (!result.user) return;
    supabaseUserAuthenticated = true;
    setProfile(result.user);
    await syncLocalTransactions();
    await loadSupabaseTransactions();
    await showOnboardingIfNeeded();
    if (location.hash === '#goals') await refreshSavingsGoals();
  }).catch((error) => {
    console.error('Supabase session could not be loaded.', error);
  });

  menuToggle.addEventListener('click', () => sidebar.classList.toggle('open'));
  document.querySelectorAll('.nav-link').forEach((link) => link.addEventListener('click', () => sidebar.classList.remove('open')));

  themeToggle.addEventListener('click', () => {
    body.classList.toggle('dark');
    themeToggle.innerHTML = body.classList.contains('dark') ? '<i class="fa-solid fa-sun"></i>' : '<i class="fa-solid fa-moon"></i>';
  });

  document.querySelectorAll('.period-btn').forEach((button) => {
    button.classList.toggle('active', button.dataset.period === selectedDateRange);
    button.addEventListener('click', () => {
      selectedDateRange = button.dataset.period;
      transactionsExpanded = false;
      localStorage.setItem(periodStorageKey, selectedDateRange);
      document.querySelectorAll('.period-btn').forEach((item) => item.classList.toggle('active', item === button));
      document.querySelectorAll('.report-range-btn').forEach((item) => item.classList.toggle('active', !reportUsesCustomRange && item.dataset.reportPeriod === selectedDateRange));
      if (!reportUsesCustomRange) updateReportsDateInputs();
      renderTransactions();
      updateSummary();
    });
  });
  seriesBar.querySelectorAll('.series-btn').forEach((button) => button.addEventListener('click', () => {
    transactionSeries = button.dataset.series;
    transactionsExpanded = false;
    seriesBar.querySelectorAll('.series-btn').forEach((item) => item.classList.toggle('active', item === button));
    renderTransactions();
  }));
  const setupIncomeInputs = [...document.querySelectorAll('.setup-income')];
  const savingsAmountInput = document.getElementById('setupSavings');
  const savingsPercentageInput = document.getElementById('setupSavingsPct');
  const investmentTargetInput = document.getElementById('setupInvestmentTarget');
  savingsPercentageInput.step = 'any';
  savingsPercentageInput.setAttribute('aria-describedby', 'setupSavingsIncomeHint setupSavingsSummary');
  let lastSavingsSource = 'amount';
  const getSetupIncome = () => {
    if (setupIncomeInputs.some((input) => input.validity.badInput || (input.value !== '' && Number(input.value) < 0))) return null;
    return setupIncomeInputs.reduce((sum, input) => sum + (readFiniteInput(input) ?? 0), 0);
  };
  const currentMonthExpenses = () => {
    const monthPrefix = today.slice(0, 7);
    const amounts = transactions
      .filter((transaction) => transaction.type === 'expense' && String(transaction.date || '').startsWith(monthPrefix))
      .map((transaction) => Number(transaction.amount))
      .filter((amount) => Number.isFinite(amount) && amount >= 0);
    return amounts.length ? amounts.reduce((sum, amount) => sum + amount, 0) : null;
  };
  const updateOnboardingGoalHints = () => {
    const income = getSetupIncome();
    const monthlySavings = readFiniteInput(savingsAmountInput);
    const savingsPercentage = readFiniteInput(savingsPercentageInput);
    const monthlyInvestment = readFiniteInput(investmentTargetInput);
    const hasIncome = income !== null && income > 0;
    const savingsPercentageText = savingsPercentage === null
      ? ''
      : String(Math.min(100, Math.max(0, savingsPercentage)));

    savingsIncomeHint.hidden = hasIncome;
    savingsSummary.textContent = hasIncome && monthlySavings !== null && savingsPercentage !== null
      ? `${money(monthlySavings)} is ${savingsPercentageText}% of your ${money(income)} monthly income.`
      : '';
    const investmentShare = hasIncome && monthlyInvestment !== null ? monthlyInvestment / income * 100 : null;
    investmentShareHint.textContent = Number.isFinite(investmentShare)
      ? `${investmentShare.toLocaleString('en-US', { maximumFractionDigits: 1 })}% of your income`
      : '';

    const incomeWarningVisible = hasIncome && monthlySavings !== null && monthlyInvestment !== null && monthlySavings + monthlyInvestment > income;
    incomeGoalWarning.hidden = !incomeWarningVisible;

    const expenses = currentMonthExpenses();
    const emergencyTarget = readFiniteInput(document.getElementById('setupEmergency'));
    const emergencyWarningVisible = expenses !== null && emergencyTarget !== null && emergencyTarget < expenses;
    emergencyFundWarning.textContent = emergencyWarningVisible
      ? `Your emergency fund target is lower than one month of expenses (${money(expenses)}).`
      : '';
    emergencyFundWarning.hidden = !emergencyWarningVisible;
  };
  const syncSavingsFields = (source) => {
    const income = getSetupIncome();
    if (source === 'amount') {
      const amount = readFiniteInput(savingsAmountInput);
      if (amount === null || amount < 0) {
        if (savingsAmountInput.value === '') lastSavingsSource = 'percentage';
        updateOnboardingGoalHints();
        return;
      }
      const percentage = calculateSavingsPercentage(amount, income);
      savingsPercentageInput.value = percentage === null ? '' : percentage.toFixed(1);
    } else {
      const percentage = readFiniteInput(savingsPercentageInput);
      if (percentage === null) {
        if (savingsPercentageInput.value === '') lastSavingsSource = 'amount';
        updateOnboardingGoalHints();
        return;
      }
      if (income === null || income <= 0) {
        savingsPercentageInput.value = '';
        lastSavingsSource = 'amount';
        updateOnboardingGoalHints();
        return;
      }
      const clampedPercentage = Math.min(100, Math.max(0, percentage));
      if (clampedPercentage !== percentage) savingsPercentageInput.value = String(clampedPercentage);
      const amount = calculateSavingsAmount(clampedPercentage, income);
      if (amount !== null) savingsAmountInput.value = String(amount);
    }
    updateOnboardingGoalHints();
  };
  const updateSetupIncome = () => {
    const total = setupIncomeInputs.reduce((sum, input) => sum + Math.max(0, readFiniteInput(input) ?? 0), 0);
    document.getElementById('setupIncomeTotal').textContent = money(total);
    syncSavingsFields(lastSavingsSource);
  };
  setupIncomeInputs.forEach((input) => input.addEventListener('input', updateSetupIncome));
  [savingsAmountInput, savingsPercentageInput].forEach((input) => {
    input.addEventListener('input', () => {
      lastSavingsSource = input === savingsAmountInput ? 'amount' : 'percentage';
      syncSavingsFields(lastSavingsSource);
    });
    input.addEventListener('change', () => {
      if (input.value.trim() === '' || input.validity.badInput) {
        updateOnboardingGoalHints();
        return;
      }
      syncSavingsFields(lastSavingsSource);
    });
  });
  [investmentTargetInput, document.getElementById('setupEmergency')].forEach((input) => {
    input.addEventListener('input', updateOnboardingGoalHints);
    input.addEventListener('change', updateOnboardingGoalHints);
  });
  if (getSetupIncome() === null || getSetupIncome() <= 0) savingsPercentageInput.value = '';
  updateOnboardingGoalHints();
  document.getElementById('setupCurrency').addEventListener('change', (event) => {
    applyCurrency(event.target.value);
    renderTransactions();
    updateSummary();
    updateOnboardingGoalHints();
  });
  document.querySelectorAll('.allocation-value').forEach((input) => input.addEventListener('input', refreshAllocationChart));
  document.getElementById('wizardBack').addEventListener('click', () => showWizardStep(wizardIndex - 1));
  document.getElementById('wizardNext').addEventListener('click', () => showWizardStep(wizardIndex + 1));
  document.getElementById('closeOnboardingButton').addEventListener('click', () => {
    if (!window.confirm('Skip setup for now? You can finish it later from the dashboard.')) return;
    onboardingModal.hide();
    if (activeUser?.id) sessionStorage.removeItem(finishSetupDismissedKey(activeUser.id));
    shouldShowFinishSetupCard = !onboardingIsComplete && Boolean(activeUser && supabaseUserAuthenticated);
    finishSetupCard.hidden = !shouldShowFinishSetupCard || Boolean(location.hash && location.hash !== '#dashboard');
  });
  document.getElementById('finishSetupButton').addEventListener('click', openFinancialProfile);
  document.getElementById('dismissFinishSetup').addEventListener('click', () => {
    shouldShowFinishSetupCard = false;
    finishSetupCard.hidden = true;
    if (activeUser?.id) sessionStorage.setItem(finishSetupDismissedKey(activeUser.id), 'dismissed');
  });
  document.getElementById('settingsOpenOnboarding').addEventListener('click', openFinancialProfile);
  document.getElementById('onboardingForm').addEventListener('submit', async (event) => {
    event.preventDefault();
    syncSavingsFields(lastSavingsSource);
    const allocations = Object.fromEntries([...document.querySelectorAll('.allocation-value')].map((input) => [input.dataset.category, Number(input.value) || 0]));
    const payload = {
      full_name: document.getElementById('setupFullName').value.trim(),
      age: Number(document.getElementById('setupAge').value) || 0,
      occupation: document.getElementById('setupOccupation').value.trim(),
      city: document.getElementById('setupCity').value.trim(),
      currency: document.getElementById('setupCurrency').value,
      monthly_salary: Number(document.getElementById('setupSalary').value) || 0,
      business_income: Number(document.getElementById('setupBusiness').value) || 0,
      freelance_income: Number(document.getElementById('setupFreelance').value) || 0,
      other_income: Number(document.getElementById('setupOther').value) || 0,
      monthly_income: [...document.querySelectorAll('.setup-income')].reduce((sum, item) => sum + (Number(item.value) || 0), 0),
      monthly_savings_goal: Number(document.getElementById('setupSavings').value) || 0,
      monthly_investment_target: Number(document.getElementById('setupInvestmentTarget').value) || 0,
      savings_percentage: Number(document.getElementById('setupSavingsPct').value) || 0,
      emergency_fund_target: Number(document.getElementById('setupEmergency').value) || 0,
      goal_deadline: document.getElementById('setupDeadline').value,
      dream_goal: document.getElementById('setupDreamGoal').value.trim(),
      monthly_investment_amount: Number(document.getElementById('setupInvestment').value) || 0,
      investment_types: [...document.querySelectorAll('.choice-grid input:checked')].map((input) => input.value),
      budget_allocations: allocations,
      lifestyle: document.getElementById('setupLifestyle').value,
      spending_style: document.querySelector('input[name="setupStyle"]:checked').value
    };
    try {
      await supabaseRequest('onboarding', { method: 'POST', body: JSON.stringify(payload) });
      applyCurrency(payload.currency);
      categoryBudgetAllocations = allocations;
      if (payload.full_name) setProfile({ ...activeUser, name: payload.full_name });
      onboardingIsComplete = true;
      shouldShowFinishSetupCard = false;
      finishSetupCard.hidden = true;
      if (activeUser?.id) {
        localStorage.removeItem(onboardingPromptedKey(activeUser.id));
        sessionStorage.removeItem(finishSetupDismissedKey(activeUser.id));
      }
      await loadSupabaseTransactions();
      onboardingModal.hide();
      document.getElementById('liveToast').querySelector('.toast-body').innerHTML = '<i class="fa-solid fa-circle-check me-2"></i>Your financial blueprint is ready.';
      bootstrap.Toast.getOrCreateInstance(document.getElementById('liveToast')).show();
    } catch (error) { alert(error.message); }
  });
  document.getElementById('refreshPrediction').addEventListener('click', async () => {
    if (activeUser && location.protocol !== 'file:') await loadSupabaseTransactions();
    else updateSummary();
    bootstrap.Toast.getOrCreateInstance(document.getElementById('liveToast')).show();
  });

  const chart = document.getElementById('cashflowChart');
  document.getElementById('setupIncomeTotal').textContent = money(0);
  cashflowChart = new Chart(chart, { type: 'line', data: { labels: [], datasets: [{ label: 'Income', data: [], borderColor: '#7fbe7e', backgroundColor: 'rgba(169,239,207,.18)', fill: true, tension: .4, pointRadius: 0, borderWidth: 2 }, { label: 'Expenses', data: [], borderColor: '#e7a467', backgroundColor: 'rgba(255,184,118,.08)', fill: true, tension: .4, pointRadius: 0, borderWidth: 2 }] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: { mode: 'index', intersect: false, displayColors: false, callbacks: { label: (context) => ` ${currencyConfig.symbol} ${context.parsed.y.toLocaleString()}` } } }, scales: { x: { title: { display: true, text: '' }, grid: { display: false }, ticks: { color: '#a5adaf', font: { size: 10 } } }, y: { border: { display: false }, grid: { color: '#edf0ef' }, ticks: { color: '#a5adaf', font: { size: 10 }, callback: (value) => `${currencyConfig.symbol} ${value / 1000}k` } } } } });
  updateSummary();

  const verificationModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('emailVerificationModal'));
  const verificationEmailInput = document.getElementById('verificationEmail');
  const verificationOtpInput = document.getElementById('verificationOtp');
  const verificationMessage = document.getElementById('emailVerificationMessage');
  const verificationIntro = document.getElementById('emailVerificationIntro');
  const resendVerificationButton = document.getElementById('resendVerificationButton');
  const changeVerificationEmailButton = document.getElementById('changeVerificationEmailButton');
  const verificationSpamHint = document.createElement('small');
  verificationSpamHint.className = 'd-block text-muted mt-2';
  verificationSpamHint.textContent = "Didn't get the email? Check your spam or Promotions folder.";
  document.getElementById('emailVerificationMessage').after(verificationSpamHint);
  let verificationCooldownTimer = null;
  let verificationChangeMode = false;
  const setVerificationCooldown = (seconds = 60) => {
    window.clearInterval(verificationCooldownTimer);
    let remaining = Math.max(0, Number(seconds) || 0);
    resendVerificationButton.disabled = remaining > 0;
    resendVerificationButton.textContent = remaining > 0 ? `Resend code (${remaining}s)` : 'Resend code';
    if (remaining === 0) return;
    verificationCooldownTimer = window.setInterval(() => {
      remaining -= 1;
      resendVerificationButton.disabled = remaining > 0;
      resendVerificationButton.textContent = remaining > 0 ? `Resend code (${remaining}s)` : 'Resend code';
      if (remaining <= 0) window.clearInterval(verificationCooldownTimer);
    }, 1000);
  };
  const openEmailVerification = (email, message, sendOtp = false) => {
    verificationEmailInput.value = email || '';
    verificationEmailInput.readOnly = true;
    verificationOtpInput.value = '';
    verificationMessage.textContent = message || 'A verification code has been sent to your email.';
    verificationSpamHint.hidden = false;
    verificationIntro.textContent = 'Enter the 6-digit code from Supabase to verify your email address.';
    changeVerificationEmailButton.textContent = 'Use a different email';
    verificationChangeMode = false;
    setVerificationCooldown(sendOtp ? 0 : 60);
    verificationModal.show();
    if (sendOtp) resendVerificationButton.click();
  };
  resendVerificationButton.addEventListener('click', async () => {
    const email = verificationEmailInput.value.trim();
    if (!email) {
      verificationMessage.textContent = 'Enter your email address first.';
      return;
    }
    resendVerificationButton.disabled = true;
    try {
      if (!supabaseClient) throw new Error('Supabase is not configured. Please contact the site administrator.');
      if (verificationChangeMode) {
        const { error } = await supabaseClient.auth.updateUser({ email });
        if (error) throw error;
        verificationMessage.textContent = 'A confirmation code was sent to the new email address.';
      } else {
        const { error } = await supabaseClient.auth.resend({ type: 'signup', email });
        if (error) throw error;
        verificationMessage.textContent = 'A verification code has been sent to your email.';
      }
      verificationEmailInput.readOnly = true;
      verificationChangeMode = false;
      changeVerificationEmailButton.textContent = 'Use a different email';
      verificationSpamHint.hidden = false;
      verificationOtpInput.value = '';
      setVerificationCooldown(60);
    } catch (error) {
      verificationMessage.textContent = error.message || 'Could not send a verification code. Please try again.';
      setVerificationCooldown(60);
    }
  });
  changeVerificationEmailButton.addEventListener('click', () => {
    if (verificationChangeMode) {
      resendVerificationButton.click();
      return;
    }
    verificationChangeMode = true;
    verificationEmailInput.readOnly = false;
    verificationEmailInput.focus();
    changeVerificationEmailButton.textContent = 'Send code to this email';
    verificationMessage.textContent = 'Enter the email address to use, then send a confirmation code.';
    setVerificationCooldown(0);
  });
  document.getElementById('emailVerificationSignOut').addEventListener('click', async () => {
    const button = document.getElementById('emailVerificationSignOut');
    button.disabled = true;
    try {
      if (!supabaseClient) throw new Error('Supabase is not configured.');
      const { error } = await supabaseClient.auth.signOut();
      if (error) throw error;
      window.location.reload();
    } catch (error) {
      verificationMessage.textContent = error.message || 'Could not sign out. Please try again.';
      button.disabled = false;
    }
  });
  document.getElementById('verifyEmailButton').addEventListener('click', async () => {
    const email = verificationEmailInput.value.trim();
    const otp = verificationOtpInput.value.trim();
    if (!email || !/^\d{6}$/.test(otp)) {
      verificationMessage.textContent = 'Enter your email and the 6-digit code.';
      return;
    }
    const button = document.getElementById('verifyEmailButton');
    button.disabled = true;
    try {
      if (!supabaseClient) throw new Error('Supabase is not configured. Please contact the site administrator.');
      const { data, error } = await supabaseClient.auth.verifyOtp({ email, token: otp, type: 'signup' });
      if (error) throw error;
      const result = await supabaseRequest('session');
      if (!result.user || !data.user) throw new Error('Email confirmation succeeded, but your Finora profile could not be loaded.');
      supabaseUserAuthenticated = true;
      setProfile(result.user);
      localStorage.setItem('finora-profile', JSON.stringify(result.user));
      verificationModal.hide();
      sessionStorage.removeItem(finishSetupDismissedKey(result.user.id));
      await syncLocalTransactions();
      await loadSupabaseTransactions();
      await showOnboardingIfNeeded();
      if (location.hash === '#goals') await refreshSavingsGoals();
    } catch (error) {
      verificationMessage.textContent = error.message || 'Could not verify this code. Please try again.';
    } finally {
      button.disabled = false;
    }
  });

  const transactionModalElement = document.getElementById('transactionModal');
  const transactionDateInput = document.getElementById('transactionDate');
  const transactionTimeInput = document.getElementById('transactionTime');
  let autoFilledTransactionTime = '';
  transactionModalElement.addEventListener('shown.bs.modal', () => {
    const now = currentLocalDateTime();
    transactionDateInput.value = now.date;
    transactionTimeInput.value = now.time;
    autoFilledTransactionTime = now.time;
  });
  transactionDateInput.addEventListener('change', () => {
    const selectedDate = transactionDateInput.value;
    const now = currentLocalDateTime();
    if (selectedDate !== now.date && transactionTimeInput.value === autoFilledTransactionTime) {
      transactionTimeInput.value = '';
      autoFilledTransactionTime = '';
    } else if (selectedDate === now.date && !transactionTimeInput.value) {
      transactionTimeInput.value = now.time;
      autoFilledTransactionTime = now.time;
    }
  });
  transactionTimeInput.addEventListener('input', () => {
    if (transactionTimeInput.value !== autoFilledTransactionTime) autoFilledTransactionTime = '';
  });

  let categorySuggestionTimer = null;
  let categorySuggestionSequence = 0;
  const showCategorySuggestion = (suggestion) => {
    const hint = document.getElementById('categoryHint');
    const message = document.getElementById('aiClassification');
    hint.textContent = suggestion.uncertain ? 'Pakka nahi hun' : suggestion.source === 'personal' ? 'Your preference' : 'Suggested';
    const icon = document.createElement('i');
    icon.className = 'fa-solid fa-sparkles';
    const text = document.createElement('span');
    text.textContent = suggestion.uncertain
      ? 'Pakka nahi hun — Other. Please choose a category.'
      : `Suggested category: ${suggestion.category}${suggestion.source === 'personal' ? ' · your saved preference' : ''}`;
    message.replaceChildren(icon, text);
  };

  document.getElementById('transactionForm').addEventListener('submit', async (event) => {
    event.preventDefault();
    const merchant = document.getElementById('merchant').value.trim();
    const amount = Number(document.getElementById('amount').value).toFixed(2);
    const type = document.querySelector('input[name="type"]:checked').value;
    const category = document.getElementById('category').value;
    const date = document.getElementById('transactionDate').value;
    const enteredTime = transactionTimeInput.value;
    const saveDateTime = currentLocalDateTime();
    const saveTime = enteredTime || (date === saveDateTime.date ? saveDateTime.time : '');
    const suggestion = category ? null : await getMerchantSuggestion(merchant);
    const resolvedCategory = category || suggestion.category;
    const transaction = { id: Date.now(), merchant, amount: Number(amount), type, category: resolvedCategory, category_explicit: category !== '', date, time: saveTime, payment_method: document.getElementById('paymentMethod').value, location: document.getElementById('location').value.trim(), notes: document.getElementById('transactionNotes').value.trim(), category_confidence: category ? 100 : (suggestion.category === 'Other' ? 0 : (suggestion.source === 'fuzzy' ? 75 : 91)) };
    let savedCategoryLearning = null;
    if (activeUser && location.protocol !== 'file:') {
      try {
        const result = await supabaseRequest('transactions', { method: 'POST', body: JSON.stringify(transaction) });
        transactions.push(result.transaction);
        savedCategoryLearning = result.category_learning;
        await loadSupabaseTransactions();
      } catch (error) { alert(error.message); return; }
    } else {
      transactions.push(transaction);
      localStorage.setItem(storageKey, JSON.stringify(transactions));
    }
    renderTransactions();
    updateSummary();
    bootstrap.Modal.getInstance(document.getElementById('transactionModal')).hide();
    document.getElementById('transactionForm').reset();
    const nextTransactionDefaults = currentLocalDateTime();
    transactionDateInput.value = nextTransactionDefaults.date;
    transactionTimeInput.value = nextTransactionDefaults.time;
    autoFilledTransactionTime = nextTransactionDefaults.time;
    document.getElementById('categoryHint').textContent = 'AI ready';
    if (savedCategoryLearning?.reason === 'limit_reached') {
      document.getElementById('liveToast').querySelector('.toast-body').textContent = 'Transaction saved. The 500 saved-category rule limit was reached.';
    } else if (savedCategoryLearning?.reason === 'save_failed') {
      document.getElementById('liveToast').querySelector('.toast-body').textContent = 'Transaction saved, but the category preference could not be saved. Please try again.';
    } else {
      document.getElementById('liveToast').querySelector('.toast-body').textContent = 'Transaction added successfully.';
    }
    bootstrap.Toast.getOrCreateInstance(document.getElementById('liveToast')).show();
  });
  document.getElementById('merchant').addEventListener('input', (event) => {
    const merchant = event.target.value.trim();
    const sequence = ++categorySuggestionSequence;
    window.clearTimeout(categorySuggestionTimer);
    const categoryInput = document.getElementById('category');
    if (categoryInput.value || !merchant) return;
    categorySuggestionTimer = window.setTimeout(async () => {
      const suggestion = await getMerchantSuggestion(merchant);
      if (sequence === categorySuggestionSequence && document.getElementById('merchant').value.trim() === merchant && !categoryInput.value) {
        showCategorySuggestion(suggestion);
      }
    }, 250);
  });
  document.getElementById('category').addEventListener('change', (event) => {
    if (event.target.value) {
      showCategorySuggestion({ category: event.target.value, source: 'personal', uncertain: false });
      document.getElementById('aiClassification').querySelector('span').textContent = `Category selected: ${event.target.value}. This preference will be saved for this merchant.`;
    }
  });

  document.getElementById('accountForm').addEventListener('submit', async (event) => {
    event.preventDefault();
    const name = document.getElementById('accountName').value.trim();
    const email = document.getElementById('accountEmail').value.trim();
    const password = document.getElementById('accountPassword').value;
    if (location.protocol === 'file:') {
      const user = { id: 'demo-user', name, email };
      localStorage.setItem('finora-profile', JSON.stringify(user));
      setProfile(user);
      await syncLocalTransactions();
      bootstrap.Modal.getInstance(document.getElementById('accountModal')).hide();
      document.getElementById('accountForm').reset();
      const toast = document.getElementById('liveToast');
      const toastBody = toast.querySelector('.toast-body');
      const icon = document.createElement('i');
      icon.className = 'fa-solid fa-circle-check me-2';
      toastBody.replaceChildren(icon, document.createTextNode('Account created on this device.'));
      bootstrap.Toast.getOrCreateInstance(toast).show();
      showOnboardingIfNeeded();
      return;
    }
    try {
      if (!supabaseClient) throw new Error('Supabase is not configured. Please contact the site administrator.');
      const { data, error } = await supabaseClient.auth.signUp({
        email,
        password,
        options: { data: { name } }
      });
      if (error) throw error;
      supabaseUserAuthenticated = false;
      bootstrap.Modal.getInstance(document.getElementById('accountModal')).hide();
      document.getElementById('accountForm').reset();
      openEmailVerification(email, data.session
        ? 'Your account was created. Verify your email before continuing.'
        : 'Check your email for a 6-digit Supabase verification code.');
    } catch (error) {
      alert(error.message || 'Could not create your account.');
    }
  });

  const loginError = document.getElementById('loginError');
  const forgotPasswordForm = document.getElementById('forgotPasswordForm');
  const forgotPasswordLink = document.getElementById('forgotPasswordLink');
  const forgotPasswordModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('forgotPasswordModal'));
  const forgotPasswordInitialStep = document.getElementById('forgotPasswordInitialStep');
  const forgotPasswordOtpStep = document.getElementById('forgotPasswordOtpStep');
  const forgotPasswordSendButton = document.getElementById('forgotPasswordSendButton');
  const forgotPasswordSubmitButton = document.getElementById('forgotPasswordSubmitButton');
  const forgotPasswordEmail = document.getElementById('forgotPasswordEmail');
  const forgotPasswordOtp = document.getElementById('forgotPasswordOtp');
  const forgotPasswordNewPassword = document.getElementById('forgotPasswordNewPassword');
  const forgotPasswordMessage = document.getElementById('forgotPasswordMessage');
  let supabasePasswordRecoveryLink = false;
  const forgotPasswordSpamHint = document.createElement('small');
  forgotPasswordSpamHint.className = 'd-block text-muted mt-2';
  forgotPasswordSpamHint.textContent = "Didn't get the email? Check your spam or Promotions folder.";
  forgotPasswordMessage.after(forgotPasswordSpamHint);
  if (supabaseClient) {
    supabaseClient.auth.onAuthStateChange((event) => {
      if (event !== 'PASSWORD_RECOVERY') return;
      window.setTimeout(() => {
        supabasePasswordRecoveryLink = true;
        forgotPasswordInitialStep.classList.add('d-none');
        forgotPasswordOtpStep.classList.remove('d-none');
        document.getElementById('forgotPasswordOtpGroup').hidden = true;
        forgotPasswordSubmitButton.classList.remove('d-none');
        forgotPasswordMessage.textContent = 'Choose a new password for your account.';
        forgotPasswordModal.show();
      }, 0);
    });
  }

  const resetForgotPasswordForm = () => {
    supabasePasswordRecoveryLink = false;
    document.getElementById('forgotPasswordOtpGroup').hidden = false;
    forgotPasswordInitialStep.classList.remove('d-none');
    forgotPasswordOtpStep.classList.add('d-none');
    forgotPasswordSendButton.classList.remove('d-none');
    forgotPasswordSubmitButton.classList.add('d-none');
    forgotPasswordEmail.value = document.getElementById('loginEmail').value.trim();
    forgotPasswordOtp.value = '';
    forgotPasswordNewPassword.value = '';
    forgotPasswordMessage.textContent = 'A password reset code will be sent to your email address.';
    forgotPasswordSpamHint.hidden = true;
  };

  forgotPasswordLink.addEventListener('click', () => {
    resetForgotPasswordForm();
    forgotPasswordModal.show();
  });

  forgotPasswordSendButton.addEventListener('click', async () => {
    const email = forgotPasswordEmail.value.trim();
    if (!email) {
      alert('Please enter the email address linked to your account.');
      return;
    }
    try {
      if (!supabaseClient) throw new Error('Supabase is not configured. Please contact the site administrator.');
      const { error } = await supabaseClient.auth.resetPasswordForEmail(email, {
        redirectTo: `${location.origin}${location.pathname}`
      });
      if (error) throw error;
      forgotPasswordInitialStep.classList.add('d-none');
      forgotPasswordOtpStep.classList.remove('d-none');
      forgotPasswordSendButton.classList.add('d-none');
      forgotPasswordSubmitButton.classList.remove('d-none');
      forgotPasswordMessage.textContent = 'A password recovery code has been sent if this email is registered.';
      forgotPasswordSpamHint.hidden = false;
      forgotPasswordOtp.focus();
    } catch (error) {
      alert(error.message);
    }
  });

  document.getElementById('forgotPasswordForm').addEventListener('submit', async (event) => {
    event.preventDefault();
    const email = forgotPasswordEmail.value.trim();
    const otp = forgotPasswordOtp.value.trim();
    const password = forgotPasswordNewPassword.value;
    if (!email || !otp || !password) {
      alert('Please enter the code and a new password.');
      return;
    }
    try {
      if (!supabaseClient) throw new Error('Supabase is not configured. Please contact the site administrator.');
      if (!supabasePasswordRecoveryLink) {
        const { error: verifyError } = await supabaseClient.auth.verifyOtp({ email, token: otp, type: 'recovery' });
        if (verifyError) throw verifyError;
      }
      const { error: updateError } = await supabaseClient.auth.updateUser({ password });
      if (updateError) throw updateError;
      supabasePasswordRecoveryLink = false;
      document.getElementById('forgotPasswordOtpGroup').hidden = false;
      forgotPasswordModal.hide();
      forgotPasswordForm.reset();
      document.getElementById('loginEmail').value = email;
      document.getElementById('loginPassword').value = '';
      loginError.classList.add('d-none');
      loginError.textContent = '';
      forgotPasswordLink.classList.add('d-none');
      const toast = document.getElementById('liveToast');
      const toastBody = toast.querySelector('.toast-body');
      const icon = document.createElement('i');
      icon.className = 'fa-solid fa-circle-check me-2';
      toastBody.replaceChildren(icon, document.createTextNode('Password reset successful. You can now sign in with your new password.'));
      bootstrap.Toast.getOrCreateInstance(toast).show();
    } catch (error) {
      alert(error.message);
    }
  });

  document.getElementById('loginForm').addEventListener('submit', async (event) => {
    event.preventDefault();
    try {
      if (location.protocol === 'file:') {
        const name = document.getElementById('loginEmail').value.split('@')[0] || 'Demo user';
        const user = { id: 'demo-user', name, email: document.getElementById('loginEmail').value };
        setProfile(user);
        localStorage.setItem('finora-profile', JSON.stringify(user));
        bootstrap.Modal.getInstance(document.getElementById('loginModal')).hide();
        await syncLocalTransactions();
        showOnboardingIfNeeded();
        return;
      }
      if (!supabaseClient) throw new Error('Supabase is not configured. Please contact the site administrator.');
      const { data, error } = await supabaseClient.auth.signInWithPassword({
        email: document.getElementById('loginEmail').value.trim(),
        password: document.getElementById('loginPassword').value
      });
      if (error) throw error;
      if (!data.user?.email_confirmed_at) {
        await supabaseClient.auth.signOut();
        openEmailVerification(data.user?.email || document.getElementById('loginEmail').value.trim(), 'Please verify your email before signing in.');
        return;
      }
      const result = await supabaseRequest('session');
      if (!result.user) throw new Error('Your Supabase account is not linked to a Finora profile. Contact support to complete account migration.');
      supabaseUserAuthenticated = true;
      loginError.classList.add('d-none');
      loginError.textContent = '';
      forgotPasswordLink.classList.add('d-none');
      setProfile(result.user);
      sessionStorage.removeItem(finishSetupDismissedKey(result.user.id));
      await syncLocalTransactions();
      await loadSupabaseTransactions();
      if (location.hash === '#goals') await refreshSavingsGoals();
      bootstrap.Modal.getInstance(document.getElementById('loginModal')).hide();
      await showOnboardingIfNeeded();
      bootstrap.Toast.getOrCreateInstance(document.getElementById('liveToast')).show();
    } catch (error) {
      const message = error.message || 'Email or password is incorrect.';
      if (error.code === 'email_not_confirmed' || /email not confirmed/i.test(message)) {
        bootstrap.Modal.getInstance(document.getElementById('loginModal')).hide();
        openEmailVerification(document.getElementById('loginEmail').value.trim(), 'Please verify your email before signing in.', true);
        return;
      }
      loginError.textContent = message;
      loginError.classList.remove('d-none');
      forgotPasswordLink.classList.remove('d-none');
      resetForgotPasswordForm();
      alert(message);
    }
  });

  const jarvisMessages = document.getElementById('jarvisMessages');
  const jarvisInput = document.getElementById('jarvisInput');
  const jarvisForm = document.getElementById('jarvisForm');
  const jarvisToggle = document.getElementById('jarvisToggle');
  const jarvisWindow = document.getElementById('jarvisWindow');
  const jarvisClose = document.getElementById('jarvisClose');

  const addJarvisMessage = (text, sender = 'bot') => {
    const message = document.createElement('div');
    message.className = `jarvis-message ${sender}`;
    if (sender === 'bot') {
      const avatar = document.createElement('img');
      avatar.className = 'jarvis-message-avatar';
      avatar.src = 'assets/img/jarvis.svg';
      avatar.alt = '';
      avatar.setAttribute('aria-hidden', 'true');
      const messageText = document.createElement('span');
      messageText.className = 'jarvis-message-text';
      messageText.textContent = text;
      message.append(avatar, messageText);
    } else {
      message.textContent = text;
    }
    jarvisMessages.appendChild(message);
    jarvisMessages.scrollTop = jarvisMessages.scrollHeight;
    return message;
  };

  const jarvisLooksLikeAction = (text) => /\b(add|record|log|spent|spend|paid|expense|expence|expens|income|salary|received|earned|credited|credit|freelance|aayi|aaya|mila|grocery|groceries|kharch|kharcha|contribut|delete|remove)\b/i.test(text)
    || /\b(set|change)\b.*\b(budget|limit)\b|\b(edit|update)\b.*\btransaction\b/i.test(text)
    || /\b\d[\d,]*(?:\.\d{1,2})?\s+ka\b/i.test(text);

  const jarvisActionLabel = (action) => ({
    add_expense: 'Add expense',
    add_income: 'Add income',
    edit_transaction: 'Edit transaction',
    add_goal_contribution: 'Contribute to savings goal',
    set_budget: 'Set monthly budget'
  })[action.type] || 'Review action';

  const renderJarvisActionCard = (proposal, proposalToken) => {
    const card = document.createElement('section');
    card.className = 'jarvis-action-card';
    const title = document.createElement('strong');
    title.className = 'jarvis-action-title';
    title.textContent = jarvisActionLabel(proposal);
    card.append(title);

    const details = proposal.type === 'edit_transaction' ? proposal.transaction : proposal;
    const addDetail = (labelText, value) => {
      const row = document.createElement('div');
      row.className = 'jarvis-action-detail';
      const label = document.createElement('span');
      label.textContent = labelText;
      const content = document.createElement('strong');
      content.textContent = String(value ?? '');
      row.append(label, content);
      card.append(row);
    };
    if (proposal.type === 'add_expense' || proposal.type === 'add_income' || proposal.type === 'edit_transaction') {
      addDetail('Title', details.merchant);
      addDetail('Amount', `${currencyConfig.symbol} ${Number(details.amount).toFixed(2)}`);
      addDetail('Category', details.category);
      addDetail('Date', details.date);
    } else if (proposal.type === 'add_goal_contribution') {
      addDetail('Goal', proposal.goal_name);
      addDetail('Amount', `${currencyConfig.symbol} ${Number(proposal.amount).toFixed(2)}`);
      addDetail('Date', proposal.date);
    } else {
      addDetail('Monthly budget', `${currencyConfig.symbol} ${Number(proposal.amount).toFixed(2)}`);
    }

    const editForm = document.createElement('form');
    editForm.className = 'jarvis-action-edit';
    editForm.hidden = true;
    const fields = {};
    const addInput = (key, labelText, value, inputType = 'text') => {
      const label = document.createElement('label');
      label.textContent = labelText;
      const input = document.createElement('input');
      input.className = 'form-control';
      input.type = inputType;
      input.value = String(value ?? '');
      if (key === 'amount') {
        input.min = '0.01';
        input.step = '0.01';
        input.required = true;
      }
      if (key === 'date') {
        input.required = true;
      }
      fields[key] = input;
      label.append(input);
      editForm.append(label);
    };
    if (proposal.type === 'add_expense' || proposal.type === 'add_income' || proposal.type === 'edit_transaction') {
      addInput('merchant', 'Title', details.merchant);
      addInput('amount', 'Amount', details.amount, 'number');
      addInput('category', 'Category', details.category);
      addInput('date', 'Date', details.date, 'date');
    } else {
      addInput('amount', 'Amount', proposal.amount, 'number');
      if (proposal.type === 'add_goal_contribution') addInput('date', 'Date', proposal.date, 'date');
    }
    const reviseButton = document.createElement('button');
    reviseButton.className = 'jarvis-action-button secondary';
    reviseButton.type = 'submit';
    reviseButton.textContent = 'Preview edits';
    editForm.append(reviseButton);
    card.append(editForm);

    const controls = document.createElement('div');
    controls.className = 'jarvis-action-controls';
    const confirmButton = document.createElement('button');
    confirmButton.className = 'jarvis-action-button primary';
    confirmButton.type = 'button';
    confirmButton.textContent = 'Confirm';
    const editButton = document.createElement('button');
    editButton.className = 'jarvis-action-button secondary';
    editButton.type = 'button';
    editButton.textContent = 'Edit';
    const cancelButton = document.createElement('button');
    cancelButton.className = 'jarvis-action-button secondary';
    cancelButton.type = 'button';
    cancelButton.textContent = 'Cancel';
    controls.append(confirmButton, editButton, cancelButton);
    card.append(controls);
    const status = document.createElement('div');
    status.className = 'jarvis-action-status';
    status.setAttribute('role', 'status');
    card.append(status);
    const setBusy = (busy) => controls.querySelectorAll('button').forEach((button) => { button.disabled = busy; });

    confirmButton.addEventListener('click', async () => {
      setBusy(true);
      try {
        const result = await supabaseRequest('jarvis-action', {
          method: 'POST',
          body: JSON.stringify({ mode: 'confirm', proposal_token: proposalToken })
        });
        status.textContent = result.message;
        if (result.category_learning?.reason === 'limit_reached') {
          status.textContent += ' The 500 saved-category rule limit was reached.';
        } else if (result.category_learning?.reason === 'save_failed') {
          status.textContent += ' The category preference could not be saved; the transaction action succeeded.';
        } else if (result.category_learning?.saved) {
          status.textContent += ' I will remember this category for the merchant.';
        }
        controls.remove();
        editForm.remove();
        if (result.undo_token) renderJarvisUndo(result.undo_token, result.message, card, status);
        try {
          if (['add_expense', 'add_income', 'edit_transaction'].includes(result.action_type)) await loadSupabaseTransactions();
          if (result.action_type === 'add_goal_contribution') await refreshSavingsGoals();
          if (result.action_type === 'set_budget') await loadSettingsData();
        } catch (refreshError) {
          status.textContent = `${result.message} Reload the page if the updated view does not appear.`;
        }
      } catch (error) {
        status.textContent = error.message || 'Could not confirm this action.';
        setBusy(false);
      }
    });
    editButton.addEventListener('click', () => {
      editForm.hidden = !editForm.hidden;
      editButton.textContent = editForm.hidden ? 'Edit' : 'Close edit';
    });
    editForm.addEventListener('submit', async (event) => {
      event.preventDefault();
      setBusy(true);
      reviseButton.disabled = true;
      try {
        const values = Object.fromEntries(Object.entries(fields).map(([key, input]) => [key, key === 'amount' ? Number(input.value) : input.value]));
        const result = await supabaseRequest('jarvis-action', {
          method: 'POST',
          body: JSON.stringify({ mode: 'revise', proposal_token: proposalToken, fields: values })
        });
        card.replaceWith(renderJarvisActionCard(result.proposal, result.proposal_token));
      } catch (error) {
        status.textContent = error.message || 'Could not update the action preview.';
        reviseButton.disabled = false;
        setBusy(false);
      }
    });
    cancelButton.addEventListener('click', async () => {
      setBusy(true);
      try {
        await supabaseRequest('jarvis-action', {
          method: 'POST',
          body: JSON.stringify({ mode: 'cancel', proposal_token: proposalToken })
        });
        status.textContent = 'Action cancelled. Nothing was changed.';
        controls.remove();
        editForm.remove();
      } catch (error) {
        status.textContent = error.message || 'Could not cancel this action.';
        setBusy(false);
      }
    });
    jarvisMessages.append(card);
    jarvisMessages.scrollTop = jarvisMessages.scrollHeight;
    return card;
  };

  const renderJarvisUndo = (undoToken, message, container = null, status = null) => {
    const bubble = container ? null : addJarvisMessage(message);
    const messageStatus = status || bubble.querySelector('.jarvis-message-text');
    const undoButton = document.createElement('button');
    undoButton.className = 'jarvis-action-button secondary jarvis-undo-button';
    undoButton.type = 'button';
    undoButton.textContent = 'Undo';
    (container || bubble).append(undoButton);
    const expiryTimer = window.setTimeout(() => {
      undoButton.remove();
      if (messageStatus.isConnected) messageStatus.textContent = 'Undo window expired.';
    }, 120000);
    undoButton.addEventListener('click', async () => {
      undoButton.disabled = true;
      try {
        const result = await supabaseRequest('jarvis-action', {
          method: 'POST',
          body: JSON.stringify({ mode: 'undo', undo_token: undoToken })
        });
        window.clearTimeout(expiryTimer);
        messageStatus.textContent = result.message;
        undoButton.remove();
        try {
          await loadSupabaseTransactions();
        } catch (refreshError) {
          messageStatus.textContent = `${result.message} Reload the page if the updated view does not appear.`;
        }
      } catch (error) {
        messageStatus.textContent = error.message || 'Could not undo this Jarvis addition.';
        if (/invalid or expired|can no longer be undone/i.test(messageStatus.textContent)) {
          window.clearTimeout(expiryTimer);
          undoButton.remove();
        } else {
          undoButton.disabled = false;
        }
      }
    });
  };

  const toggleJarvis = (isOpen) => {
    jarvisWindow.classList.toggle('open', isOpen);
    jarvisToggle.setAttribute('aria-expanded', String(isOpen));
  };

  jarvisToggle.addEventListener('click', () => toggleJarvis(!jarvisWindow.classList.contains('open')));
  jarvisClose.addEventListener('click', () => toggleJarvis(false));

  jarvisForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const question = jarvisInput.value.trim();
    if (!question) return;
    addJarvisMessage(question, 'user');
    jarvisInput.value = '';

    const submitButton = jarvisForm.querySelector('button[type="submit"]');
    submitButton.disabled = true;

    try {
      if (!supabaseUserAuthenticated || !activeUser?.id) {
        addJarvisMessage('Sign in to get answers based on your financial data.', 'bot');
        return;
      }
      if (jarvisLooksLikeAction(question)) {
        const result = await supabaseRequest('jarvis-action', {
          method: 'POST',
          body: JSON.stringify({ mode: 'preview', command: question })
        });
        if (result.auto_added) {
          renderJarvisUndo(result.undo_token, result.message);
          await loadSupabaseTransactions();
        } else {
          renderJarvisActionCard(result.proposal, result.proposal_token);
        }
        return;
      }
      const result = await supabaseRequest('jarvis', {
        method: 'POST',
        body: JSON.stringify({ question, period: selectedDateRange })
      });
      addJarvisMessage(result.reply, 'bot');
    } catch (error) {
      addJarvisMessage(error.message || 'Jarvis could not answer right now. Please try again.');
    } finally {
      submitButton.disabled = false;
      jarvisInput.focus();
    }
  });

  const profileMenuAnchor = document.getElementById('profileMenuAnchor');
  const profileMenuToggle = document.getElementById('userAvatar');
  const profileDropdown = document.getElementById('profileDropdown');
  const closeProfileDropdown = () => {
    profileDropdown.hidden = true;
    profileMenuToggle.setAttribute('aria-expanded', 'false');
  };
  const signOut = async () => {
    if (supabaseClient) {
      const { error } = await supabaseClient.auth.signOut();
      if (error) throw error;
    }
    if (activeUser?.id) {
      sessionStorage.removeItem(finishSetupDismissedKey(activeUser.id));
    }
    localStorage.removeItem('finora-profile');
    location.reload();
  };

  profileMenuToggle.addEventListener('click', (event) => {
    event.stopPropagation();
    profileDropdown.hidden = !profileDropdown.hidden;
    profileMenuToggle.setAttribute('aria-expanded', String(!profileDropdown.hidden));
  });
  document.addEventListener('click', (event) => {
    if (!profileMenuAnchor.contains(event.target)) closeProfileDropdown();
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && !profileDropdown.hidden) {
      closeProfileDropdown();
      profileMenuToggle.focus();
    }
  });
  profileDropdown.querySelector('a[href="#settings"]').addEventListener('click', closeProfileDropdown);
  document.getElementById('profileDropdownSignout').addEventListener('click', signOut);

  document.querySelector('.signin-btn').addEventListener('click', async (event) => {
    if (event.currentTarget.textContent.trim() !== 'Sign out') return;
    await signOut();
  });
});