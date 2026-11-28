<?php

namespace App\API\Services;

use App\Database\Database;
use PDO;
use RuntimeException;

/**
 * The single source of fee statements: one learner, a class, or the school,
 * for ONE academic year.
 *
 * Every statement opens with what the learner brought into the year and
 * closes with a balance that equals its own lines:
 *
 *   closing = arrears brought forward - credit brought forward
 *           + fees billed - waived - other credit notes - money received
 *
 * - Arrears brought forward: opening_arrears_amount placed on the year's
 *   first bill by the year-end rollover.
 * - Credit brought forward: rollover credit notes (ROL-...) applied to the
 *   year's bills.
 * - Fees billed: the year's tuition items and extra charges.
 * - Money received: payments credited to this year by the fee view's rule
 *   (the latest enrolled year that had started by the payment date), plus
 *   amounts recorded at go-live (migration paid amounts and advances).
 *
 * The closing balance is the fee balance view's (the same figure every fee
 * report uses); the statement recomputes it from its own lines and reports
 * any difference in `reconciliation`, so a statement that does not add up is
 * visible instead of silently printed.
 */
class FeeStatementService
{
    /** Enrollments a learner can hold fees under (pending: not admitted). */
    private const BILLABLE_STATUSES = "('active','completed','graduated','transferred','withdrawn')";

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
    }

    // ---- Entry points -------------------------------------------------------

    /** One learner's statement for a year (default: current year). */
    public function forStudent(int $studentId, $academicYear = null): array
    {
        $year = $this->resolveYear($academicYear);
        $ids = $this->enrollmentIds($year['id'], ['sae.student_id = ?'], [$studentId]);
        if (!$ids) {
            throw new RuntimeException('This learner has no enrollment in ' . $year['year_code'] . '.');
        }
        return $this->build($year, $ids)[0];
    }

    /** Every learner in a class (optionally one stream) for a year. */
    public function forClass(int $classId, ?int $streamId = null, $academicYear = null): array
    {
        $year = $this->resolveYear($academicYear);
        $where = ['ayc.class_id = ?'];
        $params = [$classId];
        if ($streamId) {
            $where[] = 'aycs.stream_id = ?';
            $params[] = $streamId;
        }
        $statements = $this->build($year, $this->enrollmentIds($year['id'], $where, $params));

        return [
            'academic_year' => ['id' => $year['id'], 'code' => $year['year_code']],
            'class_id' => $classId,
            'stream_id' => $streamId,
            'statements' => $statements,
            'totals' => $this->totals($statements),
        ];
    }

    /** School summary: one row per class, with school totals. */
    public function forSchool($academicYear = null): array
    {
        $year = $this->resolveYear($academicYear);
        $statements = $this->build($year, $this->enrollmentIds($year['id'], [], []));
        $byClass = [];
        foreach ($statements as $statement) {
            $key = $statement['student']['class_name'] ?? 'No class';
            $byClass[$key] ??= ['class_name' => $key, 'grade_order' => $statement['student']['grade_order'], 'statements' => []];
            $byClass[$key]['statements'][] = $statement;
        }
        uasort($byClass, static fn ($a, $b) => $a['grade_order'] <=> $b['grade_order'] ?: strcmp($a['class_name'], $b['class_name']));

        $classes = [];
        foreach ($byClass as $group) {
            $classes[] = ['class_name' => $group['class_name']] + $this->totals($group['statements']);
        }

        return [
            'academic_year' => ['id' => $year['id'], 'code' => $year['year_code']],
            'classes' => $classes,
            'totals' => $this->totals($statements),
        ];
    }

    // ---- Building -----------------------------------------------------------

    /**
     * @param int[] $enrollmentIds
     * @return array<int, array> statements in class/stream/name order
     */
    private function build(array $year, array $enrollmentIds): array
    {
        if (!$enrollmentIds) {
            return [];
        }
        $yearId = (int) $year['id'];
        $in = implode(',', array_map('intval', $enrollmentIds));

        $learners = $this->learners($in);
        $studentIds = array_values(array_unique(array_map(static fn ($l) => (int) $l['student_id'], $learners)));
        $studentIn = implode(',', $studentIds ?: [0]);

        $terms = $this->rows(
            'SELECT ayt.id, t.name AS term_name, ayt.opening_date
             FROM ' . ReadReplicaService::qualifiedRef('academic_year_terms') . ' ayt
             JOIN terms t ON t.id = ayt.term_id
             WHERE ayt.academic_year_id = ?
             ORDER BY ayt.opening_date, ayt.id',
            [$yearId]
        );

        // Fee balance view: the recorded figures, per enrollment and term.
        $balances = $this->group($this->rows(
            "SELECT student_academic_enrollment_id AS eid, academic_year_term_id AS tid,
                    amount_due, amount_waived, amount_paid, amount_credited, balance
             FROM " . ReadReplicaService::qualifiedRef('student_fee_balances') . "
             WHERE student_academic_enrollment_id IN ({$in})"
        ), 'eid', 'tid');

        // Tuition items and the arrears brought forward, per term.
        $tuition = $this->rows(
            "SELECT sfo.student_academic_enrollment_id AS eid, sfo.academic_year_term_id AS tid,
                    COALESCE(fc.name, 'School fees') AS item,
                    SUM(sfo.amount_due) AS amount,
                    SUM(COALESCE(sfo.opening_arrears_amount, 0)) AS opening_arrears,
                    SUM(COALESCE(sfo.migration_paid_amount, 0)) AS migration_paid
             FROM " . ReadReplicaService::qualifiedRef('student_fee_obligations') . " sfo
             LEFT JOIN " . ReadReplicaService::qualifiedRef('academic_year_fee_schedules') . " ayfs ON ayfs.id = sfo.academic_year_fee_schedule_id
             LEFT JOIN fee_catalog fc ON fc.id = ayfs.fee_catalog_id
             WHERE sfo.student_academic_enrollment_id IN ({$in})
             GROUP BY sfo.student_academic_enrollment_id, sfo.academic_year_term_id, item"
        );

        // Extra charges (stored apart from tuition; no read projection).
        $extras = $this->rows(
            "SELECT eco.student_academic_enrollment_id AS eid, eco.academic_year_term_id AS tid,
                    ec.name AS item, SUM(eco.amount_due) AS amount, SUM(COALESCE(eco.amount_waived, 0)) AS waived
             FROM extra_charge_student_obligations eco
             JOIN extra_charge_schedules ecs ON ecs.id = eco.schedule_id
             JOIN " . ReadReplicaService::qualifiedRef('extra_charges') . " ec ON ec.id = ecs.extra_charge_id
             WHERE eco.student_academic_enrollment_id IN ({$in}) AND eco.status <> 'cancelled'
             GROUP BY eco.student_academic_enrollment_id, eco.academic_year_term_id, ec.name"
        );

        // Credit notes applied to this year's bills, rollover credit apart.
        $credits = $this->rows(
            "SELECT sfo.student_academic_enrollment_id AS eid, sfo.academic_year_term_id AS tid,
                    SUM(CASE WHEN n.credit_number LIKE 'ROL-%' THEN fca.applied_amount ELSE 0 END) AS rollover,
                    SUM(CASE WHEN n.credit_number LIKE 'ROL-%' THEN 0 ELSE fca.applied_amount END) AS other
             FROM fee_credit_applications fca
             JOIN " . ReadReplicaService::qualifiedRef('fee_credit_notes') . " n ON n.id = fca.fee_credit_note_id
             JOIN " . ReadReplicaService::qualifiedRef('student_fee_obligations') . " sfo ON sfo.id = fca.student_fee_obligation_id
             WHERE sfo.student_academic_enrollment_id IN ({$in})
             GROUP BY sfo.student_academic_enrollment_id, sfo.academic_year_term_id"
        );

        // Rollover records into this year: what was carried, from which year.
        $rollovers = [];
        foreach ($this->rows(
            "SELECT r.student_id, r.arrears_carried, r.credit_carried, ay.year_code AS from_year
             FROM " . ReadReplicaService::qualifiedRef('student_fee_rollover_balances') . " r
             JOIN " . ReadReplicaService::qualifiedRef('academic_years') . " ay ON ay.id = r.from_academic_year_id
             WHERE r.to_academic_year_id = ? AND r.from_academic_year_id <> r.to_academic_year_id
               AND r.student_id IN ({$studentIn})",
            [$yearId]
        ) as $row) {
            $rollovers[(int) $row['student_id']] = $row;
        }

        // Payments credited to this year: same rule as the fee view.
        $payments = [];
        foreach ($this->rows(
            "SELECT p.id, p.student_id, p.payment_date, p.method, p.amount, p.receipt_no, p.reference
             FROM " . ReadReplicaService::qualifiedRef('payments') . " p
             WHERE p.student_id IN ({$studentIn})
               AND p.status IN ('confirmed','completed','success')
               AND ? = " . $this->paymentYearExpression() . "
             ORDER BY p.payment_date, p.id",
            [$yearId]
        ) as $row) {
            $payments[(int) $row['student_id']][] = $row;
        }

        $advances = [];
        foreach ($this->rows(
            "SELECT student_id, SUM(COALESCE(advance_amount, 0)) AS advance
             FROM " . ReadReplicaService::qualifiedRef('student_fee_migration_snapshots') . "
             WHERE academic_year_id = ? AND student_id IN ({$studentIn})
             GROUP BY student_id",
            [$yearId]
        ) as $row) {
            $advances[(int) $row['student_id']] = (float) $row['advance'];
        }

        $ledgerData = $this->ledgerSources($in, $studentIn, $yearId);

        // Who received each payment and who paid it (primary, keyed by id).
        $paymentIds = [];
        foreach ($payments as $list) foreach ($list as $p) $paymentIds[] = (int) $p['id'];
        if ($paymentIds) {
            $who = [];
            foreach ($this->rows('SELECT id, received_by, payer_name FROM payments WHERE id IN (' . implode(',', $paymentIds) . ')') as $w) {
                $who[(int) $w['id']] = $w;
            }
            foreach ($payments as &$list) {
                foreach ($list as &$p) {
                    $p['received_by'] = $who[(int) $p['id']]['received_by'] ?? null;
                    $p['payer_name'] = $who[(int) $p['id']]['payer_name'] ?? null;
                }
                unset($p);
            }
            unset($list);
        }

        $items = [];
        foreach (array_merge($tuition, $extras) as $row) {
            $items[(int) $row['eid']][(int) $row['tid']][] = $row;
        }
        $creditsBy = $this->group($credits, 'eid', 'tid');

        $statements = [];
        foreach ($learners as $learner) {
            $eid = (int) $learner['eid'];
            $sid = (int) $learner['student_id'];
            $statements[] = $this->statement(
                $year,
                $learner,
                $terms,
                $balances[$eid] ?? [],
                $items[$eid] ?? [],
                $creditsBy[$eid] ?? [],
                $rollovers[$sid] ?? null,
                $payments[$sid] ?? [],
                $advances[$sid] ?? 0.0,
                $ledgerData
            );
        }
        return $statements;
    }

    private function statement(
        array $year, array $learner, array $terms, array $balances, array $items,
        array $credits, ?array $rollover, array $payments, float $advance, array $ledgerData = []
    ): array {
        $termRows = [];
        $sum = ['opening_arrears' => 0.0, 'fees' => 0.0, 'waived' => 0.0, 'credit_bf' => 0.0, 'credit_other' => 0.0,
                'paid' => 0.0, 'balance' => 0.0, 'migration_paid' => 0.0, 'items_difference' => 0.0];

        foreach ($terms as $term) {
            $tid = (int) $term['id'];
            $view = $balances[$tid] ?? null;
            $lineItems = [];
            $itemTotal = 0.0;
            $openingArrears = 0.0;
            foreach ($items[$tid] ?? [] as $item) {
                $openingArrears += (float) ($item['opening_arrears'] ?? 0);
                $sum['migration_paid'] += (float) ($item['migration_paid'] ?? 0);
                $amount = round((float) $item['amount'], 2);
                if ($amount != 0.0) {
                    $lineItems[] = ['item' => $item['item'], 'amount' => $amount];
                    $itemTotal += $amount;
                }
            }
            if (!$view && !$lineItems && $openingArrears == 0.0) {
                continue;
            }
            $due = (float) ($view['amount_due'] ?? 0);
            $fees = round($due - $openingArrears, 2);
            $credit = $credits[$tid] ?? ['rollover' => 0, 'other' => 0];
            $row = [
                'term_id' => $tid,
                'term' => $term['term_name'],
                'items' => $lineItems,
                'arrears_brought_forward' => round($openingArrears, 2),
                'fees_billed' => $fees,
                'waived' => round((float) ($view['amount_waived'] ?? 0), 2),
                'credit_brought_forward' => round((float) $credit['rollover'], 2),
                'credit_notes' => round((float) $credit['other'], 2),
                'paid' => round((float) ($view['amount_paid'] ?? 0), 2),
                'balance' => round((float) ($view['balance'] ?? 0), 2),
            ];
            $row['status'] = $this->status($row);
            $termRows[] = $row;

            $sum['opening_arrears'] += $openingArrears;
            $sum['fees'] += $fees;
            $sum['waived'] += $row['waived'];
            $sum['credit_bf'] += $row['credit_brought_forward'];
            $sum['credit_other'] += $row['credit_notes'];
            $sum['paid'] += $row['paid'];
            $sum['balance'] += $row['balance'];
            $sum['items_difference'] += round($fees - $itemTotal, 2);
        }

        $paymentTotal = round(array_sum(array_map(static fn ($p) => (float) $p['amount'], $payments)), 2);
        $moneyIn = round($paymentTotal + $sum['migration_paid'] + $advance, 2);
        $closing = round($sum['balance'], 2);
        $computed = round($sum['opening_arrears'] - $sum['credit_bf'] + $sum['fees'] - $sum['waived'] - $sum['credit_other'] - $moneyIn, 2);
        $creditCarried = round((float) ($rollover['credit_carried'] ?? 0), 2);

        $summary = [
            'arrears_brought_forward' => round($sum['opening_arrears'], 2),
            'credit_brought_forward' => round($sum['credit_bf'], 2),
            'fees_billed' => round($sum['fees'], 2),
            'waived' => round($sum['waived'], 2),
            'credit_notes' => round($sum['credit_other'], 2),
            'payments_received' => $moneyIn,
            'closing_balance' => $closing,
        ];
        $summary['status'] = $closing < 0 ? 'In credit' : ($closing == 0.0 ? 'Cleared' : 'Balance due');

        return [
            'student' => [
                'id' => (int) $learner['student_id'],
                'admission_no' => $learner['admission_no'],
                'first_name' => $learner['first_name'],
                'last_name' => $learner['last_name'],
                'name' => trim(($learner['first_name'] ?? '') . ' ' . ($learner['last_name'] ?? '')),
                'class_name' => $learner['class_name'],
                'stream_name' => $learner['stream_name'],
                'grade_order' => (int) $learner['grade_order'],
                'student_type' => $learner['student_type'],
                'enrollment_status' => $learner['enrollment_status'],
            ],
            'academic_year' => ['id' => (int) $year['id'], 'code' => $year['year_code']],
            'brought_forward' => [
                'from_year' => $rollover['from_year'] ?? null,
                'arrears' => $summary['arrears_brought_forward'],
                'credit' => $summary['credit_brought_forward'],
                'credit_not_yet_applied' => max(0.0, round($creditCarried - $sum['credit_bf'], 2)),
            ],
            'terms' => $termRows,
            'payments' => array_map(static fn ($p) => [
                'payment_date' => $p['payment_date'],
                'method' => $p['method'],
                'amount' => round((float) $p['amount'], 2),
                'receipt_no' => $p['receipt_no'],
                'reference' => $p['reference'],
            ], $payments),
            'money_in' => [
                'payments' => $paymentTotal,
                'recorded_at_go_live' => round($sum['migration_paid'] + $advance, 2),
                'total' => $moneyIn,
                'allocated_to_fees' => round($sum['paid'], 2),
            ],
            'summary' => $summary,
            'ledger' => $this->ledger($year, $learner, $terms, $termRows, $items, $rollover, $payments, $ledgerData, $closing),
            'reconciliation' => [
                'recorded_balance' => $closing,
                'computed_balance' => $computed,
                'difference' => round($closing - $computed, 2),
                'items_difference' => round($sum['items_difference'], 2),
                'ok' => abs($closing - $computed) < 0.01 && abs($sum['items_difference']) < 0.01,
            ],
        ];
    }

    // ------------------------------------------------------------------
    // The ledger: every entry that moved the balance, dated, with who.
    // ------------------------------------------------------------------

    /** @var array<int,string> users.id => name */
    private array $names = [];

    /**
     * What the ledger needs beyond the totals, for all learners at once:
     * the bills, waivers, fee relief, credit notes applied and the figures
     * recorded when a learner joined. Simple keyed reads, joined in PHP.
     */
    private function ledgerSources(string $enrollmentIn, string $studentIn, int $yearId): array
    {
        $bills = $this->rows(
            "SELECT id, student_academic_enrollment_id AS eid, academic_year_term_id AS tid
               FROM student_fee_obligations WHERE student_academic_enrollment_id IN ({$enrollmentIn})"
        );
        $billIn = implode(',', array_map(static fn ($b) => (int) $b['id'], $bills) ?: [0]);
        $bill = [];
        foreach ($bills as $b) $bill[(int) $b['id']] = ['eid' => (int) $b['eid'], 'tid' => (int) $b['tid']];

        $byEid = static function (array $rows, string $key) use ($bill): array {
            $out = [];
            foreach ($rows as $r) {
                $b = $bill[(int) $r[$key]] ?? null;
                if (!$b) continue;
                $r['tid'] = $b['tid'];
                $out[$b['eid']][] = $r;
            }
            return $out;
        };

        $waivers = $this->rows(
            "SELECT id, student_fee_obligation_id, discount_type, discount_value, discount_percentage, reason, approved_by, approved_date
               FROM fee_discounts_waivers WHERE student_fee_obligation_id IN ({$billIn}) AND status = 'active'"
        );
        $relief = $this->rows(
            "SELECT id, student_fee_obligation_id, source, source_id, description, reason, amount, granted_by, granted_at
               FROM student_fee_relief_entries WHERE student_fee_obligation_id IN ({$billIn}) AND status = 'active'"
        );
        $apps = $this->rows(
            "SELECT id, fee_credit_note_id, student_fee_obligation_id, applied_amount, applied_by, applied_at
               FROM fee_credit_applications WHERE student_fee_obligation_id IN ({$billIn})"
        );
        $noteIn = implode(',', array_unique(array_map(static fn ($a) => (int) $a['fee_credit_note_id'], $apps)) ?: [0]);
        $notes = [];
        foreach ($this->rows("SELECT id, credit_number, credit_reason, notes, created_by, created_at FROM fee_credit_notes WHERE id IN ({$noteIn})") as $n) {
            $notes[(int) $n['id']] = $n;
        }
        $snapshots = [];
        foreach ($this->rows(
            "SELECT student_id, academic_year_paid_amount, arrears_amount, advance_amount, reference, payment_date,
                    payment_method, receipt_no, notes, imported_by, created_at
               FROM student_fee_migration_snapshots WHERE academic_year_id = ? AND student_id IN ({$studentIn})",
            [$yearId]
        ) as $snap) {
            $snapshots[(int) $snap['student_id']] = $snap;
        }

        $this->prefetchNames(array_merge(
            array_column($waivers, 'approved_by'), array_column($relief, 'granted_by'),
            array_column($apps, 'applied_by'), array_column($snapshots, 'imported_by')
        ));

        return [
            'waivers' => $byEid($waivers, 'student_fee_obligation_id'),
            'relief' => $byEid($relief, 'student_fee_obligation_id'),
            'credits' => $byEid($apps, 'student_fee_obligation_id'),
            'notes' => $notes,
            'snapshots' => $snapshots,
        ];
    }

    private function ledger(array $year, array $learner, array $terms, array $termRows, array $items,
                            ?array $rollover, array $payments, array $src, float $recordedClosing): array
    {
        $eid = (int) $learner['eid'];
        $sid = (int) $learner['student_id'];
        $opening = [];
        foreach ($terms as $t) $opening[(int) $t['id']] = substr((string) $t['opening_date'], 0, 10);
        $first = $opening ? min($opening) : date('Y-m-d');
        $termName = array_column($terms, 'term_name', 'id');
        $this->prefetchNames(array_column($payments, 'received_by'));

        $entries = [];
        $seq = 0;
        $add = function (?string $date, int $order, string $kind, string $what, ?string $detail, $by, float $debit, float $credit, ?int $tid) use (&$entries, &$seq, $first, $termName) {
            $debit = round($debit, 2);
            $credit = round($credit, 2);
            if ($debit == 0.0 && $credit == 0.0) return;
            if ($debit < 0) { $credit -= $debit; $debit = 0.0; }
            if ($credit < 0) { $debit -= $credit; $credit = 0.0; }
            $entries[] = [
                'date' => substr((string) ($date ?: $first), 0, 10),
                'order' => $order, 'seq' => $seq++,
                'kind' => $kind,
                'description' => $what,
                'detail' => $detail !== null && trim($detail) !== '' ? trim($detail) : null,
                'term' => $tid ? ($termName[$tid] ?? null) : null,
                'by' => is_int($by) || ctype_digit((string) $by) ? ($by ? $this->nameOf((int) $by) : null) : $by,
                'debit' => round($debit, 2),
                'credit' => round($credit, 2),
            ];
        };
        $termRow = [];
        foreach ($termRows as $r) $termRow[(int) $r['term_id']] = $r;

        // 1. Brought forward: arrears from last year, then what was owed on joining.
        $snap = $src['snapshots'][$sid] ?? null;
        $arrears = round(array_sum(array_column($termRows, 'arrears_brought_forward')), 2);
        $fromRollover = round(min($arrears, (float) ($rollover['arrears_carried'] ?? 0)), 2);
        if ($fromRollover > 0) {
            $add($first, 0, 'brought_forward', 'Balance brought forward from ' . ($rollover['from_year'] ?? 'the previous year'), null, null, $fromRollover, 0, null);
        }
        if ($arrears - $fromRollover > 0.004) {
            $add($first, 0, 'brought_forward', 'Balance owed when the learner joined the system',
                $snap ? $this->snapDetail($snap) : null, $snap['imported_by'] ?? null, $arrears - $fromRollover, 0, null);
        }

        // 2. Fees billed, per term and item (tuition and extra charges).
        foreach ($termRows as $r) {
            $tid = (int) $r['term_id'];
            $listed = 0.0;
            foreach ($r['items'] as $it) {
                $add($opening[$tid] ?? null, 1, 'fee', $it['item'], null, null, (float) $it['amount'], 0, $tid);
                $listed += (float) $it['amount'];
            }
            $add($opening[$tid] ?? null, 1, 'fee', 'Other fees for the term', 'Fees recorded without an item name', null, (float) $r['fees_billed'] - $listed, 0, $tid);
        }

        // 3. Waivers, scholarships and sponsorships, admission relief, extra charges waived.
        $waivedByTerm = [];
        foreach ($src['waivers'][$eid] ?? [] as $w) {
            $type = (string) $w['discount_type'];
            $label = ['percentage' => 'Fee waiver (' . rtrim(rtrim(number_format((float) $w['discount_percentage'], 2), '0'), '.') . '%)',
                      'fixed_amount' => 'Fee waiver', 'full_waiver' => 'Full fee waiver', 'merit' => 'Merit discount',
                      'need_based' => 'Needs-based fee waiver', 'sibling' => 'Sibling discount'][$type] ?? 'Fee waiver';
            $add($w['approved_date'], 2, 'waiver', $label, $w['reason'], $w['approved_by'], 0, (float) $w['discount_value'], (int) $w['tid']);
            $waivedByTerm[(int) $w['tid']] = ($waivedByTerm[(int) $w['tid']] ?? 0) + (float) $w['discount_value'];
        }
        foreach ($src['relief'][$eid] ?? [] as $rel) {
            $what = $rel['source'] === 'scholarship' ? 'Scholarship / sponsorship: ' . $rel['description'] : $rel['description'];
            $add($rel['granted_at'] ?: ($opening[(int) $rel['tid']] ?? null), 2, $rel['source'] === 'scholarship' ? 'scholarship' : ($rel['source'] === 'admission_relief' ? 'admission_relief' : 'relief'),
                $what, $rel['reason'], $rel['granted_by'], 0, (float) $rel['amount'], (int) $rel['tid']);
            $waivedByTerm[(int) $rel['tid']] = ($waivedByTerm[(int) $rel['tid']] ?? 0) + (float) $rel['amount'];
        }
        foreach ($items as $tid => $rows) {
            foreach ($rows as $it) {
                if ((float) ($it['waived'] ?? 0) > 0) {
                    $add($opening[(int) $tid] ?? null, 2, 'waiver', 'Waived: ' . $it['item'], null, null, 0, (float) $it['waived'], (int) $tid);
                    $waivedByTerm[(int) $tid] = ($waivedByTerm[(int) $tid] ?? 0) + (float) $it['waived'];
                }
            }
        }
        foreach ($termRow as $tid => $r) {
            $diff = round((float) $r['waived'] - ($waivedByTerm[$tid] ?? 0), 2);
            if ($diff > 0) {
                $add($opening[$tid] ?? null, 2, 'relief', 'Fee relief (details not recorded)', 'Recorded on the bill before relief was itemised', null, 0, $diff, $tid);
            } elseif ($diff < 0) {
                $add($opening[$tid] ?? null, 2, 'adjustment', 'Relief above the fee due (not used)', 'Relief cannot take a term below zero', null, -$diff, 0, $tid);
            }
        }

        // 4. Credit notes applied: overpayments brought forward, and others.
        $creditByTerm = [];
        foreach ($src['credits'][$eid] ?? [] as $a) {
            $n = $src['notes'][(int) $a['fee_credit_note_id']] ?? [];
            $number = (string) ($n['credit_number'] ?? '');
            $amt = (float) $a['applied_amount'];
            if (str_starts_with($number, 'ROL-')) {
                $add($first, 0, 'credit_brought_forward', 'Overpayment brought forward from ' . ($rollover['from_year'] ?? 'the previous year'),
                    'Credit note ' . $number, $a['applied_by'], 0, $amt, (int) $a['tid']);
            } else {
                $reason = ['overpayment' => 'overpayment', 'fee_reduction' => 'fee reduction', 'sponsorship_adjustment' => 'sponsorship adjustment',
                           'error_correction' => 'correction', 'waiver_excess' => 'waiver above the fee', 'refund' => 'refund'][$n['credit_reason'] ?? ''] ?? 'credit';
                $add($a['applied_at'], 3, 'credit_note', 'Credit note ' . $number . ' (' . $reason . ')', $n['notes'] ?? null, $a['applied_by'], 0, $amt, (int) $a['tid']);
            }
            $creditByTerm[(int) $a['tid']] = ($creditByTerm[(int) $a['tid']] ?? 0) + $amt;
        }
        foreach ($termRow as $tid => $r) {
            $diff = round((float) $r['credit_brought_forward'] + (float) $r['credit_notes'] - ($creditByTerm[$tid] ?? 0), 2);
            $add($opening[$tid] ?? null, 3, 'adjustment', 'Credit adjustment', null, null, $diff < 0 ? -$diff : 0, $diff > 0 ? $diff : 0, $tid);
        }

        // 5. Money received: what was paid before joining, then every payment.
        $before = 0.0;
        foreach ($items as $rows) foreach ($rows as $it) $before += (float) ($it['migration_paid'] ?? 0);
        $before += (float) ($snap['advance_amount'] ?? 0);
        if ($before > 0) {
            $add($snap['payment_date'] ?? ($snap ? substr((string) $snap['created_at'], 0, 10) : null), 4, 'paid_before_joining',
                'Fees paid before the learner joined the system', $snap ? $this->snapDetail($snap) : 'Recorded when the school started using the system',
                $snap['imported_by'] ?? null, 0, $before, null);
        }
        foreach ($payments as $p) {
            if ((string) $p['method'] === 'internal_transfer') {
                // A misdirected payment moved between learners or to transport.
                $out = (float) $p['amount'] < 0;
                $add($p['payment_date'], 4, 'payment_moved', $out ? 'Payment moved to another account' : 'Payment moved in from another account',
                    $p['reference'] ? 'Original receipt ' . $p['reference'] : null, $p['received_by'] ?? null, 0, (float) $p['amount'], null);
                continue;
            }
            $method = $this->methodLabel((string) $p['method']);
            $detail = implode(', ', array_filter([
                $p['receipt_no'] ? 'Receipt ' . $p['receipt_no'] : null,
                $p['reference'] ? 'Ref ' . $p['reference'] : null,
                !empty($p['payer_name']) ? 'Paid by ' . $p['payer_name'] : null,
            ]));
            $add($p['payment_date'], 4, 'payment', 'Payment, ' . $method, $detail, $p['received_by'] ?? null, 0, (float) $p['amount'], null);
        }

        usort($entries, static fn ($a, $b) => [$a['date'], $a['order'], $a['seq']] <=> [$b['date'], $b['order'], $b['seq']]);
        $running = 0.0;
        foreach ($entries as &$e) {
            $running = round($running + $e['debit'] - $e['credit'], 2);
            $e['balance'] = $running;
            unset($e['order'], $e['seq']);
        }
        unset($e);

        return [
            'entries' => $entries,
            'total_debit' => round(array_sum(array_column($entries, 'debit')), 2),
            'total_credit' => round(array_sum(array_column($entries, 'credit')), 2),
            'closing_balance' => $running,
            'matches_recorded_balance' => abs($running - $recordedClosing) < 0.01,
        ];
    }

    private function snapDetail(array $snap): string
    {
        return implode(', ', array_filter([
            $snap['payment_method'] ? $this->methodLabel((string) $snap['payment_method']) : null,
            $snap['receipt_no'] ? 'Receipt ' . $snap['receipt_no'] : null,
            $snap['reference'] ? 'Ref ' . $snap['reference'] : null,
            $snap['notes'] ?: null,
            'Entered ' . substr((string) $snap['created_at'], 0, 10),
        ]));
    }

    private function methodLabel(string $m): string
    {
        $m = strtolower(trim($m));
        return ['mpesa' => 'M-Pesa', 'm-pesa' => 'M-Pesa', 'm_pesa' => 'M-Pesa', 'bank' => 'bank', 'bank_transfer' => 'bank transfer',
                'cash' => 'cash', 'cheque' => 'cheque', 'card' => 'card', 'kcb' => 'KCB', 'other' => 'other'][$m]
            ?? ($m !== '' ? str_replace('_', ' ', $m) : 'method not recorded');
    }

    /** Staff names for user ids: users -> persons, two keyed reads. */
    private function prefetchNames(array $userIds): void
    {
        $ids = array_values(array_diff(array_unique(array_filter(array_map('intval', $userIds))), array_keys($this->names)));
        if (!$ids) return;
        $users = $this->rows('SELECT id, person_id FROM users WHERE id IN (' . implode(',', $ids) . ')');
        $pids = array_values(array_unique(array_filter(array_map(static fn ($u) => (int) $u['person_id'], $users))));
        $persons = [];
        if ($pids) {
            foreach ($this->rows('SELECT id, first_name, last_name FROM persons WHERE id IN (' . implode(',', $pids) . ')') as $p) {
                $persons[(int) $p['id']] = trim($p['first_name'] . ' ' . $p['last_name']);
            }
        }
        foreach ($ids as $id) $this->names[$id] = '';
        foreach ($users as $u) $this->names[(int) $u['id']] = $persons[(int) $u['person_id']] ?? '';
    }

    private function nameOf(int $userId): ?string
    {
        if (!array_key_exists($userId, $this->names)) $this->prefetchNames([$userId]);
        return ($this->names[$userId] ?? '') !== '' ? $this->names[$userId] : null;
    }

    private function status(array $row): string
    {
        $owed = $row['arrears_brought_forward'] + $row['fees_billed'];
        if ($row['balance'] < 0) return 'In credit';
        if ($owed <= 0) return 'No fees';
        if ($row['balance'] <= 0) return 'Paid';
        if ($row['paid'] + $row['credit_brought_forward'] + $row['credit_notes'] + $row['waived'] > 0) return 'Partly paid';
        return 'Unpaid';
    }

    /** Column-wise totals over many statements. */
    private function totals(array $statements): array
    {
        $keys = ['arrears_brought_forward', 'credit_brought_forward', 'fees_billed', 'waived', 'credit_notes', 'payments_received', 'closing_balance'];
        $totals = array_fill_keys($keys, 0.0);
        $totals['learners'] = count($statements);
        $totals['learners_in_credit'] = 0;
        $totals['learners_owing'] = 0;
        $totals['not_reconciled'] = 0;
        foreach ($statements as $statement) {
            foreach ($keys as $key) {
                $totals[$key] += (float) $statement['summary'][$key];
            }
            $closing = (float) $statement['summary']['closing_balance'];
            if ($closing < 0) $totals['learners_in_credit']++;
            if ($closing > 0) $totals['learners_owing']++;
            if (!$statement['reconciliation']['ok']) $totals['not_reconciled']++;
        }
        foreach ($keys as $key) {
            $totals[$key] = round($totals[$key], 2);
        }
        return $totals;
    }

    // ---- Lookups ------------------------------------------------------------

    /**
     * The academic year a payment is credited to, as SQL over alias `p`: the
     * latest of the learner's enrolled years that started on or before the
     * payment, else their first enrolled year. Mirrors vw_student_fee_balances.
     */
    public function paymentYearExpression(): string
    {
        $sae = ReadReplicaService::qualifiedRef('student_academic_enrollments');
        $ay = ReadReplicaService::qualifiedRef('academic_years');
        $statuses = self::BILLABLE_STATUSES;
        return "COALESCE(
            (SELECT ayp.id FROM {$sae} saep JOIN {$ay} ayp ON ayp.id = saep.academic_year_id
              WHERE saep.student_id = p.student_id AND saep.enrollment_status IN {$statuses}
                AND ayp.start_date <= p.payment_date
              ORDER BY ayp.start_date DESC, ayp.id DESC LIMIT 1),
            (SELECT ayf.id FROM {$sae} saef JOIN {$ay} ayf ON ayf.id = saef.academic_year_id
              WHERE saef.student_id = p.student_id AND saef.enrollment_status IN {$statuses}
              ORDER BY ayf.start_date ASC, ayf.id ASC LIMIT 1))";
    }

    private function resolveYear($academicYear): array
    {
        // Accepts a year id (int, or a short digit string), a year code or
        // name ("2026/2027"), or a start year ("2026"), like the print
        // resolver. An explicit year that does not exist is an error, never
        // silently another year.
        $ay = ReadReplicaService::qualifiedRef('academic_years');
        if ($academicYear !== null && $academicYear !== '') {
            $value = trim((string) $academicYear);
            $asId = is_int($academicYear) || (ctype_digit($value) && strlen($value) < 4) ? (int) $value : 0;
            $row = $this->row(
                "SELECT id, year_code FROM {$ay}
                 WHERE id = ? OR year_code = ? OR year_name = ? OR year_code LIKE ?
                 ORDER BY (id = ?) DESC, (year_code = ?) DESC LIMIT 1",
                [$asId, $value, $value, $value . '/%', $asId, $value]
            );
            if (!$row) {
                throw new RuntimeException('Academic year not found.');
            }
            return $row;
        }
        $row = $this->row("SELECT id, year_code FROM {$ay} WHERE is_current = 1 ORDER BY start_date DESC LIMIT 1");
        if (!$row) {
            throw new RuntimeException('No academic year is set as current.');
        }
        return $row;
    }

    /** Billable enrollments of a year matching extra conditions. */
    private function enrollmentIds(int $yearId, array $where, array $params): array
    {
        $conditions = array_merge(['sae.academic_year_id = ?', 'sae.enrollment_status IN ' . self::BILLABLE_STATUSES], $where);
        $rows = $this->rows(
            "SELECT sae.id
             FROM " . ReadReplicaService::qualifiedRef('student_academic_enrollments') . " sae
             JOIN " . ReadReplicaService::qualifiedRef('students') . " s ON s.id = sae.student_id
             LEFT JOIN " . ReadReplicaService::qualifiedRef('academic_year_class_streams') . " aycs ON aycs.id = sae.academic_year_class_stream_id
             LEFT JOIN " . ReadReplicaService::qualifiedRef('academic_year_classes') . " ayc ON ayc.id = aycs.academic_year_class_id
             WHERE " . implode(' AND ', $conditions),
            array_merge([$yearId], $params)
        );
        return array_map(static fn ($r) => (int) $r['id'], $rows);
    }

    private function learners(string $in): array
    {
        return $this->rows(
            "SELECT sae.id AS eid, sae.student_id, sae.enrollment_status, s.admission_no,
                    p.first_name, p.last_name, c.name AS class_name, st.name AS stream_name,
                    stt.name AS student_type,
                    COALESCE(FIELD(c.grade_level, 'Playgroup','PP1','PP2','Grade 1','Grade 2','Grade 3','Grade 4','Grade 5','Grade 6','Grade 7','Grade 8','Grade 9'), 99) AS grade_order
             FROM " . ReadReplicaService::qualifiedRef('student_academic_enrollments') . " sae
             JOIN " . ReadReplicaService::qualifiedRef('students') . " s ON s.id = sae.student_id
             JOIN " . ReadReplicaService::qualifiedRef('persons') . " p ON p.id = s.person_id
             LEFT JOIN " . ReadReplicaService::qualifiedRef('student_types') . " stt ON stt.id = s.student_type_id
             LEFT JOIN " . ReadReplicaService::qualifiedRef('academic_year_class_streams') . " aycs ON aycs.id = sae.academic_year_class_stream_id
             LEFT JOIN " . ReadReplicaService::qualifiedRef('academic_year_classes') . " ayc ON ayc.id = aycs.academic_year_class_id
             LEFT JOIN " . ReadReplicaService::qualifiedRef('classes') . " c ON c.id = ayc.class_id
             LEFT JOIN " . ReadReplicaService::qualifiedRef('streams') . " st ON st.id = aycs.stream_id
             WHERE sae.id IN ({$in})
             ORDER BY grade_order, c.name, st.name, p.last_name, p.first_name, sae.id"
        );
    }

    private function group(array $rows, string $first, string $second): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row[$first]][(int) $row[$second]] = $row;
        }
        return $out;
    }

    private function rows(string $sql, array $params = []): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function row(string $sql, array $params = []): ?array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}
