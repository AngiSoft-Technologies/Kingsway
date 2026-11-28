/**
 * Budgets: list, prepare, review, approve, activate and follow department
 * quotas (BudgetService). The server decides who may do what; the page only
 * hides buttons the user cannot use.
 */
(() => {
  'use strict';
  const root = document.getElementById('budgetPage');
  if (!root) return;
  const $ = (id) => document.getElementById(id);
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const api = (path, method = 'GET', body = null, params = {}) => window.API.apiCall(path, method, body, params, { checkPermission: false });
  const data = (r) => (r && r.data !== undefined && !Array.isArray(r) ? r.data : r);
  const notify = (m, t = 'info') => window.showNotification?.(m, t);
  const kes = (v) => 'KES ' + Number(v || 0).toLocaleString('en-KE', { maximumFractionDigits: 0 });
  const day = (d) => { if (!d) return ''; const x = new Date(String(d).replace(' ', 'T')); return isNaN(x) ? esc(d) : x.toLocaleDateString('en-KE', { day: 'numeric', month: 'short', year: 'numeric' }); };
  const STATUS = { draft: 'Draft', submitted: 'Submitted', under_review: 'Reviewed', approved: 'Approved', active: 'Active', closed: 'Closed', rejected: 'Returned', pending: 'Waiting' };
  const tag = (s) => `<span class="fin-tag is-${esc(s)}">${esc(STATUS[s] || s)}</span>`;
  const modal = (id) => bootstrap.Modal.getOrCreateInstance($(id));
  let ws = { budgets: [], departments: [], categories: [], years: [], can: {} };
  let current = null;
  let noteAction = null;

  // ------------------------------------------------------------------ list

  async function loadList() {
    try {
      ws = data(await api('/finance/budget-workspace', 'GET', null, { academic_year_id: $('bgYear').value || '' }));
    } catch (e) { $('bgListBody').innerHTML = `<tr><td colspan="8" class="text-muted p-3">${esc(e.message || 'Budgets could not be loaded.')}</td></tr>`; return; }
    if (!$('bgYear').options.length) {
      $('bgYear').innerHTML = ws.years.map((y) => `<option value="${y.id}" ${Number(y.is_current) ? 'selected' : ''}>${esc(y.year_code)}</option>`).join('');
      return loadList();
    }
    $('bgNew').hidden = !ws.can.prepare;
    const period = (b) => (b.term ? `Term ${b.term}, ` : 'Whole year, ') + esc(b.academic_year || '');
    $('bgListBody').innerHTML = ws.budgets.length ? ws.budgets.map((b) => `<tr>
        <td><button type="button" class="btn btn-link p-0 fw-bold text-start text-decoration-none" data-open="${b.id}">${esc(b.name)}</button>
          ${b.legacy_department_allocation ? '<div class="small text-muted">Older department allocation</div>' : ''}</td>
        <td>${period(b)}</td><td>${tag(b.status)}</td>
        <td class="num">${kes(b.allocated || b.total_amount)}</td><td class="num">${kes(b.spent)}</td><td class="num">${kes(b.committed)}</td>
        <td>${esc(b.prepared_by || '')}</td><td>${esc(b.approved_by || '')}${b.approved_at ? `<div class="small text-muted">${day(b.approved_at)}</div>` : ''}</td></tr>`).join('')
      : `<tr><td colspan="8" class="text-muted p-3">No budget for this year yet.${ws.can.prepare ? ' Use "New budget" to prepare one, department by department.' : ''}</td></tr>`;
    if (root.dataset.start === 'active') {
      root.dataset.start = '';
      const active = ws.budgets.find((b) => b.status === 'active') || ws.budgets.find((b) => b.status === 'approved');
      if (active) openBudget(active.id);
    }
  }

  // ---------------------------------------------------------------- detail

  async function openBudget(id) {
    try { current = data(await api(`/finance/budget-detail/${Number(id)}`)); }
    catch (e) { notify(e.message || 'The budget could not be loaded.', 'danger'); return; }
    renderDetail();
    $('bgDetail').hidden = false;
    $('bgDetail').scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function quota(allocated, spent, held) {
    const total = Math.max(allocated, spent + held, 1);
    const sp = Math.min(100, (spent / total) * 100);
    const hd = Math.min(100 - sp, (held / total) * 100);
    const left = allocated - spent - held;
    const over = left < -0.004;
    return `<div class="fin-quota${over ? ' is-over' : ''}">
      <div class="fin-quota__bar" role="img" aria-label="${esc(`${kes(spent)} spent and ${kes(held)} held of ${kes(allocated)}; ${over ? kes(-left) + ' over' : kes(left) + ' left'}`)}">
        <span class="fin-quota__spent" style="inline-size:${sp}%"></span><span class="fin-quota__held" style="inline-size:${hd}%"></span>${over ? '<span class="fin-quota__over"></span>' : ''}</div>
      <div class="fin-quota__text"><span><strong>${kes(spent)}</strong> spent</span>${held ? `<span><strong>${kes(held)}</strong> held</span>` : ''}<span class="left">${over ? `${kes(-left)} over` : `${kes(left)} left`}</span><span>of ${kes(allocated)}</span></div></div>`;
  }

  function actionsFor(b) {
    const can = ws.can;
    const btn = (action, label, cls = 'btn-outline-secondary') => `<button type="button" class="btn ${cls}" data-act="${action}">${esc(label)}</button>`;
    const out = [];
    if (can.prepare && ['draft', 'rejected'].includes(b.status)) out.push(btn('edit', 'Edit lines'), btn('submit', 'Submit for review', 'btn-fin'));
    if (can.decide && b.status === 'submitted') out.push(btn('review', 'Mark reviewed'), btn('approve', 'Approve', 'btn-fin'), btn('return', 'Return for changes', 'btn-outline-danger'));
    if (can.decide && b.status === 'under_review') out.push(btn('approve', 'Approve', 'btn-fin'), btn('return', 'Return for changes', 'btn-outline-danger'));
    if (can.decide && b.status === 'approved') out.push(btn('activate', 'Make active', 'btn-fin'), btn('return', 'Return for changes', 'btn-outline-danger'));
    if (can.decide && b.status === 'active') out.push(btn('close', 'Close the budget'));
    out.push(btn('print', 'Print'));
    return out.join('');
  }

  function renderDetail() {
    const b = current.budget;
    $('bgName').innerHTML = `${esc(b.name)} ${tag(b.status)}`;
    $('bgMeta').textContent = `${b.term ? `Term ${b.term}` : 'Whole year'}, ${b.academic_year || ''}${b.description ? `. ${b.description}` : ''}`;
    $('bgActions').innerHTML = actionsFor(b);
    $('bgTrail').innerHTML = b.trail.map(([what, who, when]) => `<li><strong>${esc(what)}</strong>${who ? ` by ${esc(who)}` : ''}, ${day(when)}</li>`).join('');
    $('bgNotes').innerHTML = [b.review_notes ? `<div><strong>Review note:</strong> ${esc(b.review_notes)}</div>` : '', b.approval_notes ? `<div><strong>Approval note:</strong> ${esc(b.approval_notes)}</div>` : ''].join('');

    const t = current.totals;
    const left = t.allocated - t.spent - t.committed;
    $('bgFacts').innerHTML = [
      ['', 'Allocated', kes(t.allocated), `${current.lines.length} lines across ${current.departments.length} departments`],
      ['', 'Spent', kes(t.spent), current.payroll_total ? `including ${kes(current.payroll_total)} payroll` : 'approved and paid expenses'],
      [t.committed ? 'is-warn' : '', 'Held', kes(t.committed), 'pending expenses'],
      [left < 0 ? 'is-stop' : 'is-ok', left < 0 ? 'Over the budget' : 'Left', kes(Math.abs(left)), t.allocated ? `${Math.round(((t.spent + t.committed) * 100) / t.allocated)}% used` : ''],
    ].map(([c, l, v, s]) => `<li class="${c}"><span class="fin-fact__l">${esc(l)}</span><span class="fin-fact__v">${esc(v)}</span><span class="fin-fact__s">${esc(s)}</span></li>`).join('');

    $('bgDepts').innerHTML = current.departments.map((d) => `<li><div class="fin-dept__name">${esc(d.department)}<small>${d.lines} line${d.lines === 1 ? '' : 's'}${d.used_pct !== null ? `, ${d.used_pct}% used` : ''}</small></div>${quota(d.allocated, d.spent, d.committed)}</li>`).join('')
      || '<li class="text-muted">No lines yet.</li>';
    $('bgPayrollNote').textContent = current.payroll_without_line.length
      ? `Payroll for departments without a salaries line: ${current.payroll_without_line.map((p) => `${p.department} ${kes(p.amount)}`).join(', ')}. Add a salaries line for them in the next budget.`
      : (['active', 'approved', 'closed'].includes(b.status) ? 'Payroll (gross pay plus the employer\'s NSSF and housing levy) is charged to each department\'s salaries line from approved payslips.' : '');

    const live = ['approved', 'active'].includes(b.status);
    $('bgLines').innerHTML = current.lines.map((l) => `<tr>
        <td>${esc(l.department)}</td><td>${esc(l.category)}${l.description ? `<div class="small text-muted">${esc(l.description)}</div>` : ''}${l.payroll ? `<div class="small text-muted">includes ${kes(l.payroll)} payroll</div>` : ''}</td>
        <td class="num">${kes(l.allocated)}</td><td>${quota(l.allocated, l.spent, l.committed)}</td>
        <td class="num ${l.available < 0 ? 'text-danger fw-bold' : ''}">${kes(l.available)}</td>
        <td class="d-print-none text-end">${live ? `<button type="button" class="btn btn-sm btn-outline-secondary" data-more="${l.id}">Ask for more</button>` : ''}</td></tr>`).join('')
      || '<tr><td colspan="6" class="text-muted p-3">No lines yet.</td></tr>';

    $('bgReqs').innerHTML = current.requests.map((r) => `<tr>
        <td>${esc(r.line)}</td><td class="num">${kes(r.amount)}</td><td>${esc(r.reason || '')}</td>
        <td>${esc(r.requested_by || '')}<div class="small text-muted">${day(r.requested_at)}</div></td>
        <td>${tag(r.status === 'rejected' ? 'rejected' : r.status)}${r.decided_by ? `<div class="small text-muted">${esc(r.decided_by)}, ${day(r.decided_at)}</div>` : ''}${r.rejection_reason ? `<div class="small">${esc(r.rejection_reason)}</div>` : ''}</td>
        <td class="d-print-none text-end text-nowrap">${ws.can.decide && r.status === 'pending' ? `<button type="button" class="btn btn-sm btn-fin" data-req="${r.id}" data-dec="approve">Approve</button> <button type="button" class="btn btn-sm btn-outline-danger" data-req="${r.id}" data-dec="decline">Decline</button>` : ''}</td></tr>`).join('')
      || '<tr><td colspan="6" class="text-muted p-3">No requests for more funds.</td></tr>';
  }

  // ----------------------------------------------------------------- edit

  const opt = (list, val, label, blank) => (blank ? `<option value="">${esc(blank)}</option>` : '') + list.map((x) => `<option value="${x.id}" ${Number(x.id) === Number(val) ? 'selected' : ''}>${esc(label(x))}</option>`).join('');
  function lineRow(l = {}) {
    return `<tr data-id="${Number(l.id) || ''}">
      <td><select class="form-select form-select-sm" data-f="department_id" aria-label="Department">${opt(ws.departments, l.department_id, (d) => d.name, 'School-wide')}</select></td>
      <td><select class="form-select form-select-sm" data-f="category_id" aria-label="Category">${opt(ws.categories, l.category_id, (c) => c.name, 'Choose a category')}</select></td>
      <td><input class="form-control form-control-sm" type="number" min="0" step="0.01" data-f="allocated_amount" aria-label="Amount" value="${esc(l.allocated ?? l.allocated_amount ?? '')}"></td>
      <td><input class="form-control form-control-sm" data-f="description" maxlength="255" aria-label="What it covers" value="${esc(l.description || '')}"></td>
      <td><button type="button" class="btn btn-sm btn-outline-secondary" data-rm aria-label="Remove this line"><i class="bi bi-x-lg" aria-hidden="true"></i></button></td></tr>`;
  }
  function editTotal() {
    const sum = [...$('bgEditLines').querySelectorAll('[data-f="allocated_amount"]')].reduce((s, i) => s + Number(i.value || 0), 0);
    $('bgEditTotal').textContent = `Total ${kes(sum)}`;
  }
  function openEdit(b = null) {
    $('bgEditTitle').textContent = b ? `Edit ${b.budget.name}` : 'New budget';
    $('bgEditId').value = b ? b.budget.id : '';
    $('bgEditName').value = b ? b.budget.name : '';
    $('bgEditYear').innerHTML = opt(ws.years, b ? b.budget.academic_year_id : $('bgYear').value, (y) => y.year_code);
    $('bgEditTerm').value = b && b.budget.term ? String(b.budget.term) : '';
    $('bgEditDesc').value = b ? (b.budget.description || '') : '';
    const lines = b ? b.lines : [];
    $('bgEditLines').innerHTML = (lines.length ? lines : [{}, {}, {}]).map(lineRow).join('');
    editTotal();
    modal('bgEditModal').show();
  }
  async function saveEdit(e) {
    e.preventDefault();
    const lines = [...$('bgEditLines').querySelectorAll('tr')].map((tr) => {
      const o = { id: Number(tr.dataset.id) || null };
      tr.querySelectorAll('[data-f]').forEach((f) => { o[f.dataset.f] = f.value; });
      return o;
    }).filter((l) => l.category_id || Number(l.allocated_amount));
    try {
      const res = data(await api('/finance/budget-save', 'POST', { id: $('bgEditId').value || null, name: $('bgEditName').value.trim(), academic_year_id: $('bgEditYear').value, term: $('bgEditTerm').value, description: $('bgEditDesc').value.trim(), lines }));
      modal('bgEditModal').hide();
      notify('Budget saved as a draft.', 'success');
      await loadList();
      openBudget(res.id);
    } catch (err) { notify(err.message || 'The budget could not be saved.', 'danger'); }
  }

  // --------------------------------------------------------------- actions

  const NOTE = {
    review: ['Mark reviewed', 'Your review note (optional). The budget then goes for approval.', false],
    approve: ['Approve the budget', 'Approval note (optional). Approving does not yet make the allocations live: "Make active" does.', false],
    return: ['Return for changes', 'Say what needs changing. The preparer can edit and resubmit.', true],
    more: ['Ask for more funds', 'Say what the extra money is for. The School Administrator or Director decides.', true],
    decline: ['Decline the request', 'Say why the request is declined.', true],
  };
  function askNote(action, extra = {}) {
    noteAction = { action, ...extra };
    const [title, help, required] = NOTE[action];
    $('bgNoteTitle').textContent = title;
    $('bgNoteHelp').textContent = help;
    $('bgNoteLabel').textContent = required ? 'Reason (required)' : 'Note';
    $('bgNoteText').value = '';
    $('bgNoteAmountWrap').hidden = action !== 'more';
    $('bgNoteAmount').value = '';
    modal('bgNoteModal').show();
  }
  async function act(action, notes = null) {
    try {
      await api(`/finance/budget-action/${current.budget.id}`, 'POST', { action, notes });
      notify({ submit: 'Submitted for review.', review: 'Marked reviewed.', approve: 'Budget approved.', activate: 'Budget is now active. Department allocations are live.', return: 'Returned for changes.', close: 'Budget closed.' }[action] || 'Done.', 'success');
      await loadList();
      openBudget(current.budget.id);
    } catch (e) { notify(e.message || 'That could not be done.', 'danger'); }
  }
  async function noteSubmit(e) {
    e.preventDefault();
    const text = $('bgNoteText').value.trim();
    const { action } = noteAction;
    if (NOTE[action][2] && !text) { notify('Please give a reason.', 'warning'); $('bgNoteText').focus(); return; }
    try {
      if (action === 'more') {
        await api('/finance/budget-fund-request', 'POST', { line_item_id: noteAction.line, amount: $('bgNoteAmount').value, reason: text });
        notify('Request sent for a decision.', 'success');
      } else if (action === 'decline') {
        await api(`/finance/budget-fund-decision/${noteAction.req}`, 'POST', { decision: 'decline', reason: text });
        notify('Request declined.', 'success');
      } else {
        modal('bgNoteModal').hide();
        await act(action, text || null);
        return;
      }
      modal('bgNoteModal').hide();
      openBudget(current.budget.id);
    } catch (err) { notify(err.message || 'That could not be done.', 'danger'); }
  }

  // ---------------------------------------------------------------- export

  async function csv(name, head, rows) {
    const cell = (v) => { const t = String(v ?? ''); return /[",\r\n]/.test(t) ? `"${t.replace(/"/g, '""')}"` : t; };
    try { await window.KingswayFileLifecycle.exportText(`﻿${[head, ...rows].map((r) => r.map(cell).join(',')).join('\r\n')}`, name, 'text/csv;charset=utf-8'); }
    catch (e) { notify(e.message || 'Export failed', 'danger'); }
  }

  async function init() {
    await window.AuthContext?.ready?.();
    if (root.dataset.start === 'active') $('bgTitle').textContent = 'Budget vs actuals';
    $('bgYear').addEventListener('change', () => { $('bgDetail').hidden = true; loadList(); });
    $('bgNew').addEventListener('click', () => openEdit());
    $('bgListBody').addEventListener('click', (e) => { const b = e.target.closest('[data-open]'); if (b) openBudget(b.dataset.open); });
    $('bgActions').addEventListener('click', (e) => {
      const b = e.target.closest('[data-act]'); if (!b) return;
      const a = b.dataset.act;
      if (a === 'edit') openEdit(current);
      else if (a === 'print') window.print();
      else if (NOTE[a]) askNote(a);
      else act(a);
    });
    $('bgLines').addEventListener('click', (e) => { const b = e.target.closest('[data-more]'); if (b) askNote('more', { line: Number(b.dataset.more) }); });
    $('bgReqs').addEventListener('click', async (e) => {
      const b = e.target.closest('[data-req]'); if (!b) return;
      if (b.dataset.dec === 'decline') { askNote('decline', { req: Number(b.dataset.req) }); return; }
      try { await api(`/finance/budget-fund-decision/${b.dataset.req}`, 'POST', { decision: 'approve' }); notify('Approved. The amount is added to the line.', 'success'); openBudget(current.budget.id); }
      catch (err) { notify(err.message || 'That could not be done.', 'danger'); }
    });
    $('bgEditAdd').addEventListener('click', () => { $('bgEditLines').insertAdjacentHTML('beforeend', lineRow()); });
    $('bgEditLines').addEventListener('click', (e) => { const b = e.target.closest('[data-rm]'); if (b) { b.closest('tr').remove(); editTotal(); } });
    $('bgEditLines').addEventListener('input', editTotal);
    $('bgEditForm').addEventListener('submit', saveEdit);
    $('bgNoteForm').addEventListener('submit', noteSubmit);
    $('bgPrint').addEventListener('click', () => window.print());
    $('bgListCsv').addEventListener('click', () => csv('budgets.csv', ['Budget', 'Year', 'Term', 'Status', 'Allocated', 'Spent', 'Held', 'Prepared by', 'Approved by'],
      ws.budgets.map((b) => [b.name, b.academic_year, b.term || 'Whole year', STATUS[b.status] || b.status, b.allocated || b.total_amount, b.spent, b.committed, b.prepared_by, b.approved_by])));
    $('bgLinesCsv').addEventListener('click', () => current && csv(`budget_${current.budget.id}_lines.csv`, ['Department', 'Category', 'Allocated', 'Spent', 'Of which payroll', 'Held', 'Left', 'Used %'],
      current.lines.map((l) => [l.department, l.category, l.allocated, l.spent, l.payroll, l.committed, l.available, l.used_pct ?? ''])));
    if (window.AuthContext?.canExport?.() === false) { $('bgListCsv').hidden = true; $('bgLinesCsv').hidden = true; }
    window.APIRealtime?.register?.('budget_overview', { refresh: () => { loadList(); if (current) openBudget(current.budget.id); } }, ['finance']);
    await loadList();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
