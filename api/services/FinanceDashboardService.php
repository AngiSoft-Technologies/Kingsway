<?php

namespace App\API\Services;

use PDO;

/**
 * The school's financial dashboard for one academic year, for the Director,
 * the School Administrator and the Accountant. Modelled on the standard
 * finance dashboards (profit and loss, cash flow, receivables, payables,
 * budget vs actual) and adapted to a school:
 *
 *   income vs expenses   fees and other money received against expenses and
 *                        payroll, month by month, with the surplus or deficit
 *   cash flow            money in, money out and the running net
 *   receivables          fees billed, collected and outstanding; arrears by
 *                        age and by class; the collection rate
 *   payables             approved expenses and payroll not yet paid
 *   budget vs actual     each department's allocation against its spending
 *   decisions waiting    what is waiting for the School Administrator or
 *                        Director (expenses, budgets, fund requests, moves,
 *                        refunds, unmatched payments)
 *
 * Aggregates read the KingsWayReads projections; small keyed lookups read
 * the primary.
 */
final class FinanceDashboardService
{
    public const VIEW_ROLES = [3, 4, 5, 10];

    public function __construct(private PDO $db) {}

    public static function canView(array $roleIds): bool
    {
        return (bool) array_intersect(array_map('intval', $roleIds), self::VIEW_ROLES);
    }

    public function build(?int $yearId): array
    {
        $year = $this->year($yearId);
        if (!$year) return ['year' => null];
        $from = substr((string) $year['start_date'], 0, 10);
        $to = substr((string) $year['end_date'], 0, 10);
        $months = [];
        for ($m = new \DateTime(substr($from, 0, 7) . '-01'); $m->format('Y-m') <= substr($to, 0, 7); $m->modify('+1 month')) $months[] = $m->format('Y-m');

        $income = $this->income($from, $to);
        $expenses = $this->expenses($from, $to);
        $payroll = $this->payroll($months);
        $series = [];
        $running = 0.0;
        foreach ($months as $m) {
            $in = round($income['by_month'][$m] ?? 0, 2);
            $exp = round($expenses['by_month'][$m] ?? 0, 2);
            $pay = round($payroll['by_month'][$m] ?? 0, 2);
            $net = round($in - $exp - $pay, 2);
            $running = round($running + $net, 2);
            $series[] = ['month' => $m, 'income' => $in, 'expenses' => $exp, 'payroll' => $pay, 'net' => $net, 'running' => $running];
        }
        $totalIn = round(array_sum(array_column($series, 'income')), 2);
        $totalOut = round(array_sum(array_column($series, 'expenses')) + array_sum(array_column($series, 'payroll')), 2);

        return [
            'year' => ['id' => (int) $year['id'], 'code' => $year['year_code'], 'from' => $from, 'to' => $to],
            'kpis' => [
                'income' => $totalIn,
                'spending' => $totalOut,
                'surplus' => round($totalIn - $totalOut, 2),
                'margin_pct' => $totalIn > 0 ? round(($totalIn - $totalOut) * 100 / $totalIn, 1) : null,
            ],
            'months' => $series,
            'income_by_purpose' => $income['by_purpose'],
            'channels' => $income['by_channel'],
            'expense_categories' => $expenses['by_category'],
            'expenses_pending' => $this->pendingExpenses($from, $to),
            'payroll_total' => round($payroll['total'], 2),
            'receivables' => $this->receivables((int) $year['id']),
            'recorded_at_go_live' => $this->goLive((int) $year['id']),
            'payables' => $this->payables(),
            'budget' => $this->budget((int) $year['id']),
            'waiting' => $this->waiting(),
            'generated_at' => date('Y-m-d H:i:s'),
        ];
    }

    private function year(?int $id): ?array
    {
        $ref = ReadReplicaService::qualifiedRef('academic_years');
        $stmt = $id
            ? $this->db->prepare("SELECT id, year_code, start_date, end_date FROM {$ref} WHERE id = ?")
            : $this->db->prepare("SELECT id, year_code, start_date, end_date FROM {$ref} WHERE is_current = 1 ORDER BY start_date DESC LIMIT 1");
        $stmt->execute($id ? [$id] : []);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Confirmed money received: by month, purpose and channel (moves between learners net out). */
    private function income(string $from, string $to): array
    {
        $stmt = $this->db->prepare(
            "SELECT DATE_FORMAT(payment_date, '%Y-%m') AS m, COALESCE(NULLIF(payment_purpose, ''), 'fees') AS purpose, method, SUM(amount) AS total
               FROM " . ReadReplicaService::qualifiedRef('payments') . "
              WHERE status = 'confirmed' AND payment_date BETWEEN ? AND ?
              GROUP BY m, purpose, method"
        );
        $stmt->execute([$from, $to . ' 23:59:59']);
        $byMonth = $byPurpose = $byChannel = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $t = (float) $r['total'];
            $byMonth[$r['m']] = ($byMonth[$r['m']] ?? 0) + $t;
            $byPurpose[$r['purpose']] = ($byPurpose[$r['purpose']] ?? 0) + $t;
            if ($r['method'] !== 'internal_transfer') $byChannel[$r['method']] = ($byChannel[$r['method']] ?? 0) + $t;
        }
        $add = function (string $date, string $purpose, string $method, float $amount) use (&$byMonth, &$byPurpose, &$byChannel) {
            $m = substr($date, 0, 7);
            $byMonth[$m] = ($byMonth[$m] ?? 0) + $amount;
            $byPurpose[$purpose] = ($byPurpose[$purpose] ?? 0) + $amount;
            if ($method !== 'internal_transfer') $byChannel[$method] = ($byChannel[$method] ?? 0) + $amount;
        };
        // Transport payments (their own table; no read model, so a keyed range read summed here).
        // A fees-to-transport move shows here as + and in fees as -, so the total is unchanged.
        $t = $this->db->prepare("SELECT payment_date, amount, payment_method FROM transport_entitlement_payments
                                  WHERE payment_status = 'confirmed' AND payment_date BETWEEN ? AND ?");
        $t->execute([$from, $to]);
        foreach ($t->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $method = strtolower((string) $r['payment_method']);
            $add((string) $r['payment_date'], 'transport', in_array($method, ['mpesa', 'm-pesa'], true) ? 'mpesa' : ($method === 'internal_transfer' ? 'internal_transfer' : ($method === 'cash' ? 'cash' : 'bank_transfer')), (float) $r['amount']);
        }
        // Uniform sales: paid catalogue orders (counter, staff and parent) and older uniform payments.
        $u = $this->db->prepare("SELECT DATE(paid_at) AS d, SUM(total_amount) AS total FROM " . ReadReplicaService::qualifiedRef('catalog_orders') . "
                                  WHERE payment_status = 'paid' AND status <> 'cancelled' AND paid_at BETWEEN ? AND ? GROUP BY DATE(paid_at)");
        $u->execute([$from, $to . ' 23:59:59']);
        foreach ($u->fetchAll(PDO::FETCH_ASSOC) as $r) $add((string) $r['d'], 'uniforms', 'other', (float) $r['total']);
        $up = $this->db->prepare("SELECT payment_date, payment_method, SUM(amount) AS total FROM " . ReadReplicaService::qualifiedRef('uniform_payment_records') . "
                                   WHERE payment_date BETWEEN ? AND ? GROUP BY payment_date, payment_method");
        $up->execute([$from, $to]);
        foreach ($up->fetchAll(PDO::FETCH_ASSOC) as $r) $add((string) $r['payment_date'], 'uniforms', strtolower((string) $r['payment_method']) ?: 'other', (float) $r['total']);
        $label = ['mpesa' => 'M-Pesa', 'bank_transfer' => 'Bank', 'cash' => 'Cash', 'cheque' => 'Cheque', 'other' => 'Other'];
        arsort($byChannel);
        return [
            'by_month' => $byMonth,
            'by_purpose' => array_map(static fn ($k, $v) => ['purpose' => ['fees' => 'Fees', 'transport' => 'Transport', 'uniforms' => 'Uniforms'][$k] ?? ucfirst((string) $k), 'amount' => round($v, 2)], array_keys($byPurpose), $byPurpose),
            'by_channel' => array_map(static fn ($k, $v) => ['channel' => $label[$k] ?? ucfirst(str_replace('_', ' ', (string) $k)), 'amount' => round($v, 2)], array_keys($byChannel), $byChannel),
        ];
    }

    /** Approved and paid expenses: by month and by category. */
    private function expenses(string $from, string $to): array
    {
        $stmt = $this->db->prepare(
            "SELECT DATE_FORMAT(expense_date, '%Y-%m') AS m, category_id, SUM(amount) AS total
               FROM " . ReadReplicaService::qualifiedRef('expenses') . "
              WHERE status IN ('approved','payment_pending','paid') AND deleted_at IS NULL AND expense_date BETWEEN ? AND ?
              GROUP BY m, category_id"
        );
        $stmt->execute([$from, $to]);
        $byMonth = $byCat = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $byMonth[$r['m']] = ($byMonth[$r['m']] ?? 0) + (float) $r['total'];
            $byCat[(int) $r['category_id']] = ($byCat[(int) $r['category_id']] ?? 0) + (float) $r['total'];
        }
        $names = [];
        foreach ($this->db->query('SELECT id, name FROM expense_categories') as $c) $names[(int) $c['id']] = $c['name'];
        arsort($byCat);
        $cats = [];
        foreach ($byCat as $id => $v) $cats[] = ['category' => $names[$id] ?? 'Uncategorised', 'amount' => round($v, 2)];
        return ['by_month' => $byMonth, 'by_category' => $cats];
    }

    /** Expenses submitted and waiting for approval (not yet spending). */
    private function pendingExpenses(string $from, string $to): array
    {
        $s = $this->db->prepare("SELECT COUNT(*), COALESCE(SUM(amount), 0) FROM " . ReadReplicaService::qualifiedRef('expenses') . "
                                  WHERE status = 'pending_approval' AND deleted_at IS NULL AND expense_date BETWEEN ? AND ?");
        $s->execute([$from, $to]);
        [$n, $amt] = $s->fetch(PDO::FETCH_NUM) ?: [0, 0];
        return ['count' => (int) $n, 'amount' => round((float) $amt, 2)];
    }

    /** Payroll cost (gross pay plus the employer's NSSF and housing levy) of approved and paid payslips. */
    private function payroll(array $months): array
    {
        if (!$months) return ['by_month' => [], 'total' => 0.0];
        $years = implode(',', array_unique(array_map(static fn ($m) => (int) substr($m, 0, 4), $months)));
        $rows = $this->db->query(
            "SELECT payroll_year, payroll_month, SUM(gross_salary + employer_nssf_contribution + employer_housing_levy) AS cost
               FROM " . ReadReplicaService::qualifiedRef('payslips') . "
              WHERE data_scope = 'live' AND payslip_status IN ('approved','paid') AND payroll_year IN ({$years})
              GROUP BY payroll_year, payroll_month"
        )->fetchAll(PDO::FETCH_ASSOC);
        $wanted = array_flip($months);
        $by = [];
        $total = 0.0;
        foreach ($rows as $r) {
            $m = sprintf('%04d-%02d', (int) $r['payroll_year'], (int) $r['payroll_month']);
            if (!isset($wanted[$m])) continue;
            $by[$m] = (float) $r['cost'];
            $total += (float) $r['cost'];
        }
        return ['by_month' => $by, 'total' => $total];
    }

    /** Fees paid before the school started using the system, entered when learners were added. */
    private function goLive(int $yearId): float
    {
        $s = $this->db->prepare('SELECT COALESCE(SUM(academic_year_paid_amount), 0) FROM ' . ReadReplicaService::qualifiedRef('student_fee_migration_snapshots') . ' WHERE academic_year_id = ?');
        $s->execute([$yearId]);
        return round((float) $s->fetchColumn(), 2);
    }

    /** Fees billed, waived, collected and outstanding for the year; arrears by age and by class. */
    private function receivables(int $yearId): array
    {
        $fb = ReadReplicaService::qualifiedRef('student_fee_balances');
        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(amount_due), 0) AS billed, COALESCE(SUM(amount_waived), 0) AS waived,
                    COALESCE(SUM(amount_paid + amount_credited), 0) AS collected,
                    COALESCE(SUM(CASE WHEN balance > 0 THEN balance ELSE 0 END), 0) AS owed,
                    COALESCE(SUM(CASE WHEN balance < 0 THEN -balance ELSE 0 END), 0) AS in_credit,
                    COUNT(DISTINCT CASE WHEN balance > 0 THEN student_id END) AS learners_owing,
                    COALESCE(SUM(CASE WHEN balance > 0 AND days_overdue <= 30 THEN balance ELSE 0 END), 0) AS age_0_30,
                    COALESCE(SUM(CASE WHEN balance > 0 AND days_overdue BETWEEN 31 AND 60 THEN balance ELSE 0 END), 0) AS age_31_60,
                    COALESCE(SUM(CASE WHEN balance > 0 AND days_overdue BETWEEN 61 AND 90 THEN balance ELSE 0 END), 0) AS age_61_90,
                    COALESCE(SUM(CASE WHEN balance > 0 AND days_overdue > 90 THEN balance ELSE 0 END), 0) AS age_90
               FROM {$fb} WHERE academic_year_id = ?"
        );
        $stmt->execute([$yearId]);
        $r = array_map('floatval', $stmt->fetch(PDO::FETCH_ASSOC) ?: []);
        $net = max(0.0, ($r['billed'] ?? 0) - ($r['waived'] ?? 0));

        $cls = $this->db->prepare(
            // The class of the enrollment the balance belongs to (the directory
            // holds only each learner's latest enrollment).
            "SELECT c.name AS class_name, SUM(CASE WHEN b.balance > 0 THEN b.balance ELSE 0 END) AS owed, SUM(b.amount_due - b.amount_waived) AS billed,
                    COUNT(DISTINCT CASE WHEN b.balance > 0 THEN b.student_id END) AS learners
               FROM {$fb} b
               JOIN " . ReadReplicaService::qualifiedRef('student_academic_enrollments') . " sae ON sae.id = b.student_academic_enrollment_id
               LEFT JOIN " . ReadReplicaService::qualifiedRef('academic_year_class_streams') . " aycs ON aycs.id = sae.academic_year_class_stream_id
               LEFT JOIN " . ReadReplicaService::qualifiedRef('academic_year_classes') . " ayc ON ayc.id = aycs.academic_year_class_id
               LEFT JOIN " . ReadReplicaService::qualifiedRef('classes') . " c ON c.id = ayc.class_id
              WHERE b.academic_year_id = ?
              GROUP BY c.id, c.name ORDER BY MIN(c.id), c.name"
        );
        $cls->execute([$yearId]);
        return [
            'billed' => round($r['billed'] ?? 0, 2), 'waived' => round($r['waived'] ?? 0, 2),
            'collected' => round($r['collected'] ?? 0, 2), 'owed' => round($r['owed'] ?? 0, 2), 'in_credit' => round($r['in_credit'] ?? 0, 2),
            'learners_owing' => (int) ($r['learners_owing'] ?? 0),
            'collection_rate' => $net > 0 ? round(min(100, ($r['collected'] ?? 0) * 100 / $net), 1) : null,
            'aging' => [
                ['label' => 'Up to 30 days', 'amount' => round($r['age_0_30'] ?? 0, 2)],
                ['label' => '31 to 60 days', 'amount' => round($r['age_31_60'] ?? 0, 2)],
                ['label' => '61 to 90 days', 'amount' => round($r['age_61_90'] ?? 0, 2)],
                ['label' => 'Over 90 days', 'amount' => round($r['age_90'] ?? 0, 2)],
            ],
            'by_class' => array_map(static fn ($c) => ['class' => $c['class_name'] ?: 'No class', 'owed' => round((float) $c['owed'], 2), 'billed' => round((float) $c['billed'], 2), 'learners' => (int) $c['learners']], $cls->fetchAll(PDO::FETCH_ASSOC)),
        ];
    }

    /** What the school owes: approved expenses not yet paid, and payroll approved but not paid. */
    private function payables(): array
    {
        $e = $this->db->query(
            "SELECT status, COUNT(*) AS n, COALESCE(SUM(amount), 0) AS total
               FROM " . ReadReplicaService::qualifiedRef('expenses') . "
              WHERE status IN ('approved','payment_pending') AND deleted_at IS NULL GROUP BY status"
        )->fetchAll(PDO::FETCH_ASSOC);
        $byStatus = array_column($e, null, 'status');
        $p = $this->db->query(
            "SELECT COUNT(*) AS n, COALESCE(SUM(net_salary), 0) AS total
               FROM " . ReadReplicaService::qualifiedRef('payslips') . "
              WHERE data_scope = 'live' AND payslip_status = 'approved' AND payment_status <> 'paid'"
        )->fetch(PDO::FETCH_ASSOC) ?: ['n' => 0, 'total' => 0];
        return [
            'expenses_to_pay' => ['count' => (int) ($byStatus['approved']['n'] ?? 0), 'amount' => round((float) ($byStatus['approved']['total'] ?? 0), 2)],
            'payments_in_progress' => ['count' => (int) ($byStatus['payment_pending']['n'] ?? 0), 'amount' => round((float) ($byStatus['payment_pending']['total'] ?? 0), 2)],
            'payroll_to_pay' => ['count' => (int) $p['n'], 'amount' => round((float) $p['total'], 2)],
        ];
    }

    /** The year's active (or else approved) budget, by department. */
    private function budget(int $yearId): ?array
    {
        $s = $this->db->prepare("SELECT id FROM budgets WHERE academic_year_id = ? AND status IN ('active','approved') ORDER BY status = 'active' DESC, term IS NULL DESC, id DESC LIMIT 1");
        $s->execute([$yearId]);
        $id = (int) $s->fetchColumn();
        if (!$id) return null;
        $b = (new BudgetService($this->db))->get($id);
        return ['id' => $id, 'name' => $b['budget']['name'], 'status' => $b['budget']['status'], 'totals' => $b['totals'], 'departments' => $b['departments']];
    }

    /** Decisions waiting for the School Administrator or Director. */
    private function waiting(): array
    {
        $count = function (string $sql): array {
            try { $r = $this->db->query($sql)->fetch(PDO::FETCH_NUM) ?: [0, 0]; return ['count' => (int) $r[0], 'amount' => round((float) ($r[1] ?? 0), 2)]; }
            catch (\Throwable) { return ['count' => 0, 'amount' => 0.0]; }
        };
        return [
            ['label' => 'Expenses to approve', 'route' => 'finance_approvals'] + $count("SELECT COUNT(*), SUM(amount) FROM " . ReadReplicaService::qualifiedRef('expenses') . " WHERE status = 'pending_approval' AND deleted_at IS NULL"),
            ['label' => 'Budgets to review or approve', 'route' => 'budget_overview'] + $count("SELECT COUNT(*), SUM(total_amount) FROM budgets WHERE status IN ('submitted','under_review')"),
            ['label' => 'Requests for more funds', 'route' => 'budget_overview'] + $count("SELECT COUNT(*), SUM(amount_change) FROM budget_amendments WHERE status = 'pending'"),
            ['label' => 'Money to move between learners', 'route' => 'student_fund_transfers'] + $count("SELECT COUNT(*), SUM(amount) FROM student_fund_transfers WHERE status = 'pending_approval'"),
            ['label' => 'Parent refunds to decide', 'route' => 'parent_refunds'] + $count("SELECT COUNT(*), SUM(amount) FROM parent_refund_requests WHERE status IN ('pending','pending_approval','submitted')"),
            ['label' => 'Payments with no matching learner', 'route' => 'unmatched_payments'] + $count("SELECT COUNT(*), SUM(amount) FROM " . ReadReplicaService::qualifiedRef('payment_unmatched_cases') . " WHERE status = 'unmatched'"),
        ];
    }
}
