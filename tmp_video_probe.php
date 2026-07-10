<?php
require __DIR__ . '/config/config.php';
require __DIR__ . '/config/database.php';
$pdo = getDB();
$rows = $pdo->query("SELECT id, coach_id, file_path, original_name, created_at FROM video_files ORDER BY id DESC LIMIT 15")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo implode("\t", [$r['id'], $r['coach_id'], $r['file_path'], $r['original_name'], $r['created_at']]) . PHP_EOL;
}
?>
