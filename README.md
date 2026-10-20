# minute-bucket

Minute-bucketed hit counting on MySQL. One row per `(item, minute)`, counted
with a single atomic statement, aggregated with `SUM`, and bounded by a
retention window you choose up front.

```php
$counter = new MinuteBucket($pdo, 'minute_bucket', 86400);

$counter->record('post:1234');              // one atomic statement
$counter->top(3600, 10);                    // trailing hour, top 10
$counter->total('post:1234', 900);          // trailing 15 minutes
$counter->series('post:1234', 600);         // per-minute timeline, zero-filled
$counter->purge();                          // drop what fell out of retention
```

## Why

A "most popular right now" list is trivial to write and surprisingly easy to
get wrong. Three failures only appear in production, and none of them look
like a failure.

**The counter stops when caching starts.** If the increment runs while the
page is rendered, it runs once and then never again: subsequent visitors are
served from cache and your PHP is not executed. Nothing errors. The numbers
are simply no longer related to reality. Counting has to happen on a path the
cache does not serve.

**Read-then-write loses hits under load.** `SELECT`, add one, `UPDATE` leaves a
window in which another request reads the same value. Two visits, one
increment. At low traffic you will never see it; at high traffic it is
permanent and invisible. An insert-or-add in one statement has no such window:

```sql
INSERT INTO minute_bucket (item, bucket, hits)
     VALUES (:item, :bucket, :hits)
ON DUPLICATE KEY UPDATE hits = hits + :inc
```

**A row per hit has no ceiling.** Bucketing by minute collapses an unbounded
event log into `items x minutes`, and a retention window turns that into an
arithmetic fact you can state before you deploy:

```
max rows = distinct items x (retention seconds / 60)
```

`maxRows()` returns exactly that. Being able to answer "how big will this be in
six months" during design, rather than during an incident, is the point.

## Cache stampedes

Caching the aggregate introduces a fourth failure: when the entry expires,
every in-flight request misses at the same instant and they all run the same
expensive `GROUP BY`. Load spikes precisely when the cache was supposed to
help.

`rebuild()` puts a named lock in front of the recomputation.

```php
$ranking = $counter->rebuild(
    'ranking_rebuild',
    fn () => $counter->top(3600, 10),   // one process pays
    fn () => $lastKnownGood              // the rest are served immediately
);
```

Three decisions are deliberate:

- **The lock is not waited on.** `GET_LOCK(name, 0)`. Blocking would turn a
  thundering herd into a queue, which is not an improvement.
- **Losers return stale data.** A ranking that is one minute old is almost
  never a problem. A ranking that takes four seconds to load always is.
- **The release is in `finally`.** If the rebuild throws and the lock is never
  released, no process can rebuild again for the life of that connection. This
  is the kind of bug that looks like "the cache stopped updating".

## Install

```sh
composer require karakihiroyuki/minute-bucket
```

Or drop `src/MinuteBucket.php` in — it has no dependencies beyond PDO.

Create the table from [`schema.sql`](schema.sql). `PRIMARY KEY (item, bucket)`
is not decoration: it is the conflict target the atomic upsert relies on.

### PDO configuration

```php
$pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE          => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
```

Emulation must be off. With it on, `LIMIT :lim` is rewritten as `LIMIT '10'`
and fails. Turning it off also surfaces the real protocol rule that one
placeholder cannot be bound twice — which is why `record()` binds `:hits` and
`:inc` separately rather than using `VALUES()`, deprecated since MySQL 8.0.20.

## Requirements

PHP 8.0+, PDO, MySQL 5.7+ or MariaDB 10.2+.

## Limitations

- **Hits, not unique visitors.** One person refreshing ten times counts ten.
  Deduplication is a separate concern and deliberately out of scope.
- **Window edges are approximate.** Buckets are floored to the minute, so a
  "trailing hour" is accurate to within 59 seconds. Fine for ranking; not for
  anything you bill on.
- **`GET_LOCK` is MySQL-specific.** Everything else is ordinary SQL; `rebuild()`
  is the part that would need replacing elsewhere.
- **No bot filtering.** Whatever reaches `record()` is counted.
- **`purge()` deletes in one statement.** On a table that has gone years without
  retention, delete in batches first.

## License

MIT. See [LICENSE](LICENSE).

---

# minute-bucket（日本語）

MySQL上で、**分単位のバケット**によるアクセス集計を行うための小さなライブラリです。
1行 = `(対象, 分)`。計数は1文で原子的に、集計は `SUM` で、保持期間は設計時に決めます。

## なぜ「分単位バケット」なのか

「いま読まれている順」のランキングは、書くだけなら簡単です。**本番でだけ壊れる箇所が3つあり、どれも壊れたように見えません。**

**キャッシュが効くと、計数が止まる**

ページの描画中に加算していると、キャッシュに載った時点でPHPが実行されなくなります。エラーは出ません。数字も表示されます。**実態と合わなくなるだけです。** 計測は、キャッシュが返さない経路に置く必要があります。

**読んでから書くと、同時アクセスでずれる**

`SELECT` して +1 して `UPDATE` する間に、別のリクエストが同じ値を読みます。2アクセスで +1。**アクセスが少ないうちは再現せず、増えたときだけ恒常的にずれます。** 挿入と加算を1文に畳めば、割り込まれる隙間自体が無くなります。

**1アクセス1行では、上限が無い**

分単位でまとめると、行数は `対象数 × 分数` に収まります。保持期間を決めれば、テーブルサイズは**設計時に計算できる値**になります。

```
最大行数 = 対象数 × (保持秒数 / 60)
```

`maxRows()` がこの値を返します。「半年後にどうなるか」を障害対応中ではなく設計中に答えられること、それがバケット化の目的です。

## キャッシュ失効時の集中（cache stampede）

集計結果をキャッシュすると、4つ目の問題が出ます。**失効した瞬間に、処理中の全リクエストが同時に「無い」と判断し、全部が重い集計を投げます。** キャッシュが助けるはずの場面で、逆に負荷が跳ねます。

`rebuild()` は、再計算の前に名前付きロックを置きます。設計上の判断が3つあります。

- **ロックを待たない。** `GET_LOCK(name, 0)`。待たせると、集中が渋滞に変わるだけです
- **取れなかった側は古い値を返す。** 1分古いランキングで困ることはまずありません。4秒待たされるランキングは必ず困ります
- **解放は `finally` に置く。** 再計算が例外を投げてロックが残ると、**以後その接続では誰も再計算できなくなります。** 「キャッシュが更新されなくなった」という形で表面化する種類の不具合です

## 導入

`src/MinuteBucket.php` を置くだけでも動きます（PDO以外の依存はありません）。
テーブルは [`schema.sql`](schema.sql) から作成してください。
`PRIMARY KEY (item, bucket)` は飾りではなく、**原子的な upsert の衝突判定キー**です。

PDOは `ATTR_EMULATE_PREPARES => false` が必須です。エミュレーションが有効だと `LIMIT :lim` が `LIMIT '10'` に書き換えられて構文エラーになります。

## 制限

- 数えるのは**アクセス回数**で、ユニーク数ではありません
- バケットは分で切っているため、集計期間の端は最大59秒ずれます。**課金や契約に使う数字には向きません**
- `GET_LOCK` はMySQL/MariaDB依存です
- ボットの除外は行いません
- `purge()` は1文で削除します。長期間パージしていないテーブルでは、先に分割削除してください

## ライセンス

MIT
