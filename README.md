# Seafood Calorie Calculator

A dedicated, seafood-only calorie and nutrition calculator plugin for WordPress, purpose-built for [fishmongerlondon.co.uk](https://fishmongerlondon.co.uk) and any fishmonger, seafood retailer, restaurant, or health & nutrition site that wants to give visitors an accurate, interactive way to understand what's in their fish and shellfish. Unlike generic calorie calculators that treat seafood as an afterthought buried in a giant all-purpose food database, this plugin is scoped entirely to seafood — fish, shellfish, crustaceans, and mollusks — so every data point, filter, and health insight is tuned specifically to that category.

Beyond raw calorie counts, it surfaces the nutrition angles that matter most for seafood specifically: omega-3 EPA+DHA content against a daily target, mercury exposure risk (a common concern for pregnant women, young children, and frequent seafood eaters), and how different cooking methods (baked, raw, steamed, grilled, poached, pan-fried, deep-fried, smoked) shift calorie, fat, and protein values away from the raw baseline. It supports single-item lookups, multi-item meal building with combined totals, and side-by-side comparisons between two items — covering the full range of how a real visitor might explore a seafood counter's offerings, from "how many calories in this salmon fillet" to "which of these two options is healthier for my diet."

The plugin also doubles as a lightweight engagement and lead-generation tool for the business running it: printable/PDF results carry the site's own branding, WhatsApp contact details, and a QR code, turning a nutrition lookup into a touchpoint that can drive customers back to the business. Built-in analytics and a "missed searches" log give the site owner visibility into what visitors are actually looking for — including seafood items not yet in the database — so the tool's coverage and business messaging can improve over time based on real demand rather than guesswork.

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
