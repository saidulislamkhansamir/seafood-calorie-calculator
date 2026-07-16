# Seafood Calorie Calculator

WordPress plugin powering the seafood calorie calculator on [fishmongerlondon.co.uk](https://fishmongerlondon.co.uk). A seafood-only calorie calculator with omega-3 tracking, mercury levels, health benefit tips, macro breakdown, and a daily meal tracker.

Use anywhere with the shortcode: `[seafood_calorie_calculator]`

## Structure

- `seafood_calorie_calculator.php` — main plugin file
- `css/style.css` — stylesheet
- `js/script.js` — frontend script

## Deployment

Pushing to `main` auto-deploys to the live site via GitHub Actions ([.github/workflows/deploy.yml](.github/workflows/deploy.yml)), over plain FTP, to:

```
public_html/wp-content/plugins/seafood-calorie-calculator/
```

Requires these repo secrets under **Settings → Secrets and variables → Actions**:

| Secret | Value |
|---|---|
| `FTP_SERVER` | `195.35.15.105` |
| `FTP_USERNAME` | `u591585971.fishmongerlondon.co.uk` |
| `FTP_PASSWORD` | Hostinger FTP password |

To verify a deploy went through, check the plugin version under **Plugins** in wp-admin — it should match the `Version` header in `seafood_calorie_calculator.php`.
