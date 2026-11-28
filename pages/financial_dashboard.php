<?php
/**
 * Financial Dashboard — the school's year at a glance, for the Director,
 * School Administrator and Accountant: income against spending (fees and
 * other money received; expenses and payroll), cash flow, fees owed to the
 * school by age and by class, what the school owes, each department's budget
 * against its spending, and the decisions waiting (approvers only).
 * Every chart has its table, CSV and print.
 * Data: /api/finance/financial-dashboard?academic_year_id.
 */
?>
<link rel="stylesheet" href="<?= htmlspecialchars($appBase, ENT_QUOTES, 'UTF-8') ?>/css/pages/finance_desk.css?v=<?= asset_version('css/pages/finance_desk.css') ?>">
<div class="kw-fin container-fluid py-3" id="finDashPage">
  <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-3">
    <div>
      <h1 class="fin-title">Financial dashboard</h1>
      <p class="fin-lede" id="fdLede">The school's money for the year: what came in, what went out, what is owed and how each department is spending.</p>
    </div>
    <div class="d-flex flex-wrap align-items-end gap-2 d-print-none">
      <div><label class="form-label small" for="fdYear">Academic year</label><select class="form-select" id="fdYear"></select></div>
      <button class="btn btn-outline-secondary" type="button" id="fdPrint"><i class="bi bi-printer me-1" aria-hidden="true"></i>Print</button>
    </div>
  </div>

  <ul class="fin-facts mb-3" id="fdKpis" aria-live="polite"></ul>

  <div class="fin-grid mb-3">
    <section class="fin-panel span-8" aria-labelledby="fdFlowTitle">
      <h2 id="fdFlowTitle">Income and spending, month by month</h2>
      <div class="fin-chart fin-chart--tall"><canvas id="fdFlowChart" role="img" aria-label="Income, expenses and payroll by month. The table below has the figures."></canvas></div>
    </section>
    <section class="fin-panel span-4" aria-labelledby="fdWaitTitle" id="fdWaitPanel">
      <h2 id="fdWaitTitle">Waiting for a decision</h2>
      <ul class="list-unstyled mb-0" id="fdWaiting"></ul>
    </section>

    <section class="fin-panel span-6" aria-labelledby="fdNetTitle">
      <h2 id="fdNetTitle">Surplus or deficit, month by month</h2>
      <div class="fin-chart"><canvas id="fdNetChart" role="img" aria-label="Income less spending for each month. The table below has the figures."></canvas></div>
    </section>
    <section class="fin-panel span-6" aria-labelledby="fdArTitle">
      <h2 id="fdArTitle">Fees owed to the school, by how long</h2>
      <div class="fin-chart"><canvas id="fdAgingChart" role="img" aria-label="Fees outstanding by days overdue."></canvas></div>
      <p class="small text-muted mb-0" id="fdArNote"></p>
    </section>

    <section class="fin-panel span-6" aria-labelledby="fdCatTitle">
      <h2 id="fdCatTitle">Where the money went</h2>
      <div class="fin-chart"><canvas id="fdCatChart" role="img" aria-label="Approved and paid expenses by category, largest first."></canvas></div>
    </section>
    <section class="fin-panel span-6" aria-labelledby="fdChanTitle">
      <h2 id="fdChanTitle">How payments came in</h2>
      <div class="fin-chart"><canvas id="fdChanChart" role="img" aria-label="Money received by payment channel."></canvas></div>
    </section>

    <section class="fin-panel span-12" aria-labelledby="fdBudgetTitle">
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
        <h2 class="mb-0" id="fdBudgetTitle">Budget vs actual, by department</h2>
        <a class="btn btn-outline-secondary btn-sm d-print-none" href="home.php?route=budget_actuals">Open the budget</a>
      </div>
      <ul class="fin-legend" aria-hidden="true"><li><i class="k-spent"></i>Spent</li><li><i class="k-held"></i>Held for pending expenses</li><li><i class="k-left"></i>Left</li><li><i class="k-over"></i>Over the allocation</li></ul>
      <ul class="fin-depts" id="fdDepts"></ul>
    </section>

    <section class="fin-panel span-6" aria-labelledby="fdClassTitle">
      <div class="d-flex justify-content-between align-items-center gap-2 mb-2">
        <h2 class="mb-0" id="fdClassTitle">Fees outstanding by class</h2>
        <button class="btn btn-outline-secondary btn-sm d-print-none" type="button" id="fdClassCsv"><i class="bi bi-download me-1" aria-hidden="true"></i>CSV</button>
      </div>
      <div class="table-responsive" tabindex="0" role="region" aria-label="Fees outstanding by class">
        <table class="table align-middle mb-0"><thead><tr><th scope="col">Class</th><th scope="col" class="num">Billed</th><th scope="col" class="num">Outstanding</th><th scope="col" class="num">Learners owing</th></tr></thead><tbody id="fdClassBody"></tbody></table>
      </div>
    </section>
    <section class="fin-panel span-6" aria-labelledby="fdPayTitle">
      <h2 id="fdPayTitle">What the school owes</h2>
      <ul class="fin-facts" id="fdPayables"></ul>
    </section>

    <section class="fin-panel span-12" aria-labelledby="fdMonthTitle">
      <div class="d-flex justify-content-between align-items-center gap-2 mb-2">
        <h2 class="mb-0" id="fdMonthTitle">Month by month</h2>
        <button class="btn btn-outline-secondary btn-sm d-print-none" type="button" id="fdMonthCsv"><i class="bi bi-download me-1" aria-hidden="true"></i>CSV</button>
      </div>
      <div class="table-responsive" tabindex="0" role="region" aria-label="Month by month figures">
        <table class="table align-middle mb-0"><thead><tr><th scope="col">Month</th><th scope="col" class="num">Income</th><th scope="col" class="num">Expenses</th><th scope="col" class="num">Payroll</th><th scope="col" class="num">Surplus or deficit</th><th scope="col" class="num">Running total</th></tr></thead><tbody id="fdMonthBody"></tbody></table>
      </div>
    </section>
  </div>
</div>
<?php asset_script($appBase, 'js/pages/financial_dashboard.js'); ?>
