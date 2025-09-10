<?php
require __DIR__ . '/ab_client.php';

// 1) Choose or create experiment + weighted variants
$variant = ab_variant(
    experimentKey: 'cta_text',
    experimentName: 'CTA button text',
    weights: ['Sign up now' => 50, 'Learn more' => 50]
);

// 2) Render
if ($variant === 'Sign up now') {
    echo '<a href="#" id="ctaA" class="btn btn-primary">Sign up now</a>';
} else {
    echo '<a href="#" id="ctaB" class="btn btn-success">Learn more</a>';
}

// 3a) Client-side goal (on click)
?>
    <script>
      document.addEventListener('click', function(e){
        if(e.target.matches('#ctaA, #ctaB')) {
          fetch('/ab_client.php?goal=1&experiment=cta_text')
            .then(response => response.json())
            .then(data => {
              console.log('A/B goal tracked: [cta_text]', data);
            });
        }
      });
    </script>

<?php
// 3b) Or server-side goal (after e.g. successful form):
// ab_track_goal('cta_test');
?>
