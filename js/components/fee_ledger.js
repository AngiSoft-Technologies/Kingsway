/**
 * The learner's account statement (FeeStatementService ledger) as an HTML
 * table: every entry that moved the balance (balance or overpayment brought
 * forward, fees, waivers, scholarships and sponsorships, admission relief,
 * credit notes, fees paid before joining, payments), dated, with who recorded
 * or approved it and a running balance. Used by the parent portal and the
 * family page so parents see the same entries the accounts office prints.
 */
(function () {
  'use strict';
  if (window.FeeLedger) return;
  var esc = function (v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var kes = function (v) { return 'KES ' + Number(v || 0).toLocaleString('en-KE', { minimumFractionDigits: 0, maximumFractionDigits: 2 }); };
  var bal = function (v) { v = Number(v || 0); return v < 0 ? kes(-v) + ' in credit' : kes(v); };
  var day = function (d) { var x = new Date(String(d || '').slice(0, 10) + 'T00:00:00'); return isNaN(x) ? esc(d) : x.toLocaleDateString('en-KE', { day: 'numeric', month: 'short', year: 'numeric' }); };

  function html(ledger, opts) {
    opts = opts || {};
    if (!ledger || !ledger.entries) return '';
    var rows = ledger.entries.map(function (e) {
      return '<tr><td style="white-space:nowrap">' + day(e.date) + '</td>' +
        '<td><strong>' + esc(e.description) + '</strong>' + (e.term ? ' <span style="color:#64748b">(' + esc(e.term) + ')</span>' : '') +
        (e.detail ? '<div style="color:#64748b;font-size:.85em">' + esc(e.detail) + '</div>' : '') + '</td>' +
        '<td>' + esc(e.by || '') + '</td>' +
        '<td style="text-align:right">' + (Number(e.debit) ? kes(e.debit) : '') + '</td>' +
        '<td style="text-align:right">' + (Number(e.credit) ? kes(e.credit) : '') + '</td>' +
        '<td style="text-align:right;font-weight:600">' + bal(e.balance) + '</td></tr>';
    }).join('') || '<tr><td colspan="6" style="color:#64748b">Nothing was billed or received for this year.</td></tr>';
    var warn = ledger.matches_recorded_balance === false
      ? '<div class="alert alert-warning small py-2 mb-2">These entries do not add up to the recorded balance. Please contact the accounts office.</div>' : '';
    return (opts.title ? '<h6 class="mt-3 mb-2">' + esc(opts.title) + '</h6>' : '') + warn +
      '<div class="table-responsive"><table class="' + esc(opts.tableClass || 'table table-sm table-bordered align-middle') + '" style="width:100%;border-collapse:collapse">' +
      '<thead><tr><th>Date</th><th>Details</th><th>Recorded or approved by</th><th style="text-align:right">Charged</th><th style="text-align:right">Credited</th><th style="text-align:right">Balance</th></tr></thead>' +
      '<tbody>' + rows + '</tbody>' +
      '<tfoot><tr style="font-weight:700"><td colspan="3">Closing balance</td><td style="text-align:right">' + kes(ledger.total_debit) + '</td><td style="text-align:right">' + kes(ledger.total_credit) + '</td><td style="text-align:right">' + bal(ledger.closing_balance) + '</td></tr></tfoot>' +
      '</table></div>';
  }

  window.FeeLedger = { html: html };
})();
