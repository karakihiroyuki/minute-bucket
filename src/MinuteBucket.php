<?php

declare(strict_types=1);

namespace Hozolab\MinuteBucket;

use InvalidArgumentException;
use PDO;

/**
 * Minute-bucketed counting on MySQL/MariaDB.
 *
 * One row per (item, minute). Counting is a single atomic statement,
 * aggregation is a SUM over a bucket range, and retention is bounded
 * by design rather than by hope.
 */
final class MinuteBucket
{
    public const BUCKET_SECONDS = 60;

    /** MySQL caps lock names at 64 characters since 5.7. */
    private const MAX_LOCK_NAME = 64;

    private PDO $pdo;
    private string $table;
    private int $retentionSeconds;

    /** @var callable():int */
    private $clock;

    /**
     * @param string        $table            Identifier only; never interpolated from user input.
     * @param int           $retentionSeconds How far back rows are kept. Decides the table's size ceiling.
     * @param callable|null $clock            Injectable clock, so time-dependent behaviour is testable.
     */
    public function __construct(
        PDO $pdo,
        string $table = 'minute_bucket',
        int $retentionSeconds = 86400,
        ?callable $clock = null
    ) {
        if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,62}\z/', $table) !== 1) {
            throw new InvalidArgumentException("Invalid table name: {$table}");
        }
        if ($retentionSeconds < self::BUCKET_SECONDS) {
            throw new InvalidArgumentException('retentionSeconds must be >= ' . self::BUCKET_SECONDS);
        }

        $this->pdo              = $pdo;
        $this->table            = $table;
        $this->retentionSeconds = $retentionSeconds;
        $this->clock            = $clock ?? static fn (): int => time();
    }

    public function now(): int
    {
        return ($this->clock)();
    }

    public function bucketAt(int $timestamp): int
    {
        return intdiv($timestamp, self::BUCKET_SECONDS);
    }

    public function currentBucket(): int
    {
        return $this->bucketAt($this->now());
    }

    /**
     * Record hits for an item in the current minute.
     *
     * Insert-or-add in one statement. No read precedes the write, so there is
     * no window for a concurrent request to slip between the two.
     *
     * `:inc` is bound separately from `:hits` on purpose: VALUES() inside
     * ON DUPLICATE KEY UPDATE is deprecated as of MySQL 8.0.20, and PDO with
     * emulation disabled will not let one placeholder be bound twice.
     */
    public function record(string $item, int $count = 1): void
    {
        if ($count < 1) {
            throw new InvalidArgumentException('count must be >= 1');
        }

        $sql = "INSERT INTO `{$this->table}` (item, bucket, hits)
                     VALUES (:item, :bucket, :hits)
                ON DUPLICATE KEY UPDATE hits = hits + :inc";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':item', $item, PDO::PARAM_STR);
        $stmt->bindValue(':bucket', $this->currentBucket(), PDO::PARAM_INT);
        $stmt->bindValue(':hits', $count, PDO::PARAM_INT);
        $stmt->bindValue(':inc', $count, PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * Highest-scoring items over the trailing window.
     *
     * @return array<int, array{item: string, total: int}>
     */
    public function top(int $windowSeconds, int $limit = 10): array
    {
        $sql = "SELECT item, SUM(hits) AS total
                  FROM `{$this->table}`
                 WHERE bucket >= :since
              GROUP BY item
              ORDER BY total DESC, item ASC
                 LIMIT :lim";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':since', $this->sinceBucket($windowSeconds), PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = ['item' => (string) $row['item'], 'total' => (int) $row['total']];
        }

        return $out;
    }

    public function total(string $item, int $windowSeconds): int
    {
        $sql = "SELECT COALESCE(SUM(hits), 0)
                  FROM `{$this->table}`
                 WHERE item = :item AND bucket >= :since";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':item', $item, PDO::PARAM_STR);
        $stmt->bindValue(':since', $this->sinceBucket($windowSeconds), PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * Per-minute timeline for one item, oldest first. Empty minutes are filled
     * with zero, so the result is directly plottable.
     *
     * @return array<int, array{bucket: int, timestamp: int, hits: int}>
     */
    public function series(string $item, int $windowSeconds): array
    {
        $since = $this->sinceBucket($windowSeconds);
        $until = $this->currentBucket();

        $sql = "SELECT bucket, hits
                  FROM `{$this->table}`
                 WHERE item = :item AND bucket >= :since
              ORDER BY bucket ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':item', $item, PDO::PARAM_STR);
        $stmt->bindValue(':since', $since, PDO::PARAM_INT);
        $stmt->execute();

        $found = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $found[(int) $row['bucket']] = (int) $row['hits'];
        }

        $out = [];
        for ($b = $since; $b <= $until; $b++) {
            $out[] = [
                'bucket'    => $b,
                'timestamp' => $b * self::BUCKET_SECONDS,
                'hits'      => $found[$b] ?? 0,
            ];
        }

        return $out;
    }

    /**
     * Drop buckets older than the retention window. Returns rows removed.
     *
     * Run this on a schedule. Without it the table has no ceiling.
     */
    public function purge(): int
    {
        $cutoff = $this->bucketAt($this->now() - $this->retentionSeconds);

        $stmt = $this->pdo->prepare("DELETE FROM `{$this->table}` WHERE bucket < :cutoff");
        $stmt->bindValue(':cutoff', $cutoff, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount();
    }

    /**
     * Upper bound on stored rows, given a number of distinct items.
     *
     * Being able to state this before deployment is the point of bucketing.
     */
    public function maxRows(int $distinctItems): int
    {
        return $distinctItems * intdiv($this->retentionSeconds, self::BUCKET_SECONDS);
    }

    /**
     * Run $build under a named lock, so that only one process rebuilds a cached
     * result at a time.
     *
     * $waitSeconds is 0 by default: a caller that cannot get the lock gives up
     * immediately and falls back. Queueing here would convert a thundering herd
     * into a traffic jam, which is not an improvement.
     *
     * @template T
     * @param  callable():T      $build
     * @param  (callable():T)|null $fallback Used when the lock is held elsewhere.
     * @return T|null
     */
    public function rebuild(string $lockName, callable $build, ?callable $fallback = null, int $waitSeconds = 0)
    {
        if ($lockName === '' || strlen($lockName) > self::MAX_LOCK_NAME) {
            throw new InvalidArgumentException('lockName must be 1-' . self::MAX_LOCK_NAME . ' bytes');
        }
        if ($waitSeconds < 0) {
            throw new InvalidArgumentException('waitSeconds must be >= 0');
        }

        $stmt = $this->pdo->prepare('SELECT GET_LOCK(:name, :wait)');
        $stmt->bindValue(':name', $lockName, PDO::PARAM_STR);
        $stmt->bindValue(':wait', $waitSeconds, PDO::PARAM_INT);
        $stmt->execute();

        if ((int) $stmt->fetchColumn() !== 1) {
            return $fallback === null ? null : $fallback();
        }

        try {
            return $build();
        } finally {
            // In finally, not after the call: one thrown exception must not
            // leave the lock held for the rest of the connection's life.
            $release = $this->pdo->prepare('SELECT RELEASE_LOCK(:name)');
            $release->bindValue(':name', $lockName, PDO::PARAM_STR);
            $release->execute();
        }
    }

    private function sinceBucket(int $windowSeconds): int
    {
        if ($windowSeconds < self::BUCKET_SECONDS) {
            throw new InvalidArgumentException('windowSeconds must be >= ' . self::BUCKET_SECONDS);
        }

        return $this->bucketAt($this->now() - $windowSeconds);
    }
}
