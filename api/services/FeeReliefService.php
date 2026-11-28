<?php

namespace App\API\Services;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Fee relief on a learner's bills, one row per relief and bill
 * (student_fee_relief_entries): scholarships and sponsorships
 * (student_scholarship_awards) and relief granted at admission.
 *
 * Every change writes its row and then recomputes the bill's
 * sponsored_waiver_amount as the total of its active rows, capped at the
 * fee. Nothing writes that column directly any more, so one relief can
 * never silently replace another, and the statement can say which relief
 * it was, who granted it and when.
 */
final class FeeReliefService
{
    public const SOURCES = ['scholarship', 'admission_relief', 'unrecorded'];

    public function __construct(private PDO $db) {}

    /** Add or replace one relief on one bill, then recompute the bill. */
    public function grant(int $obligationId, string $source, int $sourceId, float $amount, string $description,
                          ?string $reason, ?int $grantedBy, ?string $grantedAt = null): void
    {
        if (!in_array($source, self::SOURCES, true)) {
            throw new RuntimeException('Unknown kind of fee relief.');
        }
        $bill = $this->bill($obligationId);
        $amount = round(max(0.0, $amount), 2);
        $this->db->prepare(
            "INSERT INTO student_fee_relief_entries
                (student_id, student_fee_obligation_id, academic_year_id, academic_year_term_id, source, source_id,
                 description, reason, amount, status, granted_by, granted_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?)
             ON DUPLICATE KEY UPDATE description = VALUES(description), reason = VALUES(reason), amount = VALUES(amount),
                 status = 'active', granted_by = VALUES(granted_by), granted_at = VALUES(granted_at),
                 revoked_by = NULL, revoked_at = NULL"
        )->execute([
            (int) $bill['student_id'], $obligationId, (int) $bill['academic_year_id'], (int) $bill['academic_year_term_id'],
            $source, $sourceId, mb_substr($description, 0, 255), $reason, $amount, $grantedBy ?: null,
            $grantedAt ?: date('Y-m-d H:i:s'),
        ]);
        // A real grant replaces the placeholder carried over before reliefs were itemised.
        if ($source !== 'unrecorded') {
            $this->db->prepare("UPDATE student_fee_relief_entries SET status = 'revoked', revoked_at = NOW()
                                 WHERE student_fee_obligation_id = ? AND source = 'unrecorded' AND status = 'active'")
                ->execute([$obligationId]);
        }
        $this->recompute($obligationId);
    }

    /** Revoke every bill's relief from one source (an award or an admission), then recompute those bills. */
    public function revokeSource(string $source, int $sourceId, ?int $revokedBy): int
    {
        $stmt = $this->db->prepare("SELECT DISTINCT student_fee_obligation_id FROM student_fee_relief_entries
                                     WHERE source = ? AND source_id = ? AND status = 'active'");
        $stmt->execute([$source, $sourceId]);
        $bills = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $this->db->prepare("UPDATE student_fee_relief_entries SET status = 'revoked', revoked_by = ?, revoked_at = NOW()
                             WHERE source = ? AND source_id = ? AND status = 'active'")
            ->execute([$revokedBy ?: null, $source, $sourceId]);
        foreach ($bills as $id) {
            $this->recompute($id);
        }
        return count($bills);
    }

    /**
     * Apply a scholarship or sponsorship award to the learner's bills for its
     * year and dates: full cover, a percentage, or a fixed amount per bill,
     * never more than the fee. $skipPaid leaves bills already marked paid.
     */
    public function applyScholarshipAward(int $awardId, bool $skipPaid = false): int
    {
        $stmt = $this->db->prepare("SELECT * FROM student_scholarship_awards WHERE id = ? AND status = 'active'");
        $stmt->execute([$awardId]);
        $award = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$award) return 0;
        $program = $this->db->prepare('SELECT name FROM scholarship_programs WHERE id = ?');
        $program->execute([(int) $award['scholarship_program_id']]);
        $name = (string) ($program->fetchColumn() ?: 'Scholarship');

        // The learner's bills for the award's year, with each term's dates (keyed reads on the primary).
        $enr = $this->db->prepare('SELECT id FROM student_academic_enrollments WHERE student_id = ?');
        $enr->execute([(int) $award['student_id']]);
        $enrollmentIds = array_map('intval', $enr->fetchAll(PDO::FETCH_COLUMN));
        $billRows = [];
        if ($enrollmentIds) {
            $q = $this->db->prepare('SELECT id, amount_due, status, academic_year_term_id FROM student_fee_obligations
                                      WHERE academic_year_id = ? AND student_academic_enrollment_id IN (' . implode(',', $enrollmentIds) . ')');
            $q->execute([(int) $award['academic_year_id']]);
            $billRows = $q->fetchAll(PDO::FETCH_ASSOC);
        }
        $termIds = array_values(array_unique(array_map(static fn ($b) => (int) $b['academic_year_term_id'], $billRows)));
        $termDates = [];
        if ($termIds) {
            foreach ($this->db->query('SELECT id, opening_date, closing_date FROM academic_year_terms WHERE id IN (' . implode(',', $termIds) . ')')->fetchAll(PDO::FETCH_ASSOC) as $t) {
                $termDates[(int) $t['id']] = $t;
            }
        }
        foreach ($billRows as &$b) {
            $b['opening_date'] = $termDates[(int) $b['academic_year_term_id']]['opening_date'] ?? null;
            $b['closing_date'] = $termDates[(int) $b['academic_year_term_id']]['closing_date'] ?? null;
        }
        unset($b);

        $type = (string) $award['coverage_type'];
        $pct = (float) ($award['coverage_percentage'] ?? 0);
        $fixed = (float) ($award['coverage_amount'] ?? 0);
        $cover = $type === 'full' ? 'full cover' : ($type === 'percentage' ? rtrim(rtrim(number_format($pct, 2), '0'), '.') . '% cover' : 'fixed amount');
        $n = 0;
        $covered = [];
        foreach ($billRows as $b) {
            if ($skipPaid && $b['status'] === 'paid') {
                $covered[] = (int) $b['id']; // keep whatever this award already gave a paid bill
                continue;
            }
            if ($award['starts_on'] && $b['closing_date'] && $b['closing_date'] < $award['starts_on']) continue;
            if ($award['ends_on'] && $b['opening_date'] && $b['opening_date'] > $award['ends_on']) continue;
            $due = (float) $b['amount_due'];
            $amount = min($due, $type === 'full' ? $due : ($type === 'percentage' ? $due * $pct / 100 : $fixed));
            $this->grant((int) $b['id'], 'scholarship', $awardId, $amount, $name . ' (' . $cover . ')',
                $award['reason'] ?? null, (int) $award['awarded_by'] ?: null, $award['updated_at'] ?: $award['created_at']);
            $covered[] = (int) $b['id'];
            $n++;
        }
        // An edited award (dates moved): bills it no longer covers lose its relief.
        $old = $this->db->prepare("SELECT student_fee_obligation_id FROM student_fee_relief_entries
                                    WHERE source = 'scholarship' AND source_id = ? AND status = 'active'");
        $old->execute([$awardId]);
        foreach (array_diff(array_map('intval', $old->fetchAll(PDO::FETCH_COLUMN)), $covered) as $billId) {
            $this->db->prepare("UPDATE student_fee_relief_entries SET status = 'revoked', revoked_at = NOW()
                                 WHERE student_fee_obligation_id = ? AND source = 'scholarship' AND source_id = ?")
                ->execute([$billId, $awardId]);
            $this->recompute($billId);
        }
        return $n;
    }

    /**
     * The bill's relief = its active rows, capped at the fee. Status follows
     * as before: fully relieved bills are paid; a bill that loses its relief
     * goes back to what fee payments cover.
     */
    public function recompute(int $obligationId): void
    {
        $bill = $this->bill($obligationId);
        $sum = $this->db->prepare("SELECT COALESCE(SUM(amount), 0) FROM student_fee_relief_entries
                                    WHERE student_fee_obligation_id = ? AND status = 'active'");
        $sum->execute([$obligationId]);
        $due = (float) $bill['amount_due'];
        $relief = round(min($due, (float) $sum->fetchColumn()), 2);
        $status = $bill['status'];
        if ($due > 0 && $relief >= $due) {
            $status = 'paid';
        } elseif ($status === 'paid' && (float) $bill['sponsored_waiver_amount'] > $relief) {
            $paid = $this->db->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments
                                         WHERE student_id = ? AND status = 'confirmed' AND payment_purpose = 'fees'");
            $paid->execute([(int) $bill['student_id']]);
            $p = (float) $paid->fetchColumn();
            $status = $p + $relief >= $due ? 'paid' : ($p + $relief > 0 ? 'partial' : 'pending');
        } elseif ($status === 'pending' && $relief > 0) {
            $status = 'partial';
        }
        $this->db->prepare('UPDATE student_fee_obligations SET sponsored_waiver_amount = ?, is_sponsored = ?, status = ?, updated_at = NOW() WHERE id = ?')
            ->execute([$relief, $relief > 0 ? 1 : 0, $status, $obligationId]);
    }

    private function bill(int $obligationId): array
    {
        $stmt = $this->db->prepare('SELECT id, student_academic_enrollment_id, academic_year_id, academic_year_term_id, amount_due, status,
                                           sponsored_waiver_amount FROM student_fee_obligations WHERE id = ?');
        $stmt->execute([$obligationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('That fee bill was not found.');
        $sid = $this->db->prepare('SELECT student_id FROM student_academic_enrollments WHERE id = ?');
        $sid->execute([(int) $row['student_academic_enrollment_id']]);
        $row['student_id'] = (int) $sid->fetchColumn();
        return $row;
    }

    /** Run $fn inside a transaction unless the caller already holds one. */
    public function atomically(callable $fn)
    {
        if ($this->db->inTransaction()) return $fn();
        $this->db->beginTransaction();
        try {
            $r = $fn();
            $this->db->commit();
            return $r;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }
}
