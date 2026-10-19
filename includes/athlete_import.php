<?php

const ATHLETE_IMPORT_MAX_ROWS = 1000;
const ATHLETE_IMPORT_MAX_BYTES = 5 * 1024 * 1024;

function athleteImportHeaders(): array {
    return ['Jméno *', 'Příjmení *', 'Datum narození *', 'Pohlaví', 'Tel. kontakt', 'E-mail', 'Sazba za trénink (Kč)', 'Sazba za párový trénink (Kč)', 'Poznámky'];
}

function athleteImportGenders(): array {
    return ['unknown' => 'Neuvedeno', 'female' => 'Žena', 'male' => 'Muž', 'other' => 'Jiné', 'prefer_not_say' => 'Nechce uvádět'];
}

function athleteImportRequireExtensions(): void {
    if (!class_exists('ZipArchive') || !class_exists('DOMDocument') || !function_exists('mb_strlen')) {
        throw new RuntimeException('Import vyžaduje PHP rozšíření zip, dom a mbstring. Požádejte správce serveru o jejich zapnutí.');
    }
}

function athleteImportXmlEscape(string $value): string {
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function athleteImportXmlRow(int $number, array $values, int $style = 0): string {
    $xml = '<row r="' . $number . '">';
    foreach (array_values($values) as $column => $value) {
        $xml .= '<c r="' . chr(65 + $column) . $number . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">'
            . athleteImportXmlEscape((string)$value) . '</t></is></c>';
    }
    return $xml . '</row>';
}

function athleteImportWriteTemplate(string $path, int $coachId, string $coachName): void {
    athleteImportRequireExtensions();
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Excelovou šablonu se nepodařilo vytvořit.');
    }
    try {
        $sheetStart = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        $sheet = $sheetStart . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="5" topLeftCell="A6" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . '<cols><col min="1" max="2" width="22" customWidth="1" style="0"/>'
            . '<col min="3" max="8" width="28" customWidth="1" style="0"/><col min="9" max="9" width="55" customWidth="1" style="0"/></cols><sheetData>'
            . athleteImportXmlRow(1, ['TrainerApp – import sportovců v1'], 1)
            . athleteImportXmlRow(2, ['ID trenéra (neměnit)', (string)$coachId])
            . athleteImportXmlRow(3, ['Trenér', $coachName])
            . athleteImportXmlRow(4, ['Vyplňujte od řádku 6. Pokyny najdete na listu Návod. Maximálně 1000 sportovců.'])
            . athleteImportXmlRow(5, athleteImportHeaders(), 1);
        for ($row = 6; $row <= ATHLETE_IMPORT_MAX_ROWS + 5; $row++) {
            $sheet .= athleteImportXmlRow($row, array_fill(0, 9, ''));
        }
        $sheet .= '</sheetData><autoFilter ref="A5:I1005"/><dataValidations count="1">'
            . '<dataValidation type="list" allowBlank="1" showErrorMessage="1" errorTitle="Neplatné pohlaví" error="Vyberte hodnotu ze seznamu." sqref="D6:D1005">'
            . '<formula1>"' . implode(',', array_values(athleteImportGenders())) . '"</formula1></dataValidation></dataValidations></worksheet>';
        $instructions = [
            ['Návod k hromadnému importu sportovců'],
            ['Trenér', $coachName . ' (ID ' . $coachId . ')'],
            ['1.', 'Vyplňte list Sportovci od řádku 6, jeden sportovec na jeden řádek.'],
            ['2.', 'Neměňte první 5 řádků, pořadí sloupců ani název listu Sportovci.'],
            ['3.', 'Povinné údaje: jméno, příjmení a datum narození. Ostatní údaje jsou volitelné.'],
            ['Datum narození', 'Zadejte dd.mm.rrrr (např. 15.03.2010). Budoucí ani neplatná data nejsou povolena.'],
            ['Pohlaví', 'Neuvedeno, Žena, Muž, Jiné, Nechce uvádět. Prázdné = Neuvedeno.'],
            ['Tel. kontakt', 'Text, nejvýše 20 znaků. Zachovejte předvolbu +420 a případné úvodní nuly.'],
            ['Sazby v Kč', 'Nezáporné číslo, nejvýše 2 desetinná místa (např. 750 nebo 450,50), bez Kč.'],
            ['Prázdné sazby', 'Základní sazba zůstane nenastavená; prázdná párová sazba používá základní sazbu sportovce.'],
            ['Duplicity', 'Shodné jméno, příjmení a datum narození u stejného trenéra se přeskočí. Existující údaje se nemění.'],
            ['Omezení', 'Maximálně 1000 sportovců, soubor .xlsx do 5 MB. Nepoužívejte vzorce. Prázdné řádky se ignorují.'],
            ['Dokončení', 'Uložte jako .xlsx a předejte administrátorovi ke kontrole a potvrzení importu.'],
            ['Přístup do aplikace', 'Import nevytváří přihlašovací přístupy a neodesílá e-maily. Přístupy lze aktivovat později.'],
        ];
        $guide = $sheetStart . '<cols><col min="1" max="1" width="25" customWidth="1"/><col min="2" max="2" width="125" customWidth="1"/></cols><sheetData>';
        foreach ($instructions as $index => $values) {
            $guide .= athleteImportXmlRow($index + 1, $values, $index === 0 ? 1 : 0);
        }
        $parts = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>',
            '_rels/.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Sportovci" sheetId="1" r:id="rId1"/><sheet name="Návod" sheetId="2" r:id="rId2"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
            'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF334155"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="49" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="49" fontId="1" fillId="2" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>',
            'xl/worksheets/sheet1.xml' => $sheet,
            'xl/worksheets/sheet2.xml' => $guide . '</sheetData></worksheet>',
        ];
        foreach ($parts as $name => $xml) {
            if (!$zip->addFromString($name, $xml)) {
                throw new RuntimeException('Zápis excelové šablony selhal.');
            }
        }
    } finally {
        if (!$zip->close()) {
            throw new RuntimeException('Uložení excelové šablony selhalo.');
        }
    }
}

function athleteImportReadXml(ZipArchive $zip, string $name): DOMXPath {
    $xml = $zip->getFromName($name);
    if ($xml === false || $xml === '' || preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) {
        throw new RuntimeException('Soubor obsahuje chybějící nebo nepovolené XML: ' . $name);
    }
    $previous = libxml_use_internal_errors(true);
    try {
        $document = new DOMDocument();
        if (!$document->loadXML($xml, LIBXML_NONET) || $document->doctype !== null) {
            throw new RuntimeException('Soubor neobsahuje platné XML: ' . $name);
        }
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $xpath->registerNamespace('p', 'http://schemas.openxmlformats.org/package/2006/relationships');
        return $xpath;
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
}

function athleteImportReadWorkbook(string $path, int $coachId): array {
    athleteImportRequireExtensions();
    if (filesize($path) > ATHLETE_IMPORT_MAX_BYTES) {
        throw new RuntimeException('Soubor je příliš velký. Maximum je 5 MB.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Soubor není platný Excel (.xlsx).');
    }
    try {
        $total = 0;
        if ($zip->numFiles > 200) {
            throw new RuntimeException('Soubor obsahuje příliš mnoho částí. Použijte původní šablonu.');
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                throw new RuntimeException('Nelze přečíst obsah souboru.');
            }
            $total += $stat['size'];
            if ($total > 20 * 1024 * 1024) {
                throw new RuntimeException('Rozbalený soubor je příliš velký. Použijte původní šablonu.');
            }
        }
        $workbook = athleteImportReadXml($zip, 'xl/workbook.xml');
        $sheet = $workbook->query('/s:workbook/s:sheets/s:sheet[@name="Sportovci"]')->item(0);
        if (!$sheet instanceof DOMElement) {
            throw new RuntimeException('Chybí list Sportovci. Použijte vygenerovanou šablonu.');
        }
        $relationshipId = $sheet->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
        $relationships = athleteImportReadXml($zip, 'xl/_rels/workbook.xml.rels');
        $target = '';
        foreach ($relationships->query('/p:Relationships/p:Relationship') as $relationship) {
            if ($relationship->getAttribute('Id') === $relationshipId && $relationship->getAttribute('TargetMode') !== 'External') {
                $target = $relationship->getAttribute('Target');
            }
        }
        if (!preg_match('#^(?:/xl/)?(?:worksheets/)[a-zA-Z0-9_.-]+\.xml$#D', $target)) {
            throw new RuntimeException('Neplatný list Sportovci.');
        }
        $sheetPath = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . $target;
        $strings = [];
        if ($zip->locateName('xl/sharedStrings.xml') !== false) {
            $shared = athleteImportReadXml($zip, 'xl/sharedStrings.xml');
            foreach ($shared->query('/s:sst/s:si') as $item) {
                $value = '';
                foreach ($shared->query('.//s:t', $item) as $text) {
                    $value .= $text->textContent;
                }
                $strings[] = $value;
            }
        }
        $data = athleteImportReadXml($zip, $sheetPath);
        $rows = [];
        $seenRows = [];
        foreach ($data->query('/s:worksheet/s:sheetData/s:row') as $row) {
            $number = (int)$row->getAttribute('r');
            if ($number < 1 || isset($seenRows[$number])) {
                throw new RuntimeException('Soubor obsahuje neplatná čísla řádků.');
            }
            $seenRows[$number] = true;
            $values = array_fill(0, 9, '');
            $seenCells = [];
            foreach ($data->query('s:c', $row) as $cell) {
                $reference = $cell->getAttribute('r');
                if (!preg_match('/^([A-Z]+)([1-9][0-9]*)$/D', $reference, $match) || (int)$match[2] !== $number || isset($seenCells[$reference])) {
                    throw new RuntimeException('Soubor obsahuje neplatné adresy buněk.');
                }
                $seenCells[$reference] = true;
                if ($data->query('s:f', $cell)->length > 0) {
                    throw new RuntimeException('Řádek ' . $number . ': vzorce nejsou povoleny. Vložte pouze hodnoty.');
                }
                $type = $cell->getAttribute('t');
                $value = $data->evaluate('string(s:v)', $cell);
                if ($type === 's') {
                    if (!ctype_digit($value) || !array_key_exists((int)$value, $strings)) {
                        throw new RuntimeException('Řádek ' . $number . ': neplatná textová buňka.');
                    }
                    $value = $strings[(int)$value];
                } elseif ($type === 'inlineStr') {
                    $value = '';
                    foreach ($data->query('s:is//s:t', $cell) as $text) {
                        $value .= $text->textContent;
                    }
                } elseif (!in_array($type, ['', 'n', 'd', 'str'], true) && $value !== '') {
                    throw new RuntimeException('Řádek ' . $number . ': nepodporovaná nebo chybová hodnota buňky.');
                }
                $value = trim($value);
                if (strlen($match[1]) !== 1 || $match[1] > 'I') {
                    if ($value !== '' && $number >= 6) {
                        throw new RuntimeException('Řádek ' . $number . ': údaje mimo sloupce A až I.');
                    }
                    continue;
                }
                $values[ord($match[1]) - 65] = $value;
            }
            if ($number <= 5 || implode('', $values) !== '') {
                $rows[$number] = $values;
            }
        }
        if (($rows[1][0] ?? '') !== 'TrainerApp – import sportovců v1'
            || ($rows[2][1] ?? '') !== (string)$coachId || ($rows[5] ?? []) !== athleteImportHeaders()) {
            throw new RuntimeException('Šablona nepatří vybranému trenérovi nebo má změněnou hlavičku. Stáhněte správnou šablonu.');
        }
        $rows = array_filter($rows, static fn(int $number): bool => $number >= 6, ARRAY_FILTER_USE_KEY);
        ksort($rows);
        if (count($rows) > ATHLETE_IMPORT_MAX_ROWS) {
            throw new RuntimeException('Lze importovat maximálně 1000 sportovců najednou.');
        }
        if (!$rows) {
            throw new RuntimeException('Soubor neobsahuje žádné sportovce. Vyplňte údaje od řádku 6.');
        }
        $date1904 = in_array($workbook->evaluate('string(/s:workbook/s:workbookPr/@date1904)'), ['1', 'true'], true);
        return athleteImportValidateRows($rows, $date1904);
    } finally {
        $zip->close();
    }
}

function athleteImportBirthDate(string $value, bool $date1904): ?string {
    if (ctype_digit($value)) {
        $serial = (int)$value;
        if ($serial < ($date1904 ? 0 : 1) || $serial > 2958465 || (!$date1904 && $serial === 60)) {
            return null;
        }
        $base = new DateTimeImmutable($date1904 ? '1904-01-01' : '1899-12-31');
        $date = $base->modify('+' . ($date1904 || $serial < 60 ? $serial : $serial - 1) . ' days');
    } else {
        $date = false;
        foreach (['!j.n.Y', '!Y-m-d'] as $format) {
            $candidate = DateTimeImmutable::createFromFormat($format, $value);
            $errors = DateTimeImmutable::getLastErrors();
            if ($candidate !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                $date = $candidate;
                break;
            }
        }
    }
    return $date && $date->format('Y-m-d') >= '1000-01-01' && $date <= new DateTimeImmutable('today') ? $date->format('Y-m-d') : null;
}

function athleteImportValidateRows(array $rows, bool $date1904 = false): array {
    $valid = [];
    $errors = [];
    $genders = array_flip(athleteImportGenders());
    foreach ($rows as $number => $values) {
        [$first, $last, $birth, $gender, $phone, $email, $rate, $paired, $notes] = $values;
        $rowErrors = [];
        foreach (['Jméno' => [$first, 100, true], 'Příjmení' => [$last, 100, true], 'Tel. kontakt' => [$phone, 20, false], 'E-mail' => [$email, 255, false], 'Poznámky' => [$notes, 16000, false]] as $label => [$value, $max, $required]) {
            if ($required && $value === '') {
                $rowErrors[] = $label . ' je povinné.';
            } elseif (mb_strlen($value, 'UTF-8') > $max) {
                $rowErrors[] = $label . ': nejvýše ' . $max . ' znaků.';
            }
        }
        if (mb_strlen($first . ' ' . $last, 'UTF-8') > 200) {
            $rowErrors[] = 'Jméno a příjmení včetně mezery mohou mít dohromady nejvýše 200 znaků (název složky).';
        }
        $date = athleteImportBirthDate($birth, $date1904);
        if ($date === null) {
            $rowErrors[] = 'Zadejte platné datum narození (dd.mm.rrrr), nejvýše dnešní datum.';
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $rowErrors[] = 'Neplatná e-mailová adresa.';
        }
        if ($gender !== '' && !isset($genders[$gender])) {
            $rowErrors[] = 'Pohlaví musí být vybráno ze seznamu v šabloně.';
        }
        $rates = [];
        foreach (['Sazba za trénink' => $rate, 'Sazba za párový trénink' => $paired] as $label => $value) {
            $value = str_replace(',', '.', $value);
            if ($value !== '' && !preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D', $value)) {
                $rowErrors[] = $label . ': číslo od 0 do 99999999,99, nejvýše 2 desetinná místa.';
                $rates[] = null;
            } else {
                $rates[] = $value === '' ? null : number_format((float)$value, 2, '.', '');
            }
        }
        foreach ($rowErrors as $error) {
            $errors[] = 'Řádek ' . $number . ': ' . $error;
        }
        if (!$rowErrors) {
            $valid[] = [
                'row' => $number, 'first_name' => $first, 'last_name' => $last, 'birth_date' => $date,
                'gender' => $genders[$gender] ?? 'unknown', 'phone_contact' => $phone !== '' ? $phone : null,
                'email' => $email !== '' ? $email : null, 'training_rate' => $rates[0],
                'paired_training_rate' => $rates[1], 'notes' => $notes !== '' ? $notes : null,
            ];
        }
    }
    return ['rows' => $valid, 'errors' => $errors];
}

function athleteImportPreview(PDO $pdo, int $coachId, array $rows): array {
    $existing = $pdo->prepare('SELECT id FROM athletes WHERE coach_id = ? AND first_name = ? AND last_name = ? AND birth_date = ? LIMIT 1');
    $seen = [];
    foreach ($rows as &$row) {
        $existing->execute([$coachId, $row['first_name'], $row['last_name'], $row['birth_date']]);
        $key = mb_strtolower(json_encode([$row['first_name'], $row['last_name'], $row['birth_date']], JSON_UNESCAPED_UNICODE), 'UTF-8');
        $row['duplicate'] = (bool)$existing->fetchColumn() || isset($seen[$key]);
        $seen[$key] = true;
    }
    unset($row);
    return $rows;
}

function athleteImportSave(PDO $pdo, int $coachId, array $rows): array {
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $engines = $pdo->query("SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('coaches', 'athletes', 'gallery_folders', 'video_folders')")->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach (['coaches', 'athletes', 'gallery_folders', 'video_folders'] as $table) {
            if (strtoupper((string)($engines[$table] ?? '')) !== 'INNODB') {
                throw new RuntimeException('Hromadný import vyžaduje existující tabulku ' . $table . ' s transakčním úložištěm InnoDB.');
            }
        }
    }
    $pdo->beginTransaction();
    try {
        $coach = $pdo->prepare('SELECT id FROM coaches WHERE id = ? FOR UPDATE');
        $coach->execute([$coachId]);
        if (!$coach->fetchColumn()) {
            throw new RuntimeException('Vybraný trenér již neexistuje.');
        }
        $existing = $pdo->prepare('SELECT id FROM athletes WHERE coach_id = ? AND first_name = ? AND last_name = ? AND birth_date = ? LIMIT 1');
        $insert = $pdo->prepare('INSERT INTO athletes (coach_id, first_name, last_name, birth_date, gender, phone_contact, email, training_rate, paired_training_rate, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $gallery = $pdo->prepare("INSERT INTO gallery_folders (coach_id, name, folder_type, athlete_id, sort_order) VALUES (?, ?, 'athlete', ?, 0)");
        $video = $pdo->prepare("INSERT INTO video_folders (coach_id, name, folder_type, athlete_id, sort_order) VALUES (?, ?, 'athlete', ?, 0)");
        $inserted = 0;
        $skipped = 0;
        foreach ($rows as $row) {
            $existing->execute([$coachId, $row['first_name'], $row['last_name'], $row['birth_date']]);
            if ($existing->fetchColumn()) {
                $skipped++;
                continue;
            }
            $insert->execute([$coachId, $row['first_name'], $row['last_name'], $row['birth_date'], $row['gender'], $row['phone_contact'], $row['email'], $row['training_rate'], $row['paired_training_rate'], $row['notes']]);
            $athleteId = (int)$pdo->lastInsertId();
            $name = $row['first_name'] . ' ' . $row['last_name'];
            $gallery->execute([$coachId, $name, $athleteId]);
            $video->execute([$coachId, $name, $athleteId]);
            $inserted++;
        }
        $pdo->commit();
        return ['inserted' => $inserted, 'skipped' => $skipped];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
