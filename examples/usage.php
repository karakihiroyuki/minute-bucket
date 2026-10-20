<?php

declare(strict_types=1);

require __DIR__ . '/../src/MinuteBucket.php';

use Hozolab\MinuteBucket\MinuteBucket;

/*
 * ATTR_EMULATE_PREPARES must be false.
 *
 * With emulation on, PDO rewrites placeholders as quoted strings, and
 * `LIMIT '10'` is a syntax error. It also lets the same placeholder be
 * bound twice, which hides the fact that the real protocol does not.
 */
$pdo = new PDO(
    'mysql:host=127.0.0.1;dbname=example;charset=utf8mb4',
    'user',
    'password',
    [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);

// Keep 24 hours of history.
$counter = new MinuteBucket($pdo, 'minute_bucket', 86400);


// --- Recording ------------------------------------------------------------
// Call this from a lightweight endpoint, not from page rendering. Anything
// that runs inside a cached page stops running once the cache is warm.

$counter->record('post:1234');


// --- Reading --------------------------------------------------------------

foreach ($counter->top(3600, 10) as $i => $row) {
    printf("%2d. %-16s %d\n", $i + 1, $row['item'], $row['total']);
}

echo 'post:1234 in the last 15 minutes: ', $counter->total('post:1234', 900), "\n";

foreach ($counter->series('post:1234', 600) as $point) {
    printf("%s  %d\n", date('H:i', $point['timestamp']), $point['hits']);
}


// --- Rebuilding a cached ranking without a stampede -----------------------
// When the cache expires, every in-flight request discovers the miss at the
// same moment. Only one of them should pay for the rebuild.

$ranking = $counter->rebuild(
    'ranking_rebuild',
    function () use ($counter, $pdo) {
        $rows = $counter->top(3600, 10);
        // Persist a copy so latecomers have something to fall back to.
        file_put_contents('/tmp/ranking.json', json_encode($rows));
        return $rows;
    },
    function () {
        // Lock held elsewhere: serve the last good result.
        $raw = @file_get_contents('/tmp/ranking.json');
        return $raw === false ? [] : (json_decode($raw, true) ?: []);
    }
);


// --- Retention ------------------------------------------------------------
// Run on a schedule. The ceiling is known in advance:
//
//     max rows = distinct items x (retention seconds / 60)

echo 'ceiling for 5,000 items: ', number_format($counter->maxRows(5000)), " rows\n";
echo 'purged: ', $counter->purge(), " rows\n";
