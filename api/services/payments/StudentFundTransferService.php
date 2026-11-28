<?php
declare(strict_types=1);

namespace App\API\Services\payments;

use PDO;
use RuntimeException;
use App\API\Services\ReadReplicaService;

/** Posts controlled internal movements between fee and transport credits. */
class StudentFundTransferService
{
    private $db;

    public function __construct(PDO $db) { $this->db = $db; }

    public function create(array $data, int $userId): array
    {
        $sourceStudent = (int)($data['source_student_id'] ?? 0);
        $destinationStudent = (int)($data['destination_student_id'] ?? 0);
        $sourceType = strtolower((string)($data['source_account_type'] ?? ''));
        $destinationType = strtolower((string)($data['destination_account_type'] ?? ''));
        $amount = (float)($data['amount'] ?? 0);
        if (!$sourceStudent || !$destinationStudent || !in_array($sourceType, ['fees','transport','payment'], true) || !in_array($destinationType, ['fees','transport'], true) || $amount <= 0 || empty($data['reason'])) {
            throw new RuntimeException('Both students, account types, amount and reason are required');
        }
        if ($sourceType === 'payment') {
            // A misdirected payment: paid into the wrong learner, or transport
            // money that landed in fees. Only what is still in the payment moves.
            $pay = $this->payment((int)($data['source_payment_id'] ?? 0));
            if ((int)$pay['student_id'] !== $sourceStudent) throw new RuntimeException('That payment belongs to a different learner');
            if ($amount > $pay['movable'] + 0.004) throw new RuntimeException(sprintf('Only KES %s of this payment can still be moved', number_format($pay['movable'], 2)));
            if ($destinationType === 'fees' && $destinationStudent === $sourceStudent) throw new RuntimeException('The payment is already on this learner\'s fees');
        }
        if ($sourceType === 'fees' && !(int)($data['source_credit_note_id'] ?? 0)) throw new RuntimeException('A source fee credit note is required');
        if ($sourceType === 'transport' && !(int)($data['source_entitlement_id'] ?? 0)) throw new RuntimeException('A source transport entitlement is required');
        if ($destinationType === 'transport' && !(int)($data['destination_entitlement_id'] ?? 0)) throw new RuntimeException('A destination transport entitlement is required');
        $parentId = !empty($data['parent_id']) ? (int)$data['parent_id'] : null;
        if ($parentId) {
            $p = $this->db->prepare('SELECT 1 FROM student_parents WHERE parent_id=? AND student_id IN (?,?) LIMIT 1');
            $p->execute([$parentId, $sourceStudent, $destinationStudent]);
            if (!$p->fetchColumn()) throw new RuntimeException('The parent is not linked to either student');
        }
        $this->validateDestination($destinationType, $destinationStudent, (int)($data['destination_entitlement_id'] ?? 0));
        $reference = trim((string)($data['parent_request_reference'] ?? '')) ?: null;
        $stmt = $this->db->prepare("INSERT INTO student_fund_transfers
            (source_student_id,source_account_type,source_credit_note_id,source_entitlement_id,source_payment_id,destination_student_id,destination_account_type,destination_entitlement_id,amount,parent_id,parent_request_reference,reason,created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$sourceStudent,$sourceType,(int)($data['source_credit_note_id'] ?? 0) ?: null,(int)($data['source_entitlement_id'] ?? 0) ?: null,$sourceType === 'payment' ? (int)$data['source_payment_id'] : null,$destinationStudent,$destinationType,(int)($data['destination_entitlement_id'] ?? 0) ?: null,$amount,$parentId,$reference,trim((string)$data['reason']),$userId]);
        return $this->get((int)$this->db->lastInsertId());
    }

    public function decide(int $id, string $decision, int $userId): array
    {
        if (!in_array($decision, ['approve','reject'], true)) throw new RuntimeException('Decision must be approve or reject');
        $status = $decision === 'approve' ? 'approved' : 'rejected';
        $s = $this->db->prepare("UPDATE student_fund_transfers SET status=?, approved_by=?, approved_at=NOW() WHERE id=? AND status='pending_approval'");
        $s->execute([$status,$userId,$id]);
        if (!$s->rowCount()) throw new RuntimeException('Transfer is not awaiting approval');
        return $this->get($id);
    }

    public function post(int $id, int $userId): array
    {
        $owns = !$this->db->inTransaction();
        if ($owns) $this->db->beginTransaction();
        try {
            $s=$this->db->prepare('SELECT * FROM student_fund_transfers WHERE id=? FOR UPDATE'); $s->execute([$id]); $t=$s->fetch(PDO::FETCH_ASSOC);
            if (!$t || $t['status'] !== 'approved') throw new RuntimeException('Only an approved transfer can be posted');
            $this->currentTransferId = $id;
            $amount=(float)$t['amount'];
            if ($t['source_account_type'] === 'payment') $source=$this->debitPayment($t,$amount);
            elseif ($t['source_account_type'] === 'fees') $this->debitFeeCredit((int)$t['source_credit_note_id'],$amount,(int)$t['source_student_id']);
            else $this->debitTransport((int)$t['source_entitlement_id'],$amount,(int)$t['source_student_id']);
            // From a payment, the money lands as a payment on the right learner,
            // so their balance drops at once and their statement shows it.
            if ($t['source_account_type'] === 'payment' && $t['destination_account_type'] === 'fees') $destinationId=$this->creditPayment($t,$amount,$source);
            elseif ($t['destination_account_type'] === 'fees') $destinationId=$this->creditFeeAccount((int)$t['destination_student_id'],$amount,$id,(int)$t['created_by']);
            else $destinationId=$this->creditTransport((int)$t['destination_entitlement_id'],(int)$t['destination_student_id'],$amount,$id);
            if ($t['source_account_type'] === 'payment' && $t['destination_account_type'] === 'transport') {
                // Fee money that was meant for transport: move it between the
                // fees and transport receivables in the accounts ledger too.
                (new \App\API\Services\AccountingPostingService($this->db))->post('student_fund_transfer', $id, 'reclass_fees_to_transport',
                    'Payment ' . ($source['receipt_no'] ?? '') . ' moved from fees to transport', [
                        ['account_code' => '120001', 'debit' => number_format($amount, 2, '.', ''), 'description' => 'Fees receivable: payment moved out'],
                        ['account_code' => '120002', 'credit' => number_format($amount, 2, '.', ''), 'description' => 'Transport receivable settlement'],
                    ], $userId);
            }
            $this->db->prepare("UPDATE student_fund_transfers SET status='posted',posted_by=?,posted_at=NOW() WHERE id=?")->execute([$userId,$id]);
            if ($owns) $this->db->commit();
            $applied = null;
            if ($owns && $t['source_account_type'] !== 'payment' && $t['destination_account_type'] === 'fees') {
                // The credit lands as a credit note; apply it to the learner's
                // outstanding fees now so their balance drops. If they owe
                // nothing yet, it stays available for their next bill.
                $applied = $this->applyCreditToFees((int)$destinationId, $amount, $userId);
            }
            return $this->get($id)+['destination_record_id'=>$destinationId,'applied_to_fees'=>$applied];
        } catch (\Throwable $e) { if ($owns && $this->db->inTransaction()) $this->db->rollBack(); throw $e; }
    }

    public function list(array $filters=[]): array
    {
        $sql='SELECT t.*, CONCAT(ps.first_name," ",ps.last_name) source_student_name, CONCAT(pd.first_name," ",pd.last_name) destination_student_name FROM student_fund_transfers t JOIN ' . ReadReplicaService::qualifiedRef('students') . ' ss ON ss.id=t.source_student_id JOIN ' . ReadReplicaService::qualifiedRef('persons') . ' ps ON ps.id=ss.person_id JOIN ' . ReadReplicaService::qualifiedRef('students') . ' sd ON sd.id=t.destination_student_id JOIN ' . ReadReplicaService::qualifiedRef('persons') . ' pd ON pd.id=sd.person_id';
        $params=[]; if (!empty($filters['status'])) { $sql.=' WHERE t.status=?'; $params[]=$filters['status']; } $sql.=' ORDER BY t.created_at DESC';
        $s=$this->db->prepare($sql); $s->execute($params); return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    public function sources(): array
    {
        $fees=$this->db->query("SELECT c.id AS source_id,c.student_id,(SELECT sp.parent_id FROM " . ReadReplicaService::qualifiedRef("student_parents") . " sp WHERE sp.student_id=c.student_id ORDER BY sp.is_primary_contact DESC,sp.parent_id LIMIT 1) AS parent_id,'fees' AS account_type,c.credit_number AS reference,c.remaining_amount AS available_amount,CONCAT(p.first_name,' ',p.last_name) AS student_name FROM " . ReadReplicaService::qualifiedRef("fee_credit_notes") . " c JOIN " . ReadReplicaService::qualifiedRef("students") . " s ON s.id=c.student_id JOIN " . ReadReplicaService::qualifiedRef("persons") . " p ON p.id=s.person_id WHERE c.status IN ('available','partially_applied') AND c.remaining_amount>0 ORDER BY student_name")->fetchAll(PDO::FETCH_ASSOC);
        $transport=$this->db->query("SELECT e.id AS source_id,e.student_id,(SELECT sp.parent_id FROM " . ReadReplicaService::qualifiedRef("student_parents") . " sp WHERE sp.student_id=e.student_id ORDER BY sp.is_primary_contact DESC,sp.parent_id LIMIT 1) AS parent_id,'transport' AS account_type,CONCAT(COALESCE(ep.label,ep.period_type),' · ',ep.period_start,' to ',ep.period_end) AS reference, GREATEST(0,COALESCE((SELECT SUM(a.amount) FROM " . ReadReplicaService::qualifiedRef("transport_entitlement_payment_allocations") . " a JOIN transport_entitlement_payments p ON p.id=a.payment_id WHERE a.entitlement_id=e.id AND p.payment_status='confirmed'),0)-COALESCE((SELECT SUM(amount) FROM student_fund_transfer_postings WHERE posting_type='transport_debit' AND source_record_id=e.id),0)) AS available_amount,CONCAT(per.first_name,' ',per.last_name) AS student_name FROM " . ReadReplicaService::qualifiedRef("student_transport_entitlements") . " e JOIN " . ReadReplicaService::qualifiedRef("students") . " s ON s.id=e.student_id JOIN " . ReadReplicaService::qualifiedRef("persons") . " per ON per.id=s.person_id JOIN transport_entitlement_periods ep ON ep.id=e.period_id WHERE e.entitlement_status='active' HAVING available_amount>0 ORDER BY student_name")->fetchAll(PDO::FETCH_ASSOC);
        return ['sources'=>array_merge($fees,$transport)];
    }

    public function destinations(): array
    {
        $studentSql = "SELECT s.id,s.admission_no,p.first_name,p.last_name
            FROM " . ReadReplicaService::qualifiedRef('students') . " s
            JOIN " . ReadReplicaService::qualifiedRef('persons') . " p ON p.id=s.person_id
            WHERE s.status='active' ORDER BY p.last_name,p.first_name";
        $sql = "SELECT e.id AS entitlement_id,e.student_id,CONCAT(COALESCE(ep.label,ep.period_type),' · ',ep.period_start,' to ',ep.period_end) AS reference
            FROM " . ReadReplicaService::qualifiedRef('student_transport_entitlements') . " e
            JOIN transport_entitlement_periods ep ON ep.id=e.period_id
            WHERE e.entitlement_status='active' AND ep.period_end>=CURDATE()
            ORDER BY e.student_id,ep.period_start,e.id";
        return [
            'students' => $this->db->query($studentSql)->fetchAll(PDO::FETCH_ASSOC),
            'entitlements' => $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    public function get(int $id): array
    { $s=$this->db->prepare('SELECT * FROM student_fund_transfers WHERE id=?'); $s->execute([$id]); return $s->fetch(PDO::FETCH_ASSOC) ?: []; }

    private function debitFeeCredit(int $creditId, float $amount, int $studentId): void
    {
        $s=$this->db->prepare("SELECT * FROM fee_credit_notes WHERE id=? AND student_id=? AND status IN ('available','partially_applied') FOR UPDATE"); $s->execute([$creditId,$studentId]); $c=$s->fetch(PDO::FETCH_ASSOC);
        if (!$c || (float)$c['remaining_amount'] < $amount) throw new RuntimeException('Insufficient available fee credit');
        $new=(float)$c['applied_amount']+$amount; $status=$new >= (float)$c['credit_amount'] ? 'fully_applied' : 'partially_applied';
        $this->db->prepare('UPDATE fee_credit_notes SET applied_amount=?,status=?,updated_at=NOW() WHERE id=?')->execute([$new,$status,$creditId]);
        $this->db->prepare("INSERT INTO student_fund_transfer_postings (transfer_id,posting_type,source_record_id,amount) VALUES (?,?,?,?)")->execute([$this->currentTransferId,'fee_credit_debit',$creditId,$amount]);
    }

    private function debitTransport(int $entitlementId, float $amount, int $studentId): void
    {
        $s=$this->db->prepare('SELECT id FROM student_transport_entitlements WHERE id=? AND student_id=? AND entitlement_status="active" FOR UPDATE'); $s->execute([$entitlementId,$studentId]);
        if (!$s->fetchColumn()) throw new RuntimeException('Source transport entitlement is invalid');
        $s=$this->db->prepare("SELECT COALESCE(SUM(a.amount),0) FROM " . ReadReplicaService::qualifiedRef("transport_entitlement_payment_allocations") . " a JOIN transport_entitlement_payments p ON p.id=a.payment_id WHERE a.entitlement_id=? AND p.payment_status='confirmed'"); $s->execute([$entitlementId]); $credited=(float)$s->fetchColumn();
        $s=$this->db->prepare("SELECT COALESCE(SUM(amount),0) FROM student_fund_transfer_postings WHERE posting_type='transport_debit' AND source_record_id=?"); $s->execute([$entitlementId]); $debited=(float)$s->fetchColumn();
        if (($credited-$debited) < $amount) throw new RuntimeException('Insufficient available transport credit');
        $this->db->prepare("INSERT INTO student_fund_transfer_postings (transfer_id,posting_type,source_record_id,amount) VALUES (?,?,?,?)")->execute([$this->currentTransferId,'transport_debit',$entitlementId,$amount]);
    }

    private function creditFeeAccount(int $studentId, float $amount, int $transferId, int $createdBy): int
    {
        $year=(int)date('Y'); $s=$this->db->query('SELECT YEAR(start_date) FROM academic_years WHERE is_current=1 LIMIT 1');$year=(int)($s->fetchColumn() ?: $year);
        $ref='TRF-'.$transferId.'-FEE'; $i=$this->db->prepare("INSERT INTO fee_credit_notes (credit_number,student_id,academic_year,credit_amount,credit_reason,status,applied_amount,notes,created_by) VALUES (?,?,?,?,'error_correction','available',0,?,?)"); $i->execute([$ref,$studentId,$year,$amount,'Internal fund transfer credit',$createdBy]); $id=(int)$this->db->lastInsertId();
        $this->db->prepare("INSERT INTO student_fund_transfer_postings (transfer_id,posting_type,destination_record_id,amount) VALUES (?,?,?,?)")->execute([$transferId,'fee_credit_credit',$id,$amount]); return $id;
    }

    private function creditTransport(int $entitlementId, int $studentId, float $amount, int $transferId): int
    {
        $this->validateDestination('transport',$studentId,$entitlementId); $i=$this->db->prepare("INSERT INTO transport_entitlement_payments (student_id,amount,payment_method,provider_name,provider_reference,payment_status,payment_date,received_by,notes) VALUES (?,?, 'internal_transfer','internal_transfer',?,'confirmed',CURDATE(),NULL,?)"); $i->execute([$studentId,$amount,'TRF-'.$transferId,'Transfer from student transport/fee credit']); $paymentId=(int)$this->db->lastInsertId(); $this->db->prepare('INSERT INTO transport_entitlement_payment_allocations (payment_id,entitlement_id,amount) VALUES (?,?,?)')->execute([$paymentId,$entitlementId,$amount]); $this->db->prepare("INSERT INTO student_fund_transfer_postings (transfer_id,posting_type,destination_record_id,amount) VALUES (?,?,?,?)")->execute([$transferId,'transport_credit',$entitlementId,$amount]); return $paymentId;
    }

    private function validateDestination(string $type,int $studentId,int $entitlementId): void
    { if ($type==='transport') { $s=$this->db->prepare('SELECT 1 FROM student_transport_entitlements WHERE id=? AND student_id=? AND entitlement_status="active"'); $s->execute([$entitlementId,$studentId]); if (!$s->fetchColumn()) throw new RuntimeException('Destination transport entitlement is invalid'); } }

    private function applyCreditToFees(int $creditNoteId, float $amount, int $userId): float
    {
        $year = (string)($this->db->query("SELECT year_code FROM academic_years WHERE is_current=1 ORDER BY start_date DESC LIMIT 1")->fetchColumn() ?: '');
        if ($year === '') return 0.0;
        try {
            return (new \App\API\Services\FinanceCrudService($this->db))->applyFeeCredit($creditNoteId, $amount, ['to_year' => $year], $userId);
        } catch (\Throwable $e) {
            return 0.0; // nothing outstanding to reduce yet: the credit stays available
        }
    }

    /**
     * A confirmed fee payment and how much of it can still be moved (its
     * amount less transfers already raised from it that were not rejected).
     */
    public function payment(int $paymentId): array
    {
        $s=$this->db->prepare("SELECT id,student_id,amount,payment_date,method,receipt_no,reference,provider_transaction_id,payer_name,payer_phone,payment_purpose,status,financial_account_id FROM payments WHERE id=?");
        $s->execute([$paymentId]); $p=$s->fetch(PDO::FETCH_ASSOC);
        if (!$p) throw new RuntimeException('Payment not found');
        if (!in_array($p['status'], ['confirmed'], true) || (float)$p['amount'] <= 0) throw new RuntimeException('Only a confirmed incoming payment can be moved');
        if (!in_array((string)($p['payment_purpose'] ?? 'fees'), ['', 'fees'], true)) throw new RuntimeException('Only a fee payment can be moved this way');
        $m=$this->db->prepare("SELECT COALESCE(SUM(amount),0) FROM student_fund_transfers WHERE source_payment_id=? AND status IN ('pending_approval','approved','posted')");
        $m->execute([$paymentId]);
        $p['movable']=max(0, round((float)$p['amount'] - (float)$m->fetchColumn(), 2));
        $p['amount']=(float)$p['amount'];
        return $p;
    }

    /** Find confirmed fee payments by receipt number, M-Pesa/bank code or reference (staff search). */
    public function findPayments(string $q): array
    {
        $q=trim($q); if (mb_strlen($q) < 4) return [];
        $s=$this->db->prepare("SELECT id FROM payments WHERE status='confirmed' AND amount>0 AND (receipt_no=? OR provider_transaction_id=? OR reference=?) ORDER BY id DESC LIMIT 10");
        $s->execute([$q,$q,$q]);
        $out=[];
        foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $id) { try { $out[]=$this->payment((int)$id); } catch (\Throwable $e) {} }
        $ids=array_values(array_unique(array_map(static fn($p)=>(int)$p['student_id'],$out)));
        if ($ids) {
            $n=$this->db->query("SELECT s.id,s.admission_no,p.first_name,p.last_name FROM " . ReadReplicaService::qualifiedRef('students') . " s JOIN " . ReadReplicaService::qualifiedRef('persons') . " p ON p.id=s.person_id WHERE s.id IN (".implode(',',$ids).")")->fetchAll(PDO::FETCH_ASSOC);
            $by=array_column($n,null,'id');
            foreach ($out as &$p) { $x=$by[(int)$p['student_id']] ?? null; $p['student_name']=$x?trim($x['first_name'].' '.$x['last_name']):''; $p['admission_no']=$x['admission_no'] ?? ''; }
            unset($p);
        }
        return $out;
    }

    /** Take money out of the misdirected payment: a negative payment row on the learner it went to. */
    private function debitPayment(array $t, float $amount): array
    {
        $s=$this->db->prepare("SELECT * FROM payments WHERE id=? FOR UPDATE"); $s->execute([(int)$t['source_payment_id']]); $p=$s->fetch(PDO::FETCH_ASSOC);
        if (!$p || $p['status'] !== 'confirmed') throw new RuntimeException('The source payment is no longer confirmed');
        $m=$this->db->prepare("SELECT COALESCE(SUM(amount),0) FROM student_fund_transfer_postings WHERE posting_type='payment_debit' AND source_record_id=?"); $m->execute([(int)$p['id']]);
        if ((float)$p['amount'] - (float)$m->fetchColumn() < $amount - 0.004) throw new RuntimeException('Not enough of the payment is left to move');
        $to=$t['destination_account_type']==='transport' ? 'transport' : 'learner #'.(int)$t['destination_student_id'];
        $this->db->prepare("INSERT INTO payments (student_id,financial_account_id,receipt_no,amount,payment_date,method,payment_purpose,reference,received_by,status,notes)
                            VALUES (?,?,?,?,?,'internal_transfer','fees',?,?,'confirmed',?)")
            ->execute([(int)$p['student_id'],$p['financial_account_id'],'MOVE-'.(int)$t['id'].'-OUT',-$amount,$p['payment_date'],$p['receipt_no'],(int)$t['created_by'],
                       'Moved out of payment '.$p['receipt_no'].' to '.$to.'. Reason: '.$t['reason']]);
        $rowId=(int)$this->db->lastInsertId();
        $this->db->prepare("INSERT INTO student_fund_transfer_postings (transfer_id,posting_type,source_record_id,destination_record_id,amount) VALUES (?,?,?,?,?)")->execute([(int)$t['id'],'payment_debit',(int)$p['id'],$rowId,$amount]);
        return $p;
    }

    /** Put the money on the right learner's fees as a payment, dated like the original. */
    private function creditPayment(array $t, float $amount, array $source): int
    {
        $this->db->prepare("INSERT INTO payments (student_id,financial_account_id,receipt_no,amount,payment_date,method,payment_purpose,reference,received_by,status,notes,payer_name,payer_phone,provider_transaction_id)
                            VALUES (?,?,?,?,?,'internal_transfer','fees',?,?,'confirmed',?,?,?,?)")
            ->execute([(int)$t['destination_student_id'],$source['financial_account_id'],'MOVE-'.(int)$t['id'].'-IN',$amount,$source['payment_date'],$source['receipt_no'],(int)$t['created_by'],
                       'Moved in from payment '.$source['receipt_no'].' (paid into learner #'.(int)$source['student_id'].'). Reason: '.$t['reason'],
                       $source['payer_name'] ?? null,$source['payer_phone'] ?? null,null]);
        $id=(int)$this->db->lastInsertId();
        $this->db->prepare("INSERT INTO student_fund_transfer_postings (transfer_id,posting_type,destination_record_id,amount) VALUES (?,?,?,?)")->execute([(int)$t['id'],'payment_credit',$id,$amount]);
        return $id;
    }

    private $currentTransferId;
}
