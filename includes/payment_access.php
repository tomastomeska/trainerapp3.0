<?php

function paymentAccessEnsureSchema(PDO $pdo): void
{
    $columns = [
        ['coaches', 'payment_access_default', 'TINYINT(1) NOT NULL DEFAULT 1'],
        ['athletes', 'payment_access_override', 'TINYINT(1) NULL DEFAULT NULL'],
    ];
    foreach ($columns as [$table, $column, $definition]) {
        $stmt = $pdo->query("SHOW COLUMNS FROM {$table} LIKE '{$column}'");
        if ($stmt !== false && $stmt->fetch()) {
            continue;
        }
        try {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        } catch (PDOException $e) {
            // Another request may have added the same column after the check.
            if ((int)($e->errorInfo[1] ?? 0) !== 1060) {
                throw $e;
            }
        }
    }
}

function paymentAccessIsEnabled(PDO $pdo, int $athleteId, int $coachId): bool
{
    paymentAccessEnsureSchema($pdo);
    $stmt = $pdo->prepare(
        'SELECT COALESCE(a.payment_access_override, c.payment_access_default) AS payment_service_enabled
         FROM athletes a
         JOIN coaches c ON c.id = a.coach_id
         WHERE a.id = ? AND a.coach_id = ?
         LIMIT 1'
    );
    $stmt->execute([$athleteId, $coachId]);
    $row = $stmt->fetch();

    if (!$row) {
        throw new RuntimeException('Sportovec nebyl nalezen u tohoto trenéra.');
    }

    return (int)$row['payment_service_enabled'] === 1;
}

function paymentAccessSetAll(PDO $pdo, int $coachId, bool $enabled): void
{
    paymentAccessEnsureSchema($pdo);
    $pdo->beginTransaction();
    try {
        $coachStmt = $pdo->prepare('UPDATE coaches SET payment_access_default = ? WHERE id = ?');
        $coachStmt->execute([$enabled ? 1 : 0, $coachId]);

        $athletesStmt = $pdo->prepare(
            'UPDATE athletes SET payment_access_override = NULL WHERE coach_id = ?'
        );
        $athletesStmt->execute([$coachId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function paymentAccessSetAthlete(PDO $pdo, int $athleteId, int $coachId, bool $enabled): void
{
    paymentAccessEnsureSchema($pdo);
    $stmt = $pdo->prepare(
        'UPDATE athletes
         SET payment_access_override = ?
         WHERE id = ? AND coach_id = ?'
    );
    $stmt->execute([$enabled ? 1 : 0, $athleteId, $coachId]);

    $existsStmt = $pdo->prepare('SELECT 1 FROM athletes WHERE id = ? AND coach_id = ? LIMIT 1');
    $existsStmt->execute([$athleteId, $coachId]);
    if (!$existsStmt->fetchColumn()) {
        throw new RuntimeException('Sportovec nebyl nalezen u tohoto trenéra.');
    }
}
