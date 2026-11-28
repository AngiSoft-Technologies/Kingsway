<?php
/**
 * Budgets — the school budget from preparation to department spending.
 * Prepared by the Headteacher, School Administrator, Director or Accountant
 * (department by department, category by category); reviewed, approved and
 * made active by the School Administrator or Director. Once active, each
 * department sees its quota: spent (approved and paid expenses, and payroll
 * on staff lines), held for pending expenses, and left. Departments ask for
 * more money on a line; the School Administrator or Director decides.
 * budget_actuals opens on the active budget's departments.
 * Data: /api/finance/budget-workspace | budget-detail/{id} | budget-save |
 * budget-action/{id} | budget-fund-request | budget-fund-decision/{id}.
 */
$budgetStart = $budgetStart ?? '';
?>
<link rel="stylesheet" href="<?= htmlspecialchars($appBase, ENT_QUOTES, 'UTF-8') ?>/css/pages/finance_desk.css?v=<?= asset_version('css/pages/finance_desk.css') ?>">
<div class="kw-fin container-fluid py-3" id="budgetPage" data-start="<?= htmlspecialchars($budgetStart, ENT_QUOTES, 'UTF-8') ?>">
  <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-3">
    <div>
      <h1 class="fin-title" id="bgTitle">Budgets</h1>
      <p class="fin-lede">Each department's allocation, what it has spent, what pending expenses hold and what is left.</p>
    </div>
    <div class="d-flex flex-wrap align-items-end gap-2 d-print-none">
      <div><label class="form-label small" for="bgYear">Academic year</label><select class="form-select" id="bgYear"></select></div>
      <button class="btn btn-fin" type="button" id="bgNew" hidden><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>New budget</button>
    </div>
  </div>

  <section class="fin-panel mb-3" id="bgListPanel" aria-labelledby="bgListTitle">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
      <h2 class="mb-0" id="bgListTitle">Budgets for the year</h2>
      <div class="d-flex gap-2 d-print-none">
        <button class="btn btn-outline-secondary btn-sm" type="button" id="bgListCsv"><i class="bi bi-download me-1" aria-hidden="true"></i>CSV</button>
        <button class="btn btn-outline-secondary btn-sm" type="button" id="bgPrint"><i class="bi bi-printer me-1" aria-hidden="true"></i>Print</button>
      </div>
    </div>
    <div class="table-responsive" tabindex="0" role="region" aria-label="Budgets">
      <table class="table align-middle mb-0">
        <thead><tr><th scope="col">Budget</th><th scope="col">Period</th><th scope="col">Status</th><th scope="col" class="num">Allocated</th><th scope="col" class="num">Spent</th><th scope="col" class="num">Held</th><th scope="col">Prepared by</th><th scope="col">Approved by</th></tr></thead>
        <tbody id="bgListBody"><tr><td colspan="8" class="text-muted p-3">Loading budgets…</td></tr></tbody>
      </table>
    </div>
  </section>

  <section id="bgDetail" hidden aria-live="polite">
    <div class="fin-panel mb-3">
      <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
        <div>
          <h2 class="mb-1" id="bgName">Budget</h2>
          <div class="small text-muted" id="bgMeta"></div>
        </div>
        <div class="d-flex flex-wrap gap-2 d-print-none" id="bgActions"></div>
      </div>
      <ul class="fin-trail mt-2" id="bgTrail"></ul>
      <div class="small mt-2" id="bgNotes"></div>
    </div>

    <ul class="fin-facts mb-3" id="bgFacts"></ul>

    <section class="fin-panel mb-3" aria-labelledby="bgDeptTitle">
      <h2 id="bgDeptTitle">Departments</h2>
      <ul class="fin-legend" aria-hidden="true"><li><i class="k-spent"></i>Spent</li><li><i class="k-held"></i>Held for pending expenses</li><li><i class="k-left"></i>Left</li><li><i class="k-over"></i>Over the allocation</li></ul>
      <ul class="fin-depts" id="bgDepts"></ul>
      <p class="small text-muted mt-2 mb-0" id="bgPayrollNote"></p>
    </section>

    <section class="fin-panel mb-3" aria-labelledby="bgLinesTitle">
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
        <h2 class="mb-0" id="bgLinesTitle">Lines</h2>
        <button class="btn btn-outline-secondary btn-sm d-print-none" type="button" id="bgLinesCsv"><i class="bi bi-download me-1" aria-hidden="true"></i>CSV</button>
      </div>
      <div class="table-responsive" tabindex="0" role="region" aria-label="Budget lines">
        <table class="table align-middle mb-0">
          <thead><tr><th scope="col">Department</th><th scope="col">Category</th><th scope="col" class="num">Allocated</th><th scope="col">Use</th><th scope="col" class="num">Left</th><th scope="col" class="d-print-none"><span class="visually-hidden">Actions</span></th></tr></thead>
          <tbody id="bgLines"></tbody>
        </table>
      </div>
    </section>

    <section class="fin-panel mb-3" aria-labelledby="bgReqTitle">
      <h2 id="bgReqTitle">Requests for more funds</h2>
      <div class="table-responsive" tabindex="0" role="region" aria-label="Requests for more funds">
        <table class="table align-middle mb-0">
          <thead><tr><th scope="col">Line</th><th scope="col" class="num">Amount</th><th scope="col">Reason</th><th scope="col">Asked by</th><th scope="col">Decision</th><th scope="col" class="d-print-none"><span class="visually-hidden">Actions</span></th></tr></thead>
          <tbody id="bgReqs"></tbody>
        </table>
      </div>
    </section>
  </section>

  <!-- Edit a draft budget -->
  <div class="modal fade" id="bgEditModal" tabindex="-1" aria-labelledby="bgEditTitle" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable"><form class="modal-content" id="bgEditForm" novalidate>
      <div class="modal-header"><h2 class="modal-title h5" id="bgEditTitle">New budget</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <input type="hidden" id="bgEditId">
        <div class="row g-2 mb-3">
          <div class="col-md-5"><label class="form-label small" for="bgEditName">Name</label><input class="form-control" id="bgEditName" maxlength="255" required placeholder="For example, Budget 2027"></div>
          <div class="col-md-4"><label class="form-label small" for="bgEditYear">Academic year</label><select class="form-select" id="bgEditYear"></select></div>
          <div class="col-md-3"><label class="form-label small" for="bgEditTerm">Period</label><select class="form-select" id="bgEditTerm"><option value="">The whole year</option><option value="1">Term 1</option><option value="2">Term 2</option><option value="3">Term 3</option></select></div>
          <div class="col-12"><label class="form-label small" for="bgEditDesc">Notes</label><textarea class="form-control" id="bgEditDesc" rows="2" maxlength="2000"></textarea></div>
        </div>
        <h3 class="h6">Lines: one per department and category</h3>
        <div class="table-responsive">
          <table class="table table-sm fin-lines-edit mb-2">
            <thead><tr><th scope="col">Department</th><th scope="col">Category</th><th scope="col">Amount (KES)</th><th scope="col">What it covers</th><th scope="col"><span class="visually-hidden">Remove</span></th></tr></thead>
            <tbody id="bgEditLines"></tbody>
          </table>
        </div>
        <button type="button" class="btn btn-outline-secondary btn-sm" id="bgEditAdd"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add a line</button>
        <span class="ms-3 fw-bold" id="bgEditTotal"></span>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-fin">Save draft</button></div>
    </form></div>
  </div>

  <!-- A decision with a note (return, approve, review) or a request for more funds -->
  <div class="modal fade" id="bgNoteModal" tabindex="-1" aria-labelledby="bgNoteTitle" aria-hidden="true">
    <div class="modal-dialog"><form class="modal-content" id="bgNoteForm" novalidate>
      <div class="modal-header"><h2 class="modal-title h5" id="bgNoteTitle">Note</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <p class="small text-muted" id="bgNoteHelp"></p>
        <div id="bgNoteAmountWrap" hidden><label class="form-label small" for="bgNoteAmount">How much more (KES)</label><input class="form-control mb-2" type="number" min="1" step="0.01" id="bgNoteAmount"></div>
        <label class="form-label small" for="bgNoteText" id="bgNoteLabel">Note</label>
        <textarea class="form-control" id="bgNoteText" rows="3" maxlength="2000"></textarea>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-fin" id="bgNoteOk">Confirm</button></div>
    </form></div>
  </div>
</div>
<?php asset_script($appBase, 'js/pages/budget_overview.js'); ?>
