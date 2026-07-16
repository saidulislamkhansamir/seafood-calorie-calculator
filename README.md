# Seafood Calorie Calculator

A seafood-only calorie and nutrition calculator for WordPress, built for [fishmongerlondon.co.uk](https://fishmongerlondon.co.uk).

**Author:** The Khan Digital — [thekhandigital.com](https://thekhandigital.com)

## Features

- **Searchable seafood database** — search by name, filter by Top Omega-3, High Protein, or Low Mercury
- **Cooking method adjustments** — nutrition values recalculate for baked, raw, steamed, grilled, poached, pan-fried, deep-fried, or smoked preparation
- **Custom serving size & units** — per-gram values scale to any portion
- **Macro breakdown** — protein, fat, and calorie distribution with daily value percentages
- **Omega-3 tracking** — EPA+DHA content with progress against a daily target
- **Mercury level indicator** — flags high-mercury items
- **Health tips** — contextual nutrition notes per item
- **Meal Tracker** — build a meal from multiple items with combined nutrition totals
- **Compare tab** — side-by-side comparison of two seafood items
- **Print / PDF export** — branded printable result card with WhatsApp contact, QR code, trust strip, and configurable footer links
- **Admin settings** — configure daily calorie/protein/omega-3 goals, default serving size, and toggle individual features (filter bar, tracker, compare, health tips, mercury indicator) on or off
- **Food Requests & Missed Searches** — logs searches that return no results, so you can spot demand for items missing from the database
- **Analytics export** — export usage analytics and the food database to CSV/Excel

## Installation

1. Download or clone this repository
2. Upload the folder to `wp-content/plugins/seafood-calorie-calculator/` on your WordPress site (via FTP, SFTP, or the WordPress admin **Plugins → Add New → Upload Plugin** with a zipped copy)
3. In **wp-admin → Plugins**, activate **Seafood Calorie Calculator**
4. Add the shortcode `[seafood_calorie_calculator]` to any page or post:
   - **Gutenberg:** use a Shortcode block
   - **Classic editor:** paste directly into the text
5. Configure goals and toggle features under the plugin's settings page in wp-admin

## Structure

- `seafood_calorie_calculator.php` — main plugin file
- `css/style.css` — stylesheet
- `js/script.js` — frontend script
