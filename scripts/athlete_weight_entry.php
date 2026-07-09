<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

header('Referrer-Policy: no-referrer');

$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$invite = getAthleteWeightInviteByToken($token);

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$invite) {
        $error = 'Odkaz je neplatny nebo uz vyprsel.';
    } else {
        $weightInput = str_replace(',', '.', trim((string)($_POST['weight_kg'] ?? '')));
        $measuredAt = preg_replace('/[^0-9\-]/', '', (string)($_POST['measured_at'] ?? date('Y-m-d')));
        $weightKg = is_numeric($weightInput) ? (float)$weightInput : 0.0;

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $measuredAt)) {
            $error = 'Zadejte platne datum vazeni.';
        } elseif ($weightKg < 20 || $weightKg > 400) {
            $error = 'Zadejte platnou hmotnost v kg.';
        } else {
            addAthleteWeightLog(
                (int)$invite['athlete_id'],
                $measuredAt,
                $weightKg,
                'athlete_link',
                null,
                (int)$invite['id']
            );
            markAthleteWeightInviteUsed((int)$invite['id']);
            $success = 'Dekujeme, vase hmotnost byla ulozena.';
        }
    }
}

$athleteName = $invite
    ? trim((string)$invite['first_name'] . ' ' . (string)$invite['last_name'])
    : 'Sportovec';
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>Zadani hmotnosti - TrainerApp</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
<div class="container py-5" style="max-width: 560px;">
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-primary text-white">
            <h1 class="h5 mb-0">Zadani aktualni hmotnosti</h1>
        </div>
        <div class="card-body p-4">
            <?php if ($success !== ''): ?>
            <div class="alert alert-success mb-0"><?= h($success) ?></div>
            <?php elseif (!$invite): ?>
            <div class="alert alert-danger mb-0">Odkaz je neplatny, vyprsel nebo uz byl pouzit.</div>
            <?php else: ?>
            <p class="text-muted mb-3">
                Ahoj <strong><?= h($athleteName) ?></strong>, zadej prosim aktualni telesnou hmotnost.
            </p>

            <?php if ($error !== ''): ?>
            <div class="alert alert-danger"><?= h($error) ?></div>
            <?php endif; ?>

            <form method="post" novalidate>
                <input type="hidden" name="token" value="<?= h($token) ?>">

                <div class="mb-3">
                    <label class="form-label fw-semibold">Datum vazeni</label>
                    <input type="date" name="measured_at" class="form-control"
                           value="<?= h((string)($_POST['measured_at'] ?? date('Y-m-d'))) ?>" required>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Hmotnost (kg)</label>
                    <input type="number" name="weight_kg" class="form-control"
                           min="20" max="400" step="0.1" placeholder="napr. 78.4"
                           value="<?= h((string)($_POST['weight_kg'] ?? '')) ?>" required>
                </div>

                <button type="submit" class="btn btn-primary w-100 fw-semibold">
                    Ulozit hmotnost
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
