<?php

function coachAccountEnsureSchema(PDO $pdo): void
{
    $columns = [
        'inactivity_deletion_notified_at' => 'DATETIME NULL DEFAULT NULL',
        'inactivity_deletion_due_at' => 'DATETIME NULL DEFAULT NULL',
        'block_reason' => "ENUM('terms_violation') NULL DEFAULT NULL",
    ];

    foreach ($columns as $column => $definition) {
        $stmt = $pdo->query("SHOW COLUMNS FROM coaches LIKE " . $pdo->quote($column));
        if ($stmt === false || !$stmt->fetch()) {
            try {
                $pdo->exec("ALTER TABLE coaches ADD COLUMN `{$column}` {$definition}");
            } catch (PDOException $e) {
                if ((int)($e->errorInfo[1] ?? 0) !== 1060) {
                    throw $e;
                }
            }
        }
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS coach_account_backups (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            coach_id INT NOT NULL,
            coach_username VARCHAR(120) NOT NULL,
            coach_email VARCHAR(255) NULL,
            deleted_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            backup_sql LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_coach_account_backups_expires_at (expires_at),
            KEY idx_coach_account_backups_coach_id (coach_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function coachAccountSqlValue(PDO $pdo, mixed $value): string
{
    return $value === null ? 'NULL' : $pdo->quote((string)$value);
}

function coachAccountExportRows(PDO $pdo, string $table, array $rows, array &$lines): void
{
    if (!$rows) {
        $lines[] = '-- Tabulka ' . $table . ' neobsahuje žádná data.';
        $lines[] = '';
        return;
    }

    $lines[] = '-- Tabulka: ' . $table;
    foreach ($rows as $row) {
        $columns = implode(', ', array_map(
            static fn(string $column): string => '`' . str_replace('`', '``', $column) . '`',
            array_keys($row)
        ));
        $values = implode(', ', array_map(
            static fn(mixed $value): string => coachAccountSqlValue($pdo, $value),
            array_values($row)
        ));
        $lines[] = "INSERT INTO `{$table}` ({$columns}) VALUES ({$values});";
    }
    $lines[] = '';
}

function coachAccountBuildSqlBackup(PDO $pdo, int $coachId): array
{
    $stmt = $pdo->prepare('SELECT * FROM coaches WHERE id = ?');
    $stmt->execute([$coachId]);
    $coach = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$coach) {
        throw new RuntimeException('Trenér určený k záloze již neexistuje.');
    }

    $lines = [
        '-- Záloha dat trenéra: ' . $coach['username'],
        '-- Exportováno: ' . date('d.m.Y H:i:s'),
        'SET NAMES utf8mb4;',
        'SET FOREIGN_KEY_CHECKS = 0;',
        '',
        '-- Trenér',
    ];
    coachAccountExportRows($pdo, 'coaches', [$coach], $lines);

    $athleteStmt = $pdo->prepare('SELECT * FROM athletes WHERE coach_id = ?');
    $athleteStmt->execute([$coachId]);
    $athletes = $athleteStmt->fetchAll(PDO::FETCH_ASSOC);
    $lines[] = '-- Sportovci';
    coachAccountExportRows($pdo, 'athletes', $athletes, $lines);

    $exerciseStmt = $pdo->prepare('SELECT * FROM exercises WHERE coach_id = ?');
    $exerciseStmt->execute([$coachId]);
    $exercises = $exerciseStmt->fetchAll(PDO::FETCH_ASSOC);
    $lines[] = '-- Cviky';
    coachAccountExportRows($pdo, 'exercises', $exercises, $lines);

    $setStmt = $pdo->prepare('SELECT * FROM workout_sets WHERE coach_id = ?');
    $setStmt->execute([$coachId]);
    $sets = $setStmt->fetchAll(PDO::FETCH_ASSOC);
    $lines[] = '-- Tréninkové sady';
    coachAccountExportRows($pdo, 'workout_sets', $sets, $lines);

    $setIds = array_column($sets, 'id');
    if ($setIds) {
        $placeholders = implode(',', array_fill(0, count($setIds), '?'));
        $setExerciseStmt = $pdo->prepare(
            "SELECT * FROM workout_set_exercises WHERE workout_set_id IN ({$placeholders})"
        );
        $setExerciseStmt->execute($setIds);
        $lines[] = '-- Cviky v sadách';
        coachAccountExportRows($pdo, 'workout_set_exercises', $setExerciseStmt->fetchAll(PDO::FETCH_ASSOC), $lines);
    }

    $athleteIds = array_column($athletes, 'id');
    if ($athleteIds) {
        $placeholders = implode(',', array_fill(0, count($athleteIds), '?'));
        $sessionStmt = $pdo->prepare(
            "SELECT * FROM training_sessions WHERE athlete_id IN ({$placeholders})"
        );
        $sessionStmt->execute($athleteIds);
        $sessions = $sessionStmt->fetchAll(PDO::FETCH_ASSOC);
        $lines[] = '-- Tréninkové záznamy';
        coachAccountExportRows($pdo, 'training_sessions', $sessions, $lines);

        $sessionIds = array_column($sessions, 'id');
        if ($sessionIds) {
            $placeholders = implode(',', array_fill(0, count($sessionIds), '?'));
            $seriesStmt = $pdo->prepare(
                "SELECT * FROM session_series WHERE session_id IN ({$placeholders})"
            );
            $seriesStmt->execute($sessionIds);
            $lines[] = '-- Série cviků';
            coachAccountExportRows($pdo, 'session_series', $seriesStmt->fetchAll(PDO::FETCH_ASSOC), $lines);
        }
    }

    $lines[] = 'SET FOREIGN_KEY_CHECKS = 1;';
    return [
        'coach' => $coach,
        'sql' => implode("\n", $lines) . "\n",
    ];
}

function coachAccountStoreBackup(PDO $pdo, int $coachId, array $backup): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO coach_account_backups
            (coach_id, coach_username, coach_email, deleted_at, expires_at, backup_sql)
         VALUES (?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 1 YEAR), ?)'
    );
    $stmt->execute([
        $coachId,
        (string)$backup['coach']['username'],
        $backup['coach']['email'] ?: null,
        $backup['sql'],
    ]);
    return (int)$pdo->lastInsertId();
}

function coachAccountArchiveAndDelete(PDO $pdo, int $coachId): int
{
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT id FROM coaches WHERE id = ? FOR UPDATE');
        $stmt->execute([$coachId]);
        if (!$stmt->fetchColumn()) {
            throw new RuntimeException('Trenér určený ke smazání již neexistuje.');
        }

        $backup = coachAccountBuildSqlBackup($pdo, $coachId);
        $backupId = coachAccountStoreBackup($pdo, $coachId, $backup);
        $deleteStmt = $pdo->prepare('DELETE FROM coaches WHERE id = ?');
        $deleteStmt->execute([$coachId]);
        if ($deleteStmt->rowCount() !== 1) {
            throw new RuntimeException('Trenéra se nepodařilo smazat po vytvoření zálohy.');
        }

        $pdo->commit();
        return $backupId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function coachAccountProcessLifecycle(PDO $pdo): array
{
    coachAccountEnsureSchema($pdo);
    $pdo->exec('DELETE FROM coach_account_backups WHERE expires_at <= NOW()');

    $noticesSent = 0;
    $noticeFailures = 0;
    $lastNoticeId = 0;
    do {
        $noticeStmt = $pdo->prepare(
            "SELECT id
             FROM coaches
             WHERE id > ? AND is_active = 1
               AND email IS NOT NULL AND email <> ''
               AND inactivity_deletion_due_at IS NULL
               AND COALESCE(last_login, created_at) <= DATE_SUB(NOW(), INTERVAL 60 DAY)
             ORDER BY id
             LIMIT 100"
        );
        $noticeStmt->execute([$lastNoticeId]);
        $candidates = $noticeStmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($candidates as $candidateId) {
            $lastNoticeId = (int)$candidateId;
            $pdo->beginTransaction();
            try {
                $lockStmt = $pdo->prepare(
                    "SELECT id, name, username, email
                     FROM coaches
                     WHERE id = ? AND is_active = 1
                       AND email IS NOT NULL AND email <> ''
                       AND inactivity_deletion_due_at IS NULL
                       AND COALESCE(last_login, created_at) <= DATE_SUB(NOW(), INTERVAL 60 DAY)
                     FOR UPDATE"
                );
                $lockStmt->execute([$lastNoticeId]);
                $coach = $lockStmt->fetch(PDO::FETCH_ASSOC);
                if (!$coach) {
                    $pdo->rollBack();
                    continue;
                }

                $coachName = (string)($coach['name'] ?: $coach['username']);
                $safeName = htmlspecialchars($coachName, ENT_QUOTES, 'UTF-8');
                $loginUrl = rtrim((string)BASE_URL, '/') . '/login.php';
                $safeUrl = htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8');
                $html = '<!doctype html><html lang="cs"><meta charset="utf-8"><body>'
                    . '<p>Dobrý den, ' . $safeName . ',</p>'
                    . '<p>Váš účet trenéra v aplikaci TrainerApp je dlouhodobě neaktivní. '
                    . 'Pokud se do 14 dnů (336 hodin) nepřihlásíte, účet a data jeho sportovců budou smazány.</p>'
                    . '<p>Přihlášením během této lhůty se upozornění i plánované smazání zruší.</p>'
                    . '<p><a href="' . $safeUrl . '">Přihlásit se do TrainerApp</a></p>'
                    . '<p>S pozdravem,<br>Administrace TrainerApp</p></body></html>';
                $text = "Dobrý den, {$coachName},\n\n"
                    . "Váš účet trenéra v aplikaci TrainerApp je dlouhodobě neaktivní. "
                    . "Pokud se do 14 dnů (336 hodin) nepřihlásíte, účet a data jeho sportovců budou smazány.\n"
                    . "Přihlášením během této lhůty se upozornění i plánované smazání zruší.\n\n"
                    . "Přihlášení: {$loginUrl}\n\nAdministrace TrainerApp";

                if (!coachAccountSendEmail((string)$coach['email'], 'Upozornění na neaktivní účet TrainerApp', $html, $text)) {
                    $pdo->rollBack();
                    $noticeFailures++;
                    continue;
                }

                $updateStmt = $pdo->prepare(
                    'UPDATE coaches
                     SET inactivity_deletion_notified_at = NOW(),
                         inactivity_deletion_due_at = DATE_ADD(NOW(), INTERVAL 336 HOUR)
                     WHERE id = ? AND is_active = 1 AND inactivity_deletion_due_at IS NULL'
                );
                $updateStmt->execute([$lastNoticeId]);
                $noticesSent += $updateStmt->rowCount();
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        }
    } while (count($candidates) === 100);

    $deletions = 0;
    $deletionFailures = [];
    $lastDeletionId = 0;
    do {
        $deletionStmt = $pdo->prepare(
            'SELECT id
             FROM coaches
             WHERE id > ? AND is_active = 1 AND inactivity_deletion_due_at <= NOW()
             ORDER BY id
             LIMIT 100'
        );
        $deletionStmt->execute([$lastDeletionId]);
        $candidates = $deletionStmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($candidates as $candidateId) {
            $lastDeletionId = (int)$candidateId;
            $pdo->beginTransaction();
            try {
                $lockStmt = $pdo->prepare(
                    'SELECT id FROM coaches
                     WHERE id = ? AND is_active = 1 AND inactivity_deletion_due_at <= NOW()
                     FOR UPDATE'
                );
                $lockStmt->execute([$lastDeletionId]);
                if (!$lockStmt->fetchColumn()) {
                    $pdo->rollBack();
                    continue;
                }

                $backup = coachAccountBuildSqlBackup($pdo, $lastDeletionId);
                coachAccountStoreBackup($pdo, $lastDeletionId, $backup);
                $deleteStmt = $pdo->prepare(
                    'DELETE FROM coaches
                     WHERE id = ? AND is_active = 1 AND inactivity_deletion_due_at <= NOW()'
                );
                $deleteStmt->execute([$lastDeletionId]);
                if ($deleteStmt->rowCount() !== 1) {
                    throw new RuntimeException('Trenéra se nepodařilo smazat po vytvoření zálohy.');
                }
                $pdo->commit();
                $deletions++;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Coach account deletion failed for #' . $lastDeletionId . ': ' . $e->getMessage());
                $deletionFailures[] = [
                    'coach_id' => $lastDeletionId,
                    'error' => $e->getMessage(),
                ];
            }
        }
    } while (count($candidates) === 100);

    return [
        'notices_sent' => $noticesSent,
        'notice_failures' => $noticeFailures,
        'accounts_deleted' => $deletions,
        'deletion_failures' => $deletionFailures,
    ];
}

function coachAccountSendEmail(string $toEmail, string $subject, string $htmlBody, string $textBody): bool
{
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        error_log('coach account lifecycle email: missing or invalid recipient');
        return false;
    }

    $phpmailerSrc = dirname(__DIR__) . '/vendor/phpmailer/phpmailer/src';
    if (!file_exists($phpmailerSrc . '/PHPMailer.php')) {
        error_log('coach account lifecycle email: PHPMailer not found');
        return false;
    }

    require_once $phpmailerSrc . '/Exception.php';
    require_once $phpmailerSrc . '/PHPMailer.php';
    require_once $phpmailerSrc . '/SMTP.php';

    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USER;
        $mail->Password = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = SMTP_PORT;
        $mail->Timeout = max(3, (int)(defined('SMTP_TIMEOUT') ? SMTP_TIMEOUT : 8));
        $mail->CharSet = 'UTF-8';
        $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
        $mail->addAddress($toEmail);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;
        $mail->AltBody = $textBody;
        $mail->send();
        return true;
    } catch (Throwable $e) {
        error_log('coach account lifecycle email failed: ' . $e->getMessage());
        return false;
    }
}

function coachAccountSendBlockNotification(string $toEmail, string $coachName, string $reason): bool
{
    $reasonLabels = [
        'terms_violation' => 'Porušení všeobecných podmínek',
    ];
    if (!isset($reasonLabels[$reason])) {
        throw new InvalidArgumentException('Neplatný důvod blokace trenéra.');
    }

    $safeName = htmlspecialchars($coachName, ENT_QUOTES, 'UTF-8');
    $reasonLabel = $reasonLabels[$reason];
    $safeReason = htmlspecialchars($reasonLabel, ENT_QUOTES, 'UTF-8');
    $html = '<!doctype html><html lang="cs"><meta charset="utf-8"><body>'
        . '<p>Dobrý den, ' . $safeName . ',</p>'
        . '<p>Váš účet trenéra v aplikaci TrainerApp byl zablokován z důvodu: '
        . '<strong>' . $safeReason . '</strong>.</p>'
        . '<p>Pro odblokování kontaktujte podporu.</p>'
        . '<p>S pozdravem,<br>Administrace TrainerApp</p></body></html>';
    $text = "Dobrý den, {$coachName},\n\n"
        . "Váš účet trenéra v aplikaci TrainerApp byl zablokován z důvodu: {$reasonLabel}.\n"
        . "Pro odblokování kontaktujte podporu.\n\nAdministrace TrainerApp";

    return coachAccountSendEmail($toEmail, 'Zablokování účtu TrainerApp', $html, $text);
}
