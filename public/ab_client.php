<?php

declare(strict_types=1);

/**
 * A/B Testing Library - Minimal AB testing helper with SQLite tracking
 *
 * Usage:
 *   require __DIR__ . '/ab_client.php';
 *   $variant = ab_variant('cta_test', 'CTA button test', ['A' => 50, 'B' => 50]);
 *
 *   // Track goals:
 *   ab_track_goal('cta_test'); // server-side
 *   // or client-side: fetch('/ab_client.php?goal=1&experiment=cta_test')
 */

// Configuration constants
const AB_DB_PATH = __DIR__ . '/../ab_tests.sqlite';
const AB_COOKIE_NAME = 'ab_uid';
const AB_COOKIE_DAYS = 365;
const AB_GOAL_NAME = 'goal';

// Initialize database and user
$database = createDatabase();
migrateDatabase($database);
$userId = getUserId();

// Handle AJAX goal tracking requests
if (isset($_GET['goal'], $_GET['experiment'])) {
    $success = ab_track_goal($_GET['experiment']);

    header('Content-Type: application/json');
    echo json_encode(['success' => $success]);
    exit;
}

/**
 * Assigns or retrieves a variant for the given experiment
 *
 * @param string $experimentKey Unique experiment identifier
 * @param string|null $experimentName Human-readable experiment name
 * @param array<string, int> $weights Variant weights (e.g., ['A' => 50, 'B' => 50])
 * @return string The assigned variant key
 */
function ab_variant(
    string $experimentKey,
    ?string $experimentName = null,
    array $weights = ['A' => 50, 'B' => 50],
): string {
    global $database, $userId;

    $experimentId = getOrCreateExperiment($database, $experimentKey, $experimentName);

    // Check for existing assignment
    $existingAssignment = getExistingAssignment($database, $experimentId, $userId);
    if ($existingAssignment !== null) {
        return $existingAssignment['variant_key'];
    }

    // Create variants and get available options
    ensureVariantsExist($database, $experimentId, $weights);
    $variants = getVariants($database, $experimentId);

    // Select variant using weighted random selection
    $selectedVariant = selectWeightedVariant($variants);

    // Save the assignment
    saveAssignment($database, $experimentId, $selectedVariant['id'], $userId);

    return $selectedVariant['variant_key'];
}

/**
 * Track a goal conversion for the current user and experiment
 *
 * @param string $experimentKey The experiment identifier
 * @return bool True if goal was tracked (or already existed)
 */
function ab_track_goal(string $experimentKey): bool
{
    global $database, $userId;

    $experiment = findExperiment($database, $experimentKey);
    if ($experiment === null) {
        return false;
    }

    $variantAssignment = findUserVariant($database, $experiment['id'], $userId);
    if ($variantAssignment === null) {
        return false;
    }

    // Only track first goal per user per experiment
    if (goalAlreadyExists($database, $experiment['id'], $userId)) {
        return true;
    }

    return saveGoalEvent($database, $experiment['id'], $variantAssignment['id'], $userId);
}

// Database and utility functions

function createDatabase(): PDO
{
    $pdo = new PDO('sqlite:' . AB_DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
}

function migrateDatabase(PDO $pdo): void
{
    $queries = [
        "CREATE TABLE IF NOT EXISTS `experiments` (
            `id` INTEGER PRIMARY KEY AUTOINCREMENT,
            `experiment_key` TEXT UNIQUE NOT NULL,
            `name` TEXT
        )",
        "CREATE TABLE IF NOT EXISTS `variants` (
            `id` INTEGER PRIMARY KEY AUTOINCREMENT,
            `experiment_id` INTEGER NOT NULL,
            `variant_key` TEXT NOT NULL,
            `name` TEXT,
            `weight` INTEGER NOT NULL DEFAULT 1,
            UNIQUE(`experiment_id`, `variant_key`)
        )",
        "CREATE TABLE IF NOT EXISTS `assignments` (
            `id` INTEGER PRIMARY KEY AUTOINCREMENT,
            `experiment_id` INTEGER NOT NULL,
            `variant_id` INTEGER NOT NULL,
            `user_token` TEXT NOT NULL,
            `assigned_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(`experiment_id`, `user_token`)
        )",
        "CREATE TABLE IF NOT EXISTS `events` (
            `id` INTEGER PRIMARY KEY AUTOINCREMENT,
            `experiment_id` INTEGER NOT NULL,
            `variant_id` INTEGER NOT NULL,
            `user_token` TEXT NOT NULL,
            `event` TEXT NOT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
        )",
    ];

    foreach ($queries as $query) {
        $pdo->exec($query);
    }
}

function getUserId(): string
{
    $userId = $_COOKIE[AB_COOKIE_NAME] ?? '';

    if (empty($userId)) {
        $userId = bin2hex(random_bytes(16));

        setcookie(AB_COOKIE_NAME, $userId, [
            'expires' => time() + (AB_COOKIE_DAYS * 24 * 60 * 60),
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        $_COOKIE[AB_COOKIE_NAME] = $userId;
    }

    return $userId;
}

function getOrCreateExperiment(PDO $pdo, string $experimentKey, ?string $experimentName): int
{
    $existing = executeQuery(
        pdo: $pdo,
        sql: "
            SELECT `id`
            FROM `experiments`
            WHERE `experiment_key` = ?
        ",
        params: [$experimentKey]
    );

    if ($existing !== null) {
        return (int) $existing['id'];
    }

    $statement = $pdo->prepare(
        "INSERT INTO `experiments` (`experiment_key`, `name`)
        VALUES (?, ?)"
    );
    $statement->execute([$experimentKey, $experimentName]);

    return (int) $pdo->lastInsertId();
}

function getExistingAssignment(PDO $pdo, int $experimentId, string $userId): ?array
{
    return executeQuery(
        pdo: $pdo,
        sql: "
            SELECT `variants`.`variant_key`
            FROM `assignments`
            JOIN `variants`
                ON `variants`.`id` = `assignments`.`variant_id`
            WHERE `assignments`.`experiment_id` = ?
                AND `assignments`.`user_token` = ?
        ",
        params: [$experimentId, $userId]
    );
}

function ensureVariantsExist(PDO $pdo, int $experimentId, array $weights): void
{
    foreach ($weights as $variantKey => $weight) {
        $existing = executeQuery(
            pdo: $pdo,
            sql: "
                SELECT `id`
                FROM `variants`
                WHERE `experiment_id` = ?
                    AND `variant_key` = ?
            ",
            params: [$experimentId, $variantKey]
        );

        if ($existing === null) {
            $statement = $pdo->prepare(
                "INSERT INTO `variants` (`experiment_id`, `variant_key`, `name`, `weight`)
                VALUES (?, ?, ?, ?)"
            );
            $statement->execute([$experimentId, $variantKey, $variantKey, (int) $weight]);
        } else {
            $statement = $pdo->prepare(
                "UPDATE `variants`
                SET `weight` = ?
                WHERE `id` = ?"
            );
            $statement->execute([(int) $weight, $existing['id']]);
        }
    }
}

function getVariants(PDO $pdo, int $experimentId): array
{
    $statement = $pdo->prepare(
        "SELECT `id`, `variant_key`, `weight`
        FROM `variants`
        WHERE `experiment_id` = ?
        ORDER BY `variant_key`"
    );
    $statement->execute([$experimentId]);

    return $statement->fetchAll();
}

function selectWeightedVariant(array $variants): array
{
    $totalWeight = array_sum(array_column($variants, 'weight'));

    if ($totalWeight <= 0) {
        $totalWeight = count($variants);
    }

    $randomValue = random_int(1, $totalWeight);
    $currentWeight = 0;

    foreach ($variants as $variant) {
        $currentWeight += max(1, (int) $variant['weight']);

        if ($randomValue <= $currentWeight) {
            return $variant;
        }
    }

    return $variants[0]; // Fallback to first variant
}

function saveAssignment(PDO $pdo, int $experimentId, int $variantId, string $userId): void
{
    $statement = $pdo->prepare(
        "INSERT INTO `assignments` (`experiment_id`, `variant_id`, `user_token`)
        VALUES (?, ?, ?)"
    );
    $statement->execute([$experimentId, $variantId, $userId]);
}

function findExperiment(PDO $pdo, string $experimentKey): ?array
{
    return executeQuery(
        pdo: $pdo,
        sql: "
            SELECT `id`
            FROM `experiments`
            WHERE `experiment_key` = ?
        ",
        params: [$experimentKey]
    );
}

function findUserVariant(PDO $pdo, int $experimentId, string $userId): ?array
{
    return executeQuery(
        pdo: $pdo,
        sql: "
            SELECT `variants`.`id` 
            FROM `assignments` 
            JOIN `variants`
                ON `variants`.`id` = `assignments`.`variant_id` 
            WHERE `assignments`.`experiment_id` = ?
                AND `assignments`.`user_token` = ?
        ",
        params: [$experimentId, $userId]
    );
}

function goalAlreadyExists(PDO $pdo, int $experimentId, string $userId): bool
{
    $result = executeQuery(
        pdo: $pdo,
        sql: "
            SELECT 1 FROM `events` 
            WHERE `experiment_id` = ?
                AND `user_token` = ?
                AND `event` = ?
        ",
        params: [$experimentId, $userId, AB_GOAL_NAME]
    );

    return $result !== null;
}

function saveGoalEvent(PDO $pdo, int $experimentId, int $variantId, string $userId): bool
{
    try {
        $statement = $pdo->prepare(
            "INSERT INTO `events` (`experiment_id`, `variant_id`, `user_token`, `event`)
            VALUES (?, ?, ?, ?)"
        );
        $statement->execute([$experimentId, $variantId, $userId, AB_GOAL_NAME]);

        return true;
    } catch (PDOException $e) {
        return false;
    }
}

function executeQuery(PDO $pdo, string $sql, array $params = []): ?array
{
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    $result = $statement->fetch();

    return $result ?: null;
}
