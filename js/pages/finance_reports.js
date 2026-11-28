/**
 * Financial reports: six statements built from the Financial Dashboard's
 * figures (FinanceDashboardService), so the two always agree. Charts use the
 * same validated palette as the dashboard; every view has a table and CSV.
 */
(() => {
  'use strict';
  const root = document.getElementById('finReportsPage');
  if (!root) return;
  const $ = (id) => document.getElementById(id);
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const api = (path, params = {}) => window.API.apiCall(path, 'GET', null, params, { checkPermission: false });
  const data = (r) => (r && r.data !== undefined && !Array.isArray(r) ? r.data : r);
  const kes = (v) => 'KES ' + Number(v || 0).toLocaleString('en-KE', { maximumFractionDigits: 0 });
  const short = (v) => { const n = Math.abs(Number(v || 0)); const s = n >= 1e6 ? (n / 1e6).toFixed(1) + 'M' : n >= 1e3 ? Math.round(n / 1e3) + 'K' : String(Math.round(n)); return (v < 0 ? '-' : '') + s; };
  const month = (m) => { const [y, mo] = m.split('-'); return new Date(Number(y), Number(mo) - 1, 1).toLocaleDateString('en-KE', { month: 'short', year: '2-digit' }); };
  const pct = (a, b) => (b ? `${Math.round((a * 1000) / b) / 10}%` : '');
  const dark = () => document.documentElement.getAttribute('data-kw-theme') === 'dark';
  const C = () => (dark()
    ? { income: '#3987e5', expenses: '#d95926', payroll: '#199e70', grid: 'rgba(255,255,255,.08)', text: '#c3c2b7' }
    : { income: '#2a78d6', expenses: '#eb6834', payroll: '#1baf7a', grid: 'rgba(0,0,0,.06)', text: '#52514e' });
  let d = null;
  let view = 'income';
  let chartObj = null;
  let table = { head: [], rows: [], name: 'report' };

  const facts = (list) => list.map(([c, l, v, s]) => `<li class="${c}"><span class="fin-fact__l">${esc(l)}</span><span class="fin-fact__v">${esc(v)}</span><span class="fin-fact__s">${esc(s || '')}</span></li>`).join('');
  function chart(title, config) {
    $('frChartPanel').hidden = !config;
    chartObj?.destroy(); chartObj = null;
    if (!config || !window.Chart) return;
    $('frChartTitle').textContent = title;
    const c = C();
    const val = { grid: { color: c.grid }, border: { display: false }, ticks: { color: c.text, callback: (v) => short(v) }, beginAtZero: true };
    const cat = { grid: { display: false }, border: { display: false }, ticks: { color: c.text } };
    const horizontal = config.options?.indexAxis === 'y';
    config.options = Object.assign({ responsive: true, maintainAspectRatio: false, animation: false, interaction: { mode: 'index', intersect: false },
      plugins: { legend: { display: config.data.datasets.length > 1, labels: { color: c.text, boxWidth: 12, boxHeight: 12 } },
        tooltip: { callbacks: { label: (ctx) => `${ctx.dataset.label}: ${kes(ctx.parsed[horizontal ? 'x' : 'y'])}` } } },
      scales: horizontal ? { x: val, y: cat } : { x: { ...cat, stacked: !!config.stacked }, y: { ...val, stacked: !!config.stacked } } }, config.options || {});
    config.data.datasets.forEach((ds) => Object.assign(ds, { borderRadius: 4, borderSkipped: 'start', maxBarThickness: 28, borderColor: 'transparent', borderWidth: 2 }, ds));
    chartObj = new window.Chart($('frChart'), config);
  }
  function setTable(title, head, rows, foot, name, csvRows) {
    $('frTableTitle').textContent = title;
    $('frHead').innerHTML = `<tr>${head.map((h, i) => `<th scope="col" class="${i ? 'num' : ''}">${esc(h)}</th>`).join('')}</tr>`;
    $('frBody').innerHTML = rows.length ? rows.map((r) => `<tr${r.cls ? ` class="${r.cls}"` : ''}>${r.cells.map((c, i) => `<td class="${i ? 'num' : ''}">${c}</td>`).join('')}</tr>`).join('')
      : `<tr><td colspan="${head.length}" class="text-muted p-3">Nothing to report for this year.</td></tr>`;
    $('frFoot').innerHTML = foot ? `<tr class="fw-bold">${foot.map((c, i) => `<td class="${i ? 'num' : ''}">${c}</td>`).join('')}</tr>` : '';
    table = { head, rows: csvRows, name };
  }

  const VIEWS = {
    income() {
      const income = d.income_by_purpose.reduce((s, x) => s + x.amount, 0);
      const exp = d.expense_categories.reduce((s, x) => s + x.amount, 0);
      const pay = d.payroll_total;
      const net = income - exp - pay;
      $('frKpis').innerHTML = facts([['', 'Income', kes(income)], ['', 'Expenses', kes(exp)], ['', 'Payroll', kes(pay)],
        [net < 0 ? 'is-stop' : 'is-ok', net < 0 ? 'Deficit' : 'Surplus', kes(Math.abs(net)), income ? `${pct(net, income)} of income` : '']]);
      const c = C();
      chart('Income and spending, month by month', { type: 'bar', stacked: true, data: { labels: d.months.map((m) => month(m.month)), datasets: [
        { label: 'Income', data: d.months.map((m) => m.income), backgroundColor: c.income, stack: 'in' },
        { label: 'Expenses', data: d.months.map((m) => m.expenses), backgroundColor: c.expenses, stack: 'out' },
        { label: 'Payroll', data: d.months.map((m) => m.payroll), backgroundColor: c.payroll, stack: 'out' }] } });
      const rows = [{ cls: 'table-light fw-bold', cells: ['Income', '', ''] }]
        .concat(d.income_by_purpose.map((x) => ({ cells: [esc(x.purpose), kes(x.amount), pct(x.amount, income)] })))
        .concat([{ cls: 'fw-bold', cells: ['Total income', kes(income), ''] }, { cls: 'table-light fw-bold', cells: ['Spending', '', ''] }])
        .concat(d.expense_categories.map((x) => ({ cells: [esc(x.category), kes(x.amount), pct(x.amount, income)] })))
        .concat([{ cells: ['Payroll (gross pay and employer contributions)', kes(pay), pct(pay, income)] }, { cls: 'fw-bold', cells: ['Total spending', kes(exp + pay), pct(exp + pay, income)] }]);
      setTable('Income statement', ['Line', 'Amount', 'Of income'], rows, [net < 0 ? 'Deficit' : 'Surplus', kes(net), pct(net, income)], 'income_statement',
        [...d.income_by_purpose.map((x) => ['Income', x.purpose, x.amount]), ...d.expense_categories.map((x) => ['Expense', x.category, x.amount]), ['Expense', 'Payroll', pay], ['Result', net < 0 ? 'Deficit' : 'Surplus', net]]);
      table.head = ['Section', 'Line', 'Amount'];
    },
    cash() {
      const inflow = d.months.reduce((s, m) => s + m.income, 0);
      const outflow = d.months.reduce((s, m) => s + m.expenses + m.payroll, 0);
      const worst = d.months.reduce((w, m) => (m.running < w.running ? m : w), d.months[0] || { running: 0 });
      $('frKpis').innerHTML = facts([['', 'Money in', kes(inflow)], ['', 'Money out', kes(outflow)], [inflow - outflow < 0 ? 'is-stop' : 'is-ok', 'Net', kes(inflow - outflow)],
        [worst && worst.running < 0 ? 'is-warn' : '', 'Lowest running total', kes(worst?.running || 0), worst?.month ? month(worst.month) : '']]);
      chart('Running total through the year', { type: 'line', data: { labels: d.months.map((m) => month(m.month)), datasets: [
        { label: 'Running total', data: d.months.map((m) => m.running), borderColor: C().income, backgroundColor: C().income, borderWidth: 2, pointRadius: 4, tension: 0 }] } });
      setTable('Cash flow by month', ['Month', 'Money in', 'Expenses', 'Payroll', 'Net', 'Running total'],
        d.months.map((m) => ({ cells: [esc(month(m.month)), kes(m.income), kes(m.expenses), kes(m.payroll), `<span class="${m.net < 0 ? 'text-danger' : ''}">${kes(m.net)}</span>`, kes(m.running)] })),
        ['Year', kes(inflow), kes(d.months.reduce((s, m) => s + m.expenses, 0)), kes(d.payroll_total), kes(inflow - outflow), ''], 'cash_flow',
        d.months.map((m) => [m.month, m.income, m.expenses, m.payroll, m.net, m.running]));
    },
    fees() {
      const r = d.receivables;
      $('frKpis').innerHTML = facts([['', 'Billed', kes(r.billed), r.waived ? `${kes(r.waived)} waived or covered by scholarships` : ''], ['', 'Collected', kes(r.collected), r.collection_rate !== null ? `${r.collection_rate}% of what was due` : ''],
        [r.owed ? 'is-warn' : '', 'Outstanding', kes(r.owed), `${r.learners_owing} learners`], ['', 'In credit', kes(r.in_credit), 'overpaid, carried forward']]);
      chart('Fees outstanding, by how long', { type: 'bar', data: { labels: r.aging.map((a) => a.label), datasets: [{ label: 'Outstanding', data: r.aging.map((a) => a.amount), backgroundColor: C().expenses }] } });
      setTable('Fees by class', ['Class', 'Billed', 'Outstanding', 'Collected', 'Learners owing'],
        r.by_class.map((x) => ({ cells: [esc(x.class), kes(x.billed), `<strong>${kes(x.owed)}</strong>`, x.billed ? pct(x.billed - x.owed, x.billed) : '', String(x.learners)] })),
        ['All classes', kes(r.by_class.reduce((s, x) => s + x.billed, 0)), kes(r.owed), r.collection_rate !== null ? `${r.collection_rate}%` : '', String(r.learners_owing)], 'fees_by_class',
        r.by_class.map((x) => [x.class, x.billed, x.owed, x.learners]));
      table.head = ['Class', 'Billed', 'Outstanding', 'Learners owing'];
      $('frExtraPanel').hidden = false;
      $('frExtraPanel').innerHTML = '<p class="mb-0 small">For each learner\'s balance, open <a href="home.php?route=students_with_balance">Students with balances</a> or <a href="home.php?route=student_fees">Student fee accounts</a>.</p>';
    },
    expenses() {
      const total = d.expense_categories.reduce((s, x) => s + x.amount, 0);
      const p = d.expenses_pending || { count: 0, amount: 0 };
      $('frKpis').innerHTML = facts([['', 'Approved and paid', kes(total)], ['', 'Categories', String(d.expense_categories.length)],
        [p.amount ? 'is-warn' : '', 'Waiting for approval', kes(p.amount), `${p.count} expense${p.count === 1 ? '' : 's'}, not counted yet`], ['', 'Payroll', kes(d.payroll_total), 'shown in the income statement']]);
      const cats = d.expense_categories.slice(0, 10);
      chart('Spending by category', { type: 'bar', data: { labels: cats.map((x) => x.category), datasets: [{ label: 'Spent', data: cats.map((x) => x.amount), backgroundColor: C().expenses }] }, options: { indexAxis: 'y', interaction: { mode: 'nearest', axis: 'y', intersect: false } } });
      setTable('Expenses by category', ['Category', 'Spent', 'Share'], d.expense_categories.map((x) => ({ cells: [esc(x.category), kes(x.amount), pct(x.amount, total)] })),
        ['Total', kes(total), ''], 'expenses_by_category', d.expense_categories.map((x) => [x.category, x.amount]));
      table.head = ['Category', 'Spent'];
    },
    budget() {
      const b = d.budget;
      $('frKpis').innerHTML = b ? facts([['', 'Budget', b.name], ['', 'Allocated', kes(b.totals.allocated)], ['', 'Spent', kes(b.totals.spent)],
        [b.totals.allocated - b.totals.spent - b.totals.committed < 0 ? 'is-stop' : 'is-ok', 'Left', kes(b.totals.allocated - b.totals.spent - b.totals.committed), `${kes(b.totals.committed)} held for pending expenses`]])
        : facts([['', 'Budget', 'None approved', 'Prepare one under Budgets']]);
      const deps = b ? b.departments : [];
      const c = C();
      chart('Allocated and spent, by department', deps.length ? { type: 'bar', data: { labels: deps.map((x) => x.department), datasets: [
        { label: 'Allocated', data: deps.map((x) => x.allocated), backgroundColor: c.income },
        { label: 'Spent', data: deps.map((x) => x.spent), backgroundColor: c.expenses }] }, options: { indexAxis: 'y', interaction: { mode: 'index', axis: 'y', intersect: false } } } : null);
      setTable('Budget vs actual', ['Department', 'Allocated', 'Spent', 'Held', 'Left', 'Used'],
        deps.map((x) => { const left = x.allocated - x.spent - x.committed; return { cells: [esc(x.department), kes(x.allocated), kes(x.spent), kes(x.committed), `<span class="${left < 0 ? 'text-danger fw-bold' : ''}">${kes(left)}</span>`, x.used_pct !== null && x.used_pct !== undefined ? `${x.used_pct}%` : ''] }; }),
        b ? ['All departments', kes(b.totals.allocated), kes(b.totals.spent), kes(b.totals.committed), kes(b.totals.allocated - b.totals.spent - b.totals.committed), ''] : null, 'budget_vs_actual',
        deps.map((x) => [x.department, x.allocated, x.spent, x.committed, x.allocated - x.spent - x.committed, x.used_pct ?? '']));
    },
    payables() {
      const p = d.payables;
      const items = [['Approved expenses to pay', p.expenses_to_pay], ['Supplier payments in progress', p.payments_in_progress], ['Payroll approved, not paid', p.payroll_to_pay]];
      const total = items.reduce((s, [, x]) => s + x.amount, 0);
      $('frKpis').innerHTML = facts([[total ? 'is-warn' : '', 'The school owes', kes(total), 'approved, not yet paid'], ...items.map(([l, x]) => [x.amount ? 'is-warn' : '', l, kes(x.amount), `${x.count} item${x.count === 1 ? '' : 's'}`])]);
      chart('', null);
      setTable('What the school owes', ['Item', 'Count', 'Amount'], items.map(([l, x]) => ({ cells: [esc(l), String(x.count), kes(x.amount)] })), ['Total', '', kes(total)], 'payables', items.map(([l, x]) => [l, x.count, x.amount]));
      $('frExtraPanel').hidden = false;
      $('frExtraPanel').innerHTML = '<p class="mb-0 small">Pay suppliers under <a href="home.php?route=supplier_payments">Supplier payments</a> and staff under <a href="home.php?route=payroll">Payroll</a>.</p>';
    },
  };
  const TITLES = { income: 'Income statement', cash: 'Cash flow', fees: 'Fees owed to the school', expenses: 'Expenses', budget: 'Budget vs actual', payables: 'What the school owes' };

  function render() {
    if (!d) return;
    $('frExtraPanel').hidden = true;
    $('frTitle').textContent = `${TITLES[view]}, ${d.year.code}`;
    VIEWS[view]();
  }

  async function load() {
    try { d = data(await api('/finance/financial-dashboard', { academic_year_id: $('frYear').value || '' })); }
    catch (e) { $('frBody').innerHTML = `<tr><td class="text-muted p-3">${esc(e.message || 'The report could not be loaded.')}</td></tr>`; return; }
    if (!d.year) { $('frBody').innerHTML = '<tr><td class="text-muted p-3">No academic year is set up.</td></tr>'; return; }
    if (!$('frYear').options.length) {
      let years = [];
      try { years = data(await api('/finance/budget-workspace')).years || []; } catch { /* the Headteacher may not list budgets */ }
      if (!years.length) years = [{ id: d.year.id, year_code: d.year.code }];
      $('frYear').innerHTML = years.map((y) => `<option value="${y.id}" ${Number(y.id) === d.year.id ? 'selected' : ''}>${esc(y.year_code)}</option>`).join('');
    }
    $('frLede').textContent = `Academic year ${d.year.code}. The figures match the Financial Dashboard.`;
    render();
  }

  async function exportCsv() {
    const cell = (v) => { const t = String(v ?? ''); return /[",\r\n]/.test(t) ? `"${t.replace(/"/g, '""')}"` : t; };
    try { await window.KingswayFileLifecycle.exportText(`﻿${[table.head, ...table.rows].map((r) => r.map(cell).join(',')).join('\r\n')}`, `${table.name}_${(d?.year.code || '').replace('/', '-')}.csv`, 'text/csv;charset=utf-8'); }
    catch (e) { window.showNotification?.(e.message || 'Export failed', 'danger'); }
  }

  async function init() {
    await window.AuthContext?.ready?.();
    const tabs = [...$('frTabs').querySelectorAll('[role="tab"]')];
    const select = (t, focus) => {
      tabs.forEach((x) => { const on = x === t; x.classList.toggle('active', on); x.setAttribute('aria-selected', String(on)); x.tabIndex = on ? 0 : -1; });
      view = t.dataset.view; if (focus) t.focus(); render();
    };
    $('frTabs').addEventListener('click', (e) => { const t = e.target.closest('[role="tab"]'); if (t) select(t); });
    $('frTabs').addEventListener('keydown', (e) => {
      const i = tabs.indexOf(document.activeElement); if (i < 0) return;
      const n = { ArrowRight: i + 1, ArrowLeft: i - 1, Home: 0, End: tabs.length - 1 }[e.key]; if (n === undefined) return;
      e.preventDefault(); select(tabs[(n + tabs.length) % tabs.length], true);
    });
    $('frYear').addEventListener('change', load);
    $('frPrint').addEventListener('click', () => window.print());
    $('frCsv').addEventListener('click', exportCsv);
    if (window.AuthContext?.canExport?.() === false) $('frCsv').hidden = true;
    new MutationObserver(() => render()).observe(document.documentElement, { attributes: true, attributeFilter: ['data-kw-theme'] });
    window.APIRealtime?.register?.('finance_reports', { refresh: load }, ['finance']);
    await load();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
