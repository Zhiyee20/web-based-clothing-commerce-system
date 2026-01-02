<?php
class RewardsService {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function getBalance(int $userId): int {
        $stmt = $this->pdo->prepare("SELECT Balance FROM reward_points WHERE UserID = ?");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int)$row['Balance'] : 0;
    }

    /** Lifetime earned (accumulative), accounting for auto-reversals */
    public function getAccumulativePoints(int $userId): int {
        $stmt = $this->pdo->prepare("
            SELECT COALESCE(
                SUM(
                    CASE
                      WHEN Type = 'EARN' THEN Points
                      WHEN Type = 'AUTO_REVERSAL_EARN' THEN -Points
                      ELSE 0
                    END
                ), 0
            ) AS Accum
            FROM reward_ledger
            WHERE UserID = ?
        ");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int)$row['Accum'] : 0;
    }

    /**
     * Settings derived from tier table:
     * - ConversionRate comes from reward_tiers by accumulative points (NOT balance).
     * - No minimum redeem rule anymore.
     * - Earning rule kept as 1 pt per RM (adjust if you need).
     */
    public function getSettings(int $userId): array {
        $pointPerRM = 1.0; // earning
        $rate = 0.01;      // default (fallback)

        $accum = $this->getAccumulativePoints($userId); // lifetime earned
        $stmt = $this->pdo->prepare("
            SELECT ConversionRate
            FROM reward_tiers
            WHERE ? BETWEEN MinPoints AND MaxPoints
            LIMIT 1
        ");
        $stmt->execute([$accum]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && isset($row['ConversionRate'])) {
            $rate = (float)$row['ConversionRate'];
        }

        return [
            'PointPerRM'     => $pointPerRM,
            'ConversionRate' => $rate,
        ];
    }

    private function ensureUserRow(int $userId): void {
        $this->pdo->prepare("INSERT IGNORE INTO reward_points (UserID, Balance) VALUES (?, 0)")
                  ->execute([$userId]);
    }

    public function earnPoints(int $userId, int $points, ?int $refOrderId = null): void {
        if ($points <= 0) return;
        $this->ensureUserRow($userId);
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare("UPDATE reward_points SET Balance = Balance + ? WHERE UserID = ?")
                      ->execute([$points, $userId]);
            // reward_ledger has no Note column
            $this->pdo->prepare("INSERT INTO reward_ledger (UserID, Type, Points, RefOrderID)
                                 VALUES (?, 'EARN', ?, ?)")
                      ->execute([$userId, $points, $refOrderId]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function redeemPoints(int $userId, int $points, ?int $refOrderId = null): void {
        if ($points <= 0) return;
        $this->ensureUserRow($userId);
        $balance = $this->getBalance($userId);
        if ($points > $balance) throw new \RuntimeException("Insufficient points.");

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare("UPDATE reward_points SET Balance = Balance - ? WHERE UserID = ?")
                      ->execute([$points, $userId]);
            $this->pdo->prepare("INSERT INTO reward_ledger (UserID, Type, Points, RefOrderID)
                                 VALUES (?, 'REDEEM', ?, ?)")
                      ->execute([$userId, $points, $refOrderId]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function computeEarnablePoints(float $orderTotal): int {
        return (int) floor($orderTotal * 1.0); // 1 pt per RM
    }

    public function computeDiscountFromPointsForUser(int $userId, int $points): float {
        $rate = (float)$this->getSettings($userId)['ConversionRate'];
        return round($points * $rate, 2);
    }
}
