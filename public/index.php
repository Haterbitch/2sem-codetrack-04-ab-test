<?php
require __DIR__ . '/ab_client.php';

// 1) Choose or create experiment + weighted variants
$variant = ab_variant('cta_test', 'CTA button test', ['A' => 50, 'B' => 50]);

// 2) Render
if ($variant === 'A') {
    echo '<a href="#" id="ctaA" class="btn btn-primary">Sign up now</a>';
} else {
    echo '<a href="#" id="ctaB" class="btn btn-success">Learn more</a>';
}

// 3a) Client-side goal (on click)
?>
    <script>
      document.addEventListener('click', function(e){
        if(e.target.matches('#ctaA, #ctaB')) {
          fetch('/ab_client.php?goal=1&experiment=cta_test');
        }
      });
    </script>

<?php
// 3b) Or server-side goal (after e.g. successful form):
// ab_track_goal('cta_test');
?>
