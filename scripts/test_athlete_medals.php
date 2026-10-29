<?php
require_once __DIR__ . '/../includes/medals.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/trainerapp');
}

function medalTestAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$definitions = athleteMedalDefinitions();
medalTestAssert(count($definitions) === 21, 'The medal sets must contain 21 medals.');
medalTestAssert(count(array_unique(array_column($definitions, 'id'))) === 21, 'Medal identifiers must be unique.');

$assetDirectory = __DIR__ . '/../assets/medals/';
foreach ($definitions as $definition) {
    medalTestAssert(
        is_file($assetDirectory . $definition['image']),
        'Missing medal image: ' . $definition['image']
    );
    $imageUrl = athleteMedalImageUrl($definition['image']);
    medalTestAssert(
        $imageUrl === BASE_URL . '/assets/medals/' . rawurlencode($definition['image'])
            . '?v=' . hash_file('sha256', $assetDirectory . $definition['image']),
        'Medal image URLs must include a content-based cache version.'
    );
    medalTestAssert(
        athleteMedalImageUrl($definition['image']) === $imageUrl,
        'Unchanged medal images should retain a stable cache version.'
    );
}

medalTestAssert(
    athleteMedalStreakCompletionDate(
        ['2026-02-26', '2026-02-27', '2026-02-28', '2026-03-01', '2026-03-02', '2026-03-03', '2026-03-04'],
        7
    ) === '2026-03-04',
    'A seven-day streak should span month boundaries.'
);
medalTestAssert(
    athleteMedalStreakCompletionDate(
        ['2026-01-01', '2026-01-02', '2026-01-02', '2026-01-04', '2026-01-05'],
        3
    ) === null,
    'Duplicate dates must not fill a missing day in a streak.'
);
medalTestAssert(
    athleteMedalStreakCompletionDate(['2026-02-30', '2026-03-01'], 2) === null,
    'Invalid dates must not count toward a streak.'
);

$eventDates = [];
for ($index = 1; $index <= 1000; $index++) {
    $eventDates[] = sprintf('2026-%02d-%02d', intdiv($index - 1, 28) % 12 + 1, ($index - 1) % 28 + 1);
}
$thresholds = athleteMedalThresholdDates($eventDates, [1, 10, 50, 100, 200, 500, 1000]);
medalTestAssert(count($thresholds) === 7, 'Every offline training milestone should resolve.');
medalTestAssert($thresholds[1] === $eventDates[0], 'The first training award date should use the first completed session.');
medalTestAssert($thresholds[10] === $eventDates[9], 'The 10-training award date should use the 10th completed session.');
medalTestAssert($thresholds[1000] === $eventDates[999], 'The 1000-training award date should use the 1000th completed session.');
medalTestAssert(
    athleteMedalThresholdDates(array_slice($eventDates, 0, 9), [10]) === [],
    'An unmet milestone must remain locked.'
);

$populationCounts = athleteMedalPopulationCounts([
    'training' => [1 => 1000, 2 => 10, 3 => 9],
    'online' => [1 => 1, 2 => 50],
    'weight' => [1 => 50, 2 => 1],
    'health' => [1 => 1, 2 => 1, 3 => 1],
    'food_meal' => [1 => 1, 2 => 0],
    'food_drink' => [1 => 0, 2 => 1],
    'food_full_day' => [1 => 1, 2 => 0],
    'food_streak' => [1 => 0, 2 => 1],
]);
medalTestAssert($populationCounts['first_training'] === 3, 'First training count should include every athlete with at least one completed session.');
medalTestAssert($populationCounts['training_10'] === 2, 'The 10-training count should exclude athletes below the threshold.');
medalTestAssert($populationCounts['training_1000'] === 1, 'The 1000-training count should be exact.');
medalTestAssert($populationCounts['health_questionnaire'] === 3, 'Health awards should count athletes with at least one submitted questionnaire.');
medalTestAssert($populationCounts['strava_first_meal'] === 1, 'Strava meal awards should count athletes with a logged meal.');
medalTestAssert($populationCounts['strava_first_drink'] === 1, 'Strava drink awards should count athletes with a logged drink.');
medalTestAssert($populationCounts['strava_full_day'] === 1, 'Strava full-day awards should count complete daily records.');
medalTestAssert($populationCounts['strava_7_day_streak'] === 1, 'Strava streak awards should count athletes with a seven-day streak.');
$populationHolders = athleteMedalPopulationHolders(
    ['training' => [1 => 10, 2 => 9]],
    [
        1 => ['name' => 'Eva Sportovcová', 'coach_name' => 'Trenér Jedna'],
        2 => ['name' => 'Jan Sportovec', 'coach_name' => 'Trenér Dva'],
    ]
);
medalTestAssert(count($populationHolders['training_10']) === 1, 'Holder lists should match medal thresholds.');
medalTestAssert($populationHolders['training_10'][0]['name'] === 'Eva Sportovcová', 'Holder lists should preserve athlete names.');
medalTestAssert($populationHolders['training_10'][0]['coach_name'] === 'Trenér Jedna', 'Holder lists should include the coach name.');

echo "Athlete medal tests passed.\n";
