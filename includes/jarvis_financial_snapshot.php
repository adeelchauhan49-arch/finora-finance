<?php
declare(strict_types=1);

function jarvis_period_bounds(string $period, ?DateTimeImmutable $today = null): array
{
    $today ??= new DateTimeImmutable('today');
    $monthStart = $today->modify('first day of this month')->setTime(0, 0);
    if ($period === 'last-month') {
        $start = $monthStart->modify('-1 month');
        $end = $monthStart;
        $monthCount = 1;
    } elseif ($period === 'three-months') {
        $start = $monthStart->modify('-2 months');
        $end = $monthStart->modify('+1 month');
        $monthCount = 3;
    } else {
        $start = $monthStart;
        $end = $monthStart->modify('+1 month');
        $monthCount = 1;
    }

    return [
        'start' => $start->format('Y-m-d'),
        'end' => $end->format('Y-m-d'),
        'month_count' => $monthCount,
    ];
}

function jarvis_calculate_month_end_forecast(PDO $connection, int $userId, DateTimeImmutable $today): array
{
    $monthStart = $today->modify('first day of this month')->setTime(0, 0);
    $historyStart = $today->modify('-89 days')->setTime(0, 0);
    $recurrenceStart = $monthStart->modify('-3 months');
    $statement = $connection->prepare(
        'SELECT merchant, amount, transaction_date
         FROM transactions
         WHERE user_id = :user_id AND type = :type
           AND transaction_date >= :recurrence_start AND transaction_date <= :today
         ORDER BY transaction_date ASC, id ASC'
    );
    $statement->execute([
        'user_id' => $userId,
        'type' => 'expense',
        'recurrence_start' => $recurrenceStart->format('Y-m-d'),
        'today' => $today->format('Y-m-d'),
    ]);
    $expenses = $statement->fetchAll(PDO::FETCH_ASSOC);

    $recurringByMerchant = [];
    $currentMonthMerchantTotals = [];
    $currentMonthSpending = 0.0;
    $currentMonthExpenseCount = 0;
    foreach ($expenses as $expense) {
        $merchant = trim((string) $expense['merchant']);
        $merchantKey = mb_strtolower($merchant);
        $amount = (float) $expense['amount'];
        $date = (string) $expense['transaction_date'];
        $month = substr($date, 0, 7);
        if ($merchant !== '') {
            $recurringByMerchant[$merchantKey]['merchant'] = $merchant;
            $recurringByMerchant[$merchantKey]['months'][$month] =
                ($recurringByMerchant[$merchantKey]['months'][$month] ?? 0.0) + $amount;
        }
        if ($date >= $monthStart->format('Y-m-d')) {
            $currentMonthSpending += $amount;
            $currentMonthExpenseCount++;
            if ($merchant !== '') {
                $currentMonthMerchantTotals[$merchantKey] =
                    ($currentMonthMerchantTotals[$merchantKey] ?? 0.0) + $amount;
            }
        }
    }

    $historicalMonths = [];
    for ($offset = 3; $offset >= 1; $offset--) {
        $historicalMonths[] = $monthStart->modify('-' . $offset . ' months')->format('Y-m');
    }
    $recurringMerchants = [];
    $expectedRecurring = 0.0;
    foreach ($recurringByMerchant as $merchantKey => $merchantData) {
        $monthlyAmounts = [];
        foreach ($historicalMonths as $month) {
            if (!isset($merchantData['months'][$month])) {
                continue 2;
            }
            $monthlyAmounts[] = $merchantData['months'][$month];
        }
        sort($monthlyAmounts, SORT_NUMERIC);
        $median = $monthlyAmounts[1];
        if ($median <= 0
            || abs($monthlyAmounts[0] - $median) > $median * 0.10
            || abs($monthlyAmounts[2] - $median) > $median * 0.10) {
            continue;
        }
        $recurringMerchants[$merchantKey] = true;
        $currentMonthMerchantAmount = $currentMonthMerchantTotals[$merchantKey] ?? null;
        if ($currentMonthMerchantAmount === null || abs($currentMonthMerchantAmount - $median) > $median * 0.10) {
            $expectedRecurring += array_sum($monthlyAmounts) / count($monthlyAmounts);
        }
    }

    $historicalDailyTotals = [];
    $historicalAmounts = [];
    foreach ($expenses as $expense) {
        $date = (string) $expense['transaction_date'];
        $merchantKey = mb_strtolower(trim((string) $expense['merchant']));
        if ($date < $historyStart->format('Y-m-d') || isset($recurringMerchants[$merchantKey])) {
            continue;
        }
        $amount = (float) $expense['amount'];
        $historicalAmounts[] = $amount;
    }

    $median = 0.0;
    if ($historicalAmounts) {
        $sortedAmounts = $historicalAmounts;
        sort($sortedAmounts, SORT_NUMERIC);
        $middle = intdiv(count($sortedAmounts), 2);
        $median = count($sortedAmounts) % 2 === 0
            ? ($sortedAmounts[$middle - 1] + $sortedAmounts[$middle]) / 2
            : $sortedAmounts[$middle];
    }
    $deviations = array_map(static fn (float $amount): float => abs($amount - $median), $historicalAmounts);
    sort($deviations, SORT_NUMERIC);
    $deviationMiddle = intdiv(count($deviations), 2);
    $medianAbsoluteDeviation = $deviations
        ? (count($deviations) % 2 === 0
            ? ($deviations[$deviationMiddle - 1] + $deviations[$deviationMiddle]) / 2
            : $deviations[$deviationMiddle])
        : 0.0;
    foreach ($expenses as $expense) {
        $date = (string) $expense['transaction_date'];
        $merchantKey = mb_strtolower(trim((string) $expense['merchant']));
        if ($date < $historyStart->format('Y-m-d') || isset($recurringMerchants[$merchantKey])) {
            continue;
        }
        $amount = (float) $expense['amount'];
        if ($medianAbsoluteDeviation > 0 && abs($amount - $median) > 3 * $medianAbsoluteDeviation) {
            continue;
        }
        if ($medianAbsoluteDeviation === 0 && $median > 0 && $amount !== $median) {
            continue;
        }
        $historicalDailyTotals[$date] = ($historicalDailyTotals[$date] ?? 0.0) + $amount;
    }

    $dailyValues = [];
    for ($offset = 89; $offset >= 0; $offset--) {
        $date = $today->modify('-' . $offset . ' days')->format('Y-m-d');
        $dailyValues[] = $historicalDailyTotals[$date] ?? 0.0;
    }
    $dailyAverage = array_sum($dailyValues) / 90;
    $variance = array_sum(array_map(
        static fn (float $value): float => ($value - $dailyAverage) ** 2,
        $dailyValues
    )) / 90;
    $dailyDeviation = sqrt($variance);
    $daysElapsed = (int) $today->format('j');
    $daysRemaining = (int) $today->format('t') - $daysElapsed;
    $point = $currentMonthSpending + $expectedRecurring + ($dailyAverage * $daysRemaining);
    $uncertainty = $dailyDeviation * sqrt($daysRemaining);

    $profileStatement = $connection->prepare(
        'SELECT monthly_budget, currency FROM financial_profile WHERE user_id = :user_id LIMIT 1'
    );
    $profileStatement->execute(['user_id' => $userId]);
    $profile = $profileStatement->fetch(PDO::FETCH_ASSOC) ?: [];
    $monthlyBudget = isset($profile['monthly_budget']) ? (float) $profile['monthly_budget'] : 2400.0;
    $currencyCode = strtoupper((string) ($profile['currency'] ?? 'INR'));
    $currencySymbol = ['INR' => 'Rs', 'USD' => '$', 'EUR' => '€'][$currencyCode] ?? $currencyCode;
    $confidence = $daysElapsed >= 27 && $currentMonthExpenseCount >= 40
        ? 'High'
        : ($daysElapsed >= 21 && $currentMonthExpenseCount >= 20 ? 'Medium' : 'Low');
    $available = $daysElapsed >= 14 && $currentMonthExpenseCount >= 10;
    $budgetDifference = abs($point - $monthlyBudget);
    $budgetComparison = $point > $monthlyBudget
        ? 'At this pace, spending is projected to be ' . $currencySymbol . ' ' . number_format($budgetDifference, 2, '.', ',')
            . ' over the monthly budget of ' . $currencySymbol . ' ' . number_format($monthlyBudget, 2, '.', ',') . '.'
        : 'At this pace, about ' . $currencySymbol . ' ' . number_format($budgetDifference, 2, '.', ',')
            . ' of the monthly budget of ' . $currencySymbol . ' ' . number_format($monthlyBudget, 2, '.', ',') . ' may remain.';
    $budgetComparisonHinglish = $point > $monthlyBudget
        ? 'Is pace par spending, ' . $currencySymbol . ' ' . number_format($budgetDifference, 2, '.', ',')
            . ' monthly budget (' . $currencySymbol . ' ' . number_format($monthlyBudget, 2, '.', ',') . ') se zyada projected hai.'
        : 'Is pace par monthly budget (' . $currencySymbol . ' ' . number_format($monthlyBudget, 2, '.', ',')
            . ') mein se lagbhag ' . $currencySymbol . ' ' . number_format($budgetDifference, 2, '.', ',') . ' bach sakta hai.';

    return [
        'available' => $available,
        'point' => $available ? $point : null,
        'low' => $available ? max($currentMonthSpending + $expectedRecurring, $point - $uncertainty) : null,
        'high' => $available ? $point + $uncertainty : null,
        'confidence' => $available ? $confidence : null,
        'days_elapsed' => $daysElapsed,
        'days_remaining' => $daysRemaining,
        'expense_transaction_count' => $currentMonthExpenseCount,
        'monthly_budget' => $monthlyBudget,
        'budget_comparison_text' => $available ? $budgetComparison : null,
        'budget_comparison_text_hinglish' => $available ? $budgetComparisonHinglish : null,
        'insufficient_data_message' => 'A month-end forecast needs at least 14 elapsed days and 10 expense transactions; there are '
            . $daysElapsed . ' days and ' . $currentMonthExpenseCount . ' expenses so far.',
        'insufficient_data_message_hinglish' => 'Abhi forecast ke liye data kam hai: kam se kam 14 din aur 10 expense transactions chahiye; abhi '
            . $daysElapsed . ' din aur ' . $currentMonthExpenseCount . ' expenses hain.',
        'model_estimate' => [
            'point' => $point,
            'low' => max($currentMonthSpending + $expectedRecurring, $point - $uncertainty),
            'high' => $point + $uncertainty,
            'recurring_added' => $expectedRecurring,
        ],
    ];
}

function jarvis_build_month_end_forecast(PDO $connection, int $userId, ?DateTimeImmutable $today = null): array
{
    if ($userId < 1) {
        throw new InvalidArgumentException('Invalid Jarvis forecast request.');
    }
    $today ??= new DateTimeImmutable('today');
    $forecast = jarvis_calculate_month_end_forecast($connection, $userId, $today);
    unset($forecast['model_estimate']);
    return $forecast;
}

function jarvis_build_financial_snapshot(PDO $connection, int $userId, string $period, ?DateTimeImmutable $today = null): array
{
    if ($userId < 1 || !in_array($period, ['this-month', 'last-month', 'three-months'], true)) {
        throw new InvalidArgumentException('Invalid Jarvis snapshot request.');
    }

    $today ??= new DateTimeImmutable('today');
    $bounds = jarvis_period_bounds($period, $today);
    $recurrenceStart = (new DateTimeImmutable($bounds['end']))->modify('-3 months')->format('Y-m-d');
    $statement = $connection->prepare(
        'SELECT type, merchant, category, amount, transaction_date
         FROM transactions
         WHERE user_id = :user_id AND transaction_date >= :recurrence_start AND transaction_date < :period_end
         ORDER BY transaction_date ASC, id ASC'
    );
    $statement->execute([
        'user_id' => $userId,
        'recurrence_start' => $recurrenceStart,
        'period_end' => $bounds['end'],
    ]);
    $transactions = $statement->fetchAll(PDO::FETCH_ASSOC);

    $profileStatement = $connection->prepare(
        'SELECT monthly_budget, currency FROM financial_profile WHERE user_id = :user_id LIMIT 1'
    );
    $profileStatement->execute(['user_id' => $userId]);
    $profile = $profileStatement->fetch(PDO::FETCH_ASSOC) ?: [];
    $monthlyBudget = isset($profile['monthly_budget']) ? (float) $profile['monthly_budget'] : 2400.0;
    $currencyCode = strtoupper((string) ($profile['currency'] ?? 'INR'));
    $currencySymbol = ['INR' => 'Rs', 'USD' => '$', 'EUR' => '€'][$currencyCode] ?? $currencyCode;

    $income = 0.0;
    $spending = 0.0;
    $expenseCount = 0;
    $categoryTotals = [];
    $largestExpense = null;
    $recurringByMerchant = [];
    $currentMonthSpending = 0.0;
    $currentMonthExpenseCount = 0;
    $currentMonthDays = (int) $today->format('t');
    $currentMonthElapsedDays = (int) $today->format('j');

    foreach ($transactions as $transaction) {
        $amount = (float) $transaction['amount'];
        $date = (string) $transaction['transaction_date'];
        $type = (string) $transaction['type'];
        $merchant = trim((string) $transaction['merchant']);
        $month = substr($date, 0, 7);

        if ($date >= $bounds['start'] && $date < $bounds['end']) {
            if ($type === 'income') {
                $income += $amount;
            } elseif ($type === 'expense') {
                $spending += $amount;
                $expenseCount++;
                $category = trim((string) $transaction['category']) ?: 'Other';
                $categoryTotals[$category] = ($categoryTotals[$category] ?? 0.0) + $amount;
                if ($largestExpense === null || $amount > $largestExpense['amount']) {
                    $largestExpense = ['amount' => $amount, 'category' => $category];
                }
            }
        }

        if ($type === 'expense' && $merchant !== '') {
            $merchantKey = mb_strtolower($merchant);
            $recurringByMerchant[$merchantKey]['merchant'] = $merchant;
            $recurringByMerchant[$merchantKey]['months'][$month][] = $amount;
        }

        if ($type === 'expense' && $month === $today->format('Y-m')) {
            $currentMonthSpending += $amount;
            $currentMonthExpenseCount++;
        }
    }

    $topCategories = [];
    arsort($categoryTotals, SORT_NUMERIC);
    foreach (array_slice($categoryTotals, 0, 3, true) as $category => $amount) {
        $topCategories[] = [
            'category' => $category,
            'amount' => $amount,
            'percent_of_spending' => $spending > 0 ? ($amount / $spending) * 100 : 0.0,
        ];
    }

    $recurringTransactions = [];
    foreach ($recurringByMerchant as $merchantData) {
        $monthlyAverages = [];
        foreach ($merchantData['months'] as $monthlyAmounts) {
            $monthlyAverages[] = array_sum($monthlyAmounts) / count($monthlyAmounts);
        }
        if (count($monthlyAverages) < 3) {
            continue;
        }
        sort($monthlyAverages, SORT_NUMERIC);
        $middle = intdiv(count($monthlyAverages), 2);
        $median = count($monthlyAverages) % 2 === 0
            ? ($monthlyAverages[$middle - 1] + $monthlyAverages[$middle]) / 2
            : $monthlyAverages[$middle];
        $isSimilar = $median > 0;
        foreach ($monthlyAverages as $monthlyAverage) {
            if ($median <= 0 || abs($monthlyAverage - $median) > $median * 0.10) {
                $isSimilar = false;
                break;
            }
        }
        if ($isSimilar) {
            $recurringTransactions[] = [
                'merchant' => $merchantData['merchant'],
                'average_monthly_amount' => array_sum($monthlyAverages) / count($monthlyAverages),
                'months_observed' => count($monthlyAverages),
            ];
        }
    }

    $budget = $monthlyBudget * $bounds['month_count'];
    $savingsRate = $income > 0 ? (($income - $spending) / $income) * 100 : null;
    $monthEndForecast = jarvis_build_month_end_forecast($connection, $userId, $today);
    $forecast = $monthEndForecast['point'];

    return [
        'period' => $period,
        'period_start' => $bounds['start'],
        'period_end' => $bounds['end'],
        'currency' => $currencyCode,
        'currency_symbol' => $currencySymbol,
        'income' => $income,
        'spending' => $spending,
        'savings_rate_percent' => $savingsRate,
        'transaction_count' => count(array_filter(
            $transactions,
            static fn (array $transaction): bool => $transaction['transaction_date'] >= $bounds['start']
                && $transaction['transaction_date'] < $bounds['end']
        )),
        'expense_count' => $expenseCount,
        'top_categories' => $topCategories,
        'monthly_budget' => $monthlyBudget,
        'budget_for_period' => $budget,
        'budget_remaining' => $budget - $spending,
        'budget_overage' => max(0.0, $spending - $budget),
        'largest_expense' => $largestExpense,
        'recurring_transactions' => $recurringTransactions,
        'recurring_count' => count($recurringTransactions),
        'recurring_average_monthly_total' => array_sum(array_column($recurringTransactions, 'average_monthly_amount')),
        'forecast_spending' => $forecast,
        'month_end_forecast' => $monthEndForecast,
        'current_month_expense_count' => $currentMonthExpenseCount,
        'current_month_spending' => $currentMonthSpending,
        'data_is_limited' => count(array_filter(
            $transactions,
            static fn (array $transaction): bool => $transaction['transaction_date'] >= $bounds['start']
                && $transaction['transaction_date'] < $bounds['end']
        )) < 3,
    ];
}

function jarvis_format_money(array $snapshot, float $amount): string
{
    return $snapshot['currency_symbol'] . ' ' . number_format($amount, 2, '.', ',');
}

function jarvis_detect_intent(string $question): array
{
    $text = mb_strtolower($question);
    if (preg_match('/\b(can i afford|could i afford|afford|kya .* afford|kya .* le sakta)\b/u', $text) === 1) {
        preg_match('/(?:rs\.?|inr|usd|eur|[$€₹])?\s*([0-9][0-9,]*(?:\.[0-9]{1,2})?)/iu', $question, $matches);
        return ['intent' => 'afford', 'amount' => isset($matches[1]) ? (float) str_replace(',', '', $matches[1]) : null];
    }
    if (preg_match('/\b(forecast|predict|projection|projected|future spending|kitna kharch hoga)\b/u', $text) === 1) {
        return ['intent' => 'forecast', 'amount' => null];
    }
    if (preg_match('/\b(overspend(?:ing)?|over.?spend(?:ing)?|too much|excessive|highest spend|where am i spending|where .* spend(?:ing)?|kahan.*(zyada|kharch)|sabse zyada)\b/u', $text) === 1) {
        return ['intent' => 'overspending', 'amount' => null];
    }
    if (preg_match('/\b(how to save|how (can|do) i save|help me save|saving tips|save more|bachat|paise kaise bach)/u', $text) === 1) {
        return ['intent' => 'save', 'amount' => null];
    }
    if (preg_match('/\b(budget|within my limit|budget status|budget remaining|over budget)\b/u', $text) === 1) {
        return ['intent' => 'budget', 'amount' => null];
    }
    if (preg_match('/\b(summary|summarize|overview|how am i doing|my finances|spending summary|income.*spend)\b/u', $text) === 1) {
        return ['intent' => 'summary', 'amount' => null];
    }
    return ['intent' => 'unknown', 'amount' => null];
}

function jarvis_insufficient_data_message(array $snapshot): string
{
    $count = (int) $snapshot['transaction_count'];
    return 'Mere paas is period ke liye sirf ' . $count . ' transaction'
        . ($count === 1 ? '' : 's') . ' hain; reliable personalized analysis ke liye kam se kam 3 chahiye. '
        . 'Transactions record karne ke baad dobara poochhein.';
}

function jarvis_snapshot_question_is_hinglish(string $question): bool
{
    return preg_match('/\b(kya|kaise|kahan|kitna|kitni|kharch|kharcha|bachat|paise|mahine|pichhle|mera|meri|hai|hain|nahi|zyada|kam|batao)\b/iu', $question) === 1;
}

function jarvis_deterministic_reply(string $question, array $snapshot): string
{
    $detected = jarvis_detect_intent($question);
    $intent = $detected['intent'];
    $hinglish = jarvis_snapshot_question_is_hinglish($question);
    $money = static fn (float $amount): string => jarvis_format_money($snapshot, $amount);
    if ($intent === 'unknown') {
        return $hinglish
            ? 'Main aapki savings, overspending, budget status, spending summary, forecast, ya kisi purchase ki affordability check kar sakta hoon. Aap inmein se kya jaan-na chahenge?'
            : 'I can help with savings, overspending, budget status, a spending summary, a forecast, or purchase affordability. Which would you like to know about?';
    }
    if ($intent === 'forecast') {
        $forecast = $snapshot['month_end_forecast'];
        if (!$forecast['available']) {
            return $hinglish
                ? $forecast['insufficient_data_message_hinglish']
                : $forecast['insufficient_data_message'];
        }
        $range = $money((float) $forecast['low']) . '–' . $money((float) $forecast['high']);
        return ($hinglish
            ? 'Is mahine ka projected spending range ' . $range . ' hai (confidence: ' . $forecast['confidence']
                . ', ' . $forecast['days_elapsed'] . ' din aur ' . $forecast['expense_transaction_count'] . ' expenses ke basis par). '
            : 'This month-end spending forecast is ' . $range . ' (confidence: ' . $forecast['confidence']
                . ', based on ' . $forecast['days_elapsed'] . ' days and ' . $forecast['expense_transaction_count'] . ' expenses). ')
            . ($hinglish ? $forecast['budget_comparison_text_hinglish'] : $forecast['budget_comparison_text']);
    }
    if ($snapshot['transaction_count'] < 3) {
        if ($intent === 'afford' && $detected['amount'] === null) {
            return ($hinglish
                ? 'Kis amount ki purchase ke baare mein poochh rahe hain? '
                : 'What purchase amount would you like me to check? ')
                . ($hinglish
                    ? jarvis_insufficient_data_message($snapshot)
                    : 'There are only ' . (int) $snapshot['transaction_count'] . ' transactions in this period; at least 3 are needed for reliable personalized analysis. Record more transactions and ask again.');
        }
        return $hinglish
            ? jarvis_insufficient_data_message($snapshot)
            : 'There are only ' . (int) $snapshot['transaction_count'] . ' transactions in this period; at least 3 are needed for reliable personalized analysis. Record more transactions and ask again.';
    }

    $categories = array_map(
        static fn (array $category): string => $category['category'] . ' (' . $money((float) $category['amount']) . ', ' . number_format((float) $category['percent_of_spending'], 1) . '%)',
        $snapshot['top_categories']
    );
    $topCategoryText = $categories ? implode('; ', $categories) : ($hinglish ? 'koi expense category record nahi hui' : 'no expense categories were recorded');
    $periodName = $hinglish
        ? ['this-month' => 'Is mahine', 'last-month' => 'Pichhle mahine', 'three-months' => 'Pichhle 3 mahino mein'][$snapshot['period']]
        : ['this-month' => 'This month', 'last-month' => 'Last month', 'three-months' => 'Over the last 3 months'][$snapshot['period']];
    $base = $hinglish
        ? $periodName . ' ' . $money((float) $snapshot['spending']) . ' kharch aur '
            . $money((float) $snapshot['income']) . ' income record hui; '
            . $snapshot['transaction_count'] . ' transactions ke basis par. '
        : $periodName . ': spending of ' . $money((float) $snapshot['spending']) . ' and income of '
            . $money((float) $snapshot['income']) . ' across ' . $snapshot['transaction_count'] . ' transactions. ';

    if ($intent === 'budget') {
        return $base . ($snapshot['budget_overage'] > 0
            ? ($hinglish
                ? 'Budget ' . $money((float) $snapshot['budget_for_period']) . ' se ' . $money((float) $snapshot['budget_overage']) . ' zyada kharch hai.'
                : 'Spending is ' . $money((float) $snapshot['budget_overage']) . ' over the budget of ' . $money((float) $snapshot['budget_for_period']) . '.')
            : ($hinglish
                ? 'Budget ' . $money((float) $snapshot['budget_for_period']) . ' mein se ' . $money((float) $snapshot['spending']) . ' use hua; ' . $money((float) $snapshot['budget_remaining']) . ' baaki hai.'
                : 'You have used ' . $money((float) $snapshot['spending']) . ' of your ' . $money((float) $snapshot['budget_for_period']) . ' budget; ' . $money((float) $snapshot['budget_remaining']) . ' remains.'));
    }
    if ($intent === 'overspending') {
        $overageMessage = $snapshot['budget_overage'] > 0
            ? ($hinglish ? 'Budget se ' . $money((float) $snapshot['budget_overage']) . ' zyada kharch hua. ' : 'Spending exceeded your budget by ' . $money((float) $snapshot['budget_overage']) . '. ')
            : ($hinglish ? 'Selected period mein budget cross nahi hua. ' : 'You stayed within your budget for this period. ');
        return $base . $overageMessage . ($hinglish ? 'Top categories (amount, spending share): ' : 'Top categories (amount, share of spending): ') . $topCategoryText . '.';
    }
    if ($intent === 'summary') {
        $savingsRate = $snapshot['savings_rate_percent'] === null
            ? ($hinglish ? 'Income record nahi hai, isliye savings rate calculate nahi ho sakta.' : 'No income was recorded, so the savings rate cannot be calculated.')
            : ($hinglish ? 'Savings rate ' . number_format((float) $snapshot['savings_rate_percent'], 1) . '% hai.' : 'Your savings rate is ' . number_format((float) $snapshot['savings_rate_percent'], 1) . '%.');
        $largest = $snapshot['largest_expense'] === null
            ? ($hinglish ? 'Koi expense record nahi hai.' : 'No expenses were recorded.')
            : ($hinglish
                ? 'Sabse bada single kharch ' . $money((float) $snapshot['largest_expense']['amount']) . ' (' . $snapshot['largest_expense']['category'] . ') tha.'
                : 'The largest single expense was ' . $money((float) $snapshot['largest_expense']['amount']) . ' (' . $snapshot['largest_expense']['category'] . ').');
        return $base . $savingsRate . ($hinglish ? ' Top categories: ' : ' Top categories: ') . $topCategoryText . '. ' . $largest
            . ' ' . $snapshot['recurring_count'] . ($hinglish ? ' recurring merchant(s) mile.' : ' recurring merchant(s) found.');
    }
    if ($intent === 'afford') {
        if ($detected['amount'] === null) {
            return $base . ($hinglish ? 'Kis amount ki purchase afford karne ke baare mein check karun?' : 'What purchase amount would you like me to check for affordability?');
        }
        $amount = (float) $detected['amount'];
        $available = max(0.0, (float) $snapshot['budget_remaining']);
        if ($snapshot['income'] > 0) {
            $available = min($available, max(0.0, (float) $snapshot['income'] - (float) $snapshot['spending']));
        }
        return $base . ($amount <= $available
            ? ($hinglish
                ? 'Aapke recorded budget/cash-flow ke hisaab se ' . $money($amount) . ' ki purchase available ' . $money($available) . ' ke andar hai.'
                : 'Based on your recorded budget and cash flow, a purchase of ' . $money($amount) . ' is within the available ' . $money($available) . '.')
            : ($hinglish
                ? $money($amount) . ' ki purchase recorded available amount ' . $money($available) . ' se zyada hai.'
                : 'A purchase of ' . $money($amount) . ' exceeds the recorded available amount of ' . $money($available) . '.'));
    }
    if ($intent === 'save') {
        $advice = $snapshot['budget_overage'] > 0
            ? ($hinglish
                ? 'Pehle budget overage ' . $money((float) $snapshot['budget_overage']) . ' kam karne par focus karein.'
                : 'First, focus on reducing the budget overage of ' . $money((float) $snapshot['budget_overage']) . '.')
            : ($hinglish
                ? 'Sabse zyada kharch wali categories review karein: ' . $topCategoryText . '.'
                : 'Review your highest-spending categories: ' . $topCategoryText . '.');
        if ($snapshot['recurring_count'] > 0) {
            $advice .= $hinglish
                ? ' ' . $snapshot['recurring_count'] . ' recurring merchant(s) ka average total '
                    . $money((float) $snapshot['recurring_average_monthly_total']) . ' per month hai; jo zaroori na hon unhe review karein.'
                : ' The average total for ' . $snapshot['recurring_count'] . ' recurring merchant(s) is '
                    . $money((float) $snapshot['recurring_average_monthly_total']) . ' per month; review any that are not essential.';
        }
        return $base . $advice;
    }

    return $hinglish
        ? 'Main is sawaal ko samajh nahi paaya. Savings, overspending, budget, summary, forecast, ya purchase affordability ke baare mein poochhein.'
        : 'I could not understand that question. Ask about savings, overspending, your budget, a summary, a forecast, or purchase affordability.';
}

function jarvis_llm_snapshot(array $snapshot): array
{
    return [
        'period' => $snapshot['period'],
        'currency' => $snapshot['currency'],
        'income' => $snapshot['income'],
        'spending' => $snapshot['spending'],
        'savings_rate_percent' => $snapshot['savings_rate_percent'],
        'transaction_count' => $snapshot['transaction_count'],
        'expense_count' => $snapshot['expense_count'],
        'top_categories' => $snapshot['top_categories'],
        'monthly_budget' => $snapshot['monthly_budget'],
        'budget_for_period' => $snapshot['budget_for_period'],
        'budget_remaining' => $snapshot['budget_remaining'],
        'budget_overage' => $snapshot['budget_overage'],
        'largest_expense' => $snapshot['largest_expense'],
        'recurring_count' => $snapshot['recurring_count'],
        'recurring_average_monthly_total' => $snapshot['recurring_average_monthly_total'],
        'forecast_spending' => $snapshot['forecast_spending'],
        'month_end_forecast' => $snapshot['month_end_forecast'],
        'data_is_limited' => $snapshot['data_is_limited'],
    ];
}
