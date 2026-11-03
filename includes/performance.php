<?php
declare(strict_types=1);
require_once __DIR__ . '/calculators.php';
require_once __DIR__ . '/performance_calc.php';
require_once __DIR__ . '/performance_schema.php';
require_once __DIR__ . '/coach_intelligence.php';

function performanceJson(array $value): string {
    return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
}

function performanceValidateInput(array $input): void {
    foreach ($input as $key => $value) {
        if ($key === 'segments' && is_array($value)) {
            foreach ($value as $v) if (!is_scalar($v)) throw new InvalidArgumentException('Neplatný formát mezičasů.');
        } elseif (!is_scalar($value) && $value !== null) throw new InvalidArgumentException('Neplatný formát vstupu: ' . $key);
    }
}

function performanceRows(PDO $pdo, string $sql, array $params = []): array {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function performanceHas(array $tables, string $table, array $columns): bool {
    foreach ($columns as $column) if (!isset($tables[$table][$column])) return false;
    return true;
}

function performanceSettings(PDO $pdo, int $athleteId): array {
    $row = performanceRows($pdo, 'SELECT intelligence_enabled, share_performance, share_recovery FROM performance_settings WHERE athlete_id = ?', [$athleteId])[0] ?? [];
    return [
        'intelligence_enabled' => !empty($row['intelligence_enabled']),
        'share_performance' => !empty($row['share_performance']),
        'share_recovery' => !empty($row['share_recovery']),
    ];
}

function performanceViewer(PDO $pdo, string $type, int $actorId, int $athleteId): array {
    if (!in_array($type, ['athlete', 'coach'], true) || $actorId <= 0 || $athleteId <= 0) throw new DomainException('Neplatný uživatel.');
    if (!calculatorAppVisibleForUser($type, $actorId) || !calculatorIsEnabled('performance')) throw new DomainException('Modul není pro tento účet dostupný.');
    $sql = 'SELECT id, coach_id, first_name, last_name, birth_date FROM athletes WHERE id = ?';
    $params = [$athleteId];
    if ($type === 'athlete') {
        if ($actorId !== $athleteId) throw new DomainException('K tomuto sportovci nemáte přístup.');
    } else {
        $sql .= ' AND coach_id = ?';
        $params[] = $actorId;
    }
    $athlete = performanceRows($pdo, $sql, $params)[0] ?? null;
    if (!$athlete) throw new DomainException('Sportovec nebyl nalezen.');
    if (!performanceSchemaReady($pdo)) throw new RuntimeException('Modul vyžaduje migraci scripts\\migrate_performance.php. Kontaktujte administrátora.');
    return ['type' => $type, 'actor_id' => $actorId, 'athlete_id' => $athleteId, 'athlete' => $athlete, 'settings' => performanceSettings($pdo, $athleteId)];
}

function performanceCan(array $viewer, string $module): bool {
    if (!isset(performanceModules()[$module]) || !calculatorIsEnabled('performance_' . $module)) return false;
    if ($viewer['type'] === 'athlete') return true;
    return $module === 'recovery' ? $viewer['settings']['share_recovery'] : $viewer['settings']['share_performance'];
}

function performanceRequireModule(array $viewer, string $module): void {
    if (!performanceCan($viewer, $module)) throw new DomainException('Funkce je vypnutá nebo sportovec tuto oblast nesdílí.');
}

function performanceUserId(PDO $pdo, int $athleteId, bool $create = false): ?int {
    $row = performanceRows($pdo, 'SELECT id FROM mycoach_users WHERE athlete_id = ?', [$athleteId])[0] ?? null;
    if ($row) return (int)$row['id'];
    if (!$create) return null;
    $pdo->prepare("INSERT INTO mycoach_users (athlete_id, role) VALUES (?, 'athlete') ON DUPLICATE KEY UPDATE athlete_id = VALUES(athlete_id)")->execute([$athleteId]);
    $row = performanceRows($pdo, 'SELECT id FROM mycoach_users WHERE athlete_id = ?', [$athleteId])[0] ?? null;
    if (!$row) throw new RuntimeException('Nepodařilo se navázat existující profil sportovce.');
    return (int)$row['id'];
}

function performanceContext(PDO $pdo, array $viewer, string $date): array {
    $date = performanceDate($date);
    if ($date > date('Y-m-d')) throw new InvalidArgumentException('Přehled ani hodnocení nelze vytvářet pro budoucí den.');
    $t = performanceSchemaInventory($pdo);
    $id = $viewer['athlete_id'];
    $from = (new DateTimeImmutable($date))->modify('-27 days')->format('Y-m-d');
    $end = (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d');
    $context = [
        'date' => $date, 'settings' => $viewer['settings'], 'weight' => null, 'fluids' => null,
        'activities' => [], 'daily' => [], 'daily_source' => null, 'warnings' => [], 'upcoming' => [], 'sweat' => null,
        'available_modules' => array_values(array_filter(array_keys(performanceModules()), static fn($m) => performanceCan($viewer, $m))),
        'latest_results' => [],
    ];
    $performance = $viewer['type'] === 'athlete' || $viewer['settings']['share_performance'];
    $recovery = ($viewer['type'] === 'athlete' || $viewer['settings']['share_recovery']) && calculatorIsEnabled('performance_recovery');
    if (performanceCan($viewer, 'hydration')) {
        if (performanceHas($t, 'athlete_weight_logs', ['id', 'athlete_id', 'measured_at', 'weight_kg'])) {
            $w = performanceRows($pdo, 'SELECT id, measured_at, weight_kg FROM athlete_weight_logs WHERE athlete_id = ? AND measured_at <= ? ORDER BY measured_at DESC, id DESC LIMIT 1', [$id, $date])[0] ?? null;
            if ($w) $context['weight'] = ['kg' => (float)$w['weight_kg'], 'date' => $w['measured_at'], 'source' => 'athlete_weight_logs:' . $w['id']];
        } else $context['warnings'][] = 'Historie vážení není dostupná.';
        if (performanceHas($t, 'food_diary_days', ['id', 'athlete_id', 'date', 'hydration_ml'])) {
            $day = performanceRows($pdo, 'SELECT id, hydration_ml FROM food_diary_days WHERE athlete_id = ? AND date = ?', [$id, $date])[0] ?? null;
            if ($day) {
                if (performanceHas($t, 'food_diary_hydration_entries', ['day_id', 'drink_type', 'amount_ml'])) {
                    $drinks = performanceRows($pdo, 'SELECT drink_type, SUM(amount_ml) AS ml FROM food_diary_hydration_entries WHERE day_id = ? GROUP BY drink_type', [$day['id']]);
                    $total = $alcohol = $nonAlcohol = $unclassified = 0;
                    foreach ($drinks as $drink) {
                        $total += (int)$drink['ml'];
                        if (in_array($drink['drink_type'], ['beer', 'spirits'], true)) $alcohol += (int)$drink['ml'];
                        elseif (in_array($drink['drink_type'], ['water', 'sweet_drink', 'coffee', 'tea', 'protein'], true)) $nonAlcohol += (int)$drink['ml'];
                        else $unclassified += (int)$drink['ml'];
                    }
                    $context['fluids'] = ['day_id' => (int)$day['id'], 'total_ml' => $drinks ? $total : ($day['hydration_ml'] !== null ? (int)$day['hydration_ml'] : null), 'alcohol_ml' => $alcohol, 'non_alcohol_ml' => $drinks ? $nonAlcohol : null, 'unclassified_ml' => $unclassified, 'source' => $drinks ? 'entries' : 'legacy-total'];
                    if ($unclassified > 0) $context['warnings'][] = 'Vlastní nebo neznámé nápoje nelze rozlišit na alkoholické a nealkoholické; nejsou započtené jako potvrzený nealkoholický příjem.';
                } else {
                    $context['warnings'][] = 'Dávky nápojů nejsou dostupné, použit pouze starší denní součet.';
                    $context['fluids'] = ['day_id' => (int)$day['id'], 'total_ml' => $day['hydration_ml'] !== null ? (int)$day['hydration_ml'] : null, 'alcohol_ml' => 0, 'non_alcohol_ml' => null, 'source' => 'legacy-total'];
                }
            }
        } else $context['warnings'][] = 'Evidence nápojů není dostupná.';
        $test = performanceRows($pdo, 'SELECT id, entry_date, results_json FROM athlete_sweat_tests WHERE athlete_id = ? AND entry_date <= ? ORDER BY entry_date DESC, id DESC LIMIT 1', [$id, $date])[0] ?? null;
        if ($test) $context['sweat'] = ['source' => 'athlete_sweat_tests:' . $test['id'], 'date' => $test['entry_date'], 'result' => json_decode($test['results_json'], true, 512, JSON_THROW_ON_ERROR)];
        elseif (performanceHas($t, 'athlete_analyses', ['id', 'athlete_id', 'status', 'inputs_json', 'completed_at'])) {
            $legacy = performanceRows($pdo, "SELECT id, inputs_json, completed_at FROM athlete_analyses WHERE athlete_id = ? AND status = 'final' AND completed_at < ? ORDER BY completed_at DESC, id DESC LIMIT 1", [$id, $end])[0] ?? null;
            if ($legacy) {
                $inputs = json_decode($legacy['inputs_json'] ?? '{}', true, 512, JSON_THROW_ON_ERROR);
                if (!empty($inputs['sweat_rate_l_h'])) $context['sweat'] = ['source' => 'athlete_analyses:' . $legacy['id'], 'date' => substr($legacy['completed_at'], 0, 10), 'result' => ['rate_l_h' => (float)$inputs['sweat_rate_l_h']]];
            }
        }
        if (performanceHas($t, 'coach_calendar_events', ['id', 'athlete_id', 'second_athlete_id', 'approval_status', 'title_type', 'starts_at', 'ends_at'])) {
            $context['upcoming'] = performanceRows($pdo, "SELECT id, starts_at, ends_at, title_type FROM coach_calendar_events WHERE (athlete_id = ? OR second_athlete_id = ?) AND approval_status = 'approved' AND title_type IN ('training','group_lesson') AND starts_at >= ? AND starts_at < ? ORDER BY starts_at LIMIT 20", [$id, $id, $date, (new DateTimeImmutable($date))->modify('+7 days')->format('Y-m-d')]);
        }
    }
    if (performanceCan($viewer, 'load') || performanceCan($viewer, 'hyrox')) {
        if (performanceHas($t, 'training_sessions', ['id', 'athlete_id', 'started_at', 'completed_at', 'deleted_by_coach_at'])) {
            $duration = isset($t['training_sessions']['duration_centiseconds']) ? 'duration_centiseconds' : 'NULL AS duration_centiseconds';
            $rows = performanceRows($pdo, "SELECT id, started_at, completed_at, $duration FROM training_sessions WHERE athlete_id = ? AND completed_at IS NOT NULL AND deleted_by_coach_at IS NULL AND started_at >= ? AND started_at < ? ORDER BY started_at, id", [$id, $from, $end]);
            foreach ($rows as $row) {
                $minutes = $row['duration_centiseconds'] !== null ? (float)$row['duration_centiseconds'] / 6000 : null;
                $context['activities'][] = ['key' => 'session:' . $row['id'], 'date' => substr($row['started_at'], 0, 10), 'label' => 'Trénink #' . $row['id'], 'minutes' => $minutes, 'rpe' => null, 'duration_source' => $minutes !== null ? 'recorded' : 'missing', 'linked_to' => null];
            }
            if (performanceHas($t, 'run_outdoor_sessions', ['session_id', 'rpe'])) {
                $runs = performanceRows($pdo, 'SELECT r.session_id, r.rpe FROM run_outdoor_sessions r JOIN training_sessions s ON s.id = r.session_id WHERE s.athlete_id = ? AND s.started_at >= ? AND s.started_at < ?', [$id, $from, $end]);
                $rpes = array_column($runs, 'rpe', 'session_id');
                foreach ($context['activities'] as &$a) {
                    $rid = (int)substr($a['key'], 8);
                    if (isset($rpes[$rid])) {
                        $a['rpe'] = (float)$rpes[$rid];
                        $a['rpe_source'] = 'run_outdoor_sessions:' . $rid;
                    }
                }
                unset($a);
            }
        } else $context['warnings'][] = 'Prezenční tréninky nejsou dostupné.';
        if (performanceHas($t, 'online_trainings', ['id', 'athlete_id', 'title', 'status', 'started_at', 'completed_at', 'duration_seconds'])) {
            foreach (performanceRows($pdo, "SELECT id, title, started_at, completed_at, duration_seconds FROM online_trainings WHERE athlete_id = ? AND status = 'completed' AND COALESCE(started_at, completed_at) >= ? AND COALESCE(started_at, completed_at) < ? ORDER BY completed_at, id", [$id, $from, $end]) as $row) {
                $context['activities'][] = ['key' => 'online:' . $row['id'], 'date' => substr($row['started_at'] ?: $row['completed_at'], 0, 10), 'label' => $row['title'], 'minutes' => null, 'timer_minutes' => $row['duration_seconds'] !== null ? round((float)$row['duration_seconds'] / 60, 1) : null, 'rpe' => null, 'duration_source' => 'confirm-active-time', 'linked_to' => null];
            }
        } else $context['warnings'][] = 'Online tréninky nejsou dostupné.';
        if (performanceHas($t, 'food_diary_custom_activities', ['id', 'day_id', 'activity_type', 'activity_name', 'duration_minutes']) && performanceHas($t, 'food_diary_days', ['id', 'athlete_id', 'date'])) {
            foreach (performanceRows($pdo, 'SELECT a.id, a.activity_type, a.activity_name, a.duration_minutes, d.date FROM food_diary_custom_activities a JOIN food_diary_days d ON d.id = a.day_id WHERE d.athlete_id = ? AND d.date >= ? AND d.date <= ?', [$id, $from, $date]) as $row) {
                $context['activities'][] = ['key' => 'custom:' . $row['id'], 'date' => $row['date'], 'label' => $row['activity_name'] ?: $row['activity_type'], 'minutes' => $row['duration_minutes'] !== null ? (float)$row['duration_minutes'] : null, 'rpe' => null, 'duration_source' => 'manual', 'linked_to' => null];
            }
        }
        $links = performanceRows($pdo, 'SELECT custom_activity_id, target_key FROM performance_activity_links WHERE athlete_id = ?', [$id]);
        $linkMap = array_column($links, 'target_key', 'custom_activity_id');
        $keys = array_column($context['activities'], null, 'key');
        foreach ($context['activities'] as &$a) {
            if (str_starts_with($a['key'], 'custom:')) {
                $target = $linkMap[(int)substr($a['key'], 7)] ?? null;
                if ($target && isset($keys[$target]) && $keys[$target]['date'] === $a['date']) $a['linked_to'] = $target;
            }
        }
        unset($a);
    }
    $userId = performanceUserId($pdo, $id);
    if ($userId !== null) {
        if ($performance) {
            $rows = performanceRows($pdo, 'SELECT id, workout_id, performance_key, rpe_score, training_duration_minutes FROM mycoach_daily_questionnaires WHERE user_id = ? AND entry_date >= ? AND entry_date <= ? ORDER BY updated_at, id', [$userId, $from, $date]);
            $feedback = [];
            foreach ($rows as $row) {
                $key = $row['performance_key'];
                if (!$key && $row['workout_id']) $key = 'activity:session:' . $row['workout_id'];
                if ($key) $feedback[$key] = $row;
            }
            foreach ($context['activities'] as &$a) {
                $f = $feedback['activity:' . $a['key']] ?? null;
                if ($f) {
                    if ($f['training_duration_minutes'] !== null) {
                        $a['minutes'] = (float)$f['training_duration_minutes'];
                        $a['duration_source'] = 'mycoach_daily_questionnaires:' . $f['id'];
                    }
                    if ($f['rpe_score'] !== null) {
                        $a['rpe'] = (float)$f['rpe_score'];
                        $a['rpe_source'] = 'mycoach_daily_questionnaires:' . $f['id'];
                    }
                }
            }
            unset($a);
        }
        if ($recovery) {
            $row = performanceRows($pdo, "SELECT id, sleep_hours, feeling_score, energy_score, motivation_score, muscle_pain_score, joint_pain_score FROM mycoach_daily_questionnaires WHERE user_id = ? AND entry_date = ? AND (performance_key = ? OR (performance_key IS NULL AND workout_id IS NULL)) ORDER BY (performance_key IS NOT NULL) DESC, updated_at DESC, id DESC LIMIT 1", [$userId, $date, 'daily:' . $date])[0] ?? null;
            if ($row) {
                $context['daily_source'] = 'mycoach_daily_questionnaires:' . $row['id'];
                unset($row['id']);
                $context['daily'] = $row;
            }
            if (($context['daily']['sleep_hours'] ?? null) === null && performanceHas($t, 'mycoach_recovery_entries', ['id', 'user_id', 'entry_date', 'recovery_type', 'duration_minutes', 'updated_at'])) {
                $sleep = performanceRows($pdo, "SELECT id, duration_minutes FROM mycoach_recovery_entries WHERE user_id = ? AND entry_date = ? AND recovery_type = 'sleep' AND duration_minutes IS NOT NULL ORDER BY updated_at DESC, id DESC LIMIT 1", [$userId, $date])[0] ?? null;
                if ($sleep) {
                    $context['daily']['sleep_hours'] = (float)$sleep['duration_minutes'] / 60;
                    $context['daily_source'] = trim(($context['daily_source'] ?? '') . ',mycoach_recovery_entries:' . $sleep['id'], ',');
                }
            }
        }
    }
    if (!performanceCan($viewer, 'hydration')) {
        foreach (['weight', 'fluids', 'sweat'] as $key) $context[$key] = null;
        $context['upcoming'] = [];
    }
    if (!performanceCan($viewer, 'load') && !performanceCan($viewer, 'hyrox')) $context['activities'] = [];
    foreach (['strength', 'hyrox', 'hydration'] as $module) {
        if (!performanceCan($viewer, $module)) continue;
        $latest = performanceRows($pdo, 'SELECT id, entry_date, results_json FROM performance_results WHERE athlete_id = ? AND module_key = ? AND entry_date <= ? ORDER BY entry_date DESC, id DESC LIMIT 1', [$id, $module, $date])[0] ?? null;
        if ($latest) $context['latest_results'][$module] = ['id' => (int)$latest['id'], 'date' => $latest['entry_date'], 'results' => json_decode($latest['results_json'], true, 512, JSON_THROW_ON_ERROR)];
    }
    if (performanceCan($viewer, 'hyrox')) {
        $attempt = performanceRows($pdo, 'SELECT id, entry_date, total_seconds FROM athlete_hyrox_attempts WHERE athlete_id = ? AND entry_date <= ? ORDER BY entry_date DESC, id DESC LIMIT 1', [$id, $date])[0] ?? null;
        if ($attempt && (!isset($context['latest_results']['hyrox']) || $attempt['entry_date'] >= $context['latest_results']['hyrox']['date'])) {
            $splits = performanceRows($pdo, 'SELECT segment_key, seconds FROM athlete_hyrox_splits WHERE attempt_id = ?', [$attempt['id']]);
            $context['latest_results']['hyrox'] = ['id' => (int)$attempt['id'], 'source' => 'athlete_hyrox_attempts:' . $attempt['id'], 'date' => $attempt['entry_date'], 'results' => ['total_seconds' => (int)$attempt['total_seconds'], 'segments' => array_map('intval', array_column($splits, 'seconds', 'segment_key'))]];
        }
    }
    if (!$recovery) $context['warnings'][] = 'Regenerace je vypnutá nebo není sdílená.';
    return $context;
}

function performanceStrengthSeries(PDO $pdo, array $viewer, string $date): array {
    performanceRequireModule($viewer, 'strength');
    $t = performanceSchemaInventory($pdo);
    $end = (new DateTimeImmutable(performanceDate($date)))->modify('+1 day')->format('Y-m-d');
    $start = (new DateTimeImmutable($date))->modify('-180 days')->format('Y-m-d');
    $out = [];
    if (performanceHas($t, 'training_sessions', ['id', 'athlete_id', 'completed_at', 'deleted_by_coach_at']) && performanceHas($t, 'session_series', ['id', 'session_id', 'exercise_id', 'weight', 'equipment_weight', 'reps', 'assistance_reps']) && performanceHas($t, 'training_session_exercises', ['session_id', 'exercise_id', 'exercise_name', 'is_timed'])) {
        $rows = performanceRows($pdo, 'SELECT ss.id, ss.exercise_id, ss.weight, ss.equipment_weight, ss.reps, s.completed_at, e.exercise_name FROM session_series ss JOIN training_sessions s ON s.id = ss.session_id JOIN training_session_exercises e ON e.session_id = s.id AND e.exercise_id = ss.exercise_id WHERE s.athlete_id = ? AND s.completed_at >= ? AND s.completed_at < ? AND s.deleted_by_coach_at IS NULL AND e.is_timed = 0 AND COALESCE(ss.assistance_reps, 0) = 0 AND ss.reps BETWEEN 1 AND 12 AND ss.weight > 0 ORDER BY s.completed_at DESC, ss.id DESC LIMIT 300', [$viewer['athlete_id'], $start, $end]);
        foreach ($rows as $r) $out['series:' . $r['id']] = ['source' => 'series:' . $r['id'], 'exercise_id' => (int)$r['exercise_id'], 'name' => $r['exercise_name'], 'weight_kg' => (float)$r['weight'] + (float)$r['equipment_weight'], 'reps' => (int)$r['reps'], 'date' => substr($r['completed_at'], 0, 10)];
    }
    if (performanceHas($t, 'online_trainings', ['id', 'athlete_id', 'status', 'completed_at']) && performanceHas($t, 'online_training_results', ['id', 'online_training_id', 'online_training_series_id', 'actual_weight', 'actual_reps', 'actual_equipment_weight']) && performanceHas($t, 'online_training_series', ['id', 'online_training_exercise_id', 'prescribed_equipment_weight']) && performanceHas($t, 'online_training_exercises', ['id', 'online_training_id', 'exercise_id', 'exercise_name', 'is_timed'])) {
        $rows = performanceRows($pdo, "SELECT r.id, r.actual_weight, r.actual_equipment_weight, r.actual_reps, e.exercise_id, e.exercise_name, o.completed_at FROM online_training_results r JOIN online_trainings o ON o.id = r.online_training_id JOIN online_training_series s ON s.id = r.online_training_series_id JOIN online_training_exercises e ON e.id = s.online_training_exercise_id AND e.online_training_id = o.id WHERE o.athlete_id = ? AND o.status = 'completed' AND o.completed_at >= ? AND o.completed_at < ? AND e.is_timed = 0 AND r.actual_weight > 0 AND r.actual_reps BETWEEN 1 AND 12 AND (COALESCE(s.prescribed_equipment_weight, 0) = 0 OR r.actual_equipment_weight IS NOT NULL) ORDER BY o.completed_at DESC, r.id DESC LIMIT 300", [$viewer['athlete_id'], $start, $end]);
        foreach ($rows as $r) $out['online_result:' . $r['id']] = ['source' => 'online_result:' . $r['id'], 'exercise_id' => (int)$r['exercise_id'], 'name' => $r['exercise_name'], 'weight_kg' => (float)$r['actual_weight'] + (float)$r['actual_equipment_weight'], 'reps' => (int)$r['actual_reps'], 'date' => substr($r['completed_at'], 0, 10)];
    }
    return $out;
}

function performanceStandards(PDO $pdo, string $date, string $division, string $gender): array {
    if (!in_array($division, ['open', 'pro', 'doubles', 'relay'], true) || !in_array($gender, ['male', 'female', 'mixed'], true)) throw new InvalidArgumentException('Neplatná HYROX kategorie.');
    $t = performanceSchemaInventory($pdo);
    if (!performanceHas($t, 'mycoach_hyrox_standards', ['id', 'discipline_key', 'discipline_name', 'distance_or_reps', 'version_label', 'is_active', 'valid_from', 'valid_to']) || !performanceHas($t, 'mycoach_hyrox_standard_weights', ['id', 'standard_id', 'division', 'gender_category', 'weight_label', 'weight_kg', 'sort_order'])) return [];
    $rows = performanceRows($pdo, "SELECT s.id, s.discipline_key, s.discipline_name, s.version_label, s.distance_or_reps, s.valid_from, s.valid_to, w.weight_label, w.weight_kg FROM mycoach_hyrox_standards s LEFT JOIN mycoach_hyrox_standard_weights w ON w.standard_id = s.id AND w.division = ? AND w.gender_category IN (?, 'any') WHERE s.is_active = 1 AND (s.valid_from IS NULL OR s.valid_from <= ?) AND (s.valid_to IS NULL OR s.valid_to >= ?) ORDER BY s.id, w.sort_order, w.id", [$division, $gender, $date, $date]);
    $required = ['run_1km', 'ski_erg_1000', 'sled_push', 'sled_pull', 'burpee_broad_jumps', 'row_1000', 'farmer_carry', 'sandbag_lunges', 'wall_balls'];
    $found = [];
    foreach ($rows as $row) {
        $key = $row['discipline_key'];
        if (!in_array($key, $required, true)) continue;
        if (isset($found[$key])) throw new InvalidArgumentException('HYROX standardy jsou nejednoznačné. Správce musí vybrat jednu platnou verzi a zátěž pro každou disciplínu.');
        if (in_array($key, ['sled_push', 'sled_pull', 'farmer_carry', 'sandbag_lunges', 'wall_balls'], true) && $row['weight_kg'] === null) throw new InvalidArgumentException('Pro vybranou HYROX kategorii chybí zátěž: ' . $row['discipline_name'] . '. Požádejte správce o kontrolu standardů.');
        $found[$key] = $row;
    }
    if (count($found) !== count($required)) return [];
    return array_values($found);
}

function performanceRequestKey(array $input): string {
    $key = $input['request_key'] ?? '';
    if (!is_string($key) || !preg_match('/^[a-f0-9]{32}$/', $key)) throw new InvalidArgumentException('Neplatný identifikátor požadavku. Obnovte formulář.');
    return $key;
}

function performanceCalculate(PDO $pdo, array $viewer, string $module, array $input, string $date): array {
    performanceRequireModule($viewer, $module);
    $c = performanceContext($pdo, $viewer, $date);
    $sources = [];
    $normalized = [];
    if (in_array($module, ['hydration', 'strength'], true) && isset($viewer['athlete']['birth_date'])) {
        $age = calculateAge($viewer['athlete']['birth_date']);
        if ($age !== null && $age < 18) throw new InvalidArgumentException('Individuální plán hydratace a odhad maxima pro nezletilé řešte s kvalifikovaným trenérem.');
    }
    switch ($module) {
        case 'hydration':
            $normalized = [
                'weight_kg' => performanceNumber($input, 'weight_kg', 20, 400),
                'minutes' => performanceNumber($input, 'minutes', 0, 600),
                'environment' => (string)($input['environment'] ?? 'normal'),
                'sweat_rate_l_h' => performanceNumber($input, 'sweat_rate_l_h', .1, 4, false),
            ];
            if ($c['weight'] && $normalized['weight_kg'] === $c['weight']['kg']) $sources[] = $c['weight'];
            if ($c['sweat'] && $normalized['sweat_rate_l_h'] !== null && $normalized['sweat_rate_l_h'] === (float)$c['sweat']['result']['rate_l_h']) $sources[] = $c['sweat'];
            if ($c['fluids']) $sources[] = ['source' => 'food_diary_days:' . $c['fluids']['day_id'], 'date' => $date, 'type' => $c['fluids']['source']];
            $result = performanceHydration($normalized);
            $result['recorded_fluids'] = $c['fluids'];
            break;
        case 'strength':
            $source = (string)($input['series_source'] ?? '');
            if ($source !== '') {
                $series = performanceStrengthSeries($pdo, $viewer, $date)[$source] ?? null;
                if (!$series) throw new InvalidArgumentException('Série není dostupná nebo není vhodná pro 1RM.');
                $normalized = $series;
                $sources[] = $series;
            } else {
                $name = trim((string)($input['exercise_name'] ?? ''));
                if ($name === '' || mb_strlen($name) > 190) throw new InvalidArgumentException('Zadejte název cviku do 190 znaků.');
                $normalized = ['name' => $name, 'weight_kg' => performanceNumber($input, 'weight_kg', .5, 600), 'reps' => performanceNumber($input, 'reps', 1, 12)];
            }
            if (($input['unassisted'] ?? '') !== '1') throw new InvalidArgumentException('Potvrďte sérii bez dopomoci a celkovou zátěž.');
            $result = performanceStrength($normalized);
            break;
        case 'hyrox':
            $division = (string)($input['division'] ?? 'open');
            $gender = (string)($input['gender'] ?? 'male');
            $standard = performanceStandards($pdo, $date, $division, $gender);
            if (!$standard) throw new InvalidArgumentException('Pro datum a kategorii nejsou dostupné standardy. Požádejte administrátora o jejich kontrolu.');
            if (($input['standards_confirmed'] ?? '') !== '1') throw new InvalidArgumentException('Potvrďte kontrolu HYROX standardů a kategorie.');
            $result = performanceHyrox($input);
            $normalized = ['division' => $division, 'gender' => $gender, 'segments' => $result['segments'], 'standards' => $standard];
            $sources = array_map(static fn($s) => ['source' => 'mycoach_hyrox_standards:' . $s['id'], 'version' => $s['version_label']], $standard);
            break;
        case 'load':
            $result = performanceLoad($c['activities'], $date);
            $normalized = ['activities' => $c['activities']];
            $sources = array_column($c['activities'], 'key');
            break;
        case 'recovery':
            $result = performanceRecovery($c['daily']);
            $normalized = $c['daily'];
            if ($c['daily_source']) $sources[] = $c['daily_source'];
            break;
        default: throw new InvalidArgumentException('Neznámá kalkulačka.');
    }
    return ['module' => $module, 'date' => $date, 'inputs' => $normalized, 'results' => $result, 'sources' => $sources, 'version' => PERFORMANCE_VERSION];
}

function performanceSaveResult(PDO $pdo, array $viewer, array $report, string $requestKey): int {
    performanceRequireModule($viewer, $report['module']);
    performanceRequestKey(['request_key' => $requestKey]);
    $sql = 'INSERT INTO performance_results (athlete_id, module_key, entry_date, actor_type, actor_id, inputs_json, results_json, sources_json, algorithm_version, request_key) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)';
    $pdo->prepare($sql)->execute([$viewer['athlete_id'], $report['module'], $report['date'], $viewer['type'], $viewer['actor_id'], performanceJson($report['inputs']), performanceJson($report['results']), performanceJson($report['sources']), $report['version'], $requestKey]);
    $id = (int)$pdo->lastInsertId();
    $stored = performanceRows($pdo, 'SELECT module_key, entry_date, inputs_json FROM performance_results WHERE id = ?', [$id])[0];
    if ($stored['module_key'] !== $report['module'] || $stored['entry_date'] !== $report['date'] || $stored['inputs_json'] !== performanceJson($report['inputs'])) throw new InvalidArgumentException('Tento požadavek již uložil jiný výsledek. Obnovte formulář pro nový výpočet.');
    return $id;
}

function performanceHistory(PDO $pdo, array $viewer): array {
    $modules = array_keys(array_filter(performanceModules(), static fn($label, $key) => performanceCan($viewer, $key), ARRAY_FILTER_USE_BOTH));
    if (!$modules) return [];
    $in = implode(',', array_fill(0, count($modules), '?'));
    return performanceRows($pdo, "SELECT id, module_key, entry_date, created_at, algorithm_version, coach_note FROM performance_results WHERE athlete_id = ? AND module_key IN ($in) ORDER BY id DESC LIMIT 100", array_merge([$viewer['athlete_id']], $modules));
}

function performanceRecoveryTrend(PDO $pdo, array $viewer, string $date): array {
    performanceRequireModule($viewer, 'recovery');
    $date = performanceDate($date);
    $from = (new DateTimeImmutable($date))->modify('-27 days')->format('Y-m-d');
    $uid = performanceUserId($pdo, $viewer['athlete_id']);
    if ($uid === null) return [];
    $rows = performanceRows($pdo, "SELECT id, entry_date, sleep_hours, feeling_score, energy_score, motivation_score, muscle_pain_score, joint_pain_score FROM mycoach_daily_questionnaires WHERE user_id = ? AND entry_date >= ? AND entry_date <= ? AND (performance_key LIKE 'daily:%' OR (performance_key IS NULL AND workout_id IS NULL)) ORDER BY entry_date DESC, (performance_key IS NOT NULL) DESC, updated_at DESC, id DESC", [$uid, $from, $date]);
    $days = [];
    foreach ($rows as $row) {
        if (isset($days[$row['entry_date']])) continue;
        $days[$row['entry_date']] = ['source' => 'mycoach_daily_questionnaires:' . $row['id'], 'values' => $row];
    }
    $tables = performanceSchemaInventory($pdo);
    if (performanceHas($tables, 'mycoach_recovery_entries', ['id', 'user_id', 'entry_date', 'recovery_type', 'duration_minutes', 'updated_at'])) {
        $sleepRows = performanceRows($pdo, "SELECT id, entry_date, duration_minutes FROM mycoach_recovery_entries WHERE user_id = ? AND entry_date >= ? AND entry_date <= ? AND recovery_type = 'sleep' AND duration_minutes IS NOT NULL ORDER BY entry_date DESC, updated_at DESC, id DESC", [$uid, $from, $date]);
        foreach ($sleepRows as $sleep) {
            $d = $sleep['entry_date'];
            if (($days[$d]['values']['sleep_hours'] ?? null) !== null) continue;
            $days[$d]['values']['sleep_hours'] = (float)$sleep['duration_minutes'] / 60;
            $days[$d]['source'] = trim(($days[$d]['source'] ?? '') . ',mycoach_recovery_entries:' . $sleep['id'], ',');
        }
    }
    krsort($days);
    foreach ($days as &$day) $day['result'] = performanceRecovery($day['values']);
    unset($day);
    return $days;
}

function performanceReport(PDO $pdo, array $viewer, int $id): array {
    $row = performanceRows($pdo, 'SELECT * FROM performance_results WHERE id = ? AND athlete_id = ?', [$id, $viewer['athlete_id']])[0] ?? null;
    if (!$row) throw new DomainException('Výsledek nebyl nalezen.');
    performanceRequireModule($viewer, $row['module_key']);
    return [
        'id' => (int)$row['id'], 'module' => $row['module_key'], 'date' => $row['entry_date'],
        'created_at' => $row['created_at'], 'inputs' => json_decode($row['inputs_json'], true, 512, JSON_THROW_ON_ERROR),
        'results' => json_decode($row['results_json'], true, 512, JSON_THROW_ON_ERROR),
        'sources' => json_decode($row['sources_json'], true, 512, JSON_THROW_ON_ERROR),
        'version' => $row['algorithm_version'], 'coach_note' => $row['coach_note'],
    ];
}

function performanceAttemptReport(PDO $pdo, array $viewer, int $id): array {
    performanceRequireModule($viewer, 'hyrox');
    $attempt = performanceRows($pdo, 'SELECT * FROM athlete_hyrox_attempts WHERE id = ? AND athlete_id = ?', [$id, $viewer['athlete_id']])[0] ?? null;
    if (!$attempt) throw new DomainException('HYROX pokus nebyl nalezen.');
    $splits = performanceRows($pdo, 'SELECT segment_key, seconds FROM athlete_hyrox_splits WHERE attempt_id = ?', [$id]);
    $segments = array_map('intval', array_column($splits, 'seconds', 'segment_key'));
    return [
        'id' => $id, 'kind' => 'HYROX pokus', 'module' => 'hyrox', 'date' => $attempt['entry_date'],
        'created_at' => $attempt['created_at'], 'version' => 'HYROX součet uložených mezičasů',
        'inputs' => ['category' => $attempt['category'], 'standards' => json_decode($attempt['standard_json'], true, 512, JSON_THROW_ON_ERROR)],
        'results' => ['segments' => $segments, 'total_seconds' => (int)$attempt['total_seconds']],
        'sources' => [$attempt['activity_key']], 'coach_note' => null,
    ];
}

function performanceSaveFeedback(PDO $pdo, array $viewer, array $input, string $date): void {
    if ($viewer['type'] !== 'athlete') throw new DomainException('Subjektivní hodnocení zadává pouze sportovec.');
    $scope = (string)($input['scope'] ?? 'daily');
    $values = [];
    $workoutId = null;
    if ($scope === 'daily') {
        performanceRequireModule($viewer, 'recovery');
        $values = performanceRecovery($input)['values'];
        $key = 'daily:' . $date;
    } elseif ($scope === 'activity') {
        performanceRequireModule($viewer, 'load');
        $activityKey = (string)($input['activity_key'] ?? '');
        $activities = array_column(performanceContext($pdo, $viewer, $date)['activities'], null, 'key');
        $a = $activities[$activityKey] ?? null;
        if (!$a || $a['date'] !== $date || $a['linked_to']) throw new InvalidArgumentException('Vyberte vlastní dokončenou aktivitu pro tento den.');
        $values = ['rpe_score' => performanceNumber($input, 'rpe_score', 1, 10), 'training_duration_minutes' => performanceNumber($input, 'training_duration_minutes', 1, 600)];
        if ($values['rpe_score'] !== floor($values['rpe_score']) || $values['training_duration_minutes'] !== floor($values['training_duration_minutes'])) throw new InvalidArgumentException('RPE a aktivní minuty zadejte jako celá čísla.');
        if (str_starts_with($activityKey, 'session:')) $workoutId = (int)substr($activityKey, 8);
        $key = 'activity:' . $activityKey;
    } else throw new InvalidArgumentException('Neplatný typ hodnocení.');
    $pdo->beginTransaction();
    try {
        $uid = performanceUserId($pdo, $viewer['athlete_id'], true);
        performanceRows($pdo, 'SELECT id FROM mycoach_users WHERE id = ? FOR UPDATE', [$uid]);
        $existing = performanceRows($pdo, 'SELECT id FROM mycoach_daily_questionnaires WHERE user_id = ? AND performance_key = ? LIMIT 1', [$uid, $key])[0] ?? null;
        if (!$existing && ($scope === 'daily' || $workoutId !== null)) {
            $existing = performanceRows($pdo, 'SELECT id FROM mycoach_daily_questionnaires WHERE user_id = ? AND entry_date = ? AND performance_key IS NULL AND ' . ($workoutId !== null ? 'workout_id = ?' : 'workout_id IS NULL') . ' ORDER BY updated_at DESC, id DESC LIMIT 1', array_merge([$uid, $date], $workoutId !== null ? [$workoutId] : []))[0] ?? null;
        }
        $fields = array_keys($values);
        if ($existing) {
            $set = implode(', ', array_map(static fn($f) => "$f = ?", $fields));
            $pdo->prepare("UPDATE mycoach_daily_questionnaires SET $set, performance_key = ?, updated_at = NOW() WHERE id = ? AND user_id = ?")->execute(array_merge(array_values($values), [$key, $existing['id'], $uid]));
        } else {
            $columns = implode(', ', $fields);
            $marks = implode(',', array_fill(0, count($fields) + 4, '?'));
            $pdo->prepare("INSERT INTO mycoach_daily_questionnaires (user_id, entry_date, workout_id, performance_key, $columns) VALUES ($marks)")->execute(array_merge([$uid, $date, $workoutId, $key], array_values($values)));
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function performanceAction(PDO $pdo, array $viewer, array $input): array {
    performanceValidateInput($input);
    $date = performanceDate((string)($input['date'] ?? date('Y-m-d')));
    if ($date > date('Y-m-d')) throw new InvalidArgumentException('Datum nesmí být v budoucnosti.');
    $action = (string)($input['action'] ?? '');
    if ($action === 'settings') {
        if ($viewer['type'] !== 'athlete') throw new DomainException('Nastavení mění pouze sportovec.');
        $pdo->prepare('INSERT INTO performance_settings (athlete_id, intelligence_enabled, share_performance, share_recovery) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE intelligence_enabled = VALUES(intelligence_enabled), share_performance = VALUES(share_performance), share_recovery = VALUES(share_recovery)')->execute([$viewer['athlete_id'], ($input['intelligence_enabled'] ?? '') === '1' ? 1 : 0, ($input['share_performance'] ?? '') === '1' ? 1 : 0, ($input['share_recovery'] ?? '') === '1' ? 1 : 0]);
        return ['message' => 'Nastavení bylo uloženo.'];
    }
    if ($action === 'feedback') {
        performanceSaveFeedback($pdo, $viewer, $input, $date);
        return ['message' => 'Hodnocení bylo uloženo do existující evidence MyCoach.'];
    }
    if (in_array($action, ['delete_sweat', 'delete_attempt'], true)) {
        if ($viewer['type'] !== 'athlete') throw new DomainException('Měření maže pouze sportovec.');
        $table = $action === 'delete_sweat' ? 'athlete_sweat_tests' : 'athlete_hyrox_attempts';
        $module = $action === 'delete_sweat' ? 'hydration' : 'hyrox';
        performanceRequireModule($viewer, $module);
        $stmt = $pdo->prepare("DELETE FROM $table WHERE id = ? AND athlete_id = ?");
        $stmt->execute([(int)($input['id'] ?? 0), $viewer['athlete_id']]);
        if (!$stmt->rowCount()) throw new DomainException('Měření nebylo nalezeno.');
        return ['message' => 'Měření bylo smazáno. Původní trénink, váha a nápoje zůstaly zachovány.'];
    }
    if ($action === 'sweat') {
        performanceRequireModule($viewer, 'hydration');
        if ($viewer['type'] !== 'athlete') throw new DomainException('Měření zadává sportovec.');
        $result = performanceSweat($input);
        $normalized = [];
        foreach (['pre_kg', 'post_kg', 'drink_ml', 'urine_ml', 'minutes'] as $k) $normalized[$k] = (float)str_replace(',', '.', (string)$input[$k]);
        $pdo->prepare('INSERT INTO athlete_sweat_tests (athlete_id, entry_date, inputs_json, results_json, request_key) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)')->execute([$viewer['athlete_id'], $date, performanceJson($normalized), performanceJson($result), performanceRequestKey($input)]);
        $stored = performanceRows($pdo, 'SELECT entry_date, inputs_json FROM athlete_sweat_tests WHERE id = ?', [(int)$pdo->lastInsertId()])[0];
        if ($stored['entry_date'] !== $date || $stored['inputs_json'] !== performanceJson($normalized)) throw new InvalidArgumentException('Požadavek již obsahuje jiné měření. Obnovte formulář.');
        return ['message' => 'Test pocení byl uložen; žádné nové vážení ani dávka nápoje nebyly vytvořeny.', 'sweat' => $result];
    }
    if ($action === 'link') {
        performanceRequireModule($viewer, 'load');
        if ($viewer['type'] !== 'athlete') throw new DomainException('Propojení potvrzuje sportovec.');
        $rows = array_column(performanceContext($pdo, $viewer, $date)['activities'], null, 'key');
        $source = (string)($input['custom_key'] ?? '');
        $target = (string)($input['target_key'] ?? '');
        if (!str_starts_with($source, 'custom:') || !isset($rows[$source])) throw new InvalidArgumentException('Neplatná ruční aktivita.');
        if ($target === '') {
            $pdo->prepare('DELETE FROM performance_activity_links WHERE athlete_id = ? AND custom_activity_id = ?')->execute([$viewer['athlete_id'], (int)substr($source, 7)]);
        } else {
            if (!isset($rows[$target]) || !preg_match('/^(session|online):\d+$/', $target) || $rows[$source]['date'] !== $rows[$target]['date']) throw new InvalidArgumentException('Vyberte vlastní dokončený trénink ze stejného dne.');
            $pdo->prepare('INSERT INTO performance_activity_links (athlete_id, custom_activity_id, target_key) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE target_key = VALUES(target_key)')->execute([$viewer['athlete_id'], (int)substr($source, 7), $target]);
        }
        return ['message' => 'Propojení aktualizováno. Zátěž propojené ruční aktivity se již nepočítá podruhé.'];
    }
    if ($action === 'review' || $action === 'delete') {
        $report = performanceReport($pdo, $viewer, (int)($input['id'] ?? 0));
        if ($action === 'review') {
            if ($viewer['type'] !== 'coach') throw new DomainException('Poznámku píše trenér.');
            $note = trim((string)($input['coach_note'] ?? ''));
            if (mb_strlen($note) > 4000) throw new InvalidArgumentException('Poznámka může mít nejvýše 4000 znaků.');
            $pdo->prepare('UPDATE performance_results SET coach_note = ?, coach_note_at = NOW() WHERE id = ? AND athlete_id = ?')->execute([$note, $report['id'], $viewer['athlete_id']]);
        } else {
            if ($viewer['type'] !== 'athlete') throw new DomainException('Výsledek maže sportovec.');
            $pdo->prepare('DELETE FROM performance_results WHERE id = ? AND athlete_id = ?')->execute([$report['id'], $viewer['athlete_id']]);
        }
        return ['message' => $action === 'review' ? 'Poznámka trenéra byla uložena.' : 'Historický výsledek byl smazán. Původní aktivita zůstala zachována.'];
    }
    if ($action === 'hyrox_attempt') {
        performanceRequireModule($viewer, 'hyrox');
        if ($viewer['type'] !== 'athlete') throw new DomainException('Skutečný pokus potvrzuje sportovec.');
        $report = performanceCalculate($pdo, $viewer, 'hyrox', $input, $date);
        $key = (string)($input['activity_key'] ?? '');
        $activities = array_column(performanceContext($pdo, $viewer, $date)['activities'], null, 'key');
        if (!isset($activities[$key]) || $activities[$key]['date'] !== $date || $activities[$key]['linked_to']) throw new InvalidArgumentException('Pro skutečný pokus vyberte vlastní dokončenou aktivitu tohoto dne.');
        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO athlete_hyrox_attempts (athlete_id, entry_date, category, standard_json, total_seconds, activity_key, request_key) VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)')->execute([$viewer['athlete_id'], $date, $report['inputs']['division'] . '/' . $report['inputs']['gender'], performanceJson($report['inputs']['standards']), $report['results']['total_seconds'], $key, performanceRequestKey($input)]);
            $id = (int)$pdo->lastInsertId();
            $stored = performanceRows($pdo, 'SELECT entry_date, category, activity_key FROM athlete_hyrox_attempts WHERE id = ?', [$id])[0];
            $existing = performanceRows($pdo, 'SELECT segment_key, seconds FROM athlete_hyrox_splits WHERE attempt_id = ?', [$id]);
            if ($stored['entry_date'] !== $date || $stored['category'] !== $report['inputs']['division'] . '/' . $report['inputs']['gender'] || $stored['activity_key'] !== $key || ($existing && array_map('intval', array_column($existing, 'seconds', 'segment_key')) != $report['results']['segments'])) throw new InvalidArgumentException('K aktivitě již existuje jiný HYROX pokus. Pro opravu nejprve smažte původní pokus; trénink zůstane zachován.');
            if (!$existing) foreach ($report['results']['segments'] as $segment => $seconds) $pdo->prepare('INSERT INTO athlete_hyrox_splits (attempt_id, segment_key, seconds) VALUES (?, ?, ?)')->execute([$id, $segment, $seconds]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return ['message' => 'Pokus a mezičasy byly uloženy k existující aktivitě. Nebyl vytvořen druhý trénink.'];
    }
    if (!in_array($action, ['calculate', 'save'], true)) throw new InvalidArgumentException('Neznámá akce.');
    $report = performanceCalculate($pdo, $viewer, (string)($input['module'] ?? ''), $input, $date);
    if ($action === 'save') {
        $report['id'] = performanceSaveResult($pdo, $viewer, $report, performanceRequestKey($input));
        $report = performanceReport($pdo, $viewer, $report['id']);
    }
    return ['message' => $action === 'save' ? 'Výsledek byl uložen do historie.' : 'Náhled výpočtu; zatím není uložen.', 'report' => $report];
}
