/**
 * Financial dashboard (FinanceDashboardService). Charts use Chart.js (loaded
 * by the shell) with a validated three-colour palette: income, expenses,
 * payroll. Single-series charts use one hue. Every chart has a table.
 */
(() => {
  'use strict';
  const root = document.getElementById('finDashPage');
  if (!root) return;
  const $ = (id) => document.getElementById(id);
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const api = (path, params = {}) => window.API.apiCall(path, 'GET', null, params, { checkPermission: false });
  const data = (r) => (r && r.data !== undefined && !Array.isArray(r) ? r.data : r);
  const kes = (v) => 'KES ' + Number(v || 0).toLocaleString('en-KE', { maximumFractionDigits: 0 });
  const short = (v) => { const n = Math.abs(Number(v || 0)); const s = n >= 1e6 ? (n / 1e6).toFixed(1) + 'M' : n >= 1e3 ? Math.round(n / 1e3) + 'K' : String(Math.round(n)); return (v < 0 ? '-' : '') + s; };
  const month = (m) => { const [y, mo] = m.split('-'); return new Date(Number(y), Number(mo) - 1, 1).toLocaleDateString('en-KE', { month: 'short', year: '2-digit' }); };
  const dark = () => document.documentElement.getAttribute('data-kw-theme') === 'dark';
  // Validated palette (dataviz reference slots 1-3), light and dark steps.
  const C = () => (dark()
    ? { income: '#3987e5', expenses: '#d95926', payroll: '#199e70', grid: 'rgba(255,255,255,.08)', text: '#c3c2b7' }
    : { income: '#2a78d6', expenses: '#eb6834', payroll: '#1baf7a', grid: 'rgba(0,0,0,.06)', text: '#52514e' });
  const charts = {};
  let d = null;
  let years = [];

  function chart(id, config) {
    if (!window.Chart) return;
    charts[id]?.destroy();
    const c = C();
    config.options = Object.assign({
      responsive: true, maintainAspectRatio: false, animation: false,
      interaction: { mode: 'index', intersect: false },
      plugins: { legend: { labels: { color: c.text, boxWidth: 12, boxHeight: 12 } },
        tooltip: { callbacks: { label: (ctx) => `${ctx.dataset.label ? ctx.dataset.label + ': ' : ''}${kes(ctx.parsed.y ?? ctx.parsed.x ?? ctx.parsed)}` } } },
    }, config.options || {});
    charts[id] = new window.Chart($(id), config);
  }
  const axes = (horizontal = false) => {
    const c = C();
    const val = { grid: { color: c.grid }, border: { display: false }, ticks: { color: c.text, callback: (v) => short(v) } };
    const cat = { grid: { display: false }, border: { display: false }, ticks: { color: c.text } };
    return horizontal ? { x: { ...val, beginAtZero: true }, y: cat } : { x: cat, y: { ...val, beginAtZero: true } };
  };
  const bar = { borderRadius: 4, borderSkipped: 'start', maxBarThickness: 28, borderColor: 'transparent', borderWidth: 2 };

  function quota(allocated, spent, held) {
    const total = Math.max(allocated, spent + held, 1);
    const sp = Math.min(100, (spent / total) * 100);
    const hd = Math.min(100 - sp, (held / total) * 100);
    const left = allocated - spent - held;
    const over = left < -0.004;
    return `<div class="fin-quota${over ? ' is-over' : ''}"><div class="fin-quota__bar" role="img" aria-label="${esc(`${kes(spent)} spent, ${kes(held)} held of ${kes(allocated)}; ${over ? kes(-left) + ' over' : kes(left) + ' left'}`)}">
      <span class="fin-quota__spent" style="inline-size:${sp}%"></span><span class="fin-quota__held" style="inline-size:${hd}%"></span>${over ? '<span class="fin-quota__over"></span>' : ''}</div>
      <div class="fin-quota__text"><span><strong>${kes(spent)}</strong> spent</span>${held ? `<span><strong>${kes(held)}</strong> held</span>` : ''}<span class="left">${over ? `${kes(-left)} over` : `${kes(left)} left`}</span><span>of ${kes(allocated)}</span></div></div>`;
  }

  function render() {
    const k = d.kpis;
    const r = d.receivables;
    const p = d.payables;
    const owes = p.expenses_to_pay.amount + p.payments_in_progress.amount + p.payroll_to_pay.amount;
    $('fdLede').textContent = `Academic year ${d.year.code}: ${new Date(d.year.from).toLocaleDateString('en-KE', { month: 'long', year: 'numeric' })} to ${new Date(d.year.to).toLocaleDateString('en-KE', { month: 'long', year: 'numeric' })}.`;
    $('fdKpis').innerHTML = [
      ['', 'Money received', kes(k.income), (d.income_by_purpose || []).filter((x) => x.amount).map((x) => `${x.purpose} ${short(x.amount)}`).join(', ') || 'fees, transport and uniforms'],
      ['', 'Spending', kes(k.spending), `expenses and ${kes(d.payroll_total)} payroll`],
      [k.surplus < 0 ? 'is-stop' : 'is-ok', k.surplus < 0 ? 'Deficit' : 'Surplus', kes(Math.abs(k.surplus)), k.margin_pct !== null ? `${k.margin_pct}% of money received` : ''],
      [r.collection_rate !== null && r.collection_rate < 80 ? 'is-warn' : '', 'Fees collected', r.collection_rate !== null ? `${r.collection_rate}%` : '-', `${kes(r.collected)} of ${kes(r.billed - r.waived)} billed`],
      [r.owed ? 'is-warn' : '', 'Fees outstanding', kes(r.owed), `${r.learners_owing} learners owe${r.in_credit ? `; ${kes(r.in_credit)} in credit` : ''}`],
      [owes ? 'is-warn' : '', 'The school owes', kes(owes), 'approved, not yet paid'],
    ].map(([c, l, v, s]) => `<li class="${c}"><span class="fin-fact__l">${esc(l)}</span><span class="fin-fact__v">${esc(v)}</span><span class="fin-fact__s">${esc(s)}</span></li>`).join('');

    const c = C();
    const labels = d.months.map((m) => month(m.month));
    chart('fdFlowChart', { type: 'bar', data: { labels, datasets: [
      { label: 'Income', data: d.months.map((m) => m.income), backgroundColor: c.income, stack: 'in', ...bar },
      { label: 'Expenses', data: d.months.map((m) => m.expenses), backgroundColor: c.expenses, stack: 'out', ...bar },
      { label: 'Payroll', data: d.months.map((m) => m.payroll), backgroundColor: c.payroll, stack: 'out', ...bar },
    ] }, options: { scales: { ...axes(), x: { ...axes().x, stacked: true }, y: { ...axes().y, stacked: true } } } });
    chart('fdNetChart', { type: 'bar', data: { labels, datasets: [
      { label: 'Surplus or deficit', data: d.months.map((m) => m.net), backgroundColor: c.income, ...bar },
    ] }, options: { plugins: { legend: { display: false } }, scales: axes() } });
    chart('fdAgingChart', { type: 'bar', data: { labels: r.aging.map((a) => a.label), datasets: [
      { label: 'Outstanding', data: r.aging.map((a) => a.amount), backgroundColor: c.expenses, ...bar },
    ] }, options: { plugins: { legend: { display: false } }, scales: axes() } });
    $('fdArNote').textContent = `${kes(r.owed)} owed by ${r.learners_owing} learners. ${kes(r.waived)} was waived or covered by scholarships this year.`
      + (d.recorded_at_go_live ? ` ${kes(d.recorded_at_go_live)} was paid before learners joined the system; it counts as collected but not as money received this year.` : '');
    const cats = d.expense_categories.slice(0, 8);
    const rest = d.expense_categories.slice(8).reduce((s, x) => s + x.amount, 0);
    if (rest) cats.push({ category: 'Other', amount: rest });
    chart('fdCatChart', { type: 'bar', data: { labels: cats.map((x) => x.category), datasets: [
      { label: 'Spent', data: cats.map((x) => x.amount), backgroundColor: c.expenses, ...bar, borderSkipped: 'start' },
    ] }, options: { indexAxis: 'y', plugins: { legend: { display: false } }, scales: axes(true), interaction: { mode: 'nearest', axis: 'y', intersect: false } } });
    if (!cats.length) $('fdCatChart').closest('.fin-chart').insertAdjacentHTML('afterend', '<p class="small text-muted mb-0 fd-empty">No approved expenses yet this year.</p>');
    chart('fdChanChart', { type: 'bar', data: { labels: d.channels.map((x) => x.channel), datasets: [
      { label: 'Received', data: d.channels.map((x) => x.amount), backgroundColor: c.income, ...bar },
    ] }, options: { indexAxis: 'y', plugins: { legend: { display: false } }, scales: axes(true), interaction: { mode: 'nearest', axis: 'y', intersect: false } } });

    const waitPanel = $('fdWaitPanel');
    if (!d.waiting) { waitPanel.hidden = true; $('fdFlowChart').closest('section').classList.replace('span-8', 'span-12'); }
    else {
      $('fdWaiting').innerHTML = d.waiting.map((w) => `<li class="d-flex justify-content-between align-items-baseline gap-2 py-2 border-bottom">
          <a href="home.php?route=${esc(w.route)}" class="${w.count ? 'fw-semibold' : 'text-muted'}">${esc(w.label)}</a>
          <span class="text-nowrap">${w.count ? `<strong>${w.count}</strong>${w.amount ? `<span class="small text-muted ms-1">${kes(w.amount)}</span>` : ''}` : '<span class="text-muted">None</span>'}</span></li>`).join('');
    }

    $('fdDepts').innerHTML = d.budget && d.budget.departments.length
      ? d.budget.departments.map((x) => `<li><div class="fin-dept__name">${esc(x.department)}<small>${x.used_pct !== null ? `${x.used_pct}% used` : ''}</small></div>${quota(x.allocated, x.spent, x.committed)}</li>`).join('')
      : '<li class="text-muted">No approved budget for this year yet. Prepare one under Budgets.</li>';

    $('fdClassBody').innerHTML = r.by_class.length ? r.by_class.map((x) => `<tr><td>${esc(x.class)}</td><td class="num">${kes(x.billed)}</td><td class="num ${x.owed ? 'fw-bold' : ''}">${kes(x.owed)}</td><td class="num">${x.learners}</td></tr>`).join('')
      : '<tr><td colspan="4" class="text-muted p-3">No fees billed this year.</td></tr>';
    $('fdPayables').innerHTML = [
      ['Approved expenses to pay', p.expenses_to_pay],
      ['Supplier payments in progress', p.payments_in_progress],
      ['Payroll approved, not paid', p.payroll_to_pay],
    ].map(([l, x]) => `<li class="${x.amount ? 'is-warn' : ''}"><span class="fin-fact__l">${esc(l)}</span><span class="fin-fact__v">${esc(kes(x.amount))}</span><span class="fin-fact__s">${x.count} item${x.count === 1 ? '' : 's'}</span></li>`).join('');
    $('fdMonthBody').innerHTML = d.months.map((m) => `<tr><td>${esc(month(m.month))}</td><td class="num">${kes(m.income)}</td><td class="num">${kes(m.expenses)}</td><td class="num">${kes(m.payroll)}</td>
      <td class="num ${m.net < 0 ? 'text-danger' : ''}">${kes(m.net)}</td><td class="num">${kes(m.running)}</td></tr>`).join('');
  }

  async function load() {
    root.querySelectorAll('.fd-empty').forEach((e) => e.remove());
    try { d = data(await api('/finance/financial-dashboard', { academic_year_id: $('fdYear').value || '' })); }
    catch (e) { $('fdKpis').innerHTML = `<li><span class="fin-fact__l">${esc(e.message || 'The dashboard could not be loaded.')}</span></li>`; return; }
    if (!d.year) { $('fdKpis').innerHTML = '<li><span class="fin-fact__l">No academic year is set up.</span></li>'; return; }
    if (!$('fdYear').options.length) {
      try { years = data(await api('/finance/budget-workspace')).years || []; } catch { years = [{ id: d.year.id, year_code: d.year.code }]; }
      $('fdYear').innerHTML = years.map((y) => `<option value="${y.id}" ${Number(y.id) === d.year.id ? 'selected' : ''}>${esc(y.year_code)}</option>`).join('');
    }
    render();
  }

  async function csv(name, head, rows) {
    const cell = (v) => { const t = String(v ?? ''); return /[",\r\n]/.test(t) ? `"${t.replace(/"/g, '""')}"` : t; };
    try { await window.KingswayFileLifecycle.exportText(`﻿${[head, ...rows].map((r) => r.map(cell).join(',')).join('\r\n')}`, name, 'text/csv;charset=utf-8'); }
    catch (e) { window.showNotification?.(e.message || 'Export failed', 'danger'); }
  }

  async function init() {
    await window.AuthContext?.ready?.();
    $('fdYear').addEventListener('change', load);
    $('fdPrint').addEventListener('click', () => window.print());
    $('fdMonthCsv').addEventListener('click', () => d && csv(`finance_by_month_${d.year.code.replace('/', '-')}.csv`, ['Month', 'Income', 'Expenses', 'Payroll', 'Surplus or deficit', 'Running total'], d.months.map((m) => [m.month, m.income, m.expenses, m.payroll, m.net, m.running])));
    $('fdClassCsv').addEventListener('click', () => d && csv(`fees_outstanding_by_class_${d.year.code.replace('/', '-')}.csv`, ['Class', 'Billed', 'Outstanding', 'Learners owing'], d.receivables.by_class.map((x) => [x.class, x.billed, x.owed, x.learners])));
    if (window.AuthContext?.canExport?.() === false) { $('fdMonthCsv').hidden = true; $('fdClassCsv').hidden = true; }
    new MutationObserver(() => d && render()).observe(document.documentElement, { attributes: true, attributeFilter: ['data-kw-theme'] });
    window.APIRealtime?.register?.('financial_dashboard', { refresh: load }, ['finance']);
    await load();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
