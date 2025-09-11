# A/B Testing PHP Library

A simple, reusable PHP library for conducting A/B tests on websites. Perfect for students to add A/B testing functionality to their portfolios, guestbooks, or any web project.

## 🚀 Features

- **📦 Simple Integration** - Just include one file and start testing
- **📊 Admin Dashboard** - View experiment statistics and conversion rates  
- **🗃️ SQLite Database** - Lightweight, no complex setup required
- **🔄 Goal Tracking** - Track conversions and measure success
- **👥 User Persistence** - Cookie-based user identification across sessions
- **⚖️ Weighted Variants** - Control traffic distribution between variants
- **📱 Responsive Design** - Modern, mobile-friendly interface
- **🎯 Real-time Stats** - View conversion rates and experiment performance

## 📁 Project Structure

```
04-ab-test/
├── ab_tests.sqlite          # SQLite database (auto-created)
├── public/
│   ├── ab_client.php        # Main library file - include this in your projects
│   ├── ab_admin.php         # Admin dashboard for viewing results
│   └── index.php            # Example implementation
└── README.md
```

## 🔧 Quick Start

### 1. Include the library

```php
<?php
require_once 'ab_client.php';

// Create an A/B test with weighted variants
$variant = ab_variant(
    experimentKey: 'button_test',
    experimentName: 'CTA Button Test', 
    weights: [
        'Sign Up Now' => 50,
        'Get Started Today' => 50,
    ]
);
?>
```

### 2. Render content based on variant

```php
<?php if ($variant === 'Sign Up Now'): ?>
    <button id="cta-button" class="ab-cta-button ab-cta-primary">
        Sign Up Now
    </button>
<?php else: ?>
    <button id="cta-button" class="ab-cta-button ab-cta-success">
        Get Started Today
    </button>
<?php endif; ?>
```

### 3. Track goals with JavaScript

```javascript
document.getElementById('cta-button').addEventListener('click', function() {
    // Track conversion when button is clicked
    fetch('/ab_client.php?goal&experiment=button_test')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                console.log('Goal tracked successfully');
            }
        });
        
    // Optional: Update UI
    this.textContent = 'Thanks!';
    this.disabled = true;
});
```

## 📊 Admin Dashboard

View your experiment results at `/ab_admin.php`

### Dashboard Features:
- **Experiment Statistics** - Views, goals, and conversion rates for each variant
- **Data Management** - Delete experiment data or entire experiments
- **Implementation Examples** - Copy-paste code snippets
- **Real-time Updates** - Stats update as users interact with your tests

## 🛠️ API Reference

### `ab_variant(string $experimentKey, ?string $experimentName, array $weights)`

Creates or retrieves a variant assignment for the current user.

**Parameters:**
- `$experimentKey` - Unique identifier for the experiment
- `$experimentName` - Human-readable name (optional)
- `$weights` - Array of variant names and their weights (e.g., `['A' => 50, 'B' => 50]`)

**Returns:** String - The assigned variant name

### `ab_track_goal(string $experimentKey)`

Server-side goal tracking (alternative to JavaScript fetch).

**Parameters:**
- `$experimentKey` - The experiment identifier

**Returns:** Boolean - Success status

### Goal Tracking Endpoint

Make GET requests to track conversions:
```
/ab_client.php?goal&experiment={experimentKey}
```

Returns JSON: `{"success": true}`

## 🗄️ Database Schema

The library automatically creates these SQLite tables:

- **`experiments`** - Experiment definitions (id, experiment_key, name)
- **`variants`** - Variant configurations (id, experiment_id, variant_key, weight)
- **`assignments`** - User-variant assignments (experiment_id, variant_id, user_token)
- **`events`** - Goal conversions (experiment_id, variant_id, user_token, event)

## 🔧 Technical Details

### User Identification
- Uses secure random cookies (`ab_uid`) for user persistence
- 365-day cookie lifetime
- HttpOnly and SameSite=Lax for security

### Weighted Distribution
- Supports custom traffic distribution (e.g., 70/30 split)
- Automatic fallback to equal distribution
- Consistent assignment per user

### Database
- SQLite for simplicity and portability
- Automatic schema migration
- Thread-safe operations

## 📋 Requirements

- **PHP 8.4+** with SQLite support
- **Web server** (Apache, Nginx, or PHP built-in server)
- **Write permissions** for SQLite database creation

## 🚀 Installation

1. Copy `ab_client.php` to your project directory
2. Include it in your PHP files: `require_once 'ab_client.php';`
3. Start creating A/B tests!

The SQLite database (`ab_tests.sqlite`) will be created automatically on first use.

## 📈 Example Use Cases

- **CTA Button Testing** - Compare different button texts or colors
- **Headline Optimization** - Test different page headlines
- **Form Design** - Test different form layouts or field labels
- **Navigation Testing** - Compare different menu structures
- **Pricing Strategy** - Test different pricing displays
- **Content Variations** - Test different copy or messaging

## 🔒 Security Notes

- The admin dashboard has no authentication by default
- Add proper authentication for production use
- Consider rate limiting for goal tracking endpoints
- SQLite file should not be web-accessible

## 🎓 Educational Purpose

This library is designed for 2nd semester programming students to learn:
- A/B testing concepts and implementation
- Database design and SQLite usage
- PHP library development
- Statistical analysis of user behavior
- Modern web development practices
