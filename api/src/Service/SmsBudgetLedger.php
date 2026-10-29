<?php
declare(strict_types=1);

namespace Wellness\Service;

use DateTimeImmutable;
use PDO;
use Throwable;
use Wellness\Database;
use Wellness\Http\ApiException;

/** Internal accounting only. No public routes, provider calls or reminder activation. */
final class SmsBudgetLedger
{
    public function __construct(private readonly Database|PDO $database,private readonly bool $joinTransaction=false) {}

    public function enroll(int $poolId, int $membershipId): void
    {
        $this->locked($poolId, function (PDO $pdo, array $pool) use ($membershipId): void {
            $this->eligible($pdo, $pool, $membershipId);
            $q = $pdo->prepare('SELECT status FROM sms_budget_members WHERE pool_id=? AND membership_id=?');
            $q->execute([$pool['id'], $membershipId]);
            if ($q->fetchColumn() === 'active') return;
            $q = $pdo->prepare("SELECT COUNT(*) FROM sms_budget_members WHERE pool_id=? AND status='active'");
            $q->execute([$pool['id']]);
            if ((int)$q->fetchColumn() >= (int)$pool['max_members']) $this->deny('sms_cohort_full');
            $pdo->prepare("INSERT INTO sms_budget_members(pool_id,clinic_id,membership_id) VALUES(?,?,?) ON DUPLICATE KEY UPDATE status='active'")
                ->execute([$pool['id'], $pool['clinic_id'], $membershipId]);
        });
    }

    /** A stable reference identifies ONE delivery. Replayed entries must never trigger a new send. */
    public function reserve(int $poolId, int $membershipId, string $reference, int $estimatedCadCents, DateTimeImmutable $now, ?\Closure $bind = null): array
    {
        self::reference($reference);
        self::amount($estimatedCadCents, false);
        return $this->locked($poolId, function (PDO $pdo, array $pool) use ($membershipId, $reference, $estimatedCadCents, $now, $bind): array {
            $this->eligible($pdo, $pool, $membershipId);
            $q = $pdo->prepare("SELECT 1 FROM sms_budget_members WHERE pool_id=? AND membership_id=? AND status='active'");
            $q->execute([$pool['id'], $membershipId]);
            if (!$q->fetchColumn()) $this->deny('sms_not_enrolled');
            $previous = $this->entry($pdo, (int)$pool['id'], $reference);
            if ($previous) {
                if ((int)$previous['membership_id'] !== $membershipId || (int)$previous['estimated_cents'] !== $estimatedCadCents) $this->deny('sms_reference_conflict');
                if($bind)$bind($pdo,$pool);
                return $previous + ['created' => false];
            }
            $month = SubscriptionPolicy::month($now, $pool['timezone']);
            $period = $this->period($pdo, $pool, $month);
            $pdo->prepare('INSERT IGNORE INTO sms_budget_member_periods(pool_id,membership_id,period_start,limit_cents) VALUES(?,?,?,?)')
                ->execute([$pool['id'], $membershipId, $month, $period['member_limit_cents']]);
            $q = $pdo->prepare('SELECT * FROM sms_budget_member_periods WHERE pool_id=? AND membership_id=? AND period_start=?');
            $q->execute([$pool['id'], $membershipId, $month]);
            $member = $q->fetch();
            foreach ([$period, $member] as $budget) {
                if (!SubscriptionPolicy::budgetAllows((int)$budget['limit_cents'], (int)$budget['spent_cents'], (int)$budget['reserved_cents'], $estimatedCadCents)) $this->deny('sms_budget_exhausted');
            }
            $pdo->prepare("INSERT INTO sms_budget_entries(pool_id,period_start,membership_id,reference,estimated_cents,status) VALUES(?,?,?,?,?,'reserved')")
                ->execute([$pool['id'], $month, $membershipId, $reference, $estimatedCadCents]);
            $this->totals($pdo, (int)$pool['id'], $membershipId, $month, $estimatedCadCents, 0);
            if($bind)$bind($pdo,$pool);
            return $this->entry($pdo, (int)$pool['id'], $reference) + ['created' => true];
        });
    }

    /** Claim once BEFORE contacting the provider. A timeout retains the reservation for reconciliation. */
    public function claim(int $poolId, int $membershipId, string $reference, DateTimeImmutable $now, ?\Closure $authorize = null): bool
    {
        return $this->locked($poolId, function (PDO $pdo, array $pool) use ($poolId, $membershipId, $reference, $now, $authorize): bool {
            $this->eligible($pdo, $pool, $membershipId);
            $q = $pdo->prepare("SELECT status FROM sms_budget_members WHERE pool_id=? AND membership_id=?");
            $q->execute([$poolId, $membershipId]);
            if ($q->fetchColumn() !== 'active') $this->deny('sms_not_enrolled');
            $row = $this->ownedEntry($pdo, $poolId, $membershipId, $reference);
            if ($row['status'] !== 'reserved') return false;
            if ($row['period_start'] !== SubscriptionPolicy::month($now, $pool['timezone'])) $this->deny('sms_reservation_expired');
            // Reconciliation or shared overhead may have exhausted a cap since reservation.
            $q = $pdo->prepare('SELECT limit_cents,spent_cents,reserved_cents FROM sms_budget_periods WHERE pool_id=? AND period_start=?
                UNION ALL SELECT limit_cents,spent_cents,reserved_cents FROM sms_budget_member_periods WHERE pool_id=? AND period_start=? AND membership_id=?');
            $q->execute([$poolId, $row['period_start'], $poolId, $row['period_start'], $membershipId]);
            $budgets = $q->fetchAll();
            if (count($budgets) !== 2) $this->deny('sms_budget_exhausted');
            foreach ($budgets as $budget) {
                if (!SubscriptionPolicy::budgetAllows((int)$budget['limit_cents'], (int)$budget['spent_cents'],
                    (int)$budget['reserved_cents'] - (int)$row['estimated_cents'], (int)$row['estimated_cents'])) $this->deny('sms_budget_exhausted');
            }
            if($authorize)$authorize($pdo,$pool);
            $pdo->prepare("UPDATE sms_budget_entries SET status='submitted' WHERE id=?")->execute([$row['id']]);
            return true;
        });
    }

    /** Definitive no-send only. Never release a submitted/ambiguous provider request. */
    public function release(int $poolId, int $membershipId, string $reference): void
    {
        $this->locked($poolId, function (PDO $pdo) use ($poolId, $membershipId, $reference): void {
            $row = $this->ownedEntry($pdo, $poolId, $membershipId, $reference);
            if ($row['status'] === 'released') return;
            if ($row['status'] !== 'reserved') $this->deny('sms_invalid_transition');
            $this->totals($pdo, $poolId, $membershipId, $row['period_start'], -(int)$row['estimated_cents'], 0);
            $pdo->prepare("UPDATE sms_budget_entries SET status='released',actual_cents=0 WHERE id=?")->execute([$row['id']]);
        }, false);
    }

    /** Reconcile the original period, even after month rollover, removal or pool deactivation. */
    public function settle(int $poolId, int $membershipId, string $reference, int $actualCadCents): void
    {
        self::amount($actualCadCents, true);
        $this->locked($poolId, function (PDO $pdo) use ($poolId, $membershipId, $reference, $actualCadCents): void {
            $row = $this->ownedEntry($pdo, $poolId, $membershipId, $reference);
            if ($row['status'] === 'settled') {
                if ((int)$row['actual_cents'] !== $actualCadCents) $this->deny('sms_reference_conflict');
                return;
            }
            if ($row['status'] !== 'submitted') $this->deny('sms_invalid_transition');
            // Preserve actual costs even if the provider exceeded our conservative estimate.
            $this->totals($pdo, $poolId, $membershipId, $row['period_start'], -(int)$row['estimated_cents'], $actualCadCents);
            $pdo->prepare("UPDATE sms_budget_entries SET status='settled',actual_cents=? WHERE id=?")->execute([$actualCadCents, $row['id']]);
        }, false);
    }

    /** Number rental, taxes and other confirmed shared CAD costs also consume the clinic cap. */
    public function sharedCost(int $poolId, string $reference, int $actualCadCents, DateTimeImmutable $occurredAt): void
    {
        self::reference($reference);
        self::amount($actualCadCents, true);
        $this->locked($poolId, function (PDO $pdo, array $pool) use ($reference, $actualCadCents, $occurredAt): void {
            $month = SubscriptionPolicy::month($occurredAt, $pool['timezone']);
            $previous = $this->entry($pdo, (int)$pool['id'], $reference);
            if ($previous) {
                if ($previous['membership_id'] !== null || (int)$previous['actual_cents'] !== $actualCadCents || $previous['period_start'] !== $month) $this->deny('sms_reference_conflict');
                return;
            }
            $this->period($pdo, $pool, $month);
            $pdo->prepare("INSERT INTO sms_budget_entries(pool_id,period_start,reference,estimated_cents,actual_cents,status) VALUES(?,?,?,0,?,'settled')")
                ->execute([$pool['id'], $month, $reference, $actualCadCents]);
            $pdo->prepare('UPDATE sms_budget_periods SET spent_cents=spent_cents+? WHERE pool_id=? AND period_start=?')
                ->execute([$actualCadCents, $pool['id'], $month]);
        }, false);
    }

    private function locked(int $poolId, callable $work, bool $requireActive = true): mixed
    {
        $pdo = $this->database instanceof PDO ? $this->database : $this->database->connection();
        $own=!$pdo->inTransaction();
        if (!$own&&!$this->joinTransaction) throw new \LogicException('SMS ledger owns its transaction.');
        try {
            if($own)$pdo->beginTransaction();
            // Every mutation takes the same pool row first: both limits and cohort slots serialize.
            $q = $pdo->prepare('SELECT * FROM sms_budget_pools WHERE id=? FOR UPDATE');
            $q->execute([$poolId]);
            $pool = $q->fetch();
            if (!$pool) $this->deny('sms_pool_not_found');
            if ($requireActive && $pool['status'] !== 'active') $this->deny('sms_pool_inactive');
            $result = $work($pdo, $pool);
            if($own)$pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($own&&$pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /** Explicit audited provider correction; never rewrites estimates or the original period. */
    public function correct(int $poolId,int $member,string $reference,int $expectedActual,int $actual): void
    {
        self::amount($actual,true);self::amount($expectedActual,true);
        $this->locked($poolId,function(PDO $pdo)use($poolId,$member,$reference,$expectedActual,$actual):void{
            $row=$this->ownedEntry($pdo,$poolId,$member,$reference);
            if($row['status']!=='settled'||(int)$row['actual_cents']!==$expectedActual)$this->deny('sms_reconciliation_changed');
            $pdo->prepare('UPDATE sms_budget_periods SET spent_cents=spent_cents-?+? WHERE pool_id=? AND period_start=?')->execute([$expectedActual,$actual,$poolId,$row['period_start']]);
            $pdo->prepare('UPDATE sms_budget_member_periods SET spent_cents=spent_cents-?+? WHERE pool_id=? AND membership_id=? AND period_start=?')->execute([$expectedActual,$actual,$poolId,$member,$row['period_start']]);
            $pdo->prepare('UPDATE sms_budget_entries SET actual_cents=? WHERE id=?')->execute([$actual,$row['id']]);
        },false);
    }

    private function eligible(PDO $pdo, array $pool, int $membershipId): void
    {
        $q = $pdo->prepare("SELECT m.id FROM staff_memberships m JOIN users u ON u.id=m.local_user_id AND u.clinic_id=m.clinic_id
            JOIN clinics c ON c.id=m.clinic_id JOIN product_identities i ON i.id=m.identity_id
            JOIN practitioners p ON p.user_id=u.id
            WHERE m.id=? AND m.clinic_id=? AND m.status='active' AND u.status='active' AND u.user_type='staff'
            AND c.status='active' AND i.status='active' AND p.active=1 FOR UPDATE");
        $q->execute([$membershipId, $pool['clinic_id']]);
        if (!$q->fetchColumn()) $this->deny('sms_membership_ineligible');
    }

    private function period(PDO $pdo, array $pool, string $month): array
    {
        $pdo->prepare('INSERT IGNORE INTO sms_budget_periods(pool_id,period_start,limit_cents,member_limit_cents) VALUES(?,?,?,?)')
            ->execute([$pool['id'], $month, $pool['monthly_limit_cents'], $pool['member_limit_cents']]);
        $q = $pdo->prepare('SELECT * FROM sms_budget_periods WHERE pool_id=? AND period_start=?');
        $q->execute([$pool['id'], $month]);
        return $q->fetch();
    }

    private function totals(PDO $pdo, int $poolId, int $member, string $month, int $reservedDelta, int $spentDelta): void
    {
        $pdo->prepare('UPDATE sms_budget_periods SET reserved_cents=reserved_cents+?,spent_cents=spent_cents+? WHERE pool_id=? AND period_start=?')
            ->execute([$reservedDelta, $spentDelta, $poolId, $month]);
        $pdo->prepare('UPDATE sms_budget_member_periods SET reserved_cents=reserved_cents+?,spent_cents=spent_cents+? WHERE pool_id=? AND membership_id=? AND period_start=?')
            ->execute([$reservedDelta, $spentDelta, $poolId, $member, $month]);
    }

    private function entry(PDO $pdo, int $poolId, string $reference): array|false
    {
        self::reference($reference);
        $q = $pdo->prepare('SELECT * FROM sms_budget_entries WHERE pool_id=? AND reference=?');
        $q->execute([$poolId, $reference]);
        return $q->fetch();
    }

    private function ownedEntry(PDO $pdo, int $poolId, int $member, string $reference): array
    {
        $row = $this->entry($pdo, $poolId, $reference);
        if (!$row || $row['membership_id'] === null || (int)$row['membership_id'] !== $member) $this->deny('sms_entry_not_found');
        return $row;
    }

    private static function reference(string $reference): void
    {
        if (!preg_match('/^[a-zA-Z0-9:_-]{1,128}$/D', $reference)) throw new ApiException(422, 'sms_reference_invalid', 'Use an opaque delivery reference.');
    }

    private static function amount(int $cents, bool $zeroAllowed): void
    {
        if ($cents < ($zeroAllowed ? 0 : 1) || $cents > 100000000) throw new ApiException(422, 'sms_amount_invalid', 'Enter an exact CAD-cent amount within the supported range.');
    }

    private function deny(string $code): never
    {
        throw new ApiException(409, $code, 'The SMS accounting operation cannot be completed.');
    }
}
