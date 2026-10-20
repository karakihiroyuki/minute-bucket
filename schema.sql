-- minute-bucket: one row per (item, minute).
--
-- PRIMARY KEY (item, bucket) is what makes the atomic upsert in record()
-- work. The two are designed as a pair; changing one breaks the other.
--
-- bucket = UNIX timestamp / 60, as an integer.

CREATE TABLE IF NOT EXISTS `minute_bucket` (
    `item`   VARCHAR(191)    NOT NULL,
    `bucket` INT UNSIGNED    NOT NULL,
    `hits`   INT UNSIGNED    NOT NULL DEFAULT 0,
    PRIMARY KEY (`item`, `bucket`),
    KEY `bucket` (`bucket`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- VARCHAR(191) keeps the composite key inside InnoDB's index limit under
-- utf8mb4. If your identifiers are numeric, BIGINT UNSIGNED is smaller and
-- faster -- change the column and the bound parameter type together.
--
-- KEY `bucket` exists for purge(), which deletes by bucket alone and would
-- otherwise scan the table.
