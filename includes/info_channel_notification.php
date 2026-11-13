<?php

function renderUnreadInfoChannelNotification(PDO $pdo, string $userType, int $userId): void
{
    $reader = match ($userType) {
        'athlete' => [
            'table' => 'info_article_reads_athlete',
            'column' => 'athlete_id',
            'page' => 'athlete_infokanal.php',
        ],
        'coach' => [
            'table' => 'info_article_reads_coach',
            'column' => 'coach_id',
            'page' => 'infokanal.php',
        ],
        default => throw new InvalidArgumentException('Unsupported info channel audience.'),
    };

    if ($userId <= 0) {
        return;
    }

    $stmt = $pdo->prepare(
        "SELECT ia.id, ia.title, ic.id AS category_id
         FROM info_articles ia
         JOIN info_categories ic ON ic.id = ia.category_id
         LEFT JOIN {$reader['table']} ir
            ON ir.article_id = ia.id AND ir.{$reader['column']} = ?
         WHERE ia.is_active = 1
           AND ia.published_at <= NOW()
           AND ia.target_audience IN ('all', ?)
           AND ic.is_active = 1
           AND ic.audience IN ('all', ?)
           AND ir.article_id IS NULL
         ORDER BY ia.published_at DESC, ia.id DESC
         LIMIT 1"
    );
    $stmt->execute([$userId, $userType, $userType]);
    $article = $stmt->fetch();
    if (!$article) {
        return;
    }

    $articleId = (int)$article['id'];
    $sessionKey = 'info_channel_notice_' . $userType . '_' . $userId . '_' . $articleId;
    if (!empty($_SESSION[$sessionKey])) {
        return;
    }
    $_SESSION[$sessionKey] = true;

    $articleUrl = BASE_URL . '/' . $reader['page'] . '?category='
        . (int)$article['category_id'] . '&article=' . $articleId;
    ?>
    <div class="modal fade" id="infoChannelAnnouncementModal" tabindex="-1"
         aria-labelledby="infoChannelAnnouncementTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg">
                <div class="modal-header text-white" style="background:#1e1e2e;border-bottom:2px solid #a78bfa">
                    <h5 class="modal-title fw-bold" id="infoChannelAnnouncementTitle">
                        <i class="fas fa-lightbulb me-2" style="color:#c4b5fd"></i>Novinka v Infokanálu
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Zavřít"></button>
                </div>
                <div class="modal-body text-center py-4">
                    <p class="text-muted mb-2">Byl přidán nový příspěvek:</p>
                    <a class="fw-bold fs-5 text-decoration-none" href="<?= h($articleUrl) ?>">
                        Infokanál: <?= h((string)$article['title']) ?>
                    </a>
                </div>
                <div class="modal-footer justify-content-between">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Později</button>
                    <a class="btn btn-primary fw-semibold" href="<?= h($articleUrl) ?>">
                        <i class="fas fa-arrow-right me-1"></i>Otevřít článek
                    </a>
                </div>
            </div>
        </div>
    </div>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var element = document.getElementById('infoChannelAnnouncementModal');
        if (!element || typeof bootstrap === 'undefined') return;

        var modal = bootstrap.Modal.getOrCreateInstance(element);
        var openModal = function () { modal.show(); };
        var activeModal = document.querySelector('.modal.show:not(#infoChannelAnnouncementModal)');
        if (activeModal) {
            activeModal.addEventListener('hidden.bs.modal', openModal, { once: true });
        } else {
            openModal();
        }
    });
    </script>
    <?php
}
