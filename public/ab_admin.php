<?php

declare(strict_types=1);

/**
 * A/B Testing Admin Dashboard
 *
 * Simple dashboard to view experiment statistics and conversion rates.
 * Note: Put this behind authentication in production!
 */

require __DIR__ . '/ab_client.php';

// Handle delete data request
if (isset($_POST['delete_data'], $_POST['experiment_id'])) {
    $experimentId = (int) $_POST['experiment_id'];
    $success = deleteExperimentData($database, $experimentId);

    // Redirect to prevent resubmission
    $message = $success ? 'success' : 'error';
    header("Location: " . $_SERVER['PHP_SELF'] . "?deleted=$message");
    exit;
}

// Handle delete entire experiment request
if (isset($_POST['delete_experiment'], $_POST['experiment_id'])) {
    $experimentId = (int) $_POST['experiment_id'];
    $success = deleteEntireExperiment($database, $experimentId);

    // Redirect to prevent resubmission
    $message = $success ? 'experiment_deleted' : 'experiment_error';
    header("Location: " . $_SERVER['PHP_SELF'] . "?deleted=$message");
    exit;
}

// Get all experiments ordered by most recent
$experiments = getAllExperiments($database);

/**
 * Calculate statistics for a specific experiment
 *
 * @param PDO $database Database connection
 * @param int $experimentId The experiment ID
 * @return array Statistics including views, goals, and conversion rates
 */
function calculateExperimentStats(PDO $database, int $experimentId): array
{
    $viewStats = getVariantViews($database, $experimentId);
    $goalStats = getVariantGoals($database, $experimentId);
    $allVariants = getAllVariantsForExperiment($database, $experimentId);

    $stats = [];

    // Initialize all variants with zero stats
    foreach ($allVariants as $variant) {
        $stats[$variant['variant_key']] = [
            'views' => 0,
            'goals' => 0,
            'conversion_rate' => 0.0
        ];
    }

    // Add view counts
    foreach ($viewStats as $stat) {
        $stats[$stat['variant_key']]['views'] = (int) $stat['views'];
    }

    // Add goal counts and calculate conversion rates
    foreach ($goalStats as $stat) {
        $variantKey = $stat['variant_key'];
        $stats[$variantKey]['goals'] = (int) $stat['goals'];

        if ($stats[$variantKey]['views'] > 0) {
            $stats[$variantKey]['conversion_rate'] = round(
                ($stats[$variantKey]['goals'] / $stats[$variantKey]['views']) * 100,
                2
            );
        }
    }

    // Convert to indexed array for easier display
    $result = [];
    foreach ($stats as $variantKey => $data) {
        $result[] = [
            'variant_key' => $variantKey,
            'views' => $data['views'],
            'goals' => $data['goals'],
            'conversion_rate' => $data['conversion_rate']
        ];
    }

    // Sort by variant key for consistent display
    usort($result, fn($a, $b) => strcmp($a['variant_key'], $b['variant_key']));

    return $result;
}

function getAllExperiments(PDO $database): array
{
    $statement = $database->prepare(
        "SELECT `id`, `experiment_key`, `name` FROM `experiments` ORDER BY `id` DESC"
    );
    $statement->execute();

    return $statement->fetchAll();
}

function getVariantViews(PDO $database, int $experimentId): array
{
    $statement = $database->prepare(
        "SELECT `variants`.`variant_key`, COUNT(*) AS `views`
         FROM `assignments`
         JOIN `variants` ON `variants`.`id` = `assignments`.`variant_id`
         WHERE `assignments`.`experiment_id` = ?
         GROUP BY `variants`.`variant_key`"
    );
    $statement->execute([$experimentId]);

    return $statement->fetchAll();
}

function getVariantGoals(PDO $database, int $experimentId): array
{
    $statement = $database->prepare(
        "SELECT `variants`.`variant_key`, COUNT(*) AS `goals`
         FROM `events`
         JOIN `variants` ON `variants`.`id` = `events`.`variant_id`
         WHERE `events`.`experiment_id` = ? AND `events`.`event` = ?
         GROUP BY `variants`.`variant_key`"
    );
    $statement->execute([$experimentId, AB_GOAL_NAME]);

    return $statement->fetchAll();
}

function getAllVariantsForExperiment(PDO $database, int $experimentId): array
{
    $statement = $database->prepare(
        "SELECT `variant_key` FROM `variants` WHERE `experiment_id` = ?"
    );
    $statement->execute([$experimentId]);

    return $statement->fetchAll();
}

/**
 * Delete all data (assignments and events) for an experiment
 *
 * @param PDO $database Database connection
 * @param int $experimentId The experiment ID to clear data for
 * @return bool True if deletion was successful
 */
function deleteExperimentData(PDO $database, int $experimentId): bool
{
    try {
        $database->beginTransaction();

        // Delete all events for this experiment
        $deleteEvents = $database->prepare("DELETE FROM `events` WHERE `experiment_id` = ?");
        $deleteEvents->execute([$experimentId]);

        // Delete all assignments for this experiment
        $deleteAssignments = $database->prepare("DELETE FROM `assignments` WHERE `experiment_id` = ?");
        $deleteAssignments->execute([$experimentId]);

        $database->commit();
        return true;
    } catch (PDOException $e) {
        $database->rollBack();
        return false;
    }
}

/**
 * Delete an entire experiment and all its associated data
 *
 * @param PDO $database Database connection
 * @param int $experimentId The experiment ID to completely delete
 * @return bool True if deletion was successful
 */
function deleteEntireExperiment(PDO $database, int $experimentId): bool
{
    try {
        $database->beginTransaction();

        // Delete all events for this experiment
        $deleteEvents = $database->prepare("DELETE FROM `events` WHERE `experiment_id` = ?");
        $deleteEvents->execute([$experimentId]);

        // Delete all assignments for this experiment
        $deleteAssignments = $database->prepare("DELETE FROM `assignments` WHERE `experiment_id` = ?");
        $deleteAssignments->execute([$experimentId]);

        // Delete all variants for this experiment
        $deleteVariants = $database->prepare("DELETE FROM `variants` WHERE `experiment_id` = ?");
        $deleteVariants->execute([$experimentId]);

        // Delete the experiment itself
        $deleteExperiment = $database->prepare("DELETE FROM `experiments` WHERE `id` = ?");
        $deleteExperiment->execute([$experimentId]);

        $database->commit();
        return true;
    } catch (PDOException $e) {
        $database->rollBack();
        return false;
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>A/B Testing Dashboard</title>
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;
            line-height: 1.6;
            margin: 0;
            padding: 2rem;
            background-color: #f8f9fa;
            color: #333;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            text-align: center;
        }

        .header h1 {
            margin: 0;
            font-size: 2rem;
            font-weight: 600;
        }

        .content {
            padding: 2rem;
        }

        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #6c757d;
        }

        .experiment {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .experiment:last-child {
            margin-bottom: 0;
        }

        .experiment-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .experiment-info h2 {
            margin: 0 0 0.5rem 0;
            color: #495057;
            font-size: 1.5rem;
        }

        .experiment-key {
            font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace;
            background: #e9ecef;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.875rem;
            color: #495057;
        }

        .delete-button {
            background-color: #dc3545;
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.875rem;
            font-weight: 500;
            transition: background-color 0.2s ease;
        }

        .delete-button:hover {
            background-color: #c82333;
        }

        .delete-button:disabled {
            background-color: #6c757d;
            cursor: not-allowed;
        }

        .stats-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        .stats-table th,
        .stats-table td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid #e9ecef;
        }

        .stats-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #495057;
        }

        .stats-table tr:last-child td {
            border-bottom: none;
        }

        .stats-table tr:hover {
            background-color: #f8f9fa;
        }

        .variant-key {
            font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace;
            font-weight: 600;
            color: #007bff;
        }

        .metric {
            font-weight: 600;
        }

        .conversion-rate {
            color: #28a745;
        }

        .usage-example {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 1.5rem;
            margin-top: 2rem;
        }

        .usage-example h3 {
            margin: 0 0 1rem 0;
            color: #495057;
        }

        .code-block {
            background: #2d3748;
            color: #e2e8f0;
            padding: 1rem;
            border-radius: 6px;
            overflow-x: auto;
            font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace;
            font-size: 0.875rem;
            line-height: 1.5;
        }

        .notification {
            padding: 1rem;
            border-radius: 6px;
            margin-bottom: 1rem;
            font-weight: 500;
        }

        .notification.success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .notification.error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>A/B Testing Dashboard</h1>
        </div>

        <div class="content">
            <?php if (isset($_GET['deleted'])): ?>
                <?php if ($_GET['deleted'] === 'success'): ?>
                    <div class="notification success">
                        ✓ Experiment data deleted successfully. All assignments and goals have been cleared.
                    </div>
                <?php elseif ($_GET['deleted'] === 'experiment_deleted'): ?>
                    <div class="notification success">
                        ✓ Entire experiment deleted successfully. All data, including assignments and goals, have been removed.
                    </div>
                <?php else: ?>
                    <div class="notification error">
                        ✗ Error deleting experiment data. Please try again.
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php if (empty($experiments)): ?>
                <div class="empty-state">
                    <h3>No experiments found</h3>
                    <p>Create your first experiment by calling:</p>
                    <div class="code-block">ab_variant('my_test', 'My First Test', ['A' => 50, 'B' => 50]);</div>
                </div>
            <?php else: ?>
                <?php foreach ($experiments as $experiment): ?>
                    <div class="experiment">
                        <div class="experiment-header">
                            <div class="experiment-info">
                                <h2><?= htmlspecialchars($experiment['name'] ?: $experiment['experiment_key']) ?></h2>
                                <p>Experiment Key: <span class="experiment-key"><?= htmlspecialchars($experiment['experiment_key']) ?></span></p>
                            </div>
                            <div>
                                <form method="post" style="display:inline;">
                                    <input type="hidden" name="experiment_id" value="<?= $experiment['id'] ?>">
                                    <button type="submit" name="delete_data" class="delete-button" onclick="return confirm('Are you sure you want to delete all data for this experiment? This action cannot be undone.');">
                                        Delete Data
                                    </button>
                                </form>
                                <form method="post" style="display:inline;">
                                    <input type="hidden" name="experiment_id" value="<?= $experiment['id'] ?>">
                                    <button type="submit" name="delete_experiment" class="delete-button" onclick="return confirm('Are you sure you want to delete this entire experiment? This action cannot be undone.');">
                                        Delete Experiment
                                    </button>
                                </form>
                            </div>
                        </div>

                        <table class="stats-table">
                            <thead>
                                <tr>
                                    <th>Variant</th>
                                    <th>Views</th>
                                    <th>Goals</th>
                                    <th>Conversion Rate</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (calculateExperimentStats($database, (int) $experiment['id']) as $stat): ?>
                                    <tr>
                                        <td><span class="variant-key"><?= htmlspecialchars($stat['variant_key']) ?></span></td>
                                        <td><span class="metric"><?= $stat['views'] ?></span></td>
                                        <td><span class="metric"><?= $stat['goals'] ?></span></td>
                                        <td><span class="metric conversion-rate"><?= $stat['conversion_rate'] ?>%</span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <div class="usage-example">
                <h3>Implementation Example</h3>
                <div class="code-block">&lt;?php
require __DIR__ . '/ab_client.php';

// Assign variant
$variant = ab_variant('cta_test', 'CTA Button Test', ['A' =&gt; 50, 'B' =&gt; 50]);

// Render based on variant
if ($variant === 'A') {
    echo '&lt;button id="cta" class="btn-primary"&gt;Sign Up Now&lt;/button&gt;';
} else {
    echo '&lt;button id="cta" class="btn-success"&gt;Get Started&lt;/button&gt;';
}

// Track goals with JavaScript
?&gt;
&lt;script&gt;
document.getElementById('cta').addEventListener('click', function() {
    fetch('/ab_client.php?goal=1&amp;experiment=cta_test');
});
&lt;/script&gt;</div>
            </div>
        </div>
    </div>
</body>
</html>
