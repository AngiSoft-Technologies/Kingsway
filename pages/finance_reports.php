<?php
/**
 * Financial Reports — the year's statements from the same figures as the
 * Financial Dashboard (FinanceDashboardService), so a report and the
 * dashboard never disagree: income statement (profit and loss, with
 * payroll), cash flow, fees owed to the school, expenses (approved and paid;
 * pending shown apart), budget vs actual by department, and what the school
 * owes. Each view has its table, CSV and print.
 * Data: /api/finance/financial-dashboard?academic_year_id.
 */
?>
<link rel="stylesheet" href="<?= htmlspecialchars($appBase, ENT_QUOTES, 'UTF-8') ?>/css/pages/finance_desk.css?v=<?= asset_version('css/pages/finance_desk.css') ?>">
<div class="kw-fin container-fluid py-3" id="finReportsPage">
  <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-3">
    <div>
      <h1 class="fin-title">Financial reports</h1>
      <p class="fin-lede" id="frLede">The year's statements. The figures match the Financial Dashboard.</p>
    </div>
    <div class="d-flex flex-wrap align-items-end gap-2 d-print-none">
      <div><label class="form-label small" for="frYear">Academic year</label><select class="form-select" id="frYear"></select></div>
      <button class="btn btn-outline-secondary" type="button" id="frCsv"><i class="bi bi-download me-1" aria-hidden="true"></i>CSV</button>
      <button class="btn btn-outline-secondary" type="button" id="frPrint"><i class="bi bi-printer me-1" aria-hidden="true"></i>Print</button>
    </div>
  </div>

  <div class="nav nav-tabs mb-3 d-print-none" role="tablist" id="frTabs">
    <button class="nav-link active" type="button" role="tab" aria-selected="true" aria-controls="frView" data-view="income">Income statement</button>
    <button class="nav-link" type="button" role="tab" aria-selected="false" aria-controls="frView" data-view="cash" tabindex="-1">Cash flow</button>
    <button class="nav-link" type="button" role="tab" aria-selected="false" aria-controls="frView" data-view="fees" tabindex="-1">Fees owed</button>
    <button class="nav-link" type="button" role="tab" aria-selected="false" aria-controls="frView" data-view="expenses" tabindex="-1">Expenses</button>
    <button class="nav-link" type="button" role="tab" aria-selected="false" aria-controls="frView" data-view="budget" tabindex="-1">Budget vs actual</button>
    <button class="nav-link" type="button" role="tab" aria-selected="false" aria-controls="frView" data-view="payables" tabindex="-1">What the school owes</button>
  </div>

  <div id="frView" role="tabpanel" aria-live="polite">
    <h2 class="h5 mb-3" id="frTitle">Income statement</h2>
    <ul class="fin-facts mb-3" id="frKpis"></ul>
    <section class="fin-panel mb-3" id="frChartPanel">
      <h3 id="frChartTitle"></h3>
      <div class="fin-chart"><canvas id="frChart" role="img" aria-label="Chart of the report. The table below has the figures."></canvas></div>
    </section>
    <section class="fin-panel mb-3" id="frExtraPanel" hidden></section>
    <section class="fin-panel">
      <h3 id="frTableTitle">Statement</h3>
      <div class="table-responsive" tabindex="0" role="region" aria-labelledby="frTableTitle">
        <table class="table align-middle mb-0"><thead id="frHead"></thead><tbody id="frBody"><tr><td class="text-muted p-3">Loading the report…</td></tr></tbody><tfoot id="frFoot"></tfoot></table>
      </div>
    </section>
  </div>
</div>
<?php asset_script($appBase, 'js/pages/finance_reports.js'); ?>
