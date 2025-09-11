<?php

declare(strict_types=1);

/**
 * A/B Testing Demo Page
 *
 * Example implementation showing how to use the A/B testing library
 */

/**
 * @var $database PDO
 * @var $userId string
 */
require __DIR__ . '/ab_client.php';

// Create or get variant assignment for CTA test
$variant = ab_variant(
    experimentKey: 'cta_text',
    experimentName: 'CTA Button Text',
    weights: [
        'Sign Up Now' => 50,
        'Get Started Today' => 50,
    ]
);

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="style.css" rel="stylesheet">
    <title>Welcome to Our Service</title>

</head>
<body>
    <div class="ab-demo-container">
        <h1>Welcome to Our Service</h1>
        <p class="ab-subtitle">Join thousands of satisfied customers today</p>

        <?php if ($variant === 'Sign Up Now'): ?>
            <button id="cta-button" class="ab-cta-button ab-cta-primary">
                Sign Up Now
            </button>
        <?php else: ?>
            <button id="cta-button" class="ab-cta-button ab-cta-success">
                Get Started Today
            </button>
        <?php endif; ?>
    </div>

    <script>
        // Track goal when CTA button is clicked
        document
          .getElementById('cta-button')
          .addEventListener('click', function() {
            // Send goal tracking request
            fetch('/ab_client.php?goal&experiment=cta_text')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        console.log('Goal tracked successfully');
                    }
                })
                .catch(error => {
                    console.error('Error tracking goal:', error);
                });

            // Show feedback to user
            this.textContent = 'Thanks!';
            this.style.backgroundColor = '#6c757d';
            this.disabled = true;
        });
    </script>
</body>
</html>
