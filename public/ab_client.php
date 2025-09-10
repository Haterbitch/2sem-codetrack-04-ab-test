<?php
/**
 * ab_client.php — minimal AB testing helper + lightweight tracker (SQLite)
 * Usage in a page:
 *   require __DIR__ . '/ab_client.php';
 *   $v = ab_variant('cta_test', 'CTA button test', ['A' => 50, 'B' => 50]); // returns 'A' or 'B'
 *   // Render variant A or B...
 *   // Track a goal later:
 *   //   ab_track_goal('cta_test');             // server-side (e.g., after form success)
 *   // or from JS:
 *   //   fetch('/ab_client.php?goal=1&exp=cta_test'); // client-side (e.g., on CTA click)
 */

//// Config ////
const AB_DB_PATH = __DIR__ . '/../database.sqlite';
const AB_COOKIE_NAME = 'ab_uid';
const AB_COOKIE_DAYS = 365;
const AB_GOAL_NAME = 'goal'; // event name; keep single event for simplicity

//// Bootstrap ////
$pdo = ab_db();
ab_migrate($pdo);
$uid = ab_uid();

// Handle client-side goal ping: /ab_client.php?goal=1&exp=key
if (isset($_GET['goal'], $_GET['exp'])) {
    $ok = ab_track_goal($_GET['exp']);
    header('Content-Type: application/json');
    echo json_encode(['ok' => $ok]);
    exit;
}

//// Public API functions ////

/**
 * Assigns (or reuses) a variant for the given experiment key, returns its key (e.g. 'A', 'B').
 * If the experiment/variants don’t exist yet, they are created with given weights.
 *
 * @param string $expKey short machine key (e.g., "cta_test")
 * @param string|null $expName human name (optional)
 * @param array $weights ['A'=>50,'B'=>50] or any number of variants/weights
 * @return string variant key
 */
function ab_variant(string $expKey, ?string $expName = null, array $weights = ['A' => 50, 'B' => 50]): string
{
    global $pdo, $uid;

    $expId = ab_get_or_create_experiment($pdo, $expKey, $expName);
    $existing = ab_get_existing_assignment($pdo, $expId, $uid);
    if ($existing) {
        return $existing['variant_key'];
    }

    // Ensure variants exist & fetch with weights
    ab_ensure_variants($pdo, $expId, $weights);
    $variants = ab_get_variants($pdo, $expId); // [ [id, key, weight], ... ]

    // Weighted random pick
    $picked = ab_weighted_pick($variants);

    // Save assignment
    $stmt = $pdo->prepare("INSERT INTO `assignments` (`experiment_id`, `variant_id`, `user_token`) VALUES (?, ?, ?)");
    $stmt->execute([$expId, $picked['id'], $uid]);

    return $picked['key'];
}

/**
 * Track a goal for current user on an experiment (counts first time only).
 * @param string $expKey
 * @return bool
 */
function ab_track_goal(string $expKey): bool
{
    global $pdo, $uid;

    $exp = ab_fetch_one($pdo, "SELECT `id` FROM `experiments` WHERE KEY = ?", [$expKey]);
    if (!$exp) {
        return false;
    }

    // Find the user’s assigned variant for this experiment
    $row = ab_fetch_one(
        $pdo,
        "
    SELECT `v`.`id` AS `variant_id`
    FROM `assignments` `a`
    JOIN `variants` `v` ON `v`.`id`=`a`.`variant_id`
    WHERE `a`.`experiment_id`=? AND `a`.`user_token`=?
    LIMIT 1
  ",
        [$exp['id'], $uid]
    );
    if (!$row) {
        return false;
    }

    // Only count first goal per user per experiment
    $exists = ab_fetch_one(
        $pdo,
        "
    SELECT 1 FROM `events`
    WHERE `experiment_id`=? AND `user_token`=? AND `event`=?
    LIMIT 1
  ",
        [$exp['id'], $uid, AB_GOAL_NAME]
    );
    if ($exists) {
        return true;
    }

    $stmt = $pdo->prepare("INSERT INTO events (experiment_id, variant_id, user_token, event) VALUES (?, ?, ?, ?)");
    $stmt->execute([$exp['id'], $row['variant_id'], $uid, AB_GOAL_NAME]);
    return true;
}

//// Internals ////

function ab_db(): PDO
{
    $pdo = new PDO('sqlite:' . AB_DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
}

function ab_migrate(PDO $pdo): void
{
    $pdo->exec(
        "
    CREATE TABLE IF NOT EXISTS `experiments`(
      `id` integer PRIMARY KEY AUTOINCREMENT,
      KEY `TEXT` UNIQUE NOT NULL,
      `name` text
    );
    CREATE TABLE IF NOT EXISTS `variants`(
      `id` integer PRIMARY KEY AUTOINCREMENT,
      `experiment_id` integer NOT NULL,
      KEY `TEXT` NOT NULL,
      `name` text,
      `weight` integer NOT NULL DEFAULT 1,
      UNIQUE(`experiment_id`, `key`)
    );
    CREATE TABLE IF NOT EXISTS `assignments`(
      `id` integer PRIMARY KEY AUTOINCREMENT,
      `experiment_id` integer NOT NULL,
      `variant_id` integer NOT NULL,
      `user_token` text NOT NULL,
      `assigned_at` datetime DEFAULT CURRENT_TIMESTAMP,
      UNIQUE(`experiment_id`, `user_token`)
    );
    CREATE TABLE IF NOT EXISTS `events`(
      `id` integer PRIMARY KEY AUTOINCREMENT,
      `experiment_id` integer NOT NULL,
      `variant_id` integer NOT NULL,
      `user_token` text NOT NULL,
      `event` text NOT NULL,
      `created_at` datetime DEFAULT CURRENT_TIMESTAMP
    );
  "
    );
}

function ab_uid(): string
{
    $uid = $_COOKIE[AB_COOKIE_NAME] ?? '';
    if (!$uid) {
        $uid = bin2hex(random_bytes(16));
        setcookie(AB_COOKIE_NAME, $uid, [
            'expires' => time() + (AB_COOKIE_DAYS * 24 * 60 * 60),
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
        $_COOKIE[AB_COOKIE_NAME] = $uid;
    }
    return $uid;
}

function ab_get_or_create_experiment(PDO $pdo, string $key, ?string $name): int
{
    $row = ab_fetch_one($pdo, "SELECT `id` FROM `experiments` WHERE KEY=?", [$key]);
    if ($row) {
        return (int)$row['id'];
    }
    $stmt = $pdo->prepare("INSERT INTO experiments(key,name) VALUES(?,?)");
    $stmt->execute([$key, $name]);
    return (int)$pdo->lastInsertId();
}

function ab_ensure_variants(PDO $pdo, int $expId, array $weights): void
{
    foreach ($weights as $k => $w) {
        $row = ab_fetch_one($pdo, "SELECT `id` FROM `variants` WHERE `experiment_id`=? AND KEY=?", [$expId, (string)$k]
        );
        if (!$row) {
            $stmt = $pdo->prepare("INSERT INTO variants(experiment_id, key, name, weight) VALUES(?,?,?,?)");
            $stmt->execute([$expId, (string)$k, (string)$k, (int)$w]);
        } else {
            $stmt = $pdo->prepare("UPDATE variants SET weight=? WHERE id=?");
            $stmt->execute([(int)$w, $row['id']]);
        }
    }
}

function ab_get_variants(PDO $pdo, int $expId): array
{
    $stmt = $pdo->prepare("SELECT id, key, weight FROM variants WHERE experiment_id=? ORDER BY key");
    $stmt->execute([$expId]);
    return $stmt->fetchAll();
}

function ab_get_existing_assignment(PDO $pdo, int $expId, string $uid): ?array
{
    $row = ab_fetch_one(
        $pdo,
        "
    SELECT v.key AS variant_key
    FROM assignments a
    JOIN variants v ON v.id=a.variant_id
    WHERE a.experiment_id=? AND a.user_token=?
    LIMIT 1
  ",
        [$expId, $uid]
    );
    return $row ?: null;
}

function ab_weighted_pick(array $variants): array
{
    $total = array_sum(array_column($variants, 'weight'));
    if ($total <= 0) {
        $total = count($variants);
    }
    $r = random_int(1, $total);
    $acc = 0;
    foreach ($variants as $v) {
        $acc += max(1, (int)$v['weight']);
        if ($r <= $acc) {
            return $v;
        }
    }
    return $variants[array_key_first($variants)];
}

function ab_fetch_one(PDO $pdo, string $sql, array $params = []): ?array
{
    $stmt = $pdo->prepare($sql); $stmt->execute($params);
    $row = $stmt->fetch();
    return $row ?: null;
}
