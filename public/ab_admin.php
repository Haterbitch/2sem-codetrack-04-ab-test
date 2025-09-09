<?php
/**
 * ab_admin.php — tiny admin to view experiments & conversion
 * Put this behind basic auth if public!
 */
require __DIR__ . '/ab_client.php'; // reuses DB + helpers

// List experiments
$exps = $pdo->query("SELECT id, key, name FROM experiments ORDER BY id DESC")->fetchAll();

function stats_for_exp(PDO $pdo, int $expId): array {
    // Views = assignments per variant
    $views = $pdo->prepare("
    SELECT v.key AS variant, COUNT(*) AS views
    FROM assignments a
    JOIN variants v ON v.id=a.variant_id
    WHERE a.experiment_id=?
    GROUP BY v.key
  ");
    $views->execute([$expId]);
    $vmap = [];
    foreach ($views->fetchAll() as $r) $vmap[$r['variant']] = ['views' => (int)$r['views'], 'clicks' => 0];

    // Goals = events per variant
    $goals = $pdo->prepare("
    SELECT v.key AS variant, COUNT(*) AS clicks
    FROM events e
    JOIN variants v ON v.id=e.variant_id
    WHERE e.experiment_id=? AND e.event=?
    GROUP BY v.key
  ");
    $goals->execute([$expId, AB_GOAL_NAME]);
    foreach ($goals->fetchAll() as $r) {
        $v = $r['variant'];
        $vmap[$v] = $vmap[$v] ?? ['views'=>0,'clicks'=>0];
        $vmap[$v]['clicks'] = (int)$r['clicks'];
    }

    // Fill missing variants (if any)
    $vars = $pdo->prepare("SELECT key FROM variants WHERE experiment_id=?");
    $vars->execute([$expId]);
    foreach ($vars->fetchAll() as $r) $vmap[$r['key']] = $vmap[$r['key']] ?? ['views'=>0,'clicks'=>0];

    // Compute CTR
    $out = [];
    foreach ($vmap as $k=>$d) {
        $ctr = $d['views'] ? round(100 * $d['clicks'] / $d['views'], 2) : 0;
        $out[] = ['variant'=>$k, 'views'=>$d['views'], 'goals'=>$d['clicks'], 'ctr'=>$ctr];
    }
    usort($out, fn($a,$b)=>strcmp($a['variant'],$b['variant']));
    return $out;
}
?>
<!doctype html>
<html lang="en"><head>
<meta charset="utf-8">
<title>AB Admin</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body { font: 14px/1.4 system-ui, -apple-system, Segoe UI, Roboto, sans-serif; padding: 24px; color:#111;}
h1 { margin-top: 0; }
table { border-collapse: collapse; width: 100%; margin: 12px 0 24px; }
th, td { border: 1px solid #ddd; padding: 8px 10px; text-align: left; }
th { background: #f7f7f7; }
small.code { background:#f0f0f0; padding:2px 6px; border-radius:4px; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
.note { color:#555; }
</style>
</head><body>
<h1>A/B Experiments</h1>

    <?php if (!$exps): ?>
        <p class="note">No experiments yet. Create one by calling
    <small class="code">ab_variant('example', 'My Experiment', ['A'=>50,'B'=>50])</small> in your page.</p>
    <?php endif; ?>

    <?php foreach ($exps as $e): ?>
        <h2><?=htmlspecialchars($e['name'] ?: $e['key'])?></h2>
        <p class="note">Key: <small class="code"><?=htmlspecialchars($e['key'])?></small></p>
        <table>
    <thead><tr><th>Variant</th><th>Views</th><th>Goals</th><th>CTR %</th></tr></thead>
    <tbody>
      <?php foreach (stats_for_exp($pdo, (int)$e['id']) as $row): ?>
          <tr>
          <td><?=htmlspecialchars($row['variant'])?></td>
          <td><?=$row['views']?></td>
          <td><?=$row['goals']?></td>
          <td><?=$row['ctr']?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
    <?php endforeach; ?>

    <h3>How to use (copy/paste)</h3>
<pre><code>&lt;?php
require __DIR__ . '/ab_client.php';

// 1) Choose or create experiment + weighted variants
$variant = ab_variant('cta_test', 'CTA button test', ['A' =&gt; 50, 'B' =&gt; 50]);

// 2) Render
if ($variant === 'A') {
  echo '&lt;a href="#" id="ctaA" class="btn btn-primary"&gt;Sign up now&lt;/a&gt;';
} else {
  echo '&lt;a href="#" id="ctaB" class="btn btn-success"&gt;Learn more&lt;/a&gt;';
}

// 3a) Client-side goal (on click)
?&gt;
&lt;script&gt;
  document.addEventListener('click', function(e){
    if(e.target.matches('#ctaA, #ctaB')) {
      fetch('/ab_client.php?goal=1&amp;exp=cta_test');
    }
  });
&lt;/script&gt;

&lt;?php
// 3b) Or server-side goal (after e.g. successful form):
// ab_track_goal('cta_test');
?&gt;</code></pre>

</body></html>
