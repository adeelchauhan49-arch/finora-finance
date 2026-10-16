import unittest
from pathlib import Path


class ReportsTests(unittest.TestCase):
    def setUp(self):
        root = Path(__file__).resolve().parents[1]
        self.html = root.joinpath('index.html').read_text(encoding='utf-8')
        self.app_js = root.joinpath('assets', 'js', 'app.js').read_text(encoding='utf-8')

    def test_reports_page_has_custom_range_charts_rankings_and_export(self):
        for element_id in ('reports', 'reportsStartDate', 'reportsEndDate', 'reportsCategoryChart', 'reportsTrendChart', 'reportsTopCategories', 'reportsTopMerchants', 'reportsCsvExport', 'reportsEmptyState'):
            self.assertIn(f'id="{element_id}"', self.html)
        self.assertIn('data-report-period="this-month"', self.html)
        self.assertIn('data-report-period="last-month"', self.html)
        self.assertIn('data-report-period="three-months"', self.html)
        self.assertIn("type: 'doughnut'", self.app_js)
        self.assertIn('renderReportsRanking', self.app_js)
        self.assertIn('URL.createObjectURL(new Blob', self.app_js)

    def test_reports_filter_is_inclusive_and_empty_safe(self):
        self.assertIn('item.date >= range.start && item.date <= range.end', self.app_js)
        self.assertIn('No data for this range', self.html)
        self.assertIn("const hasData = filtered.length > 0", self.app_js)
        self.assertIn('content.hidden = !hasData', self.app_js)

    def test_dashboard_period_state_is_reused_without_custom_range_side_effect(self):
        self.assertIn('localStorage.setItem(periodStorageKey, selectedDateRange)', self.app_js)
        self.assertIn("button.dataset.period === selectedDateRange", self.app_js)
        self.assertIn('reportUsesCustomRange', self.app_js)
        self.assertIn("reportUsesCustomRange = true", self.app_js)
        self.assertIn('renderTransactions();\n    updateSummary();\n    renderReports();', self.app_js)


if __name__ == '__main__':
    unittest.main()