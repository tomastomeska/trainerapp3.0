<?php

function calendarMigrateReplacementRevocation(PDO $pdo): void
{
    foreach ([
        'replacement_revoked_at' => 'DATETIME NULL',
        'replacement_revoked_by_coach_id' => 'INT NULL',
        'replacement_revocation_reason' => 'VARCHAR(1000) NULL',
    ] as $column => $definition) {
        $columnStmt = $pdo->query('SHOW COLUMNS FROM coach_calendar_event_cancellations LIKE ' . $pdo->quote($column));
        if (!$columnStmt->fetch()) {
            $pdo->exec('ALTER TABLE coach_calendar_event_cancellations ADD COLUMN `' . $column . '` ' . $definition);
        }
    }
}

function calendarReplacementState(array $row, DateTimeImmutable $now): array
{
    if (!empty($row['replacement_revoked_at'])) {
        return ['key' => 'revoked', 'label' => 'Nárok zrušen trenérem', 'color' => 'secondary', 'priority' => 5];
    }
    if (empty($row['replacement_required'])) {
        return ['key' => 'none', 'label' => 'Bez náhrady', 'color' => 'secondary', 'priority' => 5];
    }
    if (!empty($row['replacement_event_id'])) {
        if (empty($row['replacement_id'])) {
            return ['key' => 'missing', 'label' => 'Náhradní slot chybí', 'color' => 'danger', 'priority' => 0];
        }
        if (($row['replacement_approval_status'] ?? '') === 'pending') {
            return ['key' => 'pending', 'label' => 'Čeká na schválení', 'color' => 'warning', 'priority' => 1];
        }
        return ['key' => 'approved', 'label' => 'Náhrada schválena', 'color' => 'success', 'priority' => 4];
    }
    $deadline = !empty($row['replacement_deadline_at'])
        ? new DateTimeImmutable((string)$row['replacement_deadline_at'])
        : null;
    if ($deadline === null) {
        return ['key' => 'missing', 'label' => 'Chybí lhůta náhrady', 'color' => 'danger', 'priority' => 0];
    }
    if ($deadline <= $now) {
        return ['key' => 'expired', 'label' => 'Lhůta propadla', 'color' => 'secondary', 'priority' => 3];
    }
    if ($deadline <= $now->modify('+48 hours')) {
        return ['key' => 'soon', 'label' => 'Končí do 48 hodin', 'color' => 'danger', 'priority' => 0];
    }
    return ['key' => 'open', 'label' => 'Čeká na výběr slotu', 'color' => 'warning', 'priority' => 2];
}

function calendarRevokeReplacement(PDO $pdo, int $coachId, int $cancellationId, string $reason): void
{
    $reason = trim($reason);
    if ($cancellationId <= 0 || $reason === '' || mb_strlen($reason, 'UTF-8') > 1000) {
        throw new RuntimeException('Vyplňte důvod zrušení nároku (nejvýše 1000 znaků).');
    }
    try {
        $pdo->beginTransaction();
        $claimStmt = $pdo->prepare(
            'SELECT id, replacement_required, replacement_event_id, replacement_revoked_at
             FROM coach_calendar_event_cancellations
             WHERE id = ? AND coach_id = ? FOR UPDATE'
        );
        $claimStmt->execute([$cancellationId, $coachId]);
        $claim = $claimStmt->fetch(PDO::FETCH_ASSOC);
        if (!$claim || empty($claim['replacement_required']) || !empty($claim['replacement_revoked_at'])) {
            throw new RuntimeException('Nárok není dostupný nebo už byl zrušen.');
        }
        if (!empty($claim['replacement_event_id'])) {
            $eventStmt = $pdo->prepare(
                'SELECT id FROM coach_calendar_events WHERE id = ? AND coach_id = ? FOR UPDATE'
            );
            $eventStmt->execute([(int)$claim['replacement_event_id'], $coachId]);
            if ($eventStmt->fetchColumn()) {
                throw new RuntimeException('Nárok má stále existující náhradní slot. Nejprve jej zkontrolujte v kalendáři.');
            }
        }
        $updateStmt = $pdo->prepare(
            'UPDATE coach_calendar_event_cancellations
             SET replacement_required = 0, replacement_revoked_at = NOW(),
                 replacement_revoked_by_coach_id = ?, replacement_revocation_reason = ?
             WHERE id = ? AND coach_id = ? AND replacement_required = 1 AND replacement_revoked_at IS NULL'
        );
        $updateStmt->execute([$coachId, $reason, $cancellationId, $coachId]);
        if ($updateStmt->rowCount() !== 1) {
            throw new RuntimeException('Stav nároku se mezitím změnil. Obnovte přehled.');
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function calendarReplacementRemaining(string $deadline, DateTimeImmutable $now): string
{
    $seconds = (new DateTimeImmutable($deadline))->getTimestamp() - $now->getTimestamp();
    if ($seconds <= 0) {
        return 'Lhůta uplynula';
    }
    $minutes = (int)ceil($seconds / 60);
    $days = intdiv($minutes, 1440);
    $hours = intdiv($minutes % 1440, 60);
    $remainingMinutes = $minutes % 60;
    return $days > 0
        ? $days . ' d ' . $hours . ' h'
        : ($hours > 0 ? $hours . ' h ' . $remainingMinutes . ' min' : $remainingMinutes . ' min');
}