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
    <title>Welcome to Our Service</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;
            max-width: 800px;
            margin: 2rem auto;
            padding: 2rem;
            line-height: 1.6;
            background-color: #f8f9fa;
        }

        .ab-demo-container {
            background: white;
            padding: 3rem;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            text-align: center;
        }

        h1 {
            color: #333;
            margin-bottom: 1rem;
        }

        .ab-subtitle {
            color: #666;
            margin-bottom: 3rem;
            font-size: 1.1rem;
        }

        .ab-cta-button {
            display: inline-block;
            padding: 1rem 2rem;
            font-size: 1.1rem;
            font-weight: 600;
            text-decoration: none;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
            margin: 1rem;
        }

        .ab-cta-primary {
            background-color: #007bff;
            color: white;
        }

        .ab-cta-primary:hover {
            background-color: #0056b3;
            transform: translateY(-2px);
        }

        .ab-cta-success {
            background-color: #28a745;
            color: white;
        }

        .ab-cta-success:hover {
            background-color: #1e7e34;
            transform: translateY(-2px);
        }
    </style>
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
