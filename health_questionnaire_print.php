<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/health_questionnaire.php';

requireLogin();

$coachId = getCurrentCoachId();
$athleteId = intParam($_GET, 'athlete_id');
$pdo = getDB();

$athleteStmt = $pdo->prepare(
    'SELECT a.id, a.first_name, a.last_name, a.coach_id, c.name AS coach_name, c.username AS coach_username
     FROM athletes a
     JOIN coaches c ON c.id = a.coach_id
     WHERE a.id = ? AND a.coach_id = ?
     LIMIT 1'
);
$athleteStmt->execute([$athleteId, $coachId]);
$athlete = $athleteStmt->fetch();

if (!$athlete) {
    flash('danger', 'Sportovec nenalezen.');
    redirect(BASE_URL . '/dashboard.php');
}

$latestSubmission = healthQuestionnaireFetchLatestSubmission($pdo, $athleteId);
$healthStatus = healthQuestionnaireFetchStatus($pdo, $athleteId);
$updates = healthQuestionnaireFetchUpdatesForCoach($pdo, $athleteId, 300);
$questions = healthQuestionnaireFetchQuestions($pdo, true);

$questionMetaByKey = [];
foreach ($questions as $question) {
    $questionKey = (string)($question['question_key'] ?? '');
    if ($questionKey === '') {
        continue;
    }
    $questionMetaByKey[$questionKey] = [
        'step' => (int)($question['step_index'] ?? 0),
        'section' => (string)($question['section_title'] ?? ''),
        'label' => (string)($question['question_label'] ?? $questionKey),
        'sort' => (int)($question['sort_order'] ?? 9999),
    ];
}

$answers = is_array($latestSubmission['answers'] ?? null) ? $latestSubmission['answers'] : [];
$questionRows = [];
$seenKeys = [];

foreach ($questions as $question) {
    $questionKey = (string)($question['question_key'] ?? '');
    if ($questionKey === '') {
        continue;
    }

    $inputType = (string)($question['input_type'] ?? 'text');
    $rowOptions = [];
    if ($inputType === 'yes_no' || $inputType === 'consent') {
        $rowOptions = ['ano' => 'Ano', 'ne' => 'Ne'];
    } elseif (is_array($question['options'] ?? null)) {
        foreach ((array)$question['options'] as $optionKey => $optionLabel) {
            $value = is_string($optionKey) ? $optionKey : (string)$optionLabel;
            $label = is_string($optionLabel) ? $optionLabel : (string)$optionKey;
            $rowOptions[$value] = $label;
        }
    }

    $answerValue = $answers[$questionKey] ?? null;
    $selectedValues = [];
    if (is_array($answerValue)) {
        foreach ($answerValue as $item) {
            $itemValue = trim((string)$item);
            if ($itemValue !== '') {
                $selectedValues[] = $itemValue;
            }
        }
    } elseif ($answerValue !== null) {
        $singleValue = trim((string)$answerValue);
        if ($singleValue !== '') {
            $selectedValues[] = $singleValue;
        }
    }

    $questionRows[] = [
        'question_key' => $questionKey,
        'step' => (int)($question['step_index'] ?? 0),
        'section' => (string)($question['section_title'] ?? ''),
        'label' => (string)($question['question_label'] ?? $questionKey),
        'sort' => (int)($question['sort_order'] ?? 9999),
        'input_type' => $inputType,
        'options' => $rowOptions,
        'required' => (int)($question['is_required'] ?? 0) === 1,
        'value' => $answerValue,
        'selected_values' => $selectedValues,
        'has_answer' => array_key_exists($questionKey, $answers),
    ];

    $seenKeys[$questionKey] = true;
}

$legacyAnswerRows = [];
foreach ($answers as $questionKey => $value) {
    $questionKey = (string)$questionKey;
    if (isset($seenKeys[$questionKey])) {
        continue;
    }

    $meta = $questionMetaByKey[$questionKey] ?? null;
    $legacyAnswerRows[] = [
        'question_key' => $questionKey,
        'step' => (int)($meta['step'] ?? 99),
        'section' => (string)($meta['section'] ?? 'Další údaje'),
        'label' => (string)($meta['label'] ?? $questionKey),
        'sort' => (int)($meta['sort'] ?? 9999),
        'value' => $value,
    ];
}

usort($questionRows, static function (array $a, array $b): int {
    if ($a['step'] !== $b['step']) {
        return $a['step'] <=> $b['step'];
    }
    if ($a['sort'] !== $b['sort']) {
        return $a['sort'] <=> $b['sort'];
    }
    return strcmp((string)$a['label'], (string)$b['label']);
});

usort($legacyAnswerRows, static function (array $a, array $b): int {
    if ($a['step'] !== $b['step']) {
        return $a['step'] <=> $b['step'];
    }
    if ($a['sort'] !== $b['sort']) {
        return $a['sort'] <=> $b['sort'];
    }
    return strcmp((string)$a['label'], (string)$b['label']);
});

if (!function_exists('healthPrintFormatValue')) {
    function healthPrintFormatValue(mixed $value): string
    {
        if (is_array($value)) {
            $items = [];
            foreach ($value as $item) {
                $item = trim((string)$item);
                if ($item !== '') {
                    $items[] = $item;
                }
            }
            return empty($items) ? '–' : implode(', ', $items);
        }

        $text = trim((string)$value);
        if ($text === '') {
            return '–';
        }

        if ($text === 'ano') {
            return 'Ano';
        }
        if ($text === 'ne') {
            return 'Ne';
        }

        return $text;
    }
}

if (!function_exists('healthPrintInputTypeLabel')) {
    function healthPrintInputTypeLabel(string $inputType): string
    {
        $labels = [
            'yes_no' => 'Ano / Ne',
            'single' => 'Jedna volba',
            'multi' => 'Více voleb',
            'text' => 'Krátký text',
            'textarea' => 'Delší text',
            'number' => 'Číslo',
            'date' => 'Datum',
            'consent' => 'Souhlas',
        ];

        return $labels[$inputType] ?? $inputType;
    }
}

$athleteName = trim((string)$athlete['first_name'] . ' ' . (string)$athlete['last_name']);
$coachName = trim((string)($athlete['coach_name'] ?: $athlete['coach_username']));
$printedAt = date('d.m.Y H:i');
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Zdravotní dotazník - tisk</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <style>
        body {
            background: #f8fafc;
        }

        .print-shell {
            max-width: 980px;
            margin: 1.2rem auto;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            box-shadow: 0 6px 30px rgba(15, 23, 42, .08);
        }

        .print-header {
            padding: 1.1rem 1.2rem;
            border-bottom: 1px solid #e5e7eb;
        }

        .print-controls {
            padding: .9rem 1.2rem;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            gap: .5rem;
            flex-wrap: wrap;
        }

        .print-body {
            padding: 1rem 1.2rem 1.3rem;
        }

        .section-title {
            font-weight: 700;
            margin-top: 1rem;
            margin-bottom: .55rem;
        }

        .table td,
        .table th {
            vertical-align: top;
        }

        .meta-chip {
            display: inline-block;
            border: 1px solid #d1d5db;
            border-radius: 999px;
            padding: .15rem .55rem;
            margin-right: .3rem;
            font-size: .8rem;
            color: #374151;
            background: #fff;
        }

        @media print {
            body {
                background: #fff;
            }

            .print-shell {
                margin: 0;
                max-width: none;
                border: 0;
                border-radius: 0;
                box-shadow: none;
            }

            .print-controls {
                display: none !important;
            }

            a[href]:after {
                content: '';
            }
        }
    </style>
</head>
<body>
<div class="print-shell">
    <div class="print-header">
        <h3 class="mb-2">Zdravotní dotazník sportovce</h3>
        <div class="mb-2">
            <span class="meta-chip">Sportovec: <?= h($athleteName) ?></span>
            <span class="meta-chip">Trenér: <?= h($coachName) ?></span>
            <span class="meta-chip">Tisk: <?= h($printedAt) ?></span>
            <?php if (!empty($healthStatus['filled_at'])): ?>
            <span class="meta-chip">Vyplněno: <?= h(formatDateTime((string)$healthStatus['filled_at'])) ?></span>
            <?php endif; ?>
        </div>
        <div class="fw-semibold <?= ($healthStatus['state'] ?? 'missing') === 'missing' ? 'text-danger' : ((($healthStatus['state'] ?? 'ok') === 'warning') ? 'text-warning' : 'text-success') ?>">
            <?= h((string)($healthStatus['label'] ?? '')) ?>
        </div>
    </div>

    <div class="print-controls">
        <button type="button" class="btn btn-primary" onclick="window.print()">
            Tisknout
        </button>
        <a href="<?= BASE_URL ?>/athlete_detail.php?id=<?= (int)$athleteId ?>#health-questionnaire" class="btn btn-outline-secondary">
            Zpět na detail
        </a>
    </div>

    <div class="print-body">
        <?php if ($latestSubmission === null): ?>
        <div class="alert alert-danger mb-0">Sportovec zatím zdravotní dotazník nevyplnil.</div>
        <?php else: ?>
            <div class="section-title">Kompletní výstup dotazníku (včetně možností)</div>
            <div class="table-responsive">
                <table class="table table-sm table-bordered">
                    <thead class="table-light">
                        <tr>
                            <th style="width:50px">#</th>
                            <th style="width:210px">Sekce</th>
                            <th>Otázka</th>
                            <th style="width:120px">Typ</th>
                            <th style="width:300px">Možnosti</th>
                            <th style="width:230px">Odpověď sportovce</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($questionRows)): ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted">Bez dostupných otázek.</td>
                        </tr>
                        <?php else: ?>
                            <?php foreach ($questionRows as $idx => $row): ?>
                            <tr>
                                <td><?= (int)($idx + 1) ?></td>
                                <td>
                                    <?php if ((int)$row['step'] > 0 && (int)$row['step'] < 99): ?>
                                        Krok <?= (int)$row['step'] ?>
                                    <?php else: ?>
                                        –
                                    <?php endif; ?>
                                    <br>
                                    <span class="text-muted small"><?= h((string)$row['section']) ?></span>
                                </td>
                                <td>
                                    <?= h((string)$row['label']) ?>
                                    <?php if (!empty($row['required'])): ?>
                                    <span class="badge bg-light text-dark border ms-1">Povinné</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= h(healthPrintInputTypeLabel((string)$row['input_type'])) ?></td>
                                <td>
                                    <?php if (empty($row['options'])): ?>
                                        <span class="text-muted">Volby nejsou definovány.</span>
                                    <?php else: ?>
                                        <?php foreach ((array)$row['options'] as $optValue => $optLabel): ?>
                                            <?php $isSelected = in_array((string)$optValue, (array)$row['selected_values'], true); ?>
                                            <div>
                                                <span class="<?= $isSelected ? 'fw-semibold text-success' : 'text-muted' ?>">
                                                    <?= $isSelected ? '✓' : '○' ?> <?= h((string)$optLabel) ?>
                                                </span>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($row['has_answer'])): ?>
                                        <?= nl2br(h(healthPrintFormatValue($row['value']))) ?>
                                    <?php else: ?>
                                        <span class="text-muted">Nevyplněno</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if (!empty($legacyAnswerRows)): ?>
            <div class="section-title">Historické odpovědi mimo aktuální otázky</div>
            <div class="table-responsive">
                <table class="table table-sm table-bordered">
                    <thead class="table-light">
                        <tr>
                            <th style="width:50px">#</th>
                            <th style="width:220px">Sekce</th>
                            <th>Položka</th>
                            <th style="width:280px">Uložená hodnota</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($legacyAnswerRows as $idx => $row): ?>
                        <tr>
                            <td><?= (int)($idx + 1) ?></td>
                            <td>
                                <?php if ((int)$row['step'] > 0 && (int)$row['step'] < 99): ?>
                                    Krok <?= (int)$row['step'] ?>
                                <?php else: ?>
                                    –
                                <?php endif; ?>
                                <br>
                                <span class="text-muted small"><?= h((string)$row['section']) ?></span>
                            </td>
                            <td><?= h((string)$row['label']) ?></td>
                            <td><?= nl2br(h(healthPrintFormatValue($row['value']))) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <?php if (!empty($latestSubmission['alerts'])): ?>
            <div class="section-title">Upozornění z dotazníku</div>
            <ul class="mb-0">
                <?php foreach ($latestSubmission['alerts'] as $alert): ?>
                <li><?= h((string)($alert['alert_text'] ?? 'Upozornění')) ?></li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>

            <?php if (!empty($updates)): ?>
            <div class="section-title">Nahlášené změny zdravotního stavu</div>
            <?php
            $updateCategoryLabels = [
                'omezeni' => 'Omezení',
                'zraneni' => 'Zranění',
                'leky' => 'Léky',
                'alergie' => 'Alergie',
                'stav' => 'Změna stavu',
                'jine' => 'Jiné',
            ];
            $updateSeverityLabels = [
                'info' => 'Informace',
                'warning' => 'Střední',
                'critical' => 'Vysoká',
            ];
            ?>
            <div class="table-responsive">
                <table class="table table-sm table-bordered">
                    <thead class="table-light">
                        <tr>
                            <th style="width:160px">Datum</th>
                            <th style="width:120px">Typ</th>
                            <th style="width:110px">Důležitost</th>
                            <th>Detail</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($updates as $update): ?>
                        <tr>
                            <td><?= h(formatDateTime((string)$update['created_at'])) ?></td>
                            <td><?= h((string)($updateCategoryLabels[(string)($update['change_category'] ?? '')] ?? (string)($update['change_category'] ?? ''))) ?></td>
                            <td><?= h((string)($updateSeverityLabels[(string)($update['severity'] ?? '')] ?? (string)($update['severity'] ?? ''))) ?></td>
                            <td><?= nl2br(h((string)$update['change_details'])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
