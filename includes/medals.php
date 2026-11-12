<?php

if (!function_exists('athleteMedalDefinitions')) {
    function athleteMedalDefinitions(): array
    {
        $definitions = [];
        $trainingMilestones = [1, 10, 50, 100, 200, 500, 1000];
        foreach ($trainingMilestones as $count) {
            $definitions[] = [
                'id' => $count === 1 ? 'first_training' : 'training_' . $count,
                'title' => $count === 1 ? 'První trénink' : $count . ' tréninků',
                'description' => $count === 1 ? 'Dokončit první trénink.' : 'Dokončit ' . $count . ' tréninků.',
                'category' => 'training',
                'threshold' => $count,
                'image' => $count === 1 ? 'first_training.png' : 'training_' . $count . '.png',
            ];
        }

        $definitions[] = [
            'id' => 'health_questionnaire',
            'title' => 'Vyplněný zdravotní dotazník',
            'description' => 'Odeslat zdravotní dotazník.',
            'category' => 'health',
            'threshold' => 1,
            'image' => 'health_questionnaire.png',
        ];

        $definitions[] = [
            'id' => 'calendar_first_request',
            'title' => 'První žádost o trénink do kalendáře',
            'description' => 'Poslat trenérovi první žádost o tréninkový termín.',
            'category' => 'calendar_request',
            'threshold' => 1,
            'image' => 'calendar_first_request.png',
        ];
        $definitions[] = [
            'id' => 'profile_photo',
            'title' => 'Přidána profilová fotografie',
            'description' => 'Přidat profilovou fotografii.',
            'category' => 'profile_photo',
            'threshold' => 1,
            'image' => 'profile_photo.png',
        ];

        $onlineMilestones = [1, 10, 50, 100, 200, 1000];
        foreach ($onlineMilestones as $count) {
            $definitions[] = [
                'id' => $count === 1 ? 'online_first' : 'online_' . $count,
                'title' => $count === 1 ? 'První online trénink' : $count . ' online tréninků',
                'description' => $count === 1 ? 'Dokončit první online trénink.' : 'Dokončit ' . $count . ' online tréninků.',
                'category' => 'online',
                'threshold' => $count,
                'image' => 'online_' . ($count === 1 ? 'first' : $count) . '.png',
            ];
        }

        $weightMilestones = [1, 10, 50];
        foreach ($weightMilestones as $count) {
            $definitions[] = [
                'id' => $count === 1 ? 'weight_first' : 'weight_' . $count,
                'title' => $count === 1 ? 'První zaznamenaná hmotnost' : $count . ' zaznamenaných hmotností',
                'description' => $count === 1 ? 'Zaznamenat první hmotnost.' : 'Zaznamenat hmotnost ' . $count . '×.',
                'category' => 'weight',
                'threshold' => $count,
                'image' => 'weight_' . ($count === 1 ? 'first' : $count) . '.png',
            ];
        }

        $definitions[] = [
            'id' => 'strava_first_meal',
            'title' => 'První zaznamenané jídlo',
            'description' => 'Zaznamenat jídlo v aplikaci Strava.',
            'category' => 'food_meal',
            'threshold' => 1,
            'image' => 'strava_meal.png',
        ];
        $definitions[] = [
            'id' => 'strava_first_drink',
            'title' => 'První zaznamenaný nápoj',
            'description' => 'Zaznamenat nápoj v aplikaci Strava.',
            'category' => 'food_drink',
            'threshold' => 1,
            'image' => 'strava_drink.png',
        ];
        $definitions[] = [
            'id' => 'strava_full_day',
            'title' => 'Kompletní denní záznam',
            'description' => 'Zaznamenat všech šest jídel v jednom dni. Jídla označená jako vynechaná se také počítají.',
            'category' => 'food_full_day',
            'threshold' => 1,
            'image' => 'strava_full_day.png',
        ];
        $definitions[] = [
            'id' => 'strava_7_day_streak',
            'title' => '7 dní záznamů v řadě',
            'description' => 'Zaznamenat alespoň jedno jídlo nebo nápoj v aplikaci Strava každý den po dobu 7 po sobě jdoucích dní.',
            'category' => 'food_streak',
            'threshold' => 1,
            'image' => 'strava_7_days.png',
        ];

        return $definitions;
    }
}

if (!function_exists('athleteMedalsEnsureSchema')) {
    function athleteMedalsEnsureSchema(PDO $pdo): void
    {
        $stmt = $pdo->query("SHOW COLUMNS FROM athletes LIKE 'photo_added_at'");
        if ($stmt === false || !$stmt->fetch()) {
            try {
                $pdo->exec('ALTER TABLE athletes ADD COLUMN photo_added_at DATETIME NULL DEFAULT NULL AFTER photo');
            } catch (PDOException $e) {
                if ((int)($e->errorInfo[1] ?? 0) !== 1060) {
                    throw $e;
                }
            }
        }

        $pdo->exec(
            "UPDATE athletes
             SET photo_added_at = COALESCE(created_at, NOW())
             WHERE photo_added_at IS NULL
               AND photo IS NOT NULL
               AND TRIM(photo) <> ''"
        );
    }
}

if (!function_exists('athleteMedalStreakCompletionDate')) {
    function athleteMedalStreakCompletionDate(array $eventDates, int $requiredDays): ?string
    {
        if ($requiredDays < 1) {
            return null;
        }

        $dates = array_values(array_unique(array_filter(
            array_map(static fn($date): string => trim((string)$date), $eventDates),
            static fn(string $date): bool => $date !== ''
        )));
        sort($dates, SORT_STRING);

        $streak = 0;
        $previousDate = null;
        foreach ($dates as $date) {
            $current = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if (!$current || $current->format('Y-m-d') !== $date) {
                continue;
            }

            if ($previousDate === null) {
                $streak = 1;
            } else {
                $expected = $previousDate->modify('+1 day');
                $streak = $expected->format('Y-m-d') === $date ? $streak + 1 : 1;
            }

            if ($streak >= $requiredDays) {
                return $date;
            }

            $previousDate = $current;
        }

        return null;
    }
}

if (!function_exists('athleteMedalPopulationCounts')) {
    function athleteMedalPopulationCounts(array $athleteCountsByCategory): array
    {
        $medalCounts = [];
        foreach (athleteMedalDefinitions() as $medal) {
            $holders = 0;
            foreach ($athleteCountsByCategory[$medal['category']] ?? [] as $count) {
                if ((int)$count >= (int)$medal['threshold']) {
                    $holders++;
                }
            }
            $medalCounts[$medal['id']] = $holders;
        }

        return $medalCounts;
    }
}

if (!function_exists('athleteMedalPopulationHolders')) {
    function athleteMedalPopulationHolders(array $athleteCountsByCategory, array $athletesById): array
    {
        $medalHolders = [];
        foreach (athleteMedalDefinitions() as $medal) {
            $holders = [];
            foreach ($athleteCountsByCategory[$medal['category']] ?? [] as $athleteId => $count) {
                if ((int)$count < (int)$medal['threshold'] || !isset($athletesById[$athleteId])) {
                    continue;
                }

                $holders[] = $athletesById[$athleteId] + ['id' => (int)$athleteId];
            }
            $medalHolders[$medal['id']] = $holders;
        }

        return $medalHolders;
    }
}

if (!function_exists('athleteMedalPopulationStats')) {
    function athleteMedalPopulationStats(PDO $pdo): array
    {
        athleteMedalsEnsureSchema($pdo);
        $athletesById = [];
        $athleteStmt = $pdo->query(
            'SELECT a.id AS athlete_id, a.first_name, a.last_name,
                    c.name AS coach_name, c.username AS coach_username
             FROM athletes a
             LEFT JOIN coaches c ON c.id = a.coach_id
             ORDER BY a.last_name ASC, a.first_name ASC, a.id ASC'
        );
        foreach ($athleteStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $athleteId = (int)$row['athlete_id'];
            $coachName = trim((string)($row['coach_name'] ?? ''));
            if ($coachName === '') {
                $coachName = trim((string)($row['coach_username'] ?? ''));
            }
            if ($coachName === '') {
                $coachName = 'Bez trenéra';
            }
            $athletesById[$athleteId] = [
                'name' => trim((string)$row['first_name'] . ' ' . (string)$row['last_name']),
                'coach_name' => $coachName,
            ];
        }

        $athleteCountsByCategory = [];
        $queries = [
            'training' => 'SELECT athlete_id, COUNT(*) AS event_count
                           FROM training_sessions
                           WHERE completed_at IS NOT NULL
                             AND deleted_by_coach_at IS NULL
                           GROUP BY athlete_id',
            'online' => "SELECT athlete_id, COUNT(*) AS event_count
                         FROM online_trainings
                         WHERE status = 'completed'
                           AND completed_at IS NOT NULL
                         GROUP BY athlete_id",
            'weight' => 'SELECT athlete_id, COUNT(*) AS event_count
                         FROM athlete_weight_logs
                         GROUP BY athlete_id',
            'health' => 'SELECT athlete_id, COUNT(*) AS event_count
                         FROM athlete_health_questionnaire_submissions
                         GROUP BY athlete_id',
            'calendar_request' => "SELECT requested_by_athlete_id AS athlete_id, COUNT(*) AS event_count
                                   FROM coach_calendar_events
                                   WHERE requested_by_athlete_id IS NOT NULL
                                     AND title_type = 'training'
                                   GROUP BY requested_by_athlete_id",
            'profile_photo' => 'SELECT id AS athlete_id, 1 AS event_count
                                FROM athletes
                                WHERE photo_added_at IS NOT NULL',
            'food_meal' => 'SELECT d.athlete_id, COUNT(DISTINCT d.date) AS event_count
                            FROM food_diary_days d
                            JOIN food_diary_meals m ON m.day_id = d.id AND m.skipped = 0
                            JOIN food_diary_items i ON i.meal_id = m.id
                            GROUP BY d.athlete_id',
            'food_drink' => 'SELECT d.athlete_id, COUNT(DISTINCT d.date) AS event_count
                             FROM food_diary_days d
                             JOIN food_diary_hydration_entries h ON h.day_id = d.id AND h.amount_ml > 0
                             GROUP BY d.athlete_id',
            'food_full_day' => 'SELECT complete_days.athlete_id, COUNT(*) AS event_count
                                FROM (
                                    SELECT d.athlete_id, d.id
                                    FROM food_diary_days d
                                    JOIN food_diary_meals m ON m.day_id = d.id
                                    LEFT JOIN (
                                        SELECT meal_id, COUNT(*) AS item_count
                                        FROM food_diary_items
                                        GROUP BY meal_id
                                    ) items ON items.meal_id = m.id
                                    GROUP BY d.athlete_id, d.id
                                    HAVING SUM(CASE WHEN m.skipped = 1 OR COALESCE(items.item_count, 0) > 0 THEN 1 ELSE 0 END) >= 6
                                ) complete_days
                                GROUP BY complete_days.athlete_id',
        ];

        foreach ($queries as $category => $sql) {
            $athleteCountsByCategory[$category] = array_fill_keys(array_keys($athletesById), 0);
            $stmt = $pdo->query($sql);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $athleteId = (int)$row['athlete_id'];
                if (array_key_exists($athleteId, $athleteCountsByCategory[$category])) {
                    $athleteCountsByCategory[$category][$athleteId] = (int)$row['event_count'];
                }
            }
        }

        $foodStreakQuery = $pdo->query(
            'SELECT d.athlete_id, d.date AS event_date
             FROM food_diary_days d
             WHERE EXISTS (
                 SELECT 1
                 FROM food_diary_meals m
                 JOIN food_diary_items i ON i.meal_id = m.id
                 WHERE m.day_id = d.id AND m.skipped = 0
             )
             OR EXISTS (
                 SELECT 1
                 FROM food_diary_hydration_entries h
                 WHERE h.day_id = d.id AND h.amount_ml > 0
             )
             ORDER BY d.athlete_id ASC, d.date ASC'
        );
        $foodDatesByAthlete = [];
        foreach ($foodStreakQuery->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $foodDatesByAthlete[(int)$row['athlete_id']][] = (string)$row['event_date'];
        }
        $athleteCountsByCategory['food_streak'] = array_fill_keys(array_keys($athletesById), 0);
        foreach ($foodDatesByAthlete as $athleteId => $dates) {
            if (athleteMedalStreakCompletionDate($dates, 7) !== null && isset($athleteCountsByCategory['food_streak'][$athleteId])) {
                $athleteCountsByCategory['food_streak'][$athleteId] = 1;
            }
        }

        return [
            'total_athletes' => count($athletesById),
            'medal_counts' => athleteMedalPopulationCounts($athleteCountsByCategory),
            'medal_holders' => athleteMedalPopulationHolders($athleteCountsByCategory, $athletesById),
        ];
    }
}

if (!function_exists('athleteMedalThresholdDates')) {
    function athleteMedalThresholdDates(array $eventDates, array $thresholds): array
    {
        $milestoneDates = [];
        foreach ($thresholds as $threshold) {
            $eventIndex = (int)$threshold - 1;
            if ($eventIndex >= 0 && isset($eventDates[$eventIndex]) && trim((string)$eventDates[$eventIndex]) !== '') {
                $milestoneDates[(int)$threshold] = (string)$eventDates[$eventIndex];
            }
        }

        return $milestoneDates;
    }
}

if (!function_exists('athleteMedalsLoad')) {
    function athleteMedalsLoad(PDO $pdo, int $athleteId): array
    {
        athleteMedalsEnsureSchema($pdo);
        $definitions = athleteMedalDefinitions();
        $thresholdsByCategory = [];
        foreach ($definitions as $definition) {
            if ($definition['category'] !== 'health') {
                $thresholdsByCategory[$definition['category']][] = (int)$definition['threshold'];
            }
        }

        $eventQueries = [
            'training' => [
                'sql' => 'SELECT DATE(ts.completed_at) AS event_date
                          FROM training_sessions ts
                          WHERE ts.athlete_id = ?
                            AND ts.completed_at IS NOT NULL
                            AND ts.deleted_by_coach_at IS NULL
                          ORDER BY ts.completed_at ASC, ts.id ASC
                          LIMIT 1000',
            ],
            'calendar_request' => [
                'sql' => "SELECT DATE(created_at) AS event_date
                          FROM coach_calendar_events
                          WHERE requested_by_athlete_id = ?
                            AND title_type = 'training'
                          ORDER BY created_at ASC, id ASC
                          LIMIT 1",
            ],
            'profile_photo' => [
                'sql' => 'SELECT DATE(photo_added_at) AS event_date
                          FROM athletes
                          WHERE id = ? AND photo_added_at IS NOT NULL
                          LIMIT 1',
            ],
            'online' => [
                'sql' => "SELECT DATE(ot.completed_at) AS event_date
                          FROM online_trainings ot
                          WHERE ot.athlete_id = ?
                            AND ot.status = 'completed'
                            AND ot.completed_at IS NOT NULL
                          ORDER BY ot.completed_at ASC, ot.id ASC
                          LIMIT 1000",
            ],
            'weight' => [
                'sql' => 'SELECT measured_at AS event_date
                          FROM athlete_weight_logs
                          WHERE athlete_id = ?
                          ORDER BY measured_at ASC, id ASC
                          LIMIT 50',
            ],
            'food_meal' => [
                'sql' => 'SELECT DISTINCT d.date AS event_date
                          FROM food_diary_days d
                          JOIN food_diary_meals m ON m.day_id = d.id AND m.skipped = 0
                          JOIN food_diary_items i ON i.meal_id = m.id
                          WHERE d.athlete_id = ?
                          ORDER BY d.date ASC',
            ],
            'food_drink' => [
                'sql' => 'SELECT DISTINCT d.date AS event_date
                          FROM food_diary_days d
                          JOIN food_diary_hydration_entries h ON h.day_id = d.id AND h.amount_ml > 0
                          WHERE d.athlete_id = ?
                          ORDER BY d.date ASC',
            ],
            'food_full_day' => [
                'sql' => 'SELECT complete_days.date AS event_date
                          FROM (
                              SELECT d.id, d.date
                              FROM food_diary_days d
                              JOIN food_diary_meals m ON m.day_id = d.id
                              LEFT JOIN (
                                  SELECT meal_id, COUNT(*) AS item_count
                                  FROM food_diary_items
                                  GROUP BY meal_id
                              ) items ON items.meal_id = m.id
                              WHERE d.athlete_id = ?
                              GROUP BY d.id, d.date
                              HAVING SUM(CASE WHEN m.skipped = 1 OR COALESCE(items.item_count, 0) > 0 THEN 1 ELSE 0 END) >= 6
                          ) complete_days
                          ORDER BY complete_days.date ASC',
            ],
        ];

        $milestoneDates = [];
        foreach ($eventQueries as $category => $query) {
            $stmt = $pdo->prepare($query['sql']);
            $stmt->execute([$athleteId]);
            $eventDates = array_map(
                static fn(array $row): string => (string)$row['event_date'],
                $stmt->fetchAll()
            );
            $milestoneDates[$category] = athleteMedalThresholdDates(
                $eventDates,
                $thresholdsByCategory[$category] ?? []
            );
        }

        $healthStmt = $pdo->prepare(
            'SELECT DATE(submitted_at)
             FROM athlete_health_questionnaire_submissions
             WHERE athlete_id = ?
             ORDER BY submitted_at ASC, id ASC
             LIMIT 1'
        );
        $healthStmt->execute([$athleteId]);
        $firstQuestionnaireDate = $healthStmt->fetchColumn();
        $milestoneDates['health'] = [
            1 => $firstQuestionnaireDate !== false && $firstQuestionnaireDate !== null
                ? (string)$firstQuestionnaireDate
                : null,
        ];

        $foodStreakStmt = $pdo->prepare(
            'SELECT DISTINCT d.date AS event_date
             FROM food_diary_days d
             WHERE d.athlete_id = ?
               AND (
                   EXISTS (
                       SELECT 1
                       FROM food_diary_meals m
                       JOIN food_diary_items i ON i.meal_id = m.id
                       WHERE m.day_id = d.id AND m.skipped = 0
                   )
                   OR EXISTS (
                       SELECT 1
                       FROM food_diary_hydration_entries h
                       WHERE h.day_id = d.id AND h.amount_ml > 0
                   )
               )
             ORDER BY d.date ASC'
        );
        $foodStreakStmt->execute([$athleteId]);
        $foodDates = array_map(
            static fn(array $row): string => (string)$row['event_date'],
            $foodStreakStmt->fetchAll(PDO::FETCH_ASSOC)
        );
        $milestoneDates['food_streak'] = [
            1 => athleteMedalStreakCompletionDate($foodDates, 7),
        ];

        foreach ($definitions as &$definition) {
            $definition['earned_at'] = $milestoneDates[$definition['category']][$definition['threshold']] ?? null;
        }
        unset($definition);

        usort($definitions, static function (array $a, array $b): int {
            $aEarned = $a['earned_at'] !== null;
            $bEarned = $b['earned_at'] !== null;
            if ($aEarned !== $bEarned) {
                return $aEarned ? -1 : 1;
            }

            if ($aEarned && $bEarned && $a['earned_at'] !== $b['earned_at']) {
                return strcmp((string)$b['earned_at'], (string)$a['earned_at']);
            }

            return 0;
        });

        return $definitions;
    }
}

if (!function_exists('athleteMedalImageUrl')) {
    function athleteMedalImageUrl(string $image): string
    {
        $version = hash_file('sha256', __DIR__ . '/../assets/medals/' . $image);
        if ($version === false) {
            throw new RuntimeException('Unable to version medal image: ' . $image);
        }

        return BASE_URL . '/assets/medals/' . rawurlencode($image) . '?v=' . $version;
    }
}

if (!function_exists('renderAthleteMedalCard')) {
    function renderAthleteMedalCard(array $medal): void
    {
        $earnedAt = $medal['earned_at'] ?? null;
        $earned = $earnedAt !== null;
        ?>
        <div class="medal-card<?= $earned ? ' is-earned' : ' is-locked' ?>">
            <img
                class="medal-card__image"
                src="<?= h(athleteMedalImageUrl((string)$medal['image'])) ?>"
                alt="<?= h((string)$medal['title']) ?>"
                loading="lazy"
            >
            <div class="medal-card__title"><?= h((string)$medal['title']) ?></div>
            <div class="medal-card__date">
                <?= $earned ? 'Získáno ' . h(formatDate((string)$earnedAt)) : 'Zatím nezískáno' ?>
            </div>
        </div>
        <?php
    }
}
