<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../includes/athlete_import.php';

class AthleteImportTestPDO extends PDO {
    public function prepare(string $query, array $options = []): PDOStatement|false {
        return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options);
    }
}

$checks = 0;
$paths = [];

function importCheck(bool $condition, string $message): void {
    global $checks;
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    $checks++;
}

function importRejects(callable $operation, string $message): void {
    try {
        $operation();
    } catch (RuntimeException $e) {
        importCheck(str_contains($e->getMessage(), $message), 'Expected rejection containing "' . $message . '", got: ' . $e->getMessage());
        return;
    }
    throw new RuntimeException('FAIL: expected rejection: ' . $message);
}

function importTemp(): string {
    global $paths;
    $path = tempnam(sys_get_temp_dir(), 'athlete_import_test_');
    if ($path === false) {
        throw new RuntimeException('Cannot create temporary workbook.');
    }
    $paths[] = $path;
    return $path;
}

function importWorkbook(string $template, array $rows, ?callable $edit = null): string {
    $path = importTemp();
    if (!copy($template, $path)) {
        throw new RuntimeException('Cannot copy workbook.');
    }
    $zip = new ZipArchive();
    importCheck($zip->open($path) === true, 'Open generated workbook');
    $xml = athleteImportReadXml($zip, 'xl/worksheets/sheet1.xml');
    $data = $xml->query('/s:worksheet/s:sheetData')->item(0);
    foreach (iterator_to_array($data->childNodes) as $row) {
        if ($row instanceof DOMElement && (int)$row->getAttribute('r') >= 6) {
            $data->removeChild($row);
        }
    }
    foreach ($rows as $number => $values) {
        $fragment = $data->ownerDocument->createDocumentFragment();
        $fragment->appendXML(athleteImportXmlRow($number, $values));
        $data->appendChild($fragment);
    }
    $zip->addFromString('xl/worksheets/sheet1.xml', $data->ownerDocument->saveXML());
    importCheck($zip->close(), 'Save test rows');
    if ($edit) {
        importCheck($zip->open($path) === true, 'Reopen workbook for format variation');
        $edit($zip);
        importCheck($zip->close(), 'Save workbook format variation');
    }
    return $path;
}

function importTestDB(): PDO {
    $pdo = new AthleteImportTestPDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('CREATE TABLE coaches (id INTEGER PRIMARY KEY)');
    $pdo->exec('INSERT INTO coaches VALUES (7), (8)');
    $pdo->exec("CREATE TABLE athletes (
        id INTEGER PRIMARY KEY AUTOINCREMENT, coach_id INTEGER REFERENCES coaches(id),
        first_name TEXT COLLATE NOCASE, last_name TEXT COLLATE NOCASE, birth_date TEXT,
        gender TEXT, phone_contact TEXT, email TEXT, training_rate TEXT, paired_training_rate TEXT,
        notes TEXT, login_enabled INTEGER NOT NULL DEFAULT 0, password TEXT DEFAULT NULL
    )");
    foreach (['gallery_folders', 'video_folders'] as $table) {
        $pdo->exec("CREATE TABLE $table (id INTEGER PRIMARY KEY, coach_id INTEGER REFERENCES coaches(id), name TEXT, folder_type TEXT, athlete_id INTEGER REFERENCES athletes(id), sort_order INTEGER)");
    }
    return $pdo;
}

try {
    athleteImportRequireExtensions();
    $template = importTemp();
    athleteImportWriteTemplate($template, 7, 'Trenér & Syn <test>');
    $zip = new ZipArchive();
    importCheck($zip->open($template) === true, 'Template is a ZIP archive');
    importCheck($zip->numFiles === 7, 'Template has all required workbook parts');
    $sheet = athleteImportReadXml($zip, 'xl/worksheets/sheet1.xml');
    importCheck($sheet->query('/s:worksheet/s:sheetData/s:row')->length === 1005, 'Template has space for exactly 1000 athletes');
    importCheck($sheet->evaluate('string(/s:worksheet/s:sheetData/s:row[@r="3"]/s:c[@r="B3"]/s:is/s:t)') === 'Trenér & Syn <test>', 'Coach name is XML-escaped and round-trips');
    importCheck($sheet->evaluate('string(/s:worksheet/s:dataValidations/s:dataValidation/@sqref)') === 'D6:D1005', 'Czech gender dropdown covers all rows');
    importCheck(athleteImportReadXml($zip, 'xl/worksheets/sheet2.xml')->query('/s:worksheet/s:sheetData/s:row')->length > 10, 'Czech instructions included');
    $zip->close();
    importRejects(fn() => athleteImportReadWorkbook($template, 7), 'neobsahuje žádné sportovce');

    $athlete = ['Jiří', 'Novák', '15.03.2010', 'Muž', '+420 123 456 789', 'jiri@example.test', '750', '450,50', 'Poznámka & <text>'];
    $filled = importWorkbook($template, [6 => $athlete, 8 => array_fill(0, 9, ''), 10 => ['Eva', 'Malá', '2000-02-29', '', '001234', '', '0', '', '']]);
    $parsed = athleteImportReadWorkbook($filled, 7);
    importCheck($parsed['errors'] === [] && count($parsed['rows']) === 2, 'Filled Czech workbook parses; blank rows ignored');
    $first = $parsed['rows'][0];
    importCheck($first['birth_date'] === '2010-03-15' && $first['gender'] === 'male', 'Czech date and gender normalized');
    importCheck($first['phone_contact'] === '+420 123 456 789' && $parsed['rows'][1]['phone_contact'] === '001234', 'Phone plus and leading zeros preserved');
    importCheck($first['training_rate'] === '750.00' && $first['paired_training_rate'] === '450.50', 'Both rates normalized');
    importCheck($parsed['rows'][1]['paired_training_rate'] === null && $parsed['rows'][1]['training_rate'] === '0.00', 'Blank and zero rates distinguished');
    importCheck($first['notes'] === 'Poznámka & <text>', 'Czech text preserved');
    importRejects(fn() => athleteImportReadWorkbook($filled, 8), 'nepatří vybranému trenérovi');

    importCheck(athleteImportBirthDate('29.02.2024', false) === '2024-02-29', 'Leap date accepted');
    importCheck(athleteImportBirthDate('31.02.2024', false) === null, 'Overflow date rejected');
    importCheck(athleteImportBirthDate('29.02.2023', false) === null, 'Non-leap date rejected');
    importCheck(athleteImportBirthDate('01.01.2999', false) === null, 'Future date rejected');
    importCheck(athleteImportBirthDate('61', false) === '1900-03-01', 'Excel 1900 date conversion');
    importCheck(athleteImportBirthDate('60', false) === null, 'Excel fictitious leap day rejected');
    importCheck(athleteImportBirthDate('0', true) === '1904-01-01', 'Excel 1904 date conversion');
    importCheck(athleteImportBirthDate('', false) === null, 'Missing birth date rejected');
    $invalid = ['', '', '31.02.2020', 'invalid', str_repeat('1', 21), 'invalid-email', '-1', '100000000', ''];
    $result = athleteImportValidateRows([27 => $invalid]);
    importCheck(count($result['errors']) === 8 && !$result['rows'], 'All invalid fields reported together');
    importCheck(str_starts_with($result['errors'][0], 'Řádek 27:'), 'Errors identify original spreadsheet row');
    foreach (['1.001', 'NaN', '1e3', '750 Kč', '1,2,3'] as $invalidRate) {
        $row = $athlete;
        $row[6] = $invalidRate;
        importCheck(count(athleteImportValidateRows([6 => $row])['errors']) === 1, 'Invalid rate rejected: ' . $invalidRate);
    }
    $longName = array_replace($athlete, [0 => str_repeat('a', 100), 1 => str_repeat('b', 100)]);
    importCheck(count(athleteImportValidateRows([6 => $longName])['errors']) === 1, 'Combined name fits 200-character gallery/video folder limit');
    $atLimit = array_fill(6, 1000, $athlete);
    importCheck(count(athleteImportReadWorkbook(importWorkbook($template, $atLimit), 7)['rows']) === 1000, 'Exactly 1000 athletes accepted');
    $atLimit[1006] = $athlete;
    importRejects(fn() => athleteImportReadWorkbook(importWorkbook($template, $atLimit), 7), 'maximálně 1000');

    $formula = importWorkbook($template, [6 => $athlete], static function (ZipArchive $zip): void {
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->addFromString('xl/worksheets/sheet1.xml', str_replace('<c r="A6" s="0" t="inlineStr">', '<c r="A6" s="0" t="inlineStr"><f>1+1</f>', $xml));
    });
    importRejects(fn() => athleteImportReadWorkbook($formula, 7), 'vzorce nejsou povoleny');
    $changed = importWorkbook($template, [6 => $athlete], static function (ZipArchive $zip): void {
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->addFromString('xl/worksheets/sheet1.xml', str_replace('Jméno *', 'Změněná hlavička', $xml));
    });
    importRejects(fn() => athleteImportReadWorkbook($changed, 7), 'změněnou hlavičku');
    $shared = importWorkbook($template, [6 => $athlete], static function (ZipArchive $zip): void {
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $xml = str_replace('<c r="A6" s="0" t="inlineStr"><is><t xml:space="preserve">Jiří</t></is></c>', '<c r="A6" t="s"><v>0</v></c>', $xml);
        $zip->addFromString('xl/worksheets/athletes.xml', $xml);
        $zip->deleteName('xl/worksheets/sheet1.xml');
        $zip->addFromString('xl/_rels/workbook.xml.rels', str_replace('worksheets/sheet1.xml', '/xl/worksheets/athletes.xml', $zip->getFromName('xl/_rels/workbook.xml.rels')));
        $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0" encoding="UTF-8"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><r><t>Ji</t></r><r><t>ří</t></r></si></sst>');
    });
    importCheck(athleteImportReadWorkbook($shared, 7)['rows'][0]['first_name'] === 'Jiří', 'Shared/rich strings and absolute worksheet relationship supported');
    $numericDate = importWorkbook($template, [6 => array_replace($athlete, [2 => '0'])], static function (ZipArchive $zip): void {
        $zip->addFromString('xl/workbook.xml', str_replace('<sheets>', '<workbookPr date1904="1"/><sheets>', $zip->getFromName('xl/workbook.xml')));
    });
    importCheck(athleteImportReadWorkbook($numericDate, 7)['rows'][0]['birth_date'] === '1904-01-01', 'Workbook date system respected');
    $malformed = importTemp();
    file_put_contents($malformed, 'not a zip');
    importRejects(fn() => athleteImportReadWorkbook($malformed, 7), 'není platný Excel');
    $large = importTemp();
    $handle = fopen($large, 'wb');
    ftruncate($handle, ATHLETE_IMPORT_MAX_BYTES + 1);
    fclose($handle);
    importRejects(fn() => athleteImportReadWorkbook($large, 7), 'příliš velký');
    $entity = importWorkbook($template, [6 => $athlete], static function (ZipArchive $zip): void {
        $zip->addFromString('xl/sharedStrings.xml', '<!DOCTYPE sst [<!ENTITY test "value">]><sst/>');
    });
    importRejects(fn() => athleteImportReadWorkbook($entity, 7), 'nepovolené XML');

    $pdo = importTestDB();
    $rows = $parsed['rows'];
    $preview = athleteImportPreview($pdo, 7, [$rows[0], $rows[0], $rows[1]]);
    importCheck(!$preview[0]['duplicate'] && $preview[1]['duplicate'] && !$preview[2]['duplicate'], 'Duplicates within workbook marked in preview');
    $saved = athleteImportSave($pdo, 7, $preview);
    importCheck($saved === ['inserted' => 2, 'skipped' => 1], 'Import inserts only unique athletes');
    importCheck((int)$pdo->query('SELECT COUNT(*) FROM gallery_folders')->fetchColumn() === 2, 'Gallery folders created');
    importCheck((int)$pdo->query('SELECT COUNT(*) FROM video_folders')->fetchColumn() === 2, 'Video folders created');
    importCheck((int)$pdo->query("SELECT COUNT(*) FROM athletes WHERE coach_id = 7 AND login_enabled = 0 AND password IS NULL")->fetchColumn() === 2, 'Coach assigned; login remains disabled');
    importCheck($pdo->query('SELECT phone_contact FROM athletes ORDER BY id DESC LIMIT 1')->fetchColumn() === '001234', 'Phone leading zeros survive database insertion');
    importCheck(athleteImportSave($pdo, 7, $rows) === ['inserted' => 0, 'skipped' => 2], 'Repeated upload does not create duplicates');
    $modified = $rows[0];
    $modified['email'] = 'changed@example.test';
    importCheck(athleteImportPreview($pdo, 7, [$modified])[0]['duplicate'], 'Existing athlete marked in preview');
    athleteImportSave($pdo, 7, [$modified]);
    importCheck($pdo->query('SELECT email FROM athletes ORDER BY id LIMIT 1')->fetchColumn() === 'jiri@example.test', 'Existing data never overwritten');
    importCheck(athleteImportSave($pdo, 8, $rows)['inserted'] === 2, 'Same athlete may be imported for a different coach');
    $pdo = importTestDB();
    $pdo->exec("CREATE TRIGGER fail_video BEFORE INSERT ON video_folders WHEN NEW.name LIKE 'Eva%' BEGIN SELECT RAISE(ABORT, 'test folder failure'); END");
    importRejects(fn() => athleteImportSave($pdo, 7, $rows), 'test folder failure');
    foreach (['athletes', 'gallery_folders', 'video_folders'] as $table) {
        importCheck((int)$pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn() === 0, 'Whole import rolled back after folder failure: ' . $table);
    }
    importCheck(!$pdo->inTransaction(), 'Failed import closes transaction');
    importRejects(fn() => athleteImportSave($pdo, 999, $rows), 'trenér již neexistuje');
    echo 'PASS: ' . $checks . ' athlete import checks (XLSX, validation, limits, duplicates, persistence, rollback).' . PHP_EOL;
} finally {
    foreach ($paths as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
}
