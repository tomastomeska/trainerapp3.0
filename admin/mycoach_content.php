<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/header.php';

requireAdminLogin();

$pdo = getDB();

// ── Migrace tabulek za běhu (pokud ještě neproběhla) ─────────
foreach ([
    'mycoach_app_sections','mycoach_app_videos','mycoach_app_exercises',
    'mycoach_app_workouts','mycoach_app_workout_exercises','mycoach_app_foods',
] as $tbl) {
    $r = $pdo->query("SHOW TABLES LIKE '$tbl'");
    if (!$r || !$r->fetch()) {
        flash('warning', "Tabulka $tbl chybí – spusťte scripts/migrate_mycoach_app.php");
        break;
    }
}

try {
  $col = $pdo->query("SHOW COLUMNS FROM mycoach_app_workouts LIKE 'category'");
  if ($col && !$col->fetch()) {
    $pdo->exec("ALTER TABLE mycoach_app_workouts ADD COLUMN category VARCHAR(255) NULL AFTER title");
  }
} catch (Throwable $e) {
  // Pokud migrace sloupce selže, pokračujeme bez pádu stránky.
}

$sectionTypes   = mycoachAppSectionTypes();
$tileColors     = ['orange','yellow','red','blue','green','teal','purple'];
$difficultyOpts = ['beginner'=>'Začátečník','intermediate'=>'Středně pokročilý','advanced'=>'Pokročilý'];

function mcSplitListValues(?string $raw): array {
  $value = trim((string)$raw);
  if ($value === '') { return []; }
  $parts = preg_split('/[,;|\/]+/', $value);
  if (!is_array($parts)) { return []; }
  $out = [];
  $seen = [];
  foreach ($parts as $part) {
    $part = trim((string)$part);
    if ($part === '') { continue; }
    $k = mb_strtolower($part, 'UTF-8');
    if (isset($seen[$k])) { continue; }
    $seen[$k] = true;
    $out[] = $part;
  }
  return $out;
}

function mcNormalizeListString(?string $raw): string {
  return implode(', ', mcSplitListValues($raw));
}

function mcNormalizeCsvHeader(string $header): string {
  $header = trim(mb_strtolower($header, 'UTF-8'));
  $header = strtr($header, [
    'á'=>'a','ä'=>'a','č'=>'c','ď'=>'d','é'=>'e','ě'=>'e','ë'=>'e','í'=>'i','ĺ'=>'l','ľ'=>'l','ň'=>'n',
    'ó'=>'o','ô'=>'o','ö'=>'o','ř'=>'r','š'=>'s','ť'=>'t','ú'=>'u','ů'=>'u','ü'=>'u','ý'=>'y','ž'=>'z',
  ]);
  $header = preg_replace('/\s+/', '_', $header) ?? '';
  $header = preg_replace('/[^a-z0-9_]/', '', $header) ?? '';
  return $header;
}

// ── POST akce ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Neplatný token.');
        redirect(BASE_URL . '/admin/mycoach_content.php');
    }

    $action = trim((string)($_POST['action'] ?? ''));

    // ── Sekce ──────────────────────────────────────────────
    if ($action === 'save_section') {
        $id       = (int)($_POST['section_id'] ?? 0);
        $title    = trim((string)($_POST['title'] ?? ''));
        $subtitle = trim((string)($_POST['subtitle'] ?? ''));
        $icon     = preg_match('/^fa-[a-z0-9-]+$/', trim((string)($_POST['icon_class'] ?? ''))) ? trim((string)$_POST['icon_class']) : 'fa-play-circle';
        $color    = in_array(trim((string)($_POST['tile_color'] ?? '')), $tileColors, true) ? trim((string)$_POST['tile_color']) : 'orange';
        $type     = isset($sectionTypes[trim((string)($_POST['section_type'] ?? ''))]) ? trim((string)$_POST['section_type']) : 'videos';
        $desc     = trim((string)($_POST['description'] ?? ''));
        $sort     = (int)($_POST['sort_order'] ?? 0);
        $active   = isset($_POST['is_active']) ? 1 : 0;
        $audience = in_array(trim((string)($_POST['audience'] ?? 'all')), ['all','coach','athlete'], true) ? trim((string)$_POST['audience']) : 'all';

        if ($title === '') { flash('danger', 'Název sekce je povinný.'); redirect(BASE_URL . '/admin/mycoach_content.php'); }

        if ($id > 0) {
            $pdo->prepare('UPDATE mycoach_app_sections SET title=?,subtitle=?,icon_class=?,tile_color=?,section_type=?,description=?,sort_order=?,is_active=?,audience=? WHERE id=?')
                ->execute([$title,$subtitle,$icon,$color,$type,$desc,$sort,$active,$audience,$id]);
            flash('success', 'Sekce byla uložena.');
            redirect(BASE_URL . '/admin/mycoach_content.php?sec=' . $id);
        } else {
            $pdo->prepare('INSERT INTO mycoach_app_sections (title,subtitle,icon_class,tile_color,section_type,description,sort_order,is_active,audience) VALUES (?,?,?,?,?,?,?,?,?)')
                ->execute([$title,$subtitle,$icon,$color,$type,$desc,$sort,$active,$audience]);
            flash('success', 'Sekce byla přidána. Klikněte na novou záložku pro přidání obsahu.');
            redirect(BASE_URL . '/admin/mycoach_content.php?sec=' . (int)$pdo->lastInsertId());
        }
    }

    if ($action === 'delete_section') {
        $id = (int)($_POST['section_id'] ?? 0);
        if ($id > 0) { $pdo->prepare('DELETE FROM mycoach_app_sections WHERE id=?')->execute([$id]); flash('success','Sekce smazána.'); }
        redirect(BASE_URL . '/admin/mycoach_content.php#sections');
    }

    // ── Videa ──────────────────────────────────────────────
    if ($action === 'save_video') {
        $id       = (int)($_POST['video_id'] ?? 0);
        $secId    = (int)($_POST['section_id'] ?? 0) ?: null;
        $title    = trim((string)($_POST['title'] ?? ''));
        $desc     = trim((string)($_POST['description'] ?? ''));
        $vtype    = in_array(trim((string)($_POST['video_type'] ?? '')), ['upload','youtube','vimeo','url'], true) ? trim((string)$_POST['video_type']) : 'youtube';
        $vurl     = trim((string)($_POST['video_url'] ?? ''));
        $vpath    = trim((string)($_POST['video_path'] ?? ''));
        $thumb    = trim((string)($_POST['thumbnail'] ?? ''));
        $dur      = is_numeric($_POST['duration_seconds'] ?? '') ? (int)$_POST['duration_seconds'] : null;
        $tags     = trim((string)($_POST['tags'] ?? ''));
        $sort     = (int)($_POST['sort_order'] ?? 0);
        $active   = isset($_POST['is_active']) ? 1 : 0;

        if ($title === '') { 
            $redirectTarget = $secId > 0 ? (BASE_URL . '/admin/mycoach_content.php?sec=' . $secId) : (BASE_URL . '/admin/mycoach_content.php#videos');
            flash('danger', 'Název videa je povinný.'); 
            redirect($redirectTarget); 
        }

        if ($id > 0) {
            $pdo->prepare('UPDATE mycoach_app_videos SET section_id=?,title=?,description=?,video_type=?,video_url=?,video_path=?,thumbnail=?,duration_seconds=?,tags=?,sort_order=?,is_active=? WHERE id=?')
                ->execute([$secId,$title,$desc,$vtype,$vurl,$vpath,$thumb,$dur,$tags,$sort,$active,$id]);
            flash('success', 'Video bylo uloženo.');
        } else {
            $pdo->prepare('INSERT INTO mycoach_app_videos (section_id,title,description,video_type,video_url,video_path,thumbnail,duration_seconds,tags,sort_order,is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$secId,$title,$desc,$vtype,$vurl,$vpath,$thumb,$dur,$tags,$sort,$active]);
            flash('success', 'Video bylo přidáno.');
        }
        $redirectTarget = $secId > 0 ? (BASE_URL . '/admin/mycoach_content.php?sec=' . $secId) : (BASE_URL . '/admin/mycoach_content.php#videos');
        redirect($redirectTarget);
    }

    if ($action === 'delete_video') {
        $id = (int)($_POST['video_id'] ?? 0);
        $secId = (int)($_POST['section_id'] ?? 0);
        if ($id > 0) { $pdo->prepare('DELETE FROM mycoach_app_videos WHERE id=?')->execute([$id]); flash('success','Video smazáno.'); }
        $redirectTarget = $secId > 0 ? (BASE_URL . '/admin/mycoach_content.php?sec=' . $secId) : (BASE_URL . '/admin/mycoach_content.php#videos');
        redirect($redirectTarget);
    }

    // ── Cviky ──────────────────────────────────────────────
    if ($action === 'save_exercise') {
        $id     = (int)($_POST['exercise_id'] ?? 0);
        $name   = trim((string)($_POST['name'] ?? ''));
        $slug   = mycoachAppExerciseSlugify($name);
      $cat    = mcNormalizeListString((string)($_POST['category'] ?? ''));
        $desc   = trim((string)($_POST['description'] ?? ''));
        $instr  = trim((string)($_POST['instructions'] ?? ''));
      $muscles= mcNormalizeListString((string)($_POST['muscle_groups'] ?? ''));
        $equip  = trim((string)($_POST['equipment'] ?? ''));
        $diff   = in_array(trim((string)($_POST['difficulty'] ?? '')), ['beginner','intermediate','advanced'], true) ? trim((string)$_POST['difficulty']) : 'intermediate';
        $vurl   = trim((string)($_POST['video_url'] ?? ''));
        // Nahraný soubor videa má přednost před URL
        $vupload = trim((string)($_POST['video_path_upload'] ?? ''));
        if ($vupload !== '') $vurl = $vupload;
        $thumb  = trim((string)($_POST['thumbnail'] ?? ''));
        // Nahraný obrázek má přednost
        $thumbUp = trim((string)($_POST['thumbnail_upload'] ?? ''));
        if ($thumbUp !== '') $thumb = $thumbUp;
        $sort   = (int)($_POST['sort_order'] ?? 0);
        $active = isset($_POST['is_active']) ? 1 : 0;

        if ($name === '') { flash('danger', 'Název cviku je povinný.'); redirect(BASE_URL . '/admin/mycoach_content.php#exercises'); }

        if ($id > 0) {
            $baseSlug = $slug; $i = 1;
            while (true) {
                $chk = $pdo->prepare('SELECT id FROM mycoach_app_exercises WHERE slug=? AND id != ? LIMIT 1');
                $chk->execute([$slug, $id]);
                if (!$chk->fetch()) break;
                $slug = $baseSlug . '-' . $i++;
            }
            $pdo->prepare('UPDATE mycoach_app_exercises SET name=?,slug=?,category=?,description=?,instructions=?,muscle_groups=?,equipment=?,difficulty=?,video_url=?,thumbnail=?,sort_order=?,is_active=? WHERE id=?')
                ->execute([$name,$slug,$cat,$desc,$instr,$muscles,$equip,$diff,$vurl,$thumb,$sort,$active,$id]);
            flash('success', 'Cvik byl uložen.');
        } else {
            // Unikátní slug při duplicitách
            $baseSlug = $slug; $i = 1;
            while (true) {
                $chk = $pdo->prepare('SELECT id FROM mycoach_app_exercises WHERE slug=? LIMIT 1');
                $chk->execute([$slug]);
                if (!$chk->fetch()) break;
                $slug = $baseSlug . '-' . $i++;
            }
            $pdo->prepare('INSERT INTO mycoach_app_exercises (name,slug,category,description,instructions,muscle_groups,equipment,difficulty,video_url,thumbnail,sort_order,is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$name,$slug,$cat,$desc,$instr,$muscles,$equip,$diff,$vurl,$thumb,$sort,$active]);
            flash('success', 'Cvik byl přidán.');
        }
        redirect(BASE_URL . '/admin/mycoach_content.php#exercises');
    }

    if ($action === 'delete_exercise') {
        $id = (int)($_POST['exercise_id'] ?? 0);
        if ($id > 0) { $pdo->prepare('DELETE FROM mycoach_app_exercises WHERE id=?')->execute([$id]); flash('success','Cvik smazán.'); }
        redirect(BASE_URL . '/admin/mycoach_content.php#exercises');
    }

    if ($action === 'import_exercises_csv') {
      if (empty($_FILES['exercises_csv']['tmp_name']) || (int)($_FILES['exercises_csv']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        flash('danger', 'Vyberte prosím platný CSV soubor pro import cviků.');
        redirect(BASE_URL . '/admin/mycoach_content.php#exercises');
      }

      $handle = fopen((string)$_FILES['exercises_csv']['tmp_name'], 'r');
      if (!$handle) {
        flash('danger', 'CSV soubor se nepodařilo otevřít.');
        redirect(BASE_URL . '/admin/mycoach_content.php#exercises');
      }

      $bom = fread($handle, 3);
      if ($bom !== "\xEF\xBB\xBF") {
        rewind($handle);
      }

      $headerRow = fgetcsv($handle, 0, ';');
      if (!is_array($headerRow) || empty($headerRow)) {
        fclose($handle);
        flash('danger', 'CSV soubor neobsahuje hlavičku.');
        redirect(BASE_URL . '/admin/mycoach_content.php#exercises');
      }

      $headerMap = [];
      foreach ($headerRow as $idx => $header) {
        $key = mcNormalizeCsvHeader((string)$header);
        if ($key !== '' && !isset($headerMap[$key])) {
          $headerMap[$key] = (int)$idx;
        }
      }

      $findIdx = static function(array $map, array $aliases): ?int {
        foreach ($aliases as $alias) {
          if (isset($map[$alias])) { return (int)$map[$alias]; }
        }
        return null;
      };

      $idxId          = $findIdx($headerMap, ['id']);
      $idxName        = $findIdx($headerMap, ['nazev','name']);
      $idxCategory    = $findIdx($headerMap, ['kategorie','category']);
      $idxDifficulty  = $findIdx($headerMap, ['obtiznost','difficulty']);
      $idxMuscles     = $findIdx($headerMap, ['svalove_partie','muscle_groups']);
      $idxEquipment   = $findIdx($headerMap, ['vybaveni','equipment']);
      $idxVideo       = $findIdx($headerMap, ['video_url','video']);
      $idxThumbnail   = $findIdx($headerMap, ['thumbnail','nahled']);
      $idxDescription = $findIdx($headerMap, ['popis','description']);
      $idxInstr       = $findIdx($headerMap, ['instrukce','instructions']);
      $idxSort        = $findIdx($headerMap, ['poradi','sort_order']);
      $idxActive      = $findIdx($headerMap, ['aktivni','is_active']);

      if ($idxName === null) {
        fclose($handle);
        flash('danger', 'CSV musí obsahovat sloupec "nazev" (nebo "name").');
        redirect(BASE_URL . '/admin/mycoach_content.php#exercises');
      }

      $difficultyMap = [
        'beginner' => 'beginner',
        'zacatecnik' => 'beginner',
        'začátečník' => 'beginner',
        'intermediate' => 'intermediate',
        'stredni' => 'intermediate',
        'středni' => 'intermediate',
        'střední' => 'intermediate',
        'pokrocily' => 'advanced',
        'pokročily' => 'advanced',
        'pokročilý' => 'advanced',
        'advanced' => 'advanced',
      ];

      $activeMap = [
        '1' => 1, 'ano' => 1, 'true' => 1, 'aktivni' => 1, 'aktivní' => 1, 'yes' => 1,
        '0' => 0, 'ne' => 0, 'false' => 0, 'no' => 0,
      ];

      $stmtById = $pdo->prepare('SELECT id FROM mycoach_app_exercises WHERE id=? LIMIT 1');
      $stmtByName = $pdo->prepare('SELECT id FROM mycoach_app_exercises WHERE name=? LIMIT 1');
      $stmtChkSlugIns = $pdo->prepare('SELECT id FROM mycoach_app_exercises WHERE slug=? LIMIT 1');
      $stmtChkSlugUpd = $pdo->prepare('SELECT id FROM mycoach_app_exercises WHERE slug=? AND id != ? LIMIT 1');
      $stmtInsert = $pdo->prepare('INSERT INTO mycoach_app_exercises (name,slug,category,description,instructions,muscle_groups,equipment,difficulty,video_url,thumbnail,sort_order,is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
      $stmtUpdate = $pdo->prepare('UPDATE mycoach_app_exercises SET name=?,slug=?,category=?,description=?,instructions=?,muscle_groups=?,equipment=?,difficulty=?,video_url=?,thumbnail=?,sort_order=?,is_active=? WHERE id=?');

      $inserted = 0;
      $updated  = 0;
      $skipped  = 0;

      while (($row = fgetcsv($handle, 0, ';')) !== false) {
        if (!is_array($row) || count($row) === 0) { continue; }

        $name = trim((string)($row[$idxName] ?? ''));
        if ($name === '') {
          $skipped++;
          continue;
        }

        $cat = mcNormalizeListString((string)($idxCategory !== null ? ($row[$idxCategory] ?? '') : ''));
        $desc = trim((string)($idxDescription !== null ? ($row[$idxDescription] ?? '') : ''));
        $instr = trim((string)($idxInstr !== null ? ($row[$idxInstr] ?? '') : ''));
        $muscles = mcNormalizeListString((string)($idxMuscles !== null ? ($row[$idxMuscles] ?? '') : ''));
        $equip = trim((string)($idxEquipment !== null ? ($row[$idxEquipment] ?? '') : ''));
        $video = trim((string)($idxVideo !== null ? ($row[$idxVideo] ?? '') : ''));
        $thumb = trim((string)($idxThumbnail !== null ? ($row[$idxThumbnail] ?? '') : ''));
        $sort = ($idxSort !== null && is_numeric($row[$idxSort] ?? null)) ? (int)$row[$idxSort] : 0;

        $rawDiff = trim((string)($idxDifficulty !== null ? ($row[$idxDifficulty] ?? '') : ''));
        $diffKey = mb_strtolower($rawDiff, 'UTF-8');
        $difficulty = $difficultyMap[$diffKey] ?? 'intermediate';

        $rawActive = trim((string)($idxActive !== null ? ($row[$idxActive] ?? '') : '1'));
        $activeKey = mb_strtolower($rawActive, 'UTF-8');
        $active = $activeMap[$activeKey] ?? (is_numeric($rawActive) ? ((int)$rawActive > 0 ? 1 : 0) : 1);

        $targetId = 0;
        if ($idxId !== null && is_numeric($row[$idxId] ?? null) && (int)$row[$idxId] > 0) {
          $targetId = (int)$row[$idxId];
          $stmtById->execute([$targetId]);
          if (!$stmtById->fetch()) {
            $targetId = 0;
          }
        }

        if ($targetId <= 0) {
          $stmtByName->execute([$name]);
          $existingByName = $stmtByName->fetchColumn();
          if ($existingByName) { $targetId = (int)$existingByName; }
        }

        $slugBase = mycoachAppExerciseSlugify($name);
        if ($slugBase === '') { $slugBase = 'cvik'; }
        $slug = $slugBase;
        $i = 1;

        if ($targetId > 0) {
          while (true) {
            $stmtChkSlugUpd->execute([$slug, $targetId]);
            if (!$stmtChkSlugUpd->fetch()) { break; }
            $slug = $slugBase . '-' . $i++;
          }
          $stmtUpdate->execute([$name,$slug,$cat,$desc,$instr,$muscles,$equip,$difficulty,$video,$thumb,$sort,$active,$targetId]);
          $updated++;
        } else {
          while (true) {
            $stmtChkSlugIns->execute([$slug]);
            if (!$stmtChkSlugIns->fetch()) { break; }
            $slug = $slugBase . '-' . $i++;
          }
          $stmtInsert->execute([$name,$slug,$cat,$desc,$instr,$muscles,$equip,$difficulty,$video,$thumb,$sort,$active]);
          $inserted++;
        }
      }

      fclose($handle);
      flash('success', 'Import cviků dokončen. Přidáno: ' . $inserted . ', aktualizováno: ' . $updated . ', přeskočeno: ' . $skipped . '.');
      redirect(BASE_URL . '/admin/mycoach_content.php#exercises');
    }

    // ── Tréninky ───────────────────────────────────────────
    if ($action === 'save_workout') {
        $id    = (int)($_POST['workout_id'] ?? 0);
        $secId = (int)($_POST['section_id'] ?? 0) ?: null;
        $title = trim((string)($_POST['title'] ?? ''));
      $category = mcNormalizeListString((string)($_POST['category'] ?? ''));
        $desc  = trim((string)($_POST['description'] ?? ''));
        $diff  = in_array(trim((string)($_POST['difficulty'] ?? '')), ['beginner','intermediate','advanced'], true) ? trim((string)$_POST['difficulty']) : 'intermediate';
        $dur   = is_numeric($_POST['duration_minutes'] ?? '') ? (int)$_POST['duration_minutes'] : null;
        $thumb = trim((string)($_POST['thumbnail'] ?? ''));
        $sort  = (int)($_POST['sort_order'] ?? 0);
        $active= isset($_POST['is_active']) ? 1 : 0;

        if ($title === '') {
            $redirectTarget = $secId > 0 ? (BASE_URL . '/admin/mycoach_content.php?sec=' . $secId) : (BASE_URL . '/admin/mycoach_content.php#workouts');
            flash('danger', 'Název tréninku je povinný.');
            redirect($redirectTarget);
        }

        if ($id > 0) {
          $pdo->prepare('UPDATE mycoach_app_workouts SET section_id=?,title=?,category=?,description=?,difficulty=?,duration_minutes=?,thumbnail=?,sort_order=?,is_active=? WHERE id=?')
            ->execute([$secId,$title,$category,$desc,$diff,$dur,$thumb,$sort,$active,$id]);
        } else {
          $pdo->prepare('INSERT INTO mycoach_app_workouts (section_id,title,category,description,difficulty,duration_minutes,thumbnail,sort_order,is_active) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$secId,$title,$category,$desc,$diff,$dur,$thumb,$sort,$active]);
        }
        flash('success', 'Trénink byl uložen.');
        $redirectTarget = $secId > 0 ? (BASE_URL . '/admin/mycoach_content.php?sec=' . $secId) : (BASE_URL . '/admin/mycoach_content.php#workouts');
        redirect($redirectTarget);
    }

    if ($action === 'delete_workout') {
        $id = (int)($_POST['workout_id'] ?? 0);
        $secId = (int)($_POST['section_id'] ?? 0);
        if ($id > 0) { $pdo->prepare('DELETE FROM mycoach_app_workouts WHERE id=?')->execute([$id]); flash('success','Trénink smazán.'); }
        $redirectTarget = $secId > 0 ? (BASE_URL . '/admin/mycoach_content.php?sec=' . $secId) : (BASE_URL . '/admin/mycoach_content.php#workouts');
        redirect($redirectTarget);
    }

    if ($action === 'import_workouts_csv') {
      if (empty($_FILES['workouts_csv']['tmp_name']) || (int)($_FILES['workouts_csv']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        flash('danger', 'Vyberte prosím platný CSV soubor pro import tréninků.');
        redirect(BASE_URL . '/admin/mycoach_content.php#workouts');
      }

      $handle = fopen((string)$_FILES['workouts_csv']['tmp_name'], 'r');
      if (!$handle) {
        flash('danger', 'CSV soubor tréninků se nepodařilo otevřít.');
        redirect(BASE_URL . '/admin/mycoach_content.php#workouts');
      }

      $bom = fread($handle, 3);
      if ($bom !== "\xEF\xBB\xBF") {
        rewind($handle);
      }

      $headerRow = fgetcsv($handle, 0, ';');
      if (!is_array($headerRow) || empty($headerRow)) {
        fclose($handle);
        flash('danger', 'CSV soubor tréninků neobsahuje hlavičku.');
        redirect(BASE_URL . '/admin/mycoach_content.php#workouts');
      }

      $headerMap = [];
      foreach ($headerRow as $idx => $header) {
        $key = mcNormalizeCsvHeader((string)$header);
        if ($key !== '' && !isset($headerMap[$key])) {
          $headerMap[$key] = (int)$idx;
        }
      }

      $findIdx = static function(array $map, array $aliases): ?int {
        foreach ($aliases as $alias) {
          if (isset($map[$alias])) { return (int)$map[$alias]; }
        }
        return null;
      };

      $idxId          = $findIdx($headerMap, ['id']);
      $idxTitle       = $findIdx($headerMap, ['nazev','title']);
      $idxSectionId   = $findIdx($headerMap, ['sekce_id','section_id']);
      $idxSectionName = $findIdx($headerMap, ['sekce','section']);
      $idxCategory    = $findIdx($headerMap, ['kategorie','category']);
      $idxDifficulty  = $findIdx($headerMap, ['obtiznost','difficulty']);
      $idxDuration    = $findIdx($headerMap, ['delka_min','duration_minutes']);
      $idxThumb       = $findIdx($headerMap, ['thumbnail','nahled']);
      $idxDesc        = $findIdx($headerMap, ['popis','description']);
      $idxSort        = $findIdx($headerMap, ['poradi','sort_order']);
      $idxActive      = $findIdx($headerMap, ['aktivni','is_active']);

      if ($idxTitle === null) {
        fclose($handle);
        flash('danger', 'CSV tréninků musí obsahovat sloupec "nazev" (nebo "title").');
        redirect(BASE_URL . '/admin/mycoach_content.php#workouts');
      }

      $difficultyMap = [
        'beginner' => 'beginner',
        'zacatecnik' => 'beginner',
        'začátečník' => 'beginner',
        'intermediate' => 'intermediate',
        'stredni' => 'intermediate',
        'středni' => 'intermediate',
        'střední' => 'intermediate',
        'pokrocily' => 'advanced',
        'pokročily' => 'advanced',
        'pokročilý' => 'advanced',
        'advanced' => 'advanced',
      ];

      $activeMap = [
        '1' => 1, 'ano' => 1, 'true' => 1, 'aktivni' => 1, 'aktivní' => 1, 'yes' => 1,
        '0' => 0, 'ne' => 0, 'false' => 0, 'no' => 0,
      ];

      $sectionByNameStmt = $pdo->prepare('SELECT id FROM mycoach_app_sections WHERE title=? LIMIT 1');
      $workoutByIdStmt = $pdo->prepare('SELECT id FROM mycoach_app_workouts WHERE id=? LIMIT 1');
      $workoutByTitleStmt = $pdo->prepare('SELECT id FROM mycoach_app_workouts WHERE title=? LIMIT 1');
      $insertStmt = $pdo->prepare('INSERT INTO mycoach_app_workouts (section_id,title,category,description,difficulty,duration_minutes,thumbnail,sort_order,is_active) VALUES (?,?,?,?,?,?,?,?,?)');
      $updateStmt = $pdo->prepare('UPDATE mycoach_app_workouts SET section_id=?,title=?,category=?,description=?,difficulty=?,duration_minutes=?,thumbnail=?,sort_order=?,is_active=? WHERE id=?');

      $inserted = 0;
      $updated = 0;
      $skipped = 0;

      while (($row = fgetcsv($handle, 0, ';')) !== false) {
        if (!is_array($row) || count($row) === 0) { continue; }

        $title = trim((string)($row[$idxTitle] ?? ''));
        if ($title === '') {
          $skipped++;
          continue;
        }

        $sectionId = null;
        if ($idxSectionId !== null && is_numeric($row[$idxSectionId] ?? null) && (int)$row[$idxSectionId] > 0) {
          $sectionId = (int)$row[$idxSectionId];
        } elseif ($idxSectionName !== null) {
          $sectionName = trim((string)($row[$idxSectionName] ?? ''));
          if ($sectionName !== '') {
            $sectionByNameStmt->execute([$sectionName]);
            $resolvedSectionId = $sectionByNameStmt->fetchColumn();
            if ($resolvedSectionId) {
              $sectionId = (int)$resolvedSectionId;
            }
          }
        }

        $category = mcNormalizeListString((string)($idxCategory !== null ? ($row[$idxCategory] ?? '') : ''));
        $desc = trim((string)($idxDesc !== null ? ($row[$idxDesc] ?? '') : ''));
        $thumb = trim((string)($idxThumb !== null ? ($row[$idxThumb] ?? '') : ''));
        $sort = ($idxSort !== null && is_numeric($row[$idxSort] ?? null)) ? (int)$row[$idxSort] : 0;
        $duration = ($idxDuration !== null && is_numeric($row[$idxDuration] ?? null)) ? max(0, (int)$row[$idxDuration]) : null;

        $rawDiff = trim((string)($idxDifficulty !== null ? ($row[$idxDifficulty] ?? '') : ''));
        $diffKey = mb_strtolower($rawDiff, 'UTF-8');
        $difficulty = $difficultyMap[$diffKey] ?? 'intermediate';

        $rawActive = trim((string)($idxActive !== null ? ($row[$idxActive] ?? '') : '1'));
        $activeKey = mb_strtolower($rawActive, 'UTF-8');
        $active = $activeMap[$activeKey] ?? (is_numeric($rawActive) ? ((int)$rawActive > 0 ? 1 : 0) : 1);

        $targetId = 0;
        if ($idxId !== null && is_numeric($row[$idxId] ?? null) && (int)$row[$idxId] > 0) {
          $targetId = (int)$row[$idxId];
          $workoutByIdStmt->execute([$targetId]);
          if (!$workoutByIdStmt->fetch()) {
            $targetId = 0;
          }
        }

        if ($targetId <= 0) {
          $workoutByTitleStmt->execute([$title]);
          $existingByTitle = $workoutByTitleStmt->fetchColumn();
          if ($existingByTitle) { $targetId = (int)$existingByTitle; }
        }

        if ($targetId > 0) {
          $updateStmt->execute([$sectionId,$title,$category,$desc,$difficulty,$duration,$thumb,$sort,$active,$targetId]);
          $updated++;
        } else {
          $insertStmt->execute([$sectionId,$title,$category,$desc,$difficulty,$duration,$thumb,$sort,$active]);
          $inserted++;
        }
      }

      fclose($handle);
      flash('success', 'Import tréninků dokončen. Přidáno: ' . $inserted . ', aktualizováno: ' . $updated . ', přeskočeno: ' . $skipped . '.');
      redirect(BASE_URL . '/admin/mycoach_content.php#workouts');
    }

    // ── Cviky v tréninku ───────────────────────────────────
    if ($action === 'save_workout_exercise') {
        $wid   = (int)($_POST['workout_id'] ?? 0);
        $exid  = (int)($_POST['exercise_id'] ?? 0);
        $sets  = is_numeric($_POST['sets'] ?? '') ? (int)$_POST['sets'] : null;
        $reps  = is_numeric($_POST['reps'] ?? '') ? (int)$_POST['reps'] : null;
        $durS  = is_numeric($_POST['duration_seconds'] ?? '') ? (int)$_POST['duration_seconds'] : null;
        $rest  = is_numeric($_POST['rest_seconds'] ?? '') ? (int)$_POST['rest_seconds'] : null;
        $notes = trim((string)($_POST['notes'] ?? ''));
        $sort  = (int)($_POST['sort_order'] ?? 0);

        if ($wid > 0 && $exid > 0) {
            $pdo->prepare('INSERT INTO mycoach_app_workout_exercises (workout_id,exercise_id,sets,reps,duration_seconds,rest_seconds,notes,sort_order) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$wid,$exid,$sets,$reps,$durS,$rest,$notes,$sort]);
            flash('success', 'Cvik byl přidán do tréninku.');
        }
        redirect(BASE_URL . '/admin/mycoach_content.php?edit_workout=' . $wid . '#workouts');
    }

    if ($action === 'delete_workout_exercise') {
        $id  = (int)($_POST['workout_exercise_id'] ?? 0);
        $wid = (int)($_POST['workout_id'] ?? 0);
        if ($id > 0) { $pdo->prepare('DELETE FROM mycoach_app_workout_exercises WHERE id=?')->execute([$id]); flash('success','Cvik odebrán.'); }
        redirect(BASE_URL . '/admin/mycoach_content.php?edit_workout=' . $wid . '#workouts');
    }

    // ── Obsah sekce (section_items) ────────────────────────
    if ($action === 'save_section_item') {
        $id     = (int)($_POST['item_id'] ?? 0);
        $secId  = (int)($_POST['item_section_id'] ?? 0);
        $type   = in_array(trim((string)($_POST['item_type'] ?? '')), ['video_youtube','video_vimeo','video_upload','link','article','image'], true)
                  ? trim((string)$_POST['item_type']) : 'video_youtube';
        $title  = trim((string)($_POST['item_title'] ?? ''));
        $desc   = trim((string)($_POST['item_description'] ?? ''));
        $url    = trim((string)($_POST['item_url'] ?? ''));
        $fpath  = trim((string)($_POST['item_file_path'] ?? ''));
        $thumb  = trim((string)($_POST['item_thumbnail'] ?? ''));
        $dur    = is_numeric($_POST['item_duration_seconds'] ?? '') ? (int)$_POST['item_duration_seconds'] : null;
        $cont   = trim((string)($_POST['item_content'] ?? ''));
        $sort   = (int)($_POST['item_sort_order'] ?? 0);
        $active = isset($_POST['item_is_active']) ? 1 : 0;

        if ($title === '') { flash('danger', 'Název položky je povinný.'); redirect(BASE_URL . '/admin/mycoach_content.php?sec=' . $secId); }
        if ($secId <= 0)   { flash('danger', 'Chybí sekce.'); redirect(BASE_URL . '/admin/mycoach_content.php'); }

        if ($id > 0) {
            $pdo->prepare('UPDATE mycoach_app_section_items SET item_type=?,title=?,description=?,url=?,file_path=?,thumbnail=?,duration_seconds=?,content=?,sort_order=?,is_active=? WHERE id=?')
                ->execute([$type,$title,$desc,$url,$fpath,$thumb,$dur,$cont,$sort,$active,$id]);
            flash('success', 'Položka uložena.');
        } else {
            $pdo->prepare('INSERT INTO mycoach_app_section_items (section_id,item_type,title,description,url,file_path,thumbnail,duration_seconds,content,sort_order,is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$secId,$type,$title,$desc,$url,$fpath,$thumb,$dur,$cont,$sort,$active]);
            flash('success', 'Položka přidána.');
        }
        redirect(BASE_URL . '/admin/mycoach_content.php?sec=' . $secId);
    }

    if ($action === 'delete_section_item') {
        $id    = (int)($_POST['item_id'] ?? 0);
        $secId = (int)($_POST['item_section_id'] ?? 0);
        if ($id > 0) { $pdo->prepare('DELETE FROM mycoach_app_section_items WHERE id=?')->execute([$id]); flash('success', 'Položka smazána.'); }
        redirect(BASE_URL . '/admin/mycoach_content.php?sec=' . $secId);
    }

    // ── Výchozí sekce ──────────────────────────────────────
    if ($action === 'create_default_sections') {
        $defaults = [
            ['Cviky',         'Encyklopedie cviků',   'fa-person-running', 'blue',   'exercises', 0],
            ['Videa',         'Video obsah',           'fa-play-circle',    'orange', 'videos',    10],
            ['Tréninky',      'Tréninkové plány',      'fa-dumbbell',       'red',    'workout',   20],
        ];
        $lastId = 0;
        foreach ($defaults as [$title, $sub, $icon, $color, $type, $sort]) {
            $exists = $pdo->prepare('SELECT id FROM mycoach_app_sections WHERE title=? LIMIT 1');
            $exists->execute([$title]);
            if (!$exists->fetch()) {
                $pdo->prepare('INSERT INTO mycoach_app_sections (title,subtitle,icon_class,tile_color,section_type,sort_order,is_active,audience) VALUES (?,?,?,?,?,?,1,"all")')
                    ->execute([$title,$sub,$icon,$color,$type,$sort]);
                if ($lastId === 0) $lastId = (int)$pdo->lastInsertId();
            }
        }
        flash('success', 'Výchozí sekce byly vytvořeny. Klikněte na záložku sekce a přidejte obsah.');
        redirect(BASE_URL . '/admin/mycoach_content.php');
    }
}

// ── Načtení dat ───────────────────────────────────────────────
$sections  = [];
$videos    = [];
$exercises = [];
$workouts  = [];
try { $sections  = $pdo->query('SELECT * FROM mycoach_app_sections ORDER BY sort_order ASC, id ASC')->fetchAll() ?: []; } catch(Throwable $e){}
try { $videos    = $pdo->query('SELECT v.*, s.title AS section_title FROM mycoach_app_videos v LEFT JOIN mycoach_app_sections s ON s.id=v.section_id ORDER BY v.sort_order ASC, v.id ASC')->fetchAll() ?: []; } catch(Throwable $e){}
try { $exercises = $pdo->query('SELECT * FROM mycoach_app_exercises ORDER BY sort_order ASC, name ASC')->fetchAll() ?: []; } catch(Throwable $e){}
try { $workouts  = $pdo->query('SELECT w.*, s.title AS section_title FROM mycoach_app_workouts w LEFT JOIN mycoach_app_sections s ON s.id=w.section_id ORDER BY w.sort_order ASC, w.id ASC')->fetchAll() ?: []; } catch(Throwable $e){}

// Načtení section_items (všechny; pro display se filtrují per sekce v šabloně)
$sectionItems = [];
try {
    $siStmt = $pdo->query('SELECT * FROM mycoach_app_section_items ORDER BY sort_order ASC, id ASC');
    foreach ($siStmt->fetchAll() ?: [] as $si) {
        $sectionItems[(int)$si['section_id']][] = $si;
    }
} catch(Throwable $e){}

// Automaticky vytvoř výchozí sekce při prvním spuštění (prázdná DB)
if (empty($sections)) {
    $defaults = [
        ['Cviky',    'Encyklopedie cviků',  'fa-person-running', 'blue',   'exercises', 0],
        ['Videa',    'Video obsah',          'fa-play-circle',    'orange', 'videos',    10],
        ['Tréninky', 'Tréninkové plány',     'fa-dumbbell',       'red',    'workout',   20],
    ];
    foreach ($defaults as [$t, $s, $ic, $co, $ty, $so]) {
        try {
            $pdo->prepare('INSERT IGNORE INTO mycoach_app_sections (title,subtitle,icon_class,tile_color,section_type,sort_order,is_active,audience) VALUES (?,?,?,?,?,?,1,"all")')
                ->execute([$t,$s,$ic,$co,$ty,$so]);
        } catch(Throwable $e){}
    }
    try { $sections = $pdo->query('SELECT * FROM mycoach_app_sections ORDER BY sort_order ASC, id ASC')->fetchAll() ?: []; } catch(Throwable $e){}
}

// Aktivní sekce z URL parametru (pro přímý skok po POST)
$activeSectionId = (int)($_GET['sec'] ?? 0);

$exerciseCategories = [];
$exerciseMuscles = [];
$workoutCategories = [];
foreach ($exercises as $exItem) {
  foreach (mcSplitListValues((string)($exItem['category'] ?? '')) as $catPart) {
    $exerciseCategories[$catPart] = $catPart;
  }
  foreach (mcSplitListValues((string)($exItem['muscle_groups'] ?? '')) as $musclePart) {
    $exerciseMuscles[$musclePart] = $musclePart;
  }
}
ksort($exerciseCategories, SORT_NATURAL | SORT_FLAG_CASE);
ksort($exerciseMuscles, SORT_NATURAL | SORT_FLAG_CASE);
foreach ($workouts as $woItem) {
  foreach (mcSplitListValues((string)($woItem['category'] ?? '')) as $catPart) {
    $workoutCategories[$catPart] = $catPart;
  }
}
ksort($workoutCategories, SORT_NATURAL | SORT_FLAG_CASE);

// Detail tréninku pro edit
$editWorkoutId = (int)($_GET['edit_workout'] ?? 0);
$editWorkout   = null;
$workoutExercises = [];
if ($editWorkoutId > 0) {
    try {
        $editWorkout = $pdo->prepare('SELECT * FROM mycoach_app_workouts WHERE id=? LIMIT 1');
        $editWorkout->execute([$editWorkoutId]);
        $editWorkout = $editWorkout->fetch() ?: null;
        if ($editWorkout) {
            $weStmt = $pdo->prepare('SELECT we.*, e.name AS ex_name FROM mycoach_app_workout_exercises we LEFT JOIN mycoach_app_exercises e ON e.id=we.exercise_id WHERE we.workout_id=? ORDER BY we.sort_order ASC, we.id ASC');
            $weStmt->execute([$editWorkoutId]);
            $workoutExercises = $weStmt->fetchAll() ?: [];
        }
    } catch(Throwable $e){ $editWorkout = null; }
}

renderAdminHeader('MyCoach – správa obsahu');

function mcAdminSectionOptions(array $sections, ?int $selected = null): string {
    $out = '<option value="">– Bez sekce –</option>';
    foreach ($sections as $s) {
        $sel = ($selected !== null && (int)$s['id'] === $selected) ? ' selected' : '';
        $out .= '<option value="' . (int)$s['id'] . '"' . $sel . '>' . h($s['title']) . '</option>';
    }
    return $out;
}
function mcAdminExerciseOptions(array $exercises, ?int $selected = null): string {
    $out = '<option value="">– Vyberte cvik –</option>';
    foreach ($exercises as $e) {
        $sel = ($selected !== null && (int)$e['id'] === $selected) ? ' selected' : '';
        $out .= '<option value="' . (int)$e['id'] . '"' . $sel . '>' . h($e['name']) . '</option>';
    }
    return $out;
}
?>

<style>
.mcc-card { border:0; box-shadow:0 1px 8px rgba(0,0,0,.08); border-radius:12px; margin-bottom:1.5rem; }
.mcc-card .card-header { border-radius:12px 12px 0 0; font-weight:700; }
.mcc-tab-nav .nav-link { font-weight:600; font-size:.9rem; }
</style>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h4 class="fw-bold mb-1"><i class="fas fa-layer-group me-2 text-warning"></i>MyCoach App – správa obsahu</h4>
        <div class="text-muted small">Sekce, videa, cviky, tréninky</div>
    </div>
    <a href="<?= BASE_URL ?>/admin/mycoach.php" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left me-1"></i>MyCoach admin
    </a>
</div>

<!-- Flash zprávy -->
<?php
$_flash = getFlash();
if ($_flash): ?>
<div class="alert alert-<?= h($_flash['type']) ?> alert-dismissible fade show py-2">
  <?= !empty($_flash['html']) ? $_flash['message'] : h($_flash['message']) ?>
  <button type="button" class="btn-close btn-sm" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Tabs -->
<ul class="nav nav-tabs mcc-tab-nav mb-4 flex-wrap" id="mccTabs">
  <li class="nav-item">
    <a class="nav-link <?= $activeSectionId === 0 ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#tabSections">
      <i class="fas fa-grid-2 me-1"></i>Sekce
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link" data-bs-toggle="tab" data-bs-target="#tabVideos">
      <i class="fas fa-film me-1"></i>Videa
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link" data-bs-toggle="tab" data-bs-target="#tabWorkouts">
      <i class="fas fa-dumbbell me-1"></i>Tréninky
    </a>
  </li>
  <?php foreach ($sections as $sec): ?>
  <li class="nav-item">
    <a class="nav-link <?= $activeSectionId === (int)$sec['id'] ? 'active' : '' ?>"
       data-bs-toggle="tab" data-bs-target="#tabSection<?= (int)$sec['id'] ?>">
      <i class="fas <?= h($sec['icon_class'] ?? 'fa-cube') ?> me-1"></i><?= h($sec['title']) ?>
    </a>
  </li>
  <?php endforeach; ?>
  <li class="nav-item ms-auto">
    <a class="nav-link text-muted border-start ps-3" data-bs-toggle="tab" data-bs-target="#tabExercises"
       title="Globální encyklopedie cviků – není viditelná jako sekce v app, slouží jen pro správu obsahu">
      <i class="fas fa-wrench me-1"></i>Správa cviků
    </a>
  </li>
</ul>

<div class="tab-content">

<!-- ══════════ SEKCE ══════════ -->
<div class="tab-pane fade show active" id="tabSections">
  <div class="alert alert-info border-0 mb-3 small">
    <i class="fas fa-circle-info me-1"></i>
    <strong>Jak to funguje:</strong> Sekce = dlaždice, které vidí uživatelé v aplikaci.
    Každá sekce má svoji záložku v tomto adminu — klikni na záložku sekce a přidej obsah (video, odkaz, článek, obrázek).
    Sekce s typem <strong>Cviky</strong> automaticky zobrazí globální encyklopedii cviků (spravuje se v záložce <em>Cviky</em> napravo).
  </div>
  <div class="row g-3">
    <div class="col-lg-4">
      <div class="card mcc-card">
        <div class="card-header" style="background:#1a1a2e;color:#f7941d;">
          <i class="fas fa-plus me-1"></i>Přidat / upravit sekci
        </div>
        <div class="card-body" id="sectionFormWrap">
          <form method="post" id="sectionForm">
            <?= csrfField() ?><input type="hidden" name="action" value="save_section">
            <input type="hidden" name="section_id" id="sfId" value="0">
            <div class="mb-2">
              <label class="form-label small fw-semibold">Název *</label>
              <input type="text" name="title" id="sfTitle" class="form-control form-control-sm" required>
            </div>
            <div class="mb-2">
              <label class="form-label small fw-semibold">Podnázev</label>
              <input type="text" name="subtitle" id="sfSubtitle" class="form-control form-control-sm">
            </div>
            <div class="row g-2 mb-2">
              <div class="col-6">
                <label class="form-label small fw-semibold">Typ sekce</label>
                <select name="section_type" id="sfType" class="form-select form-select-sm">
                  <?php foreach ($sectionTypes as $k=>$t): ?>
                  <option value="<?= h($k) ?>"><?= h($t['label']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-6">
                <label class="form-label small fw-semibold">Barva dlaždice</label>
                <select name="tile_color" id="sfColor" class="form-select form-select-sm">
                  <?php foreach ($tileColors as $c): ?>
                  <option value="<?= $c ?>"><?= ucfirst($c) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="row g-2 mb-2">
              <div class="col-7">
                <label class="form-label small fw-semibold">FontAwesome ikona</label>
                <input type="text" name="icon_class" id="sfIcon" class="form-control form-control-sm" placeholder="fa-play-circle">
              </div>
              <div class="col-5">
                <label class="form-label small fw-semibold">Pořadí</label>
                <input type="number" name="sort_order" id="sfSort" class="form-control form-control-sm" value="0">
              </div>
            </div>
            <div class="mb-2">
              <label class="form-label small fw-semibold">Cílová skupina</label>
              <select name="audience" id="sfAudience" class="form-select form-select-sm">
                <option value="all">Všichni</option>
                <option value="coach">Jen trenéři</option>
                <option value="athlete">Jen sportovci</option>
              </select>
            </div>
            <div class="mb-2">
              <label class="form-label small fw-semibold">Popis</label>
              <textarea name="description" id="sfDesc" class="form-control form-control-sm" rows="2"></textarea>
            </div>
            <div class="mb-3 form-check">
              <input type="checkbox" name="is_active" id="sfActive" class="form-check-input" value="1" checked>
              <label class="form-check-label small" for="sfActive">Aktivní</label>
            </div>
            <div class="d-flex gap-2">
              <button type="submit" class="btn btn-warning btn-sm fw-semibold flex-grow-1">Uložit sekci</button>
              <button type="button" class="btn btn-outline-secondary btn-sm" onclick="sectionFormReset()">Reset</button>
            </div>
          </form>
        </div>
      </div>
    </div>
    <div class="col-lg-8">
      <div class="card mcc-card">
        <div class="card-header bg-dark text-white">Sekce (<?= count($sections) ?>)</div>
        <div class="card-body p-0">
          <?php if (empty($sections)): ?>
            <div class="p-4 text-center">
              <p class="text-muted mb-3">Zatím žádné sekce. Vytvořte vlastní vlevo, nebo začněte s výchozími.</p>
              <form method="post" class="d-inline">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="create_default_sections">
                <button type="submit" class="btn btn-warning btn-sm fw-semibold">
                  <i class="fas fa-magic me-1"></i>Vytvořit výchozí sekce (Cviky, Videa, Tréninky)
                </button>
              </form>
            </div>
          <?php else: ?>
          <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead class="table-light"><tr>
              <th>#</th><th>Název</th><th>Typ</th><th class="text-center">Skupina</th><th class="text-center">Aktivní</th><th class="text-end">Akce</th>
            </tr></thead>
            <tbody>
            <?php foreach ($sections as $sec): ?>
            <tr>
              <td class="text-muted small"><?= (int)$sec['sort_order'] ?></td>
              <td>
                <div class="fw-semibold"><?= h($sec['title']) ?></div>
                <?php if ($sec['subtitle']): ?><div class="text-muted small"><?= h($sec['subtitle']) ?></div><?php endif; ?>
              </td>
              <td><span class="badge bg-secondary"><?= h($sectionTypes[$sec['section_type']]['label'] ?? $sec['section_type']) ?></span></td>
              <td class="text-center small"><?= h($sec['audience']) ?></td>
              <td class="text-center">
                <span class="badge <?= $sec['is_active'] ? 'bg-success' : 'bg-secondary' ?>"><?= $sec['is_active'] ? 'Ano' : 'Ne' ?></span>
              </td>
              <td class="text-end">
                <button type="button" class="btn btn-xs btn-outline-primary btn-sm"
                        onclick='sectionFormFill(<?= json_encode($sec, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>)'>
                  <i class="fas fa-pen"></i>
                </button>
                <?php if (in_array($sec['section_type'], ['videos','mixed'], true)): ?>
                <button type="button" class="btn btn-xs btn-outline-warning btn-sm"
                        title="Přidat video do této sekce"
                        onclick="jumpToAddVideo(<?= (int)$sec['id'] ?>)">
                  <i class="fas fa-film"></i>+
                </button>
                <?php endif; ?>
                <?php if (in_array($sec['section_type'], ['workout','mixed'], true)): ?>
                <button type="button" class="btn btn-xs btn-outline-info btn-sm"
                        title="Přidat trénink do této sekce"
                        onclick="jumpToAddWorkout(<?= (int)$sec['id'] ?>)">
                  <i class="fas fa-dumbbell"></i>+
                </button>
                <?php endif; ?>
                <form method="post" class="d-inline" onsubmit="return confirm('Smazat sekci?')">
                  <?= csrfField() ?><input type="hidden" name="action" value="delete_section">
                  <input type="hidden" name="section_id" value="<?= (int)$sec['id'] ?>">
                  <button class="btn btn-xs btn-outline-danger btn-sm"><i class="fas fa-trash"></i></button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ══════════ VIDEA ══════════ -->
<div class="tab-pane fade" id="tabVideos">
  <div class="row g-3">
    <div class="col-lg-4">
      <div class="card mcc-card">
        <div class="card-header" style="background:#1a1a2e;color:#f7941d;">
          <i class="fas fa-plus me-1"></i>Přidat / upravit video
        </div>
        <div class="card-body">
          <form method="post" id="videoForm">
            <?= csrfField() ?><input type="hidden" name="action" value="save_video">
            <input type="hidden" name="video_id" id="vfId" value="0">
            <div class="mb-2">
              <label class="form-label small fw-semibold">Název *</label>
              <input type="text" name="title" id="vfTitle" class="form-control form-control-sm" required>
            </div>
            <div class="mb-2">
              <label class="form-label small fw-semibold">Sekce</label>
              <select name="section_id" id="vfSection" class="form-select form-select-sm">
                <?= mcAdminSectionOptions($sections) ?>
              </select>
            </div>
            <div class="mb-2">
              <label class="form-label small fw-semibold">Typ videa</label>
              <select name="video_type" id="vfType" class="form-select form-select-sm" onchange="videoTypeChange(this)">
                <option value="youtube">YouTube</option>
                <option value="vimeo">Vimeo</option>
                <option value="upload">Upload (soubor)</option>
                <option value="url">Přímá URL</option>
              </select>
            </div>
            <div class="mb-2" id="vfUrlWrap">
              <label class="form-label small fw-semibold" id="vfUrlLabel">YouTube URL nebo ID</label>
              <input type="text" name="video_url" id="vfUrl" class="form-control form-control-sm" placeholder="https://youtube.com/watch?v=...">
            </div>
            <div class="mb-2 d-none" id="vfPathWrap">
              <label class="form-label small fw-semibold">Nahrát video ze zařízení</label>
              <input type="hidden" name="video_path" id="vfPath">
              <div class="input-group input-group-sm">
                <input type="text" id="vfPathDisplay" class="form-control form-control-sm" placeholder="Žádný soubor nevybrán" readonly>
                <label class="btn btn-outline-warning btn-sm mb-0" for="vfFileInput" style="cursor:pointer;">
                  <i class="fas fa-folder-open me-1"></i>Vybrat
                </label>
              </div>
              <input type="file" id="vfFileInput" accept="video/mp4,video/webm,video/ogg,video/quicktime,video/x-msvideo,video/x-matroska" class="d-none">
              <div id="vfUploadProgress" class="d-none mt-1">
                <div class="progress" style="height:6px;">
                  <div id="vfUploadBar" class="progress-bar bg-warning" style="width:0%"></div>
                </div>
                <div id="vfUploadStatus" class="small text-muted mt-1">Nahrávám...</div>
              </div>
            </div>
            <div class="mb-2">
              <label class="form-label small fw-semibold">Náhledový obrázek (URL/cesta)</label>
              <input type="text" name="thumbnail" id="vfThumb" class="form-control form-control-sm">
            </div>
            <div class="row g-2 mb-2">
              <div class="col-6">
                <label class="form-label small fw-semibold">Délka (sekundy)</label>
                <input type="number" name="duration_seconds" id="vfDur" class="form-control form-control-sm" min="0">
              </div>
              <div class="col-6">
                <label class="form-label small fw-semibold">Pořadí</label>
                <input type="number" name="sort_order" id="vfSort" class="form-control form-control-sm" value="0">
              </div>
            </div>
            <div class="mb-2">
              <label class="form-label small fw-semibold">Tagy (čárkou)</label>
              <input type="text" name="tags" id="vfTags" class="form-control form-control-sm">
            </div>
            <div class="mb-2">
              <label class="form-label small fw-semibold">Popis</label>
              <textarea name="description" id="vfDesc" class="form-control form-control-sm" rows="2"></textarea>
            </div>
            <div class="mb-3 form-check">
              <input type="checkbox" name="is_active" id="vfActive" class="form-check-input" value="1" checked>
              <label class="form-check-label small" for="vfActive">Aktivní</label>
            </div>
            <div class="d-flex gap-2">
              <button type="submit" class="btn btn-warning btn-sm fw-semibold flex-grow-1">Uložit video</button>
              <button type="button" class="btn btn-outline-secondary btn-sm" onclick="videoFormReset()">Reset</button>
            </div>
          </form>
        </div>
      </div>
    </div>
    <div class="col-lg-8">
      <div class="card mcc-card">
        <div class="card-header bg-dark text-white">Videa (<?= count($videos) ?>)</div>
        <div class="card-body p-0">
          <?php if (empty($videos)): ?>
            <p class="p-3 text-muted">Zatím žádná videa.</p>
          <?php else: ?>
          <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead class="table-light"><tr>
              <th>#</th><th>Název</th><th>Sekce</th><th>Typ</th><th class="text-center">Délka</th><th class="text-end">Akce</th>
            </tr></thead>
            <tbody>
            <?php foreach ($videos as $vid): ?>
            <tr>
              <td class="text-muted small"><?= (int)$vid['sort_order'] ?></td>
              <td>
                <div class="fw-semibold"><?= h($vid['title']) ?></div>
                <?php if ($vid['tags']): ?><div class="text-muted small"><?= h($vid['tags']) ?></div><?php endif; ?>
              </td>
              <td class="small text-muted"><?= h($vid['section_title'] ?? '–') ?></td>
              <td><span class="badge bg-info text-dark small"><?= h($vid['video_type']) ?></span></td>
              <td class="text-center small"><?= $vid['duration_seconds'] ? mycoachAppFormatDuration((int)$vid['duration_seconds']) : '–' ?></td>
              <td class="text-end">
                <button type="button" class="btn btn-xs btn-outline-primary btn-sm"
                        onclick='videoFormFill(<?= json_encode($vid, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>)'>
                  <i class="fas fa-pen"></i>
                </button>
                <form method="post" class="d-inline" onsubmit="return confirm('Smazat video?')">
                  <?= csrfField() ?><input type="hidden" name="action" value="delete_video">
                  <input type="hidden" name="video_id" value="<?= (int)$vid['id'] ?>">
                  <button class="btn btn-xs btn-outline-danger btn-sm"><i class="fas fa-trash"></i></button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ══════════ DYNAMICKÉ SEKCE ══════════ -->
<?php
$itemTypeLabels = [
    'video_youtube' => ['label'=>'YouTube video','icon'=>'fa-youtube','color'=>'text-danger'],
    'video_vimeo'   => ['label'=>'Vimeo video',  'icon'=>'fa-vimeo', 'color'=>'text-info'],
    'video_upload'  => ['label'=>'Vlastní video (upload)','icon'=>'fa-video','color'=>'text-warning'],
    'link'          => ['label'=>'Odkaz',         'icon'=>'fa-link',  'color'=>'text-primary'],
    'article'       => ['label'=>'Článek / text', 'icon'=>'fa-file-lines','color'=>'text-success'],
    'image'         => ['label'=>'Obrázek',       'icon'=>'fa-image', 'color'=>'text-secondary'],
];
foreach ($sections as $sec):
    $secId    = (int)$sec['id'];
    $secItems = $sectionItems[$secId] ?? [];
    $isActive = $activeSectionId === $secId;
?>
<div class="tab-pane fade <?= $isActive ? 'show active' : '' ?>" id="tabSection<?= $secId ?>">
  <div class="row g-3">
    <!-- Formulář pro přidání/editaci položky -->
    <div class="col-lg-4">
      <div class="card mcc-card">
        <div class="card-header" style="background:#1a1a2e;color:#f7941d;">
          <i class="fas fa-plus me-1"></i><span id="sifTitle_<?= $secId ?>">Přidat položku</span>
        </div>
        <div class="card-body">
          <form method="post" id="sifForm_<?= $secId ?>">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save_section_item">
            <input type="hidden" name="item_id" id="sifId_<?= $secId ?>" value="0">
            <input type="hidden" name="item_section_id" value="<?= $secId ?>">

            <div class="mb-2">
              <label class="form-label small fw-semibold">Typ obsahu</label>
              <select name="item_type" id="sifType_<?= $secId ?>" class="form-select form-select-sm"
                      onchange="sifTypeChange(<?= $secId ?>)">
                <?php foreach ($itemTypeLabels as $k => $v): ?>
                <option value="<?= $k ?>"><?= $v['label'] ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="mb-2">
              <label class="form-label small fw-semibold">Název *</label>
              <input type="text" name="item_title" id="sifItemTitle_<?= $secId ?>" class="form-control form-control-sm" required>
            </div>

            <!-- URL (pro video_youtube, video_vimeo, link) -->
            <div class="mb-2" id="sifUrlWrap_<?= $secId ?>">
              <label class="form-label small fw-semibold" id="sifUrlLabel_<?= $secId ?>">URL</label>
              <input type="text" name="item_url" id="sifUrl_<?= $secId ?>" class="form-control form-control-sm"
                     placeholder="https://...">
              <div id="sifLinkHint_<?= $secId ?>" class="form-text d-none">
                <i class="fas fa-circle-info me-1"></i>Odkaz se v aplikaci otevře v integrovaném prohlížeči.
              </div>
            </div>

            <!-- Soubor (pro video_upload, image) -->
            <div class="mb-2 d-none" id="sifFileWrap_<?= $secId ?>">
              <label class="form-label small fw-semibold" id="sifFileLabel_<?= $secId ?>">Nahrát soubor</label>
              <input type="hidden" name="item_file_path" id="sifFilePath_<?= $secId ?>">
              <div class="input-group input-group-sm">
                <input type="text" id="sifFileDisplay_<?= $secId ?>" class="form-control form-control-sm" placeholder="Žádný soubor" readonly>
                <label class="btn btn-outline-warning btn-sm mb-0" for="sifFileInput_<?= $secId ?>" style="cursor:pointer;">
                  <i class="fas fa-folder-open me-1"></i>Vybrat
                </label>
              </div>
              <input type="file" id="sifFileInput_<?= $secId ?>" class="d-none">
              <div id="sifFileProgress_<?= $secId ?>" class="d-none mt-1">
                <div class="progress" style="height:6px;">
                  <div id="sifFileBar_<?= $secId ?>" class="progress-bar bg-warning" style="width:0%"></div>
                </div>
                <div id="sifFileStatus_<?= $secId ?>" class="small text-muted mt-1">Nahrávám...</div>
              </div>
            </div>

            <!-- Obsah článku -->
            <div class="mb-2 d-none" id="sifContentWrap_<?= $secId ?>">
              <label class="form-label small fw-semibold">Text / obsah článku</label>
              <textarea name="item_content" id="sifContent_<?= $secId ?>" class="form-control form-control-sm" rows="6"></textarea>
            </div>

            <!-- Náhled -->
            <div class="mb-2" id="sifThumbWrap_<?= $secId ?>">
              <label class="form-label small fw-semibold">Náhledový obrázek (URL nebo cesta)</label>
              <input type="text" name="item_thumbnail" id="sifThumb_<?= $secId ?>" class="form-control form-control-sm" placeholder="https://...">
            </div>

            <!-- Délka (jen pro videa) -->
            <div class="mb-2 d-none" id="sifDurWrap_<?= $secId ?>">
              <label class="form-label small fw-semibold">Délka videa (sekundy)</label>
              <input type="number" name="item_duration_seconds" id="sifDur_<?= $secId ?>" class="form-control form-control-sm" min="0">
            </div>

            <div class="mb-2">
              <label class="form-label small fw-semibold">Popis</label>
              <textarea name="item_description" id="sifDesc_<?= $secId ?>" class="form-control form-control-sm" rows="2"></textarea>
            </div>

            <div class="row g-2 mb-2">
              <div class="col-6">
                <label class="form-label small fw-semibold">Pořadí</label>
                <input type="number" name="item_sort_order" id="sifSort_<?= $secId ?>" class="form-control form-control-sm" value="<?= count($secItems) * 10 ?>">
              </div>
            </div>
            <div class="mb-3 form-check">
              <input type="checkbox" name="item_is_active" id="sifActive_<?= $secId ?>" class="form-check-input" value="1" checked>
              <label class="form-check-label small" for="sifActive_<?= $secId ?>">Aktivní</label>
            </div>
            <div class="d-flex gap-2">
              <button type="submit" class="btn btn-warning btn-sm fw-semibold flex-grow-1">Uložit</button>
              <button type="button" class="btn btn-outline-secondary btn-sm"
                      onclick="sifReset(<?= $secId ?>)">Reset</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Seznam položek sekce -->
    <div class="col-lg-8">
      <div class="card mcc-card">
        <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
          <span><?= h($sec['title']) ?> (<?= count($secItems) ?> položek)</span>
          <span class="badge bg-secondary small"><?= h($sectionTypes[$sec['section_type']]['label'] ?? $sec['section_type']) ?></span>
        </div>
        <div class="card-body p-0">
          <?php if (empty($secItems)): ?>
            <p class="p-3 text-muted small">Žádné položky. Přidejte první pomocí formuláře vlevo.</p>
          <?php else: ?>
          <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead class="table-light">
              <tr><th>#</th><th>Typ</th><th>Název</th><th class="text-center">Aktivní</th><th class="text-end">Akce</th></tr>
            </thead>
            <tbody>
            <?php foreach ($secItems as $si): ?>
            <?php
              $itInfo = $itemTypeLabels[$si['item_type']] ?? ['label'=>$si['item_type'],'icon'=>'fa-cube','color'=>''];
            ?>
            <tr>
              <td class="text-muted small"><?= (int)$si['sort_order'] ?></td>
              <td>
                <span class="<?= $itInfo['color'] ?>">
                  <i class="fas <?= $itInfo['icon'] ?>"></i>
                </span>
                <span class="small ms-1"><?= $itInfo['label'] ?></span>
              </td>
              <td>
                <div class="fw-semibold"><?= h($si['title']) ?></div>
                <?php if (!empty($si['url'])): ?>
                  <div class="small text-muted text-truncate" style="max-width:220px;"><?= h($si['url']) ?></div>
                <?php endif; ?>
              </td>
              <td class="text-center">
                <span class="badge <?= $si['is_active'] ? 'bg-success' : 'bg-secondary' ?>"><?= $si['is_active'] ? 'Ano' : 'Ne' ?></span>
              </td>
              <td class="text-end">
                <button type="button" class="btn btn-xs btn-outline-primary btn-sm"
                        onclick='sifFill(<?= $secId ?>, <?= json_encode($si, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>)'>
                  <i class="fas fa-pen"></i>
                </button>
                <form method="post" class="d-inline" onsubmit="return confirm('Smazat položku?')">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="delete_section_item">
                  <input type="hidden" name="item_id" value="<?= (int)$si['id'] ?>">
                  <input type="hidden" name="item_section_id" value="<?= $secId ?>">
                  <button class="btn btn-xs btn-outline-danger btn-sm"><i class="fas fa-trash"></i></button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>
<?php endforeach; ?>

<!-- ══════════ CVIKY ══════════ -->
<div class="tab-pane fade" id="tabExercises">
  <div class="row g-3">
    <div class="col-lg-4">
      <div class="card mcc-card">
        <div class="card-header" style="background:#1a1a2e;color:#f7941d;">
          <i class="fas fa-plus me-1"></i><span id="exerciseFormTitle">Přidat / upravit cvik</span>
        </div>
        <div class="card-body">
          <form method="post" id="exerciseForm">
            <?= csrfField() ?><input type="hidden" name="action" value="save_exercise">
            <input type="hidden" name="exercise_id" id="efId" value="0">
            <div class="mb-2">
              <label class="form-label small fw-semibold">Název *</label>
              <input type="text" name="name" id="efName" class="form-control form-control-sm" required>
            </div>
            <div class="row g-2 mb-2">
              <div class="col-6">
                <label class="form-label small fw-semibold">Kategorie</label>
                <input type="text" name="category" id="efCat" class="form-control form-control-sm" placeholder="např. Síla, Horní část těla">
                <div class="form-text small">Můžete zadat více kategorií oddělených čárkou.</div>
              </div>
              <div class="col-6">
                <label class="form-label small fw-semibold">Obtížnost</label>
                <select name="difficulty" id="efDiff" class="form-select form-select-sm">
                  <?php foreach ($difficultyOpts as $k=>$v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="mb-2">
              <label class="form-label small fw-semibold">Svalové partie</label>
              <input type="text" name="muscle_groups" id="efMuscles" class="form-control form-control-sm" placeholder="např. Nohy, Záda">
            </div>
            <div class="mb-2">
              <label class="form-label small fw-semibold">Vybavení</label>
              <input type="text" name="equipment" id="efEquip" class="form-control form-control-sm" placeholder="např. Činka, Kettlebell">
            </div>
            <div class="mb-2">
              <label class="form-label small fw-semibold">
                Video
                <span class="text-muted fw-normal small">– YouTube/Vimeo URL, nebo nahraj soubor</span>
              </label>
              <input type="text" name="video_url" id="efVideo" class="form-control form-control-sm mb-1" placeholder="https://youtube.com/watch?v=...">
              <input type="hidden" name="video_path_upload" id="efVideoPath">
              <div class="input-group input-group-sm">
                <input type="text" id="efVideoPathDisplay" class="form-control form-control-sm" placeholder="nebo nahraj soubor..." readonly>
                <label class="btn btn-outline-secondary btn-sm mb-0" for="efVideoFileInput" style="cursor:pointer;">
                  <i class="fas fa-upload me-1"></i>Nahrát
                </label>
              </div>
              <input type="file" id="efVideoFileInput" accept="video/mp4,video/webm,video/ogg,video/quicktime" class="d-none">
              <div id="efVideoProgress" class="d-none mt-1">
                <div class="progress" style="height:5px;"><div id="efVideoBar" class="progress-bar bg-warning" style="width:0%"></div></div>
                <div id="efVideoStatus" class="small text-muted"></div>
              </div>
            </div>
            <div class="mb-2">
              <label class="form-label small fw-semibold">
                Náhledový obrázek
                <span class="text-muted fw-normal small">– URL, nebo nahraj soubor</span>
              </label>
              <input type="text" name="thumbnail" id="efThumb" class="form-control form-control-sm mb-1" placeholder="https://... nebo cesta">
              <input type="hidden" name="thumbnail_upload" id="efThumbPath">
              <div class="input-group input-group-sm">
                <input type="text" id="efThumbDisplay" class="form-control form-control-sm" placeholder="nebo nahraj obrázek..." readonly>
                <label class="btn btn-outline-secondary btn-sm mb-0" for="efThumbFileInput" style="cursor:pointer;">
                  <i class="fas fa-image me-1"></i>Nahrát
                </label>
              </div>
              <input type="file" id="efThumbFileInput" accept="image/*" class="d-none">
              <div id="efThumbProgress" class="d-none mt-1">
                <div class="progress" style="height:5px;"><div id="efThumbBar" class="progress-bar bg-warning" style="width:0%"></div></div>
                <div id="efThumbStatus" class="small text-muted"></div>
              </div>
            </div>
            <div class="mb-2">
              <label class="form-label small fw-semibold">
                Krátký popis
                <span class="text-muted fw-normal small">– zobrazuje se v přehledu cviků</span>
              </label>
              <textarea name="description" id="efDesc" class="form-control form-control-sm" rows="2"
                        placeholder="Např: Klasický silový cvik na rozvoj prsního svalstva."></textarea>
            </div>
            <div class="mb-2">
              <label class="form-label small fw-semibold">
                Provedení / instrukce
                <span class="text-muted fw-normal small">– krok za krokem jak cvik provést</span>
              </label>
              <textarea name="instructions" id="efInstr" class="form-control form-control-sm" rows="4"
                        placeholder="1. Lehněte si na bench...&#10;2. Uchopte činku...&#10;3. Spusťte pomalu dolů..."></textarea>
            </div>
            <div class="row g-2 mb-2">
              <div class="col-6">
                <label class="form-label small fw-semibold">Pořadí</label>
                <input type="number" name="sort_order" id="efSort" class="form-control form-control-sm" value="0">
              </div>
            </div>
            <div class="mb-3 form-check">
              <input type="checkbox" name="is_active" id="efActive" class="form-check-input" value="1" checked>
              <label class="form-check-label small" for="efActive">Aktivní</label>
            </div>
            <div class="d-flex gap-2">
              <button type="submit" class="btn btn-warning btn-sm fw-semibold flex-grow-1">Uložit cvik</button>
              <button type="button" class="btn btn-outline-secondary btn-sm" onclick="exerciseFormReset()">Reset</button>
            </div>
          </form>
        </div>
      </div>

      <div class="card mcc-card">
        <div class="card-header bg-dark text-white">
          <i class="fas fa-file-csv me-1"></i>Import cviků z CSV
        </div>
        <div class="card-body">
          <p class="small text-muted mb-2">
            Nahrajte více cviků najednou podle české šablony. Pokud CSV obsahuje <strong>ID</strong> nebo stejný <strong>Název</strong>, cvik se aktualizuje.
          </p>
          <div class="d-flex flex-wrap gap-2 mb-3">
            <a class="btn btn-outline-secondary btn-sm" href="<?= BASE_URL ?>/scripts/csv/mycoach_cviky_import_sablona.csv" download>
              <i class="fas fa-download me-1"></i>Stáhnout šablonu CSV
            </a>
            <a class="btn btn-outline-secondary btn-sm" href="<?= BASE_URL ?>/scripts/csv/mycoach_cviky_import_navod.md" target="_blank" rel="noopener">
              <i class="fas fa-book me-1"></i>Návod k importu
            </a>
          </div>
          <form method="post" enctype="multipart/form-data">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="import_exercises_csv">
            <div class="mb-2">
              <label class="form-label small fw-semibold">CSV soubor (oddělovač středník ;)</label>
              <input type="file" name="exercises_csv" class="form-control form-control-sm" accept=".csv,text/csv" required>
            </div>
            <button type="submit" class="btn btn-warning btn-sm fw-semibold w-100">
              <i class="fas fa-file-import me-1"></i>Importovat cviky
            </button>
          </form>
        </div>
      </div>
    </div>
    <div class="col-lg-8">
      <div class="card mcc-card">
        <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center flex-wrap gap-2">
          <span>Encyklopedie cviků (<?= count($exercises) ?>)</span>
          <?php if (!empty($exercises)): ?>
          <div class="d-flex flex-wrap gap-2 align-items-center">
            <span class="small text-muted">Filtr:</span>
            <div class="btn-group btn-group-sm" role="group" aria-label="Kategorie">
              <button type="button" class="btn btn-light active exercise-filter-btn category" data-filter-type="category" data-filter-value="all">Vše</button>
              <?php foreach ($exerciseCategories as $categoryName): ?>
                <button type="button" class="btn btn-outline-light exercise-filter-btn category" data-filter-type="category" data-filter-value="<?= h(mb_strtolower($categoryName, 'UTF-8')) ?>"><?= h($categoryName) ?></button>
              <?php endforeach; ?>
            </div>
            <div class="btn-group btn-group-sm" role="group" aria-label="Svalové partie">
              <button type="button" class="btn btn-light active exercise-filter-btn muscle" data-filter-type="muscle" data-filter-value="all">Vše</button>
              <?php foreach ($exerciseMuscles as $muscleName): ?>
                <button type="button" class="btn btn-outline-light exercise-filter-btn muscle" data-filter-type="muscle" data-filter-value="<?= h(mb_strtolower($muscleName, 'UTF-8')) ?>"><?= h($muscleName) ?></button>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endif; ?>
        </div>
        <div class="card-body p-0">
          <?php if (empty($exercises)): ?>
            <p class="p-3 text-muted">Zatím žádné cviky.</p>
          <?php else: ?>
          <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead class="table-light"><tr>
              <th>Cvik</th><th>Kategorie</th><th>Obtížnost</th><th>Svalové partie</th><th class="text-center">Aktivní</th><th class="text-end">Akce</th>
            </tr></thead>
            <tbody id="exerciseTableBody">
            <?php foreach ($exercises as $ex): ?>
            <?php
              $rowCategoryValues = array_map(static fn($v) => mb_strtolower($v, 'UTF-8'), mcSplitListValues((string)($ex['category'] ?? '')));
              $rowMuscleValues = array_map(static fn($v) => mb_strtolower($v, 'UTF-8'), mcSplitListValues((string)($ex['muscle_groups'] ?? '')));
            ?>
            <tr data-exercise-row
                data-category="<?= h(implode('|', $rowCategoryValues)) ?>"
                data-muscle="<?= h(implode('|', $rowMuscleValues)) ?>">
              <td class="fw-semibold"><?= h($ex['name']) ?></td>
              <td class="small text-muted"><?= h($ex['category'] ?? '–') ?></td>
              <td><span class="badge <?= ['beginner'=>'bg-success','intermediate'=>'bg-warning text-dark','advanced'=>'bg-danger'][$ex['difficulty']] ?? 'bg-secondary' ?> small">
                <?= $difficultyOpts[$ex['difficulty']] ?? $ex['difficulty'] ?></span>
              </td>
              <td class="small text-muted"><?= h($ex['muscle_groups'] ?? '–') ?></td>
              <td class="text-center"><span class="badge <?= $ex['is_active'] ? 'bg-success' : 'bg-secondary' ?>"><?= $ex['is_active'] ? 'Ano' : 'Ne' ?></span></td>
              <td class="text-end">
                <button type="button" class="btn btn-xs btn-outline-primary btn-sm"
                        onclick='exerciseFormFill(<?= json_encode($ex, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>)'>
                  <i class="fas fa-pen"></i>
                </button>
                <form method="post" class="d-inline" onsubmit="return confirm('Smazat cvik?')">
                  <?= csrfField() ?><input type="hidden" name="action" value="delete_exercise">
                  <input type="hidden" name="exercise_id" value="<?= (int)$ex['id'] ?>">
                  <button class="btn btn-xs btn-outline-danger btn-sm"><i class="fas fa-trash"></i></button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ══════════ TRÉNINKY ══════════ -->
<div class="tab-pane fade" id="tabWorkouts">
  <?php if ($editWorkout): ?>
  <!-- Detail tréninku – přidání cviků -->
  <div class="card mcc-card mb-3">
    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
      <span><i class="fas fa-list-ol me-1"></i>Cviky v tréninku: <strong><?= h($editWorkout['title']) ?></strong></span>
      <a href="<?= BASE_URL ?>/admin/mycoach_content.php#workouts" class="btn btn-sm btn-outline-light">
        <i class="fas fa-arrow-left me-1"></i>Zpět
      </a>
    </div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-5">
          <form method="post">
            <?= csrfField() ?><input type="hidden" name="action" value="save_workout_exercise">
            <input type="hidden" name="workout_id" value="<?= (int)$editWorkout['id'] ?>">
            <div class="mb-2">
              <label class="form-label small fw-semibold">Cvik *</label>
              <select name="exercise_id" class="form-select form-select-sm" required>
                <?= mcAdminExerciseOptions($exercises) ?>
              </select>
            </div>
            <div class="row g-2 mb-2">
              <div class="col-3"><label class="form-label small fw-semibold">Série</label><input type="number" name="sets" class="form-control form-control-sm" min="1" max="99"></div>
              <div class="col-3"><label class="form-label small fw-semibold">Opak.</label><input type="number" name="reps" class="form-control form-control-sm" min="1" max="999"></div>
              <div class="col-3"><label class="form-label small fw-semibold">Čas (s)</label><input type="number" name="duration_seconds" class="form-control form-control-sm" min="0"></div>
              <div class="col-3"><label class="form-label small fw-semibold">Pauza (s)</label><input type="number" name="rest_seconds" class="form-control form-control-sm" min="0"></div>
            </div>
            <div class="mb-2"><label class="form-label small fw-semibold">Poznámka</label><input type="text" name="notes" class="form-control form-control-sm"></div>
            <div class="mb-2"><label class="form-label small fw-semibold">Pořadí</label><input type="number" name="sort_order" class="form-control form-control-sm" value="<?= count($workoutExercises) * 10 ?>"></div>
            <button type="submit" class="btn btn-warning btn-sm fw-semibold">Přidat cvik</button>
          </form>
        </div>
        <div class="col-md-7">
          <?php if (empty($workoutExercises)): ?>
            <p class="text-muted">Žádné cviky v tréninku.</p>
          <?php else: ?>
          <table class="table table-sm align-middle mb-0">
            <thead class="table-light"><tr><th>#</th><th>Cvik</th><th>Série×Opak.</th><th>Čas</th><th>Pauza</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($workoutExercises as $we): ?>
            <tr>
              <td class="text-muted small"><?= (int)$we['sort_order'] ?></td>
              <td class="fw-semibold"><?= h($we['ex_name'] ?? '?') ?></td>
              <td class="small"><?= $we['sets'] ? $we['sets'] . '×' . $we['reps'] : '–' ?></td>
              <td class="small"><?= $we['duration_seconds'] ? $we['duration_seconds'] . 's' : '–' ?></td>
              <td class="small"><?= $we['rest_seconds'] ? $we['rest_seconds'] . 's' : '–' ?></td>
              <td>
                <form method="post" class="d-inline" onsubmit="return confirm('Odebrat cvik?')">
                  <?= csrfField() ?><input type="hidden" name="action" value="delete_workout_exercise">
                  <input type="hidden" name="workout_exercise_id" value="<?= (int)$we['id'] ?>">
                  <input type="hidden" name="workout_id" value="<?= (int)$editWorkout['id'] ?>">
                  <button class="btn btn-xs btn-outline-danger btn-sm"><i class="fas fa-times"></i></button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="row g-3">
    <div class="col-lg-4">
      <div class="card mcc-card">
        <div class="card-header" style="background:#1a1a2e;color:#f7941d;"><i class="fas fa-plus me-1"></i>Přidat / upravit trénink</div>
        <div class="card-body">
          <form method="post" id="workoutForm">
            <?= csrfField() ?><input type="hidden" name="action" value="save_workout">
            <input type="hidden" name="workout_id" id="wfId" value="0">
            <div class="mb-2">
              <label class="form-label small fw-semibold">Název *</label>
              <input type="text" name="title" id="wfTitle" class="form-control form-control-sm" required>
            </div>
            <div class="mb-2">
              <label class="form-label small fw-semibold">Sekce</label>
              <select name="section_id" id="wfSection" class="form-select form-select-sm"><?= mcAdminSectionOptions($sections) ?></select>
            </div>
            <div class="mb-2">
              <label class="form-label small fw-semibold">Kategorie tréninku</label>
              <input type="text" name="category" id="wfCategory" class="form-control form-control-sm" placeholder="např. Protažení, Kardio, Trénink doma">
              <div class="form-text small">Můžete zadat více kategorií oddělených čárkou.</div>
            </div>
            <div class="row g-2 mb-2">
              <div class="col-6">
                <label class="form-label small fw-semibold">Obtížnost</label>
                <select name="difficulty" id="wfDiff" class="form-select form-select-sm">
                  <?php foreach ($difficultyOpts as $k=>$v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?>
                </select>
              </div>
              <div class="col-6">
                <label class="form-label small fw-semibold">Délka (min)</label>
                <input type="number" name="duration_minutes" id="wfDur" class="form-control form-control-sm" min="0">
              </div>
            </div>
            <div class="mb-2">
              <label class="form-label small fw-semibold">Popis</label>
              <textarea name="description" id="wfDesc" class="form-control form-control-sm" rows="2"></textarea>
            </div>
            <div class="row g-2 mb-2">
              <div class="col-6">
                <label class="form-label small fw-semibold">Pořadí</label>
                <input type="number" name="sort_order" id="wfSort" class="form-control form-control-sm" value="0">
              </div>
            </div>
            <div class="mb-3 form-check">
              <input type="checkbox" name="is_active" id="wfActive" class="form-check-input" value="1" checked>
              <label class="form-check-label small" for="wfActive">Aktivní</label>
            </div>
            <div class="d-flex gap-2">
              <button type="submit" class="btn btn-warning btn-sm fw-semibold flex-grow-1">Uložit trénink</button>
              <button type="button" class="btn btn-outline-secondary btn-sm" onclick="workoutFormReset()">Reset</button>
            </div>
          </form>
        </div>
      </div>

      <div class="card mcc-card">
        <div class="card-header bg-dark text-white">
          <i class="fas fa-file-csv me-1"></i>Import tréninků z CSV
        </div>
        <div class="card-body">
          <p class="small text-muted mb-2">
            Nahrajte více tréninků najednou podle české šablony. Pokud CSV obsahuje <strong>ID</strong> nebo stejný <strong>Název</strong>, trénink se aktualizuje.
          </p>
          <div class="d-flex flex-wrap gap-2 mb-3">
            <a class="btn btn-outline-secondary btn-sm" href="<?= BASE_URL ?>/scripts/csv/mycoach_treninky_import_sablona.csv" download>
              <i class="fas fa-download me-1"></i>Stáhnout šablonu CSV
            </a>
            <a class="btn btn-outline-secondary btn-sm" href="<?= BASE_URL ?>/scripts/csv/mycoach_treninky_import_navod.md" target="_blank" rel="noopener">
              <i class="fas fa-book me-1"></i>Návod k importu
            </a>
          </div>
          <form method="post" enctype="multipart/form-data">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="import_workouts_csv">
            <div class="mb-2">
              <label class="form-label small fw-semibold">CSV soubor (oddělovač středník ;)</label>
              <input type="file" name="workouts_csv" class="form-control form-control-sm" accept=".csv,text/csv" required>
            </div>
            <button type="submit" class="btn btn-warning btn-sm fw-semibold w-100">
              <i class="fas fa-file-import me-1"></i>Importovat tréninky
            </button>
          </form>
        </div>
      </div>
    </div>
    <div class="col-lg-8">
      <div class="card mcc-card">
        <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center flex-wrap gap-2">
          <span>Tréninky (<?= count($workouts) ?>)</span>
          <?php if (!empty($workoutCategories)): ?>
            <div class="d-flex align-items-center gap-2 flex-wrap">
              <span class="small text-muted">Filtr kategorie:</span>
              <div class="btn-group btn-group-sm" role="group" aria-label="Kategorie tréninků">
                <button type="button" class="btn btn-light active workout-filter-btn" data-filter-value="all">Vše</button>
                <?php foreach ($workoutCategories as $woCategory): ?>
                  <button type="button" class="btn btn-outline-light workout-filter-btn" data-filter-value="<?= h(mb_strtolower($woCategory, 'UTF-8')) ?>"><?= h($woCategory) ?></button>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endif; ?>
        </div>
        <div class="card-body p-0">
          <?php if (empty($workouts)): ?>
            <p class="p-3 text-muted">Zatím žádné tréninky.</p>
          <?php else: ?>
          <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead class="table-light"><tr><th>Název</th><th>Kategorie</th><th>Sekce</th><th>Obtížnost</th><th class="text-center">Délka</th><th class="text-end">Akce</th></tr></thead>
            <tbody>
            <?php foreach ($workouts as $wo): ?>
            <?php $woCategoryTokens = array_map(static fn($v) => mb_strtolower($v, 'UTF-8'), mcSplitListValues((string)($wo['category'] ?? ''))); ?>
            <tr data-workout-row data-category="<?= h(implode('|', $woCategoryTokens)) ?>">
              <td class="fw-semibold"><?= h($wo['title']) ?></td>
              <td class="small text-muted"><?= h($wo['category'] ?? '–') ?></td>
              <td class="small text-muted"><?= h($wo['section_title'] ?? '–') ?></td>
              <td><span class="badge <?= ['beginner'=>'bg-success','intermediate'=>'bg-warning text-dark','advanced'=>'bg-danger'][$wo['difficulty']] ?? 'bg-secondary' ?> small"><?= $difficultyOpts[$wo['difficulty']] ?? $wo['difficulty'] ?></span></td>
              <td class="text-center small"><?= $wo['duration_minutes'] ? $wo['duration_minutes'] . ' min' : '–' ?></td>
              <td class="text-end">
                <a href="<?= BASE_URL ?>/admin/mycoach_content.php?edit_workout=<?= (int)$wo['id'] ?>#workouts"
                   class="btn btn-xs btn-outline-info btn-sm" title="Cviky tréninku">
                  <i class="fas fa-list-ol"></i>
                </a>
                <button type="button" class="btn btn-xs btn-outline-primary btn-sm"
                        onclick='workoutFormFill(<?= json_encode($wo, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>)'>
                  <i class="fas fa-pen"></i>
                </button>
                <form method="post" class="d-inline" onsubmit="return confirm('Smazat trénink?')">
                  <?= csrfField() ?><input type="hidden" name="action" value="delete_workout">
                  <input type="hidden" name="workout_id" value="<?= (int)$wo['id'] ?>">
                  <input type="hidden" name="section_id" value="<?= (int)($wo['section_id'] ?? 0) ?>">
                  <button class="btn btn-xs btn-outline-danger btn-sm"><i class="fas fa-trash"></i></button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

</div><!-- /tab-content -->

<script>
// ── Sekce formulář ──────────────────────────────────────────────
function sectionFormReset() {
  document.getElementById('sfId').value='0';
  ['sfTitle','sfSubtitle','sfIcon','sfDesc'].forEach(id=>{document.getElementById(id).value='';});
  document.getElementById('sfSort').value='0';
  document.getElementById('sfActive').checked=true;
  document.getElementById('sfType').value='videos';
  document.getElementById('sfColor').value='orange';
  document.getElementById('sfAudience').value='all';
}

// Přepne na tab Videa a předvyplní sekci
function jumpToAddVideo(sectionId) {
  const tab = document.querySelector('[data-bs-target="#tabVideos"]');
  if (tab) bootstrap.Tab.getOrCreateInstance(tab).show();
  videoFormReset();
  document.getElementById('vfSection').value = sectionId;
  setTimeout(()=>{ document.getElementById('videoForm').scrollIntoView({behavior:'smooth',block:'start'}); }, 200);
}

// Přepne na tab Tréninky a předvyplní sekci
function jumpToAddWorkout(sectionId) {
  const tab = document.querySelector('[data-bs-target="#tabWorkouts"]');
  if (tab) bootstrap.Tab.getOrCreateInstance(tab).show();
  workoutFormReset();
  setTimeout(()=>{
    const sel = document.getElementById('wfSection');
    if (sel) sel.value = sectionId;
    document.getElementById('workoutForm').scrollIntoView({behavior:'smooth',block:'start'});
  }, 200);
}
function sectionFormFill(sec) {
  document.getElementById('sfId').value=sec.id;
  document.getElementById('sfTitle').value=sec.title||'';
  document.getElementById('sfSubtitle').value=sec.subtitle||'';
  document.getElementById('sfIcon').value=sec.icon_class||'';
  document.getElementById('sfColor').value=sec.tile_color||'orange';
  document.getElementById('sfType').value=sec.section_type||'videos';
  document.getElementById('sfAudience').value=sec.audience||'all';
  document.getElementById('sfDesc').value=sec.description||'';
  document.getElementById('sfSort').value=sec.sort_order||0;
  document.getElementById('sfActive').checked=sec.is_active=='1'||sec.is_active===1;
  document.getElementById('sectionFormWrap').scrollIntoView({behavior:'smooth'});
}

// ── Videa formulář ──────────────────────────────────────────────
function videoFormReset() {
  document.getElementById('vfId').value='0';
  ['vfTitle','vfUrl','vfPath','vfThumb','vfTags','vfDesc'].forEach(id=>{document.getElementById(id).value='';});
  document.getElementById('vfPathDisplay').value='';
  document.getElementById('vfUploadProgress').classList.add('d-none');
  document.getElementById('vfDur').value='';
  document.getElementById('vfSort').value='0';
  document.getElementById('vfActive').checked=true;
  document.getElementById('vfType').value='youtube';
  videoTypeChange(document.getElementById('vfType'));
}
function videoTypeChange(sel) {
  const isUpload = sel.value==='upload';
  document.getElementById('vfUrlWrap').classList.toggle('d-none', isUpload);
  document.getElementById('vfPathWrap').classList.toggle('d-none', !isUpload);
  const labels={'youtube':'YouTube URL nebo ID','vimeo':'Vimeo URL nebo ID','url':'Přímá URL videa'};
  document.getElementById('vfUrlLabel').textContent=labels[sel.value]||'URL';
}
function videoFormFill(vid) {
  document.getElementById('vfId').value=vid.id;
  document.getElementById('vfTitle').value=vid.title||'';
  document.getElementById('vfSection').value=vid.section_id||'';
  document.getElementById('vfType').value=vid.video_type||'youtube';
  videoTypeChange(document.getElementById('vfType'));
  document.getElementById('vfUrl').value=vid.video_url||'';
  document.getElementById('vfPath').value=vid.video_path||'';
  document.getElementById('vfPathDisplay').value=vid.video_path ? '✔ '+vid.video_path.split('/').pop() : '';
  document.getElementById('vfThumb').value=vid.thumbnail||'';
  document.getElementById('vfDur').value=vid.duration_seconds||'';
  document.getElementById('vfTags').value=vid.tags||'';
  document.getElementById('vfDesc').value=vid.description||'';
  document.getElementById('vfSort').value=vid.sort_order||0;
  document.getElementById('vfActive').checked=vid.is_active=='1'||vid.is_active===1;
  document.getElementById('videoForm').scrollIntoView({behavior:'smooth',block:'start'});
}

// ── Video upload přes AJAX ──────────────────────────────────────
document.getElementById('vfFileInput').addEventListener('change', function() {
  const file = this.files[0];
  if (!file) return;
  const display   = document.getElementById('vfPathDisplay');
  const progress  = document.getElementById('vfUploadProgress');
  const bar       = document.getElementById('vfUploadBar');
  const status    = document.getElementById('vfUploadStatus');
  const pathField = document.getElementById('vfPath');

  display.value = file.name;
  progress.classList.remove('d-none');
  bar.style.width = '0%';
  status.textContent = 'Nahrávám...';

  const fd = new FormData();
  fd.append('video', file);
  fd.append('csrf_token', <?= json_encode(csrfToken()) ?>);

  const xhr = new XMLHttpRequest();
  xhr.open('POST', <?= json_encode(BASE_URL . '/admin/api/mycoach_video_upload.php') ?>);

  xhr.upload.addEventListener('progress', function(e) {
    if (e.lengthComputable) {
      const pct = Math.round(e.loaded / e.total * 100);
      bar.style.width = pct + '%';
      status.textContent = 'Nahrávám... ' + pct + '%';
    }
  });

  xhr.addEventListener('load', function() {
    let resp;
    try { resp = JSON.parse(xhr.responseText); } catch(e) { resp = {success:false,error:'Neplatná odpověď serveru.'}; }
    if (resp.success) {
      pathField.value = resp.path;
      bar.style.width = '100%';
      bar.classList.replace('bg-warning','bg-success');
      status.textContent = '✔ Nahráno: ' + resp.name;
    } else {
      bar.classList.replace('bg-warning','bg-danger');
      status.textContent = '✘ Chyba: ' + (resp.error || 'Neznámá chyba');
    }
  });

  xhr.addEventListener('error', function() {
    bar.classList.replace('bg-warning','bg-danger');
    status.textContent = '✘ Síťová chyba při nahrávání.';
  });

  xhr.send(fd);
});

// ── Cviky formulář ──────────────────────────────────────────────
function exerciseFormReset() {
  document.getElementById('efId').value='0';
  ['efName','efCat','efMuscles','efEquip','efVideo','efThumb','efDesc','efInstr'].forEach(id=>{document.getElementById(id).value='';});
  document.getElementById('efDiff').value='intermediate';
  document.getElementById('efSort').value='0';
  document.getElementById('efActive').checked=true;
  document.getElementById('exerciseFormTitle').textContent='Přidat cvik';
}
function exerciseFormFill(ex) {
  document.getElementById('efId').value=ex.id;
  document.getElementById('efName').value=ex.name||'';
  document.getElementById('efCat').value=ex.category||'';
  document.getElementById('efDiff').value=ex.difficulty||'intermediate';
  document.getElementById('efMuscles').value=ex.muscle_groups||'';
  document.getElementById('efEquip').value=ex.equipment||'';
  document.getElementById('efVideo').value=ex.video_url||'';
  document.getElementById('efVideoPath').value='';
  document.getElementById('efVideoPathDisplay').value=ex.video_url&&!ex.video_url.startsWith('http')?'✔ '+ex.video_url.split('/').pop():'';
  document.getElementById('efThumb').value=ex.thumbnail||'';
  document.getElementById('efThumbPath').value='';
  document.getElementById('efThumbDisplay').value=ex.thumbnail&&!ex.thumbnail.startsWith('http')?'✔ '+ex.thumbnail.split('/').pop():'';
  document.getElementById('efDesc').value=ex.description||'';
  document.getElementById('efInstr').value=ex.instructions||'';
  document.getElementById('efSort').value=ex.sort_order||0;
  document.getElementById('efActive').checked=ex.is_active=='1'||ex.is_active===1;
  document.getElementById('exerciseFormTitle').textContent='Upravit cvik: '+ex.name;
  document.getElementById('exerciseForm').scrollIntoView({behavior:'smooth',block:'start'});
}

function applyExerciseAdminFilter(filterType, value) {
  const rows = document.querySelectorAll('[data-exercise-row]');
  const activeCat = document.querySelector('.exercise-filter-btn.category.active')?.dataset.filterValue || 'all';
  const activeMuscle = document.querySelector('.exercise-filter-btn.muscle.active')?.dataset.filterValue || 'all';

  rows.forEach(function(row) {
    const rowCats = (row.dataset.category || '').split('|').filter(Boolean);
    const rowMuscles = (row.dataset.muscle || '').split('|').filter(Boolean);
    const catMatch = activeCat === 'all' || rowCats.includes(activeCat);
    const muscleMatch = activeMuscle === 'all' || rowMuscles.includes(activeMuscle);
    row.style.display = catMatch && muscleMatch ? '' : 'none';
  });
}

document.querySelectorAll('.exercise-filter-btn').forEach(function(btn) {
  btn.addEventListener('click', function() {
    const type = btn.dataset.filterType;
    document.querySelectorAll('.exercise-filter-btn.' + type).forEach(function(el) {
      el.classList.toggle('active', el === btn);
      el.classList.toggle('btn-light', el === btn);
      el.classList.toggle('btn-outline-light', el !== btn);
    });
    applyExerciseAdminFilter(type, btn.dataset.filterValue);
  });
});

// Upload videa cviku
function efUpload(inputId, displayId, progressId, barId, statusId, pathFieldId, isImage) {
  const inp = document.getElementById(inputId);
  inp.addEventListener('change', function() {
    const file = this.files[0]; if (!file) return;
    const fd = new FormData();
    const csrf = <?= json_encode(csrfToken()) ?>;
    let endpoint;
    if (isImage) {
      fd.append('file', file); fd.append('media_type','image'); fd.append('csrf_token', csrf);
      endpoint = <?= json_encode(BASE_URL . '/admin/api/events_media_upload.php') ?>;
    } else {
      fd.append('video', file); fd.append('csrf_token', csrf);
      endpoint = <?= json_encode(BASE_URL . '/admin/api/mycoach_video_upload.php') ?>;
    }
    document.getElementById(displayId).value = file.name;
    document.getElementById(progressId).classList.remove('d-none');
    const bar = document.getElementById(barId);
    const status = document.getElementById(statusId);
    bar.style.width='0%';
    const xhr = new XMLHttpRequest();
    xhr.open('POST', endpoint);
    xhr.upload.addEventListener('progress', e => { if(e.lengthComputable){const p=Math.round(e.loaded/e.total*100); bar.style.width=p+'%'; status.textContent='Nahrávám... '+p+'%';} });
    xhr.addEventListener('load', () => {
      let r; try{r=JSON.parse(xhr.responseText);}catch(e){r={success:false,error:'Chyba'}}
      if(r.success){ document.getElementById(pathFieldId).value=r.path||r.url||''; bar.style.width='100%'; bar.classList.replace('bg-warning','bg-success'); status.textContent='✔ Nahráno';}
      else{ bar.classList.replace('bg-warning','bg-danger'); status.textContent='✘ '+(r.error||'Chyba'); }
    });
    xhr.addEventListener('error', ()=>{ bar.classList.replace('bg-warning','bg-danger'); status.textContent='✘ Síťová chyba'; });
    xhr.send(fd);
  });
}
efUpload('efVideoFileInput','efVideoPathDisplay','efVideoProgress','efVideoBar','efVideoStatus','efVideoPath', false);
efUpload('efThumbFileInput','efThumbDisplay','efThumbProgress','efThumbBar','efThumbStatus','efThumbPath', true);

// ── Tréninky formulář ────────────────────────────────────────────
function workoutFormReset() {
  document.getElementById('wfId').value='0';
  ['wfTitle','wfCategory','wfDesc'].forEach(id=>{document.getElementById(id).value='';});
  document.getElementById('wfDur').value='';
  document.getElementById('wfSort').value='0';
  document.getElementById('wfActive').checked=true;
  document.getElementById('wfDiff').value='intermediate';
  document.getElementById('wfSection').value='';
}
function workoutFormFill(wo) {
  document.getElementById('wfId').value=wo.id;
  document.getElementById('wfTitle').value=wo.title||'';
  document.getElementById('wfSection').value=wo.section_id||'';
  document.getElementById('wfCategory').value=wo.category||'';
  document.getElementById('wfDiff').value=wo.difficulty||'intermediate';
  document.getElementById('wfDur').value=wo.duration_minutes||'';
  document.getElementById('wfDesc').value=wo.description||'';
  document.getElementById('wfSort').value=wo.sort_order||0;
  document.getElementById('wfActive').checked=wo.is_active=='1'||wo.is_active===1;
}

function applyWorkoutAdminFilter(value) {
  const rows = document.querySelectorAll('[data-workout-row]');
  rows.forEach(function(row) {
    const categories = (row.dataset.category || '').split('|').filter(Boolean);
    const match = value === 'all' || categories.includes(value);
    row.style.display = match ? '' : 'none';
  });
}

document.querySelectorAll('.workout-filter-btn').forEach(function(btn) {
  btn.addEventListener('click', function() {
    const value = btn.dataset.filterValue || 'all';
    document.querySelectorAll('.workout-filter-btn').forEach(function(el) {
      el.classList.toggle('active', el === btn);
      el.classList.toggle('btn-light', el === btn);
      el.classList.toggle('btn-outline-light', el !== btn);
    });
    applyWorkoutAdminFilter(value);
  });
});

// Aktivace správného tabu dle URL hash nebo ?sec=
(function(){
  const sec = new URLSearchParams(location.search).get('sec');
  if (sec) {
    const el = document.querySelector('[data-bs-target="#tabSection'+sec+'"]');
    if (el) { new bootstrap.Tab(el).show(); return; }
  }
  const map={'#sections':'tabSections','#exercises':'tabExercises','#workouts':'tabWorkouts'};
  const tab = map[location.hash];
  if (tab) {
    const el = document.querySelector('[data-bs-target="#'+tab+'"]');
    if (el) new bootstrap.Tab(el).show();
  }
})();

// ── Section Items formulář ──────────────────────────────────────
const sifVideoTypes = new Set(['video_youtube','video_vimeo','video_upload']);
const sifFileTypes  = new Set(['video_upload','image']);
const sifUrlTypes   = new Set(['video_youtube','video_vimeo','link']);
const sifDurTypes   = new Set(['video_youtube','video_vimeo','video_upload']);
const sifContentTypes = new Set(['article']);

function sifTypeChange(secId) {
  const type = document.getElementById('sifType_'+secId).value;
  const show = (id, vis) => { const el=document.getElementById(id+'_'+secId); if(el) el.classList.toggle('d-none', !vis); };
  show('sifUrlWrap',   sifUrlTypes.has(type));
  show('sifFileWrap',  sifFileTypes.has(type));
  show('sifContentWrap', sifContentTypes.has(type));
  show('sifDurWrap',   sifDurTypes.has(type));
  show('sifThumbWrap', type !== 'image'); // pro image je thumbnail = soubor
  show('sifLinkHint',  type === 'link');
  const urlLabel = document.getElementById('sifUrlLabel_'+secId);
  if (urlLabel) {
    const labels = {video_youtube:'YouTube URL nebo ID', video_vimeo:'Vimeo URL nebo ID', link:'URL odkazu'};
    urlLabel.textContent = labels[type] || 'URL';
  }
  // Nastav accept na file inputu
  const fi = document.getElementById('sifFileInput_'+secId);
  if (fi) fi.accept = (type==='image') ? 'image/*' : 'video/mp4,video/webm,video/ogg,video/quicktime,video/x-msvideo';
}

function sifReset(secId) {
  document.getElementById('sifId_'+secId).value='0';
  ['sifItemTitle','sifUrl','sifFilePath','sifThumb','sifDesc','sifContent','sifDur'].forEach(id=>{
    const el=document.getElementById(id+'_'+secId); if(el) el.value='';
  });
  const fd=document.getElementById('sifFileDisplay_'+secId); if(fd) fd.value='';
  const fp=document.getElementById('sifFileProgress_'+secId); if(fp) fp.classList.add('d-none');
  const ss=document.getElementById('sifSort_'+secId); if(ss) ss.value='0';
  const sa=document.getElementById('sifActive_'+secId); if(sa) sa.checked=true;
  const st=document.getElementById('sifType_'+secId); if(st) { st.value='video_youtube'; sifTypeChange(secId); }
  const tl=document.getElementById('sifTitle_'+secId); if(tl) tl.textContent='Přidat položku';
}

function sifFill(secId, item) {
  document.getElementById('sifId_'+secId).value = item.id;
  document.getElementById('sifType_'+secId).value = item.item_type||'video_youtube';
  sifTypeChange(secId);
  const set = (id, val) => { const el=document.getElementById(id+'_'+secId); if(el) el.value=val||''; };
  set('sifItemTitle', item.title);
  set('sifUrl',       item.url);
  set('sifFilePath',  item.file_path);
  const fd=document.getElementById('sifFileDisplay_'+secId);
  if(fd) fd.value = item.file_path ? '✔ '+item.file_path.split('/').pop() : '';
  set('sifThumb',     item.thumbnail);
  set('sifDesc',      item.description);
  set('sifContent',   item.content);
  set('sifDur',       item.duration_seconds);
  set('sifSort',      item.sort_order);
  const sa=document.getElementById('sifActive_'+secId); if(sa) sa.checked=item.is_active=='1'||item.is_active===1;
  const tl=document.getElementById('sifTitle_'+secId); if(tl) tl.textContent='Upravit: '+item.title;
  document.getElementById('sifForm_'+secId).scrollIntoView({behavior:'smooth',block:'start'});
}

// ── Univerzální file upload pro section_items ──────────────────
document.querySelectorAll('[id^="sifFileInput_"]').forEach(function(input) {
  const secId = input.id.replace('sifFileInput_','');
  input.addEventListener('change', function() {
    const file = this.files[0];
    if (!file) return;
    const isImage = file.type.startsWith('image/');
    const display  = document.getElementById('sifFileDisplay_'+secId);
    const progress = document.getElementById('sifFileProgress_'+secId);
    const bar      = document.getElementById('sifFileBar_'+secId);
    const status   = document.getElementById('sifFileStatus_'+secId);
    const pathFld  = document.getElementById('sifFilePath_'+secId);
    if(display) display.value = file.name;
    if(progress) progress.classList.remove('d-none');
    if(bar) bar.style.width='0%';
    if(status) status.textContent='Nahrávám...';

    const fd2 = new FormData();
    const csrf = <?= json_encode(csrfToken()) ?>;
    if (isImage) {
      fd2.append('file', file);
      fd2.append('media_type', 'image');
      fd2.append('csrf_token', csrf);
    } else {
      fd2.append('video', file);
      fd2.append('csrf_token', csrf);
    }
    const endpoint = isImage
      ? <?= json_encode(BASE_URL . '/admin/api/events_media_upload.php') ?>
      : <?= json_encode(BASE_URL . '/admin/api/mycoach_video_upload.php') ?>;
    const xhr = new XMLHttpRequest();
    xhr.open('POST', endpoint);
    xhr.upload.addEventListener('progress', function(e) {
      if(e.lengthComputable && bar) { const p=Math.round(e.loaded/e.total*100); bar.style.width=p+'%'; if(status) status.textContent='Nahrávám... '+p+'%'; }
    });
    xhr.addEventListener('load', function() {
      let resp; try { resp=JSON.parse(xhr.responseText); } catch(e) { resp={success:false,error:'Chyba odpovědi'}; }
      if(resp.success) {
        if(pathFld) pathFld.value = resp.path || resp.url || '';
        if(bar) { bar.style.width='100%'; bar.classList.replace('bg-warning','bg-success'); }
        if(status) status.textContent='✔ Nahráno: '+(resp.name||'');
      } else {
        if(bar) bar.classList.replace('bg-warning','bg-danger');
        if(status) status.textContent='✘ '+(resp.error||'Chyba');
      }
    });
    xhr.addEventListener('error', function() {
      if(bar) bar.classList.replace('bg-warning','bg-danger');
      if(status) status.textContent='✘ Síťová chyba.';
    });
    xhr.send(fd2);
  });
});

// Inicializuj všechny section_items formuláře
document.querySelectorAll('[id^="sifType_"]').forEach(function(sel) {
  const secId = sel.id.replace('sifType_','');
  sifTypeChange(secId);
});
</script>

<?php renderAdminFooter(); ?>
