<?php

namespace App\API\Services;

use PDO;
use RuntimeException;
use Throwable;

/**
 * The school budget, from preparation to department spending.
 *
 *   draft -> submitted -> under_review -> approved -> active -> closed
 *                  \____________\____________/
 *                         returned (rejected), editable again
 *
 * - Prepared by the Headteacher, School Administrator, Director or Accountant:
 *   one line per department and expense category (the department's quota).
 * - Reviewed (recommended) by the School Administrator or Director.
 * - Approved and activated by the School Administrator or Director. The
 *   Accountant prepares and reports; they never approve.
 * - Once active, every expense is charged to its department's line: pending
 *   expenses hold (commit) money, approved and paid ones are spent (kept by
 *   the expense triggers), and payroll is charged to each department's staff
 *   lines from its payslips. A department asks for more money on a line and
 *   the School Administrator or Director decides.
 *
 * Reads are single-table keyed reads on the primary, joined in PHP, so a
 * decision shows at once.
 */
final class BudgetService
{
    public const PREPARE_ROLES = [3, 4, 5, 10];
    public const DECIDE_ROLES = [3, 4];
    public const VIEW_ROLES = [3, 4, 5, 10];
    private const EDITABLE = ['draft', 'rejected'];
    private const STAFF_CODES = ['SALARY', 'ALLOWANCE', 'STATUTORY'];

    /** @var array<int,string> */
    private array $names = [];

    public function __construct(private PDO $db) {}

    public static function can(string $what, array $roleIds): bool
    {
        $roles = array_map('intval', $roleIds);
        $allowed = ['prepare' => self::PREPARE_ROLES, 'decide' => self::DECIDE_ROLES, 'view' => self::VIEW_ROLES][$what] ?? [];
        return (bool) array_intersect($roles, $allowed);
    }

    // ------------------------------------------------------------------ reads

    public function lookups(): array
    {
        return [
            'departments' => $this->db->query("SELECT id, name, code FROM departments WHERE status = 'active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC),
            'categories' => $this->db->query("SELECT id, code, name, type FROM expense_categories WHERE status = 'active' ORDER BY type, name")->fetchAll(PDO::FETCH_ASSOC),
            'years' => $this->db->query('SELECT id, year_code, start_date, end_date, is_current FROM academic_years ORDER BY start_date DESC')->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    /** Every budget, newest first, with its totals. */
    public function list(?int $yearId = null): array
    {
        $sql = 'SELECT id, name, academic_year, academic_year_id, term, total_amount, status, created_by, submitted_by, submitted_at,
                       reviewed_by, reviewed_at, approved_by, approved_at, activated_at, closed_at, created_at, description
                  FROM budgets';
        $params = [];
        if ($yearId) { $sql .= ' WHERE academic_year_id = ?'; $params[] = $yearId; }
        $stmt = $this->db->prepare($sql . ' ORDER BY created_at DESC, id DESC LIMIT 200');
        $stmt->execute($params);
        $budgets = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$budgets) return [];

        $ids = implode(',', array_map(static fn ($b) => (int) $b['id'], $budgets));
        $totals = [];
        foreach ($this->db->query("SELECT budget_id, allocated_amount, spent_amount, committed_amount FROM budget_line_items WHERE budget_id IN ($ids)") as $l) {
            $t = &$totals[(int) $l['budget_id']];
            $t['allocated'] = ($t['allocated'] ?? 0) + (float) $l['allocated_amount'];
            $t['spent'] = ($t['spent'] ?? 0) + (float) $l['spent_amount'];
            $t['committed'] = ($t['committed'] ?? 0) + (float) $l['committed_amount'];
            $t['lines'] = ($t['lines'] ?? 0) + 1;
            unset($t);
        }
        $this->prefetchNames(array_merge(array_column($budgets, 'created_by'), array_column($budgets, 'approved_by')));
        return array_map(function ($b) use ($totals) {
            $t = $totals[(int) $b['id']] ?? [];
            return [
                'id' => (int) $b['id'], 'name' => $b['name'], 'academic_year' => $b['academic_year'],
                'academic_year_id' => $b['academic_year_id'] ? (int) $b['academic_year_id'] : null,
                'term' => $b['term'] !== null ? (int) $b['term'] : null, 'status' => $b['status'],
                'total_amount' => (float) $b['total_amount'],
                'allocated' => round($t['allocated'] ?? 0, 2), 'spent' => round($t['spent'] ?? 0, 2), 'committed' => round($t['committed'] ?? 0, 2),
                'lines' => (int) ($t['lines'] ?? 0),
                'prepared_by' => $this->nameOf((int) $b['created_by']), 'approved_by' => $this->nameOf((int) $b['approved_by']),
                'created_at' => $b['created_at'], 'approved_at' => $b['approved_at'],
                'legacy_department_allocation' => str_starts_with((string) $b['description'], '[dept_id:'),
            ];
        }, $budgets);
    }

    /**
     * One budget with its lines, each line's spending (expenses plus payroll on
     * staff lines) and a department summary, plus the requests for more funds.
     */
    public function get(int $id): array
    {
        $stmt = $this->db->prepare('SELECT * FROM budgets WHERE id = ?');
        $stmt->execute([$id]);
        $b = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$b) throw new RuntimeException('Budget not found.', 404);

        $lk = $this->lookups();
        $dept = array_column($lk['departments'], 'name', 'id');
        foreach ($this->db->query('SELECT id, name FROM departments') as $d) $dept[(int) $d['id']] = $d['name'];
        $cats = [];
        foreach ($this->db->query('SELECT id, code, name, type FROM expense_categories') as $c) $cats[(int) $c['id']] = $c;

        $lines = $this->db->prepare('SELECT id, department_id, category_id, description, allocated_amount, spent_amount, committed_amount, notes
                                       FROM budget_line_items WHERE budget_id = ? ORDER BY department_id, category_id, id');
        $lines->execute([$id]);
        $lines = $lines->fetchAll(PDO::FETCH_ASSOC);

        $payroll = in_array($b['status'], ['active', 'closed', 'approved'], true) ? $this->payrollByDepartment($b) : ['by_department' => [], 'total' => 0.0];
        $payrollPlaced = [];
        $out = [];
        foreach ($lines as $l) {
            $d = $l['department_id'] !== null ? (int) $l['department_id'] : 0;
            $cat = $cats[(int) $l['category_id']] ?? ['code' => '', 'name' => 'Category', 'type' => 'other'];
            $payrollCost = 0.0;
            // Payroll is charged to the department's salaries line (or first staff line).
            if (in_array($cat['code'], self::STAFF_CODES, true) && !isset($payrollPlaced[$d]) && isset($payroll['by_department'][$d])) {
                $payrollCost = $payroll['by_department'][$d];
                $payrollPlaced[$d] = true;
            }
            $allocated = (float) $l['allocated_amount'];
            $spent = round((float) $l['spent_amount'] + $payrollCost, 2);
            $committed = (float) $l['committed_amount'];
            $out[] = [
                'id' => (int) $l['id'], 'department_id' => $d ?: null, 'department' => $d ? ($dept[$d] ?? 'Department') : 'School-wide',
                'category_id' => (int) $l['category_id'], 'category' => $cat['name'], 'category_type' => $cat['type'],
                'description' => $l['description'], 'notes' => $l['notes'],
                'allocated' => $allocated, 'spent' => $spent, 'payroll' => round($payrollCost, 2), 'committed' => $committed,
                'available' => round($allocated - $spent - $committed, 2),
                'used_pct' => $allocated > 0 ? round(($spent + $committed) * 100 / $allocated, 1) : null,
            ];
        }
        // Payroll for departments with no staff line still counts, shown on its own.
        $unplaced = [];
        foreach ($payroll['by_department'] as $d => $amount) {
            if (!isset($payrollPlaced[$d]) && $amount > 0) $unplaced[] = ['department_id' => $d ?: null, 'department' => $d ? ($dept[$d] ?? 'Department') : 'No department', 'amount' => round($amount, 2)];
        }

        $byDept = [];
        foreach ($out as $l) {
            $k = $l['department'];
            $x = &$byDept[$k];
            $x['department'] = $k; $x['department_id'] = $l['department_id'];
            foreach (['allocated', 'spent', 'committed', 'available'] as $f) $x[$f] = round(($x[$f] ?? 0) + $l[$f], 2);
            $x['lines'] = ($x['lines'] ?? 0) + 1;
            unset($x);
        }
        foreach ($byDept as &$x) $x['used_pct'] = $x['allocated'] > 0 ? round(($x['spent'] + $x['committed']) * 100 / $x['allocated'], 1) : null;
        unset($x);
        usort($byDept, static fn ($a, $c) => $c['allocated'] <=> $a['allocated']);

        $req = $this->db->prepare('SELECT * FROM budget_amendments WHERE budget_id = ? ORDER BY created_at DESC');
        $req->execute([$id]);
        $requests = $req->fetchAll(PDO::FETCH_ASSOC);
        $lineById = array_column($out, null, 'id');
        $this->prefetchNames(array_merge([$b['created_by'], $b['submitted_by'], $b['reviewed_by'], $b['approved_by']],
            array_column($requests, 'requested_by'), array_column($requests, 'approved_by')));

        return [
            'budget' => [
                'id' => (int) $b['id'], 'name' => $b['name'], 'academic_year' => $b['academic_year'],
                'academic_year_id' => $b['academic_year_id'] ? (int) $b['academic_year_id'] : null,
                'term' => $b['term'] !== null ? (int) $b['term'] : null, 'status' => $b['status'],
                'description' => $b['description'], 'total_amount' => (float) $b['total_amount'],
                'review_notes' => $b['review_notes'], 'approval_notes' => $b['approval_notes'],
                'trail' => array_values(array_filter([
                    ['Prepared', $this->nameOf((int) $b['created_by']), $b['created_at']],
                    $b['submitted_at'] ? ['Submitted', $this->nameOf((int) $b['submitted_by']), $b['submitted_at']] : null,
                    $b['reviewed_at'] ? [$b['status'] === 'rejected' && !$b['approved_at'] ? 'Returned' : 'Reviewed', $this->nameOf((int) $b['reviewed_by']), $b['reviewed_at']] : null,
                    $b['approved_at'] ? ['Approved', $this->nameOf((int) $b['approved_by']), $b['approved_at']] : null,
                    $b['activated_at'] ? ['Made active', null, $b['activated_at']] : null,
                    $b['closed_at'] ? ['Closed', null, $b['closed_at']] : null,
                ])),
            ],
            'lines' => $out,
            'departments' => array_values($byDept),
            'payroll_without_line' => $unplaced,
            'payroll_total' => round($payroll['total'], 2),
            'totals' => [
                'allocated' => round(array_sum(array_column($out, 'allocated')), 2),
                'spent' => round(array_sum(array_column($out, 'spent')) + array_sum(array_column($unplaced, 'amount')), 2),
                'committed' => round(array_sum(array_column($out, 'committed')), 2),
            ],
            'requests' => array_map(fn ($r) => [
                'id' => (int) $r['id'], 'line_item_id' => $r['line_item_id'] ? (int) $r['line_item_id'] : null,
                'line' => $r['line_item_id'] && isset($lineById[(int) $r['line_item_id']]) ? $lineById[(int) $r['line_item_id']]['department'] . ', ' . $lineById[(int) $r['line_item_id']]['category'] : 'Whole budget',
                'type' => $r['amendment_type'], 'amount' => (float) $r['amount_change'], 'reason' => $r['reason'], 'status' => $r['status'],
                'requested_by' => $this->nameOf((int) $r['requested_by']), 'requested_at' => $r['created_at'],
                'decided_by' => $this->nameOf((int) $r['approved_by']), 'decided_at' => $r['approved_at'], 'rejection_reason' => $r['rejection_reason'],
            ], $requests),
        ];
    }

    /**
     * Payroll cost (gross pay plus the employer's NSSF and housing levy) of
     * approved or paid payslips inside the budget's period, by the staff
     * member's department.
     */
    private function payrollByDepartment(array $budget): array
    {
        [$from, $to] = $this->period($budget);
        if (!$from) return ['by_department' => [], 'total' => 0.0];
        $months = [];
        for ($m = new \DateTime(substr($from, 0, 7) . '-01'); $m->format('Y-m') <= substr($to, 0, 7); $m->modify('+1 month')) {
            $months[] = [(int) $m->format('Y'), (int) $m->format('n')];
        }
        if (!$months) return ['by_department' => [], 'total' => 0.0];
        $years = implode(',', array_unique(array_column($months, 0)));
        $wanted = array_flip(array_map(static fn ($p) => $p[0] . '-' . $p[1], $months));
        $rows = $this->db->query("SELECT staff_id, payroll_year, payroll_month, gross_salary, employer_nssf_contribution, employer_housing_levy
                                    FROM payslips WHERE data_scope = 'live' AND payslip_status IN ('approved','paid') AND payroll_year IN ($years)")->fetchAll(PDO::FETCH_ASSOC);
        $dept = [];
        foreach ($this->db->query('SELECT staff_id, department_id FROM staff_department_assignments WHERE effective_to IS NULL OR effective_to >= CURDATE() ORDER BY id') as $a) {
            $dept[(int) $a['staff_id']] = (int) $a['department_id'];
        }
        $by = [];
        $total = 0.0;
        foreach ($rows as $r) {
            if (!isset($wanted[$r['payroll_year'] . '-' . $r['payroll_month']])) continue;
            $cost = (float) $r['gross_salary'] + (float) $r['employer_nssf_contribution'] + (float) $r['employer_housing_levy'];
            $d = $dept[(int) $r['staff_id']] ?? 0;
            $by[$d] = ($by[$d] ?? 0) + $cost;
            $total += $cost;
        }
        return ['by_department' => $by, 'total' => $total];
    }

    /** The budget's dates: its term's, or the academic year's. */
    private function period(array $b): array
    {
        if (!$b['academic_year_id']) return [null, null];
        if ($b['term'] !== null) {
            $t = $this->db->prepare('SELECT opening_date, closing_date FROM academic_year_terms WHERE academic_year_id = ? ORDER BY opening_date, id');
            $t->execute([(int) $b['academic_year_id']]);
            $terms = $t->fetchAll(PDO::FETCH_ASSOC);
            $term = $terms[(int) $b['term'] - 1] ?? null;
            if ($term) return [$term['opening_date'], $term['closing_date']];
        }
        $y = $this->db->prepare('SELECT start_date, end_date FROM academic_years WHERE id = ?');
        $y->execute([(int) $b['academic_year_id']]);
        $r = $y->fetch(PDO::FETCH_ASSOC);
        return $r ? [$r['start_date'], $r['end_date']] : [null, null];
    }

    // ----------------------------------------------------------------- writes

    /** Create or edit a budget while it is a draft or returned: name, period and lines. */
    public function save(array $d, int $userId): array
    {
        $id = (int) ($d['id'] ?? 0);
        $name = trim((string) ($d['name'] ?? ''));
        if ($name === '') throw new RuntimeException('Give the budget a name.');
        $yearId = (int) ($d['academic_year_id'] ?? 0);
        $y = $this->db->prepare('SELECT id, year_code FROM academic_years WHERE id = ?');
        $y->execute([$yearId]);
        $year = $y->fetch(PDO::FETCH_ASSOC);
        if (!$year) throw new RuntimeException('Choose the academic year.');
        $term = isset($d['term']) && $d['term'] !== '' && $d['term'] !== null ? (int) $d['term'] : null;
        if ($term !== null && ($term < 1 || $term > 3)) throw new RuntimeException('Choose Term 1, 2 or 3, or the whole year.');

        $deptIds = array_map('intval', array_column($this->lookups()['departments'], 'id'));
        $catIds = array_map('intval', $this->db->query("SELECT id FROM expense_categories WHERE status = 'active'")->fetchAll(PDO::FETCH_COLUMN));
        $lines = [];
        $seen = [];
        foreach ((array) ($d['lines'] ?? []) as $l) {
            $dep = (int) ($l['department_id'] ?? 0) ?: null;
            $cat = (int) ($l['category_id'] ?? 0);
            $amt = round((float) ($l['allocated_amount'] ?? 0), 2);
            if (!$cat && !$amt) continue;
            if (!in_array($cat, $catIds, true)) throw new RuntimeException('Each line needs an expense category.');
            if ($dep !== null && !in_array($dep, $deptIds, true)) throw new RuntimeException('Choose a department from the list.');
            if ($amt < 0) throw new RuntimeException('An allocation cannot be negative.');
            $key = ($dep ?? 0) . '-' . $cat;
            if (isset($seen[$key])) throw new RuntimeException('Each department can have only one line per category. Combine them.');
            $seen[$key] = true;
            $lines[] = ['id' => (int) ($l['id'] ?? 0), 'department_id' => $dep, 'category_id' => $cat, 'amount' => $amt,
                        'description' => mb_substr(trim((string) ($l['description'] ?? '')), 0, 255) ?: null];
        }
        if (!$lines) throw new RuntimeException('Add at least one line: a department, a category and an amount.');
        $total = round(array_sum(array_column($lines, 'amount')), 2);
        $description = mb_substr(trim((string) ($d['description'] ?? '')), 0, 2000) ?: null;

        return $this->atomically(function () use ($id, $name, $year, $term, $lines, $total, $description, $userId) {
            if ($id) {
                $b = $this->lock($id);
                if (!in_array($b['status'], self::EDITABLE, true)) throw new RuntimeException('Only a draft or a returned budget can be changed. Ask for more funds on an approved budget instead.', 409);
                $this->db->prepare('UPDATE budgets SET name = ?, academic_year = ?, academic_year_id = ?, term = ?, total_amount = ?, description = ? WHERE id = ?')
                    ->execute([$name, (int) substr($year['year_code'], 0, 4), (int) $year['id'], $term, $total, $description, $id]);
            } else {
                $this->db->prepare("INSERT INTO budgets (name, academic_year, academic_year_id, term, total_amount, description, status, created_by)
                                    VALUES (?, ?, ?, ?, ?, ?, 'draft', ?)")
                    ->execute([$name, (int) substr($year['year_code'], 0, 4), (int) $year['id'], $term, $total, $description, $userId]);
                $id = (int) $this->db->lastInsertId();
            }
            $keep = [];
            $exist = $this->db->prepare('SELECT id FROM budget_line_items WHERE budget_id = ?');
            $exist->execute([$id]);
            $existing = array_map('intval', $exist->fetchAll(PDO::FETCH_COLUMN));
            foreach ($lines as $l) {
                if ($l['id'] && in_array($l['id'], $existing, true)) {
                    $this->db->prepare('UPDATE budget_line_items SET department_id = ?, category_id = ?, description = ?, allocated_amount = ? WHERE id = ? AND budget_id = ?')
                        ->execute([$l['department_id'], $l['category_id'], $l['description'], $l['amount'], $l['id'], $id]);
                    $keep[] = $l['id'];
                } else {
                    $this->db->prepare('INSERT INTO budget_line_items (budget_id, department_id, category_id, description, allocated_amount) VALUES (?, ?, ?, ?, ?)')
                        ->execute([$id, $l['department_id'], $l['category_id'], $l['description'], $l['amount']]);
                    $keep[] = (int) $this->db->lastInsertId();
                }
            }
            foreach (array_diff($existing, $keep) as $gone) {
                $used = $this->db->prepare('SELECT COUNT(*) FROM expenses WHERE budget_line_item_id = ?');
                $used->execute([$gone]);
                if ((int) $used->fetchColumn() > 0) throw new RuntimeException('A line that already has expenses cannot be removed. Set it to the amount you need instead.', 409);
                $this->db->prepare('DELETE FROM budget_line_items WHERE id = ?')->execute([$gone]);
            }
            return ['id' => $id];
        });
    }

    /**
     * Move a budget along: submit, review, approve, activate, return, close.
     * Who may do what is checked here, not in the page.
     */
    public function act(int $id, string $action, ?string $notes, int $userId, array $roleIds): array
    {
        $notes = $notes !== null ? (mb_substr(trim($notes), 0, 2000) ?: null) : null;
        $rules = [
            'submit' => [self::EDITABLE, 'submitted', 'prepare'],
            'review' => [['submitted'], 'under_review', 'decide'],
            'approve' => [['submitted', 'under_review'], 'approved', 'decide'],
            'activate' => [['approved'], 'active', 'decide'],
            'return' => [['submitted', 'under_review', 'approved'], 'rejected', 'decide'],
            'close' => [['active'], 'closed', 'decide'],
        ];
        if (!isset($rules[$action])) throw new RuntimeException('Unknown budget action.');
        [$from, $to, $who] = $rules[$action];
        if (!self::can($who, $roleIds)) {
            throw new RuntimeException($who === 'decide'
                ? 'Only the School Administrator or the Director can ' . $action . ' a budget.'
                : 'You cannot submit budgets.', 403);
        }
        if ($action === 'return' && !$notes) throw new RuntimeException('Say why the budget is being returned, so it can be corrected.');

        return $this->atomically(function () use ($id, $action, $from, $to, $notes, $userId) {
            $b = $this->lock($id);
            if (!in_array($b['status'], $from, true)) {
                throw new RuntimeException('This budget is ' . str_replace('_', ' ', $b['status']) . ', so it cannot be ' . ['submit' => 'submitted', 'review' => 'reviewed', 'approve' => 'approved', 'activate' => 'made active', 'return' => 'returned', 'close' => 'closed'][$action] . ' now.', 409);
            }
            if ($action === 'submit') {
                $n = $this->db->prepare('SELECT COUNT(*) FROM budget_line_items WHERE budget_id = ?');
                $n->execute([$id]);
                if (!(int) $n->fetchColumn()) throw new RuntimeException('Add the department lines before submitting.');
            }
            $set = ['status = ?'];
            $params = [$to];
            if ($action === 'submit') { $set[] = 'submitted_by = ?'; $set[] = 'submitted_at = NOW()'; $params[] = $userId; }
            if (in_array($action, ['review', 'return'], true)) { $set[] = 'reviewed_by = ?'; $set[] = 'reviewed_at = NOW()'; $set[] = 'review_notes = COALESCE(?, review_notes)'; array_push($params, $userId, $notes); }
            if ($action === 'approve') { $set[] = 'approved_by = ?'; $set[] = 'approved_at = NOW()'; $set[] = 'approval_notes = COALESCE(?, approval_notes)'; array_push($params, $userId, $notes); }
            if ($action === 'activate') {
                $set[] = 'activated_at = NOW()';
                // One active budget per year and period: the previous one closes.
                $this->db->prepare("UPDATE budgets SET status = 'closed', closed_at = NOW() WHERE status = 'active' AND id <> ?
                                     AND academic_year_id <=> ? AND term <=> ?")
                    ->execute([$id, $b['academic_year_id'], $b['term']]);
            }
            if ($action === 'close') $set[] = 'closed_at = NOW()';
            $params[] = $id;
            $this->db->prepare('UPDATE budgets SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($params);
            \App\API\Includes\FileLogger::write('finance', ['type' => 'budget_' . $action, 'budget_id' => $id, 'user_id' => $userId, 'from' => $b['status'], 'to' => $to]);
            return ['id' => $id, 'status' => $to];
        });
    }

    /** A department asks for more money on one line of an active budget. */
    public function requestFunds(int $lineId, float $amount, string $reason, int $userId): array
    {
        $amount = round($amount, 2);
        if ($amount <= 0) throw new RuntimeException('Enter how much more is needed.');
        $reason = trim($reason);
        if ($reason === '') throw new RuntimeException('Say what the extra money is for.');
        $l = $this->db->prepare('SELECT id, budget_id FROM budget_line_items WHERE id = ?');
        $l->execute([$lineId]);
        $line = $l->fetch(PDO::FETCH_ASSOC);
        if (!$line) throw new RuntimeException('Budget line not found.', 404);
        $b = $this->db->prepare('SELECT status FROM budgets WHERE id = ?');
        $b->execute([(int) $line['budget_id']]);
        if (!in_array($b->fetchColumn(), ['approved', 'active'], true)) throw new RuntimeException('More funds can be requested only on an approved or active budget. Change the draft instead.', 409);
        $this->db->prepare("INSERT INTO budget_amendments (budget_id, line_item_id, amendment_type, amount_change, reason, status, requested_by)
                            VALUES (?, ?, 'supplementary', ?, ?, 'pending', ?)")
            ->execute([(int) $line['budget_id'], $lineId, $amount, mb_substr($reason, 0, 2000), $userId]);
        return ['id' => (int) $this->db->lastInsertId()];
    }

    /** The School Administrator or Director approves (adds to the line) or declines a request. */
    public function decideFunds(int $requestId, bool $approve, ?string $reason, int $userId, array $roleIds): array
    {
        if (!self::can('decide', $roleIds)) throw new RuntimeException('Only the School Administrator or the Director decides on requests for more funds.', 403);
        if (!$approve && !trim((string) $reason)) throw new RuntimeException('Say why the request is declined.');
        return $this->atomically(function () use ($requestId, $approve, $reason, $userId) {
            $r = $this->db->prepare("SELECT * FROM budget_amendments WHERE id = ? FOR UPDATE");
            $r->execute([$requestId]);
            $req = $r->fetch(PDO::FETCH_ASSOC);
            if (!$req || $req['status'] !== 'pending') throw new RuntimeException('This request has already been decided.', 409);
            $this->db->prepare('UPDATE budget_amendments SET status = ?, approved_by = ?, approved_at = NOW(), rejection_reason = ? WHERE id = ?')
                ->execute([$approve ? 'approved' : 'rejected', $userId, $approve ? null : mb_substr(trim((string) $reason), 0, 2000), $requestId]);
            if ($approve) {
                $amount = (float) $req['amount_change'];
                if ($req['line_item_id']) {
                    $this->db->prepare('UPDATE budget_line_items SET allocated_amount = allocated_amount + ? WHERE id = ?')->execute([$amount, (int) $req['line_item_id']]);
                }
                $this->db->prepare('UPDATE budgets SET total_amount = total_amount + ? WHERE id = ?')->execute([$amount, (int) $req['budget_id']]);
            }
            return ['id' => $requestId, 'status' => $approve ? 'approved' : 'rejected'];
        });
    }

    /**
     * The active budget line an expense belongs to (its department and
     * category), and what is left on it. $excludeExpenseId leaves the expense
     * being approved out of the committed figure.
     */
    public function lineFor(?int $departmentId, ?int $categoryId, ?string $date = null): ?array
    {
        if (!$categoryId) return null;
        $date = $date ?: date('Y-m-d');
        $budgets = $this->db->query("SELECT id, academic_year_id, term FROM budgets WHERE status = 'active' ORDER BY term IS NULL, id DESC")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($budgets as $b) {
            [$from, $to] = $this->period($b);
            if ($from && ($date < $from || $date > $to)) continue;
            $q = $this->db->prepare('SELECT id, department_id, allocated_amount, spent_amount, committed_amount FROM budget_line_items
                                      WHERE budget_id = ? AND category_id = ? AND department_id <=> ? LIMIT 1');
            $q->execute([(int) $b['id'], $categoryId, $departmentId ?: null]);
            $line = $q->fetch(PDO::FETCH_ASSOC);
            if (!$line && $departmentId) {
                // A school-wide line for the category serves departments without their own.
                $q->execute([(int) $b['id'], $categoryId, null]);
                $line = $q->fetch(PDO::FETCH_ASSOC);
            }
            if ($line) {
                return ['budget_id' => (int) $b['id'], 'line_id' => (int) $line['id'], 'allocated' => (float) $line['allocated_amount'],
                        'spent' => (float) $line['spent_amount'], 'committed' => (float) $line['committed_amount'],
                        'available' => round((float) $line['allocated_amount'] - (float) $line['spent_amount'] - (float) $line['committed_amount'], 2)];
            }
        }
        return null;
    }

    // ---------------------------------------------------------------- helpers

    private function lock(int $id): array
    {
        $s = $this->db->prepare('SELECT * FROM budgets WHERE id = ? FOR UPDATE');
        $s->execute([$id]);
        $b = $s->fetch(PDO::FETCH_ASSOC);
        if (!$b) throw new RuntimeException('Budget not found.', 404);
        return $b;
    }

    private function atomically(callable $fn): array
    {
        if ($this->db->inTransaction()) return $fn();
        $this->db->beginTransaction();
        try { $r = $fn(); $this->db->commit(); return $r; }
        catch (Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
    }

    private function prefetchNames(array $ids): void
    {
        $ids = array_values(array_diff(array_unique(array_filter(array_map('intval', $ids))), array_keys($this->names)));
        if (!$ids) return;
        $users = $this->db->query('SELECT id, person_id FROM users WHERE id IN (' . implode(',', $ids) . ')')->fetchAll(PDO::FETCH_ASSOC);
        $pids = array_values(array_unique(array_filter(array_map(static fn ($u) => (int) $u['person_id'], $users))));
        $p = [];
        if ($pids) foreach ($this->db->query('SELECT id, first_name, last_name FROM persons WHERE id IN (' . implode(',', $pids) . ')') as $r) $p[(int) $r['id']] = trim($r['first_name'] . ' ' . $r['last_name']);
        foreach ($ids as $id) $this->names[$id] = '';
        foreach ($users as $u) $this->names[(int) $u['id']] = $p[(int) $u['person_id']] ?? '';
    }

    private function nameOf(int $id): ?string
    {
        if (!$id) return null;
        if (!array_key_exists($id, $this->names)) $this->prefetchNames([$id]);
        return $this->names[$id] !== '' ? $this->names[$id] : null;
    }
}
