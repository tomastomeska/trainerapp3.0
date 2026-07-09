<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

header('Referrer-Policy: no-referrer');

$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$request = getPasswordResetRequestByToken($token);
$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Neplatný bezpečnostní token.';
    } elseif (!$request) {
        $error = 'Odkaz pro reset hesla je neplatný nebo vypršel.';
    } else {
        $newPassword = (string)($_POST['new_password'] ?? '');
        $newPasswordConfirm = (string)($_POST['new_password_confirm'] ?? '');

        if (strlen($newPassword) < 8) {
            $error = 'Nové heslo musí mít alespoň 8 znaků.';
        } elseif ($newPassword !== $newPasswordConfirm) {
            $error = 'Hesla se neshodují.';
        } else {
            $pdo = getDB();
            $hash = password_hash($newPassword, PASSWORD_DEFAULT);

            if (($request['user_type'] ?? '') === 'athlete') {
                $athleteId = (int)($request['athlete_id'] ?? 0);
                $stmt = $pdo->prepare('UPDATE athletes SET password = ?, force_password_change = 0 WHERE id = ? LIMIT 1');
                $stmt->execute([$hash, $athleteId]);
            } else {
                $coachId = (int)($request['coach_id'] ?? 0);
                $stmt = $pdo->prepare('UPDATE coaches SET password = ? WHERE id = ? LIMIT 1');
                $stmt->execute([$hash, $coachId]);
            }

            markPasswordResetRequestUsed((int)$request['id']);
            $success = 'Heslo bylo úspěšně změněno. Nyní se můžete přihlásit.';
            $request = null;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>Reset hesla - <?= h(APP_NAME) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <style>
        body {
            min-height: 100vh;
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 18px;
            background: linear-gradient(150deg, #050b18 0%, #0a1531 35%, #11285f 100%);
        }
        .reset-card {
            width: 100%;
            max-width: 520px;
            border: 0;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 18px 48px rgba(7, 18, 44, 0.45);
        }
        .reset-card .card-header {
            background: #0f234f;
            color: #fff;
            font-weight: 700;
        }
        .btn-primary {
            font-weight: 700;
        }
    </style>
</head>
<body>
<div class="card reset-card">
    <div class="card-header py-3 px-4">
        <i class="fas fa-key me-2"></i>Reset hesla
    </div>
    <div class="card-body p-4">
        <?php if ($success): ?>
            <div class="alert alert-success"><?= h($success) ?></div>
            <a href="<?= BASE_URL ?>/login.php" class="btn btn-primary">Přejít na přihlášení</a>
        <?php elseif (!$request): ?>
            <div class="alert alert-danger mb-3">Odkaz pro reset hesla je neplatný nebo vypršel.</div>
            <a href="<?= BASE_URL ?>/login.php" class="btn btn-outline-secondary">Zpět na přihlášení</a>
        <?php else: ?>
            <p class="text-muted mb-3">Zadejte nové heslo pro svůj účet.</p>

            <?php if ($error): ?>
                <div class="alert alert-danger py-2"><?= h($error) ?></div>
            <?php endif; ?>

            <form method="post" novalidate>
                <?= csrfField() ?>
                <input type="hidden" name="token" value="<?= h($token) ?>">

                <div class="mb-3">
                    <label class="form-label fw-semibold" for="new_password">Nové heslo</label>
                    <input id="new_password" type="password" name="new_password" class="form-control" minlength="8" required>
                </div>

                <div class="mb-4">
                    <label class="form-label fw-semibold" for="new_password_confirm">Potvrzení nového hesla</label>
                    <input id="new_password_confirm" type="password" name="new_password_confirm" class="form-control" minlength="8" required>
                </div>

                <button type="submit" class="btn btn-primary w-100">Uložit nové heslo</button>
            </form>
        <?php endif; ?>
    </div>
</div>
<script src="https://kit.fontawesome.com/a2e0e6ad65.js" crossorigin="anonymous"></script>
</body>
</html>
