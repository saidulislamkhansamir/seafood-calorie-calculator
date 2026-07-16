<?php
/**
 * Plugin Name:  Seafood Calorie Calculator
 * Plugin URI:   https://thekhandigital.com
 * Description:  A seafood-only calorie calculator with omega-3 tracking, mercury levels, health benefit tips, macro breakdown, and a daily meal tracker. Use shortcode [seafood_calorie_calculator] anywhere.
 * Version:      5.5.6
 * Author:       The Khan Digital
 * Author URI:   https://thekhandigital.com
 * License:      GPL-2.0+
 * Text Domain:  seafood-calorie-calculator
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Prevent direct access

// ─── Constants ───────────────────────────────────────────────────────────────
define( 'FCC_VERSION',     '5.5.6' );
define( 'FCC_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'FCC_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );

// ─── Main Plugin Class ────────────────────────────────────────────────────────
class SeafoodCalorieCalculator {

    private static $instance = null;
    private $foodCache = null;

    public static function getInstance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // One-time migration: set fcc_tables_ready if tables exist but option missing
        if ( ! get_option( 'fcc_tables_ready' ) ) {
            global $wpdb;
            if ( $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}fcc_foods'" ) === "{$wpdb->prefix}fcc_foods" ) {
                update_option( 'fcc_tables_ready', 1 );
            }
        }

        add_action( 'wp_enqueue_scripts',    [ $this, 'enqueueAssets'   ], 999 );
        add_action( 'admin_menu',            [ $this, 'adminMenu'       ] );
        add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), [ $this, 'addSettingsLink' ] );
        add_action( 'admin_post_fcc_save_settings',     [ $this, 'adminSaveSettings'    ] );
        add_action( 'admin_post_fcc_save_pdf_settings', [ $this, 'adminSavePdfSettings' ] );
        if ( ! get_option('fcc_sort_priority_col') ) {
            add_action( 'init', [ $this, 'migrateSortPriorityCol' ] );
        }
        if ( ! get_option('fcc_analytics_name_sync_v1') ) {
            add_action( 'init', [ $this, 'syncAnalyticsFoodNames' ] );
        }
        add_shortcode( 'seafood_calorie_calculator', [ $this, 'renderCalculator' ] );
        add_action( 'wp_ajax_fcc_search_food',              [ $this, 'ajaxSearchFood'      ] );
        add_action( 'wp_ajax_nopriv_fcc_search_food',       [ $this, 'ajaxSearchFood'      ] );
        add_action( 'wp_ajax_fcc_calculate_calories',       [ $this, 'ajaxCalculateCalories' ] );
        add_action( 'wp_ajax_nopriv_fcc_calculate_calories',[ $this, 'ajaxCalculateCalories' ] );
        add_action( 'wp_ajax_fcc_update_sort_priority',     [ $this, 'ajaxUpdateSortPriority' ] );
        add_action( 'wp_ajax_fcc_track_event',              [ $this, 'ajaxTrackEvent'      ] );
        add_action( 'wp_ajax_nopriv_fcc_track_event',       [ $this, 'ajaxTrackEvent'      ] );
        add_action( 'wp_ajax_fcc_save_meal',                [ $this, 'ajaxSaveMeal'        ] );
        add_action( 'wp_ajax_nopriv_fcc_save_meal',         [ $this, 'ajaxSaveMeal'        ] );
        add_action( 'admin_post_fcc_save_food',        [ $this, 'adminSaveFood'        ] );
        add_action( 'admin_post_fcc_delete_food',      [ $this, 'adminDeleteFood'      ] );
        add_action( 'admin_post_fcc_reset_analytics',  [ $this, 'adminResetAnalytics'  ] );
        add_action( 'admin_post_fcc_export_analytics', [ $this, 'adminExportAnalytics' ] );
        add_action( 'admin_post_fcc_export_excel',     [ $this, 'adminExportAnalyticsExcel' ] );
        add_action( 'admin_post_fcc_export_foods',     [ $this, 'adminExportFoods'     ] );
        add_action( 'admin_post_fcc_import_foods',     [ $this, 'adminImportFoods'     ] );
        add_action( 'admin_post_fcc_seed_foods',       [ $this, 'adminSeedFoods'       ] );
        add_action( 'wp_ajax_fcc_log_missing_search',         [ $this, 'ajaxLogMissingSearch'    ] );
        add_action( 'wp_ajax_nopriv_fcc_log_missing_search',  [ $this, 'ajaxLogMissingSearch'    ] );
        add_action( 'wp_ajax_fcc_submit_food_request',         [ $this, 'ajaxSubmitFoodRequest'   ] );
        add_action( 'wp_ajax_nopriv_fcc_submit_food_request',  [ $this, 'ajaxSubmitFoodRequest'   ] );
        add_action( 'wp_ajax_fcc_update_request_status',       [ $this, 'ajaxUpdateRequestStatus' ] );

        // One-time migration: seed foods table if it exists but is empty
        if ( get_option( 'fcc_tables_ready' ) && ! get_option( 'fcc_foods_seeded' ) ) {
            add_action( 'init', [ $this, 'ensureFoodsSeeded' ] );
        }

        // One-time migration: create analytics tables if they don't exist yet
        if ( ! get_option( 'fcc_analytics_ready' ) ) {
            add_action( 'init', [ $this, 'ensureAnalyticsTables' ] );
        }

        // One-time migration: clean redundant cooking suffixes from fish names
        if ( ! get_option( 'fcc_names_v2' ) ) {
            add_action( 'init', [ $this, 'migrateCleanFoodNames' ] );
        }

        // One-time migration: insert new fish batch v2 (IDs 91–117)
        if ( get_option( 'fcc_foods_seeded' ) && ! get_option( 'fcc_fish_v2' ) ) {
            add_action( 'init', [ $this, 'ensureNewFishV2' ] );
        }

        // One-time migration: insert new fish batch v3 (IDs 118–130)
        if ( get_option( 'fcc_foods_seeded' ) && ! get_option( 'fcc_fish_v3' ) ) {
            add_action( 'init', [ $this, 'ensureNewFishV3' ] );
        }

        // One-time migration: insert new fish batch v4 (IDs 131–153)
        if ( get_option( 'fcc_foods_seeded' ) && ! get_option( 'fcc_fish_v4' ) ) {
            add_action( 'init', [ $this, 'ensureNewFishV4' ] );
        }

        // One-time migration: insert new fish batch v5 (IDs 154–175)
        if ( get_option( 'fcc_foods_seeded' ) && ! get_option( 'fcc_fish_v5' ) ) {
            add_action( 'init', [ $this, 'ensureNewFishV5' ] );
        }

        // One-time migration: insert new fish batch v6 (IDs 176–202)
        if ( get_option( 'fcc_foods_seeded' ) && ! get_option( 'fcc_fish_v6' ) ) {
            add_action( 'init', [ $this, 'ensureNewFishV6' ] );
        }

        // One-time migration: insert new fish batch v7 (IDs 203–211)
        if ( get_option( 'fcc_foods_seeded' ) && ! get_option( 'fcc_fish_v7' ) ) {
            add_action( 'init', [ $this, 'ensureNewFishV7' ] );
        }

        // One-time migration: insert new fish batch v8 (IDs 212–235)
        if ( get_option( 'fcc_foods_seeded' ) && ! get_option( 'fcc_fish_v8' ) ) {
            add_action( 'init', [ $this, 'ensureNewFishV8' ] );
        }

        // One-time migration: create food requests + missed searches tables
        if ( ! get_option( 'fcc_requests_ready' ) ) {
            add_action( 'init', [ $this, 'ensureRequestTables' ] );
        }
    }

    // ── Enqueue Styles & Scripts ──────────────────────────────────────────────
    public function enqueueAssets() {
        // Only load on the page/post that contains the shortcode — prevents DB
        // queries and ~30KB of inline JSON being injected on every other page.
        global $post;
        if ( ! is_a( $post, 'WP_Post' ) || ! has_shortcode( $post->post_content, 'seafood_calorie_calculator' ) ) {
            return;
        }

        // Always load our own FA6 under a plugin-specific handle so we're never
        // blocked by the theme registering an older/different Font Awesome version.
        wp_enqueue_style(
            'fcc-font-awesome',
            'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css',
            [],
            '6.5.0'
        );

        // IMPORTANT: Load plugin CSS LAST (priority 999) so it overwrites
        // WoodMart/WooCommerce/Elementor theme styles that load at default priority 10.
        // The !important declarations in style.css + late loading = guaranteed override.
        wp_enqueue_style(
            'fcc-style',
            FCC_PLUGIN_URL . 'css/style.css',
            [],        // no deps — avoids queue ordering issues with theme styles
            FCC_VERSION . '.1'  // bump version to bust browser/WP caches on update
        );

        wp_enqueue_script(
            'fcc-script',
            FCC_PLUGIN_URL . 'js/script.js',
            [ 'jquery' ],
            FCC_VERSION,
            true  // load in footer
        );
        // Pass AJAX URL and nonce to JS
        $foods_lite = array_map( function( $f ) {
            return [ 'id'=>(int)$f['id'], 'name'=>$f['name'], 'category'=>$f['category'],
                     'calories'=>(float)$f['calories'], 'protein'=>(float)$f['protein'],
                     'fat'=>(float)$f['fat'], 'omega3'=>(float)$f['omega3'], 'mercury'=>$f['mercury'] ];
        }, $this->getFoodDatabase() );

        // Top trending fish (cached 1 hour via transient)
        $trending = get_transient( 'fcc_top_trending' );
        if ( $trending === false ) {
            global $wpdb;
            $trending = $wpdb->get_results(
                "SELECT food_id, food_name FROM {$wpdb->prefix}fcc_analytics ORDER BY calcs DESC LIMIT 5",
                ARRAY_A
            ) ?: [];
            set_transient( 'fcc_top_trending', $trending, HOUR_IN_SECONDS );
        }

        $user_meal = null;
        if ( is_user_logged_in() ) {
            $raw = get_user_meta( get_current_user_id(), 'fcc_meal_items', true );
            if ( $raw ) {
                $d = json_decode( $raw, true );
                if ( is_array($d) && ! empty($d['items']) ) {
                    $user_meal = $d;
                }
            }
        }

        $pdf_s = $this->getPdfSettings();
        wp_localize_script( 'fcc-script', 'fcc_ajax', [
            'ajax_url'       => admin_url( 'admin-ajax.php' ),
            'nonce'          => wp_create_nonce( 'fcc_nonce' ),
            'foods'          => $foods_lite,
            'eco'            => $this->getEcoData(),
            'methods'        => $this->getCookingMethods(),
            'settings'       => $this->getSettings(),
            'user_logged_in' => is_user_logged_in() ? 1 : 0,
            'user_meal'      => $user_meal,
            'trending'       => $trending,
            'pdf'            => [
                'caviar_subtitle'  => $pdf_s['caviar_subtitle'],
                'seafood_subtitle' => $pdf_s['seafood_subtitle'],
            ],
        ] );
        // tips/allergens/seasons removed — all returned per-food in AJAX calc response
        wp_script_add_data( 'fcc-script', 'defer', true );
    }

    // ── Seafood Database — hardcoded defaults (per 100g values) ──────────────
    private function getDefaultFoods() {
        return [
            // ── White Fish ───────────────────────────────────────────────────
            [ 'id'=>1,  'name'=>'Cod',                        'category'=>'White Fish',       'calories'=>82,  'protein'=>18.0, 'carbs'=>0.0,  'fat'=>0.7,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.2, 'mercury'=>'low'      ],
            [ 'id'=>2,  'name'=>'Haddock',                  'category'=>'White Fish',       'calories'=>87,  'protein'=>19.0, 'carbs'=>0.0,  'fat'=>0.7,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.2, 'mercury'=>'low'      ],
            [ 'id'=>3,  'name'=>'Pollock',                  'category'=>'White Fish',       'calories'=>92,  'protein'=>20.0, 'carbs'=>0.0,  'fat'=>1.0,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.3, 'mercury'=>'low'      ],
            [ 'id'=>4,  'name'=>'Halibut',                  'category'=>'White Fish',       'calories'=>111, 'protein'=>23.0, 'carbs'=>0.0,  'fat'=>2.3,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.5, 'mercury'=>'moderate' ],
            [ 'id'=>5,  'name'=>'Sea Bass',                 'category'=>'White Fish',       'calories'=>97,  'protein'=>18.5, 'carbs'=>0.0,  'fat'=>2.0,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.7, 'mercury'=>'low'      ],
            [ 'id'=>6,  'name'=>'Plaice',                   'category'=>'White Fish',       'calories'=>79,  'protein'=>18.0, 'carbs'=>0.0,  'fat'=>0.5,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.3, 'mercury'=>'low'      ],
            [ 'id'=>7,  'name'=>'Dover Sole',               'category'=>'White Fish',       'calories'=>83,  'protein'=>18.5, 'carbs'=>0.0,  'fat'=>0.9,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.2, 'mercury'=>'low'      ],
            [ 'id'=>8,  'name'=>'Tilapia',                  'category'=>'White Fish',       'calories'=>96,  'protein'=>20.1, 'carbs'=>0.0,  'fat'=>1.7,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.1, 'mercury'=>'low'      ],
            [ 'id'=>9,  'name'=>'Monkfish',                 'category'=>'White Fish',       'calories'=>76,  'protein'=>16.4, 'carbs'=>0.0,  'fat'=>0.8,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.1, 'mercury'=>'low'      ],
            [ 'id'=>10, 'name'=>'Flounder',                 'category'=>'White Fish',       'calories'=>86,  'protein'=>17.9, 'carbs'=>0.0,  'fat'=>1.2,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.3, 'mercury'=>'low'      ],
            [ 'id'=>11, 'name'=>'Whiting',                  'category'=>'White Fish',       'calories'=>90,  'protein'=>19.5, 'carbs'=>0.0,  'fat'=>0.9,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.2, 'mercury'=>'low'      ],
            [ 'id'=>12, 'name'=>'Skate Wing',               'category'=>'White Fish',       'calories'=>79,  'protein'=>18.0, 'carbs'=>0.0,  'fat'=>0.5,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.3, 'mercury'=>'moderate' ],
            // ── Oily Fish ────────────────────────────────────────────────────
            [ 'id'=>13, 'name'=>'Salmon (Fresh)',             'category'=>'Oily Fish',        'calories'=>208, 'protein'=>20.4, 'carbs'=>0.0,  'fat'=>13.4, 'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>2.3, 'mercury'=>'low'      ],
            [ 'id'=>14, 'name'=>'Tuna (Fresh)',              'category'=>'Oily Fish',        'calories'=>144, 'protein'=>30.5, 'carbs'=>0.0,  'fat'=>2.5,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>1.2, 'mercury'=>'moderate' ],
            [ 'id'=>15, 'name'=>'Mackerel (Fresh)',          'category'=>'Oily Fish',        'calories'=>239, 'protein'=>21.0, 'carbs'=>0.0,  'fat'=>17.1, 'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>2.5, 'mercury'=>'low'      ],
            [ 'id'=>16, 'name'=>'Sardines (Fresh)',          'category'=>'Oily Fish',        'calories'=>185, 'protein'=>24.6, 'carbs'=>0.0,  'fat'=>10.4, 'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>1.5, 'mercury'=>'low'      ],
            [ 'id'=>17, 'name'=>'Herring',                   'category'=>'Oily Fish',        'calories'=>199, 'protein'=>20.4, 'carbs'=>0.0,  'fat'=>13.0, 'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>1.7, 'mercury'=>'low'      ],
            [ 'id'=>18, 'name'=>'Rainbow Trout',             'category'=>'Oily Fish',        'calories'=>150, 'protein'=>22.5, 'carbs'=>0.0,  'fat'=>6.6,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>2.0, 'mercury'=>'low'      ],
            [ 'id'=>19, 'name'=>'Anchovy',                   'category'=>'Oily Fish',        'calories'=>131, 'protein'=>20.4, 'carbs'=>0.0,  'fat'=>4.8,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>1.5, 'mercury'=>'low'      ],
            [ 'id'=>20, 'name'=>'Sprats',                    'category'=>'Oily Fish',        'calories'=>195, 'protein'=>20.0, 'carbs'=>0.0,  'fat'=>12.5, 'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>1.3, 'mercury'=>'low'      ],
            [ 'id'=>21, 'name'=>'Sea Bream',                 'category'=>'Oily Fish',        'calories'=>96,  'protein'=>20.3, 'carbs'=>0.0,  'fat'=>1.7,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.6, 'mercury'=>'low'      ],
            // ── Shellfish ────────────────────────────────────────────────────
            [ 'id'=>22, 'name'=>'Prawns',                    'category'=>'Shellfish',        'calories'=>99,  'protein'=>24.0, 'carbs'=>0.2,  'fat'=>0.3,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.3, 'mercury'=>'low'      ],
            [ 'id'=>23, 'name'=>'King Prawns',              'category'=>'Shellfish',        'calories'=>90,  'protein'=>20.3, 'carbs'=>0.0,  'fat'=>0.5,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.3, 'mercury'=>'low'      ],
            [ 'id'=>24, 'name'=>'Lobster',                  'category'=>'Shellfish',        'calories'=>97,  'protein'=>20.5, 'carbs'=>0.5,  'fat'=>1.0,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.2, 'mercury'=>'low'      ],
            [ 'id'=>25, 'name'=>'Crab',                     'category'=>'Shellfish',        'calories'=>84,  'protein'=>18.4, 'carbs'=>0.0,  'fat'=>1.1,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.3, 'mercury'=>'low'      ],
            [ 'id'=>26, 'name'=>'Dressed Crab',              'category'=>'Shellfish',        'calories'=>128, 'protein'=>18.0, 'carbs'=>0.0,  'fat'=>6.4,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.4, 'mercury'=>'low'      ],
            [ 'id'=>27, 'name'=>'Mussels',                   'category'=>'Shellfish',        'calories'=>86,  'protein'=>11.9, 'carbs'=>3.7,  'fat'=>2.2,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.5, 'mercury'=>'low'      ],
            [ 'id'=>28, 'name'=>'Oysters',                  'category'=>'Shellfish',        'calories'=>59,  'protein'=>7.1,  'carbs'=>4.7,  'fat'=>1.6,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.4, 'mercury'=>'low'      ],
            [ 'id'=>29, 'name'=>'Clams',                    'category'=>'Shellfish',        'calories'=>74,  'protein'=>12.8, 'carbs'=>2.6,  'fat'=>1.0,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.2, 'mercury'=>'low'      ],
            [ 'id'=>30, 'name'=>'Scallops',                 'category'=>'Shellfish',        'calories'=>111, 'protein'=>20.5, 'carbs'=>5.4,  'fat'=>1.2,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.3, 'mercury'=>'low'      ],
            [ 'id'=>31, 'name'=>'Langoustines',             'category'=>'Shellfish',        'calories'=>80,  'protein'=>17.5, 'carbs'=>0.0,  'fat'=>0.8,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.2, 'mercury'=>'low'      ],
            [ 'id'=>32, 'name'=>'Cockles',                  'category'=>'Shellfish',        'calories'=>53,  'protein'=>11.2, 'carbs'=>0.0,  'fat'=>0.6,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.2, 'mercury'=>'low'      ],
            [ 'id'=>33, 'name'=>'Whelks',                   'category'=>'Shellfish',        'calories'=>70,  'protein'=>14.3, 'carbs'=>0.0,  'fat'=>1.2,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.1, 'mercury'=>'low'      ],
            // ── Squid & Octopus ──────────────────────────────────────────────
            [ 'id'=>34, 'name'=>'Squid / Calamari',          'category'=>'Squid & Octopus',  'calories'=>92,  'protein'=>15.6, 'carbs'=>3.1,  'fat'=>1.4,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.5, 'mercury'=>'low'      ],
            [ 'id'=>35, 'name'=>'Calamari (Fried)',          'category'=>'Squid & Octopus',  'calories'=>175, 'protein'=>15.3, 'carbs'=>12.0, 'fat'=>7.7,  'fiber'=>0.3, 'sugar'=>0.0, 'omega3'=>0.3, 'mercury'=>'low'      ],
            [ 'id'=>36, 'name'=>'Octopus',                  'category'=>'Squid & Octopus',  'calories'=>82,  'protein'=>17.4, 'carbs'=>0.0,  'fat'=>1.0,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.3, 'mercury'=>'low'      ],
            // ── Canned & Smoked ──────────────────────────────────────────────
            [ 'id'=>37, 'name'=>'Tuna (Canned, Water)',       'category'=>'Canned & Smoked',  'calories'=>109, 'protein'=>25.5, 'carbs'=>0.0,  'fat'=>0.5,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.3, 'mercury'=>'moderate' ],
            [ 'id'=>38, 'name'=>'Tuna (Canned, Oil)',        'category'=>'Canned & Smoked',  'calories'=>189, 'protein'=>25.1, 'carbs'=>0.0,  'fat'=>9.9,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.3, 'mercury'=>'moderate' ],
            [ 'id'=>39, 'name'=>'Salmon (Canned)',           'category'=>'Canned & Smoked',  'calories'=>153, 'protein'=>19.8, 'carbs'=>0.0,  'fat'=>7.8,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>1.8, 'mercury'=>'low'      ],
            [ 'id'=>40, 'name'=>'Sardines (Canned)',         'category'=>'Canned & Smoked',  'calories'=>208, 'protein'=>24.6, 'carbs'=>0.0,  'fat'=>11.5, 'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>1.5, 'mercury'=>'low'      ],
            [ 'id'=>41, 'name'=>'Sardines (Tomato)',         'category'=>'Canned & Smoked',  'calories'=>162, 'protein'=>17.8, 'carbs'=>1.7,  'fat'=>9.9,  'fiber'=>0.0, 'sugar'=>0.5, 'omega3'=>1.3, 'mercury'=>'low'      ],
            [ 'id'=>42, 'name'=>'Anchovies (Canned)',        'category'=>'Canned & Smoked',  'calories'=>210, 'protein'=>29.0, 'carbs'=>0.0,  'fat'=>9.7,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>1.5, 'mercury'=>'low'      ],
            [ 'id'=>43, 'name'=>'Mackerel (Canned)',         'category'=>'Canned & Smoked',  'calories'=>185, 'protein'=>20.0, 'carbs'=>0.0,  'fat'=>11.5, 'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>2.2, 'mercury'=>'low'      ],
            [ 'id'=>44, 'name'=>'Smoked Salmon',             'category'=>'Canned & Smoked',  'calories'=>142, 'protein'=>23.5, 'carbs'=>0.0,  'fat'=>5.4,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>2.0, 'mercury'=>'low'      ],
            [ 'id'=>45, 'name'=>'Smoked Haddock',            'category'=>'Canned & Smoked',  'calories'=>101, 'protein'=>23.3, 'carbs'=>0.0,  'fat'=>0.9,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.2, 'mercury'=>'low'      ],
            [ 'id'=>46, 'name'=>'Smoked Mackerel',           'category'=>'Canned & Smoked',  'calories'=>305, 'protein'=>18.9, 'carbs'=>0.0,  'fat'=>25.9, 'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>2.3, 'mercury'=>'low'      ],
            [ 'id'=>47, 'name'=>'Smoked Trout',              'category'=>'Canned & Smoked',  'calories'=>135, 'protein'=>23.0, 'carbs'=>0.0,  'fat'=>4.5,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>1.8, 'mercury'=>'low'      ],
            [ 'id'=>48, 'name'=>'Rollmops',                  'category'=>'Canned & Smoked',  'calories'=>128, 'protein'=>12.0, 'carbs'=>2.5,  'fat'=>8.0,  'fiber'=>0.0, 'sugar'=>0.5, 'omega3'=>1.5, 'mercury'=>'low'      ],
            [ 'id'=>49, 'name'=>'Gravlax',                   'category'=>'Canned & Smoked',  'calories'=>146, 'protein'=>20.5, 'carbs'=>1.4,  'fat'=>6.6,  'fiber'=>0.0, 'sugar'=>1.2, 'omega3'=>2.1, 'mercury'=>'low'      ],
            // ── Roe & Caviar ─────────────────────────────────────────────────
            [ 'id'=>50, 'name'=>'Cod Roe',                   'category'=>'Roe & Caviar',     'calories'=>130, 'protein'=>21.5, 'carbs'=>3.0,  'fat'=>3.5,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.7, 'mercury'=>'low'      ],
            [ 'id'=>51, 'name'=>'Salmon Roe (Ikura)',        'category'=>'Roe & Caviar',     'calories'=>251, 'protein'=>29.0, 'carbs'=>1.5,  'fat'=>14.0, 'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>3.6, 'mercury'=>'low'      ],
            [ 'id'=>52, 'name'=>'Caviar (black / sturgeon)', 'category'=>'Roe & Caviar',     'calories'=>264, 'protein'=>24.6, 'carbs'=>3.8,  'fat'=>17.9, 'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>6.8, 'mercury'=>'low'      ],
            [ 'id'=>53, 'name'=>'Taramasalata',              'category'=>'Roe & Caviar',     'calories'=>478, 'protein'=>3.8,  'carbs'=>6.9,  'fat'=>48.6, 'fiber'=>0.3, 'sugar'=>1.5, 'omega3'=>0.4, 'mercury'=>'low'      ],
            // ── Prepared Seafood ─────────────────────────────────────────────
            [ 'id'=>54, 'name'=>'Battered Cod',               'category'=>'Prepared Seafood', 'calories'=>247, 'protein'=>15.8, 'carbs'=>20.4, 'fat'=>11.4, 'fiber'=>0.8, 'sugar'=>0.5, 'omega3'=>0.1, 'mercury'=>'low'      ],
            [ 'id'=>55, 'name'=>'Fish Cakes',               'category'=>'Prepared Seafood', 'calories'=>175, 'protein'=>9.7,  'carbs'=>14.7, 'fat'=>9.0,  'fiber'=>1.2, 'sugar'=>0.8, 'omega3'=>0.2, 'mercury'=>'low'      ],
            [ 'id'=>56, 'name'=>'Fish Fingers',             'category'=>'Prepared Seafood', 'calories'=>233, 'protein'=>12.5, 'carbs'=>21.4, 'fat'=>10.7, 'fiber'=>0.8, 'sugar'=>1.2, 'omega3'=>0.1, 'mercury'=>'low'      ],
            [ 'id'=>57, 'name'=>'Breaded Scampi',           'category'=>'Prepared Seafood', 'calories'=>237, 'protein'=>9.5,  'carbs'=>23.2, 'fat'=>12.7, 'fiber'=>0.9, 'sugar'=>1.0, 'omega3'=>0.1, 'mercury'=>'low'      ],
            [ 'id'=>58, 'name'=>'Prawn Toast',               'category'=>'Prepared Seafood', 'calories'=>238, 'protein'=>10.0, 'carbs'=>22.6, 'fat'=>12.8, 'fiber'=>1.0, 'sugar'=>1.5, 'omega3'=>0.1, 'mercury'=>'low'      ],
            // ── Seaweed ──────────────────────────────────────────────────────
            [ 'id'=>59, 'name'=>'Nori (dried seaweed)',      'category'=>'Seaweed',          'calories'=>35,  'protein'=>5.8,  'carbs'=>5.0,  'fat'=>0.3,  'fiber'=>0.5, 'sugar'=>0.0, 'omega3'=>0.1, 'mercury'=>'low'      ],
            [ 'id'=>60, 'name'=>'Wakame (raw seaweed)',              'category'=>'Seaweed',          'calories'=>45,  'protein'=>3.0,  'carbs'=>9.1,  'fat'=>0.6,  'fiber'=>0.5, 'sugar'=>0.5, 'omega3'=>0.0, 'mercury'=>'low'      ],
            // ── White Fish (additional) ──────────────────────────────────────
            [ 'id'=>61, 'name'=>'Arctic Char',                      'category'=>'Oily Fish',        'calories'=>155, 'protein'=>21.3, 'carbs'=>0.0,  'fat'=>7.5,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>1.8, 'mercury'=>'low'      ],
            [ 'id'=>62, 'name'=>'Brill',                           'category'=>'White Fish',       'calories'=>95,  'protein'=>17.6, 'carbs'=>0.0,  'fat'=>2.2,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.4, 'mercury'=>'low'      ],
            [ 'id'=>63, 'name'=>'Barramundi',                      'category'=>'White Fish',       'calories'=>105, 'protein'=>20.1, 'carbs'=>0.0,  'fat'=>2.6,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.7, 'mercury'=>'low'      ],
            [ 'id'=>64, 'name'=>'Carp',                            'category'=>'White Fish',       'calories'=>162, 'protein'=>17.8, 'carbs'=>0.0,  'fat'=>7.2,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.8, 'mercury'=>'low'      ],
            [ 'id'=>65, 'name'=>'Cod Cheeks',                      'category'=>'White Fish',       'calories'=>75,  'protein'=>16.5, 'carbs'=>0.0,  'fat'=>0.6,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.2, 'mercury'=>'low'      ],
            [ 'id'=>66, 'name'=>'Cod Tongue',                      'category'=>'White Fish',       'calories'=>70,  'protein'=>15.5, 'carbs'=>0.2,  'fat'=>0.4,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.1, 'mercury'=>'low'      ],
            [ 'id'=>67, 'name'=>'Coley / Saithe',                  'category'=>'White Fish',       'calories'=>85,  'protein'=>19.0, 'carbs'=>0.0,  'fat'=>0.6,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.3, 'mercury'=>'low'      ],
            [ 'id'=>68, 'name'=>'Cobia',                           'category'=>'White Fish',       'calories'=>105, 'protein'=>19.9, 'carbs'=>0.0,  'fat'=>2.5,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.6, 'mercury'=>'moderate' ],
            [ 'id'=>69, 'name'=>'Dabs',                            'category'=>'White Fish',       'calories'=>76,  'protein'=>16.5, 'carbs'=>0.0,  'fat'=>0.8,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.3, 'mercury'=>'low'      ],
            [ 'id'=>70, 'name'=>'Dogfish / Rock Salmon',           'category'=>'White Fish',       'calories'=>130, 'protein'=>21.9, 'carbs'=>0.0,  'fat'=>4.7,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.5, 'mercury'=>'moderate' ],
            [ 'id'=>71, 'name'=>'Dorade / Wild Pink Sea Bream',     'category'=>'White Fish',       'calories'=>100, 'protein'=>20.0, 'carbs'=>0.0,  'fat'=>2.0,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.7, 'mercury'=>'low'      ],
            [ 'id'=>72, 'name'=>'Conger Eel',                       'category'=>'Oily Fish',        'calories'=>132, 'protein'=>18.5, 'carbs'=>0.0,  'fat'=>6.7,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.9, 'mercury'=>'moderate' ],
            [ 'id'=>73, 'name'=>'Eels',                            'category'=>'Oily Fish',        'calories'=>184, 'protein'=>18.4, 'carbs'=>0.0,  'fat'=>11.7, 'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>1.2, 'mercury'=>'moderate' ],
            [ 'id'=>74, 'name'=>'Cuttlefish',                      'category'=>'Squid & Octopus',  'calories'=>79,  'protein'=>16.2, 'carbs'=>0.8,  'fat'=>0.7,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.4, 'mercury'=>'low'      ],
            [ 'id'=>75, 'name'=>'Fish Bones (edible, soft)',        'category'=>'Prepared Seafood', 'calories'=>140, 'protein'=>20.0, 'carbs'=>0.0,  'fat'=>5.5,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.3, 'mercury'=>'low'      ],
            [ 'id'=>76, 'name'=>'Fish Mix (Smoked Blend)',           'category'=>'Prepared Seafood', 'calories'=>130, 'protein'=>21.0, 'carbs'=>0.0,  'fat'=>5.5,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>1.2, 'mercury'=>'low'      ],
            [ 'id'=>77, 'name'=>'Fish Mix (Classic)',               'category'=>'Prepared Seafood', 'calories'=>120, 'protein'=>20.5, 'carbs'=>0.0,  'fat'=>4.0,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>1.0, 'mercury'=>'low'      ],
            [ 'id'=>78, 'name'=>'Fish Soup Mix (Bouillabaisse)',    'category'=>'Prepared Seafood', 'calories'=>85,  'protein'=>15.5, 'carbs'=>1.5,  'fat'=>2.5,  'fiber'=>0.0, 'sugar'=>0.3, 'omega3'=>0.5, 'mercury'=>'low'      ],
            // ── Additional UK Fish & Shellfish ───────────────────────────────
            [ 'id'=>79,  'name'=>'Turbot',                  'category'=>'White Fish',       'calories'=>95,  'protein'=>19.8, 'carbs'=>0.0,  'fat'=>2.7,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.4, 'mercury'=>'low'      ],
            [ 'id'=>80,  'name'=>'John Dory',               'category'=>'White Fish',       'calories'=>82,  'protein'=>17.5, 'carbs'=>0.0,  'fat'=>1.2,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.2, 'mercury'=>'low'      ],
            [ 'id'=>81,  'name'=>'Red Gurnard',             'category'=>'White Fish',       'calories'=>88,  'protein'=>19.0, 'carbs'=>0.0,  'fat'=>1.4,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.3, 'mercury'=>'low'      ],
            [ 'id'=>82,  'name'=>'Lemon Sole',              'category'=>'White Fish',       'calories'=>79,  'protein'=>17.2, 'carbs'=>0.0,  'fat'=>0.7,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.2, 'mercury'=>'low'      ],
            [ 'id'=>83,  'name'=>'Sea Trout',               'category'=>'Oily Fish',        'calories'=>168, 'protein'=>20.2, 'carbs'=>0.0,  'fat'=>9.1,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>1.8, 'mercury'=>'low'      ],
            [ 'id'=>84,  'name'=>'Swordfish',               'category'=>'Oily Fish',        'calories'=>121, 'protein'=>19.8, 'carbs'=>0.0,  'fat'=>4.0,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.6, 'mercury'=>'high'     ],
            [ 'id'=>85,  'name'=>'Brown Shrimp',            'category'=>'Shellfish',        'calories'=>98,  'protein'=>20.2, 'carbs'=>0.0,  'fat'=>1.5,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.4, 'mercury'=>'low'      ],
            [ 'id'=>86,  'name'=>'Spider Crab',             'category'=>'Shellfish',        'calories'=>87,  'protein'=>16.8, 'carbs'=>0.0,  'fat'=>2.0,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.3, 'mercury'=>'low'      ],
            [ 'id'=>87,  'name'=>'Razor Clams',             'category'=>'Shellfish',        'calories'=>61,  'protein'=>11.2, 'carbs'=>2.5,  'fat'=>0.5,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.2, 'mercury'=>'low'      ],
            [ 'id'=>88,  'name'=>'Red Mullet',              'category'=>'Oily Fish',        'calories'=>117, 'protein'=>18.5, 'carbs'=>0.0,  'fat'=>4.8,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.8, 'mercury'=>'low'      ],
            [ 'id'=>89,  'name'=>'Periwinkles',             'category'=>'Shellfish',        'calories'=>91,  'protein'=>16.0, 'carbs'=>2.1,  'fat'=>1.4,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.1, 'mercury'=>'low'      ],
            [ 'id'=>90,  'name'=>'Bloater',                 'category'=>'Canned & Smoked',  'calories'=>218, 'protein'=>18.5, 'carbs'=>0.0,  'fat'=>15.8, 'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>1.4, 'mercury'=>'low'      ],
            // ── Additional Species (Batch V2) ────────────────────────────────────
            [ 'id'=>91,  'name'=>'Hake',                        'category'=>'White Fish',       'calories'=>86,  'protein'=>18.7, 'carbs'=>0.0, 'fat'=>1.3,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.4, 'mercury'=>'low'      ],
            [ 'id'=>92,  'name'=>'Red Snapper',                 'category'=>'White Fish',       'calories'=>100, 'protein'=>20.5, 'carbs'=>0.0, 'fat'=>1.3,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.3, 'mercury'=>'moderate' ],
            [ 'id'=>93,  'name'=>'Gurnards Ungraded',           'category'=>'White Fish',       'calories'=>88,  'protein'=>18.5, 'carbs'=>0.0, 'fat'=>1.5,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.3, 'mercury'=>'low'      ],
            [ 'id'=>94,  'name'=>'Grouper',                     'category'=>'White Fish',       'calories'=>92,  'protein'=>19.4, 'carbs'=>0.0, 'fat'=>1.0,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.2, 'mercury'=>'moderate' ],
            [ 'id'=>95,  'name'=>'Grey Mullet',                 'category'=>'Oily Fish',        'calories'=>145, 'protein'=>18.5, 'carbs'=>0.0, 'fat'=>8.0,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>1.0, 'mercury'=>'low'      ],
            [ 'id'=>96,  'name'=>'Hamachi',                     'category'=>'Oily Fish',        'calories'=>146, 'protein'=>20.7, 'carbs'=>0.0, 'fat'=>6.6,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>1.5, 'mercury'=>'moderate' ],
            [ 'id'=>97,  'name'=>'Kebab (Salmon, Ling + Cod)',  'category'=>'Prepared Seafood', 'calories'=>130, 'protein'=>20.0, 'carbs'=>0.0, 'fat'=>5.0,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>1.2, 'mercury'=>'low'      ],
            [ 'id'=>98,  'name'=>'Kingfish',                    'category'=>'Oily Fish',        'calories'=>134, 'protein'=>19.5, 'carbs'=>0.0, 'fat'=>6.0,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.9, 'mercury'=>'moderate' ],
            [ 'id'=>99,  'name'=>'Ling',                        'category'=>'White Fish',       'calories'=>83,  'protein'=>19.3, 'carbs'=>0.0, 'fat'=>0.8,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.2, 'mercury'=>'low'      ],
            [ 'id'=>100, 'name'=>'Mahi Mahi',                   'category'=>'White Fish',       'calories'=>109, 'protein'=>21.2, 'carbs'=>0.0, 'fat'=>2.7,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.3, 'mercury'=>'moderate' ],
            [ 'id'=>101, 'name'=>'Marlin',                      'category'=>'Oily Fish',        'calories'=>119, 'protein'=>21.3, 'carbs'=>0.0, 'fat'=>3.5,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.6, 'mercury'=>'high'     ],
            [ 'id'=>102, 'name'=>'Megrim',                      'category'=>'White Fish',       'calories'=>78,  'protein'=>16.5, 'carbs'=>0.0, 'fat'=>1.0,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.2, 'mercury'=>'low'      ],
            [ 'id'=>103, 'name'=>'Monkfish Tail',               'category'=>'White Fish',       'calories'=>76,  'protein'=>16.4, 'carbs'=>0.0, 'fat'=>0.8,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.1, 'mercury'=>'low'      ],
            [ 'id'=>104, 'name'=>'Monkfish Cheeks',             'category'=>'White Fish',       'calories'=>72,  'protein'=>15.5, 'carbs'=>0.2, 'fat'=>0.6,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.1, 'mercury'=>'low'      ],
            [ 'id'=>105, 'name'=>'Monkfish Livers',             'category'=>'White Fish',       'calories'=>165, 'protein'=>14.0, 'carbs'=>2.5, 'fat'=>10.5, 'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.8, 'mercury'=>'low'      ],
            [ 'id'=>106, 'name'=>'Nile Perch',                  'category'=>'White Fish',       'calories'=>97,  'protein'=>17.7, 'carbs'=>0.0, 'fat'=>2.7,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.3, 'mercury'=>'moderate' ],
            [ 'id'=>107, 'name'=>'Octopus (Mediterranean)',     'category'=>'Squid & Octopus',  'calories'=>82,  'protein'=>17.4, 'carbs'=>0.0, 'fat'=>1.0,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.3, 'mercury'=>'low'      ],
            [ 'id'=>108, 'name'=>'Octopus (U.K.)',              'category'=>'Squid & Octopus',  'calories'=>80,  'protein'=>16.5, 'carbs'=>0.5, 'fat'=>1.2,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.3, 'mercury'=>'low'      ],
            [ 'id'=>109, 'name'=>'Parrot Fish',                 'category'=>'White Fish',       'calories'=>92,  'protein'=>18.0, 'carbs'=>0.0, 'fat'=>1.5,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.2, 'mercury'=>'moderate' ],
            [ 'id'=>110, 'name'=>'Pomfret',                     'category'=>'White Fish',       'calories'=>115, 'protein'=>19.5, 'carbs'=>0.0, 'fat'=>3.5,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.5, 'mercury'=>'moderate' ],
            [ 'id'=>111, 'name'=>'Pike',                        'category'=>'White Fish',       'calories'=>88,  'protein'=>19.3, 'carbs'=>0.0, 'fat'=>0.9,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.1, 'mercury'=>'low'      ],
            [ 'id'=>112, 'name'=>'Redfish',                     'category'=>'Oily Fish',        'calories'=>94,  'protein'=>18.2, 'carbs'=>0.0, 'fat'=>1.7,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.5, 'mercury'=>'low'      ],
            [ 'id'=>113, 'name'=>'Sand Soles Ungraded',         'category'=>'White Fish',       'calories'=>80,  'protein'=>17.5, 'carbs'=>0.0, 'fat'=>0.7,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.2, 'mercury'=>'low'      ],
            [ 'id'=>114, 'name'=>'Sailfish',                    'category'=>'Oily Fish',        'calories'=>98,  'protein'=>21.0, 'carbs'=>0.0, 'fat'=>1.2,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.4, 'mercury'=>'high'     ],
            [ 'id'=>115, 'name'=>'Salmon Head',                 'category'=>'Oily Fish',        'calories'=>160, 'protein'=>17.0, 'carbs'=>0.0, 'fat'=>9.5,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>2.0, 'mercury'=>'low'      ],
            [ 'id'=>116, 'name'=>'Wild Salmon',                 'category'=>'Oily Fish',        'calories'=>182, 'protein'=>22.0, 'carbs'=>0.0, 'fat'=>10.5, 'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>2.5, 'mercury'=>'low'      ],
            [ 'id'=>117, 'name'=>'Sea Reared Trout',            'category'=>'Oily Fish',        'calories'=>172, 'protein'=>21.5, 'carbs'=>0.0, 'fat'=>9.5,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>2.2, 'mercury'=>'low'      ],
            // ── Additional Species (Batch V3) ────────────────────────────────────
            [ 'id'=>118, 'name'=>'Sea Cucumber',               'category'=>'Shellfish',        'calories'=>39,  'protein'=>9.0,  'carbs'=>0.4, 'fat'=>0.2,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.0, 'mercury'=>'low'      ],
            [ 'id'=>119, 'name'=>'Shark Steaks',               'category'=>'Oily Fish',        'calories'=>130, 'protein'=>21.0, 'carbs'=>0.0, 'fat'=>4.5,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.6, 'mercury'=>'high'     ],
            [ 'id'=>120, 'name'=>'Tope Shark',                 'category'=>'White Fish',       'calories'=>130, 'protein'=>20.5, 'carbs'=>0.0, 'fat'=>4.8,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.5, 'mercury'=>'high'     ],
            [ 'id'=>121, 'name'=>'Skate Knobs (Eyes)',         'category'=>'White Fish',       'calories'=>80,  'protein'=>17.5, 'carbs'=>0.0, 'fat'=>0.8,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.3, 'mercury'=>'moderate' ],
            [ 'id'=>122, 'name'=>'Squid (Whole)',              'category'=>'Squid & Octopus',  'calories'=>92,  'protein'=>15.6, 'carbs'=>3.1, 'fat'=>1.4,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.5, 'mercury'=>'low'      ],
            [ 'id'=>123, 'name'=>'Sturgeon',                   'category'=>'Oily Fish',        'calories'=>135, 'protein'=>16.1, 'carbs'=>0.0, 'fat'=>6.7,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.5, 'mercury'=>'low'      ],
            [ 'id'=>124, 'name'=>'Stone Bass (Meagre)',        'category'=>'White Fish',       'calories'=>82,  'protein'=>17.5, 'carbs'=>0.0, 'fat'=>1.2,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.4, 'mercury'=>'low'      ],
            [ 'id'=>125, 'name'=>'Tilapia (Black)',            'category'=>'White Fish',       'calories'=>96,  'protein'=>20.1, 'carbs'=>0.0, 'fat'=>1.7,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.1, 'mercury'=>'low'      ],
            [ 'id'=>126, 'name'=>'Tilapia (Red)',              'category'=>'White Fish',       'calories'=>97,  'protein'=>20.0, 'carbs'=>0.0, 'fat'=>1.8,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.1, 'mercury'=>'low'      ],
            [ 'id'=>127, 'name'=>'Brown Trout',                'category'=>'Oily Fish',        'calories'=>158, 'protein'=>20.8, 'carbs'=>0.0, 'fat'=>8.1,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>1.5, 'mercury'=>'low'      ],
            [ 'id'=>128, 'name'=>'Tuna Toro',                  'category'=>'Oily Fish',        'calories'=>344, 'protein'=>20.0, 'carbs'=>0.0, 'fat'=>28.0, 'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>4.2, 'mercury'=>'high'     ],
            [ 'id'=>129, 'name'=>'Witch Sole (Torbay)',        'category'=>'White Fish',       'calories'=>76,  'protein'=>16.8, 'carbs'=>0.0, 'fat'=>0.9,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.2, 'mercury'=>'low'      ],
            [ 'id'=>130, 'name'=>'Zander',                     'category'=>'White Fish',       'calories'=>84,  'protein'=>19.2, 'carbs'=>0.0, 'fat'=>0.6,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.2, 'mercury'=>'low'      ],
            // ── Additional Species (Batch V4) ────────────────────────────────────
            [ 'id'=>131, 'name'=>'Clams (Amandes)',            'category'=>'Shellfish',        'calories'=>71,  'protein'=>12.5, 'carbs'=>2.8, 'fat'=>0.9,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.2, 'mercury'=>'low'      ],
            [ 'id'=>132, 'name'=>'Clams (Palourdes)',          'category'=>'Shellfish',        'calories'=>70,  'protein'=>12.0, 'carbs'=>2.5, 'fat'=>0.8,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.2, 'mercury'=>'low'      ],
            [ 'id'=>133, 'name'=>'Clams (Venus/Surf)',         'category'=>'Shellfish',        'calories'=>74,  'protein'=>12.8, 'carbs'=>2.6, 'fat'=>1.0,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.2, 'mercury'=>'low'      ],
            [ 'id'=>134, 'name'=>'Crab Claws',                 'category'=>'Shellfish',        'calories'=>90,  'protein'=>19.0, 'carbs'=>0.0, 'fat'=>1.5,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.4, 'mercury'=>'low'      ],
            [ 'id'=>135, 'name'=>'Crab Meat (Brown)',          'category'=>'Shellfish',        'calories'=>127, 'protein'=>16.5, 'carbs'=>0.0, 'fat'=>7.2,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>1.0, 'mercury'=>'low'      ],
            [ 'id'=>136, 'name'=>'Crab Meat (White)',          'category'=>'Shellfish',        'calories'=>99,  'protein'=>22.0, 'carbs'=>0.0, 'fat'=>0.9,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.3, 'mercury'=>'low'      ],
            [ 'id'=>137, 'name'=>'Crab Meat (Claw)',           'category'=>'Shellfish',        'calories'=>105, 'protein'=>20.0, 'carbs'=>0.0, 'fat'=>2.5,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.5, 'mercury'=>'low'      ],
            [ 'id'=>138, 'name'=>'Crab Meat (Backfin)',        'category'=>'Shellfish',        'calories'=>102, 'protein'=>21.5, 'carbs'=>0.0, 'fat'=>1.8,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.4, 'mercury'=>'low'      ],
            [ 'id'=>139, 'name'=>'Velvet Crab',                'category'=>'Shellfish',        'calories'=>80,  'protein'=>17.5, 'carbs'=>0.0, 'fat'=>0.8,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.2, 'mercury'=>'low'      ],
            [ 'id'=>140, 'name'=>'Cockles (in Shell, Live)',   'category'=>'Shellfish',        'calories'=>22,  'protein'=>4.5,  'carbs'=>0.0, 'fat'=>0.2,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.1, 'mercury'=>'low'      ],
            [ 'id'=>141, 'name'=>'Crayfish (English)',         'category'=>'Shellfish',        'calories'=>72,  'protein'=>14.8, 'carbs'=>0.3, 'fat'=>0.9,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.2, 'mercury'=>'low'      ],
            [ 'id'=>142, 'name'=>'Crayfish (Import)',          'category'=>'Shellfish',        'calories'=>72,  'protein'=>14.8, 'carbs'=>0.3, 'fat'=>0.9,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.2, 'mercury'=>'low'      ],
            [ 'id'=>143, 'name'=>'Lobster (Native)',           'category'=>'Shellfish',        'calories'=>95,  'protein'=>20.0, 'carbs'=>0.5, 'fat'=>0.9,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.2, 'mercury'=>'low'      ],
            [ 'id'=>144, 'name'=>'Spiny Lobster',              'category'=>'Shellfish',        'calories'=>112, 'protein'=>20.6, 'carbs'=>1.3, 'fat'=>1.5,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.2, 'mercury'=>'low'      ],
            [ 'id'=>145, 'name'=>'Oysters (Native)',           'category'=>'Shellfish',        'calories'=>65,  'protein'=>7.5,  'carbs'=>4.2, 'fat'=>1.8,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.5, 'mercury'=>'low'      ],
            [ 'id'=>146, 'name'=>'Sea Urchin',                 'category'=>'Roe & Caviar',     'calories'=>119, 'protein'=>13.0, 'carbs'=>3.4, 'fat'=>3.7,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.5, 'mercury'=>'low'      ],
            [ 'id'=>147, 'name'=>'Sea Lettuce',                'category'=>'Seaweed',          'calories'=>31,  'protein'=>2.7,  'carbs'=>4.8, 'fat'=>0.3,  'fiber'=>0.5, 'sugar'=>0.0, 'omega3'=>0.0, 'mercury'=>'low'      ],
            [ 'id'=>148, 'name'=>'Sea Spaghetti',              'category'=>'Seaweed',          'calories'=>35,  'protein'=>2.0,  'carbs'=>6.5, 'fat'=>0.2,  'fiber'=>1.0, 'sugar'=>0.0, 'omega3'=>0.1, 'mercury'=>'low'      ],
            [ 'id'=>149, 'name'=>'Dulse',                      'category'=>'Seaweed',          'calories'=>44,  'protein'=>6.0,  'carbs'=>5.8, 'fat'=>0.3,  'fiber'=>1.3, 'sugar'=>0.0, 'omega3'=>0.1, 'mercury'=>'low'      ],
            [ 'id'=>150, 'name'=>'Kombu',                      'category'=>'Seaweed',          'calories'=>43,  'protein'=>1.7,  'carbs'=>9.6, 'fat'=>0.6,  'fiber'=>1.3, 'sugar'=>0.5, 'omega3'=>0.0, 'mercury'=>'low'      ],
            [ 'id'=>151, 'name'=>'Scampi Tails',               'category'=>'Shellfish',        'calories'=>78,  'protein'=>17.0, 'carbs'=>0.0, 'fat'=>0.7,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.2, 'mercury'=>'low'      ],
            [ 'id'=>152, 'name'=>'Scallops (King, Roe On)',    'category'=>'Shellfish',        'calories'=>118, 'protein'=>18.5, 'carbs'=>3.4, 'fat'=>2.5,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.4, 'mercury'=>'low'      ],
            [ 'id'=>153, 'name'=>'Tiger Prawns',               'category'=>'Shellfish',        'calories'=>87,  'protein'=>20.3, 'carbs'=>0.0, 'fat'=>0.4,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.2, 'mercury'=>'low'      ],
            // ── Additional Species (Batch V5) ────────────────────────────────────
            [ 'id'=>154, 'name'=>'Arbroath Smokies',           'category'=>'Canned & Smoked',  'calories'=>175, 'protein'=>26.0, 'carbs'=>0.0, 'fat'=>7.5,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.3, 'mercury'=>'low'      ],
            [ 'id'=>155, 'name'=>'Buckling',                   'category'=>'Canned & Smoked',  'calories'=>280, 'protein'=>20.0, 'carbs'=>0.0, 'fat'=>22.5, 'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>2.8, 'mercury'=>'low'      ],
            [ 'id'=>156, 'name'=>'Smoked Cod Roe (Natural)',   'category'=>'Roe & Caviar',     'calories'=>155, 'protein'=>24.0, 'carbs'=>2.0, 'fat'=>5.5,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.8, 'mercury'=>'low'      ],
            [ 'id'=>157, 'name'=>'Smoked Cod',                 'category'=>'Canned & Smoked',  'calories'=>95,  'protein'=>22.0, 'carbs'=>0.0, 'fat'=>0.8,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.2, 'mercury'=>'low'      ],
            [ 'id'=>158, 'name'=>'Cured Salmon Trio',          'category'=>'Canned & Smoked',  'calories'=>165, 'protein'=>22.0, 'carbs'=>0.5, 'fat'=>8.5,  'fiber'=>0.0, 'sugar'=>0.5, 'omega3'=>2.2, 'mercury'=>'low'      ],
            [ 'id'=>159, 'name'=>'Gravadlax (Beetroot)',       'category'=>'Canned & Smoked',  'calories'=>150, 'protein'=>20.0, 'carbs'=>2.0, 'fat'=>6.8,  'fiber'=>0.0, 'sugar'=>1.5, 'omega3'=>2.0, 'mercury'=>'low'      ],
            [ 'id'=>160, 'name'=>'Hot Roast Salmon',           'category'=>'Canned & Smoked',  'calories'=>200, 'protein'=>22.5, 'carbs'=>0.0, 'fat'=>12.0, 'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>2.1, 'mercury'=>'low'      ],
            [ 'id'=>161, 'name'=>'Kipper',                     'category'=>'Canned & Smoked',  'calories'=>217, 'protein'=>20.5, 'carbs'=>0.0, 'fat'=>15.2, 'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>2.0, 'mercury'=>'low'      ],
            [ 'id'=>162, 'name'=>'Bottarga',                   'category'=>'Roe & Caviar',     'calories'=>315, 'protein'=>36.0, 'carbs'=>0.0, 'fat'=>18.5, 'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>2.5, 'mercury'=>'moderate' ],
            [ 'id'=>163, 'name'=>'Avruga / Arenkha',           'category'=>'Roe & Caviar',     'calories'=>180, 'protein'=>20.0, 'carbs'=>4.5, 'fat'=>8.5,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>3.2, 'mercury'=>'low'      ],
            [ 'id'=>164, 'name'=>'Crayfish Tails (in Brine)',  'category'=>'Shellfish',        'calories'=>68,  'protein'=>14.0, 'carbs'=>0.3, 'fat'=>0.8,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.1, 'mercury'=>'low'      ],
            [ 'id'=>165, 'name'=>'Caviar (Beluga)',            'category'=>'Roe & Caviar',     'calories'=>264, 'protein'=>24.6, 'carbs'=>3.8, 'fat'=>17.9, 'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>6.8, 'mercury'=>'low'      ],
            [ 'id'=>166, 'name'=>'Caviar (Oscietra)',          'category'=>'Roe & Caviar',     'calories'=>258, 'protein'=>24.0, 'carbs'=>4.0, 'fat'=>16.5, 'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>6.2, 'mercury'=>'low'      ],
            [ 'id'=>167, 'name'=>'Caviar (Sevruga)',           'category'=>'Roe & Caviar',     'calories'=>255, 'protein'=>25.0, 'carbs'=>3.5, 'fat'=>16.0, 'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>5.8, 'mercury'=>'low'      ],
            [ 'id'=>168, 'name'=>'Jellied Eels',               'category'=>'Prepared Seafood', 'calories'=>98,  'protein'=>10.5, 'carbs'=>0.0, 'fat'=>5.5,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.8, 'mercury'=>'moderate' ],
            [ 'id'=>169, 'name'=>'Lumpfish Roe (Black)',       'category'=>'Roe & Caviar',     'calories'=>122, 'protein'=>12.5, 'carbs'=>1.8, 'fat'=>7.0,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>1.2, 'mercury'=>'low'      ],
            [ 'id'=>170, 'name'=>'Lumpfish Roe (Red)',         'category'=>'Roe & Caviar',     'calories'=>122, 'protein'=>12.5, 'carbs'=>1.8, 'fat'=>7.0,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>1.2, 'mercury'=>'low'      ],
            [ 'id'=>171, 'name'=>'Octopus Salad (in Oil)',     'category'=>'Squid & Octopus',  'calories'=>150, 'protein'=>14.5, 'carbs'=>2.0, 'fat'=>9.5,  'fiber'=>0.0, 'sugar'=>0.5, 'omega3'=>0.5, 'mercury'=>'low'      ],
            [ 'id'=>172, 'name'=>'Squid Ink',                  'category'=>'Squid & Octopus',  'calories'=>30,  'protein'=>3.5,  'carbs'=>1.5, 'fat'=>1.0,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.1, 'mercury'=>'low'      ],
            [ 'id'=>173, 'name'=>'Samphire (Farmed)',          'category'=>'Seaweed',          'calories'=>25,  'protein'=>1.5,  'carbs'=>3.0, 'fat'=>0.5,  'fiber'=>1.2, 'sugar'=>0.0, 'omega3'=>0.0, 'mercury'=>'low'      ],
            [ 'id'=>174, 'name'=>'Samphire (Wild)',            'category'=>'Seaweed',          'calories'=>25,  'protein'=>1.5,  'carbs'=>3.0, 'fat'=>0.5,  'fiber'=>1.2, 'sugar'=>0.0, 'omega3'=>0.0, 'mercury'=>'low'      ],
            [ 'id'=>175, 'name'=>'Sea Purslane',               'category'=>'Seaweed',          'calories'=>20,  'protein'=>1.7,  'carbs'=>2.5, 'fat'=>0.4,  'fiber'=>1.0, 'sugar'=>0.0, 'omega3'=>0.0, 'mercury'=>'low'      ],
            // ── Additional Species (Batch V6) ────────────────────────────────────
            [ 'id'=>176, 'name'=>'Fish Soup (Perard)',          'category'=>'Prepared Seafood', 'calories'=>45,  'protein'=>4.5,  'carbs'=>3.5, 'fat'=>1.5,  'fiber'=>0.5, 'sugar'=>1.0, 'omega3'=>0.3, 'mercury'=>'low'      ],
            [ 'id'=>177, 'name'=>'Crab Soup (Perard)',          'category'=>'Prepared Seafood', 'calories'=>62,  'protein'=>4.0,  'carbs'=>5.5, 'fat'=>2.5,  'fiber'=>0.2, 'sugar'=>1.5, 'omega3'=>0.2, 'mercury'=>'low'      ],
            [ 'id'=>178, 'name'=>'Lobster Soup (Perard)',       'category'=>'Prepared Seafood', 'calories'=>65,  'protein'=>3.5,  'carbs'=>6.0, 'fat'=>2.8,  'fiber'=>0.2, 'sugar'=>1.8, 'omega3'=>0.1, 'mercury'=>'low'      ],
            [ 'id'=>179, 'name'=>'Salmon Roe (Keta)',           'category'=>'Roe & Caviar',     'calories'=>243, 'protein'=>26.5, 'carbs'=>1.8, 'fat'=>14.0, 'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>3.4, 'mercury'=>'low'      ],
            [ 'id'=>180, 'name'=>'Seafood Salad (in Oil)',      'category'=>'Prepared Seafood', 'calories'=>140, 'protein'=>13.0, 'carbs'=>2.5, 'fat'=>8.5,  'fiber'=>0.0, 'sugar'=>0.5, 'omega3'=>0.5, 'mercury'=>'low'      ],
            [ 'id'=>181, 'name'=>'Sweet Cure Herring',          'category'=>'Canned & Smoked',  'calories'=>185, 'protein'=>16.5, 'carbs'=>3.5, 'fat'=>11.5, 'fiber'=>0.0, 'sugar'=>2.5, 'omega3'=>2.0, 'mercury'=>'low'      ],
            [ 'id'=>182, 'name'=>'Tobiko (Wasabi/Green)',       'category'=>'Roe & Caviar',     'calories'=>110, 'protein'=>12.5, 'carbs'=>2.8, 'fat'=>4.5,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>2.5, 'mercury'=>'low'      ],
            [ 'id'=>183, 'name'=>'Tobiko (Orange)',             'category'=>'Roe & Caviar',     'calories'=>110, 'protein'=>12.5, 'carbs'=>2.8, 'fat'=>4.5,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>2.5, 'mercury'=>'low'      ],
            [ 'id'=>184, 'name'=>'Tobiko (Yellow)',             'category'=>'Roe & Caviar',     'calories'=>110, 'protein'=>12.5, 'carbs'=>2.8, 'fat'=>4.5,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>2.5, 'mercury'=>'low'      ],
            [ 'id'=>185, 'name'=>'Tuna (Chunks)',               'category'=>'Oily Fish',        'calories'=>144, 'protein'=>30.5, 'carbs'=>0.0, 'fat'=>2.5,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>1.2, 'mercury'=>'moderate' ],
            [ 'id'=>186, 'name'=>'Terrine (Salmon & Cream)',    'category'=>'Prepared Seafood', 'calories'=>195, 'protein'=>14.5, 'carbs'=>2.5, 'fat'=>14.5, 'fiber'=>0.2, 'sugar'=>0.8, 'omega3'=>1.5, 'mercury'=>'low'      ],
            [ 'id'=>187, 'name'=>'King Prawns (Seawater)',      'category'=>'Shellfish',        'calories'=>90,  'protein'=>20.3, 'carbs'=>0.0, 'fat'=>0.5,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.3, 'mercury'=>'low'      ],
            [ 'id'=>188, 'name'=>'Crevettes',                   'category'=>'Shellfish',        'calories'=>95,  'protein'=>21.5, 'carbs'=>0.0, 'fat'=>0.5,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.3, 'mercury'=>'low'      ],
            [ 'id'=>189, 'name'=>'Cocktail Prawns',             'category'=>'Shellfish',        'calories'=>80,  'protein'=>17.5, 'carbs'=>0.0, 'fat'=>0.8,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.2, 'mercury'=>'low'      ],
            [ 'id'=>190, 'name'=>'Prawns (Cooked, Tail On)',    'category'=>'Shellfish',        'calories'=>99,  'protein'=>24.0, 'carbs'=>0.2, 'fat'=>0.3,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.3, 'mercury'=>'low'      ],
            [ 'id'=>191, 'name'=>'Prawns (Raw, Wild)',          'category'=>'Shellfish',        'calories'=>85,  'protein'=>18.5, 'carbs'=>0.0, 'fat'=>0.8,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.3, 'mercury'=>'low'      ],
            [ 'id'=>192, 'name'=>'Red Argentine Shrimps',       'category'=>'Shellfish',        'calories'=>88,  'protein'=>20.0, 'carbs'=>0.0, 'fat'=>0.5,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.4, 'mercury'=>'low'      ],
            [ 'id'=>193, 'name'=>'Langoustine (Whole, Raw)',    'category'=>'Shellfish',        'calories'=>76,  'protein'=>16.5, 'carbs'=>0.0, 'fat'=>0.7,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.2, 'mercury'=>'low'      ],
            [ 'id'=>194, 'name'=>'Soft Shell Crab',             'category'=>'Shellfish',        'calories'=>85,  'protein'=>15.0, 'carbs'=>0.5, 'fat'=>1.5,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.3, 'mercury'=>'low'      ],
            [ 'id'=>195, 'name'=>'King Crab (Clusters)',        'category'=>'Shellfish',        'calories'=>84,  'protein'=>18.3, 'carbs'=>0.0, 'fat'=>0.8,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.4, 'mercury'=>'low'      ],
            [ 'id'=>196, 'name'=>'Squid Tubes',                 'category'=>'Squid & Octopus',  'calories'=>90,  'protein'=>15.3, 'carbs'=>2.8, 'fat'=>1.3,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.5, 'mercury'=>'low'      ],
            [ 'id'=>197, 'name'=>'Baby Squid',                  'category'=>'Squid & Octopus',  'calories'=>88,  'protein'=>15.0, 'carbs'=>2.9, 'fat'=>1.3,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.5, 'mercury'=>'low'      ],
            [ 'id'=>198, 'name'=>'Baby Squid (Chipirones)',     'category'=>'Squid & Octopus',  'calories'=>88,  'protein'=>15.0, 'carbs'=>2.9, 'fat'=>1.3,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.5, 'mercury'=>'low'      ],
            [ 'id'=>199, 'name'=>'Baby Cuttlefish',             'category'=>'Squid & Octopus',  'calories'=>72,  'protein'=>14.8, 'carbs'=>0.8, 'fat'=>0.6,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.4, 'mercury'=>'low'      ],
            [ 'id'=>200, 'name'=>'Baby Octopus',                'category'=>'Squid & Octopus',  'calories'=>76,  'protein'=>16.5, 'carbs'=>0.0, 'fat'=>0.8,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.3, 'mercury'=>'low'      ],
            [ 'id'=>201, 'name'=>'Black Cod (Sablefish)',       'category'=>'Oily Fish',        'calories'=>250, 'protein'=>13.0, 'carbs'=>0.0, 'fat'=>21.5, 'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>3.2, 'mercury'=>'low'      ],
            [ 'id'=>202, 'name'=>'Chilean Sea Bass',            'category'=>'Oily Fish',        'calories'=>196, 'protein'=>19.0, 'carbs'=>0.0, 'fat'=>13.2, 'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>2.2, 'mercury'=>'moderate' ],

            // ── Additional Species (Batch V7)
            [ 'id'=>203, 'name'=>'Pangasius (Basa)',            'category'=>'White Fish',       'calories'=>90,  'protein'=>15.5, 'carbs'=>0.0, 'fat'=>3.5,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.1, 'mercury'=>'low'      ],
            [ 'id'=>204, 'name'=>'Whitebait (Blanched)',        'category'=>'White Fish',       'calories'=>95,  'protein'=>16.5, 'carbs'=>0.5, 'fat'=>3.5,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>1.2, 'mercury'=>'low'      ],
            [ 'id'=>205, 'name'=>'Whitebait (Plain)',           'category'=>'White Fish',       'calories'=>90,  'protein'=>16.0, 'carbs'=>0.0, 'fat'=>3.0,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>1.0, 'mercury'=>'low'      ],
            [ 'id'=>206, 'name'=>'Bahamas Lobster Tails',       'category'=>'Shellfish',        'calories'=>90,  'protein'=>20.0, 'carbs'=>0.5, 'fat'=>0.8,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.3, 'mercury'=>'low'      ],
            [ 'id'=>207, 'name'=>'Tobiko (Black)',              'category'=>'Roe & Caviar',     'calories'=>110, 'protein'=>12.5, 'carbs'=>2.8, 'fat'=>4.5,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>2.5, 'mercury'=>'low'      ],
            [ 'id'=>208, 'name'=>'Masago (Orange)',             'category'=>'Roe & Caviar',     'calories'=>90,  'protein'=>11.5, 'carbs'=>3.8, 'fat'=>2.8,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>1.8, 'mercury'=>'low'      ],
            [ 'id'=>209, 'name'=>'Masago (Black)',              'category'=>'Roe & Caviar',     'calories'=>90,  'protein'=>11.5, 'carbs'=>3.8, 'fat'=>2.8,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>1.8, 'mercury'=>'low'      ],
            [ 'id'=>210, 'name'=>'Sushi Ebi',                  'category'=>'Shellfish',        'calories'=>85,  'protein'=>19.0, 'carbs'=>1.5, 'fat'=>0.5,  'fiber'=>0.0, 'sugar'=>1.0, 'omega3'=>0.2, 'mercury'=>'low'      ],
            [ 'id'=>211, 'name'=>'Snow Crab Meat',             'category'=>'Shellfish',        'calories'=>90,  'protein'=>18.5, 'carbs'=>0.0, 'fat'=>1.5,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.5, 'mercury'=>'low'      ],

            // ── Additional Species (Batch V8)
            [ 'id'=>212, 'name'=>'Pouting / Bib',              'category'=>'White Fish',       'calories'=>72,  'protein'=>16.0, 'carbs'=>0.0, 'fat'=>0.7,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.2, 'mercury'=>'low'      ],
            [ 'id'=>213, 'name'=>'Black Sea Bream',            'category'=>'White Fish',       'calories'=>95,  'protein'=>19.5, 'carbs'=>0.0, 'fat'=>2.2,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.5, 'mercury'=>'low'      ],
            [ 'id'=>214, 'name'=>'Garfish',                    'category'=>'White Fish',       'calories'=>98,  'protein'=>20.0, 'carbs'=>0.0, 'fat'=>2.5,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.7, 'mercury'=>'low'      ],
            [ 'id'=>215, 'name'=>'Smelt',                      'category'=>'White Fish',       'calories'=>97,  'protein'=>17.5, 'carbs'=>0.0, 'fat'=>3.2,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.8, 'mercury'=>'low'      ],
            [ 'id'=>216, 'name'=>'Pilchards (Cornish)',        'category'=>'Oily Fish',        'calories'=>192, 'protein'=>20.2, 'carbs'=>0.0, 'fat'=>12.8, 'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>2.0, 'mercury'=>'low'      ],
            [ 'id'=>217, 'name'=>'Albacore Tuna',              'category'=>'Oily Fish',        'calories'=>148, 'protein'=>26.0, 'carbs'=>0.0, 'fat'=>4.9,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>2.1, 'mercury'=>'moderate' ],
            [ 'id'=>218, 'name'=>'Yellowfin Tuna',             'category'=>'Oily Fish',        'calories'=>144, 'protein'=>29.3, 'carbs'=>0.0, 'fat'=>2.8,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.9, 'mercury'=>'moderate' ],
            [ 'id'=>219, 'name'=>'Wahoo',                      'category'=>'Oily Fish',        'calories'=>132, 'protein'=>22.2, 'carbs'=>0.0, 'fat'=>5.0,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>1.0, 'mercury'=>'moderate' ],
            [ 'id'=>220, 'name'=>'Scallops (Queen Meat)',      'category'=>'Shellfish',        'calories'=>88,  'protein'=>16.0, 'carbs'=>3.2, 'fat'=>0.8,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.2, 'mercury'=>'low'      ],
            [ 'id'=>221, 'name'=>'Mussel Meat (Shucked)',      'category'=>'Shellfish',        'calories'=>86,  'protein'=>11.9, 'carbs'=>3.7, 'fat'=>2.2,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.5, 'mercury'=>'low'      ],
            [ 'id'=>222, 'name'=>'Goose Barnacles (Percebes)', 'category'=>'Shellfish',        'calories'=>53,  'protein'=>10.5, 'carbs'=>1.2, 'fat'=>0.8,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.2, 'mercury'=>'low'      ],
            [ 'id'=>223, 'name'=>'Mantis Shrimp',              'category'=>'Shellfish',        'calories'=>90,  'protein'=>18.5, 'carbs'=>1.5, 'fat'=>1.0,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.5, 'mercury'=>'low'      ],
            [ 'id'=>224, 'name'=>'Smoked Eel',                 'category'=>'Canned & Smoked',  'calories'=>290, 'protein'=>18.5, 'carbs'=>0.0, 'fat'=>24.5, 'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>1.8, 'mercury'=>'moderate' ],
            [ 'id'=>225, 'name'=>'Smoked Sprats',              'category'=>'Canned & Smoked',  'calories'=>288, 'protein'=>18.5, 'carbs'=>0.0, 'fat'=>24.0, 'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>2.8, 'mercury'=>'low'      ],
            [ 'id'=>226, 'name'=>'Smoked Halibut',             'category'=>'Canned & Smoked',  'calories'=>150, 'protein'=>26.0, 'carbs'=>0.0, 'fat'=>4.8,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.8, 'mercury'=>'moderate' ],
            [ 'id'=>227, 'name'=>'Herring Roe (Soft)',         'category'=>'Roe & Caviar',     'calories'=>95,  'protein'=>16.5, 'carbs'=>2.5, 'fat'=>2.8,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>1.5, 'mercury'=>'low'      ],
            [ 'id'=>228, 'name'=>'Smoked Mackerel Pâté',      'category'=>'Prepared Seafood', 'calories'=>320, 'protein'=>12.5, 'carbs'=>2.0, 'fat'=>29.0, 'fiber'=>0.0, 'sugar'=>1.5, 'omega3'=>3.5, 'mercury'=>'low'      ],
            [ 'id'=>229, 'name'=>'Fish Pie Mix',               'category'=>'Prepared Seafood', 'calories'=>100, 'protein'=>20.0, 'carbs'=>0.0, 'fat'=>2.0,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>1.0, 'mercury'=>'low'      ],
            [ 'id'=>230, 'name'=>'Prawn Cocktail',             'category'=>'Prepared Seafood', 'calories'=>125, 'protein'=>12.0, 'carbs'=>4.5, 'fat'=>6.5,  'fiber'=>0.0, 'sugar'=>3.0, 'omega3'=>0.3, 'mercury'=>'low'      ],
            [ 'id'=>231, 'name'=>'Dressed Lobster',            'category'=>'Prepared Seafood', 'calories'=>97,  'protein'=>20.5, 'carbs'=>0.5, 'fat'=>1.0,  'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>0.2, 'mercury'=>'low'      ],
            [ 'id'=>232, 'name'=>'Marinated Anchovies (Boquerones)', 'category'=>'Canned & Smoked', 'calories'=>175, 'protein'=>17.5, 'carbs'=>0.0, 'fat'=>12.0, 'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>2.2, 'mercury'=>'low' ],
            [ 'id'=>233, 'name'=>'Mussels (Smoked, in Oil)',   'category'=>'Prepared Seafood', 'calories'=>167, 'protein'=>12.5, 'carbs'=>1.5, 'fat'=>12.5, 'fiber'=>0.0, 'sugar'=>0.0, 'omega3'=>1.8, 'mercury'=>'low'      ],
            [ 'id'=>234, 'name'=>'Carrageen (Irish Moss)',     'category'=>'Seaweed',          'calories'=>35,  'protein'=>1.5,  'carbs'=>8.5, 'fat'=>0.2,  'fiber'=>1.8, 'sugar'=>0.0, 'omega3'=>0.0, 'mercury'=>'low'      ],
            [ 'id'=>235, 'name'=>'Hijiki',                     'category'=>'Seaweed',          'calories'=>47,  'protein'=>1.4,  'carbs'=>10.1,'fat'=>0.2,  'fiber'=>4.5, 'sugar'=>0.0, 'omega3'=>0.0, 'mercury'=>'low'      ],
        ];
    }

    // ── DB-backed getFoodDatabase() with fallback ─────────────────────────────
    private function getFoodCount() {
        global $wpdb;
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}fcc_foods" );
    }

    private function getFoodDatabase() {
        if ( $this->foodCache !== null ) return $this->foodCache;
        global $wpdb;
        $tbl = $wpdb->prefix . 'fcc_foods';
        if ( get_option( 'fcc_tables_ready' ) ) {
            $rows = $wpdb->get_results( "SELECT * FROM $tbl ORDER BY sort_order ASC, id ASC", ARRAY_A );
            if ( ! empty( $rows ) ) {
                $this->foodCache = $rows;
                return $this->foodCache;
            }
        }
        $foods = $this->getDefaultFoods();
        $alg   = $this->getDefaultAllergens();
        $eco   = $this->getDefaultEcoData();
        $seas  = $this->getDefaultSeasonData();
        $tips  = $this->getDefaultHealthTips();
        foreach ( $foods as &$f ) {
            $id  = (int) $f['id'];
            $er  = $eco[$id] ?? ['r'=>'ok','s'=>'wild'];
            $f['allergens']  = $alg[$id]  ?? '';
            $f['eco_rating'] = $er['r'];
            $f['eco_source'] = $er['s'];
            $f['season']     = $seas[$id] ?? 'All year';
            $f['health_tip'] = $tips[$id] ?? '';
            $f['sort_order']    = $id;
            $f['sort_priority'] = 0;
        }
        unset( $f );
        $this->foodCache = $foods;
        return $this->foodCache;
    }

    // ── Seed wp_fcc_foods from hardcoded defaults ─────────────────────────────
    private function seedFoodsTable() {
        global $wpdb;
        $tbl   = $wpdb->prefix . 'fcc_foods';
        $foods = $this->getDefaultFoods();
        $alg   = $this->getDefaultAllergens();
        $eco   = $this->getDefaultEcoData();
        $seas  = $this->getDefaultSeasonData();
        $tips  = $this->getDefaultHealthTips();
        $fmt   = ['%d','%s','%s','%f','%f','%f','%f','%f','%f','%f','%s','%s','%s','%s','%s','%s','%d'];
        foreach ( $foods as $f ) {
            $id = (int) $f['id'];
            $er = $eco[$id] ?? ['r'=>'ok','s'=>'wild'];
            $wpdb->insert( $tbl, [
                'id'         => $id,
                'name'       => $f['name'],
                'category'   => $f['category'],
                'calories'   => $f['calories'],
                'protein'    => $f['protein'],
                'carbs'      => $f['carbs'],
                'fat'        => $f['fat'],
                'fiber'      => $f['fiber'],
                'sugar'      => $f['sugar'],
                'omega3'     => $f['omega3'],
                'mercury'    => $f['mercury'],
                'allergens'  => $alg[$id]  ?? '',
                'eco_rating' => $er['r'],
                'eco_source' => $er['s'],
                'season'     => $seas[$id] ?? 'All year',
                'health_tip' => $tips[$id] ?? '',
                'sort_order' => $id,
            ], $fmt );
        }
    }

    // ── One-time migration: seed wp_fcc_foods if table exists but is empty ───────
    public function ensureFoodsSeeded() {
        global $wpdb;
        $tbl   = $wpdb->prefix . 'fcc_foods';
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $tbl" );
        if ( $count === 0 ) {
            $this->seedFoodsTable();
        }
        update_option( 'fcc_foods_seeded', 1 );
        $this->foodCache = null; // bust in-request cache so fresh data is used
    }

    // ── One-time migration: insert new fish batch v2 (IDs 91–117) ───────────────
    public function ensureNewFishV2() {
        global $wpdb;
        $tbl         = $wpdb->prefix . 'fcc_foods';
        $existing    = array_map( 'intval', (array) $wpdb->get_col( "SELECT id FROM $tbl WHERE id >= 91" ) );
        $foods       = $this->getDefaultFoods();
        $alg         = $this->getDefaultAllergens();
        $eco         = $this->getDefaultEcoData();
        $seas        = $this->getDefaultSeasonData();
        $tips        = $this->getDefaultHealthTips();
        $fmt         = ['%d','%s','%s','%f','%f','%f','%f','%f','%f','%f','%s','%s','%s','%s','%s','%s','%d'];
        foreach ( $foods as $f ) {
            $id = (int) $f['id'];
            if ( $id < 91 || in_array( $id, $existing ) ) continue;
            $er = $eco[$id] ?? ['r'=>'ok','s'=>'wild'];
            $wpdb->insert( $tbl, [
                'id'         => $id,
                'name'       => $f['name'],
                'category'   => $f['category'],
                'calories'   => $f['calories'],
                'protein'    => $f['protein'],
                'carbs'      => $f['carbs'],
                'fat'        => $f['fat'],
                'fiber'      => $f['fiber'],
                'sugar'      => $f['sugar'],
                'omega3'     => $f['omega3'],
                'mercury'    => $f['mercury'],
                'allergens'  => $alg[$id]  ?? '',
                'eco_rating' => $er['r'],
                'eco_source' => $er['s'],
                'season'     => $seas[$id] ?? 'All year',
                'health_tip' => $tips[$id] ?? '',
                'sort_order' => $id,
            ], $fmt );
        }
        $this->foodCache = null;
        update_option( 'fcc_fish_v2', 1 );
    }

    // ── One-time migration: insert new fish batch v3 (IDs 118–130) ───────────────
    public function ensureNewFishV3() {
        global $wpdb;
        $tbl         = $wpdb->prefix . 'fcc_foods';
        $existing    = array_map( 'intval', (array) $wpdb->get_col( "SELECT id FROM $tbl WHERE id >= 118" ) );
        $foods       = $this->getDefaultFoods();
        $alg         = $this->getDefaultAllergens();
        $eco         = $this->getDefaultEcoData();
        $seas        = $this->getDefaultSeasonData();
        $tips        = $this->getDefaultHealthTips();
        $fmt         = ['%d','%s','%s','%f','%f','%f','%f','%f','%f','%f','%s','%s','%s','%s','%s','%s','%d'];
        foreach ( $foods as $f ) {
            $id = (int) $f['id'];
            if ( $id < 118 || in_array( $id, $existing ) ) continue;
            $er = $eco[$id] ?? ['r'=>'ok','s'=>'wild'];
            $wpdb->insert( $tbl, [
                'id'         => $id,
                'name'       => $f['name'],
                'category'   => $f['category'],
                'calories'   => $f['calories'],
                'protein'    => $f['protein'],
                'carbs'      => $f['carbs'],
                'fat'        => $f['fat'],
                'fiber'      => $f['fiber'],
                'sugar'      => $f['sugar'],
                'omega3'     => $f['omega3'],
                'mercury'    => $f['mercury'],
                'allergens'  => $alg[$id]  ?? '',
                'eco_rating' => $er['r'],
                'eco_source' => $er['s'],
                'season'     => $seas[$id] ?? 'All year',
                'health_tip' => $tips[$id] ?? '',
                'sort_order' => $id,
            ], $fmt );
        }
        $this->foodCache = null;
        update_option( 'fcc_fish_v3', 1 );
    }

    // ── One-time migration: insert new fish batch v4 (IDs 131–153) ───────────────
    public function ensureNewFishV4() {
        global $wpdb;
        $tbl         = $wpdb->prefix . 'fcc_foods';
        $existing    = array_map( 'intval', (array) $wpdb->get_col( "SELECT id FROM $tbl WHERE id >= 131" ) );
        $foods       = $this->getDefaultFoods();
        $alg         = $this->getDefaultAllergens();
        $eco         = $this->getDefaultEcoData();
        $seas        = $this->getDefaultSeasonData();
        $tips        = $this->getDefaultHealthTips();
        $fmt         = ['%d','%s','%s','%f','%f','%f','%f','%f','%f','%f','%s','%s','%s','%s','%s','%s','%d'];
        foreach ( $foods as $f ) {
            $id = (int) $f['id'];
            if ( $id < 131 || in_array( $id, $existing ) ) continue;
            $er = $eco[$id] ?? ['r'=>'ok','s'=>'wild'];
            $wpdb->insert( $tbl, [
                'id'         => $id,
                'name'       => $f['name'],
                'category'   => $f['category'],
                'calories'   => $f['calories'],
                'protein'    => $f['protein'],
                'carbs'      => $f['carbs'],
                'fat'        => $f['fat'],
                'fiber'      => $f['fiber'],
                'sugar'      => $f['sugar'],
                'omega3'     => $f['omega3'],
                'mercury'    => $f['mercury'],
                'allergens'  => $alg[$id]  ?? '',
                'eco_rating' => $er['r'],
                'eco_source' => $er['s'],
                'season'     => $seas[$id] ?? 'All year',
                'health_tip' => $tips[$id] ?? '',
                'sort_order' => $id,
            ], $fmt );
        }
        $this->foodCache = null;
        update_option( 'fcc_fish_v4', 1 );
    }

    // ── One-time migration: insert new fish batch v5 (IDs 154–175) ───────────────
    public function ensureNewFishV5() {
        global $wpdb;
        $tbl         = $wpdb->prefix . 'fcc_foods';
        $existing    = array_map( 'intval', (array) $wpdb->get_col( "SELECT id FROM $tbl WHERE id >= 154" ) );
        $foods       = $this->getDefaultFoods();
        $alg         = $this->getDefaultAllergens();
        $eco         = $this->getDefaultEcoData();
        $seas        = $this->getDefaultSeasonData();
        $tips        = $this->getDefaultHealthTips();
        $fmt         = ['%d','%s','%s','%f','%f','%f','%f','%f','%f','%f','%s','%s','%s','%s','%s','%s','%d'];
        foreach ( $foods as $f ) {
            $id = (int) $f['id'];
            if ( $id < 154 || in_array( $id, $existing ) ) continue;
            $er = $eco[$id] ?? ['r'=>'ok','s'=>'wild'];
            $wpdb->insert( $tbl, [
                'id'         => $id,
                'name'       => $f['name'],
                'category'   => $f['category'],
                'calories'   => $f['calories'],
                'protein'    => $f['protein'],
                'carbs'      => $f['carbs'],
                'fat'        => $f['fat'],
                'fiber'      => $f['fiber'],
                'sugar'      => $f['sugar'],
                'omega3'     => $f['omega3'],
                'mercury'    => $f['mercury'],
                'allergens'  => $alg[$id]  ?? '',
                'eco_rating' => $er['r'],
                'eco_source' => $er['s'],
                'season'     => $seas[$id] ?? 'All year',
                'health_tip' => $tips[$id] ?? '',
                'sort_order' => $id,
            ], $fmt );
        }
        $this->foodCache = null;
        update_option( 'fcc_fish_v5', 1 );
    }

    // ── One-time migration: insert new fish batch v6 (IDs 176–202) ───────────────
    public function ensureNewFishV6() {
        global $wpdb;
        $tbl         = $wpdb->prefix . 'fcc_foods';
        $existing    = array_map( 'intval', (array) $wpdb->get_col( "SELECT id FROM $tbl WHERE id >= 176" ) );
        $foods       = $this->getDefaultFoods();
        $alg         = $this->getDefaultAllergens();
        $eco         = $this->getDefaultEcoData();
        $seas        = $this->getDefaultSeasonData();
        $tips        = $this->getDefaultHealthTips();
        $fmt         = ['%d','%s','%s','%f','%f','%f','%f','%f','%f','%f','%s','%s','%s','%s','%s','%s','%d'];
        foreach ( $foods as $f ) {
            $id = (int) $f['id'];
            if ( $id < 176 || in_array( $id, $existing ) ) continue;
            $er = $eco[$id] ?? ['r'=>'ok','s'=>'wild'];
            $wpdb->insert( $tbl, [
                'id'         => $id,
                'name'       => $f['name'],
                'category'   => $f['category'],
                'calories'   => $f['calories'],
                'protein'    => $f['protein'],
                'carbs'      => $f['carbs'],
                'fat'        => $f['fat'],
                'fiber'      => $f['fiber'],
                'sugar'      => $f['sugar'],
                'omega3'     => $f['omega3'],
                'mercury'    => $f['mercury'],
                'allergens'  => $alg[$id]  ?? '',
                'eco_rating' => $er['r'],
                'eco_source' => $er['s'],
                'season'     => $seas[$id] ?? 'All year',
                'health_tip' => $tips[$id] ?? '',
                'sort_order' => $id,
            ], $fmt );
        }
        $this->foodCache = null;
        update_option( 'fcc_fish_v6', 1 );
    }

    // ── One-time migration: insert new fish batch v7 (IDs 203–211) ──────────────
    public function ensureNewFishV7() {
        global $wpdb;
        $tbl         = $wpdb->prefix . 'fcc_foods';
        $existing    = array_map( 'intval', (array) $wpdb->get_col( "SELECT id FROM $tbl WHERE id >= 203" ) );
        $foods       = $this->getDefaultFoods();
        $alg         = $this->getDefaultAllergens();
        $eco         = $this->getDefaultEcoData();
        $seas        = $this->getDefaultSeasonData();
        $tips        = $this->getDefaultHealthTips();
        $fmt         = ['%d','%s','%s','%f','%f','%f','%f','%f','%f','%f','%s','%s','%s','%s','%s','%s','%d'];
        foreach ( $foods as $f ) {
            $id = (int) $f['id'];
            if ( $id < 203 || in_array( $id, $existing ) ) continue;
            $er = $eco[$id] ?? ['r'=>'ok','s'=>'wild'];
            $wpdb->insert( $tbl, [
                'id'         => $id,
                'name'       => $f['name'],
                'category'   => $f['category'],
                'calories'   => $f['calories'],
                'protein'    => $f['protein'],
                'carbs'      => $f['carbs'],
                'fat'        => $f['fat'],
                'fiber'      => $f['fiber'],
                'sugar'      => $f['sugar'],
                'omega3'     => $f['omega3'],
                'mercury'    => $f['mercury'],
                'allergens'  => $alg[$id]  ?? '',
                'eco_rating' => $er['r'],
                'eco_source' => $er['s'],
                'season'     => $seas[$id] ?? 'All year',
                'health_tip' => $tips[$id] ?? '',
                'sort_order' => $id,
            ], $fmt );
        }
        $this->foodCache = null;
        update_option( 'fcc_fish_v7', 1 );
    }

    // ── One-time migration: insert new fish batch v8 (IDs 212–235) ──────────────
    public function ensureNewFishV8() {
        global $wpdb;
        $tbl         = $wpdb->prefix . 'fcc_foods';
        $existing    = array_map( 'intval', (array) $wpdb->get_col( "SELECT id FROM $tbl WHERE id >= 212" ) );
        $foods       = $this->getDefaultFoods();
        $alg         = $this->getDefaultAllergens();
        $eco         = $this->getDefaultEcoData();
        $seas        = $this->getDefaultSeasonData();
        $tips        = $this->getDefaultHealthTips();
        $fmt         = ['%d','%s','%s','%f','%f','%f','%f','%f','%f','%f','%s','%s','%s','%s','%s','%s','%d'];
        foreach ( $foods as $f ) {
            $id = (int) $f['id'];
            if ( $id < 212 || in_array( $id, $existing ) ) continue;
            $er = $eco[$id] ?? ['r'=>'ok','s'=>'wild'];
            $wpdb->insert( $tbl, [
                'id'         => $id,
                'name'       => $f['name'],
                'category'   => $f['category'],
                'calories'   => $f['calories'],
                'protein'    => $f['protein'],
                'carbs'      => $f['carbs'],
                'fat'        => $f['fat'],
                'fiber'      => $f['fiber'],
                'sugar'      => $f['sugar'],
                'omega3'     => $f['omega3'],
                'mercury'    => $f['mercury'],
                'allergens'  => $alg[$id]  ?? '',
                'eco_rating' => $er['r'],
                'eco_source' => $er['s'],
                'season'     => $seas[$id] ?? 'All year',
                'health_tip' => $tips[$id] ?? '',
                'sort_order' => $id,
            ], $fmt );
        }
        $this->foodCache = null;
        update_option( 'fcc_fish_v8', 1 );
    }

    // ── One-time migration: create analytics tables if activate() missed them ────
    public function ensureAnalyticsTables() {
        global $wpdb;
        $cs = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $agg = $wpdb->prefix . 'fcc_analytics';
        dbDelta( "CREATE TABLE $agg (
            food_id   mediumint(9) NOT NULL,
            food_name varchar(100) NOT NULL,
            searches  mediumint(9) DEFAULT 0,
            calcs     mediumint(9) DEFAULT 0,
            PRIMARY KEY (food_id)
        ) $cs;" );

        $daily = $wpdb->prefix . 'fcc_analytics_daily';
        dbDelta( "CREATE TABLE $daily (
            id        int          NOT NULL AUTO_INCREMENT,
            food_id   mediumint(9) NOT NULL,
            food_name varchar(100) NOT NULL DEFAULT '',
            category  varchar(50)  NOT NULL DEFAULT '',
            log_date  date         NOT NULL,
            searches  int          DEFAULT 0,
            calcs     int          DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY food_date (food_id, log_date)
        ) $cs;" );

        update_option( 'fcc_analytics_ready', 1 );
    }

    // ── One-time migration: create Food Requests + Missed Searches tables ─────────
    public function ensureRequestTables() {
        global $wpdb;
        $cs = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $ms = $wpdb->prefix . 'fcc_missing_searches';
        dbDelta( "CREATE TABLE $ms (
            id            bigint(20)   NOT NULL AUTO_INCREMENT,
            query         varchar(150) NOT NULL,
            count         int(11)      NOT NULL DEFAULT 1,
            status        varchar(20)  NOT NULL DEFAULT 'active',
            last_searched datetime     NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY   fcc_miss_query (query(150))
        ) $cs;" );

        $fr = $wpdb->prefix . 'fcc_food_requests';
        dbDelta( "CREATE TABLE $fr (
            id             bigint(20)   NOT NULL AUTO_INCREMENT,
            food_name      varchar(150) NOT NULL,
            note           text,
            count          int(11)      NOT NULL DEFAULT 1,
            status         varchar(20)  NOT NULL DEFAULT 'pending',
            created_at     datetime     NOT NULL,
            last_requested datetime     NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY   fcc_req_name (food_name(150))
        ) $cs;" );

        update_option( 'fcc_requests_ready', 1 );
    }

    // ── One-time migration: clean redundant cooking suffixes from fish names ─────
    public function migrateCleanFoodNames() {
        global $wpdb;
        if ( ! get_option( 'fcc_tables_ready' ) ) return;

        $name_map = [
            'Cod (baked)'                    => 'Cod',
            'Haddock (baked)'                => 'Haddock',
            'Pollock (cooked)'               => 'Pollock',
            'Halibut (baked)'                => 'Halibut',
            'Sea Bass (baked)'               => 'Sea Bass',
            'Plaice (baked)'                 => 'Plaice',
            'Dover Sole (baked)'             => 'Dover Sole',
            'Tilapia (cooked)'               => 'Tilapia',
            'Monkfish (cooked)'              => 'Monkfish',
            'Flounder (baked)'               => 'Flounder',
            'Whiting (cooked)'               => 'Whiting',
            'Skate Wing (baked)'             => 'Skate Wing',
            'Salmon (fresh, baked)'          => 'Salmon (Fresh)',
            'Tuna (fresh, grilled)'          => 'Tuna (Fresh)',
            'Mackerel (fresh, grilled)'      => 'Mackerel (Fresh)',
            'Sardines (fresh, grilled)'      => 'Sardines (Fresh)',
            'Herring (grilled)'              => 'Herring',
            'Trout (rainbow, baked)'         => 'Rainbow Trout',
            'Anchovy (raw)'                  => 'Anchovy',
            'Sprats (grilled)'               => 'Sprats',
            'Sea Bream (baked)'              => 'Sea Bream',
            'Prawns (cooked)'                => 'Prawns',
            'King Prawns (cooked)'           => 'King Prawns',
            'Lobster (cooked)'               => 'Lobster',
            'Crab (boiled)'                  => 'Crab',
            'Mussels (cooked)'               => 'Mussels',
            'Oysters (raw)'                  => 'Oysters',
            'Clams (cooked)'                 => 'Clams',
            'Scallops (cooked)'              => 'Scallops',
            'Langoustines (cooked)'          => 'Langoustines',
            'Cockles (cooked)'               => 'Cockles',
            'Whelks (cooked)'                => 'Whelks',
            'Squid / Calamari (raw)'         => 'Squid / Calamari',
            'Calamari (fried)'               => 'Calamari (Fried)',
            'Octopus (cooked)'               => 'Octopus',
            'Tuna (canned in water)'         => 'Tuna (Canned, Water)',
            'Tuna (canned in oil)'           => 'Tuna (Canned, Oil)',
            'Salmon (canned)'                => 'Salmon (Canned)',
            'Sardines (canned in oil)'       => 'Sardines (Canned)',
            'Sardines (in tomato sauce)'     => 'Sardines (Tomato)',
            'Anchovies (canned in oil)'      => 'Anchovies (Canned)',
            'Mackerel (canned in brine)'     => 'Mackerel (Canned)',
            'Smoked Haddock (poached)'       => 'Smoked Haddock',
            'Smoked Mackerel (fillet)'       => 'Smoked Mackerel',
            'Smoked Trout (fillet)'          => 'Smoked Trout',
            'Rollmops (pickled herring)'     => 'Rollmops',
            'Gravlax (cured salmon)'         => 'Gravlax',
            'Cod Roe (cooked)'               => 'Cod Roe',
            'Battered Cod (fried)'           => 'Battered Cod',
            'Fish Cakes (fried)'             => 'Fish Cakes',
            'Fish Fingers (baked)'           => 'Fish Fingers',
            'Breaded Scampi (fried)'         => 'Breaded Scampi',
            'Arctic Char (baked)'            => 'Arctic Char',
            'Brill (baked)'                  => 'Brill',
            'Barramundi (baked)'             => 'Barramundi',
            'Carp (baked)'                   => 'Carp',
            'Cod Cheeks (cooked)'            => 'Cod Cheeks',
            'Cod Tongue (cooked)'            => 'Cod Tongue',
            'Coley / Saithe (baked)'         => 'Coley / Saithe',
            'Cobia (baked)'                  => 'Cobia',
            'Dabs (baked)'                   => 'Dabs',
            'Dogfish / Rock Salmon (baked)'  => 'Dogfish / Rock Salmon',
            'Conger Eel (baked)'             => 'Conger Eel',
            'Eels (cooked)'                  => 'Eels',
            'Cuttlefish (cooked)'            => 'Cuttlefish',
            'Fish Mix (Salmon, White + Smoked)' => 'Fish Mix (Smoked Blend)',
            'Fish Mix (Salmon + White)'      => 'Fish Mix (Classic)',
            'Turbot (baked)'                 => 'Turbot',
            'John Dory (baked)'              => 'John Dory',
            'Red Gurnard (baked)'            => 'Red Gurnard',
            'Lemon Sole (baked)'             => 'Lemon Sole',
            'Sea Trout (baked)'              => 'Sea Trout',
            'Swordfish (grilled)'            => 'Swordfish',
            'Brown Shrimp (cooked)'          => 'Brown Shrimp',
            'Spider Crab (cooked)'           => 'Spider Crab',
            'Razor Clams (cooked)'           => 'Razor Clams',
            'Red Mullet (baked)'             => 'Red Mullet',
            'Periwinkles (cooked)'           => 'Periwinkles',
            'Bloater (smoked herring)'       => 'Bloater',
        ];

        $foods_tbl     = $wpdb->prefix . 'fcc_foods';
        $analytics_tbl = $wpdb->prefix . 'fcc_analytics';
        $daily_tbl     = $wpdb->prefix . 'fcc_analytics_daily';

        foreach ( $name_map as $old_name => $new_name ) {
            $wpdb->update( $foods_tbl,     [ 'name' => $new_name ], [ 'name' => $old_name ], [ '%s' ], [ '%s' ] );
            $wpdb->update( $analytics_tbl, [ 'food_name' => $new_name ], [ 'food_name' => $old_name ], [ '%s' ], [ '%s' ] );
            $wpdb->update( $daily_tbl,     [ 'food_name' => $new_name ], [ 'food_name' => $old_name ], [ '%s' ], [ '%s' ] );
        }

        delete_transient( 'fcc_top_trending' );
        update_option( 'fcc_names_v2', 1 );
    }

    // ── Health Tips — hardcoded defaults ─────────────────────────────────────
    private function getDefaultHealthTips() {
        return [
            1  => 'Excellent lean protein source. Low in calories and fat — ideal for weight management and muscle recovery.',
            2  => 'Packed with protein and vitamin B12. Supports thyroid function and energy metabolism.',
            3  => 'One of the most sustainable white fish. High in protein, low in fat, and a great source of phosphorus for bone health.',
            4  => 'Rich in magnesium, selenium, and B vitamins. Supports heart health and reduces inflammation. Moderate mercury — limit to 2 portions per week.',
            5  => 'Contains heart-healthy omega-3s and selenium, which acts as an antioxidant to protect cells from damage.',
            6  => 'High in protein, low in fat. A good source of iodine for thyroid health and selenium for immune support.',
            7  => 'Very lean and easily digestible. High in protein and selenium — great for post-workout recovery.',
            8  => 'Budget-friendly lean protein. Contains niacin and phosphorus. Best paired with omega-3-rich foods.',
            9  => 'Firm-textured and meaty. Very low in fat and calories, high in protein and selenium.',
            10 => 'Mild, delicate flavour with high protein and low fat. Good source of magnesium and vitamin B12.',
            11 => 'Economical and nutritious. High in protein and phosphorus, which supports bone health and energy production.',
            12 => 'Unique cartilaginous fish rich in protein. Contains collagen-building nutrients that support joint health.',
            13 => 'One of the richest sources of omega-3 EPA and DHA. Supports heart health, brain function, and reduces inflammation.',
            14 => 'Excellent high-protein, low-fat fish. Rich in omega-3s and selenium. Limit to 2–4 portions per week due to moderate mercury.',
            15 => 'One of the highest omega-3 fish available. Outstanding for heart health, brain function, and lowering triglycerides.',
            16 => 'Loaded with omega-3s, calcium (from edible bones), and vitamin D. Among the most nutrient-dense, sustainable fish.',
            17 => 'Rich in omega-3 fatty acids, vitamin D, and B12. Supports cardiovascular health and immune function.',
            18 => 'Freshwater oily fish packed with omega-3s, vitamin D, and B12. A great heart-healthy alternative to salmon.',
            19 => 'Tiny but mighty. Packed with omega-3s, calcium, and iron. Excellent for cardiovascular and bone health.',
            20 => 'Small, sustainable fish with outstanding omega-3 content. Rich in vitamin D and calcium.',
            21 => 'Lean and flavourful with heart-healthy omega-3s. A good source of selenium and phosphorus.',
            22 => 'Low in fat and calories, high in protein. Contains astaxanthin — a powerful antioxidant that gives prawns their pink colour.',
            23 => 'Excellent lean protein with iodine for thyroid health. Rich in zinc and selenium for immune support.',
            24 => 'Low in fat, high in protein. Good source of copper and selenium. A luxurious lean protein choice.',
            25 => 'Rich in protein and low in fat. Excellent source of zinc, selenium, and vitamin B12 for immune and nerve health.',
            26 => 'Brown crab meat is especially rich in omega-3s and fat-soluble vitamins, while white meat is high in lean protein.',
            27 => 'One of the most sustainable seafoods. Packed with iron, vitamin B12, and omega-3s — excellent for preventing anaemia.',
            28 => 'The highest natural source of zinc, critical for immune function. Also rich in iron and vitamin B12.',
            29 => 'Extraordinary source of vitamin B12 and iron. A small serving provides more iron than most cuts of red meat.',
            30 => 'Low-fat, high-protein shellfish. Contains taurine — an amino acid that supports heart and brain function.',
            31 => 'Elegant crustacean high in protein and low in fat. Good source of phosphorus for bone health.',
            32 => 'Traditional British seafood. Exceptionally low in calories and fat, yet rich in protein, iron, and vitamin B12.',
            33 => 'A classic British seafood. Low in fat, decent protein, and a source of selenium and iodine.',
            34 => 'Good source of protein and omega-3s. Contains taurine which helps regulate blood pressure and supports heart health.',
            35 => 'When battered and fried, calorie content rises significantly. Baked or grilled calamari retains more nutritional value.',
            36 => 'Very lean and high in protein. Rich in iron and B12. Contains taurine for cardiovascular support.',
            37 => 'Convenient, affordable protein. Choose tuna in water to keep calories low. Limit to 2–4 portions per week due to mercury.',
            38 => 'Higher in fat than water-packed tuna but still a great protein source. Drain well and monitor consumption frequency.',
            39 => 'An affordable way to get omega-3s. Canned salmon with soft bones is also an excellent source of calcium.',
            40 => 'Rich in omega-3s and calcium from edible bones. Convenient and nutritious — great on toast or in salads.',
            41 => 'Omega-3s combined with the antioxidant lycopene from tomatoes. A heart-healthy, anti-inflammatory combination.',
            42 => 'Intense flavour with exceptional omega-3 content. A small amount adds significant nutritional value to any dish.',
            43 => 'Convenient and affordable omega-3 powerhouse. Supports heart health, brain function, and reduces inflammation.',
            44 => 'Rich in omega-3 EPA and DHA, vitamin D, and B12. Note the higher sodium content from the smoking process.',
            45 => 'High in protein and very low in fat. Good source of iodine and selenium. Higher in sodium than fresh haddock.',
            46 => 'Arguably the most convenient omega-3 powerhouse. Ready to eat, rich in vitamin D and B12.',
            47 => 'Delicious and nutrient-dense. High in omega-3s, protein, and B vitamins. A great alternative to smoked salmon.',
            48 => 'Traditional pickled herring rich in omega-3s. The pickling process may also offer probiotic benefits for gut health.',
            49 => 'Cured salmon retains most of the omega-3s of fresh salmon. Be mindful of the sodium content from the curing process.',
            50 => 'High in protein and omega-3s. A traditional British ingredient packed with vitamins A, D, and B12.',
            51 => 'One of the highest omega-3 foods available. Each egg contains concentrated EPA, DHA, and the antioxidant astaxanthin.',
            52 => 'Extraordinarily rich in omega-3s and vitamin D. One of the most nutrient-dense foods on earth, calorie for calorie.',
            53 => 'Made from cod or carp roe blended with oil. Contains some omega-3s but is high in fat and calories — enjoy in moderation.',
            54 => 'A British classic. The batter significantly increases calorie and fat content. Grilled cod gives maximum nutritional benefit.',
            55 => 'A good way to include fish in the diet. Homemade versions with more fish and less filler are considerably more nutritious.',
            56 => 'A convenient fish option for all ages. Baking rather than frying significantly reduces fat content.',
            57 => 'Langoustine tails in breadcrumbs. Oven-baking is a much healthier option than deep-frying.',
            58 => 'A takeaway favourite higher in fat and carbs than plain prawns. Best enjoyed occasionally as a treat.',
            59 => 'Rich in iodine for thyroid health. Contains unique antioxidants and one of the highest protein percentages of any plant food.',
            60 => 'Contains fucoxanthin, a unique marine antioxidant. Good source of iodine, manganese, and folate. Very low in calories.',
            61 => 'A sustainably farmed relative of salmon and trout with exceptional omega-3 content. Lower in mercury than most oily fish, making it an excellent regular choice for heart and brain health.',
            62 => 'A flat fish closely related to turbot, prized for its delicate, sweet flesh. High in protein, low in fat, and a good source of selenium and B vitamins for energy metabolism.',
            63 => 'A mild, buttery fish farmed sustainably in many parts of the world. High in lean protein with heart-healthy omega-3s. Also contains iodine, selenium, and vitamin B12.',
            64 => 'A popular freshwater fish across Europe and Asia. Higher in fat than most white fish, delivering more omega-3s per serving. Rich in vitamin B12 and phosphorus for energy and nerve function.',
            65 => 'The tender, prized cheeks of the cod — extremely lean and delicate in texture. High in protein and contains natural collagen that supports skin elasticity, joint comfort, and gut health.',
            66 => 'A traditional delicacy in Newfoundland and Iceland. Very lean with a soft, creamy texture. Rich in protein, collagen, and glycine — all beneficial for connective tissue and gut lining health.',
            67 => 'An underrated, sustainable British fish and an excellent affordable alternative to cod. Very low in fat and high in protein. Eco-friendly with nutritional benefits comparable to cod or haddock.',
            68 => 'A fast-growing, sustainably farmed fish with a mild, rich flavour. High in protein and heart-healthy omega-3s. Moderate mercury — best enjoyed as part of a varied seafood diet.',
            69 => 'A small, overlooked flat fish native to British coastal waters. Very low in fat and high in protein. An underrated sustainable choice with a delicate flavour, best pan-fried whole.',
            70 => 'Sold as "rock salmon" in UK fish and chip shops. Firm, flavourful flesh with good protein content. As a small shark species it accumulates moderate mercury — enjoy occasionally.',
            71 => 'Wild-caught dorade is prized in Mediterranean cuisine for its sweet, firm flesh. Rich in omega-3s, selenium, and phosphorus. Often considered one of the finest tasting sea fish available.',
            72 => 'A firm, meaty sea fish prized in coastal cuisines. Good source of omega-3 fatty acids, vitamin B12, and phosphorus. Moderate mercury — best enjoyed occasionally as part of a varied diet.',
            73 => 'A rich, flavourful fish prized in British and European cuisine. Excellent source of omega-3s, vitamin B12, and vitamin D. Moderate mercury — European eel is an endangered species, so always source sustainably.',
            74 => 'A close relative of squid with tender, flavourful flesh. Very lean and high in protein. Rich in copper, selenium, and taurine, supporting cardiovascular health and cognitive function.',
            75 => 'Soft, edible fish bones (from canned or slow-cooked fish) are among the richest natural sources of calcium and phosphorus. Excellent for bone density, dental health, and as a highly bioavailable calcium supplement.',
            76 => 'A flavourful blend of salmon, white fish, and smoked fish. Combines the high omega-3 content of salmon with lean protein from white fish and the depth of smoked fish. Ideal for pies, fishcakes, and chowders.',
            77 => 'A versatile mix of salmon and white fish providing a balance of rich omega-3s from salmon and lean protein from white fish. Perfect for fish pies, cakes, pasta dishes, and soups.',
            78 => 'A traditional Provençal seafood blend combining multiple fish and shellfish. Provides a diverse range of omega-3 fatty acids, minerals, and lean protein from a variety of seafood sources. A nutritionally rich, warming choice.',
            79 => 'Premium UK flatfish with firm, sweet flesh. Excellent source of phosphorus and vitamin B12. A luxury fish highly prized in fine dining.',
            80 => 'Distinctive fish with a compressed body and unique flavour. Low in fat and calories, high in protein. Contains iodine for thyroid health.',
            81 => 'Highly sustainable, underrated UK fish. Rich in protein and selenium. An eco-conscious choice with delicate, sweet-flavoured white flesh.',
            82 => 'Sweet, delicate flatfish with very low calorie content. Good source of B vitamins and selenium for immune and metabolic health.',
            83 => 'Wild-caught relative of salmon, rich in omega-3 EPA and DHA. Contains astaxanthin antioxidants. A seasonal UK delicacy at its best in spring and summer.',
            84 => 'Firm, steak-like texture ideal for grilling. Contains healthy omega-3s but has elevated mercury — limit to once per fortnight, especially for pregnant women.',
            85 => 'Tiny Morecambe Bay specialty packed with nutrients. Excellent source of iodine, zinc, and lean protein. A traditional British coastal delicacy.',
            86 => 'Sweeter and more flavourful than common crab. Highly sustainable from UK coastal waters. Rich in zinc, copper, and lean protein.',
            87 => 'Low calorie, high-iron shellfish sustainably harvested from UK coasts. A traditional British seafood with a clean, briny flavour.',
            88 => 'Distinctive Mediterranean and British fish with sweet, flavourful flesh. Rich in phosphorus and vitamin B12. The liver is considered a delicacy in traditional cooking.',
            89 => 'Traditional British seaside shellfish. Very low in calories and fat, yet a good source of protein and vitamin B12.',
            90  => 'Lightly cold-smoked whole herring — a classic British delicacy. Rich in omega-3 fatty acids, vitamin D, and B12 for heart and brain health.',
            91  => 'One of Spain and Portugal\'s most prized fish. Lean, mild, and easily digestible. High in protein, low in fat, and a good source of selenium and iodine for thyroid health.',
            92  => 'Versatile, mild-flavoured fish popular worldwide. Good source of lean protein, omega-3s, and potassium. Moderate mercury — limit to 2 portions per week.',
            93  => 'A highly sustainable and underrated British fish. Firm, sweet flesh with excellent protein content. An eco-conscious choice that supports sustainable UK fisheries.',
            94  => 'Firm, moist white fish popular in tropical and subtropical cuisine. Lean and high in protein. Moderate mercury — enjoy occasionally as part of a varied seafood diet.',
            95  => 'A versatile coastal fish with firm flesh and a mild, nutty flavour. Good source of omega-3s, protein, and B vitamins. Highly sustainable from UK and Mediterranean waters.',
            96  => 'Premium Japanese amberjack prized in sushi and sashimi. Rich in omega-3 fatty acids, protein, and vitamin B12. A heart-healthy fish with a buttery, melt-in-the-mouth texture.',
            97  => 'A flavourful mix of salmon, ling, and cod on skewers. Combines the omega-3 richness of salmon with the lean protein of white fish. A healthy, high-protein grilled option.',
            98  => 'Meaty, full-flavoured fish popular in Australian and Asian cuisines. Rich in omega-3 fatty acids and protein. An excellent source of vitamins B12 and D. Best enjoyed as part of a varied diet.',
            99  => 'A deep-water white fish highly prized in Norway and Northern Europe. Firm-textured and lean with excellent protein content. A sustainable and nutritionally comparable alternative to cod.',
            100 => 'A tropical fish with firm, sweet flesh. High in protein and low in fat. A good source of niacin, vitamin B12, and selenium. Moderate mercury — a healthy choice in moderation.',
            101 => 'A prized billfish with rich, dense, steak-like flesh. High in protein and omega-3s, but elevated mercury — limit to once a fortnight, especially for children and pregnant women.',
            102 => 'A sustainable flat fish from UK and Atlantic waters, similar to plaice. Delicate, mild flesh that is low in fat and calories. A nutritious, underrated choice for light meals.',
            103 => 'The premium meaty cut from monkfish — firm, succulent, and almost lobster-like in texture. Very low in fat and high in lean protein. Contains selenium and phosphorus for immune and energy support.',
            104 => 'Tender, prized nuggets of monkfish with a sweet, firm texture. Very lean and high in protein. A sustainable and versatile ingredient ideal for pan-frying or adding to soups and stews.',
            105 => 'A rare delicacy rich in omega-3 fatty acids, fat-soluble vitamins (A, D, E, K), and minerals. Nutritionally dense and unique in flavour — best enjoyed in small portions as a luxury ingredient.',
            106 => 'A widely-consumed freshwater fish with high lean protein content. Good source of niacin and phosphorus. Sustainability varies by source — choose certified options for best environmental impact.',
            107 => 'Mediterranean octopus prized for its tender, flavourful flesh when slow-cooked. Very lean with high protein. Rich in iron, vitamin B12, and taurine for cardiovascular and cognitive health.',
            108 => 'A British coastal delicacy. Very lean, high in protein, and rich in vitamin B12, iron, and copper. Contains taurine which supports heart health. UK-sourced stocks are well-managed.',
            109 => 'A vivid tropical fish with sweet, firm flesh popular in Caribbean and Pacific cuisines. Lean and high in protein. Source from certified sustainable fisheries to protect coral reef ecosystems.',
            110 => 'A prized fish in South and Southeast Asian cuisine with tender, sweet, slightly oily flesh. Good source of omega-3 fatty acids, vitamin D, and lean protein. Choose sustainably sourced options.',
            111 => 'A freshwater predator with lean, firm, white flesh. Very low in fat and calories, high in protein. Popular in French and Eastern European cuisine. Wild-caught and generally low in pollutants.',
            112 => 'A deep-sea North Atlantic fish (also known as rosefish or ocean perch) with mild, sweet flesh. Good source of omega-3 fatty acids, selenium, and vitamin B12. Sustainably managed in regulated fisheries.',
            113 => 'A flat fish from Atlantic and Mediterranean waters with a mild, delicate flavour similar to Dover Sole. Lean, low in calories, and high in protein. An excellent everyday white fish option.',
            114 => 'A powerful billfish with firm, protein-rich flesh. Very high in protein and relatively low in fat. Elevated mercury — limit to once per fortnight. Best sourced from regulated sport fisheries.',
            115 => 'The most flavourful and collagen-rich part of the salmon. High in omega-3 fatty acids, vitamin D, and gelatin. The cheeks yield tender meat while bones and cartilage provide joint-supporting nutrients.',
            116 => 'Wild-caught salmon is leaner than farmed with a more intense flavour and rich omega-3 EPA and DHA content. Excellent source of vitamin D, B12, and astaxanthin antioxidants. One of the most nutritionally complete fish.',
            117 => 'Rainbow trout raised in sea enclosures develops a richer omega-3 profile than freshwater-farmed trout, with a firmer texture and salmon-like flavour. High in protein, vitamin D, and B12. A sustainable, heart-healthy choice.',
            118 => 'A traditional Asian marine delicacy and superfood. Extremely low in calories with a surprisingly high protein content. Rich in antioxidants, collagen, and anti-inflammatory compounds. Highly valued in traditional medicine for joint and skin health.',
            119 => 'Dense, meaty fish steaks with a firm, steak-like texture ideal for grilling or pan-frying. High in protein and a source of omega-3 fatty acids. Elevated mercury — limit to once a fortnight. Choose sustainably certified shark from well-managed fisheries.',
            120 => 'A small coastal shark, also sold as "rock salmon" in UK fish and chip shops. Firm, flavourful flesh with good protein content. High mercury — enjoy occasionally. Tope is vulnerable in EU waters; always verify sustainable sourcing certification before purchase.',
            121 => 'The distinctive round muscle cuts from the knob of the skate body — gelatinous, collagen-rich, and uniquely textured. Very lean and high in protein. Contains glycine and collagen supporting joint health and gut lining integrity.',
            122 => 'Whole uncleaned squid retains the ink sac, which contains melanin and taurine antioxidants. Lean, high in protein, and a good source of phosphorus and vitamin B12. Versatile ingredient for grilling, stuffing, or slow-cooking whole.',
            123 => 'An ancient, prehistoric fish prized for its firm, rich flesh as well as its iconic roe (caviar). Excellent source of omega-3 fatty acids, protein, and phosphorus. Wild sturgeon is critically endangered — always choose certified farmed options.',
            124 => 'Also known as Meagre (Argyrosomus regius), this impressive sea fish has firm, white, delicately flavoured flesh. Very lean, high in protein, and a good source of selenium and B vitamins. Increasingly available from sustainable Mediterranean aquaculture.',
            125 => 'A robust black tilapia variety with firm, mild-flavoured flesh. An excellent budget-friendly lean protein. Higher in omega-6 fatty acids than oily fish — best enjoyed as part of a varied diet that includes omega-3-rich seafood.',
            126 => 'A hybrid red tilapia with pale, mild, slightly sweet flesh. Excellent lean protein with minimal fat. Best when farmed in open, free-flowing water systems which produce the cleanest flavour and the most sustainable environmental footprint.',
            127 => 'A native British freshwater fish with rich, distinctively flavoured flesh and excellent omega-3 content. Good source of vitamin D and B12. Wild brown trout is a seasonal delicacy especially prized when sourced from clean chalk streams in spring and summer.',
            128 => 'The prized fatty belly of bluefin tuna, revered in Japanese cuisine as the ultimate sushi and sashimi delicacy. Extraordinarily rich in omega-3 EPA and DHA — among the highest of any fish. Very high in fat and calories. Elevated mercury and critically endangered bluefin tuna status — treat as a rare, occasional luxury.',
            129 => 'Also known as Torbay Sole, this flat fish has delicate, sweet flesh comparable to lemon sole. Very lean and low in calories. A sustainably managed flat fish from well-regulated North Atlantic and UK coastal waters. An underrated, eco-conscious alternative to Dover Sole.',
            130 => 'A freshwater fish of the perch family, also called pike-perch (Sander lucioperca). Extremely lean with minimal fat and high protein content. Prized for its mild, delicate flavour and firm, near-boneless flesh. Popular across Central and Eastern Europe and growing in availability in UK markets.',
            131 => 'Amande clams (also called dog cockles) have a sweet, briny flavour and tender texture. Rich in vitamin B12, iron, and lean protein. A traditional French delicacy often served raw like oysters or simply steamed open with white wine and garlic.',
            132 => 'Palourde (carpet shell) clams are a classic of French and Portuguese cuisine. Sweet, firm flesh with exceptional briny depth. Very high in vitamin B12 and iron — among the best shellfish sources of both nutrients. Best steamed open in white wine.',
            133 => 'Large surf or Venus clams with plump, meaty flesh. High in protein, vitamin B12, and iron. Versatile for chowders, pasta, and grilling. A sustainably harvested coastal shellfish from well-managed UK and European waters.',
            134 => 'Brown crab claws contain a rich mix of white and dark meat. High in protein and omega-3 fatty acids. An excellent source of zinc, selenium, and vitamin B12. Great as finger food or served simply cracked with crusty bread and butter.',
            135 => 'The darker meat from the body cavity of brown crab — richer and more intensely flavoured than white meat. Packed with omega-3 fatty acids, vitamin B12, and fat-soluble vitamins. Ideal for pâtés, sauces, bisques, and seasoning other crab dishes.',
            136 => 'Premium white meat from the claws and legs of brown crab. Very lean and exceptionally high in protein. An outstanding source of zinc, selenium, and B12. Perfect for crab sandwiches, light salads, and starters where the delicate flavour can shine.',
            137 => 'Claw meat has a slightly firmer texture and more pronounced flavour than body white meat. Rich in protein and balanced in fat. A versatile cut used in crab cakes, pasta dishes, dressed crab, and chowders.',
            138 => 'A premium US-style grade of crab meat consisting of large, intact flakes from the body near the backfin. High in lean protein and very low in fat. Ideal for crab cakes, elegant salads, and any preparation where visible, quality flakes matter.',
            139 => 'The velvet swimming crab (Necora puber) has sweet, flavourful meat and makes an outstanding bisque base. High in lean protein. A UK sustainable shellfish with a fiery appearance that belies its delicious flavour — best sourced in autumn and winter.',
            140 => 'Calorie values are per 100g total weight including shell (approx. 60% is shell). Once shucked and cooked, cockle meat is dense in vitamin B12, iron, and protein. A traditional British seaside shellfish at its best when bought live from clean, managed beds.',
            141 => 'Signal crayfish are an invasive North American species in UK rivers — eating them actively benefits native ecosystems. Delicious, sustainable, and high in lean protein with a clean, sweet flavour. A guilt-free seasonal ingredient that helps restore native wildlife.',
            142 => 'Farmed freshwater crayfish imported primarily from Sweden, China, or Turkey. Sweet, lobster-like flesh with excellent lean protein. Low in fat and calories. Choose certified farmed options to ensure responsible sourcing and minimal environmental impact.',
            143 => 'The European native lobster (Homarus gammarus) is considered by many chefs to be superior in flavour to Canadian lobster — sweeter, firmer, and more complex. High in protein, low in fat, and a good source of zinc and selenium. A premium UK coastal delicacy.',
            144 => 'Rock lobster or crawfish (Palinurus species) has firm, sweet tail meat and no large claws. High in protein and slightly richer in fat than European lobster. A luxury warm-water shellfish often imported from the Caribbean, Mediterranean, or South Africa.',
            145 => 'The native European flat oyster (Ostrea edulis) has a deeper, more complex flavour than the Pacific rock oyster and is considered by many connoisseurs to be the finest oyster in the world. Slightly higher in zinc and glycogen. A precious and increasingly rare UK delicacy — season September to April.',
            146 => 'The vibrant golden roe of sea urchins (uni) is one of the world\'s great culinary delicacies. Intensely flavoured and rich in umami, it is packed with omega-3 fatty acids, protein, and fat-soluble vitamins. A seasonal British treat at its best between November and March.',
            147 => 'Sea lettuce (Ulva lactuca) — known as "laitue de mer" in French — is a vibrant green seaweed packed with iodine, iron, and vitamin C. Very low in calories with a mild, fresh oceanic flavour. An excellent sustainable source of micronutrients and unique marine antioxidants.',
            148 => 'Sea spaghetti (Himanthalia elongata) — sold as "haricots de mer" in France — has a pleasant mild flavour and satisfying bite. Rich in iodine, manganese, and dietary fibre. A sustainable British seaweed increasingly used in contemporary cuisine as a pasta alternative.',
            149 => 'Dulse (Palmaria palmata) is a red seaweed with a distinctly savoury, almost bacon-like flavour when dried and pan-fried. Surprisingly high in protein relative to its weight. Rich in iodine, iron, and antioxidants — one of the most nutritionally dense and versatile edible seaweeds.',
            150 => 'Royal kombu (Laminaria) is the foundation of Japanese dashi stock and one of the richest natural sources of iodine — one small piece can supply more than a week\'s requirement. Contains natural glutamates responsible for deep umami flavour. Essential for thyroid health and metabolism support.',
            151 => 'Raw, unbreaded langoustine tails — the premium base ingredient for breaded scampi. Very low in fat and high in lean protein. A versatile, delicate crustacean with a subtle sweetness. Far lower in calories than the breaded version, making it an excellent healthy cooking ingredient.',
            152 => 'Large king scallops with the vibrant orange coral (roe) still attached. The coral adds omega-3 fatty acids, carotenoids, and a richer, creamier flavour compared to roe-off scallops. A premium, sustainably managed UK shellfish at its finest from October through to March.',
            153 => 'Large, meaty tropical prawns with firm texture and a sweet flavour. Very high in lean protein and very low in fat. A good source of selenium, iodine, and astaxanthin — the powerful carotenoid antioxidant that gives them their distinctive pink colour when cooked.',
            154 => 'A PGI-protected Scottish delicacy — whole haddock hot-smoked over hardwood until moist and deeply flavoured. Rich in protein, vitamin D, and B12. Higher in calories than cold-smoked haddock. Best eaten warm, traditionally with butter, straight from the paper.',
            155 => 'Whole herring hot-smoked until fully cooked through — rich, fatty, and intensely flavoured with crisp golden skin. One of the finest sources of omega-3 EPA and DHA, vitamin D, and B12. A traditional delicacy across Eastern Europe. Eat as is or flaked into salads and pâtés.',
            156 => 'Natural smoked cod roe — the unblended base ingredient used to make taramasalata. Very high in protein, omega-3 fatty acids, and fat-soluble vitamins A, D, and E. Much leaner and lower in calories than the finished dip. Rich in umami with a deep, briny, smoky flavour.',
            157 => 'Cold-smoked cod retains the lean, mild flesh of fresh cod while gaining depth from the smoking process. Very high in protein and extremely low in fat. Rich in selenium, B12, and iodine. Slightly higher in sodium than fresh cod due to the brining stage. Poach in milk for the classic preparation.',
            158 => 'A premium combination of salmon prepared in three curing or smoking styles — typically cold-smoked, hot-smoked, and gravadlax — offering different textures and flavour profiles on one plate. Delivers consistent omega-3 EPA and DHA across all three preparations. An elegant sharing platter or starter.',
            159 => 'Scandinavian cured salmon with the addition of vibrant beetroot, which imparts a deep crimson colour and earthy sweetness to the cure. Retains the full omega-3 content of fresh salmon. The beetroot adds betalain antioxidants, folate, and a visually stunning appearance. Perfect for festive occasions.',
            160 => 'Hot-smoked salmon has a fully cooked, flaky texture quite different from cold-smoked. Rich in omega-3 EPA and DHA, protein, and vitamin D. Slightly higher in calories than cold-smoked. Outstanding flaked into salads, pasta, and rice dishes, or served warm with dressed leaves and crusty bread.',
            161 => 'A whole herring split, brined, and cold-smoked — the quintessential British breakfast. Exceptionally rich in omega-3 fatty acids, vitamin D, and B12. Higher in sodium due to brining. One of the most nutritious, affordable, and overlooked British seafoods. Grill and serve with lemon butter or scrambled eggs.',
            162 => 'Pressed, dried grey mullet roe — an ancient Mediterranean delicacy known as the "truffle of the sea." Extraordinarily concentrated in protein, omega-3 fatty acids, and umami flavour. Used in very small quantities grated over pasta, eggs, or toast. A powerful flavour ingredient from Sardinia and Sicily.',
            163 => 'A sustainable caviar alternative made from smoked herring roe. Excellent omega-3 content with none of the sustainability concerns of sturgeon. Rich in protein and marine antioxidants with a pleasingly mild, briny flavour. An accessible, responsible luxury for everyday use on blinis, toast, or as a garnish.',
            164 => 'Preserved crayfish tails in light brine — a convenient, shelf-stable protein source. Retains the sweet, lobster-like flavour of freshwater crayfish. Lower in calories than comparable shellfish. Versatile for salads, pasta, vol-au-vents, and light lunches. Drain and rinse before use to reduce sodium.',
            165 => 'Royal Beluga caviar from Huso huso — the largest sturgeon and most prized of all roe. The largest, most delicate eggs with a buttery, almost creamy flavour and the highest omega-3 concentration of any caviar variety. Always farmed (wild Beluga is critically endangered). An extraordinary and occasional luxury.',
            166 => 'Oscietra (Ossetra) caviar from Acipenser gueldenstaedtii — smaller, firmer eggs with a complex nutty, briny, slightly sweet flavour. Very high in omega-3 EPA and DHA. Rich in vitamins A, D, and B12. Considered by many connoisseurs to be the most complex and interesting of the three classic caviars.',
            167 => 'Sevruga caviar from Acipenser stellatus — the smallest eggs but the most intensely flavoured of the classic three. Strong, assertive, briny character. High in omega-3 fatty acids and fat-soluble vitamins. The most affordable of the classic caviars yet highly prized by those who love bold, oceanic flavour.',
            168 => 'A traditional East London delicacy — poached eel set in natural gelatin aspic. Contains the healthy omega-3s and protein of eel with a distinctive gelatinous texture. Higher in sodium. European eel is critically endangered — source only from certified sustainable or responsibly farmed origins.',
            169 => 'Black-dyed lumpfish roe — an affordable caviar alternative from North Atlantic lumpfish. A good source of omega-3 fatty acids, protein, and iron. Lower in omega-3 than premium sturgeon caviar but nutritionally useful. Popular as a garnish on canapés, devilled eggs, and smoked salmon blinis.',
            170 => 'Red-dyed lumpfish roe with the same nutritional profile as the black variety. A colourful, affordable caviar substitute used for garnishing and flavouring. Contains omega-3 fatty acids, protein, and iron. The vivid red colour comes entirely from food-safe dye — not a natural indicator of variety.',
            171 => 'Cooked octopus pieces marinated in extra virgin olive oil with herbs and lemon — a classic Mediterranean antipasto. The olive oil adds heart-healthy monounsaturated fats. High in lean protein, iron, and taurine for cardiovascular support. Ready to eat, versatile as a starter or in salads.',
            172 => 'Cephalopod ink used as a natural food colourant and intense flavouring in pasta, risotto, paella, and seafood dishes. Contains melanin antioxidants, taurine, and trace minerals. Very low in calories. A small sachet or teaspoon delivers bold ocean flavour and a dramatically deep black colour.',
            173 => 'Farmed marsh samphire (Salicornia europaea) — also called glasswort or sea asparagus. A bright green coastal succulent with crunchy texture and a naturally salty, mineral-rich flavour. Low in calories, a source of vitamin C and iodine. Blanch briefly and serve with butter alongside any fish dish.',
            174 => 'Wild marsh samphire harvested from UK estuaries and salt marshes. Nutritionally similar to farmed but with a more intense, briny flavour from the natural salt flat environment. A seasonal British delicacy at its peak June to September. Best enjoyed barely blanched to preserve its vibrant colour and snap.',
            175 => 'A coastal succulent plant (Halimione portulacoides) with a distinctly salty, mineral flavour. Low in calories with good vitamin C content. Used as a premium garnish, salad green, and vegetable accompaniment in coastal and fine-dining cuisine. Foraged from UK salt marshes and growing in popularity.',
            176 => 'A rich, versatile French-style fish soup made to a traditional Provençal recipe. Packed with fish protein and minerals from a blend of white fish, with tomato, herbs, and rouille-style depth. Convenient, ready to heat, and an excellent base for bouillabaisse-style dishes at home.',
            177 => 'A velvety French bisque made from sweet brown and white crab meat simmered to a rich, warming depth. Contains crustacean protein and naturally occurring iodine. Ready to heat — an elegant, convenient option for a restaurant-quality starter in minutes.',
            178 => 'A luxurious bisque made from lobster shells and meat with brandy, cream, and aromatics in the classic French tradition. Intensely flavoured and deeply satisfying. Contains crustacean protein and natural iodine. A premium convenience product perfect for special occasions.',
            179 => 'Keta (chum) salmon pearls — large, vibrant orange eggs with a mild, clean flavour and a satisfying pop. High in omega-3 EPA and DHA, protein, and fat-soluble vitamins. Slightly larger and more translucent than Ikura. A premium roe for sushi, garnishing, and festive presentation.',
            180 => 'A prepared mix of squid, octopus, mussels, and other shellfish preserved in olive oil with herbs. A convenient, protein-rich product delivering diverse marine proteins, omega-3s, and minerals. The olive oil adds heart-healthy fats. Ready to eat as antipasto, in salads, or on bruschetta.',
            181 => 'Sweet-cured herring in a light sugar and vinegar brine — a Maatjes-style preparation beloved in Scandinavian and Dutch cuisine. Rich in omega-3 fatty acids, protein, and B12. Sweet-sharp flavour profile, best served chilled with pickled cucumber, dill, and dark rye bread.',
            182 => 'Flying fish roe (tobiko) infused with wasabi for a sharp pungency and vibrant green colour. High in omega-3s and protein. A striking garnish in Japanese cuisine on sushi, maki, and platters. Contains wasabi heat compounds — use thoughtfully if serving to guests sensitive to spice.',
            183 => 'Natural orange flying fish roe — a Japanese sushi staple prized for its satisfying crunch and mild briny sweetness. High in omega-3 fatty acids, protein, and B12. One of the most affordable and nutritionally dense forms of roe. Used extensively as a sushi topping and decorative garnish.',
            184 => 'Yuzu-flavoured flying fish roe with a delicate citrus fragrance and bright golden colour. Nutritionally identical to standard tobiko — high in omega-3s, protein, and B12. The yuzu adds a fragrant, subtle citrus note that pairs beautifully with white fish, scallops, and delicate sushi rolls.',
            185 => 'Meaty fresh tuna chunks — ideal for searing, salads, pasta, and tataki preparations. Exceptionally high in protein and relatively low in fat. A good source of selenium, vitamin B12, and omega-3s. Moderate mercury — limit to 2–4 portions per week. Sear briefly over high heat for best flavour.',
            186 => 'A luxurious cold terrine of salmon and cream cheese — rich, smooth, and elegantly flavoured. Higher in fat than plain salmon due to the cream. Delivers omega-3 EPA and DHA of salmon plus protein and calcium from dairy. Best served chilled on blinis or rye crispbread with dill and lemon.',
            187 => 'Premium king prawns stored and dispatched in seawater — firmer, fresher, and sweeter than freshwater-held counterparts. Very high in lean protein, low in fat. Rich in iodine, selenium, and astaxanthin. The seawater preserves a distinctly fresh, clean sweetness that is immediately apparent.',
            188 => 'Large Atlantic crevettes (gambas) have a firm, meaty texture and outstanding sweet, complex flavour. Very high in lean protein and very low in fat. Rich in iodine and selenium. Exceptional grilled whole with garlic butter or served cold as the centrepiece of a premium seafood platter.',
            189 => 'Small, sweet cold-water cocktail prawns — the classic British starter. Low in calories and fat, high in lean protein. Rich in iodine and selenium. The smaller size and cold-water origin delivers a more delicate, sweet flavour than warm-water varieties. Perfect for prawn cocktails and light sandwiches.',
            190 => 'Medium cooked prawns with tail shell retained — ideal for presentation, sharing platters, and dipping. Very high in lean protein, very low in fat. Rich in astaxanthin, selenium, and iodine. The tail acts as a natural handle. Minimally season as they are often lightly salted during processing.',
            191 => 'Raw, wild-caught prawns retain natural sweetness and firm texture best expressed when cooked from raw. High in lean protein, very low in fat. Wild origin typically delivers a more complex, nuanced flavour than farmed. Cook minimally — just until pink — to preserve texture and nutrients.',
            192 => 'Premium wild-caught red shrimps from the cold South Atlantic off Argentina (Pleoticus muelleri). Naturally vivid red when raw — unique among prawns. Exceptional sweet, almost lobster-like flavour. Very high in protein, minimal fat. A superior-quality shrimp increasingly sought by UK chefs and fishmongers.',
            193 => 'Whole, raw langoustines with heads and shells intact — the freshest form before any preparation. Lower in calorie-per-gram than cooked as shell and head form a large portion of weight. Head and shell deliver exceptional stock and bisque base. Best grilled halved or poached whole for maximum sweet flavour.',
            194 => 'Soft shell crabs are blue crabs harvested immediately after moulting — the new shell is completely edible. The entire crab can be eaten whole — legs, body, and papery shell. High in lean protein with a delightfully crispy texture when pan-fried. A seasonal delicacy often served in Asian and American cuisine.',
            195 => 'Alaskan king crab clusters — legs and knuckles from one of the world\'s largest and most prized crabs. Exceptionally sweet, firm white meat with impressive protein content. Already cooked and frozen — simply steam or grill to heat. A luxury occasion seafood from well-managed, certified Alaskan fisheries.',
            196 => 'Cleaned squid tubes without tentacles — the most versatile and widely used squid product for rings, stuffing, and grilling. High in protein, very low in fat. Contains taurine for cardiovascular health. Cook very briefly (2 min) or very slowly (20+ min) to avoid a tough, rubbery texture.',
            197 => 'Whole small squid with a mild, sweet flavour that intensifies beautifully when grilled over charcoal. High in protein, very low in fat. Rich in B12 and selenium. Incredibly versatile — grill whole, stuff, stir-fry, or add to seafood soups. Best not overcooked — 2 minutes maximum at high heat.',
            198 => 'Spanish chipirones are tiny squid — a classic tapas ingredient traditionally served whole in their own ink, stuffed with jamón, or flash-fried in olive oil. Sweet, tender, and deeply flavourful. High in lean protein. Extremely quick to cook — ideal for fast, elegant preparations.',
            199 => 'Small, tender cuttlefish with a milder, sweeter flavour than larger varieties. Exceptionally lean with very high protein. Rich in copper, selenium, and taurine for cardiovascular and cognitive support. Cook quickly at high heat or very slowly to keep tender. Delicious chargrilled, stuffed, or in risotto nero.',
            200 => 'Small octopus (Octopus vulgaris) with naturally sweet, tender meat — far more tender than large octopus without requiring lengthy pre-cooking. Very lean, high in protein, rich in iron and B12. Perfect grilled whole, pan-fried, or added to Spanish and Greek salads and stews.',
            201 => 'Black cod (Anoplopoma fimbria / sablefish) has one of the highest omega-3 concentrations of any fish — silky, buttery flesh that melts in the mouth. Made globally famous by Nobu\'s miso black cod recipe. From well-managed Alaskan and Canadian Pacific fisheries. A genuinely exceptional, unique eating experience.',
            202 => 'Chilean sea bass (Patagonian toothfish, Dissostichus eleginoides) has buttery, rich, flaky flesh with outstanding omega-3 content. A premium white fish with depth of flavour. Moderate mercury — enjoy occasionally. Choose only from MSC-certified fisheries as illegal, unregulated fishing remains a concern for this species.',
            203 => 'Pangasius (Basa, Tra) is a farmed Vietnamese catfish prized for its mild, white, boneless fillets. Very lean and affordable. Lower in omega-3s than cold-water fish, so best served alongside oily fish or omega-3-rich sides. Look for fillets from farms certified by ASC or GlobalG.A.P. for the most responsibly produced product.',
            204 => 'Blanched whitebait are tiny whole juvenile fish — traditionally sprats or herring fry — briefly parboiled to set the flesh before serving or further cooking. Eaten whole including bones, so exceptionally high in calcium and vitamin D. A classic British seasonal treat. Contains bones which provide extra minerals with no extra calories.',
            205 => 'Fresh plain whitebait — tiny whole juvenile fish at their most natural. Best flash-fried in seasoned flour for the classic British pub dish, or coated in light tempura batter. Eating the whole fish including head and bones delivers outstanding calcium, phosphorus, and B12. A genuine spring and summer seasonal speciality.',
            206 => 'Caribbean spiny lobster (Panulirus argus) tails from the Bahamas — no claws, all sweet tail meat. Very lean, high protein, and naturally low in fat. A superb luxury shellfish with clean, oceanic flavour. Grill or steam with butter and citrus. Often considered sweeter and more tender than European lobster when from well-managed Caribbean fisheries.',
            207 => 'Black tobiko — flying fish roe (Cypselurus spp.) coloured jet black with squid ink. Nutritionally identical to standard tobiko: high in omega-3s, lean protein, and B12. The squid ink gives a subtle briny, umami depth beyond the natural tobiko flavour. A dramatic garnish for sushi platters, canapés, and blinis. Contains both fish and mollusc allergens.',
            208 => 'Masago are the tiny roe of the capelin (Mallotus villosus), a small Arctic smelt. Smaller and more affordable than tobiko, with a delicate, mildly briny flavour and satisfying pop. High in omega-3s relative to their size. Widely used in California rolls, sashimi garnishes, and sushi platters. A lower-cost, high-flavour roe alternative.',
            209 => 'Black masago — capelin roe coloured with squid ink, giving a dramatic appearance and subtle extra umami. Nutritionally identical to orange masago: high in omega-3s, lean protein, and B12. The squid ink adds a gentle brininess. Contains fish and mollusc allergens. A striking, affordable garnish for sushi, seafood canapés, and chefs\' platters.',
            210 => 'Sushi ebi are specially prepared cooked prawns for sushi — typically butterflied, skewered, and lightly sweetened to a characteristic pale pink translucent finish. Very lean and high in protein. The gentle sweetness balances beautifully with sushi rice vinegar. Usually made from black tiger or Pacific white prawns. One of the most popular toppings in nigiri sushi worldwide.',
            211 => 'Snow crab (Chionoecetes opilio) from cold North Atlantic and Pacific waters has sweet, delicate, slightly fibrous meat — finer and more subtle than king crab. Very lean, high protein, rich in zinc and B12. Well-managed Canadian and Norwegian MSC-certified fisheries make this one of the most sustainable crab options available. Excellent cold in salads, or warmed with drawn butter.',
            212 => 'Pouting (also called bib or pout, Trisopterus luscus) is an underrated, sustainably abundant UK white fish from the cod family. Very fresh and tender when caught locally, with mild, sweet flesh. Lean and inexpensive — an excellent choice for supporting UK inshore fishermen while eating nutritiously. Best eaten within a day or two of catch as the flesh softens quickly.',
            213 => 'Black sea bream (Spondyliosoma cantharus) has firm, sweet, slightly pinkish flesh with a flavour often described as superior to farmed sea bream. A popular UK summer fish with excellent sustainability credentials from the MCS. Particularly good pan-fried whole or on the barbecue. Season runs May to October when the fish move inshore to rocky UK coastlines.',
            214 => 'Garfish (Belone belone) is a remarkable elongated fish with distinctive blue-green iridescent bones — perfectly harmless but startling the first time. The flesh is lean, clean, and delicate. Excellent pan-fried in butter with lemon. A UK seasonal species arriving inshore in spring, best April to July. Highly sustainable — often a by-catch of bass and mackerel fisheries.',
            215 => 'European smelt (Osmerus eperlanus) is a small, silvery fish famous for its distinctive fresh cucumber fragrance — a reliable indicator of peak freshness. Delicate, slightly sweet flesh with a soft texture. Traditionally dusted in flour and fried whole, eaten bones and all for extra calcium. A genuine seasonal UK treat, most available in early spring when fish run upriver to spawn.',
            216 => 'Cornish pilchards are mature sardines (Sardina pilchardus) — larger, richer, and more intensely flavoured than their juvenile counterparts. Historically the cornerstone of the Cornish fishing industry. Packed with omega-3s, vitamin D, and B12. MSC-certified Cornish pilchards from hand-line and drift-net fisheries are among the most sustainably caught oily fish in British waters. Best from July to November.',
            217 => 'Albacore (Thunnus alalunga) is the premium white-flesh tuna — prized for its pale flesh, delicate flavour, and exceptionally high omega-3 content compared to skipjack and yellowfin. Notably richer in omega-3s than most tuna species. Choose pole-and-line caught albacore for the best sustainability credentials. Excellent seared, grilled, or in premium canned preparations.',
            218 => 'Yellowfin tuna (Thunnus albacares) has lean, firm, meaty flesh with a mild, slightly sweet flavour — the most popular sashimi tuna in UK restaurants. Lower in fat and omega-3s than bluefin or albacore but still a very good source of protein, selenium, and B12. Moderate mercury — enjoy regularly but not daily. Best seared rare or served as sashimi at peak freshness.',
            219 => 'Wahoo (Acanthocybium solandri) is a fast, elegant pelagic fish prized for its exceptionally clean, white, moist flesh and mild flavour — often described as one of the finest eating fish in the ocean. Moderately high in omega-3s and very lean. Best simply prepared — grilled with olive oil and herbs. UK availability depends on imports from tropical Atlantic and Indian Ocean fisheries.',
            220 => 'Queen scallops (Aequipecten opercularis) are the smaller, sweeter relatives of king scallops — tender and delicate rather than meaty. UK waters around Scotland, Ireland, and the West Country produce outstanding queens. Very lean and high in protein. Best flash-seared in a hot pan with butter, or served raw in thin slices with citrus. More affordable than king scallops with a distinct, sweet, almost nutty flavour.',
            221 => 'Ready-shucked cooked mussel meat delivers outstanding zinc, selenium, manganese, omega-3s, and B12 in a convenient ready-to-use format. Exceptionally eco-friendly: rope-grown mussels actively improve water quality and require no feed, fertiliser, or freshwater. Ideal for pasta, paella, soups, and risotto. One of the most nutrient-dense, affordable, and sustainable seafood products available globally.',
            222 => 'Goose barnacles (Percebes, Pollicipes pollicipes) are one of the world\'s most prized shellfish — hand-harvested at great risk from Atlantic storm-beaten rocks. Intensely briny, oceanic, slightly sweet meat. Simply boil in seawater and eat with your hands — one of the purest seafood experiences possible. A rare luxury when available from UK specialist fishmongers, typically from Galician and Portuguese coasts.',
            223 => 'Mantis shrimp (Squilla mantis) have extraordinarily sweet, succulent meat with a flavour often compared to lobster or langoustine but more delicate. A prized delicacy across Mediterranean and Asian cuisines. Occasionally available from UK specialist fishmongers. Best steamed or grilled with butter and garlic. Rich in lean protein and minerals with a low calorie count.',
            224 => 'Hot-smoked eel has an extraordinarily rich, silky, intensely savoury flavour — one of the finest smoked fish products available in the UK. Traditionally served with horseradish or watercress. Very high in fat and calories but highly nutritious with excellent omega-3 and B vitamin content. The European eel (Anguilla anguilla) is critically endangered — choose only smoked eel from certified sustainable aquaculture sources.',
            225 => 'Hot-smoked sprats are tiny whole silvery fish with deeply smoky, intensely rich, oil-laden flesh — eaten whole including bones for outstanding calcium and phosphorus. Among the most omega-3-dense foods per gram available. An underrated, affordable British smoked fish with a long heritage in East Anglian and Baltic smoking traditions. Perfect with rye bread, cream cheese, and dill.',
            226 => 'Cold-smoked halibut has a uniquely delicate, silky, translucent texture — milder and more subtle than smoked salmon. Very lean with moderate omega-3 content. Pairs beautifully with cucumber, crème fraîche, dill, and lemon. Choose from well-managed North Atlantic or Pacific fisheries — halibut stocks vary considerably by region and fishing method.',
            227 => 'Soft herring roe (milt from male herring) is a traditional British delicacy most popular in spring when herring are in spawn. Extremely tender, delicately flavoured, and rich in protein and B12. Classically pan-fried in seasoned flour with butter and lemon. An affordable, seasonal, nutritionally excellent product that is somewhat overlooked by modern British diners but revered by those who know it.',
            228 => 'Smoked mackerel pâté is one of Britain\'s most beloved fish preparations — rich, smoky, creamy, and packed with the outstanding omega-3s of smoked mackerel. Made by blending hot-smoked mackerel with cream cheese or crème fraîche, lemon, and horseradish. A genuinely nutritious indulgence: high in healthy fats, protein, and B12. Excellent on toast, with oatcakes, or as a canapé base.',
            229 => 'Traditional British fish pie mix is typically a combination of raw diced salmon, smoked haddock, and cod or pollock — providing contrasting textures, colours, and a balance of oily and white fish nutrition. Rich in omega-3s from the salmon, lean protein from the white fish, and smoky depth from the haddock. The foundation of one of Britain\'s most comforting dishes.',
            230 => 'The classic British prawn cocktail — chilled prawns in a tangy Marie Rose sauce of mayonnaise, ketchup, Worcestershire sauce, and lemon — remains one of the UK\'s most popular restaurant starters. Calorie content varies by sauce quantity; the prawns themselves are very lean and high in protein. For a lighter version, use half the sauce quantity. A nostalgic UK classic that deserves its enduring popularity.',
            231 => 'A dressed lobster is a whole cooked native lobster (Homarus gammarus) with the meat extracted, prepared, and returned to the cleaned shell for presentation. All lobster meat — claw, knuckle, and tail — is included. Leaner than dressed crab, with clean, sweet, intensely flavoured meat. One of the finest ready-prepared shellfish products at any fishmonger. Best simply with good mayonnaise and brown bread.',
            232 => 'Boquerones are fresh white anchovies cured in vinegar rather than salt, then finished in olive oil with garlic and parsley. The acid cure gives them a milder, brighter flavour than salt-packed anchovies. Very high in omega-3s and protein. A Spanish tapa staple increasingly popular in UK delis and fishmongers. The vinegar cure denatures proteins without heat — technically a ceviche-style preparation.',
            233 => 'Smoked mussels in olive oil deliver concentrated umami, omega-3s, and the impressive mineral profile of fresh mussels — zinc, selenium, manganese, and B12 — in a convenient ready-to-eat form. Delicious straight from the jar, in pasta dishes, on bruschetta, or added to seafood salads. Rope-farmed mussels are among the most sustainable seafood choices available globally.',
            234 => 'Carrageen (Irish moss, Chondrus crispus) is a red seaweed with a long history in Irish and British coastal cuisine. Naturally rich in carrageenan — a gel-forming polysaccharide — making it a traditional thickener for blancmanges, puddings, and drinks. Rich in iodine, potassium, and magnesium. Used in traditional Irish cooking for centuries to make carrageen milk pudding.',
            235 => 'Hijiki (Sargassum fusiforme) is a dark, thread-like seaweed with an intense ocean flavour used extensively in Japanese cooking. Rich in fibre, calcium, and magnesium. Note: the UK Food Standards Agency advises limiting hijiki consumption due to naturally elevated inorganic arsenic levels — occasional enjoyment in small quantities carries minimal risk, but it should not be eaten daily.',
        ];
    }

    // ── DB-backed getHealthTips() extractor ──────────────────────────────────
    private function getHealthTips() {
        $out = [];
        foreach ( $this->getFoodDatabase() as $f ) {
            $out[(int)$f['id']] = $f['health_tip'] ?? '';
        }
        return $out;
    }

    // ── Plugin Activation ─────────────────────────────────────────────────────
    public static function activate() {
        global $wpdb;
        $cs = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $analytics = $wpdb->prefix . 'fcc_analytics';
        dbDelta( "CREATE TABLE IF NOT EXISTS $analytics (
            food_id   mediumint(9) NOT NULL,
            food_name varchar(100) NOT NULL,
            searches  mediumint(9) DEFAULT 0,
            calcs     mediumint(9) DEFAULT 0,
            PRIMARY KEY (food_id)
        ) $cs;" );
        add_option( 'fcc_omega3_target',  0.5  );
        add_option( 'fcc_daily_calories', 2000 );

        $foods_tbl = $wpdb->prefix . 'fcc_foods';
        dbDelta( "CREATE TABLE IF NOT EXISTS $foods_tbl (
            id         mediumint(9)  NOT NULL AUTO_INCREMENT,
            name       varchar(100)  NOT NULL DEFAULT '',
            category   varchar(50)   NOT NULL DEFAULT '',
            calories   decimal(7,2)  DEFAULT 0,
            protein    decimal(6,2)  DEFAULT 0,
            carbs      decimal(6,2)  DEFAULT 0,
            fat        decimal(6,2)  DEFAULT 0,
            fiber      decimal(6,2)  DEFAULT 0,
            sugar      decimal(6,2)  DEFAULT 0,
            omega3     decimal(6,2)  DEFAULT 0,
            mercury    varchar(20)   DEFAULT 'low',
            allergens  varchar(100)  DEFAULT '',
            eco_rating varchar(10)   DEFAULT 'ok',
            eco_source varchar(10)   DEFAULT 'wild',
            season     varchar(50)   DEFAULT 'All year',
            health_tip text,
            sort_order     int        DEFAULT 0,
            sort_priority  int        DEFAULT 0,
            PRIMARY KEY (id)
        ) $cs;" );

        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $foods_tbl" );
        if ( $count === 0 ) {
            self::getInstance()->seedFoodsTable();
        }

        $daily_tbl = $wpdb->prefix . 'fcc_analytics_daily';
        dbDelta( "CREATE TABLE IF NOT EXISTS $daily_tbl (
            id        int           NOT NULL AUTO_INCREMENT,
            food_id   mediumint(9)  NOT NULL,
            food_name varchar(100)  NOT NULL DEFAULT '',
            category  varchar(50)   NOT NULL DEFAULT '',
            log_date  date          NOT NULL,
            searches  int           DEFAULT 0,
            calcs     int           DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY food_date (food_id, log_date)
        ) $cs;" );

        // Flag used instead of SHOW TABLES LIKE on every request
        update_option( 'fcc_tables_ready', 1 );
        update_option( 'fcc_analytics_ready', 1 );
        // fcc_foods_seeded is NOT set here — ensureFoodsSeeded() sets it after actual seeding
    }

    // ── Settings ──────────────────────────────────────────────────────────────
    private function getSettings() {
        return [
            'omega3_target'        => floatval( get_option( 'fcc_omega3_target',        0.5     ) ),
            'daily_calories'       => intval(   get_option( 'fcc_daily_calories',        2000    ) ),
            'protein_target'       => intval(   get_option( 'fcc_protein_target',        50      ) ),
            'fat_target'           => intval(   get_option( 'fcc_fat_target',            70      ) ),
            'carbs_target'         => intval(   get_option( 'fcc_carbs_target',          260     ) ),
            'default_serving'      => intval(   get_option( 'fcc_default_serving',       100     ) ),
            'default_method'       => sanitize_text_field( get_option( 'fcc_default_method', 'baked' ) ),
            'widget_title'         => sanitize_text_field( get_option( 'fcc_widget_title',   ''      ) ),
            'show_tracker'         => intval(   get_option( 'fcc_show_tracker',          1       ) ),
            'show_compare'         => intval(   get_option( 'fcc_show_compare',          1       ) ),
            'show_filter_bar'      => intval(   get_option( 'fcc_show_filter_bar',       1       ) ),
            'show_health_tips'     => intval(   get_option( 'fcc_show_health_tips',      1       ) ),
            'show_allergens'       => intval(   get_option( 'fcc_show_allergens',        1       ) ),
            'show_eco'             => intval(   get_option( 'fcc_show_eco',              1       ) ),
            'show_mercury'         => intval(   get_option( 'fcc_show_mercury',          1       ) ),
            'show_season'          => intval(   get_option( 'fcc_show_season',           1       ) ),
            'show_request_btn'     => intval(   get_option( 'fcc_show_request_btn',      1       ) ),
            'auto_log_searches'    => intval(   get_option( 'fcc_auto_log_searches',     1       ) ),
            'search_results_limit'  => intval( get_option( 'fcc_search_results_limit',   8 ) ),
            'search_min_chars'      => intval( get_option( 'fcc_search_min_chars',       1 ) ),
            'search_name_priority'  => intval( get_option( 'fcc_search_name_priority',   1 ) ),
        ];
    }

    // ── DB Migration: sort_priority column ───────────────────────────────────
    public function migrateSortPriorityCol() {
        global $wpdb;
        $tbl = $wpdb->prefix . 'fcc_foods';
        $col = $wpdb->get_var( "SHOW COLUMNS FROM `$tbl` LIKE 'sort_priority'" );
        if ( ! $col ) {
            $wpdb->query( "ALTER TABLE `$tbl` ADD COLUMN `sort_priority` INT NOT NULL DEFAULT 0" );
        }
        update_option( 'fcc_sort_priority_col', 1 );
    }

    // ── DB Migration: sync analytics food names with current food names ───────
    public function syncAnalyticsFoodNames() {
        global $wpdb;
        $foods = $wpdb->prefix . 'fcc_foods';
        $anal  = $wpdb->prefix . 'fcc_analytics';
        $daily = $wpdb->prefix . 'fcc_analytics_daily';
        $wpdb->query( "UPDATE `$anal`  a JOIN `$foods` f ON a.food_id = f.id SET a.food_name  = f.name WHERE a.food_name  != f.name" );
        $wpdb->query( "UPDATE `$daily` d JOIN `$foods` f ON d.food_id = f.id SET d.food_name  = f.name WHERE d.food_name  != f.name" );
        delete_transient( 'fcc_top_trending' );
        update_option( 'fcc_analytics_name_sync_v1', 1 );
    }

    // ── Search match score (lower = better rank) ─────────────────────────────
    private function searchMatchScore( $food, $query ) {
        $name = strtolower( $food['name'] );
        $q    = strtolower( $query );
        if ( strpos( $name, $q ) === 0 )    return 0; // name starts with query
        if ( strpos( $name, $q ) !== false ) return 1; // name contains query
        return 2;                                       // category match only
    }

    // ── PDF Print Settings ───────────────────────────────────────────────────
    private function getPdfSettings() {
        $defaults = [
            'whatsapp_number'     => '447367067305',
            'buy_btn_label'       => 'WhatsApp Us →',
            'caviar_subtitle'     => 'Order from Fishmonger London · Dispatched within 24 hours · UK-wide delivery',
            'seafood_subtitle'    => 'Order directly from Fishmonger London · Next-day wholesale delivery in Greater London',
            'wholesale_title'     => 'Wholesale Seafood',
            'wholesale_body1'     => 'Fresh & frozen · All seafood types',
            'wholesale_body2'     => 'Greater London & surrounding areas',
            'wholesale_body3'     => 'Restaurants · Hotels · Caterers · Events · Corporates',
            'wholesale_link_text' => 'Get a Quote →',
            'wholesale_wa_msg'    => "Hi, I'm interested in wholesale seafood",
            'caviar_title'        => 'Premium Caviar',
            'caviar_types'        => 'Beluga · Oscietra · Sevruga · Sturgeon · Salmon Roe (Keta)',
            'caviar_delivery'     => 'Delivered across the UK',
            'caviar_link_text'    => 'Shop Caviar →',
            'caviar_link_url'     => 'https://fishmongerlondon.co.uk/collection/caviar/',
            'trust_1'             => '✓ MSC Certified',
            'trust_2'             => '✓ Next-day Delivery Available',
            'trust_3'             => '✓ Wholesale & Retail',
            'trust_4'             => '✓ UK-wide Caviar Delivery',
            'footer_link1_text'   => 'Buy Caviar',
            'footer_link1_url'    => 'https://fishmongerlondon.co.uk/collection/caviar/',
            'footer_link2_text'   => 'Buy Beluga Caviar',
            'footer_link2_url'    => 'https://fishmongerlondon.co.uk/collection/beluga-caviar/',
            'footer_link3_text'   => 'Seafood Calorie Calculator',
            'footer_link3_url'    => 'https://fishmongerlondon.co.uk/seafood-calorie-calculator/',
            'footer_link4_text'   => '💬 WhatsApp Us',
            'footer_link4_url'    => 'https://wa.me/447367067305',
            'disclaimer_show'     => '1',
            'disclaimer_text'     => 'Nutritional values are per 100g and for general information only. Not a substitute for professional dietary or medical advice. If you have a health condition, allergy, or are pregnant, consult a qualified healthcare professional. All listed products may contain fish, crustaceans, or molluscs. Data reviewed June 2026.',
        ];
        return wp_parse_args( get_option( 'fcc_pdf_settings', [] ), $defaults );
    }

    // ── Cooking Method Multipliers ────────────────────────────────────────────
    private function getCookingMethods() {
        return [
            [ 'key'=>'baked',      'label'=>'Baked',      'cal'=>1.00, 'fat'=>1.00, 'prot'=>1.00 ],
            [ 'key'=>'raw',        'label'=>'Raw',        'cal'=>0.95, 'fat'=>1.00, 'prot'=>0.95 ],
            [ 'key'=>'steamed',    'label'=>'Steamed',    'cal'=>0.97, 'fat'=>0.92, 'prot'=>1.00 ],
            [ 'key'=>'grilled',    'label'=>'Grilled',    'cal'=>1.05, 'fat'=>0.85, 'prot'=>1.05 ],
            [ 'key'=>'poached',    'label'=>'Poached',    'cal'=>0.96, 'fat'=>0.90, 'prot'=>1.00 ],
            [ 'key'=>'pan_fried',  'label'=>'Pan Fried',  'cal'=>1.35, 'fat'=>2.20, 'prot'=>0.95 ],
            [ 'key'=>'deep_fried', 'label'=>'Deep Fried', 'cal'=>1.85, 'fat'=>3.80, 'prot'=>0.85 ],
            [ 'key'=>'smoked',     'label'=>'Smoked',     'cal'=>1.10, 'fat'=>1.05, 'prot'=>1.10 ],
        ];
    }

    // ── Allergen Data — hardcoded defaults ───────────────────────────────────
    private function getDefaultAllergens() {
        return [
            1=>'Fish',  2=>'Fish',  3=>'Fish',  4=>'Fish',  5=>'Fish',  6=>'Fish',
            7=>'Fish',  8=>'Fish',  9=>'Fish',  10=>'Fish', 11=>'Fish', 12=>'Fish',
            13=>'Fish', 14=>'Fish', 15=>'Fish', 16=>'Fish', 17=>'Fish', 18=>'Fish',
            19=>'Fish', 20=>'Fish', 21=>'Fish',
            22=>'Crustaceans', 23=>'Crustaceans', 24=>'Crustaceans',
            25=>'Crustaceans', 26=>'Crustaceans', 31=>'Crustaceans',
            27=>'Molluscs', 28=>'Molluscs', 29=>'Molluscs',
            30=>'Molluscs', 32=>'Molluscs', 33=>'Molluscs',
            34=>'Molluscs', 35=>'Molluscs+Gluten', 36=>'Molluscs',
            37=>'Fish',  38=>'Fish',  39=>'Fish',  40=>'Fish',  41=>'Fish',
            42=>'Fish',  43=>'Fish',  44=>'Fish',  45=>'Fish',  46=>'Fish',
            47=>'Fish',  48=>'Fish',  49=>'Fish',
            50=>'Fish',  51=>'Fish',  52=>'Fish',  53=>'Fish+Gluten',
            54=>'Fish+Gluten', 55=>'Fish+Gluten', 56=>'Fish+Gluten',
            57=>'Crustaceans+Gluten', 58=>'Crustaceans+Gluten',
            59=>'None',  60=>'None',
            61=>'Fish',  62=>'Fish',  63=>'Fish',  64=>'Fish',  65=>'Fish',
            66=>'Fish',  67=>'Fish',  68=>'Fish',  69=>'Fish',  70=>'Fish',
            71=>'Fish',  72=>'Fish',  73=>'Fish',  74=>'Molluscs',
            75=>'Fish',  76=>'Fish',  77=>'Fish',
            78=>'Fish+Crustaceans+Molluscs',
            79=>'Fish',  80=>'Fish',  81=>'Fish',  82=>'Fish',  83=>'Fish',  84=>'Fish',
            85=>'Crustaceans', 86=>'Crustaceans', 87=>'Molluscs',
            88=>'Fish',  89=>'Molluscs', 90=>'Fish',
            91=>'Fish',  92=>'Fish',  93=>'Fish',  94=>'Fish',  95=>'Fish',
            96=>'Fish',  97=>'Fish',  98=>'Fish',  99=>'Fish',  100=>'Fish',
            101=>'Fish', 102=>'Fish', 103=>'Fish', 104=>'Fish', 105=>'Fish',
            106=>'Fish', 107=>'Molluscs', 108=>'Molluscs', 109=>'Fish',
            110=>'Fish', 111=>'Fish', 112=>'Fish', 113=>'Fish', 114=>'Fish',
            115=>'Fish', 116=>'Fish', 117=>'Fish',
            118=>'None', 119=>'Fish', 120=>'Fish', 121=>'Fish', 122=>'Molluscs',
            123=>'Fish', 124=>'Fish', 125=>'Fish', 126=>'Fish', 127=>'Fish',
            128=>'Fish', 129=>'Fish', 130=>'Fish',
            131=>'Molluscs',    132=>'Molluscs',    133=>'Molluscs',
            134=>'Crustaceans', 135=>'Crustaceans', 136=>'Crustaceans',
            137=>'Crustaceans', 138=>'Crustaceans', 139=>'Crustaceans',
            140=>'Molluscs',    141=>'Crustaceans', 142=>'Crustaceans',
            143=>'Crustaceans', 144=>'Crustaceans', 145=>'Molluscs',
            146=>'None',        147=>'None',         148=>'None',
            149=>'None',        150=>'None',         151=>'Crustaceans',
            152=>'Molluscs',    153=>'Crustaceans',
            154=>'Fish',  155=>'Fish',  156=>'Fish',  157=>'Fish',  158=>'Fish',
            159=>'Fish',  160=>'Fish',  161=>'Fish',  162=>'Fish',  163=>'Fish',
            164=>'Crustaceans', 165=>'Fish', 166=>'Fish', 167=>'Fish',
            168=>'Fish',  169=>'Fish',  170=>'Fish',
            171=>'Molluscs', 172=>'Molluscs',
            173=>'None',  174=>'None',  175=>'None',
            176=>'Fish+Crustaceans+Molluscs', 177=>'Crustaceans', 178=>'Crustaceans',
            179=>'Fish',  180=>'Fish+Molluscs', 181=>'Fish',
            182=>'Fish',  183=>'Fish',  184=>'Fish',  185=>'Fish',  186=>'Fish',
            187=>'Crustaceans', 188=>'Crustaceans', 189=>'Crustaceans',
            190=>'Crustaceans', 191=>'Crustaceans', 192=>'Crustaceans',
            193=>'Crustaceans', 194=>'Crustaceans', 195=>'Crustaceans',
            196=>'Molluscs', 197=>'Molluscs', 198=>'Molluscs',
            199=>'Molluscs', 200=>'Molluscs',
            201=>'Fish',  202=>'Fish',
            203=>'Fish',
            204=>'Fish',        205=>'Fish',
            206=>'Crustaceans', 207=>'Fish+Molluscs',
            208=>'Fish',        209=>'Fish+Molluscs',
            210=>'Crustaceans', 211=>'Crustaceans',
            212=>'Fish',        213=>'Fish',
            214=>'Fish',        215=>'Fish',
            216=>'Fish',        217=>'Fish',
            218=>'Fish',        219=>'Fish',
            220=>'Molluscs',    221=>'Molluscs',
            222=>'Crustaceans', 223=>'Crustaceans',
            224=>'Fish',        225=>'Fish',
            226=>'Fish',        227=>'Fish',
            228=>'Fish',        229=>'Fish',
            230=>'Crustaceans', 231=>'Crustaceans',
            232=>'Fish',        233=>'Molluscs',
            234=>'None',        235=>'None',
        ];
    }

    // ── DB-backed getAllergens() extractor ────────────────────────────────────
    private function getAllergens() {
        $out = [];
        foreach ( $this->getFoodDatabase() as $f ) {
            $out[(int)$f['id']] = $f['allergens'] ?? '';
        }
        return $out;
    }

    // ── Sustainability / Eco Data — hardcoded defaults ────────────────────────
    // r = rating (good/ok/avoid)  s = source (wild/farmed/mixed)
    private function getDefaultEcoData() {
        return [
            1=>['r'=>'good','s'=>'wild'],   2=>['r'=>'good','s'=>'wild'],
            3=>['r'=>'good','s'=>'wild'],   4=>['r'=>'ok',  's'=>'wild'],
            5=>['r'=>'ok',  's'=>'farmed'], 6=>['r'=>'good','s'=>'wild'],
            7=>['r'=>'ok',  's'=>'wild'],   8=>['r'=>'ok',  's'=>'farmed'],
            9=>['r'=>'ok',  's'=>'wild'],   10=>['r'=>'ok', 's'=>'wild'],
            11=>['r'=>'good','s'=>'wild'],  12=>['r'=>'avoid','s'=>'wild'],
            13=>['r'=>'ok',  's'=>'farmed'],14=>['r'=>'ok', 's'=>'wild'],
            15=>['r'=>'good','s'=>'wild'],  16=>['r'=>'good','s'=>'wild'],
            17=>['r'=>'good','s'=>'wild'],  18=>['r'=>'good','s'=>'farmed'],
            19=>['r'=>'good','s'=>'wild'],  20=>['r'=>'good','s'=>'wild'],
            21=>['r'=>'ok',  's'=>'farmed'],22=>['r'=>'ok', 's'=>'farmed'],
            23=>['r'=>'ok',  's'=>'farmed'],24=>['r'=>'ok', 's'=>'wild'],
            25=>['r'=>'good','s'=>'wild'],  26=>['r'=>'good','s'=>'wild'],
            27=>['r'=>'good','s'=>'farmed'],28=>['r'=>'good','s'=>'farmed'],
            29=>['r'=>'ok',  's'=>'wild'],  30=>['r'=>'ok', 's'=>'farmed'],
            31=>['r'=>'ok',  's'=>'wild'],  32=>['r'=>'good','s'=>'wild'],
            33=>['r'=>'ok',  's'=>'wild'],  34=>['r'=>'good','s'=>'wild'],
            35=>['r'=>'ok',  's'=>'wild'],  36=>['r'=>'ok', 's'=>'wild'],
            37=>['r'=>'ok',  's'=>'wild'],  38=>['r'=>'ok', 's'=>'wild'],
            39=>['r'=>'ok',  's'=>'farmed'],40=>['r'=>'good','s'=>'wild'],
            41=>['r'=>'good','s'=>'wild'],  42=>['r'=>'good','s'=>'wild'],
            43=>['r'=>'good','s'=>'wild'],  44=>['r'=>'ok', 's'=>'farmed'],
            45=>['r'=>'good','s'=>'wild'],  46=>['r'=>'good','s'=>'wild'],
            47=>['r'=>'good','s'=>'farmed'],48=>['r'=>'good','s'=>'wild'],
            49=>['r'=>'ok',  's'=>'farmed'],50=>['r'=>'ok', 's'=>'wild'],
            51=>['r'=>'ok',  's'=>'farmed'],52=>['r'=>'avoid','s'=>'wild'],
            53=>['r'=>'ok',  's'=>'wild'],  54=>['r'=>'ok', 's'=>'wild'],
            55=>['r'=>'ok',  's'=>'mixed'], 56=>['r'=>'ok', 's'=>'mixed'],
            57=>['r'=>'ok',  's'=>'farmed'],58=>['r'=>'ok', 's'=>'farmed'],
            59=>['r'=>'good','s'=>'farmed'],60=>['r'=>'good','s'=>'farmed'],
            61=>['r'=>'good','s'=>'farmed'],62=>['r'=>'good','s'=>'wild'],
            63=>['r'=>'good','s'=>'farmed'],64=>['r'=>'ok', 's'=>'wild'],
            65=>['r'=>'good','s'=>'wild'],  66=>['r'=>'good','s'=>'wild'],
            67=>['r'=>'good','s'=>'wild'],  68=>['r'=>'good','s'=>'farmed'],
            69=>['r'=>'good','s'=>'wild'],  70=>['r'=>'ok', 's'=>'wild'],
            71=>['r'=>'good','s'=>'wild'],  72=>['r'=>'ok', 's'=>'wild'],
            73=>['r'=>'avoid','s'=>'wild'], 74=>['r'=>'good','s'=>'wild'],
            75=>['r'=>'ok',  's'=>'mixed'], 76=>['r'=>'ok', 's'=>'mixed'],
            77=>['r'=>'ok',  's'=>'mixed'], 78=>['r'=>'ok', 's'=>'mixed'],
            79=>['r'=>'ok',  's'=>'wild'],  80=>['r'=>'ok',   's'=>'wild'],
            81=>['r'=>'good','s'=>'wild'],  82=>['r'=>'ok',   's'=>'wild'],
            83=>['r'=>'good','s'=>'wild'],  84=>['r'=>'avoid','s'=>'wild'],
            85=>['r'=>'good','s'=>'wild'],  86=>['r'=>'good', 's'=>'wild'],
            87=>['r'=>'good','s'=>'wild'],  88=>['r'=>'ok',   's'=>'wild'],
            89=>['r'=>'good','s'=>'wild'],  90=>['r'=>'ok',    's'=>'wild'],
            91=>['r'=>'ok',  's'=>'wild'],   92=>['r'=>'ok',    's'=>'wild'],
            93=>['r'=>'good','s'=>'wild'],   94=>['r'=>'avoid', 's'=>'wild'],
            95=>['r'=>'good','s'=>'wild'],   96=>['r'=>'ok',    's'=>'farmed'],
            97=>['r'=>'ok',  's'=>'mixed'],  98=>['r'=>'ok',    's'=>'wild'],
            99=>['r'=>'good','s'=>'wild'],   100=>['r'=>'ok',   's'=>'wild'],
            101=>['r'=>'avoid','s'=>'wild'], 102=>['r'=>'good', 's'=>'wild'],
            103=>['r'=>'ok', 's'=>'wild'],   104=>['r'=>'ok',   's'=>'wild'],
            105=>['r'=>'ok', 's'=>'wild'],   106=>['r'=>'ok',   's'=>'farmed'],
            107=>['r'=>'ok', 's'=>'wild'],   108=>['r'=>'good', 's'=>'wild'],
            109=>['r'=>'avoid','s'=>'wild'], 110=>['r'=>'ok',   's'=>'wild'],
            111=>['r'=>'good','s'=>'wild'],  112=>['r'=>'good', 's'=>'wild'],
            113=>['r'=>'ok', 's'=>'wild'],   114=>['r'=>'avoid','s'=>'wild'],
            115=>['r'=>'ok', 's'=>'farmed'], 116=>['r'=>'good', 's'=>'wild'],
            117=>['r'=>'ok',    's'=>'farmed'],
            118=>['r'=>'ok',   's'=>'wild'],   119=>['r'=>'avoid', 's'=>'wild'],
            120=>['r'=>'avoid','s'=>'wild'],   121=>['r'=>'ok',    's'=>'wild'],
            122=>['r'=>'good', 's'=>'wild'],   123=>['r'=>'avoid', 's'=>'farmed'],
            124=>['r'=>'ok',   's'=>'farmed'], 125=>['r'=>'ok',    's'=>'farmed'],
            126=>['r'=>'ok',   's'=>'farmed'], 127=>['r'=>'good',  's'=>'wild'],
            128=>['r'=>'avoid','s'=>'wild'],   129=>['r'=>'good',  's'=>'wild'],
            130=>['r'=>'good', 's'=>'wild'],
            131=>['r'=>'ok',   's'=>'wild'],   132=>['r'=>'ok',   's'=>'wild'],
            133=>['r'=>'ok',   's'=>'wild'],   134=>['r'=>'good', 's'=>'wild'],
            135=>['r'=>'good', 's'=>'wild'],   136=>['r'=>'good', 's'=>'wild'],
            137=>['r'=>'good', 's'=>'wild'],   138=>['r'=>'ok',   's'=>'wild'],
            139=>['r'=>'good', 's'=>'wild'],   140=>['r'=>'good', 's'=>'wild'],
            141=>['r'=>'good', 's'=>'wild'],   142=>['r'=>'ok',   's'=>'farmed'],
            143=>['r'=>'ok',   's'=>'wild'],   144=>['r'=>'ok',   's'=>'wild'],
            145=>['r'=>'good', 's'=>'farmed'], 146=>['r'=>'ok',   's'=>'wild'],
            147=>['r'=>'good', 's'=>'wild'],   148=>['r'=>'good', 's'=>'wild'],
            149=>['r'=>'good', 's'=>'wild'],   150=>['r'=>'good', 's'=>'farmed'],
            151=>['r'=>'ok',   's'=>'wild'],   152=>['r'=>'ok',   's'=>'wild'],
            153=>['r'=>'ok',    's'=>'farmed'],
            154=>['r'=>'good', 's'=>'wild'],   155=>['r'=>'good', 's'=>'wild'],
            156=>['r'=>'ok',   's'=>'wild'],   157=>['r'=>'good', 's'=>'wild'],
            158=>['r'=>'ok',   's'=>'mixed'],  159=>['r'=>'ok',   's'=>'farmed'],
            160=>['r'=>'ok',   's'=>'farmed'], 161=>['r'=>'good', 's'=>'wild'],
            162=>['r'=>'ok',   's'=>'wild'],   163=>['r'=>'good', 's'=>'wild'],
            164=>['r'=>'ok',   's'=>'farmed'], 165=>['r'=>'avoid','s'=>'farmed'],
            166=>['r'=>'avoid','s'=>'farmed'], 167=>['r'=>'avoid','s'=>'farmed'],
            168=>['r'=>'avoid','s'=>'wild'],   169=>['r'=>'ok',   's'=>'wild'],
            170=>['r'=>'ok',   's'=>'wild'],   171=>['r'=>'ok',   's'=>'wild'],
            172=>['r'=>'good', 's'=>'wild'],   173=>['r'=>'good', 's'=>'farmed'],
            174=>['r'=>'good', 's'=>'wild'],   175=>['r'=>'good', 's'=>'wild'],
            176=>['r'=>'ok',   's'=>'mixed'],  177=>['r'=>'ok',   's'=>'wild'],
            178=>['r'=>'ok',   's'=>'wild'],   179=>['r'=>'ok',   's'=>'farmed'],
            180=>['r'=>'ok',   's'=>'mixed'],  181=>['r'=>'good', 's'=>'wild'],
            182=>['r'=>'ok',   's'=>'wild'],   183=>['r'=>'ok',   's'=>'wild'],
            184=>['r'=>'ok',   's'=>'wild'],   185=>['r'=>'ok',   's'=>'wild'],
            186=>['r'=>'ok',   's'=>'mixed'],  187=>['r'=>'ok',   's'=>'farmed'],
            188=>['r'=>'ok',   's'=>'wild'],   189=>['r'=>'ok',   's'=>'farmed'],
            190=>['r'=>'ok',   's'=>'farmed'], 191=>['r'=>'good', 's'=>'wild'],
            192=>['r'=>'good', 's'=>'wild'],   193=>['r'=>'ok',   's'=>'wild'],
            194=>['r'=>'ok',   's'=>'farmed'], 195=>['r'=>'ok',   's'=>'wild'],
            196=>['r'=>'good', 's'=>'wild'],   197=>['r'=>'good', 's'=>'wild'],
            198=>['r'=>'good', 's'=>'wild'],   199=>['r'=>'ok',   's'=>'wild'],
            200=>['r'=>'ok',   's'=>'wild'],   201=>['r'=>'ok',   's'=>'wild'],
            202=>['r'=>'ok',   's'=>'wild'],
            203=>['r'=>'ok',   's'=>'farmed'], 204=>['r'=>'ok',   's'=>'wild'],
            205=>['r'=>'ok',   's'=>'wild'],   206=>['r'=>'ok',   's'=>'wild'],
            207=>['r'=>'ok',   's'=>'wild'],   208=>['r'=>'ok',   's'=>'wild'],
            209=>['r'=>'ok',   's'=>'wild'],   210=>['r'=>'ok',   's'=>'farmed'],
            211=>['r'=>'good', 's'=>'wild'],
            212=>['r'=>'good', 's'=>'wild'],   213=>['r'=>'good', 's'=>'wild'],
            214=>['r'=>'good', 's'=>'wild'],   215=>['r'=>'ok',   's'=>'wild'],
            216=>['r'=>'good', 's'=>'wild'],   217=>['r'=>'ok',   's'=>'wild'],
            218=>['r'=>'ok',   's'=>'wild'],   219=>['r'=>'ok',   's'=>'wild'],
            220=>['r'=>'ok',   's'=>'wild'],   221=>['r'=>'good', 's'=>'farmed'],
            222=>['r'=>'ok',   's'=>'wild'],   223=>['r'=>'ok',   's'=>'wild'],
            224=>['r'=>'avoid','s'=>'wild'],   225=>['r'=>'good', 's'=>'wild'],
            226=>['r'=>'ok',   's'=>'wild'],   227=>['r'=>'good', 's'=>'wild'],
            228=>['r'=>'ok',   's'=>'wild'],   229=>['r'=>'ok',   's'=>'mixed'],
            230=>['r'=>'ok',   's'=>'farmed'], 231=>['r'=>'ok',   's'=>'wild'],
            232=>['r'=>'ok',   's'=>'wild'],   233=>['r'=>'good', 's'=>'farmed'],
            234=>['r'=>'good', 's'=>'wild'],   235=>['r'=>'ok',   's'=>'wild'],
        ];
    }

    // ── DB-backed getEcoData() extractor ──────────────────────────────────────
    private function getEcoData() {
        $out = [];
        foreach ( $this->getFoodDatabase() as $f ) {
            $out[(int)$f['id']] = ['r' => ($f['eco_rating'] ?? 'ok'), 's' => ($f['eco_source'] ?? 'wild')];
        }
        return $out;
    }

    // ── UK Season Data — hardcoded defaults ───────────────────────────────────
    private function getDefaultSeasonData() {
        return [
            1=>'Oct–May',  2=>'Sep–Feb',  3=>'All year', 4=>'Jun–Mar',
            5=>'All year', 6=>'May–Sep',  7=>'May–Feb',  8=>'All year',
            9=>'All year', 10=>'Jun–Mar', 11=>'All year',12=>'Oct–Apr',
            13=>'All year',14=>'All year',15=>'May–Oct', 16=>'May–Oct',
            17=>'May–Jan', 18=>'All year',19=>'All year',20=>'Sep–Feb',
            21=>'All year',22=>'All year',23=>'All year',24=>'Apr–Oct',
            25=>'Apr–Dec', 26=>'Apr–Dec', 27=>'Sep–Mar', 28=>'Sep–Apr',
            29=>'All year',30=>'Oct–Mar', 31=>'May–Sep', 32=>'All year',
            33=>'All year',34=>'All year',35=>'All year',36=>'All year',
            37=>'All year',38=>'All year',39=>'All year',40=>'All year',
            41=>'All year',42=>'All year',43=>'All year',44=>'All year',
            45=>'All year',46=>'All year',47=>'All year',48=>'All year',
            49=>'All year',50=>'Oct–May', 51=>'All year',52=>'All year',
            53=>'All year',54=>'All year',55=>'All year',56=>'All year',
            57=>'All year',58=>'All year',59=>'All year',60=>'All year',
            61=>'All year',62=>'Apr–Mar', 63=>'All year',64=>'Oct–Mar',
            65=>'Oct–May', 66=>'Oct–May', 67=>'All year',68=>'All year',
            69=>'Sep–May', 70=>'All year',71=>'All year',72=>'All year',
            73=>'All year',74=>'All year',75=>'All year',76=>'All year',
            77=>'All year',78=>'All year',
            79=>'Apr–Feb', 80=>'Sep–Apr', 81=>'Sep–Jun', 82=>'Jun–Mar',
            83=>'Mar–Sep', 84=>'All year',85=>'Apr–Nov', 86=>'Apr–Oct',
            87=>'All year',88=>'May–Sep', 89=>'All year',90=>'Oct–Mar',
            91=>'Jun–Mar',  92=>'All year', 93=>'Sep–Jun',  94=>'All year',
            95=>'Sep–Feb',  96=>'All year', 97=>'All year', 98=>'All year',
            99=>'Jan–Jul',  100=>'All year',101=>'All year',102=>'Apr–Nov',
            103=>'All year',104=>'All year',105=>'All year',106=>'All year',
            107=>'All year',108=>'All year',109=>'All year',110=>'All year',
            111=>'All year',112=>'All year',113=>'May–Feb', 114=>'All year',
            115=>'All year',116=>'May–Oct', 117=>'All year',
            118=>'All year',119=>'All year',120=>'All year',121=>'Oct–Apr',
            122=>'All year',123=>'All year',124=>'Jun–Oct',125=>'All year',
            126=>'All year',127=>'Mar–Sep', 128=>'All year',129=>'May–Mar',
            130=>'All year',
            131=>'All year',132=>'Sep–Apr', 133=>'All year',134=>'Apr–Dec',
            135=>'Apr–Dec', 136=>'Apr–Dec', 137=>'Apr–Dec',138=>'All year',
            139=>'Oct–Mar', 140=>'All year',141=>'May–Oct',142=>'All year',
            143=>'Apr–Oct', 144=>'All year',145=>'Sep–Apr',146=>'Nov–Mar',
            147=>'All year',148=>'Apr–Sep', 149=>'All year',150=>'All year',
            151=>'May–Sep', 152=>'Oct–Mar', 153=>'All year',
            154=>'All year',155=>'May–Jan', 156=>'All year',157=>'All year',
            158=>'All year',159=>'All year',160=>'All year',161=>'May–Jan',
            162=>'All year',163=>'All year',164=>'All year',165=>'All year',
            166=>'All year',167=>'All year',168=>'All year',169=>'All year',
            170=>'All year',171=>'All year',172=>'All year',173=>'All year',
            174=>'Jun–Sep', 175=>'Jun–Sep',
            176=>'All year',177=>'All year',178=>'All year',179=>'All year',
            180=>'All year',181=>'All year',182=>'All year',183=>'All year',
            184=>'All year',185=>'All year',186=>'All year',187=>'All year',
            188=>'All year',189=>'All year',190=>'All year',191=>'All year',
            192=>'All year',193=>'May–Sep', 194=>'All year',195=>'All year',
            196=>'All year',197=>'All year',198=>'All year',199=>'All year',
            200=>'All year',201=>'All year',202=>'All year',
            203=>'All year', 204=>'Feb–Jul',  205=>'Feb–Jul',
            206=>'All year', 207=>'All year', 208=>'All year',
            209=>'All year', 210=>'All year', 211=>'All year',
            212=>'Oct–Apr',  213=>'May–Oct',  214=>'Apr–Jul',
            215=>'Jan–Apr',  216=>'Jul–Nov',  217=>'All year',
            218=>'All year', 219=>'All year', 220=>'Oct–Mar',
            221=>'All year', 222=>'All year', 223=>'All year',
            224=>'All year', 225=>'All year', 226=>'All year',
            227=>'Jan–Apr',  228=>'All year', 229=>'All year',
            230=>'All year', 231=>'Apr–Oct',  232=>'All year',
            233=>'All year', 234=>'All year', 235=>'All year',
        ];
    }

    // ── DB-backed getSeasonData() extractor ───────────────────────────────────
    private function getSeasonData() {
        $out = [];
        foreach ( $this->getFoodDatabase() as $f ) {
            $out[(int)$f['id']] = $f['season'] ?? 'All year';
        }
        return $out;
    }

    // ── AJAX: Search Foods ────────────────────────────────────────────────────
    public function ajaxSearchFood() {
        check_ajax_referer( 'fcc_nonce', 'nonce' );

        $query   = strtolower( sanitize_text_field( $_POST['query'] ?? '' ) );
        $foods   = $this->getFoodDatabase();
        $tips    = $this->getHealthTips();
        $s       = $this->getSettings();
        $results = [];

        foreach ( $foods as $food ) {
            if ( empty( $query )
                || stripos( $food['name'],     $query ) !== false
                || stripos( $food['category'], $query ) !== false
            ) {
                $food['tip'] = $tips[ (int)$food['id'] ] ?? '';
                $results[]   = $food;
            }
        }

        if ( ! empty( $query ) ) {
            $name_priority = (bool) $s['search_name_priority'];
            usort( $results, function( $a, $b ) use ( $query, $name_priority ) {
                // 1. Sort priority (higher value = appears first)
                $pa = intval( $a['sort_priority'] ?? 0 );
                $pb = intval( $b['sort_priority'] ?? 0 );
                if ( $pa !== $pb ) return $pb - $pa;
                // 2. Name match score (only when toggle is ON)
                if ( $name_priority ) {
                    $sa = $this->searchMatchScore( $a, $query );
                    $sb = $this->searchMatchScore( $b, $query );
                    if ( $sa !== $sb ) return $sa - $sb;
                }
                // 3. Alphabetical tiebreaker
                return strcmp( strtolower( $a['name'] ), strtolower( $b['name'] ) );
            } );
        }

        wp_send_json_success( $results );
    }

    // ── AJAX: Calculate Calories ─────────────────────────────────────────────
    public function ajaxCalculateCalories() {
        check_ajax_referer( 'fcc_nonce', 'nonce' );

        $food_id = intval( $_POST['food_id']  ?? 0 );
        $serving = floatval( $_POST['serving'] ?? 100 );
        $unit    = sanitize_text_field( $_POST['unit']   ?? 'g' );
        $method  = sanitize_text_field( $_POST['method'] ?? 'baked' );

        $food = null;
        foreach ( $this->getFoodDatabase() as $f ) {
            if ( (int)$f['id'] === $food_id ) { $food = $f; break; }
        }
        if ( ! $food ) { wp_send_json_error( 'Food not found' ); return; }

        // Unit → Grams
        $grams = $serving;
        switch ( $unit ) {
            case 'oz':   $grams = $serving * 28.3495; break;
            case 'cup':  $grams = $serving * 240;     break;
            case 'tbsp': $grams = $serving * 15;      break;
            case 'tsp':  $grams = $serving * 5;       break;
            case 'lb':   $grams = $serving * 453.592; break;
        }
        $factor = $grams / 100;

        // Cooking method multipliers
        $m_map = [];
        foreach ( $this->getCookingMethods() as $cm ) { $m_map[ $cm['key'] ] = $cm; }
        $m = $m_map[ $method ] ?? $m_map['baked'];

        // Macros with cooking method applied
        $protein_g = round( $food['protein'] * $factor * $m['prot'], 1 );
        $carbs_g   = round( $food['carbs']   * $factor, 1 );
        $fat_g     = round( $food['fat']     * $factor * $m['fat'],  1 );
        $fiber_g   = round( $food['fiber']   * $factor, 1 );
        $sugar_g   = round( $food['sugar']   * $factor, 1 );
        $total_cal = round( $food['calories'] * $factor * $m['cal'], 1 );

        $cal_protein = round( $protein_g * 4, 1 );
        $cal_carbs   = round( $carbs_g   * 4, 1 );
        $cal_fat     = round( $fat_g     * 9, 1 );

        $total_macro = $cal_protein + $cal_carbs + $cal_fat;
        $prot_pct  = $total_macro > 0 ? round( ( $cal_protein / $total_macro ) * 100, 1 ) : 0;
        $carbs_pct = $total_macro > 0 ? round( ( $cal_carbs   / $total_macro ) * 100, 1 ) : 0;
        $fat_pct   = $total_macro > 0 ? round( ( $cal_fat     / $total_macro ) * 100, 1 ) : 0;

        $daily_cal      = intval( get_option( 'fcc_daily_calories', 2000 ) );
        $daily_protein  = intval( get_option( 'fcc_protein_target', 50  ) );
        $daily_carbs    = intval( get_option( 'fcc_carbs_target',   260 ) );
        $daily_fat      = intval( get_option( 'fcc_fat_target',     70  ) );
        $dv_calories = round( ( $total_cal / max(1,$daily_cal)     ) * 100, 1 );
        $dv_protein  = round( ( $protein_g / max(1,$daily_protein) ) * 100, 1 );
        $dv_carbs    = round( ( $carbs_g   / max(1,$daily_carbs)   ) * 100, 1 );
        $dv_fat      = round( ( $fat_g     / max(1,$daily_fat)     ) * 100, 1 );
        $dv_fiber    = round( ( $fiber_g   / 28  ) * 100, 1 );
        $dv_sugar    = round( ( $sugar_g   / 50  ) * 100, 1 );

        $eco       = $this->getEcoData()[ $food_id ]      ?? [ 'r'=>'ok', 's'=>'wild' ];
        $allergens = $this->getAllergens()[ $food_id ]     ?? '';
        $season    = $this->getSeasonData()[ $food_id ]   ?? 'All year';

        $this->trackAnalytics( $food_id, $food['name'], 'calc' );

        wp_send_json_success( [
            'food_name'    => $food['name'],    'category'   => $food['category'],
            'serving_g'   => round( $grams, 1 ),'method_label'=> $m['label'],
            'total_cal'   => $total_cal,
            'protein_g'   => $protein_g,        'carbs_g'    => $carbs_g,
            'fat_g'       => $fat_g,            'fiber_g'    => $fiber_g,
            'sugar_g'     => $sugar_g,
            'cal_protein' => $cal_protein,      'cal_carbs'  => $cal_carbs,
            'cal_fat'     => $cal_fat,
            'prot_pct'    => $prot_pct,         'carbs_pct'  => $carbs_pct,
            'fat_pct'     => $fat_pct,
            'dv_calories' => $dv_calories,      'dv_protein' => $dv_protein,
            'dv_carbs'    => $dv_carbs,         'dv_fat'     => $dv_fat,
            'dv_fiber'    => $dv_fiber,         'dv_sugar'   => $dv_sugar,
            'omega3_g'    => round( $food['omega3'] * $factor, 2 ),
            'mercury'     => $food['mercury'],
            'health_tip'  => ( $this->getHealthTips()[ $food['id'] ] ?? '' ),
            'allergens'   => $allergens,
            'eco_rating'  => $eco['r'],         'eco_source' => $eco['s'],
            'season'      => $season,
        ] );
    }

    // ── Render Shortcode HTML ─────────────────────────────────────────────────
    public function renderCalculator() {
        $cooking_methods  = $this->getCookingMethods();
        $s                = $this->getSettings();
        $daily_cal_label  = $s['daily_calories'];
        $default_serving  = $s['default_serving'];
        $default_method   = $s['default_method'];
        $widget_title     = $s['widget_title'] ?: 'Seafood Calorie Lookup';
        $show_tracker     = (bool) $s['show_tracker'];
        $show_compare     = (bool) $s['show_compare'];
        $food_count       = $this->getFoodCount();
        $pdf              = $this->getPdfSettings();
        $wa_num           = esc_attr( $pdf['whatsapp_number'] );
        $wa_base          = 'https://wa.me/' . $wa_num;
        ob_start(); ?>

<div id="fcc-wrapper">

    <!-- ──────────────── TAB NAVIGATION ──────────────── -->
    <div class="fcc-tabs">
        <button class="fcc-tab active" data-tab="food-calc">
            <i class="fas fa-fish"></i>
            <span class="fcc-tab-label-long">Seafood Calculator</span>
            <span class="fcc-tab-label-short">Calculator</span>
        </button>
        <?php if ( $show_tracker ): ?>
        <button class="fcc-tab" data-tab="meal-tracker">
            <i class="fas fa-clipboard-list"></i>
            <span class="fcc-tab-label-long">Meal Tracker</span>
            <span class="fcc-tab-label-short">Tracker</span>
        </button>
        <?php endif; ?>
        <?php if ( $show_compare ): ?>
        <button class="fcc-tab" data-tab="compare">
            <i class="fas fa-scale-balanced"></i>
            <span class="fcc-tab-label-long">Compare</span>
            <span class="fcc-tab-label-short">Compare</span>
            <span id="fcc-compare-badge" class="fcc-compare-badge" style="display:none"></span>
        </button>
        <?php endif; ?>
    </div>

    <!-- ══════════════ TAB 1 — SEAFOOD CALCULATOR ══════════════ -->
    <div class="fcc-tab-content active" id="tab-food-calc">

        <div class="fcc-card">
            <div class="fcc-card-header">
                <div class="fcc-header-icon"><i class="fas fa-magnifying-glass"></i></div>
                <div>
                    <h3><?php echo esc_html( $widget_title ); ?></h3>
                    <p>Search from <?php echo $food_count; ?>+ seafood items · All values per 100g</p>
                </div>
            </div>

            <?php if ( $s['show_filter_bar'] ): ?>
            <!-- Health goal filter bar -->
            <div class="fcc-filter-row" id="fcc-filter-row">
                <button class="fcc-filter-btn active" data-filter="all">All</button>
                <button class="fcc-filter-btn" data-filter="omega3"><i class="fas fa-water"></i> Top Omega-3</button>
                <button class="fcc-filter-btn" data-filter="low_cal"><i class="fas fa-fire"></i> Lowest Cal</button>
                <button class="fcc-filter-btn" data-filter="protein"><i class="fas fa-dumbbell"></i> High Protein</button>
                <button class="fcc-filter-btn" data-filter="low_mercury"><i class="fas fa-flask"></i> Low Mercury</button>
                <button class="fcc-filter-btn" data-filter="eco"><i class="fas fa-leaf"></i> Sustainable</button>
            </div>
            <?php endif; ?>

            <!-- Search -->
            <div class="fcc-search-wrap">
                <i class="fas fa-search fcc-search-icon"></i>
                <input type="text" id="fcc-food-search"
                    placeholder="Type a seafood name, or use a filter above…"
                    autocomplete="off">
                <div id="fcc-food-suggestions" class="fcc-dropdown"></div>
            </div>

            <!-- Serving controls — hidden until food selected -->
            <div class="fcc-serving-row" id="fcc-serving-section" style="display:none;">
                <div class="fcc-selected-pill">
                    <i class="fas fa-bowl-food"></i>
                    <span id="fcc-selected-name">—</span>
                    <button id="fcc-clear-food" title="Remove food"><i class="fas fa-xmark"></i></button>
                </div>



                <div class="fcc-controls">
                    <div class="fcc-field">
                        <label for="fcc-serving-size"><i class="fas fa-weight-scale"></i> Amount</label>
                        <input type="number" id="fcc-serving-size" value="<?php echo esc_attr($default_serving); ?>" min="1" max="99999">
                    </div>
                    <div class="fcc-field">
                        <label for="fcc-serving-unit"><i class="fas fa-ruler"></i> Unit</label>
                        <select id="fcc-serving-unit">
                            <option value="g">grams (g)</option>
                            <option value="oz">ounces (oz)</option>
                            <option value="cup">cup</option>
                            <option value="tbsp">tablespoon</option>
                            <option value="tsp">teaspoon</option>
                            <option value="lb">pound (lb)</option>
                        </select>
                    </div>
                    <div class="fcc-field">
                        <label for="fcc-method"><i class="fas fa-fire-burner"></i> Cooking</label>
                        <select id="fcc-method">
                            <?php foreach ( $cooking_methods as $cm ): ?>
                            <option value="<?php echo esc_attr($cm['key']); ?>"<?php selected( $default_method, $cm['key'] ); ?>><?php echo esc_html($cm['label']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button id="fcc-calculate-btn" class="fcc-btn fcc-btn-primary">
                        <i class="fas fa-calculator"></i> Calculate
                    </button>
                </div>
            </div>
        </div><!-- .fcc-card -->

        <!-- ── Results Panel ── -->
        <div id="fcc-results" style="display:none;">

            <!-- Print-only branding header card -->
            <div id="fcc-print-header">
                <div class="fcc-ph-brand">🦞 <?php echo esc_html( get_bloginfo('name') ); ?> — Seafood Calorie Calculator</div>
                <div class="fcc-ph-tagline">Full nutrition data for UK seafood · All values per 100g · All cooking methods</div>
                <div class="fcc-ph-url"><?php echo esc_url( get_bloginfo('url') ); ?></div>
            </div>

            <!-- Hero calories -->
            <div class="fcc-hero-card">
                <div class="fcc-hero-left">
                    <div class="fcc-fire-icon"><i class="fas fa-fire-flame-curved"></i></div>
                    <div>
                        <div class="fcc-hero-name" id="res-food-name"></div>
                        <div class="fcc-hero-cal" id="res-calories">0</div>
                        <div class="fcc-hero-label">Calories (kcal)</div>
                        <div class="fcc-hero-sub" id="res-serving-info"></div>
                    </div>
                </div>
                <div class="fcc-hero-badge" id="res-category-badge"></div>
            </div>

            <!-- Info bar: allergens · eco · season -->
            <div class="fcc-info-bar fcc-card fcc-section">
                <div class="fcc-info-item">
                    <span class="fcc-info-label"><i class="fas fa-triangle-exclamation"></i> Allergens</span>
                    <div id="res-allergens" class="fcc-allergen-wrap">—</div>
                </div>
                <div class="fcc-info-item">
                    <span class="fcc-info-label"><i class="fas fa-leaf"></i> Sustainability</span>
                    <div id="res-eco">—</div>
                </div>
                <div class="fcc-info-item">
                    <span class="fcc-info-label"><i class="fas fa-calendar-days"></i> UK Season</span>
                    <div class="fcc-season-badge" id="res-season">—</div>
                </div>
            </div>

            <!-- 4-macro cards -->
            <div class="fcc-macro-row">
                <div class="fcc-macro-box protein">
                    <i class="fas fa-dumbbell"></i>
                    <span class="fcc-mval" id="res-protein">—</span>
                    <span class="fcc-mlabel">Protein</span>
                    <span class="fcc-mcal" id="res-protein-cals"></span>
                </div>
                <div class="fcc-macro-box carbs">
                    <i class="fas fa-bread-slice"></i>
                    <span class="fcc-mval" id="res-carbs">—</span>
                    <span class="fcc-mlabel">Carbs</span>
                    <span class="fcc-mcal" id="res-carbs-cals"></span>
                </div>
                <div class="fcc-macro-box fat">
                    <i class="fas fa-droplet"></i>
                    <span class="fcc-mval" id="res-fat">—</span>
                    <span class="fcc-mlabel">Fat</span>
                    <span class="fcc-mcal" id="res-fat-cals"></span>
                </div>
                <div class="fcc-macro-box fiber">
                    <i class="fas fa-leaf"></i>
                    <span class="fcc-mval" id="res-fiber">—</span>
                    <span class="fcc-mlabel">Fiber</span>
                    <span class="fcc-mcal" id="res-sugar-val"></span>
                </div>
            </div>

            <!-- Health Insight -->
            <div class="fcc-card fcc-section" id="fcc-health-insight">
                <h4 class="fcc-section-title"><i class="fas fa-shield-heart"></i> Health Insight</h4>
                <div class="fcc-health-row">
                    <div class="fcc-snap-omega">
                        <div class="fcc-snap-omega-icon"><i class="fas fa-water"></i></div>
                        <div>
                            <span class="fcc-snap-val" id="res-omega3">—</span>
                            <span class="fcc-snap-sub">Omega-3 EPA+DHA (g)</span>
                        </div>
                    </div>
                    <span class="fcc-mercury" id="res-mercury">—</span>
                </div>
                <!-- Omega-3 daily target progress -->
                <div class="fcc-omega3-target">
                    <div class="fcc-omega3-target-row">
                        <span><i class="fas fa-bullseye"></i> Daily Omega-3 Target</span>
                        <span id="res-omega3-pct">—</span>
                    </div>
                    <div class="fcc-bar-track fcc-omega3-track">
                        <div class="fcc-bar-fill fcc-omega3-fill" id="bar-omega3" style="width:0%"></div>
                    </div>
                    <small class="fcc-omega3-hint" id="res-omega3-hint">of daily recommended intake</small>
                </div>
                <div class="fcc-health-tip-text" id="res-health-tip"></div>
            </div>

            <!-- Macro Distribution -->
            <div class="fcc-card fcc-section">
                <h4 class="fcc-section-title"><i class="fas fa-chart-bar"></i> Macro Distribution</h4>
                <div class="fcc-bar-group">
                    <div class="fcc-bar-row">
                        <span class="fcc-bar-label">Protein</span>
                        <div class="fcc-bar-track"><div class="fcc-bar-fill protein" id="bar-protein" style="width:0%"></div></div>
                        <span class="fcc-bar-pct" id="bar-protein-pct">0%</span>
                    </div>
                    <div class="fcc-bar-row">
                        <span class="fcc-bar-label">Carbs</span>
                        <div class="fcc-bar-track"><div class="fcc-bar-fill carbs" id="bar-carbs" style="width:0%"></div></div>
                        <span class="fcc-bar-pct" id="bar-carbs-pct">0%</span>
                    </div>
                    <div class="fcc-bar-row">
                        <span class="fcc-bar-label">Fat</span>
                        <div class="fcc-bar-track"><div class="fcc-bar-fill fat" id="bar-fat" style="width:0%"></div></div>
                        <span class="fcc-bar-pct" id="bar-fat-pct">0%</span>
                    </div>
                </div>
            </div>

            <!-- % Daily Values -->
            <div class="fcc-card fcc-section">
                <h4 class="fcc-section-title">
                    <i class="fas fa-percent"></i> % Daily Value
                    <small>(based on <?php echo $daily_cal_label; ?> kcal/day)</small>
                </h4>
                <div class="fcc-dv-grid">
                    <div class="fcc-dv-item"><span>Calories</span><span class="fcc-dv-val" id="dv-calories">—</span></div>
                    <div class="fcc-dv-item"><span>Protein</span><span class="fcc-dv-val" id="dv-protein">—</span></div>
                    <div class="fcc-dv-item"><span>Carbs</span><span class="fcc-dv-val" id="dv-carbs">—</span></div>
                    <div class="fcc-dv-item"><span>Fat</span><span class="fcc-dv-val" id="dv-fat">—</span></div>
                    <div class="fcc-dv-item"><span>Fiber</span><span class="fcc-dv-val" id="dv-fiber">—</span></div>
                    <div class="fcc-dv-item"><span>Sugar</span><span class="fcc-dv-val" id="dv-sugar">—</span></div>
                </div>
            </div>

            <!-- Action buttons -->
            <div class="fcc-action-row">
                <button id="fcc-add-to-meal" class="fcc-btn fcc-btn-success">
                    <i class="fas fa-plus-circle"></i> Add to Meal
                </button>
                <div class="fcc-action-secondary">
                    <button id="fcc-add-to-compare" class="fcc-btn fcc-btn-compare">
                        <i class="fas fa-scale-balanced"></i> Compare
                    </button>
                    <button id="fcc-share-btn" class="fcc-btn fcc-btn-outline">
                        <i class="fas fa-share-nodes"></i> Share
                    </button>
                    <button id="fcc-print-btn" class="fcc-btn fcc-btn-outline">
                        <i class="fas fa-print"></i> Print
                    </button>
                </div>
            </div>

            <!-- Print-only marketing section -->
            <div id="fcc-print-marketing">

                <!-- 1. Buy Fresh CTA + QR code -->
                <div class="fcc-pm-buy">
                    <div class="fcc-pm-buy-info">
                        <div class="fcc-pm-buy-title">🛒 Buy Fresh <span id="fcc-pm-fish-name">Seafood</span></div>
                        <div class="fcc-pm-buy-sub"><?php echo esc_html( $pdf['seafood_subtitle'] ); ?></div>
                        <a href="<?php echo esc_url( $wa_base ); ?>" class="fcc-pm-buy-btn"><?php echo esc_html( $pdf['buy_btn_label'] ); ?></a>
                    </div>
                    <div class="fcc-pm-qr-wrap">
                        <img src="https://api.qrserver.com/v1/create-qr-code/?size=80x80&data=<?php echo urlencode( $wa_base ); ?>&color=1e293b&bgcolor=ffffff" alt="Scan to WhatsApp" class="fcc-pm-qr-img">
                        <div class="fcc-pm-qr-label">Scan to order</div>
                    </div>
                </div>

                <!-- 2. In-season badge (shown/hidden by JS) -->
                <div class="fcc-pm-season" id="fcc-pm-season">
                    🌿 <strong><span id="fcc-pm-season-name">This fish</span> is in season right now</strong> — At its freshest. Order today.
                </div>

                <!-- 3 & 4. Wholesale + Caviar two-column -->
                <div class="fcc-pm-cols">
                    <div class="fcc-pm-col fcc-pm-wholesale">
                        <div class="fcc-pm-col-icon">🏭</div>
                        <div class="fcc-pm-col-title"><?php echo esc_html( $pdf['wholesale_title'] ); ?></div>
                        <div class="fcc-pm-col-body"><?php echo esc_html( $pdf['wholesale_body1'] ); ?><br><?php echo esc_html( $pdf['wholesale_body2'] ); ?><br><?php echo esc_html( $pdf['wholesale_body3'] ); ?></div>
                        <a href="<?php echo esc_url( $wa_base . '?text=' . rawurlencode( $pdf['wholesale_wa_msg'] ) ); ?>" class="fcc-pm-col-link"><?php echo esc_html( $pdf['wholesale_link_text'] ); ?></a>
                    </div>
                    <div class="fcc-pm-col fcc-pm-caviar">
                        <div class="fcc-pm-col-icon">🦞</div>
                        <div class="fcc-pm-col-title"><?php echo esc_html( $pdf['caviar_title'] ); ?></div>
                        <div class="fcc-pm-col-body"><?php echo esc_html( $pdf['caviar_types'] ); ?><br><?php echo esc_html( $pdf['caviar_delivery'] ); ?></div>
                        <a href="<?php echo esc_url( $pdf['caviar_link_url'] ); ?>" class="fcc-pm-col-link"><?php echo esc_html( $pdf['caviar_link_text'] ); ?></a>
                    </div>
                </div>

                <!-- 5. Trust strip -->
                <div class="fcc-pm-trust">
                    <span><?php echo esc_html( $pdf['trust_1'] ); ?></span>
                    <span><?php echo esc_html( $pdf['trust_2'] ); ?></span>
                    <span><?php echo esc_html( $pdf['trust_3'] ); ?></span>
                    <span><?php echo esc_html( $pdf['trust_4'] ); ?></span>
                </div>

            </div>

            <!-- Print-only footer -->
            <div id="fcc-print-footer">
                <div class="fcc-pf-inner">
                    <a href="<?php echo esc_url( $pdf['footer_link1_url'] ); ?>" class="fcc-pf-link"><?php echo esc_html( $pdf['footer_link1_text'] ); ?></a>
                    <span class="fcc-pf-sep">·</span>
                    <a href="<?php echo esc_url( $pdf['footer_link2_url'] ); ?>" class="fcc-pf-link"><?php echo esc_html( $pdf['footer_link2_text'] ); ?></a>
                    <span class="fcc-pf-sep">·</span>
                    <a href="<?php echo esc_url( $pdf['footer_link3_url'] ); ?>" class="fcc-pf-link"><?php echo esc_html( $pdf['footer_link3_text'] ); ?></a>
                    <span class="fcc-pf-sep">·</span>
                    <a href="<?php echo esc_url( $pdf['footer_link4_url'] ); ?>" class="fcc-pf-link"><?php echo esc_html( $pdf['footer_link4_text'] ); ?></a>
                </div>
            </div>

            <?php if ( $pdf['disclaimer_show'] ) : ?>
            <div id="fcc-print-disclaimer">
                <?php echo esc_html( $pdf['disclaimer_text'] ); ?>
            </div>
            <?php endif; ?>

        </div><!-- #fcc-results -->
    </div><!-- tab-food-calc -->


    <!-- ══════════════ TAB 2 — MEAL TRACKER ══════════════ -->
    <div class="fcc-tab-content" id="tab-meal-tracker">
        <div class="fcc-card">
            <div class="fcc-card-header">
                <div class="fcc-header-icon green"><i class="fas fa-bowl-rice"></i></div>
                <div style="flex:1">
                    <h3>Today's Seafood Intake</h3>
                    <p id="fcc-meal-date">Track your daily seafood intake</p>
                </div>
                <span id="fcc-save-status" style="display:none;"></span>
            </div>

            <div class="fcc-persist-notice" id="fcc-persist-notice" style="display:none;">
                <span id="fcc-persist-text"></span>
            </div>

            <div id="fcc-meal-list">
                <div class="fcc-empty-state">
                    <i class="fas fa-fish"></i>
                    <p>No seafood added yet.<br>Use the Seafood Calculator tab to add items.</p>
                </div>
            </div>

            <div id="fcc-meal-totals" style="display:none;">
                <div class="fcc-totals-strip">
                    <div class="fcc-total-box">
                        <i class="fas fa-fire"></i>
                        <span id="total-calories">0</span><small>kcal</small>
                    </div>
                    <div class="fcc-total-box protein">
                        <i class="fas fa-dumbbell"></i>
                        <span id="total-protein">0g</span><small>Protein</small>
                    </div>
                    <div class="fcc-total-box carbs">
                        <i class="fas fa-bread-slice"></i>
                        <span id="total-carbs">0g</span><small>Carbs</small>
                    </div>
                    <div class="fcc-total-box fat">
                        <i class="fas fa-droplet"></i>
                        <span id="total-fat">0g</span><small>Fat</small>
                    </div>
                    <div class="fcc-total-box omega3-box">
                        <i class="fas fa-water"></i>
                        <span id="total-omega3">0g</span><small>Omega-3</small>
                    </div>
                </div>
                <div class="fcc-meal-o3-wrap">
                    <div class="fcc-meal-o3-label">
                        <span>Daily Omega-3 target</span>
                        <span id="fcc-meal-omega3-pct" class="fcc-meal-o3-pct">0%</span>
                    </div>
                    <div class="fcc-meal-o3-track">
                        <div class="fcc-meal-o3-fill" id="fcc-meal-omega3-bar"></div>
                    </div>
                </div>
                <button id="fcc-clear-meal" class="fcc-btn fcc-btn-danger">
                    <i class="fas fa-trash-can"></i> Clear All
                </button>
            </div>
        </div>
    </div><!-- tab-meal-tracker -->


    <!-- ══════════════ TAB 3 — COMPARE ══════════════ -->
    <div class="fcc-tab-content" id="tab-compare">
        <div class="fcc-card">
            <div class="fcc-card-header">
                <div class="fcc-header-icon teal"><i class="fas fa-scale-balanced"></i></div>
                <div>
                    <h3>Compare Two Seafood Items</h3>
                    <p>Calculate any two items, then click <strong>Compare</strong> on each result</p>
                </div>
            </div>
            <div class="fcc-compare-slots">
                <div class="fcc-compare-slot empty" id="compare-slot-a">
                    <i class="fas fa-fish"></i><span>Item A — not selected</span>
                </div>
                <div class="fcc-compare-vs">VS</div>
                <div class="fcc-compare-slot empty" id="compare-slot-b">
                    <i class="fas fa-fish"></i><span>Item B — not selected</span>
                </div>
            </div>
            <button id="fcc-clear-compare" class="fcc-btn fcc-btn-danger" style="display:none;margin-top:12px;">
                <i class="fas fa-rotate-left"></i> Reset
            </button>
        </div>

        <div id="fcc-compare-table" style="display:none;">
            <div class="fcc-card fcc-section">
                <h4 class="fcc-section-title"><i class="fas fa-table-columns"></i> Side-by-Side Comparison</h4>
                <div class="fcc-compare-scroll">
                    <table class="fcc-compare-tbl">
                        <thead>
                            <tr>
                                <th class="cmp-label-col">Nutrient</th>
                                <th id="cmp-head-a">Item A</th>
                                <th id="cmp-head-b">Item B</th>
                            </tr>
                        </thead>
                        <tbody id="cmp-body"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div><!-- tab-compare -->

</div><!-- #fcc-wrapper -->

        <?php
        return ob_get_clean();
    }

    // ── AJAX: Track Analytics Event ───────────────────────────────────────────
    public function ajaxTrackEvent() {
        check_ajax_referer( 'fcc_nonce', 'nonce' );
        $food_id   = intval( $_POST['food_id']   ?? 0 );
        $food_name = sanitize_text_field( $_POST['food_name'] ?? '' );
        $event     = sanitize_text_field( $_POST['event']     ?? 'search' );
        $this->trackAnalytics( $food_id, $food_name, $event );
        wp_send_json_success();
    }

    // ── AJAX: Save Meal (localStorage fallback for logged-in users) ───────────
    public function ajaxSaveMeal() {
        check_ajax_referer( 'fcc_nonce', 'nonce' );
        if ( ! is_user_logged_in() ) { wp_send_json_error( 'Not logged in' ); return; }
        $items = sanitize_text_field( $_POST['items'] ?? '[]' );
        update_user_meta( get_current_user_id(), 'fcc_meal_items', $items );
        wp_send_json_success();
    }

    // ── AJAX: Log Missing Search ──────────────────────────────────────────────
    public function ajaxLogMissingSearch() {
        check_ajax_referer( 'fcc_nonce', 'nonce' );
        $query = sanitize_text_field( $_POST['query'] ?? '' );
        if ( strlen( $query ) < 3 ) { wp_send_json_success(); return; }
        global $wpdb;
        $table = $wpdb->prefix . 'fcc_missing_searches';
        if ( $wpdb->get_var( "SHOW TABLES LIKE '$table'" ) !== $table ) { wp_send_json_success(); return; }
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO $table (query, count, status, last_searched)
             VALUES (%s, 1, 'active', %s)
             ON DUPLICATE KEY UPDATE count = count + 1, last_searched = %s",
            $query, current_time('mysql'), current_time('mysql')
        ) );
        wp_send_json_success();
    }

    // ── AJAX: Submit Food Request (frontend) ──────────────────────────────────
    public function ajaxSubmitFoodRequest() {
        check_ajax_referer( 'fcc_nonce', 'nonce' );
        $food_name = sanitize_text_field( $_POST['food_name'] ?? '' );
        $note      = sanitize_textarea_field( $_POST['note']  ?? '' );
        if ( strlen( $food_name ) < 2 ) { wp_send_json_error( 'Name too short' ); return; }
        global $wpdb;
        $table = $wpdb->prefix . 'fcc_food_requests';
        if ( $wpdb->get_var( "SHOW TABLES LIKE '$table'" ) !== $table ) { wp_send_json_error( 'Not ready' ); return; }
        $now = current_time( 'mysql' );
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO $table (food_name, note, count, status, created_at, last_requested)
             VALUES (%s, %s, 1, 'pending', %s, %s)
             ON DUPLICATE KEY UPDATE count = count + 1, last_requested = %s, note = IF(note = '' OR note IS NULL, %s, note)",
            $food_name, $note, $now, $now, $now, $note
        ) );
        wp_send_json_success( [ 'message' => 'Request sent! We\'ll review it.' ] );
    }

    // ── AJAX: Update Request / Missing Search Status (admin) ──────────────────
    public function ajaxUpdateRequestStatus() {
        check_ajax_referer( 'fcc_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Forbidden' ); return; }
        $type   = sanitize_text_field( $_POST['type']   ?? '' );
        $id     = intval( $_POST['id']     ?? 0 );
        $status = sanitize_text_field( $_POST['status'] ?? '' );
        if ( ! $id || ! in_array( $status, [ 'active', 'pending', 'added', 'dismissed' ], true ) ) {
            wp_send_json_error( 'Invalid params' ); return;
        }
        global $wpdb;
        $table = ( $type === 'missing' )
            ? $wpdb->prefix . 'fcc_missing_searches'
            : $wpdb->prefix . 'fcc_food_requests';
        $wpdb->update( $table, [ 'status' => $status ], [ 'id' => $id ] );
        wp_send_json_success();
    }

    // ── Internal: Track Analytics ─────────────────────────────────────────────
    private function trackAnalytics( $food_id, $food_name, $action ) {
        global $wpdb;
        $fid = intval( $food_id );
        if ( ! $fid ) return;
        $col = ( $action === 'calc' ) ? 'calcs' : 'searches';

        // Resolve category from cached food DB (zero extra query)
        $category = '';
        foreach ( $this->getFoodDatabase() as $f ) {
            if ( (int)$f['id'] === $fid ) { $category = $f['category'] ?? ''; break; }
        }

        // Lifetime aggregate table
        $agg = $wpdb->prefix . 'fcc_analytics';
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT food_id FROM $agg WHERE food_id = %d", $fid ) );
        if ( $row ) {
            $wpdb->query( $wpdb->prepare( "UPDATE $agg SET $col = $col + 1 WHERE food_id = %d", $fid ) );
        } else {
            $wpdb->insert( $agg, [
                'food_id'   => $fid,
                'food_name' => sanitize_text_field($food_name),
                'searches'  => ( $action === 'search' ) ? 1 : 0,
                'calcs'     => ( $action === 'calc'   ) ? 1 : 0,
            ], [ '%d', '%s', '%d', '%d' ] );
        }

        // Daily time-series table (INSERT … ON DUPLICATE KEY UPDATE)
        $daily = $wpdb->prefix . 'fcc_analytics_daily';
        if ( get_option( 'fcc_tables_ready' ) ) {
            $wpdb->query( $wpdb->prepare(
                "INSERT INTO $daily (food_id, food_name, category, log_date, $col)
                 VALUES (%d, %s, %s, %s, 1)
                 ON DUPLICATE KEY UPDATE $col = $col + 1",
                $fid, sanitize_text_field($food_name), sanitize_text_field($category), gmdate('Y-m-d')
            ) );
        }

        // Bust the 1-hour trending cache so new data appears promptly
        delete_transient('fcc_top_trending');
    }

    // ── Admin: Menu ───────────────────────────────────────────────────────────
    public function adminMenu() {
        add_menu_page( 'Seafood Calculator', 'Seafood Calc', 'manage_options',
            'fcc-settings', [ $this, 'adminSettingsPage' ], 'dashicons-carrot', 30 );
        add_submenu_page( 'fcc-settings', 'Settings',      'Settings',      'manage_options', 'fcc-settings',      [ $this, 'adminSettingsPage'    ] );
        add_submenu_page( 'fcc-settings', 'PDF Settings',  'PDF Settings',  'manage_options', 'fcc-pdf-settings',  [ $this, 'adminPdfSettingsPage' ] );
        add_submenu_page( 'fcc-settings', 'Manage Foods',  'Manage Foods',  'manage_options', 'fcc-foods',         [ $this, 'adminFoodsPage'       ] );
        add_submenu_page( 'fcc-settings', 'Analytics',     'Analytics',     'manage_options', 'fcc-analytics',     [ $this, 'adminAnalyticsPage'   ] );
        add_submenu_page( 'fcc-settings', 'Food Requests', 'Food Requests', 'manage_options', 'fcc-requests',      [ $this, 'adminRequestsPage'    ] );
    }

    // ── Admin: "Settings" link on Plugins list row ─────────────────────────────
    public function addSettingsLink( $links ) {
        $settings_link = '<a href="' . admin_url( 'admin.php?page=fcc-settings' ) . '">Settings</a>';
        array_unshift( $links, $settings_link );
        return $links;
    }

    // ── Admin: Save Settings ──────────────────────────────────────────────────
    public function adminSaveSettings() {
        if ( ! current_user_can('manage_options') ) wp_die('Forbidden');
        check_admin_referer('fcc_save_settings');
        update_option( 'fcc_omega3_target',        floatval( $_POST['omega3_target']        ?? 0.5   ) );
        update_option( 'fcc_daily_calories',       intval(   $_POST['daily_calories']       ?? 2000  ) );
        update_option( 'fcc_protein_target',       intval(   $_POST['protein_target']       ?? 50    ) );
        update_option( 'fcc_fat_target',           intval(   $_POST['fat_target']           ?? 70    ) );
        update_option( 'fcc_carbs_target',         intval(   $_POST['carbs_target']         ?? 260   ) );
        update_option( 'fcc_default_serving',      intval(   $_POST['default_serving']      ?? 100   ) );
        update_option( 'fcc_default_method',       sanitize_text_field( $_POST['default_method']    ?? 'baked' ) );
        update_option( 'fcc_widget_title',         sanitize_text_field( $_POST['widget_title']      ?? ''      ) );
        update_option( 'fcc_show_tracker',         intval(   $_POST['show_tracker']         ?? 1     ) );
        update_option( 'fcc_show_compare',         intval(   $_POST['show_compare']         ?? 1     ) );
        update_option( 'fcc_show_filter_bar',      intval(   $_POST['show_filter_bar']      ?? 1     ) );
        update_option( 'fcc_show_health_tips',     intval(   $_POST['show_health_tips']     ?? 1     ) );
        update_option( 'fcc_show_allergens',       intval(   $_POST['show_allergens']       ?? 1     ) );
        update_option( 'fcc_show_eco',             intval(   $_POST['show_eco']             ?? 1     ) );
        update_option( 'fcc_show_mercury',         intval(   $_POST['show_mercury']         ?? 1     ) );
        update_option( 'fcc_show_season',          intval(   $_POST['show_season']          ?? 1     ) );
        update_option( 'fcc_show_request_btn',     intval(   $_POST['show_request_btn']     ?? 1     ) );
        update_option( 'fcc_auto_log_searches',    intval(   $_POST['auto_log_searches']    ?? 1     ) );
        update_option( 'fcc_search_results_limit',  intval( $_POST['search_results_limit']  ?? 8 ) );
        update_option( 'fcc_search_min_chars',      intval( $_POST['search_min_chars']      ?? 1 ) );
        update_option( 'fcc_search_name_priority',  intval( $_POST['search_name_priority']  ?? 1 ) );
        wp_redirect( admin_url('admin.php?page=fcc-settings&saved=1') );
        exit;
    }

    // ── Admin: Save PDF Settings ──────────────────────────────────────────────
    public function adminSavePdfSettings() {
        if ( ! current_user_can('manage_options') ) wp_die('Forbidden');
        check_admin_referer('fcc_save_pdf_settings');
        $text_fields = [
            'whatsapp_number', 'buy_btn_label', 'caviar_subtitle', 'seafood_subtitle',
            'wholesale_title', 'wholesale_body1', 'wholesale_body2', 'wholesale_body3',
            'wholesale_link_text', 'wholesale_wa_msg',
            'caviar_title', 'caviar_types', 'caviar_delivery', 'caviar_link_text', 'caviar_link_url',
            'trust_1', 'trust_2', 'trust_3', 'trust_4',
            'footer_link1_text', 'footer_link1_url',
            'footer_link2_text', 'footer_link2_url',
            'footer_link3_text', 'footer_link3_url',
            'footer_link4_text', 'footer_link4_url',
            'disclaimer_show',
        ];
        $data = [];
        foreach ( $text_fields as $f ) {
            $data[$f] = sanitize_text_field( $_POST[$f] ?? '' );
        }
        $data['disclaimer_text'] = sanitize_textarea_field( $_POST['disclaimer_text'] ?? '' );
        update_option( 'fcc_pdf_settings', $data );
        wp_redirect( admin_url('admin.php?page=fcc-pdf-settings&saved=1') );
        exit;
    }

    // ── Admin: PDF Settings Page ──────────────────────────────────────────────
    public function adminPdfSettingsPage() {
        $p     = $this->getPdfSettings();
        $saved = isset( $_GET['saved'] );
        ?>
<style>
/* ── PDF Settings Admin — matches Food Requests style ── */
.fcc-ps-wrap{max-width:960px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}
/* Header */
.fcc-ps-header{background:linear-gradient(135deg,#1e293b 0%,#0f172a 100%);border-radius:16px;padding:24px 28px;margin-bottom:22px;display:flex;align-items:center;justify-content:space-between;gap:20px;flex-wrap:wrap;box-shadow:0 6px 24px rgba(15,23,42,.28)}
.fcc-ps-header-left{display:flex;align-items:center;gap:16px}
.fcc-ps-icon{width:54px;height:54px;background:linear-gradient(135deg,#ff914d,#e07a3d);border-radius:14px;display:flex;align-items:center;justify-content:center;box-shadow:0 4px 16px rgba(255,145,77,.4);flex-shrink:0;font-size:26px;line-height:1}
.fcc-ps-title{font-size:21px;font-weight:800;color:#fff;margin:0;line-height:1.25}
.fcc-ps-subtitle{margin:5px 0 0;color:#94a3b8;font-size:12.5px;line-height:1.55}
.fcc-ps-info-bar{display:flex;align-items:center;gap:10px;background:#fff;border:1.5px solid #f1f5f9;border-radius:10px;padding:11px 16px;margin-bottom:20px;font-size:12.5px;color:#64748b;box-shadow:0 1px 4px rgba(15,23,42,.04)}
.fcc-ps-info-bar .fcc-ps-info-icon{width:28px;height:28px;background:linear-gradient(135deg,#fff7ed,#ffe4cc);border-radius:7px;display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0}
.fcc-ps-info-bar strong{color:#1e293b}
/* Save notice */
.fcc-ps-notice{background:#f0fdf4;border:1.5px solid #22c55e;border-radius:10px;padding:12px 18px;font-size:13px;font-weight:600;color:#15803d;margin-bottom:20px;display:flex;align-items:center;gap:8px}
/* Section card */
.fcc-ps-card{background:#fff;border-radius:16px;border:1.5px solid #f1f5f9;box-shadow:0 2px 10px rgba(15,23,42,.06);overflow:hidden;margin-bottom:18px}
.fcc-ps-card-head{padding:16px 22px 14px;border-bottom:1.5px solid #f8fafc;display:flex;align-items:center;gap:10px}
.fcc-ps-card-icon{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:17px;flex-shrink:0}
.fcc-ps-card-icon.orange{background:linear-gradient(135deg,#fff7ed,#ffe4cc)}
.fcc-ps-card-icon.blue{background:linear-gradient(135deg,#eff6ff,#dbeafe)}
.fcc-ps-card-icon.green{background:linear-gradient(135deg,#f0fdf4,#dcfce7)}
.fcc-ps-card-icon.slate{background:linear-gradient(135deg,#f8fafc,#f1f5f9)}
.fcc-ps-card-icon.purple{background:linear-gradient(135deg,#faf5ff,#ede9fe)}
.fcc-ps-card-title{font-size:13.5px;font-weight:700;color:#1e293b;margin:0}
.fcc-ps-card-desc{font-size:11.5px;color:#94a3b8;margin:2px 0 0}
.fcc-ps-card-body{padding:20px 22px}
/* Grid */
.fcc-ps-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px 22px}
.fcc-ps-grid.g1{grid-template-columns:1fr}
.fcc-ps-grid.g3{grid-template-columns:1fr 1fr 1fr}
.fcc-ps-full{grid-column:1/-1}
/* Field */
.fcc-ps-field{}
.fcc-ps-field label{display:block;font-size:10.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px}
.fcc-ps-field input[type=text],.fcc-ps-field textarea{width:100%;border:1.5px solid #e2e8f0;border-radius:8px;padding:9px 12px;font-size:13px;color:#0f172a;font-family:inherit;box-sizing:border-box;transition:border-color .15s,box-shadow .15s;background:#fafbfc}
.fcc-ps-field input[type=text]:focus,.fcc-ps-field textarea:focus{border-color:#ff914d;outline:none;box-shadow:0 0 0 3px rgba(255,145,77,.12);background:#fff}
.fcc-ps-field textarea{resize:vertical;min-height:72px}
.fcc-ps-field .hint{font-size:11px;color:#94a3b8;margin-top:4px;line-height:1.45}
/* Separator between pairs */
.fcc-ps-link-pair{background:#f8fafc;border:1.5px solid #f1f5f9;border-radius:10px;padding:14px 16px;display:grid;grid-template-columns:1fr 2fr;gap:12px;align-items:start}
.fcc-ps-link-num{font-size:10px;font-weight:800;color:#ff914d;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px}
/* Toggle */
.fcc-ps-tog-row{display:flex;align-items:center;gap:12px;padding:12px 0}
.fcc-ps-tog-row label.tog-lbl{position:relative;display:inline-block;width:42px;height:24px;flex-shrink:0}
.fcc-ps-tog-row label.tog-lbl input{opacity:0;width:0;height:0}
.fcc-ps-tog-row label.tog-lbl .sl{position:absolute;cursor:pointer;inset:0;background:#cbd5e1;border-radius:999px;transition:.2s}
.fcc-ps-tog-row label.tog-lbl .sl:before{content:"";position:absolute;width:18px;height:18px;left:3px;bottom:3px;background:#fff;border-radius:50%;transition:.2s;box-shadow:0 1px 3px rgba(0,0,0,.15)}
.fcc-ps-tog-row label.tog-lbl input:checked+.sl{background:#ff914d}
.fcc-ps-tog-row label.tog-lbl input:checked+.sl:before{transform:translateX(18px)}
.fcc-ps-tog-row .tog-label{font-size:13px;font-weight:600;color:#1e293b}
.fcc-ps-tog-row .tog-hint{font-size:11.5px;color:#94a3b8;margin-left:4px}
/* Trust item */
.fcc-ps-trust-item{background:#f8fafc;border:1.5px solid #f1f5f9;border-radius:10px;padding:12px 14px;display:flex;align-items:center;gap:10px}
.fcc-ps-trust-num{width:22px;height:22px;background:linear-gradient(135deg,#ff914d,#e07a3d);border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:800;color:#fff;flex-shrink:0}
.fcc-ps-trust-item input[type=text]{border:1.5px solid #e2e8f0;border-radius:7px;padding:7px 10px;font-size:13px;color:#0f172a;font-family:inherit;box-sizing:border-box;width:100%;background:#fff;transition:border-color .15s,box-shadow .15s}
.fcc-ps-trust-item input[type=text]:focus{border-color:#ff914d;outline:none;box-shadow:0 0 0 3px rgba(255,145,77,.12)}
/* Divider */
.fcc-ps-divider{height:1px;background:#f1f5f9;margin:16px 0}
/* Save bar */
.fcc-ps-save-bar{display:flex;align-items:center;gap:16px;padding:20px 0 4px}
.fcc-ps-save-btn{background:linear-gradient(135deg,#ff914d,#e07a3d);color:#fff;border:none;border-radius:10px;padding:11px 30px;font-size:14px;font-weight:700;cursor:pointer;font-family:inherit;box-shadow:0 4px 14px rgba(255,145,77,.35);transition:all .15s;display:inline-flex;align-items:center;gap:8px}
.fcc-ps-save-btn:hover{transform:translateY(-1px);box-shadow:0 6px 20px rgba(255,145,77,.45)}
.fcc-ps-save-btn:active{transform:translateY(0)}
.fcc-ps-save-note{font-size:12px;color:#94a3b8}
</style>

<div class="wrap fcc-ps-wrap">

    <!-- Header -->
    <div class="fcc-ps-header">
        <div class="fcc-ps-header-left">
            <div class="fcc-ps-icon">🖨️</div>
            <div>
                <h1 class="fcc-ps-title">PDF Print Settings</h1>
                <p class="fcc-ps-subtitle">Control every element of the printed PDF — marketing cards, trust strip, footer links &amp; disclaimer.</p>
            </div>
        </div>
    </div>

    <div class="fcc-ps-info-bar">
        <div class="fcc-ps-info-icon">💡</div>
        <span><strong>Tip:</strong> Changes apply the next time a user clicks <strong>Print</strong> on the calculator — no page reload needed after saving.</span>
    </div>

    <?php if ( $saved ) : ?>
    <div class="fcc-ps-notice">✅ PDF settings saved successfully.</div>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
        <?php wp_nonce_field('fcc_save_pdf_settings'); ?>
        <input type="hidden" name="action" value="fcc_save_pdf_settings">

        <!-- ── General ── -->
        <div class="fcc-ps-card">
            <div class="fcc-ps-card-head">
                <div class="fcc-ps-card-icon green">📱</div>
                <div>
                    <p class="fcc-ps-card-title">General</p>
                    <p class="fcc-ps-card-desc">WhatsApp number used in all PDF links and the QR code</p>
                </div>
            </div>
            <div class="fcc-ps-card-body">
                <div class="fcc-ps-grid g1">
                    <div class="fcc-ps-field">
                        <label>WhatsApp Number <span style="font-weight:400;text-transform:none;letter-spacing:0">(digits only, include country code)</span></label>
                        <input type="text" name="whatsapp_number" value="<?php echo esc_attr($p['whatsapp_number']); ?>" placeholder="e.g. 447367067305">
                        <div class="hint">Updates all WhatsApp links and the QR code across the entire PDF automatically.</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Buy CTA Card ── -->
        <div class="fcc-ps-card">
            <div class="fcc-ps-card-head">
                <div class="fcc-ps-card-icon orange">🛒</div>
                <div>
                    <p class="fcc-ps-card-title">Buy CTA Card</p>
                    <p class="fcc-ps-card-desc">The orange call-to-action box at the top of the marketing section</p>
                </div>
            </div>
            <div class="fcc-ps-card-body">
                <div class="fcc-ps-grid g1">
                    <div class="fcc-ps-field">
                        <label>Button Label</label>
                        <input type="text" name="buy_btn_label" value="<?php echo esc_attr($p['buy_btn_label']); ?>">
                    </div>
                    <div class="fcc-ps-divider"></div>
                    <div class="fcc-ps-field">
                        <label>Subtitle — Caviar &amp; Roe items</label>
                        <input type="text" name="caviar_subtitle" value="<?php echo esc_attr($p['caviar_subtitle']); ?>">
                        <div class="hint">Shown when the searched item matches: caviar, beluga, oscietra, sevruga, sturgeon, salmon roe, keta.</div>
                    </div>
                    <div class="fcc-ps-field">
                        <label>Subtitle — All other seafood</label>
                        <input type="text" name="seafood_subtitle" value="<?php echo esc_attr($p['seafood_subtitle']); ?>">
                        <div class="hint">Shown for all non-caviar items — cod, salmon, prawns, etc.</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Wholesale Card ── -->
        <div class="fcc-ps-card">
            <div class="fcc-ps-card-head">
                <div class="fcc-ps-card-icon slate">🏭</div>
                <div>
                    <p class="fcc-ps-card-title">Wholesale Card</p>
                    <p class="fcc-ps-card-desc">Left card in the two-column marketing block</p>
                </div>
            </div>
            <div class="fcc-ps-card-body">
                <div class="fcc-ps-grid">
                    <div class="fcc-ps-field">
                        <label>Card Title</label>
                        <input type="text" name="wholesale_title" value="<?php echo esc_attr($p['wholesale_title']); ?>">
                    </div>
                    <div class="fcc-ps-field">
                        <label>Link Text</label>
                        <input type="text" name="wholesale_link_text" value="<?php echo esc_attr($p['wholesale_link_text']); ?>">
                    </div>
                    <div class="fcc-ps-field">
                        <label>Body Line 1</label>
                        <input type="text" name="wholesale_body1" value="<?php echo esc_attr($p['wholesale_body1']); ?>">
                    </div>
                    <div class="fcc-ps-field">
                        <label>Body Line 2</label>
                        <input type="text" name="wholesale_body2" value="<?php echo esc_attr($p['wholesale_body2']); ?>">
                    </div>
                    <div class="fcc-ps-field fcc-ps-full">
                        <label>Body Line 3</label>
                        <input type="text" name="wholesale_body3" value="<?php echo esc_attr($p['wholesale_body3']); ?>">
                    </div>
                    <div class="fcc-ps-field fcc-ps-full">
                        <label>WhatsApp Pre-fill Message</label>
                        <input type="text" name="wholesale_wa_msg" value="<?php echo esc_attr($p['wholesale_wa_msg']); ?>">
                        <div class="hint">Text that pre-fills the WhatsApp message when a customer taps the link.</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Premium Caviar Card ── -->
        <div class="fcc-ps-card">
            <div class="fcc-ps-card-head">
                <div class="fcc-ps-card-icon orange">🦞</div>
                <div>
                    <p class="fcc-ps-card-title">Premium Caviar Card</p>
                    <p class="fcc-ps-card-desc">Right card in the two-column marketing block</p>
                </div>
            </div>
            <div class="fcc-ps-card-body">
                <div class="fcc-ps-grid">
                    <div class="fcc-ps-field">
                        <label>Card Title</label>
                        <input type="text" name="caviar_title" value="<?php echo esc_attr($p['caviar_title']); ?>">
                    </div>
                    <div class="fcc-ps-field">
                        <label>Link Text</label>
                        <input type="text" name="caviar_link_text" value="<?php echo esc_attr($p['caviar_link_text']); ?>">
                    </div>
                    <div class="fcc-ps-field fcc-ps-full">
                        <label>Caviar Types (line 1)</label>
                        <input type="text" name="caviar_types" value="<?php echo esc_attr($p['caviar_types']); ?>">
                    </div>
                    <div class="fcc-ps-field">
                        <label>Delivery Note (line 2)</label>
                        <input type="text" name="caviar_delivery" value="<?php echo esc_attr($p['caviar_delivery']); ?>">
                    </div>
                    <div class="fcc-ps-field">
                        <label>Shop Link URL</label>
                        <input type="text" name="caviar_link_url" value="<?php echo esc_attr($p['caviar_link_url']); ?>">
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Trust Strip ── -->
        <div class="fcc-ps-card">
            <div class="fcc-ps-card-head">
                <div class="fcc-ps-card-icon green">✓</div>
                <div>
                    <p class="fcc-ps-card-title">Trust Strip</p>
                    <p class="fcc-ps-card-desc">Dark navy bar with 4 trust signals displayed across the PDF</p>
                </div>
            </div>
            <div class="fcc-ps-card-body">
                <div class="fcc-ps-grid">
                    <?php foreach ( [1,2,3,4] as $i ) : ?>
                    <div class="fcc-ps-trust-item">
                        <div class="fcc-ps-trust-num"><?php echo $i; ?></div>
                        <input type="text" name="trust_<?php echo $i; ?>" value="<?php echo esc_attr($p['trust_'.$i]); ?>">
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- ── Footer Links ── -->
        <div class="fcc-ps-card">
            <div class="fcc-ps-card-head">
                <div class="fcc-ps-card-icon blue">🔗</div>
                <div>
                    <p class="fcc-ps-card-title">Footer Links</p>
                    <p class="fcc-ps-card-desc">Four links displayed in the orange footer bar at the bottom of the PDF</p>
                </div>
            </div>
            <div class="fcc-ps-card-body">
                <div class="fcc-ps-grid">
                    <?php foreach ( [1,2,3,4] as $i ) : ?>
                    <div class="fcc-ps-link-pair">
                        <div class="fcc-ps-field">
                            <p class="fcc-ps-link-num">Link <?php echo $i; ?></p>
                            <label>Label</label>
                            <input type="text" name="footer_link<?php echo $i; ?>_text" value="<?php echo esc_attr($p['footer_link'.$i.'_text']); ?>">
                        </div>
                        <div class="fcc-ps-field">
                            <p class="fcc-ps-link-num">&nbsp;</p>
                            <label>URL</label>
                            <input type="text" name="footer_link<?php echo $i; ?>_url" value="<?php echo esc_attr($p['footer_link'.$i.'_url']); ?>">
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- ── Disclaimer ── -->
        <div class="fcc-ps-card">
            <div class="fcc-ps-card-head">
                <div class="fcc-ps-card-icon purple">🛡️</div>
                <div>
                    <p class="fcc-ps-card-title">Disclaimer</p>
                    <p class="fcc-ps-card-desc">Small grey text printed below the footer — show or hide with the toggle</p>
                </div>
            </div>
            <div class="fcc-ps-card-body">
                <div class="fcc-ps-tog-row">
                    <label class="tog-lbl">
                        <input type="hidden" name="disclaimer_show" value="0">
                        <input type="checkbox" name="disclaimer_show" value="1" <?php checked($p['disclaimer_show'], '1'); ?>>
                        <span class="sl"></span>
                    </label>
                    <span class="tog-label">Show disclaimer in PDF</span>
                    <span class="tog-hint">— toggle off to hide it entirely</span>
                </div>
                <div class="fcc-ps-divider"></div>
                <div class="fcc-ps-field">
                    <label>Disclaimer Text</label>
                    <textarea name="disclaimer_text" rows="3"><?php echo esc_textarea($p['disclaimer_text']); ?></textarea>
                </div>
            </div>
        </div>

        <!-- Save -->
        <div class="fcc-ps-save-bar">
            <button type="submit" class="fcc-ps-save-btn">
                <span class="dashicons dashicons-saved" style="font-size:16px;width:16px;height:16px;margin-top:1px"></span>
                Save PDF Settings
            </button>
            <span class="fcc-ps-save-note">Changes take effect immediately on the next print.</span>
        </div>
    </form>
</div>
        <?php
    }

    // ── Admin: Settings Page ──────────────────────────────────────────────────
    public function adminSettingsPage() {
        $s       = $this->getSettings();
        $methods = $this->getCookingMethods();

        // Find pages/posts using the shortcode
        global $wpdb;
        $sc_pages = $wpdb->get_results(
            "SELECT ID, post_title, post_type FROM {$wpdb->posts}
             WHERE post_status='publish' AND post_content LIKE '%[seafood_calorie_calculator]%'
             ORDER BY post_type, post_title LIMIT 20",
            ARRAY_A
        ) ?: [];

        // Helper: render a toggle card
        $tog_card = function( $name, $label, $desc, $icon, $on ) {
            $card_cls = 'fcc-tog-card' . ( $on ? ' fcc-tog-card-on' : '' );
            echo '<div class="' . $card_cls . '">';
            echo '<div class="fcc-tog-card-icon">' . $icon . '</div>';
            echo '<div class="fcc-tog-card-body"><strong>' . esc_html($label) . '</strong><span>' . esc_html($desc) . '</span></div>';
            echo '<div style="display:flex;flex-direction:column;align-items:center;gap:4px;flex-shrink:0">';
            echo '<label class="fcc-tog"><input type="hidden" name="' . esc_attr($name) . '" value="0">';
            echo '<input type="checkbox" name="' . esc_attr($name) . '" value="1"' . ($on ? ' checked' : '') . ' onchange="var c=this.closest(\'.fcc-tog-card\');c.classList.toggle(\'fcc-tog-card-on\',this.checked);var l=c.querySelector(\'.fcc-tog-state\');l.textContent=this.checked?\'On\':\'Off\';l.className=\'fcc-tog-state \'+(this.checked?\'on\':\'off\')">';
            echo '<span class="fcc-tog-sl"></span></label>';
            echo '<span class="fcc-tog-state ' . ($on ? 'on' : 'off') . '">' . ($on ? 'On' : 'Off') . '</span>';
            echo '</div></div>';
        };
        ?>
<style>
/* ── Base ── */
.fcc-set-adm{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#1e293b}
/* ── Hero ── */
.fcc-hero{background:linear-gradient(135deg,#1e293b 0%,#334155 100%);border-radius:12px;padding:20px 24px;margin-bottom:22px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:14px;box-shadow:0 4px 20px rgba(0,0,0,.15)}
.fcc-hero-left{display:flex;align-items:center;gap:14px}
.fcc-hero-icon{width:48px;height:48px;background:linear-gradient(135deg,#ff914d,#e07a3d);border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:24px;flex-shrink:0;box-shadow:0 4px 12px rgba(255,145,77,.35)}
.fcc-hero-title{color:#fff;font-size:19px;font-weight:700;margin:0;line-height:1.2}
.fcc-hero-sub{color:rgba(255,255,255,.5);font-size:12px;margin-top:3px}
/* ── Cards ── */
.fcc-set-card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;margin-bottom:20px;box-shadow:0 4px 16px rgba(0,0,0,.06);overflow:hidden;transition:box-shadow .2s}
.fcc-set-card:hover{box-shadow:0 8px 28px rgba(0,0,0,.09)}
/* Color variants — top accent border + tinted header */
.fcc-card-orange{border-top:4px solid #ff914d}
.fcc-card-orange .fcc-set-chdr{background:linear-gradient(135deg,rgba(255,145,77,.07) 0%,rgba(255,255,255,0) 80%)}
.fcc-card-blue{border-top:4px solid #3b82f6}
.fcc-card-blue .fcc-set-chdr{background:linear-gradient(135deg,rgba(59,130,246,.07) 0%,rgba(255,255,255,0) 80%)}
.fcc-card-green{border-top:4px solid #10b981}
.fcc-card-green .fcc-set-chdr{background:linear-gradient(135deg,rgba(16,185,129,.07) 0%,rgba(255,255,255,0) 80%)}
.fcc-card-purple{border-top:4px solid #8b5cf6}
.fcc-card-purple .fcc-set-chdr{background:linear-gradient(135deg,rgba(139,92,246,.07) 0%,rgba(255,255,255,0) 80%)}
.fcc-card-cyan{border-top:4px solid #06b6d4}
.fcc-card-cyan .fcc-set-chdr{background:linear-gradient(135deg,rgba(6,182,212,.07) 0%,rgba(255,255,255,0) 80%)}
/* Card header */
.fcc-set-chdr{display:flex;align-items:center;gap:14px;padding:20px 24px;border-bottom:1px solid #f1f5f9}
.fcc-set-cicon{width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0}
.fcc-set-ctitle{font-size:15px;font-weight:700;color:#1e293b;margin:0;line-height:1.3}
.fcc-set-csub{font-size:12px;color:#64748b;margin:3px 0 0}
/* ── Toggle cards ON state ── */
.fcc-tog-card-on{background:linear-gradient(135deg,rgba(16,185,129,.06),rgba(16,185,129,.1))!important;border-color:#6ee7b7!important}
/* ── Rows ── */
.fcc-set-body{padding:4px 24px}
.fcc-set-row{display:flex;align-items:center;gap:20px;padding:14px 0;border-bottom:1px solid #f8fafc}
.fcc-set-row:last-child{border-bottom:none}
.fcc-set-lbl{flex:1;min-width:0}
.fcc-set-lbl strong{display:block;font-size:13.5px;color:#1e293b;font-weight:600;margin-bottom:3px}
.fcc-set-lbl span{font-size:12px;color:#64748b;line-height:1.5}
.fcc-set-ctrl{flex-shrink:0;display:flex;align-items:center;gap:8px;width:310px;justify-content:flex-end}
.fcc-set-unit{min-width:62px}
/* ── Inputs ── */
.fcc-set-num{border:1.5px solid #e2e8f0;border-radius:7px;padding:8px 11px;font-size:14px;color:#1e293b;background:#fafafa;width:92px;box-sizing:border-box;transition:border-color .15s;text-align:right;font-family:inherit}
.fcc-set-num:focus{border-color:#ff914d;outline:none;background:#fff;box-shadow:0 0 0 3px rgba(255,145,77,.12)}
.fcc-set-sel{border:1.5px solid #e2e8f0;border-radius:7px;padding:8px 11px;font-size:13px;color:#1e293b;background:#fafafa;min-width:160px;transition:border-color .15s;cursor:pointer;font-family:inherit}
.fcc-set-sel:focus{border-color:#ff914d;outline:none;box-shadow:0 0 0 3px rgba(255,145,77,.12)}
.fcc-set-txt{border:1.5px solid #e2e8f0;border-radius:7px;padding:8px 11px;font-size:13px;color:#1e293b;background:#fafafa;width:280px;box-sizing:border-box;transition:border-color .15s;font-family:inherit}
.fcc-set-txt:focus{border-color:#ff914d;outline:none;background:#fff;box-shadow:0 0 0 3px rgba(255,145,77,.12)}
.fcc-set-unit{font-size:12px;color:#94a3b8;font-weight:600;white-space:nowrap}
/* ── Toggle switch ── */
.fcc-tog{position:relative;display:inline-block;width:46px;height:26px;cursor:pointer;flex-shrink:0}
.fcc-tog input{opacity:0;width:0;height:0;position:absolute}
.fcc-tog-sl{position:absolute;top:0;left:0;right:0;bottom:0;background:#e2e8f0;border-radius:26px;transition:.25s}
.fcc-tog-sl:before{position:absolute;content:"";height:20px;width:20px;left:3px;bottom:3px;background:#fff;border-radius:50%;transition:.25s;box-shadow:0 1px 4px rgba(0,0,0,.2)}
.fcc-tog input:checked+.fcc-tog-sl{background:#ff914d}
.fcc-tog input:checked+.fcc-tog-sl:before{transform:translateX(20px)}
.fcc-set-tog-lbl{font-size:12.5px;font-weight:600;color:#64748b;min-width:46px}
/* ── Toggle cards grid ── */
.fcc-tog-section{padding:16px 24px}
.fcc-tog-section+.fcc-tog-section{padding-top:4px}
.fcc-tog-section-title{font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:#94a3b8;margin:0 0 12px;display:flex;align-items:center;gap:8px}
.fcc-tog-section-title::after{content:"";flex:1;height:1px;background:#f1f5f9}
.fcc-tog-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:10px}
.fcc-tog-card{display:flex;align-items:center;gap:10px;padding:12px 14px;background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:10px;transition:border-color .15s}
.fcc-tog-card:hover{border-color:#ffc7a0;background:#fff9f6}
.fcc-tog-card-icon{font-size:19px;flex-shrink:0;width:28px;text-align:center}
.fcc-tog-card-body{flex:1;min-width:0}
.fcc-tog-card-body strong{display:block;font-size:12.5px;font-weight:700;color:#1e293b;margin-bottom:2px;line-height:1.3}
.fcc-tog-card-body span{font-size:11px;color:#64748b;line-height:1.4}
.fcc-tog-state{font-size:10.5px;font-weight:700;letter-spacing:.3px}
.fcc-tog-state.on{color:#16a34a}
.fcc-tog-state.off{color:#94a3b8}
/* ── Embed panel ── */
.fcc-embed-panel{background:linear-gradient(135deg,#0f172a 0%,#1e293b 100%)!important;border-color:#1e293b!important;margin-top:32px!important}
.fcc-embed-panel .fcc-set-chdr{border-bottom-color:#334155!important}
.fcc-embed-panel .fcc-set-ctitle{color:#e2e8f0!important}
.fcc-embed-panel .fcc-set-csub{color:#64748b!important}
.fcc-embed-panel .fcc-set-cicon{background:rgba(255,145,77,.15)!important}
.fcc-embed-body{padding:4px 24px 22px}
.fcc-embed-sc-row{display:flex;align-items:center;gap:10px;margin:14px 0}
.fcc-embed-sc{font-family:'Courier New',monospace;background:#0a0f1a;border:1.5px solid #334155;color:#7dd3fc;padding:11px 16px;border-radius:8px;font-size:14px;font-weight:700;flex:1;letter-spacing:.3px;user-select:all}
.fcc-embed-copy{background:#ff914d;color:#fff;border:none;padding:10px 18px;border-radius:7px;font-size:12.5px;font-weight:700;cursor:pointer;transition:background .15s;white-space:nowrap;font-family:inherit}
.fcc-embed-copy:hover{background:#e07a3d}
.fcc-embed-copy.copied{background:#16a34a}
.fcc-embed-sub{font-size:11.5px;color:#64748b;margin:0 0 14px;line-height:1.6}
.fcc-embed-pages-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#475569;margin:0 0 8px}
.fcc-embed-pages{display:flex;flex-wrap:wrap;gap:6px}
.fcc-embed-page-chip{display:inline-flex;align-items:center;gap:5px;padding:4px 11px;background:#1e293b;border:1px solid #334155;border-radius:999px;font-size:12px;color:#cbd5e1;text-decoration:none;transition:all .15s}
.fcc-embed-page-chip:hover{border-color:#ff914d;color:#ff914d;text-decoration:none}
.fcc-embed-none{font-size:13px;color:#475569;font-style:italic}
/* ── Saved notice ── */
.fcc-saved-ok{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;padding:12px 18px;border-radius:8px;font-weight:600;font-size:13px;margin-bottom:18px;display:flex;align-items:center;gap:8px}
/* ── Save bar ── */
.fcc-save-bar{display:flex;align-items:center;gap:14px;padding:16px 0 6px}
.fcc-save-btn{background:#ff914d;color:#fff;border:none;padding:11px 28px;border-radius:8px;font-size:14px;font-weight:700;cursor:pointer;transition:background .15s;display:inline-flex;align-items:center;gap:7px;font-family:inherit}
.fcc-save-btn:hover{background:#e07a3d}
</style>

<form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" class="fcc-set-adm wrap">
<?php wp_nonce_field('fcc_save_settings'); ?>
<input type="hidden" name="action" value="fcc_save_settings">

<!-- ── Hero ─────────────────────────────────────────────────────────── -->
<div class="fcc-hero">
    <div class="fcc-hero-left">
        <div class="fcc-hero-icon">⚙️</div>
        <div>
            <div class="fcc-hero-title">Calculator Settings</div>
            <div class="fcc-hero-sub">Nutrition targets · calculator defaults · display controls · embed info</div>
        </div>
    </div>
    <button type="submit" class="fcc-save-btn">💾 Save Settings</button>
</div>

<?php if ( isset($_GET['saved']) ): ?>
<div class="fcc-saved-ok">✅ Settings saved successfully.</div>
<?php endif; ?>

<!-- ── Card 1: Nutrition Targets ─────────────────────────────────────── -->
<div class="fcc-set-card fcc-card-orange">
    <div class="fcc-set-chdr">
        <div class="fcc-set-cicon" style="background:linear-gradient(135deg,#fff3e8,#ffe0c4);box-shadow:0 0 0 3px rgba(255,145,77,.18),0 4px 10px rgba(255,145,77,.15)">🥗</div>
        <div>
            <p class="fcc-set-ctitle">Nutrition Targets</p>
            <p class="fcc-set-csub">Daily reference values — shown as % Daily Value progress bars on the result card</p>
        </div>
    </div>
    <div class="fcc-set-body">
    <div class="fcc-set-row">
        <div class="fcc-set-lbl">
            <strong>Daily Calorie Goal</strong>
            <span>Used to calculate % Daily Value. NHS adult reference is 2,000 kcal.</span>
        </div>
        <div class="fcc-set-ctrl">
            <input type="number" name="daily_calories" value="<?php echo esc_attr($s['daily_calories']); ?>" step="50" min="1000" max="5000" class="fcc-set-num">
            <span class="fcc-set-unit">kcal / day</span>
        </div>
    </div>
    <div class="fcc-set-row">
        <div class="fcc-set-lbl">
            <strong>Daily Protein Target</strong>
            <span>NHS / WHO recommendation for average adults is 50 g/day.</span>
        </div>
        <div class="fcc-set-ctrl">
            <input type="number" name="protein_target" value="<?php echo esc_attr($s['protein_target']); ?>" step="5" min="10" max="300" class="fcc-set-num">
            <span class="fcc-set-unit">g / day</span>
        </div>
    </div>
    <div class="fcc-set-row">
        <div class="fcc-set-lbl">
            <strong>Daily Fat Target</strong>
            <span>UK NHS reference value is 70 g/day for average adults.</span>
        </div>
        <div class="fcc-set-ctrl">
            <input type="number" name="fat_target" value="<?php echo esc_attr($s['fat_target']); ?>" step="5" min="10" max="300" class="fcc-set-num">
            <span class="fcc-set-unit">g / day</span>
        </div>
    </div>
    <div class="fcc-set-row">
        <div class="fcc-set-lbl">
            <strong>Daily Carbohydrate Target</strong>
            <span>UK NHS reference value is 260 g/day for average adults.</span>
        </div>
        <div class="fcc-set-ctrl">
            <input type="number" name="carbs_target" value="<?php echo esc_attr($s['carbs_target']); ?>" step="10" min="50" max="600" class="fcc-set-num">
            <span class="fcc-set-unit">g / day</span>
        </div>
    </div>
    <div class="fcc-set-row">
        <div class="fcc-set-lbl">
            <strong>Daily Omega-3 Target</strong>
            <span>NHS / WHO recommend 0.25–0.5 g EPA+DHA per day. Shown as a dedicated progress bar.</span>
        </div>
        <div class="fcc-set-ctrl">
            <input type="number" name="omega3_target" value="<?php echo esc_attr($s['omega3_target']); ?>" step="0.05" min="0.1" max="5.0" class="fcc-set-num">
            <span class="fcc-set-unit">g / day</span>
        </div>
    </div>
    </div>
</div>

<!-- ── Card 2: Calculator Defaults ──────────────────────────────────── -->
<div class="fcc-set-card fcc-card-blue">
    <div class="fcc-set-chdr">
        <div class="fcc-set-cicon" style="background:linear-gradient(135deg,#eff6ff,#dbeafe);box-shadow:0 0 0 3px rgba(59,130,246,.18),0 4px 10px rgba(59,130,246,.12)">🦞</div>
        <div>
            <p class="fcc-set-ctitle">Calculator Defaults</p>
            <p class="fcc-set-csub">Pre-filled values when a visitor first opens the calculator</p>
        </div>
    </div>
    <div class="fcc-set-body">
    <div class="fcc-set-row">
        <div class="fcc-set-lbl">
            <strong>Default Serving Size</strong>
            <span>Pre-fills the "Amount" input. A typical fish fillet is 150–180 g. Visitors can still change it.</span>
        </div>
        <div class="fcc-set-ctrl">
            <input type="number" name="default_serving" value="<?php echo esc_attr($s['default_serving']); ?>" step="10" min="10" max="1000" class="fcc-set-num">
            <span class="fcc-set-unit">g</span>
        </div>
    </div>
    <div class="fcc-set-row">
        <div class="fcc-set-lbl">
            <strong>Default Cooking Method</strong>
            <span>Pre-selects the cooking dropdown when the calculator loads.</span>
        </div>
        <div class="fcc-set-ctrl">
            <select name="default_method" class="fcc-set-sel">
                <?php foreach ( $methods as $m ): ?>
                <option value="<?php echo esc_attr($m['key']); ?>" <?php selected( $s['default_method'], $m['key'] ); ?>><?php echo esc_html($m['label']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="fcc-set-row">
        <div class="fcc-set-lbl">
            <strong>Calculator Section Title</strong>
            <span>Heading shown above the search box. Leave blank to use the default "Seafood Calorie Lookup".</span>
        </div>
        <div class="fcc-set-ctrl">
            <input type="text" name="widget_title" value="<?php echo esc_attr($s['widget_title']); ?>" maxlength="80" placeholder="Seafood Calorie Lookup" class="fcc-set-txt">
        </div>
    </div>
    </div>
</div>

<!-- ── Card 3: Display Options ──────────────────────────────────────── -->
<div class="fcc-set-card fcc-card-green">
    <div class="fcc-set-chdr">
        <div class="fcc-set-cicon" style="background:linear-gradient(135deg,#f0fdf4,#dcfce7);box-shadow:0 0 0 3px rgba(16,185,129,.18),0 4px 10px rgba(16,185,129,.12)">👁</div>
        <div>
            <p class="fcc-set-ctitle">Display Options</p>
            <p class="fcc-set-csub">Show or hide sections and features in the front-end calculator</p>
        </div>
    </div>

    <div class="fcc-tog-section">
        <p class="fcc-tog-section-title">Calculator UI</p>
        <div class="fcc-tog-grid">
            <?php $tog_card('show_filter_bar', 'Health Filter Bar', 'Goal-based buttons above the search (Omega-3, Low Cal, etc.)', '🎯', $s['show_filter_bar']); ?>
            <?php $tog_card('show_tracker',    'Meal Tracker Tab',  'Multi-item meal builder with combined nutrition totals',         '📋', $s['show_tracker']); ?>
            <?php $tog_card('show_compare',    'Compare Tab',       'Side-by-side comparison of two different seafood items',         '⚖️', $s['show_compare']); ?>
        </div>
    </div>

    <div class="fcc-tog-section" style="padding-bottom:20px">
        <p class="fcc-tog-section-title">Result Card</p>
        <div class="fcc-tog-grid">
            <?php $tog_card('show_health_tips', 'Health Tips',   'Nutritional tip text below the Omega-3 insight section',     '💡', $s['show_health_tips']); ?>
            <?php $tog_card('show_allergens',   'Allergens',     'Allergen badges (Fish, Crustaceans, Molluscs, etc.)',          '⚠️', $s['show_allergens']); ?>
            <?php $tog_card('show_eco',         'Eco Rating',    'Sustainability badge and source label (MSC, Farmed, etc.)',   '🌿', $s['show_eco']); ?>
            <?php $tog_card('show_mercury',     'Mercury Level', 'Mercury risk indicator on the result card',                   '⚗️', $s['show_mercury']); ?>
            <?php $tog_card('show_season',      'UK Season',     'Seasonal availability and in-season indicator',               '📅', $s['show_season']); ?>
        </div>
    </div>
</div>

<!-- ── Card 4: Food Requests ────────────────────────────────────────── -->
<div class="fcc-set-card fcc-card-purple">
    <div class="fcc-set-chdr">
        <div class="fcc-set-cicon" style="background:linear-gradient(135deg,#faf5ff,#ede9fe);box-shadow:0 0 0 3px rgba(139,92,246,.18),0 4px 10px rgba(139,92,246,.12)">📨</div>
        <div>
            <p class="fcc-set-ctitle">Food Requests</p>
            <p class="fcc-set-csub">Control how visitors can suggest new items and how missed searches are tracked</p>
        </div>
    </div>
    <div class="fcc-set-body">
    <div class="fcc-set-row">
        <div class="fcc-set-lbl">
            <strong>"Suggest Adding" Button</strong>
            <span>Shows a request form when a search returns no results — lets visitors nominate foods to add. Submissions appear in the Food Requests admin page.</span>
        </div>
        <div class="fcc-set-ctrl">
            <label class="fcc-tog">
                <input type="hidden" name="show_request_btn" value="0">
                <input type="checkbox" name="show_request_btn" value="1" <?php checked($s['show_request_btn'],1); ?> onchange="this.closest('.fcc-set-ctrl').querySelector('.fcc-set-tog-lbl').textContent=this.checked?'Enabled':'Disabled'">
                <span class="fcc-tog-sl"></span>
            </label>
            <span class="fcc-set-tog-lbl"><?php echo $s['show_request_btn'] ? 'Enabled' : 'Disabled'; ?></span>
        </div>
    </div>
    <div class="fcc-set-row">
        <div class="fcc-set-lbl">
            <strong>Auto-log Missed Searches</strong>
            <span>Silently records searches that return 0 results (3+ characters). Shown in the Missed Searches table in Food Requests — useful for spotting high-demand gaps in your database.</span>
        </div>
        <div class="fcc-set-ctrl">
            <label class="fcc-tog">
                <input type="hidden" name="auto_log_searches" value="0">
                <input type="checkbox" name="auto_log_searches" value="1" <?php checked($s['auto_log_searches'],1); ?> onchange="this.closest('.fcc-set-ctrl').querySelector('.fcc-set-tog-lbl').textContent=this.checked?'Enabled':'Disabled'">
                <span class="fcc-tog-sl"></span>
            </label>
            <span class="fcc-set-tog-lbl"><?php echo $s['auto_log_searches'] ? 'Enabled' : 'Disabled'; ?></span>
        </div>
    </div>
    </div>
</div>

<!-- ── Card 5: Search Behaviour ─────────────────────────────────────── -->
<div class="fcc-set-card fcc-card-cyan">
    <div class="fcc-set-chdr">
        <div class="fcc-set-cicon" style="background:linear-gradient(135deg,#ecfeff,#cffafe);box-shadow:0 0 0 3px rgba(6,182,212,.18),0 4px 10px rgba(6,182,212,.12)">🔍</div>
        <div>
            <p class="fcc-set-ctitle">Search Behaviour</p>
            <p class="fcc-set-csub">Control how the search dropdown behaves for visitors</p>
        </div>
    </div>
    <div class="fcc-set-body">
    <div class="fcc-set-row">
        <div class="fcc-set-lbl">
            <strong>Search Results Limit</strong>
            <span>Maximum number of suggestions shown in the search dropdown. More results = longer list; fewer = cleaner UI.</span>
        </div>
        <div class="fcc-set-ctrl">
            <select name="search_results_limit" class="fcc-set-sel">
                <?php foreach ([6,8,10,12,15] as $n): ?>
                <option value="<?php echo $n; ?>" <?php selected($s['search_results_limit'],$n); ?>><?php echo $n; ?> results</option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="fcc-set-row">
        <div class="fcc-set-lbl">
            <strong>Minimum Characters to Search</strong>
            <span>How many characters a visitor must type before the search fires. 1 is most responsive; 3 reduces unnecessary server requests.</span>
        </div>
        <div class="fcc-set-ctrl">
            <select name="search_min_chars" class="fcc-set-sel">
                <?php foreach ([1=>'1 character (instant)',2=>'2 characters',3=>'3 characters'] as $n=>$l): ?>
                <option value="<?php echo $n; ?>" <?php selected($s['search_min_chars'],$n); ?>><?php echo $l; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="fcc-set-row">
        <div class="fcc-set-lbl">
            <strong>Name Match Priority</strong>
            <span>When ON, results where the fish name contains the query rank above category-only matches. Example: searching "caviar" shows "Beluga Caviar" before "Cod Roe" (which only matches via category). Individual items can also be pinned using <strong>Sort Priority</strong> in Manage Foods.</span>
        </div>
        <div class="fcc-set-ctrl">
            <input type="hidden" name="search_name_priority" value="0">
            <?php $tog_card('search_name_priority','Name Match Priority','Rank name matches above category matches','🎯',$s['search_name_priority']); ?>
        </div>
    </div>
    </div>
</div>

<!-- ── Save bar ─────────────────────────────────────────────────────── -->
<div class="fcc-save-bar">
    <button type="submit" class="fcc-save-btn">💾 Save Settings</button>
    <span style="font-size:12px;color:#94a3b8">Changes apply on the next page load</span>
</div>
</form>

<!-- ── Card 6: Shortcode & Embed (outside form — read-only info) ─────── -->
<div class="fcc-set-card fcc-embed-panel fcc-set-adm">
    <div class="fcc-set-chdr">
        <div class="fcc-set-cicon">📌</div>
        <div>
            <p class="fcc-set-ctitle">Shortcode &amp; Embed</p>
            <p class="fcc-set-csub">Copy this shortcode into any page or post to embed the calculator</p>
        </div>
    </div>
    <div class="fcc-embed-body">
        <div class="fcc-embed-sc-row">
            <div class="fcc-embed-sc" id="fcc-sc-text">[seafood_calorie_calculator]</div>
            <button type="button" class="fcc-embed-copy" id="fcc-sc-copy" onclick="
                navigator.clipboard.writeText('[seafood_calorie_calculator]').then(function(){
                    var b=document.getElementById('fcc-sc-copy');
                    b.textContent='✓ Copied!';b.classList.add('copied');
                    setTimeout(function(){b.textContent='Copy';b.classList.remove('copied');},2000);
                });
            ">Copy</button>
        </div>
        <p class="fcc-embed-sub">Paste it into the WordPress page editor (Gutenberg: use a <strong>Shortcode block</strong>; Classic editor: paste directly into the text). One shortcode per page is recommended.</p>
        <?php if ( $sc_pages ): ?>
        <p class="fcc-embed-pages-label" style="color:#64748b">Currently active on:</p>
        <div class="fcc-embed-pages">
            <?php foreach ( $sc_pages as $pg ): ?>
            <a href="<?php echo esc_url( get_edit_post_link($pg['ID']) ); ?>" class="fcc-embed-page-chip" target="_blank">
                <span style="font-size:10px;opacity:.6"><?php echo esc_html(ucfirst($pg['post_type'])); ?></span>
                <?php echo esc_html($pg['post_title']); ?>
                <span style="font-size:10px;opacity:.5">↗</span>
            </a>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <p class="fcc-embed-none" style="color:#475569">Not embedded on any published page yet — copy the shortcode above and paste it into a page to get started.</p>
        <?php endif; ?>
    </div>
</div>
        <?php
    }

    // ── Admin: Analytics Dashboard ────────────────────────────────────────────
    public function adminAnalyticsPage() {
        global $wpdb;
        $agg   = $wpdb->prefix . 'fcc_analytics';
        $daily = $wpdb->prefix . 'fcc_analytics_daily';
        $foods = $wpdb->prefix . 'fcc_foods';

        // Date range filter
        $fcc_from = isset($_GET['fcc_from']) ? sanitize_text_field($_GET['fcc_from']) : '';
        $fcc_to   = isset($_GET['fcc_to'])   ? sanitize_text_field($_GET['fcc_to'])   : '';
        if ($fcc_from && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fcc_from)) $fcc_from = '';
        if ($fcc_to   && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fcc_to))   $fcc_to   = '';
        $has_range = $fcc_from !== '' && $fcc_to !== '';

        // KPIs
        $t_calc  = (int) $wpdb->get_var( "SELECT SUM(calcs) FROM $agg" );
        $t_srch  = (int) $wpdb->get_var( "SELECT SUM(searches) FROM $agg" );
        $t_items = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $agg" );
        $today_calcs = 0;
        $week_calcs  = 0;
        $has_daily = (bool) get_option( 'fcc_tables_ready' );
        if ( $has_daily ) {
            $today_calcs = (int) $wpdb->get_var( "SELECT SUM(calcs) FROM $daily WHERE log_date = CURDATE()" );
            $week_calcs  = (int) $wpdb->get_var( "SELECT SUM(calcs) FROM $daily WHERE log_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)" );
        }

        // Top 10 all-time for bar chart
        $top10 = $wpdb->get_results(
            "SELECT a.food_id, a.food_name, a.calcs, a.searches, COALESCE(f.category,'Unknown') as category
             FROM $agg a LEFT JOIN $foods f ON a.food_id = f.id
             ORDER BY a.calcs DESC LIMIT 10", ARRAY_A ) ?: [];
        $max_calcs = $top10 ? max( array_column($top10, 'calcs') ) : 1;

        // Category breakdown
        $cat_rows = $wpdb->get_results(
            "SELECT COALESCE(f.category,'Unknown') as category, SUM(a.calcs) as calcs
             FROM $agg a LEFT JOIN $foods f ON a.food_id = f.id
             GROUP BY f.category ORDER BY calcs DESC", ARRAY_A ) ?: [];
        $total_cat = max( 1, array_sum( array_column($cat_rows, 'calcs') ) );

        // Last 7 days
        $trend7 = [];
        if ( $has_daily ) {
            $trend7 = $wpdb->get_results(
                "SELECT food_name, SUM(calcs) as calcs FROM $daily
                 WHERE log_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                 GROUP BY food_id ORDER BY calcs DESC LIMIT 5", ARRAY_A ) ?: [];
        }

        // Top 10 by searches (separate from calcs)
        $top10_searched = $wpdb->get_results(
            "SELECT a.food_id, a.food_name, a.calcs, a.searches, COALESCE(f.category,'Unknown') as category
             FROM $agg a LEFT JOIN $foods f ON a.food_id = f.id
             ORDER BY a.searches DESC LIMIT 10", ARRAY_A ) ?: [];
        $max_searches = $top10_searched ? max( array_column($top10_searched, 'searches') ) : 1;

        // Discovery gap: searched but never calculated (window-shoppers)
        $discovery_gap = $wpdb->get_results(
            "SELECT food_id, food_name, searches FROM $agg
             WHERE calcs = 0 AND searches > 0
             ORDER BY searches DESC LIMIT 5", ARRAY_A ) ?: [];

        // Day-by-day activity (7-day default or custom date range)
        $daily_activity = [];
        $daily_max = 1;
        if ( $has_daily ) {
            if ($has_range) {
                $daily_activity = $wpdb->get_results(
                    $wpdb->prepare("SELECT log_date, SUM(calcs) as calcs, SUM(searches) as searches
                     FROM $daily WHERE log_date BETWEEN %s AND %s
                     GROUP BY log_date ORDER BY log_date ASC", $fcc_from, $fcc_to), ARRAY_A ) ?: [];
            } else {
                $daily_activity = $wpdb->get_results(
                    "SELECT log_date, SUM(calcs) as calcs, SUM(searches) as searches
                     FROM $daily WHERE log_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                     GROUP BY log_date ORDER BY log_date ASC", ARRAY_A ) ?: [];
            }
            if ( $daily_activity ) $daily_max = max( 1, max( array_column($daily_activity, 'calcs') ) );
        }

        // Today's top fish
        $today_top = null;
        if ( $has_daily ) {
            $today_top = $wpdb->get_row(
                "SELECT food_name, calcs FROM $daily WHERE log_date = CURDATE() ORDER BY calcs DESC LIMIT 1", ARRAY_A );
        }

        // Engagement rate (avg calcs per search)
        $engagement_rate = ( $t_srch > 0 ) ? round( ( $t_calc / $t_srch ) * 100 ) : 0;

        // Unique active days
        $active_days = $has_daily ? (int) $wpdb->get_var( "SELECT COUNT(DISTINCT log_date) FROM $daily" ) : 0;

        // Avg calcs per active day
        $avg_calcs_day = $active_days > 0 ? round( $t_calc / $active_days, 1 ) : 0;

        // Week-over-week: last 7 days vs previous 7 days
        $last_week_calcs = 0;
        $week_trend      = null;
        if ( $has_daily ) {
            $last_week_calcs = (int) $wpdb->get_var(
                "SELECT SUM(calcs) FROM $daily WHERE log_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY) AND log_date < DATE_SUB(CURDATE(), INTERVAL 7 DAY)" );
            if ( $last_week_calcs > 0 ) {
                $week_trend = round( ( ( $week_calcs - $last_week_calcs ) / $last_week_calcs ) * 100 );
            }
        }

        // Day-of-week activity patterns
        $dow_data   = [];
        $dow_max    = 1;
        $dow_by_num = [];
        if ( $has_daily ) {
            $dow_data = $wpdb->get_results(
                "SELECT DAYNAME(log_date) as day_name, DAYOFWEEK(log_date) as day_num,
                        SUM(calcs) as calcs, SUM(searches) as searches
                 FROM $daily GROUP BY DAYOFWEEK(log_date) ORDER BY DAYOFWEEK(log_date)", ARRAY_A ) ?: [];
            if ( $dow_data ) $dow_max = max( 1, max( array_column($dow_data, 'calcs') ) );
            foreach ( $dow_data as $d ) { $dow_by_num[(int)$d['day_num']] = $d; }
        }

        // Last 14 days
        $trend14 = [];
        $trend14_max = 1;
        if ( $has_daily ) {
            $trend14 = $wpdb->get_results(
                "SELECT log_date, SUM(calcs) as calcs, SUM(searches) as searches
                 FROM $daily WHERE log_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
                 GROUP BY log_date ORDER BY log_date ASC", ARRAY_A ) ?: [];
            if ( $trend14 ) $trend14_max = max( 1, max( array_column($trend14, 'calcs') ) );
        }

        // Last 30 days for extended trend
        $trend30 = [];
        $trend30_max = 1;
        if ( $has_daily ) {
            $trend30 = $wpdb->get_results(
                "SELECT log_date, SUM(calcs) as calcs, SUM(searches) as searches
                 FROM $daily WHERE log_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                 GROUP BY log_date ORDER BY log_date ASC", ARRAY_A ) ?: [];
            if ( $trend30 ) $trend30_max = max( 1, max( array_column($trend30, 'calcs') ) );
        }

        // All items detail table
        $sort = in_array( sanitize_text_field($_GET['sort'] ?? ''), ['searches','calcs','total','eng'] ) ? sanitize_text_field($_GET['sort']) : 'calcs';
        $order_sql = match( $sort ) {
            'total' => '(a.searches + a.calcs)',
            'eng'   => 'IF(a.searches>0, a.calcs/a.searches, 0)',
            default => "a.$sort",
        };
        $all_items = $wpdb->get_results(
            "SELECT a.food_id, a.food_name, a.calcs, a.searches, COALESCE(f.category,'Unknown') as category
             FROM $agg a LEFT JOIN $foods f ON a.food_id = f.id
             ORDER BY $order_sql DESC", ARRAY_A ) ?: [];

        // Category colour map
        $cat_colours = [
            'White Fish'=>'#3b82f6','Oily Fish'=>'#f97316','Shellfish'=>'#a855f7',
            'Squid & Octopus'=>'#0891b2','Canned & Smoked'=>'#65a30d',
            'Roe & Caviar'=>'#dc2626','Prepared Seafood'=>'#f59e0b','Seaweed'=>'#10b981',
            'Unknown'=>'#94a3b8',
        ];

        $top_fish = $top10[0] ?? null;
        $top_cat  = $cat_rows[0] ?? null;
        $max_interest = $all_items ? max( array_map(fn($r) => $r['searches'] + $r['calcs'], $all_items) ) : 1;
        ?>
<style>
/* ── Analytics base ──────────────────────────────── */
.fcc-bi{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;max-width:1280px;color:#1e293b}
/* ── Hero ────────────────────────────────────────── */
.fcc-bi-hero{background:linear-gradient(135deg,#1e293b 0%,#334155 100%);border-radius:12px;padding:20px 24px;margin-bottom:22px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:14px;box-shadow:0 4px 20px rgba(0,0,0,.15)}
.fcc-bi-hero-left{display:flex;align-items:center;gap:14px}
.fcc-bi-hero-icon{width:48px;height:48px;background:linear-gradient(135deg,#ff914d,#e07a3d);border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:24px;flex-shrink:0;box-shadow:0 4px 12px rgba(255,145,77,.35)}
.fcc-bi-hero-title{color:#fff;font-size:19px;font-weight:700;margin:0;line-height:1.2}
.fcc-bi-hero-sub{color:rgba(255,255,255,.5);font-size:12px;margin-top:3px}
.fcc-bi-hero-right{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.fcc-bi-btn{padding:9px 16px;border-radius:7px;font-size:12.5px;font-weight:700;text-decoration:none;border:none;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all .15s;white-space:nowrap}
.fcc-bi-btn-or{background:#ff914d;color:#fff}.fcc-bi-btn-or:hover{background:#e07a3d;color:#fff}
.fcc-bi-btn-ghost{background:rgba(255,255,255,.12);color:#fff;border:1.5px solid rgba(255,255,255,.2)}.fcc-bi-btn-ghost:hover{background:rgba(255,255,255,.22)}
.fcc-bi-btn-danger{background:rgba(220,38,38,.15);color:#fca5a5;border:1.5px solid rgba(220,38,38,.3)}.fcc-bi-btn-danger:hover{background:rgba(220,38,38,.25)}
/* ── KPI cards ───────────────────────────────────── */
.fcc-kpi-row{display:grid;grid-template-columns:repeat(auto-fill,minmax(155px,1fr));gap:14px;margin-bottom:22px}
.fcc-kpi{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:16px 18px;position:relative;overflow:hidden;box-shadow:0 1px 6px rgba(0,0,0,.05)}
.fcc-kpi::before{content:'';position:absolute;top:0;left:0;right:0;height:4px;background:var(--kc,#ff914d);border-radius:10px 10px 0 0}
.fcc-kpi-ico{font-size:20px;margin-bottom:8px;display:block}
.fcc-kpi-val{font-size:26px;font-weight:800;color:#1e293b;line-height:1}
.fcc-kpi-lbl{font-size:11.5px;color:#64748b;margin-top:5px;font-weight:700;text-transform:uppercase;letter-spacing:.3px}
.fcc-kpi-sub{font-size:11px;color:#94a3b8;margin-top:4px;display:flex;align-items:center;gap:4px}
.fcc-trend-up{color:#16a34a;font-weight:700}.fcc-trend-dn{color:#dc2626;font-weight:700}.fcc-trend-eq{color:#94a3b8}
/* ── Panels ──────────────────────────────────────── */
.fcc-grid2{display:grid;grid-template-columns:3fr 2fr;gap:18px;margin-bottom:18px}
.fcc-grid3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:18px;margin-bottom:18px}
.fcc-panel{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:20px;box-shadow:0 1px 6px rgba(0,0,0,.05)}
.fcc-panel-hdr{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;padding-bottom:12px;border-bottom:1px solid #f1f5f9}
.fcc-panel-hdr h3{margin:0;font-size:14px;color:#1e293b;font-weight:800;display:flex;align-items:center;gap:7px}
.fcc-panel-note{font-size:11px;color:#94a3b8;margin-top:12px}
/* ── Bar charts ──────────────────────────────────── */
.fcc-bar-row{display:flex;align-items:center;gap:10px;margin-bottom:7px}
.fcc-bar-name{width:120px;font-size:12px;font-weight:600;color:#334155;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;flex-shrink:0}
.fcc-bar-track{flex:1;height:20px;background:#f1f5f9;border-radius:5px;overflow:hidden}
.fcc-bar-fill{height:100%;border-radius:5px;transition:width .6s cubic-bezier(.4,0,.2,1)}
.fcc-bar-val{width:44px;text-align:right;font-size:12px;font-weight:700;color:#475569;flex-shrink:0}
/* ── Category ────────────────────────────────────── */
.fcc-cat-row{display:flex;align-items:center;gap:10px;margin-bottom:8px}
.fcc-cat-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0}
.fcc-cat-name{font-size:12.5px;color:#334155;font-weight:600;flex:1}
.fcc-cat-pct{font-size:12px;font-weight:800;color:#1e293b;width:38px;text-align:right}
.fcc-cat-track{width:90px;height:8px;background:#f1f5f9;border-radius:4px;overflow:hidden;flex-shrink:0}
.fcc-cat-fill{height:100%;border-radius:4px}
/* ── Insights ────────────────────────────────────── */
.fcc-insight{background:#fff8f4;border-left:4px solid #ff914d;border-radius:0 7px 7px 0;padding:11px 14px;margin-bottom:9px;font-size:13px;color:#334155;line-height:1.55}
.fcc-insight strong{color:#c2410c}
/* ── 7-Day chart ─────────────────────────────────── */
.fcc-day-row{display:flex;align-items:center;gap:8px;margin-bottom:6px}
.fcc-day-lbl{width:68px;font-size:11px;color:#64748b;font-weight:600;flex-shrink:0}
.fcc-day-stack{flex:1;display:flex;flex-direction:column;gap:2px}
.fcc-day-track{height:10px;background:#f1f5f9;border-radius:3px;overflow:hidden}
.fcc-day-calc{height:100%;border-radius:3px;background:#ff914d}
.fcc-day-srch{height:100%;border-radius:3px;background:#cbd5e1}
.fcc-day-vals{width:54px;text-align:right;font-size:10.5px;color:#64748b;flex-shrink:0;white-space:nowrap}
/* ── Day-of-week ─────────────────────────────────── */
.fcc-dow-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:6px;align-items:end;height:80px;margin-bottom:8px}
.fcc-dow-col{display:flex;flex-direction:column;align-items:center;gap:4px;height:100%}
.fcc-dow-bar-wrap{flex:1;display:flex;align-items:flex-end;width:100%}
.fcc-dow-bar{width:100%;border-radius:4px 4px 0 0;background:#e2e8f0;min-height:4px;transition:background .2s}
.fcc-dow-bar.has-data{background:#ff914d}
.fcc-dow-day{font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase}
.fcc-dow-val{font-size:10px;color:#64748b;font-weight:600}
/* ── Gap items ───────────────────────────────────── */
.fcc-gap-item{display:flex;justify-content:space-between;align-items:center;padding:8px 11px;background:#fef9f0;border:1px solid #fed7aa;border-radius:7px;margin-bottom:6px;font-size:12.5px}
.fcc-gap-name{font-weight:700;color:#1e293b}
.fcc-gap-hint{font-size:11px;color:#94a3b8;margin-top:2px}
/* ── Badges ──────────────────────────────────────── */
.fcc-eng-badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700}
.fcc-eng-high{background:#dcfce7;color:#16a34a}.fcc-eng-mid{background:#fef9c3;color:#854d0e}.fcc-eng-low{background:#f1f5f9;color:#64748b}
.fcc-no-calc-badge{font-size:11px;background:#fee2e2;color:#dc2626;padding:2px 8px;border-radius:10px;font-weight:700}
/* ── Detail table ────────────────────────────────── */
.fcc-tbl-wrap{background:#fff;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;margin-bottom:24px;box-shadow:0 1px 6px rgba(0,0,0,.05)}
.fcc-detail-tbl{width:100%;border-collapse:collapse;font-size:12.5px}
.fcc-detail-tbl thead th{background:#1e293b;color:#cbd5e1;padding:11px 13px;text-align:left;font-weight:700;white-space:nowrap;font-size:11.5px;text-transform:uppercase;letter-spacing:.3px}
.fcc-detail-tbl thead th a{color:#94a3b8;text-decoration:none}
.fcc-detail-tbl thead th a:hover,.fcc-sort-active{color:#ff914d!important;font-weight:800}
.fcc-detail-tbl tbody tr{border-bottom:1px solid #f1f5f9;transition:background .1s}
.fcc-detail-tbl tbody tr:hover{background:#fff8f4}
.fcc-detail-tbl tbody tr:last-child{border-bottom:none}
.fcc-detail-tbl td{padding:10px 13px;vertical-align:middle}
.fcc-rank-1{background:#ff914d;color:#fff;width:22px;height:22px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-weight:800;font-size:11px}
.fcc-rank-2{background:#94a3b8;color:#fff;width:22px;height:22px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-weight:800;font-size:11px}
.fcc-rank-3{background:#d97706;color:#fff;width:22px;height:22px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-weight:800;font-size:11px}
.fcc-rank-n{color:#94a3b8;width:22px;text-align:center;font-size:12px;display:inline-block}
.fcc-interest-bar{display:inline-block;height:5px;border-radius:3px;background:#ff914d;vertical-align:middle;margin-left:6px;opacity:.7}
.fcc-empty-analytics{padding:40px;text-align:center;color:#94a3b8;font-size:14px}
@media(max-width:900px){.fcc-grid2,.fcc-grid3{grid-template-columns:1fr}.fcc-kpi-row{grid-template-columns:repeat(2,1fr)}.fcc-bar-name{width:90px}}
</style>
<div class="fcc-bi wrap">

<!-- Hero header -->
<div class="fcc-bi-hero">
    <div class="fcc-bi-hero-left">
        <div class="fcc-bi-hero-icon">📊</div>
        <div>
            <div class="fcc-bi-hero-title">Analytics Dashboard</div>
            <div class="fcc-bi-hero-sub">
                <?php
                $date_range = $has_daily && $active_days
                    ? 'Tracking since ' . $wpdb->get_var("SELECT MIN(log_date) FROM $daily") . ' · ' . number_format($t_calc) . ' total calculations'
                    : 'Lifetime engagement data for your seafood calculator';
                echo esc_html($date_range);
                ?>
            </div>
        </div>
    </div>
    <div class="fcc-bi-hero-right" style="flex-wrap:wrap;gap:8px">
        <!-- Date range filter -->
        <form method="get" action="" style="display:inline-flex;align-items:center;gap:6px;flex-wrap:wrap">
            <input type="hidden" name="page" value="fcc-analytics">
            <?php if (isset($_GET['sort'])): ?><input type="hidden" name="sort" value="<?php echo esc_attr($_GET['sort']); ?>"><?php endif; ?>
            <input type="date" name="fcc_from" value="<?php echo esc_attr($fcc_from); ?>"
                   style="padding:5px 8px;border:1px solid #cbd5e1;border-radius:6px;font-size:12px;color:#1e293b">
            <span style="font-size:12px;color:#64748b">to</span>
            <input type="date" name="fcc_to" value="<?php echo esc_attr($fcc_to); ?>"
                   style="padding:5px 8px;border:1px solid #cbd5e1;border-radius:6px;font-size:12px;color:#1e293b">
            <button type="submit" class="fcc-bi-btn" style="background:#334155;color:#fff">Filter</button>
            <?php if ($has_range): ?>
            <a href="?page=fcc-analytics" class="fcc-bi-btn" style="background:#e2e8f0;color:#475569;text-decoration:none">✕ Clear</a>
            <?php endif; ?>
        </form>
        <!-- Export buttons (pass current date range) -->
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
            <?php wp_nonce_field('fcc_export_analytics'); ?>
            <input type="hidden" name="action" value="fcc_export_analytics">
            <input type="hidden" name="fcc_from" value="<?php echo esc_attr($fcc_from); ?>">
            <input type="hidden" name="fcc_to"   value="<?php echo esc_attr($fcc_to); ?>">
            <button type="submit" class="fcc-bi-btn fcc-bi-btn-or">↓ CSV</button>
        </form>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
            <?php wp_nonce_field('fcc_export_excel'); ?>
            <input type="hidden" name="action" value="fcc_export_excel">
            <input type="hidden" name="fcc_from" value="<?php echo esc_attr($fcc_from); ?>">
            <input type="hidden" name="fcc_to"   value="<?php echo esc_attr($fcc_to); ?>">
            <button type="submit" class="fcc-bi-btn" style="background:#1d6f42;color:#fff">↓ Excel</button>
        </form>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline"
              onsubmit="return confirm('Reset ALL analytics data? This cannot be undone.');">
            <?php wp_nonce_field('fcc_reset_analytics'); ?>
            <input type="hidden" name="action" value="fcc_reset_analytics">
            <button type="submit" class="fcc-bi-btn fcc-bi-btn-danger">⚠ Reset All</button>
        </form>
    </div>
</div>
<?php if ($has_range): ?>
<div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:10px 16px;margin-bottom:16px;font-size:13px;color:#1d4ed8">
    📅 Showing data from <strong><?php echo esc_html($fcc_from); ?></strong> to <strong><?php echo esc_html($fcc_to); ?></strong>
    — Activity chart and exports reflect this range. KPI totals remain all-time.
</div>
<?php endif; ?>

<?php if ( isset($_GET['reset']) ): ?><div class="notice notice-success is-dismissible" style="margin-bottom:16px"><p>Analytics data has been reset.</p></div><?php endif; ?>

<!-- KPIs -->
<div class="fcc-kpi-row">
    <div class="fcc-kpi" style="--kc:#ff914d">
        <span class="fcc-kpi-ico">🧮</span>
        <div class="fcc-kpi-val"><?php echo number_format($t_calc); ?></div>
        <div class="fcc-kpi-lbl">Total Calculations</div>
        <div class="fcc-kpi-sub">
            <?php if ($week_calcs): ?>
                <span><?php echo number_format($week_calcs); ?> this week</span>
                <?php if ($week_trend !== null): ?>
                <span class="<?php echo $week_trend > 0 ? 'fcc-trend-up' : ($week_trend < 0 ? 'fcc-trend-dn' : 'fcc-trend-eq'); ?>">
                    <?php echo ($week_trend > 0 ? '↑' : ($week_trend < 0 ? '↓' : '→')) . ' ' . abs($week_trend) . '%'; ?>
                </span>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    <div class="fcc-kpi" style="--kc:#3b82f6">
        <span class="fcc-kpi-ico">🔍</span>
        <div class="fcc-kpi-val"><?php echo number_format($t_srch); ?></div>
        <div class="fcc-kpi-lbl">Total Searches</div>
        <div class="fcc-kpi-sub"><?php echo $t_srch ? $engagement_rate . '% convert to calc' : 'No searches yet'; ?></div>
    </div>
    <div class="fcc-kpi" style="--kc:#8b5cf6">
        <span class="fcc-kpi-ico">🦞</span>
        <div class="fcc-kpi-val"><?php echo number_format($t_items); ?></div>
        <div class="fcc-kpi-lbl">Fish Tracked</div>
        <div class="fcc-kpi-sub">of <?php echo count($this->getFoodDatabase()); ?> in DB</div>
    </div>
    <div class="fcc-kpi" style="--kc:#16a34a">
        <span class="fcc-kpi-ico">📅</span>
        <div class="fcc-kpi-val" style="color:#16a34a"><?php echo number_format($today_calcs); ?></div>
        <div class="fcc-kpi-lbl">Today's Calcs</div>
        <div class="fcc-kpi-sub"><?php if ($today_top): echo esc_html($today_top['food_name']); else: echo date('D j M'); endif; ?></div>
    </div>
    <div class="fcc-kpi" style="--kc:#f59e0b">
        <span class="fcc-kpi-ico">⚡</span>
        <div class="fcc-kpi-val" style="color:#d97706"><?php echo number_format($avg_calcs_day, 1); ?></div>
        <div class="fcc-kpi-lbl">Avg Calcs / Day</div>
        <div class="fcc-kpi-sub"><?php echo $active_days ? 'over ' . $active_days . ' active days' : 'no data yet'; ?></div>
    </div>
    <div class="fcc-kpi" style="--kc:#0891b2">
        <span class="fcc-kpi-ico">🔦</span>
        <div class="fcc-kpi-val" style="color:#0891b2"><?php echo count($discovery_gap); ?></div>
        <div class="fcc-kpi-lbl">Discovery Gap</div>
        <div class="fcc-kpi-sub">searched, never calc'd</div>
    </div>
    <div class="fcc-kpi" style="--kc:#ec4899">
        <span class="fcc-kpi-ico">💬</span>
        <div class="fcc-kpi-val" style="color:#db2777"><?php echo $engagement_rate; ?>%</div>
        <div class="fcc-kpi-lbl">Conversion Rate</div>
        <div class="fcc-kpi-sub">searches → calculations</div>
    </div>
</div>

<?php if ( $t_items == 0 ): ?>
<div class="fcc-empty-analytics">
    <p>📊 No analytics data yet.<br>Data is collected automatically as users search and calculate on the frontend.</p>
</div>
<?php else: ?>

<!-- Top 10 Calculated + Top 10 Searched -->
<div class="fcc-grid2">
    <!-- Most Calculated -->
    <div class="fcc-panel">
        <div class="fcc-panel-hdr"><h3>🏆 Top 10 Most Calculated</h3></div>
        <?php if ( $top10 ): foreach ( $top10 as $r ):
            $pct   = $max_calcs > 0 ? round( ($r['calcs'] / $max_calcs) * 100 ) : 0;
            $color = $cat_colours[ $r['category'] ] ?? '#94a3b8';
        ?>
        <div class="fcc-bar-row">
            <div class="fcc-bar-name" title="<?php echo esc_attr($r['food_name']); ?>"><?php echo esc_html($r['food_name']); ?></div>
            <div class="fcc-bar-track">
                <div class="fcc-bar-fill" style="width:<?php echo $pct; ?>%;background:<?php echo $color; ?>"></div>
            </div>
            <div class="fcc-bar-val"><?php echo number_format($r['calcs']); ?></div>
        </div>
        <?php endforeach; else: ?>
        <p style="color:#94a3b8;font-size:13px">No calculation data yet.</p>
        <?php endif; ?>
        <p style="margin:12px 0 0;font-size:11px;color:#94a3b8">Coloured by category · lifetime calculations</p>
    </div>

    <!-- Most Searched -->
    <div class="fcc-panel">
        <div class="fcc-panel-hdr"><h3>🔍 Top 10 Most Searched</h3></div>
        <?php if ( $top10_searched ): foreach ( $top10_searched as $r ):
            $pct   = $max_searches > 0 ? round( ($r['searches'] / $max_searches) * 100 ) : 0;
            $color = $cat_colours[ $r['category'] ] ?? '#94a3b8';
        ?>
        <div class="fcc-bar-row">
            <div class="fcc-bar-name" title="<?php echo esc_attr($r['food_name']); ?>"><?php echo esc_html($r['food_name']); ?></div>
            <div class="fcc-bar-track">
                <div class="fcc-bar-fill" style="width:<?php echo $pct; ?>%;background:<?php echo $color; ?>"></div>
            </div>
            <div class="fcc-bar-val"><?php echo number_format($r['searches']); ?></div>
        </div>
        <?php endforeach; else: ?>
        <p style="color:#94a3b8;font-size:13px">No search data yet.</p>
        <?php endif; ?>
        <p style="margin:12px 0 0;font-size:11px;color:#94a3b8">What customers are curious about · lifetime searches</p>
    </div>
</div>

<!-- Business Insights + Category + Weekly Patterns -->
<div class="fcc-grid3" style="margin-bottom:18px">
    <div class="fcc-panel">
        <div class="fcc-panel-hdr"><h3>💡 Business Insights</h3></div>
        <?php if ( $top_fish ): ?>
        <div class="fcc-insight">
            🥇 <strong><?php echo esc_html($top_fish['food_name']); ?></strong> is your most-calculated fish with <?php echo number_format($top_fish['calcs']); ?> calculations — feature it prominently in your shop and on the homepage.
        </div>
        <?php endif; ?>
        <?php if ( $top_cat ): ?>
        <div class="fcc-insight">
            📂 <strong><?php echo esc_html($top_cat['category']); ?></strong> is the most-browsed category (<?php echo number_format($top_cat['calcs']); ?> calcs). Ensure strong availability and variety in this range.
        </div>
        <?php endif; ?>
        <?php if ( isset($top10[0], $top10[1]) ): ?>
        <div class="fcc-insight">
            📦 Stock tip: ensure <strong><?php echo esc_html($top10[0]['food_name']); ?></strong> and <strong><?php echo esc_html($top10[1]['food_name']); ?></strong> are always available — customers actively check their nutrition before buying.
        </div>
        <?php endif; ?>
        <?php if ( $engagement_rate >= 70 ): ?>
        <div class="fcc-insight">
            ✅ <strong>High engagement:</strong> <?php echo $engagement_rate; ?>% of searchers go on to calculate — your visitors are serious buyers, not just browsers.
        </div>
        <?php elseif ( $engagement_rate > 0 && $engagement_rate < 40 ): ?>
        <div class="fcc-insight">
            💡 Only <?php echo $engagement_rate; ?>% of searches lead to a calculation. Consider adding more prominent "Calculate" prompts or health-focused copy to increase conversions.
        </div>
        <?php endif; ?>
        <?php if ( $discovery_gap ): ?>
        <div class="fcc-insight">
            🔦 <strong>Discovery gap:</strong> <strong><?php echo esc_html($discovery_gap[0]['food_name']); ?></strong><?php if (isset($discovery_gap[1])): ?> and <?php echo count($discovery_gap) - 1; ?> others<?php endif; ?> are searched but never calculated — customers look but don't commit. Check pricing, availability, or add a recipe/serving idea.
        </div>
        <?php endif; ?>
        <?php
        $foods_db    = $this->getFoodDatabase();
        $foods_by_id = [];
        foreach ($foods_db as $f) { $foods_by_id[(int)$f['id']] = $f; }
        foreach (array_slice($top10, 0, 5) as $r) {
            $fd = $foods_by_id[(int)$r['food_id']] ?? null;
            if ($fd && (float)($fd['omega3'] ?? 0) >= 1.5) {
                echo '<div class="fcc-insight">🌊 <strong>' . esc_html($r['food_name']) . '</strong> is a top seller <em>and</em> delivers ' . $fd['omega3'] . 'g omega-3/100g — use this as a health marketing story.</div>';
                break;
            }
        }
        ?>
    </div>

    <!-- Category breakdown -->
    <div class="fcc-panel">
        <div class="fcc-panel-hdr"><h3>📂 By Category</h3></div>
        <?php if ( $cat_rows ): foreach ( $cat_rows as $c ):
            $pct   = $total_cat > 0 ? round( ($c['calcs'] / $total_cat) * 100 ) : 0;
            $color = $cat_colours[ $c['category'] ] ?? '#94a3b8';
        ?>
        <div class="fcc-cat-row">
            <div class="fcc-cat-dot" style="background:<?php echo $color; ?>"></div>
            <div class="fcc-cat-name"><?php echo esc_html($c['category']); ?></div>
            <div class="fcc-cat-track"><div class="fcc-cat-fill" style="width:<?php echo $pct; ?>%;background:<?php echo $color; ?>"></div></div>
            <div class="fcc-cat-pct"><?php echo $pct; ?>%</div>
        </div>
        <?php endforeach; else: ?>
        <p style="color:#94a3b8;font-size:13px">No category data yet.</p>
        <?php endif; ?>
    </div>

    <!-- Weekly Patterns (day-of-week) -->
    <div class="fcc-panel">
        <div class="fcc-panel-hdr"><h3>📆 Weekly Patterns</h3></div>
        <?php
        $days = [1=>'Sun',2=>'Mon',3=>'Tue',4=>'Wed',5=>'Thu',6=>'Fri',7=>'Sat'];
        ?>
        <div class="fcc-dow-grid">
        <?php foreach ($days as $num => $lbl):
            $dd   = $dow_by_num[$num] ?? null;
            $calcs_d = $dd ? (int)$dd['calcs'] : 0;
            $h    = $dow_max > 0 ? max(4, round(($calcs_d / $dow_max) * 100)) : 4;
        ?>
        <div class="fcc-dow-col">
            <div class="fcc-dow-bar-wrap">
                <div class="fcc-dow-bar <?php echo $calcs_d ? 'has-data' : ''; ?>" style="height:<?php echo $h; ?>%"
                     title="<?php echo esc_attr($lbl . ': ' . $calcs_d . ' calcs'); ?>"></div>
            </div>
            <div class="fcc-dow-day"><?php echo $lbl; ?></div>
            <div class="fcc-dow-val"><?php echo $calcs_d ?: '—'; ?></div>
        </div>
        <?php endforeach; ?>
        </div>
        <p class="fcc-panel-note">Which days customers are most active — useful for stock planning &amp; promotions</p>
    </div>
</div>

<!-- Activity charts + Discovery Gap -->
<div class="fcc-grid2" style="margin-bottom:24px">
    <div class="fcc-panel">
        <div class="fcc-panel-hdr">
            <h3>📅 <?php echo $has_range ? 'Custom Range Activity' : '7-Day Activity'; ?></h3>
            <div style="display:flex;gap:10px;font-size:11px;font-weight:600">
                <span style="display:flex;align-items:center;gap:4px"><span style="width:10px;height:10px;border-radius:2px;background:#ff914d;display:inline-block"></span>Calcs</span>
                <span style="display:flex;align-items:center;gap:4px"><span style="width:10px;height:10px;border-radius:2px;background:#cbd5e1;display:inline-block"></span>Searches</span>
            </div>
        </div>
        <?php if ( $daily_activity ):
            $day_map   = [];
            foreach ($daily_activity as $d) { $day_map[$d['log_date']] = $d; }
            $srch_max7 = max(1, max(array_map(function($d){ return (int)$d['searches']; }, $daily_activity)));
            if ($has_range) {
                // Show all days in range
                $range_days = [];
                $ts = strtotime($fcc_from);
                $te = strtotime($fcc_to);
                for ($ts2 = $ts; $ts2 <= $te; $ts2 += 86400) { $range_days[] = date('Y-m-d', $ts2); }
                foreach ($range_days as $dt):
                    $calcs = isset($day_map[$dt]) ? (int)$day_map[$dt]['calcs']   : 0;
                    $srch  = isset($day_map[$dt]) ? (int)$day_map[$dt]['searches'] : 0;
                    $cpct  = $daily_max > 0 ? round(($calcs / $daily_max) * 100) : 0;
                    $spct  = $srch_max7 > 0 ? round(($srch  / $srch_max7) * 100) : 0;
            ?>
            <div class="fcc-day-row">
                <div class="fcc-day-lbl"><?php echo date('D j M', strtotime($dt)); ?></div>
                <div class="fcc-day-stack">
                    <div class="fcc-day-track"><div class="fcc-day-calc" style="width:<?php echo $cpct; ?>%"></div></div>
                    <div class="fcc-day-track"><div class="fcc-day-srch" style="width:<?php echo $spct; ?>%"></div></div>
                </div>
                <div class="fcc-day-vals"><?php echo $calcs ? '<b>' . $calcs . '</b>' : '—'; ?> / <?php echo $srch ?: '—'; ?></div>
            </div>
            <?php endforeach;
            } else {
                for ($i = 6; $i >= 0; $i--):
                    $dt    = date('Y-m-d', strtotime("-$i days"));
                    $calcs = isset($day_map[$dt]) ? (int)$day_map[$dt]['calcs']   : 0;
                    $srch  = isset($day_map[$dt]) ? (int)$day_map[$dt]['searches'] : 0;
                    $cpct  = $daily_max > 0 ? round(($calcs / $daily_max)   * 100) : 0;
                    $spct  = $srch_max7 > 0 ? round(($srch  / $srch_max7)   * 100) : 0;
            ?>
            <div class="fcc-day-row">
                <div class="fcc-day-lbl"><?php echo date('D j M', strtotime($dt)); ?></div>
                <div class="fcc-day-stack">
                    <div class="fcc-day-track"><div class="fcc-day-calc" style="width:<?php echo $cpct; ?>%"></div></div>
                    <div class="fcc-day-track"><div class="fcc-day-srch" style="width:<?php echo $spct; ?>%"></div></div>
                </div>
                <div class="fcc-day-vals"><?php echo $calcs ? '<b>' . $calcs . '</b>' : '—'; ?> / <?php echo $srch ?: '—'; ?></div>
            </div>
            <?php endfor;
            } ?>
        <?php else: ?>
        <p style="color:#94a3b8;font-size:13px">Daily data accumulates automatically. Check back after your first visitor session.</p>
        <?php endif; ?>
    </div>

    <!-- 14-Day Activity panel -->
    <?php if (!$has_range): ?>
    <div class="fcc-panel">
        <div class="fcc-panel-hdr">
            <h3>📅 14-Day Activity</h3>
            <div style="display:flex;gap:10px;font-size:11px;font-weight:600">
                <span style="display:flex;align-items:center;gap:4px"><span style="width:10px;height:10px;border-radius:2px;background:#ff914d;display:inline-block"></span>Calcs</span>
                <span style="display:flex;align-items:center;gap:4px"><span style="width:10px;height:10px;border-radius:2px;background:#cbd5e1;display:inline-block"></span>Searches</span>
            </div>
        </div>
        <?php if ($trend14):
            $map14      = [];
            foreach ($trend14 as $d) { $map14[$d['log_date']] = $d; }
            $srch_max14 = max(1, max(array_map(function($d){ return (int)$d['searches']; }, $trend14)));
            for ($i = 13; $i >= 0; $i--):
                $dt    = date('Y-m-d', strtotime("-$i days"));
                $calcs = isset($map14[$dt]) ? (int)$map14[$dt]['calcs']   : 0;
                $srch  = isset($map14[$dt]) ? (int)$map14[$dt]['searches'] : 0;
                $cpct  = $trend14_max  > 0 ? round(($calcs / $trend14_max)  * 100) : 0;
                $spct  = $srch_max14   > 0 ? round(($srch  / $srch_max14)   * 100) : 0;
        ?>
        <div class="fcc-day-row" style="min-height:28px">
            <div class="fcc-day-lbl" style="font-size:11px;width:58px"><?php echo date('D j M', strtotime($dt)); ?></div>
            <div class="fcc-day-stack">
                <div class="fcc-day-track" style="height:6px"><div class="fcc-day-calc" style="width:<?php echo $cpct; ?>%"></div></div>
                <div class="fcc-day-track" style="height:6px"><div class="fcc-day-srch" style="width:<?php echo $spct; ?>%"></div></div>
            </div>
            <div class="fcc-day-vals" style="font-size:11px;min-width:46px"><?php echo $calcs ? '<b>'.$calcs.'</b>' : '—'; ?> / <?php echo $srch ?: '—'; ?></div>
        </div>
        <?php endfor; else: ?>
        <p style="color:#94a3b8;font-size:13px">Not enough data yet for a 14-day view.</p>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- 30-Day Activity panel -->
    <?php if (!$has_range): ?>
    <div class="fcc-panel">
        <div class="fcc-panel-hdr">
            <h3>📆 30-Day Activity</h3>
            <div style="display:flex;gap:10px;font-size:11px;font-weight:600">
                <span style="display:flex;align-items:center;gap:4px"><span style="width:10px;height:10px;border-radius:2px;background:#ff914d;display:inline-block"></span>Calcs</span>
                <span style="display:flex;align-items:center;gap:4px"><span style="width:10px;height:10px;border-radius:2px;background:#cbd5e1;display:inline-block"></span>Searches</span>
            </div>
        </div>
        <?php if ($trend30):
            $map30     = [];
            foreach ($trend30 as $d) { $map30[$d['log_date']] = $d; }
            $srch_max30 = max(1, max(array_map(function($d){ return (int)$d['searches']; }, $trend30)));
            for ($i = 29; $i >= 0; $i--):
                $dt    = date('Y-m-d', strtotime("-$i days"));
                $calcs = isset($map30[$dt]) ? (int)$map30[$dt]['calcs']   : 0;
                $srch  = isset($map30[$dt]) ? (int)$map30[$dt]['searches'] : 0;
                $cpct  = $trend30_max  > 0 ? round(($calcs / $trend30_max)  * 100) : 0;
                $spct  = $srch_max30   > 0 ? round(($srch  / $srch_max30)   * 100) : 0;
        ?>
        <div class="fcc-day-row" style="min-height:26px">
            <div class="fcc-day-lbl" style="font-size:10px;width:56px"><?php echo date('D j M', strtotime($dt)); ?></div>
            <div class="fcc-day-stack">
                <div class="fcc-day-track" style="height:5px"><div class="fcc-day-calc" style="width:<?php echo $cpct; ?>%"></div></div>
                <div class="fcc-day-track" style="height:5px"><div class="fcc-day-srch" style="width:<?php echo $spct; ?>%"></div></div>
            </div>
            <div class="fcc-day-vals" style="font-size:10px;min-width:46px"><?php echo $calcs ? '<b>'.$calcs.'</b>' : '—'; ?> / <?php echo $srch ?: '—'; ?></div>
        </div>
        <?php endfor; else: ?>
        <p style="color:#94a3b8;font-size:13px">Not enough data yet for a 30-day view.</p>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="fcc-panel">
        <div class="fcc-panel-hdr"><h3>🔦 Discovery Gap</h3><span style="font-size:11px;color:#94a3b8">searched, never calc'd</span></div>
        <?php if ( $discovery_gap ): ?>
        <?php foreach ($discovery_gap as $g): ?>
        <div class="fcc-gap-item">
            <div>
                <div class="fcc-gap-name"><?php echo esc_html($g['food_name']); ?></div>
                <div class="fcc-gap-hint"><?php echo number_format($g['searches']); ?> searches · 0 calculations</div>
            </div>
            <span style="font-size:11px;background:#fee2e2;color:#dc2626;padding:3px 8px;border-radius:10px;font-weight:700">No calc</span>
        </div>
        <?php endforeach; ?>
        <p style="margin:10px 0 0;font-size:11px;color:#94a3b8">These fish attract attention but don't convert. Consider featured promotions or recipe ideas.</p>
        <?php else: ?>
        <p style="color:#16a34a;font-size:13px;font-weight:600">✅ Every searched fish has been calculated — great conversion!</p>
        <?php endif; ?>
    </div>
    <?php endif; // end has_range else ?>
</div>

<!-- Discovery Gap (always shown below when not in date range mode) -->
<?php if (!$has_range): ?>
<div class="fcc-panel" style="margin-bottom:24px">
    <div class="fcc-panel-hdr"><h3>🔦 Discovery Gap</h3><span style="font-size:11px;color:#94a3b8">searched, never calc'd</span></div>
    <?php if ( $discovery_gap ): ?>
    <?php foreach ($discovery_gap as $g): ?>
    <div class="fcc-gap-item">
        <div>
            <div class="fcc-gap-name"><?php echo esc_html($g['food_name']); ?></div>
            <div class="fcc-gap-hint"><?php echo number_format($g['searches']); ?> searches · 0 calculations</div>
        </div>
        <span style="font-size:11px;background:#fee2e2;color:#dc2626;padding:3px 8px;border-radius:10px;font-weight:700">No calc</span>
    </div>
    <?php endforeach; ?>
    <p style="margin:10px 0 0;font-size:11px;color:#94a3b8">These fish attract attention but don't convert. Consider featured promotions or recipe ideas.</p>
    <?php else: ?>
    <p style="color:#16a34a;font-size:13px;font-weight:600">✅ Every searched fish has been calculated — great conversion!</p>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Full detail table -->
<div class="fcc-tbl-wrap">
<div style="display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-bottom:1px solid #e2e8f0;background:#f8fafc">
    <strong style="color:#1e293b;font-size:13.5px">All Tracked Fish</strong>
    <div style="display:flex;gap:4px;flex-wrap:wrap;align-items:center">
        <span style="font-size:11.5px;color:#94a3b8;margin-right:4px">Sort:</span>
        <?php foreach(['calcs'=>'Calculations','searches'=>'Searches','total'=>'Total Interest','eng'=>'Engagement'] as $k=>$l): ?>
        <button onclick="fccSortTable('<?php echo $k; ?>')" id="fcc-sort-<?php echo $k; ?>"
           style="padding:3px 10px;border-radius:999px;font-size:11.5px;font-weight:600;cursor:pointer;border:none;
                  <?php echo $sort===$k ? 'background:#ff914d;color:#fff' : 'background:#f1f5f9;color:#64748b'; ?>">
            <?php echo $l; ?></button>
        <?php endforeach; ?>
    </div>
</div>
<table class="fcc-detail-tbl">
    <thead>
        <tr>
            <th style="width:36px">Rank</th>
            <th>Fish Name</th>
            <th>Category</th>
            <th>Searches</th>
            <th>Calculations</th>
            <th>Engagement</th>
            <th>Interest</th>
        </tr>
    </thead>
    <tbody id="fcc-detail-tbody">
    <?php foreach ( $all_items as $i => $r ):
        $interest  = $r['searches'] + $r['calcs'];
        $int_pct   = $max_interest > 0 ? round(($interest / $max_interest) * 100) : 0;
        $eng_rate  = (int)$r['searches'] > 0 ? round( ((int)$r['calcs'] / (int)$r['searches']) * 100 ) : ( (int)$r['calcs'] > 0 ? 100 : 0 );
        $eng_cls   = $eng_rate >= 70 ? 'fcc-eng-high' : ( $eng_rate >= 30 ? 'fcc-eng-mid' : 'fcc-eng-low' );
        $rank_cls  = $i === 0 ? 'fcc-rank-1' : ($i === 1 ? 'fcc-rank-2' : ($i === 2 ? 'fcc-rank-3' : 'fcc-rank-n'));
        $color     = $cat_colours[ $r['category'] ] ?? '#94a3b8';
    ?>
    <tr data-calcs="<?php echo (int)$r['calcs']; ?>" data-searches="<?php echo (int)$r['searches']; ?>" data-total="<?php echo $interest; ?>" data-eng="<?php echo $eng_rate; ?>">
        <td><span class="<?php echo $rank_cls; ?>"><?php echo $i + 1; ?></span></td>
        <td><strong><?php echo esc_html($r['food_name']); ?></strong></td>
        <td><span style="display:inline-flex;align-items:center;gap:5px"><span style="width:9px;height:9px;border-radius:50%;background:<?php echo $color; ?>;display:inline-block"></span><?php echo esc_html($r['category']); ?></span></td>
        <td><?php echo number_format($r['searches']); ?></td>
        <td style="font-weight:700;color:#ff914d"><?php echo number_format($r['calcs']); ?></td>
        <td><span class="fcc-eng-badge <?php echo $eng_cls; ?>"><?php echo $eng_rate; ?>%</span></td>
        <td>
            <?php echo number_format($interest); ?>
            <span class="fcc-interest-bar" style="width:<?php echo $int_pct; ?>px;max-width:80px"></span>
        </td>
    </tr>
    <?php endforeach; ?>
    <?php if ( !$all_items ): ?>
    <tr><td colspan="7" class="fcc-empty-analytics">No data yet.</td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div>

<?php endif; // has data ?>
</div>

<script>
(function(){
    var currentSort = '<?php echo esc_js($sort); ?>';
    function rankClass(i){ return i===0?'fcc-rank-1':i===1?'fcc-rank-2':i===2?'fcc-rank-3':'fcc-rank-n'; }
    window.fccSortTable = function(key) {
        var tbody = document.getElementById('fcc-detail-tbody');
        if (!tbody) return;
        var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr[data-calcs]'));
        rows.sort(function(a,b){
            return parseFloat(b.getAttribute('data-'+key)||0) - parseFloat(a.getAttribute('data-'+key)||0);
        });
        rows.forEach(function(row, i){
            var span = row.querySelector('td:first-child span');
            if (span){
                span.className = rankClass(i);
                span.textContent = i+1;
            }
            tbody.appendChild(row);
        });
        // update button styles
        document.querySelectorAll('[id^="fcc-sort-"]').forEach(function(btn){
            var k = btn.id.replace('fcc-sort-','');
            btn.style.background = k===key ? '#ff914d' : '#f1f5f9';
            btn.style.color      = k===key ? '#fff'    : '#64748b';
        });
        currentSort = key;
    };
})();
</script>
        <?php
    }

    // ── Admin: Food Requests Page ─────────────────────────────────────────────
    public function adminRequestsPage() {
        if ( ! current_user_can('manage_options') ) wp_die('Forbidden');
        global $wpdb;

        $req_table  = $wpdb->prefix . 'fcc_food_requests';
        $miss_table = $wpdb->prefix . 'fcc_missing_searches';
        $has_req    = $wpdb->get_var( "SHOW TABLES LIKE '$req_table'"  ) === $req_table;
        $has_miss   = $wpdb->get_var( "SHOW TABLES LIKE '$miss_table'" ) === $miss_table;

        $req_filter  = sanitize_text_field( $_GET['req_status']  ?? 'pending' );
        $miss_filter = sanitize_text_field( $_GET['miss_status'] ?? 'active'  );
        if ( ! in_array( $req_filter,  [ 'all','pending','added','dismissed' ], true ) ) $req_filter  = 'pending';
        if ( ! in_array( $miss_filter, [ 'all','active', 'added','dismissed' ], true ) ) $miss_filter = 'active';

        // Sort
        $req_sort  = sanitize_text_field( $_GET['req_sort']  ?? 'count_desc' );
        $miss_sort = sanitize_text_field( $_GET['miss_sort'] ?? 'count_desc' );
        if ( ! in_array( $req_sort,  ['count_desc','date_desc','date_asc'], true ) ) $req_sort  = 'count_desc';
        if ( ! in_array( $miss_sort, ['count_desc','date_desc','date_asc'], true ) ) $miss_sort = 'count_desc';

        // Period filter
        $req_period  = sanitize_text_field( $_GET['req_period']  ?? 'all' );
        $miss_period = sanitize_text_field( $_GET['miss_period'] ?? 'all' );
        if ( ! in_array( $req_period,  ['all','7d','30d','custom'], true ) ) $req_period  = 'all';
        if ( ! in_array( $miss_period, ['all','7d','30d','custom'], true ) ) $miss_period = 'all';

        // Custom date ranges
        $req_from  = sanitize_text_field( $_GET['req_from']  ?? '' );
        $req_to    = sanitize_text_field( $_GET['req_to']    ?? '' );
        $miss_from = sanitize_text_field( $_GET['miss_from'] ?? '' );
        $miss_to   = sanitize_text_field( $_GET['miss_to']   ?? '' );
        foreach ( ['req_from','req_to','miss_from','miss_to'] as $dv ) {
            if ( $$dv && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $$dv) ) $$dv = '';
        }

        // ORDER BY map
        $sort_map_req  = [
            'count_desc' => 'count DESC, last_requested DESC',
            'date_desc'  => 'last_requested DESC, count DESC',
            'date_asc'   => 'last_requested ASC, count DESC',
        ];
        $sort_map_miss = [
            'count_desc' => 'count DESC, last_searched DESC',
            'date_desc'  => 'last_searched DESC, count DESC',
            'date_asc'   => 'last_searched ASC, count DESC',
        ];

        // Date WHERE builder
        $date_where = function( $period, $from, $to, $col ) use ($wpdb) {
            if ( $period === '7d'  ) return " AND DATE($col) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
            if ( $period === '30d' ) return " AND DATE($col) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
            if ( $period === 'custom' && $from && $to )
                return $wpdb->prepare( " AND DATE($col) BETWEEN %s AND %s", $from, $to );
            return '';
        };

        $req_counts  = [ 'all'=>0, 'pending'=>0, 'added'=>0, 'dismissed'=>0 ];
        $miss_counts = [ 'all'=>0, 'active'=>0,  'added'=>0, 'dismissed'=>0 ];
        $miss_hot    = 0;
        if ( $has_req ) {
            foreach ( ['pending','added','dismissed'] as $s ) {
                $n = (int)$wpdb->get_var( $wpdb->prepare("SELECT COUNT(*) FROM $req_table WHERE status=%s", $s) );
                $req_counts[$s] = $n; $req_counts['all'] += $n;
            }
        }
        if ( $has_miss ) {
            foreach ( ['active','added','dismissed'] as $s ) {
                $n = (int)$wpdb->get_var( $wpdb->prepare("SELECT COUNT(*) FROM $miss_table WHERE status=%s", $s) );
                $miss_counts[$s] = $n; $miss_counts['all'] += $n;
            }
            $miss_hot = (int)$wpdb->get_var("SELECT COUNT(*) FROM $miss_table WHERE count >= 5 AND status='active'");
        }

        $per_page  = 25;
        $req_page  = max( 1, intval( $_GET['req_page']  ?? 1 ) );
        $miss_page = max( 1, intval( $_GET['miss_page'] ?? 1 ) );

        $requests      = [];
        $missed        = [];
        $req_total     = 0;
        $miss_total    = 0;
        if ( $has_req ) {
            $req_where  = 'WHERE 1=1';
            if ( $req_filter !== 'all' ) $req_where .= $wpdb->prepare( ' AND status=%s', $req_filter );
            $req_where  .= $date_where( $req_period, $req_from, $req_to, 'last_requested' );
            $req_total  = (int)$wpdb->get_var("SELECT COUNT(*) FROM $req_table $req_where");
            $req_page   = min( $req_page, max( 1, (int)ceil($req_total / $per_page) ) );
            $offset     = ($req_page - 1) * $per_page;
            $req_order  = $sort_map_req[ $req_sort ];
            $requests   = $wpdb->get_results("SELECT * FROM $req_table $req_where ORDER BY $req_order LIMIT $per_page OFFSET $offset") ?: [];
        }
        if ( $has_miss ) {
            $miss_where  = 'WHERE 1=1';
            if ( $miss_filter !== 'all' ) $miss_where .= $wpdb->prepare( ' AND status=%s', $miss_filter );
            $miss_where  .= $date_where( $miss_period, $miss_from, $miss_to, 'last_searched' );
            $miss_total = (int)$wpdb->get_var("SELECT COUNT(*) FROM $miss_table $miss_where");
            $miss_page  = min( $miss_page, max( 1, (int)ceil($miss_total / $per_page) ) );
            $offset     = ($miss_page - 1) * $per_page;
            $miss_order = $sort_map_miss[ $miss_sort ];
            $missed     = $wpdb->get_results("SELECT * FROM $miss_table $miss_where ORDER BY $miss_order LIMIT $per_page OFFSET $offset") ?: [];
        }

        $action_nonce = wp_create_nonce('fcc_nonce');
        $ajax_url     = admin_url('admin-ajax.php');
        $base         = admin_url('admin.php?page=fcc-requests');

        // Builds a full admin URL carrying all current filter state, with selective overrides.
        $build_url = function( array $overrides ) use ( $base, $req_filter, $miss_filter, $req_sort, $miss_sort, $req_period, $miss_period, $req_from, $req_to, $miss_from, $miss_to, $req_page, $miss_page ) {
            $params = array_merge( [
                'req_status'  => $req_filter,
                'miss_status' => $miss_filter,
                'req_sort'    => $req_sort,
                'miss_sort'   => $miss_sort,
                'req_period'  => $req_period,
                'miss_period' => $miss_period,
                'req_from'    => $req_from,
                'req_to'      => $req_to,
                'miss_from'   => $miss_from,
                'miss_to'     => $miss_to,
                'req_page'    => $req_page,
                'miss_page'   => $miss_page,
            ], $overrides );
            $qs = [];
            foreach ( $params as $k => $v ) {
                if ( $v !== '' ) $qs[] = $k . '=' . rawurlencode( (string) $v );
            }
            return $base . ( $qs ? '&' . implode( '&', $qs ) : '' );
        };
        ?>
        <style>
        .fcc-req-wrap{max-width:1100px}
        .fcc-req-header{background:linear-gradient(135deg,#1e293b 0%,#0f172a 100%);border-radius:16px;padding:24px 28px;margin-bottom:22px;display:flex;align-items:center;justify-content:space-between;gap:20px;flex-wrap:wrap;box-shadow:0 6px 24px rgba(15,23,42,.28);border:none}
        .fcc-req-header-left{display:flex;align-items:center;gap:16px}
        .fcc-req-header-icon{width:54px;height:54px;background:linear-gradient(135deg,#ff914d,#e07a3d);border-radius:14px;display:flex;align-items:center;justify-content:center;box-shadow:0 4px 16px rgba(255,145,77,.4);flex-shrink:0}
        .fcc-req-header-icon .dashicons{color:#fff;font-size:27px;width:27px;height:27px}
        .fcc-req-title{font-size:21px;font-weight:800;color:#fff;margin:0;line-height:1.25}
        .fcc-req-subtitle{margin:5px 0 0;color:#94a3b8;font-size:12.5px;line-height:1.55}
        .fcc-req-header-badges{display:flex;flex-direction:column;align-items:flex-end;gap:8px}
        .fcc-req-header-tip{display:flex;align-items:flex-start;gap:9px;background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.12);border-radius:10px;padding:10px 14px;max-width:290px;font-size:12px;color:#cbd5e1;line-height:1.5}
        .fcc-req-header-tip strong{color:#fff}
        .fcc-req-header-tip em{color:#fbbf24}
        .fcc-req-header-tip .dashicons{color:#ff914d;font-size:15px;width:15px;height:15px;margin-top:2px;flex-shrink:0}
        /* Stat cards */
        .fcc-req-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:22px}
        .fcc-req-stat{background:#fff;border-radius:14px;padding:18px 20px;border:1.5px solid #f1f5f9;box-shadow:0 2px 8px rgba(15,23,42,.05);display:flex;align-items:center;gap:14px;transition:box-shadow .15s}
        .fcc-req-stat:hover{box-shadow:0 4px 16px rgba(15,23,42,.09)}
        .fcc-req-stat-icon{width:46px;height:46px;border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
        .fcc-req-stat-icon .dashicons{font-size:22px;width:22px;height:22px}
        .fcc-req-stat-val{font-size:28px;font-weight:800;line-height:1;color:#1e293b}
        .fcc-req-stat-lbl{font-size:11.5px;color:#94a3b8;font-weight:500;margin-top:3px}
        /* Section */
        .fcc-req-section{background:#fff;border-radius:16px;border:1.5px solid #f1f5f9;box-shadow:0 2px 10px rgba(15,23,42,.06);overflow:hidden;margin-bottom:22px}
        .fcc-req-section-head{padding:18px 22px 15px;border-bottom:1.5px solid #f8fafc;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px}
        .fcc-req-section-title{font-size:14px;font-weight:700;color:#1e293b;display:flex;align-items:center;gap:7px;margin:0;line-height:1.4}
        .fcc-req-section-title .dashicons{font-size:16px;width:16px;height:16px;color:#ff914d}
        .fcc-req-section-sub{font-size:11.5px;color:#b0bad0;margin:3px 0 0}
        /* Tabs */
        .fcc-req-tabs{display:flex;gap:5px;flex-wrap:wrap}
        .fcc-req-tab{padding:5px 13px;border-radius:999px;font-size:11.5px;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:5px;transition:all .15s;border:1.5px solid transparent}
        .fcc-req-tab-on{background:#ff914d;color:#fff;border-color:#ff914d}
        .fcc-req-tab-off{background:#f8fafc;color:#64748b;border-color:#f1f5f9}
        .fcc-req-tab-off:hover{background:#f1f5f9;border-color:#e2e8f0;color:#334155;text-decoration:none}
        .fcc-req-tab-cnt{border-radius:999px;padding:1px 6px;font-size:10px;font-weight:700;min-width:16px;text-align:center}
        .fcc-req-tab-on .fcc-req-tab-cnt{background:rgba(255,255,255,.3);color:#fff}
        .fcc-req-tab-off .fcc-req-tab-cnt{background:#e2e8f0;color:#64748b}
        /* Table */
        .fcc-req-tbl{width:100%;border-collapse:collapse;font-size:13px}
        .fcc-req-tbl thead tr{background:linear-gradient(135deg,#f8fafc 0%,#f1f5f9 100%)}
        .fcc-req-tbl thead td,.fcc-req-tbl th{padding:11px 16px;text-align:left;color:#64748b;font-weight:700;font-size:10.5px;text-transform:uppercase;letter-spacing:.5px;border-bottom:1.5px solid #e8edf4;white-space:nowrap}
        .fcc-req-tbl th.ctr{text-align:center}
        .fcc-req-tbl tbody tr{border-bottom:1px solid #f4f6f9;transition:background .1s}
        .fcc-req-tbl tbody tr:last-child{border-bottom:none}
        .fcc-req-tbl tbody tr:hover{background:#fafbfe}
        .fcc-req-tbl td{padding:13px 16px;vertical-align:middle}
        .fcc-req-tbl td.ctr{text-align:center}
        /* Rank */
        .fcc-req-rank{display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:7px;background:#f1f5f9;color:#94a3b8;font-size:11px;font-weight:700}
        /* Name */
        .fcc-req-name-cell .food-name{font-size:13.5px;font-weight:700;color:#0f172a}
        .fcc-req-name-cell .food-note-wrap{margin-top:3px;max-width:240px}
        .fcc-req-name-cell .food-note{font-size:11.5px;color:#94a3b8;line-height:1.45}
        .fcc-req-name-cell .food-note.food-note-clamp{display:-webkit-box;-webkit-line-clamp:1;-webkit-box-orient:vertical;overflow:hidden}
        .fcc-req-name-cell .food-note.food-note-clamp.expanded{display:block;overflow:visible;-webkit-line-clamp:unset;white-space:normal}
        .food-note-toggle{font-size:11px;color:#ff914d;cursor:pointer;border:none;background:none;padding:0;margin-top:2px;font-weight:600;font-family:inherit;display:block}
        .food-note-toggle:hover{color:#e07a3d;text-decoration:underline}
        /* Count badge */
        .fcc-req-cnt{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;padding:3px 11px;font-size:12px;font-weight:800;min-width:34px}
        .fcc-req-cnt-hot{background:#fef2f2;color:#dc2626}
        .fcc-req-cnt-warm{background:#fff7ed;color:#ea580c}
        .fcc-req-cnt-cold{background:#f1f5f9;color:#475569}
        /* Hot tag */
        .fcc-req-hot-tag{display:inline-flex;align-items:center;gap:3px;font-size:10px;font-weight:700;color:#dc2626;background:#fef2f2;border-radius:5px;padding:2px 7px;margin-left:7px;vertical-align:middle}
        .fcc-req-hot-tag .dashicons{font-size:11px;width:11px;height:11px}
        /* Status pill */
        .fcc-req-pill{display:inline-flex;align-items:center;gap:5px;border-radius:999px;padding:4px 11px;font-size:11.5px;font-weight:600}
        .fcc-req-pill::before{content:'';width:6px;height:6px;border-radius:50%;background:currentColor;display:inline-block;flex-shrink:0}
        .fcc-req-pill.pending,.fcc-req-pill.active{background:#fff3ec;color:#c2410c}
        .fcc-req-pill.added{background:#dcfce7;color:#15803d}
        .fcc-req-pill.dismissed{background:#f1f5f9;color:#94a3b8}
        /* Actions */
        .fcc-req-acts{display:flex;gap:5px;align-items:center;flex-wrap:wrap}
        .fcc-req-btn{display:inline-flex;align-items:center;gap:4px;padding:5px 12px;border-radius:8px;font-size:12px;font-weight:600;cursor:pointer;border:1.5px solid transparent;font-family:inherit;transition:all .15s;white-space:nowrap;text-decoration:none;line-height:1.3}
        .fcc-req-btn:hover{filter:brightness(.93);text-decoration:none}
        .fcc-req-btn:disabled{opacity:.45;cursor:not-allowed}
        .fcc-req-btn .dashicons{font-size:13px;width:13px;height:13px;margin-top:0}
        .fcc-req-btn-add{background:#eff6ff;color:#2563eb;border-color:#dbeafe}
        .fcc-req-btn-add:hover{background:#dbeafe;color:#1d4ed8}
        .fcc-req-btn-done{background:#f0fdf4;color:#15803d;border-color:#bbf7d0}
        .fcc-req-btn-done:hover{background:#dcfce7}
        .fcc-req-btn-dismiss{background:#f8fafc;color:#64748b;border-color:#e2e8f0}
        .fcc-req-btn-dismiss:hover{background:#f1f5f9;color:#475569}
        .fcc-req-btn-restore{background:#fff7ed;color:#c2410c;border-color:#fed7aa}
        .fcc-req-btn-restore:hover{background:#ffedd5}
        /* Empty */
        .fcc-req-empty{padding:52px 24px;text-align:center}
        .fcc-req-empty .dashicons{font-size:42px;width:42px;height:42px;color:#e2e8f0;margin-bottom:12px}
        .fcc-req-empty-msg{font-size:13px;color:#94a3b8;max-width:380px;margin:0 auto;line-height:1.6}
        /* Pagination */
        .fcc-req-pager{display:flex;align-items:center;justify-content:space-between;padding:12px 18px;border-top:1.5px solid #f4f6f9;flex-wrap:wrap;gap:8px}
        .fcc-req-pager-info{font-size:12.5px;color:#94a3b8}
        .fcc-req-pager-info strong{color:#475569}
        .fcc-req-pager-btns{display:flex;align-items:center;gap:6px}
        .fcc-req-pager-btn{display:inline-flex;align-items:center;gap:4px;padding:5px 13px;border-radius:8px;font-size:12px;font-weight:600;text-decoration:none;border:1.5px solid #e2e8f0;background:#fff;color:#475569;transition:all .15s}
        .fcc-req-pager-btn:hover{background:#f8fafc;border-color:#cbd5e1;text-decoration:none;color:#1e293b}
        .fcc-req-pager-btn.disabled{opacity:.4;pointer-events:none;cursor:default}
        .fcc-req-pager-pages{display:flex;gap:4px}
        .fcc-req-pager-page{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:7px;font-size:12px;font-weight:600;text-decoration:none;border:1.5px solid #e2e8f0;background:#fff;color:#475569;transition:all .15s}
        .fcc-req-pager-page:hover{background:#f1f5f9;text-decoration:none;color:#1e293b}
        .fcc-req-pager-page.current{background:#ff914d;border-color:#ff914d;color:#fff}
        .fcc-req-pager-ellipsis{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;font-size:12px;color:#94a3b8}
        @media(max-width:720px){.fcc-req-stats{grid-template-columns:1fr 1fr}}
        @media(max-width:480px){.fcc-req-stats{grid-template-columns:1fr}.fcc-req-section-head{flex-direction:column;align-items:flex-start}}
        </style>

        <div class="wrap fcc-req-wrap">

        <!-- Page Header -->
        <div class="fcc-req-header">
            <div class="fcc-req-header-left">
                <div class="fcc-req-header-icon">
                    <span class="dashicons dashicons-lightbulb"></span>
                </div>
                <div>
                    <h1 class="fcc-req-title">Food Requests</h1>
                    <p class="fcc-req-subtitle">User-submitted requests &amp; auto-logged missed searches<br>Use these insights to decide what fish to add next.</p>
                </div>
            </div>
            <div class="fcc-req-header-badges">
                <div class="fcc-req-header-tip">
                    <span class="dashicons dashicons-info-outline"></span>
                    <span><strong>Tip:</strong> Items searched 5+ times are flagged <em>Hot</em> — these are your highest-priority additions.</span>
                </div>
            </div>
        </div>

        <?php if ( ! $has_req && ! $has_miss ): ?>
        <div style="background:#fff3ec;border:1.5px solid #ffcba0;border-radius:14px;padding:18px 22px;color:#92400e;font-size:13.5px">
            <strong>Setting up&hellip;</strong> Tables are being created. Refresh this page in a moment.
        </div>
        <?php else: ?>

        <!-- Stat Cards -->
        <div class="fcc-req-stats">
            <div class="fcc-req-stat">
                <div class="fcc-req-stat-icon" style="background:#fff3ec">
                    <span class="dashicons dashicons-admin-users" style="color:#ff914d"></span>
                </div>
                <div>
                    <div class="fcc-req-stat-val"><?php echo $req_counts['pending']; ?></div>
                    <div class="fcc-req-stat-lbl">Pending User Requests</div>
                </div>
            </div>
            <div class="fcc-req-stat">
                <div class="fcc-req-stat-icon" style="background:#eff6ff">
                    <span class="dashicons dashicons-search" style="color:#3b82f6"></span>
                </div>
                <div>
                    <div class="fcc-req-stat-val"><?php echo $miss_counts['active']; ?></div>
                    <div class="fcc-req-stat-lbl">Active Missed Searches</div>
                </div>
            </div>
            <div class="fcc-req-stat">
                <div class="fcc-req-stat-icon" style="background:#fef2f2">
                    <span class="dashicons dashicons-warning" style="color:#dc2626"></span>
                </div>
                <div>
                    <div class="fcc-req-stat-val"><?php echo $miss_hot; ?></div>
                    <div class="fcc-req-stat-lbl">High Priority (5+ searches)</div>
                </div>
            </div>
        </div>

        <!-- ═══ User Requests Section ═══════════════════════════════════════════ -->
        <div class="fcc-req-section">
            <div class="fcc-req-section-head">
                <div>
                    <h2 class="fcc-req-section-title">
                        <span class="dashicons dashicons-admin-users"></span>
                        User Requests
                    </h2>
                    <p class="fcc-req-section-sub">Submitted by visitors when they can&rsquo;t find a fish in the calculator</p>
                </div>
                <div class="fcc-req-tabs">
                    <?php foreach (['all'=>'All','pending'=>'Pending','added'=>'Added','dismissed'=>'Dismissed'] as $k=>$l):
                        $url   = $build_url(['req_status'=>$k,'req_page'=>1]);
                        $on    = $req_filter === $k;
                        $cnt   = $k === 'all' ? $req_counts['all'] : $req_counts[$k];
                    ?>
                    <a href="<?php echo esc_url($url); ?>" class="fcc-req-tab <?php echo $on ? 'fcc-req-tab-on' : 'fcc-req-tab-off'; ?>" data-section="fcc-req-body">
                        <?php echo $l; ?>
                        <?php if ($cnt): ?><span class="fcc-req-tab-cnt"><?php echo $cnt; ?></span><?php endif; ?>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Sort + Period toolbar — OUTSIDE AJAX body so persists during section reloads -->
            <div class="fcc-filter-toolbar" id="fcc-req-toolbar">
                <div class="fcc-ftool-group">
                    <span class="fcc-ftool-label">Sort:</span>
                    <div class="fcc-ftool-pills">
                    <?php foreach (['count_desc'=>'Most Requested','date_desc'=>'Latest','date_asc'=>'Oldest'] as $sk=>$sl): ?>
                        <a href="<?php echo esc_url($build_url(['req_sort'=>$sk,'req_page'=>1])); ?>"
                           class="fcc-ftool-pill<?php echo $req_sort===$sk?' active':''; ?>"
                           data-section="fcc-req-body" data-param="req_sort" data-val="<?php echo esc_attr($sk); ?>">
                            <?php echo esc_html($sl); ?>
                        </a>
                    <?php endforeach; ?>
                    </div>
                </div>
                <div class="fcc-ftool-group">
                    <span class="fcc-ftool-label">Period:</span>
                    <div class="fcc-ftool-pills">
                    <?php foreach (['all'=>'All Time','7d'=>'Last 7 Days','30d'=>'Last 30 Days','custom'=>'Custom'] as $pk=>$pl): ?>
                        <a href="<?php echo esc_url($build_url(['req_period'=>$pk,'req_page'=>1,'req_from'=>'','req_to'=>''])); ?>"
                           class="fcc-ftool-pill<?php echo $req_period===$pk?' active':''; ?>"
                           data-section="fcc-req-body" data-param="req_period" data-val="<?php echo esc_attr($pk); ?>">
                            <?php echo esc_html($pl); ?>
                        </a>
                    <?php endforeach; ?>
                    </div>
                </div>
                <div class="fcc-ftool-custom-range<?php echo $req_period==='custom'?' visible':''; ?>" id="fcc-req-custom">
                    <input type="date" class="fcc-ftool-date" id="fcc-req-from" value="<?php echo esc_attr($req_from); ?>" max="<?php echo esc_attr(date('Y-m-d')); ?>">
                    <span style="color:#94a3b8;font-size:12px">to</span>
                    <input type="date" class="fcc-ftool-date" id="fcc-req-to"   value="<?php echo esc_attr($req_to); ?>"   max="<?php echo esc_attr(date('Y-m-d')); ?>">
                    <button type="button" class="fcc-ftool-apply"
                            data-section="fcc-req-body"
                            data-from="fcc-req-from" data-to="fcc-req-to"
                            data-base="<?php echo esc_attr($build_url(['req_period'=>'custom','req_page'=>1,'req_from'=>'__FROM__','req_to'=>'__TO__'])); ?>">
                        Apply
                    </button>
                </div>
            </div>

            <div id="fcc-req-body" class="fcc-section-body">
            <?php if ( !$requests ): ?>
            <div class="fcc-req-empty">
                <div><span class="dashicons dashicons-email-alt"></span></div>
                <p class="fcc-req-empty-msg">
                    <?php echo $req_filter === 'pending'
                        ? 'No pending requests yet. When visitors search for a fish that isn\'t in the database, they\'ll see an option to request it — it will show up here.'
                        : 'No items match this filter.'; ?>
                </p>
            </div>
            <?php else: ?>
            <div style="overflow-x:auto">
            <table class="fcc-req-tbl">
                <thead><tr>
                    <th style="width:42px">#</th>
                    <th>Food Name</th>
                    <th class="ctr">Requests</th>
                    <th>Last Requested</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr></thead>
                <tbody>
                <?php foreach ($requests as $i => $r): ?>
                <tr>
                    <td><span class="fcc-req-rank"><?php echo $i + 1; ?></span></td>
                    <td class="fcc-req-name-cell">
                        <div class="food-name"><?php echo esc_html($r->food_name); ?></div>
                        <?php if ($r->note): ?>
                        <div class="food-note-wrap">
                            <div class="food-note food-note-clamp">&#8220;<?php echo esc_html($r->note); ?>&#8221;</div>
                            <?php if ( mb_strlen($r->note) > 55 ): ?>
                            <button type="button" class="food-note-toggle" onclick="fccToggleNote(this)">Show more &#8595;</button>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td class="ctr">
                        <span class="fcc-req-cnt <?php echo (int)$r->count >= 5 ? 'fcc-req-cnt-hot' : ((int)$r->count >= 3 ? 'fcc-req-cnt-warm' : 'fcc-req-cnt-cold'); ?>">
                            <?php echo (int)$r->count; ?>×
                        </span>
                    </td>
                    <td style="color:#64748b;font-size:12.5px;white-space:nowrap"><?php echo esc_html(substr($r->last_requested,0,10)); ?></td>
                    <td><span class="fcc-req-pill <?php echo esc_attr($r->status); ?>"><?php echo ucfirst($r->status); ?></span></td>
                    <td>
                        <div class="fcc-req-acts">
                            <?php if ($r->status !== 'added'): ?>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=fcc-foods&action=add&prefill_name='.urlencode($r->food_name))); ?>" class="fcc-req-btn fcc-req-btn-add">
                                <span class="dashicons dashicons-plus-alt2"></span> Add to DB
                            </a>
                            <button onclick="fccUpdateStatus('request',<?php echo (int)$r->id; ?>,'added',this)" class="fcc-req-btn fcc-req-btn-done">
                                <span class="dashicons dashicons-yes-alt"></span> Mark Added
                            </button>
                            <?php endif; ?>
                            <?php if ($r->status !== 'dismissed'): ?>
                            <button onclick="fccUpdateStatus('request',<?php echo (int)$r->id; ?>,'dismissed',this)" class="fcc-req-btn fcc-req-btn-dismiss">
                                <span class="dashicons dashicons-no-alt"></span> Dismiss
                            </button>
                            <?php endif; ?>
                            <?php if ($r->status === 'dismissed' || $r->status === 'added'): ?>
                            <button onclick="fccUpdateStatus('request',<?php echo (int)$r->id; ?>,'pending',this)" class="fcc-req-btn fcc-req-btn-restore">
                                <span class="dashicons dashicons-undo"></span> Restore
                            </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php
            // User Requests pagination
            $req_pages = max(1, (int)ceil($req_total / $per_page));
            if ($req_pages > 1):
                $from = ($req_page - 1) * $per_page + 1;
                $to   = min($req_page * $per_page, $req_total);
            ?>
            <div class="fcc-req-pager">
                <span class="fcc-req-pager-info">Showing <strong><?php echo $from; ?>–<?php echo $to; ?></strong> of <strong><?php echo number_format($req_total); ?></strong> requests</span>
                <div class="fcc-req-pager-btns">
                    <a href="<?php echo esc_url($build_url(['req_page'=>$req_page-1])); ?>"
                       class="fcc-req-pager-btn <?php echo $req_page<=1?'disabled':''; ?>">
                        <span class="dashicons dashicons-arrow-left-alt2" style="font-size:13px;width:13px;height:13px"></span> Prev
                    </a>
                    <div class="fcc-req-pager-pages">
                    <?php
                    $start = max(1, $req_page-2); $end = min($req_pages, $req_page+2);
                    if ($start>1){ echo '<a href="'.esc_url($build_url(['req_page'=>1])).'" class="fcc-req-pager-page">1</a>'; if($start>2) echo '<span class="fcc-req-pager-ellipsis">…</span>'; }
                    for($p=$start;$p<=$end;$p++) echo '<a href="'.esc_url($build_url(['req_page'=>$p])).'" class="fcc-req-pager-page'.($p===$req_page?' current':'').'">'.$p.'</a>';
                    if ($end<$req_pages){ if($end<$req_pages-1) echo '<span class="fcc-req-pager-ellipsis">…</span>'; echo '<a href="'.esc_url($build_url(['req_page'=>$req_pages])).'" class="fcc-req-pager-page">'.$req_pages.'</a>'; }
                    ?>
                    </div>
                    <a href="<?php echo esc_url($build_url(['req_page'=>$req_page+1])); ?>"
                       class="fcc-req-pager-btn <?php echo $req_page>=$req_pages?'disabled':''; ?>">
                        Next <span class="dashicons dashicons-arrow-right-alt2" style="font-size:13px;width:13px;height:13px"></span>
                    </a>
                </div>
            </div>
            <?php endif; // pagination ?>
            <?php endif; // has rows ?>
            </div><!-- #fcc-req-body -->
        </div>

        <!-- ═══ Missed Searches Section ══════════════════════════════════════════ -->
        <div class="fcc-req-section">
            <div class="fcc-req-section-head">
                <div>
                    <h2 class="fcc-req-section-title">
                        <span class="dashicons dashicons-search"></span>
                        Missed Searches
                        <span style="font-size:10.5px;font-weight:500;color:#b0bad0;background:#f8fafc;border-radius:999px;padding:2px 9px;border:1px solid #e8edf5;margin-left:4px">Auto-logged</span>
                    </h2>
                    <p class="fcc-req-section-sub">Search queries that returned 0 results &mdash; sorted by frequency to highlight your highest-demand gaps</p>
                </div>
                <div class="fcc-req-tabs">
                    <?php foreach (['all'=>'All','active'=>'Active','added'=>'Added','dismissed'=>'Dismissed'] as $k=>$l):
                        $url  = $build_url(['miss_status'=>$k,'miss_page'=>1]);
                        $on   = $miss_filter === $k;
                        $cnt  = $k === 'all' ? $miss_counts['all'] : $miss_counts[$k];
                    ?>
                    <a href="<?php echo esc_url($url); ?>" class="fcc-req-tab <?php echo $on ? 'fcc-req-tab-on' : 'fcc-req-tab-off'; ?>" data-section="fcc-miss-body">
                        <?php echo $l; ?>
                        <?php if ($cnt): ?><span class="fcc-req-tab-cnt"><?php echo $cnt; ?></span><?php endif; ?>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Sort + Period toolbar — OUTSIDE AJAX body so persists during section reloads -->
            <div class="fcc-filter-toolbar" id="fcc-miss-toolbar">
                <div class="fcc-ftool-group">
                    <span class="fcc-ftool-label">Sort:</span>
                    <div class="fcc-ftool-pills">
                    <?php foreach (['count_desc'=>'Most Searched','date_desc'=>'Latest','date_asc'=>'Oldest'] as $sk=>$sl): ?>
                        <a href="<?php echo esc_url($build_url(['miss_sort'=>$sk,'miss_page'=>1])); ?>"
                           class="fcc-ftool-pill<?php echo $miss_sort===$sk?' active':''; ?>"
                           data-section="fcc-miss-body" data-param="miss_sort" data-val="<?php echo esc_attr($sk); ?>">
                            <?php echo esc_html($sl); ?>
                        </a>
                    <?php endforeach; ?>
                    </div>
                </div>
                <div class="fcc-ftool-group">
                    <span class="fcc-ftool-label">Period:</span>
                    <div class="fcc-ftool-pills">
                    <?php foreach (['all'=>'All Time','7d'=>'Last 7 Days','30d'=>'Last 30 Days','custom'=>'Custom'] as $pk=>$pl): ?>
                        <a href="<?php echo esc_url($build_url(['miss_period'=>$pk,'miss_page'=>1,'miss_from'=>'','miss_to'=>''])); ?>"
                           class="fcc-ftool-pill<?php echo $miss_period===$pk?' active':''; ?>"
                           data-section="fcc-miss-body" data-param="miss_period" data-val="<?php echo esc_attr($pk); ?>">
                            <?php echo esc_html($pl); ?>
                        </a>
                    <?php endforeach; ?>
                    </div>
                </div>
                <div class="fcc-ftool-custom-range<?php echo $miss_period==='custom'?' visible':''; ?>" id="fcc-miss-custom">
                    <input type="date" class="fcc-ftool-date" id="fcc-miss-from" value="<?php echo esc_attr($miss_from); ?>" max="<?php echo esc_attr(date('Y-m-d')); ?>">
                    <span style="color:#94a3b8;font-size:12px">to</span>
                    <input type="date" class="fcc-ftool-date" id="fcc-miss-to"   value="<?php echo esc_attr($miss_to); ?>"   max="<?php echo esc_attr(date('Y-m-d')); ?>">
                    <button type="button" class="fcc-ftool-apply"
                            data-section="fcc-miss-body"
                            data-from="fcc-miss-from" data-to="fcc-miss-to"
                            data-base="<?php echo esc_attr($build_url(['miss_period'=>'custom','miss_page'=>1,'miss_from'=>'__FROM__','miss_to'=>'__TO__'])); ?>">
                        Apply
                    </button>
                </div>
            </div>

            <div id="fcc-miss-body" class="fcc-section-body">
            <?php if ( !$missed ): ?>
            <div class="fcc-req-empty">
                <div><span class="dashicons dashicons-search"></span></div>
                <p class="fcc-req-empty-msg">
                    <?php echo $miss_filter === 'active'
                        ? 'No missed searches yet. When a visitor types something that returns 0 results (3+ characters), it will be automatically logged here.'
                        : 'No items match this filter.'; ?>
                </p>
            </div>
            <?php else: ?>
            <div style="overflow-x:auto">
            <table class="fcc-req-tbl">
                <thead><tr>
                    <th style="width:42px">#</th>
                    <th>Search Query</th>
                    <th class="ctr">Times Searched</th>
                    <th>Last Searched</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr></thead>
                <tbody>
                <?php foreach ($missed as $i => $r):
                    $cnt = (int)$r->count;
                    $hot = $cnt >= 5;
                ?>
                <tr>
                    <td><span class="fcc-req-rank"><?php echo $i + 1; ?></span></td>
                    <td>
                        <span class="fcc-req-name-cell"><span class="food-name"><?php echo esc_html($r->query); ?></span></span>
                        <?php if ($hot): ?>
                        <span class="fcc-req-hot-tag"><span class="dashicons dashicons-warning"></span> Hot</span>
                        <?php endif; ?>
                    </td>
                    <td class="ctr">
                        <span class="fcc-req-cnt <?php echo $cnt >= 5 ? 'fcc-req-cnt-hot' : ($cnt >= 3 ? 'fcc-req-cnt-warm' : 'fcc-req-cnt-cold'); ?>">
                            <?php echo $cnt; ?>×
                        </span>
                    </td>
                    <td style="color:#64748b;font-size:12.5px;white-space:nowrap"><?php echo esc_html(substr($r->last_searched,0,10)); ?></td>
                    <td><span class="fcc-req-pill <?php echo esc_attr($r->status); ?>"><?php echo ucfirst($r->status); ?></span></td>
                    <td>
                        <div class="fcc-req-acts">
                            <?php if ($r->status !== 'added'): ?>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=fcc-foods&action=add&prefill_name='.urlencode($r->query))); ?>" class="fcc-req-btn fcc-req-btn-add">
                                <span class="dashicons dashicons-plus-alt2"></span> Add to DB
                            </a>
                            <button onclick="fccUpdateStatus('missing',<?php echo (int)$r->id; ?>,'added',this)" class="fcc-req-btn fcc-req-btn-done">
                                <span class="dashicons dashicons-yes-alt"></span> Mark Added
                            </button>
                            <?php endif; ?>
                            <?php if ($r->status !== 'dismissed'): ?>
                            <button onclick="fccUpdateStatus('missing',<?php echo (int)$r->id; ?>,'dismissed',this)" class="fcc-req-btn fcc-req-btn-dismiss">
                                <span class="dashicons dashicons-no-alt"></span> Dismiss
                            </button>
                            <?php endif; ?>
                            <?php if ($r->status === 'dismissed' || $r->status === 'added'): ?>
                            <button onclick="fccUpdateStatus('missing',<?php echo (int)$r->id; ?>,'active',this)" class="fcc-req-btn fcc-req-btn-restore">
                                <span class="dashicons dashicons-undo"></span> Restore
                            </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php
            // Missed Searches pagination
            $miss_pages = max(1, (int)ceil($miss_total / $per_page));
            if ($miss_pages > 1):
                $from = ($miss_page - 1) * $per_page + 1;
                $to   = min($miss_page * $per_page, $miss_total);
            ?>
            <div class="fcc-req-pager">
                <span class="fcc-req-pager-info">Showing <strong><?php echo $from; ?>–<?php echo $to; ?></strong> of <strong><?php echo number_format($miss_total); ?></strong> searches</span>
                <div class="fcc-req-pager-btns">
                    <a href="<?php echo esc_url($build_url(['miss_page'=>$miss_page-1])); ?>"
                       class="fcc-req-pager-btn <?php echo $miss_page<=1?'disabled':''; ?>">
                        <span class="dashicons dashicons-arrow-left-alt2" style="font-size:13px;width:13px;height:13px"></span> Prev
                    </a>
                    <div class="fcc-req-pager-pages">
                    <?php
                    $start = max(1, $miss_page-2); $end = min($miss_pages, $miss_page+2);
                    if ($start>1){ echo '<a href="'.esc_url($build_url(['miss_page'=>1])).'" class="fcc-req-pager-page">1</a>'; if($start>2) echo '<span class="fcc-req-pager-ellipsis">…</span>'; }
                    for($p=$start;$p<=$end;$p++) echo '<a href="'.esc_url($build_url(['miss_page'=>$p])).'" class="fcc-req-pager-page'.($p===$miss_page?' current':'').'">'.$p.'</a>';
                    if ($end<$miss_pages){ if($end<$miss_pages-1) echo '<span class="fcc-req-pager-ellipsis">…</span>'; echo '<a href="'.esc_url($build_url(['miss_page'=>$miss_pages])).'" class="fcc-req-pager-page">'.$miss_pages.'</a>'; }
                    ?>
                    </div>
                    <a href="<?php echo esc_url($build_url(['miss_page'=>$miss_page+1])); ?>"
                       class="fcc-req-pager-btn <?php echo $miss_page>=$miss_pages?'disabled':''; ?>">
                        Next <span class="dashicons dashicons-arrow-right-alt2" style="font-size:13px;width:13px;height:13px"></span>
                    </a>
                </div>
            </div>
            <?php endif; // pagination ?>
            <?php endif; // has rows ?>
            </div><!-- #fcc-miss-body -->
        </div>

        <?php endif; // has tables ?>
        </div>

        <style>
        .fcc-section-body{position:relative;transition:opacity .18s}
        .fcc-section-body.fcc-loading{opacity:.45;pointer-events:none}
        .fcc-section-spinner{display:none;position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:28px;height:28px;border:3px solid #e2e8f0;border-top-color:#ff914d;border-radius:50%;animation:fcc-spin .7s linear infinite;z-index:10}
        .fcc-section-body.fcc-loading .fcc-section-spinner{display:block}
        @keyframes fcc-spin{to{transform:translate(-50%,-50%) rotate(360deg)}}
        /* ── Filter Toolbar ───────────────────────────────────────────── */
        .fcc-filter-toolbar{display:flex;flex-wrap:wrap;align-items:center;gap:8px 20px;padding:10px 20px 12px;border-top:1px solid #f1f5f9;background:#fafbfd}
        .fcc-ftool-group{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
        .fcc-ftool-label{font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#94a3b8;white-space:nowrap}
        .fcc-ftool-pills{display:flex;flex-wrap:wrap;gap:5px}
        .fcc-ftool-pill{display:inline-block;padding:4px 11px;border-radius:999px;border:1.5px solid #e2e8f0;background:#fff;color:#475569;font-size:12px;font-weight:600;text-decoration:none;transition:all .12s;line-height:1.4;white-space:nowrap;cursor:pointer}
        .fcc-ftool-pill:hover{border-color:#ff914d;color:#ff914d;background:#fff6f2;text-decoration:none}
        .fcc-ftool-pill.active{background:#ff914d;border-color:#ff914d;color:#fff}
        .fcc-ftool-pill.active:hover{background:#ef8035;border-color:#ef8035;color:#fff}
        .fcc-ftool-custom-range{display:none;align-items:center;gap:7px;flex-wrap:wrap;width:100%;padding-top:6px}
        .fcc-ftool-custom-range.visible{display:flex}
        .fcc-ftool-date{border:1.5px solid #e2e8f0;border-radius:7px;padding:5px 8px;font-size:12.5px;color:#475569;background:#fff;cursor:pointer;outline:none;font-family:inherit}
        .fcc-ftool-date:focus{border-color:#ff914d;box-shadow:0 0 0 2px #fff0e8}
        .fcc-ftool-apply{padding:5px 14px;background:#1e293b;color:#fff;border:none;border-radius:7px;font-size:12px;font-weight:700;cursor:pointer;transition:background .12s;font-family:inherit}
        .fcc-ftool-apply:hover{background:#0f172a}
        </style>
        <script>
        (function($){
            // ── Note expand / collapse ───────────────────────────────────────
            window.fccToggleNote = function(btn) {
                var note = btn.previousElementSibling;
                var expanded = note.classList.toggle('expanded');
                btn.innerHTML = expanded ? 'Show less &#8593;' : 'Show more &#8595;';
            };

            // ── Status update (Mark Added / Dismiss / Restore) ──────────────
            function fccUpdateStatus(type, id, status, btn) {
                var row = btn.closest('tr');
                btn.disabled = true;
                btn.style.opacity = '0.35';
                $.post('<?php echo esc_js($ajax_url); ?>', {
                    action: 'fcc_update_request_status',
                    nonce:  '<?php echo esc_js($action_nonce); ?>',
                    type:   type, id: id, status: status
                }, function(res) {
                    if (res.success) {
                        row.style.transition = 'opacity .22s,transform .22s';
                        row.style.opacity    = '0';
                        row.style.transform  = 'translateX(12px)';
                        setTimeout(function(){ row.remove(); }, 240);
                    } else {
                        btn.disabled = false;
                        btn.style.opacity = '1';
                        alert('Could not update status. Please try again.');
                    }
                });
            }
            window.fccUpdateStatus = fccUpdateStatus;

            // ── AJAX section pagination ──────────────────────────────────────
            function fccLoadSection(bodyId, url) {
                var $body = $('#' + bodyId);
                if (!$body.length) return;

                // Add spinner if not present
                if (!$body.find('.fcc-section-spinner').length) {
                    $body.prepend('<div class="fcc-section-spinner"></div>');
                }
                $body.addClass('fcc-loading');

                // jQuery .load() with fragment — fetches URL, extracts #bodyId contents
                $body.load(url + ' #' + bodyId + ' > *', function() {
                    $body.removeClass('fcc-loading');
                    // Update browser URL without reload
                    if (window.history && window.history.pushState) {
                        window.history.pushState(null, '', url);
                    }
                });
            }

            // Intercept status tab clicks (All / Pending / Added / Dismissed)
            $(document).on('click', '.fcc-req-tab', function(e) {
                e.preventDefault();
                var href    = $(this).attr('href');
                var section = $(this).data('section');
                if (href && section) fccLoadSection(section, href);
            });

            // Intercept pagination clicks inside section bodies
            $(document).on('click', '#fcc-req-body .fcc-req-pager-btn:not(.disabled), #fcc-req-body .fcc-req-pager-page:not(.current)', function(e) {
                e.preventDefault();
                var href = $(this).attr('href');
                if (href && href !== '#') fccLoadSection('fcc-req-body', href);
            });

            $(document).on('click', '#fcc-miss-body .fcc-req-pager-btn:not(.disabled), #fcc-miss-body .fcc-req-pager-page:not(.current)', function(e) {
                e.preventDefault();
                var href = $(this).attr('href');
                if (href && href !== '#') fccLoadSection('fcc-miss-body', href);
            });

            // Intercept sort / period pill clicks — AJAX reload section
            $(document).on('click', '.fcc-ftool-pill', function(e) {
                e.preventDefault();
                var $pill    = $(this);
                var section  = $pill.data('section');
                var param    = $pill.data('param');
                var val      = $pill.data('val');
                var href     = $pill.attr('href');
                if (!section || !href) return;

                // Update active pill in this group immediately (optimistic UI)
                $pill.closest('.fcc-ftool-pills').find('.fcc-ftool-pill').removeClass('active');
                $pill.addClass('active');

                // Show/hide custom date range row
                if (param === 'req_period' || param === 'miss_period') {
                    var customId = param === 'req_period' ? '#fcc-req-custom' : '#fcc-miss-custom';
                    if (val === 'custom') {
                        $(customId).addClass('visible');
                        return; // wait for Apply — don't trigger load yet
                    } else {
                        $(customId).removeClass('visible');
                    }
                }

                fccLoadSection(section, href);
            });

            // Custom date range Apply button
            $(document).on('click', '.fcc-ftool-apply', function() {
                var section  = $(this).data('section');
                var fromVal  = $('#' + $(this).data('from')).val();
                var toVal    = $('#' + $(this).data('to')).val();
                var baseUrl  = $(this).data('base');
                if (!fromVal || !toVal) { alert('Select both start and end dates.'); return; }
                if (fromVal > toVal)    { alert('Start date must be before end date.'); return; }
                var url = baseUrl.replace('__FROM__', fromVal).replace('__TO__', toVal);
                fccLoadSection(section, url);
            });

        })(jQuery);
        </script>
        <?php
    }

    // ── Admin: Reset Analytics ────────────────────────────────────────────────
    public function adminResetAnalytics() {
        if ( ! current_user_can('manage_options') ) wp_die('Forbidden');
        check_admin_referer('fcc_reset_analytics');
        global $wpdb;
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}fcc_analytics" );
        if ( get_option('fcc_tables_ready') ) {
            $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}fcc_analytics_daily" );
        }
        delete_transient('fcc_top_trending');
        wp_redirect( admin_url('admin.php?page=fcc-analytics&reset=1') );
        exit;
    }

    // ── Admin: Export Analytics CSV ───────────────────────────────────────────
    public function adminExportAnalytics() {
        if ( ! current_user_can('manage_options') ) wp_die('Forbidden');
        check_admin_referer('fcc_export_analytics');
        global $wpdb;
        $fcc_from = sanitize_text_field($_POST['fcc_from'] ?? '');
        $fcc_to   = sanitize_text_field($_POST['fcc_to']   ?? '');
        if ($fcc_from && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fcc_from)) $fcc_from = '';
        if ($fcc_to   && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fcc_to))   $fcc_to   = '';
        $has_range = $fcc_from !== '' && $fcc_to !== '';

        if ($has_range) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT d.log_date, d.food_id, d.food_name, COALESCE(f.category,'Unknown') as category,
                            d.searches, d.calcs, (d.searches + d.calcs) as total_interest
                     FROM {$wpdb->prefix}fcc_analytics_daily d
                     LEFT JOIN {$wpdb->prefix}fcc_foods f ON d.food_id = f.id
                     WHERE d.log_date BETWEEN %s AND %s
                     ORDER BY d.log_date ASC, d.calcs DESC",
                    $fcc_from, $fcc_to
                ), ARRAY_A ) ?: [];
            $filename = 'fcc-analytics-' . $fcc_from . '-to-' . $fcc_to . '.csv';
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Date','Fish ID','Fish Name','Category','Searches','Calculations','Total Interest']);
            foreach ($rows as $r) {
                fputcsv($out, [$r['log_date'], $r['food_id'], $r['food_name'], $r['category'], $r['searches'], $r['calcs'], $r['total_interest']]);
            }
        } else {
            $rows = $wpdb->get_results(
                "SELECT a.food_id, a.food_name, COALESCE(f.category,'Unknown') as category,
                        a.searches, a.calcs, (a.searches + a.calcs) as total_interest
                 FROM {$wpdb->prefix}fcc_analytics a
                 LEFT JOIN {$wpdb->prefix}fcc_foods f ON a.food_id = f.id
                 ORDER BY a.calcs DESC",
                ARRAY_A ) ?: [];
            $filename = 'fcc-analytics-all-time-' . gmdate('Y-m-d') . '.csv';
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Fish ID','Fish Name','Category','Searches','Calculations','Total Interest']);
            foreach ($rows as $r) {
                fputcsv($out, [$r['food_id'], $r['food_name'], $r['category'], $r['searches'], $r['calcs'], $r['total_interest']]);
            }
        }
        fclose($out);
        exit;
    }

    // ── Admin: Export Analytics Excel ────────────────────────────────────────
    public function adminExportAnalyticsExcel() {
        if ( ! current_user_can('manage_options') ) wp_die('Forbidden');
        check_admin_referer('fcc_export_excel');
        global $wpdb;
        $fcc_from = sanitize_text_field($_POST['fcc_from'] ?? '');
        $fcc_to   = sanitize_text_field($_POST['fcc_to']   ?? '');
        if ($fcc_from && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fcc_from)) $fcc_from = '';
        if ($fcc_to   && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fcc_to))   $fcc_to   = '';
        $has_range = $fcc_from !== '' && $fcc_to !== '';

        if ($has_range) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT d.log_date, d.food_id, d.food_name, COALESCE(f.category,'Unknown') as category,
                            d.searches, d.calcs, (d.searches + d.calcs) as total_interest
                     FROM {$wpdb->prefix}fcc_analytics_daily d
                     LEFT JOIN {$wpdb->prefix}fcc_foods f ON d.food_id = f.id
                     WHERE d.log_date BETWEEN %s AND %s
                     ORDER BY d.log_date ASC, d.calcs DESC",
                    $fcc_from, $fcc_to
                ), ARRAY_A ) ?: [];
            $filename  = 'fcc-analytics-' . $fcc_from . '-to-' . $fcc_to . '.xls';
            $headers   = ['Date','Fish ID','Fish Name','Category','Searches','Calculations','Total Interest'];
            $row_keys  = ['log_date','food_id','food_name','category','searches','calcs','total_interest'];
        } else {
            $rows = $wpdb->get_results(
                "SELECT a.food_id, a.food_name, COALESCE(f.category,'Unknown') as category,
                        a.searches, a.calcs, (a.searches + a.calcs) as total_interest
                 FROM {$wpdb->prefix}fcc_analytics a
                 LEFT JOIN {$wpdb->prefix}fcc_foods f ON a.food_id = f.id
                 ORDER BY a.calcs DESC",
                ARRAY_A ) ?: [];
            $filename  = 'fcc-analytics-all-time-' . gmdate('Y-m-d') . '.xls';
            $headers   = ['Fish ID','Fish Name','Category','Searches','Calculations','Total Interest'];
            $row_keys  = ['food_id','food_name','category','searches','calcs','total_interest'];
        }

        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        // SpreadsheetML — opens natively in Excel 2003+, LibreOffice, Google Sheets
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
               xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">
<Worksheet ss:Name="Analytics">
<Table>';
        // Header row
        echo '<Row>';
        foreach ($headers as $h) {
            echo '<Cell><Data ss:Type="String">' . esc_html($h) . '</Data></Cell>';
        }
        echo '</Row>';
        // Data rows
        foreach ($rows as $r) {
            echo '<Row>';
            foreach ($row_keys as $k) {
                $v    = $r[$k] ?? '';
                $type = is_numeric($v) ? 'Number' : 'String';
                echo '<Cell><Data ss:Type="' . $type . '">' . esc_html($v) . '</Data></Cell>';
            }
            echo '</Row>';
        }
        echo '</Table></Worksheet></Workbook>';
        exit;
    }

    // ── Admin: Manage Foods Page (CRUD) ──────────────────────────────────────
    public function adminFoodsPage() {
        global $wpdb;
        $tbl       = $wpdb->prefix . 'fcc_foods';
        $action    = sanitize_text_field( $_GET['action'] ?? '' );
        $edit_id   = intval( $_GET['id'] ?? 0 );
        $edit_food = null;
        if ( $action === 'edit' && $edit_id ) {
            $edit_food = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $tbl WHERE id=%d", $edit_id ), ARRAY_A );
        }
        $categories  = ['White Fish','Oily Fish','Shellfish','Squid & Octopus','Canned & Smoked','Roe & Caviar','Prepared Seafood','Seaweed'];
        $per_page    = 50;
        $cat_filter  = sanitize_text_field( $_GET['cat'] ?? '' );
        if ( ! in_array( $cat_filter, $categories, true ) ) $cat_filter = '';
        $paged       = max( 1, intval( $_GET['paged'] ?? 1 ) );
        $where       = $cat_filter ? $wpdb->prepare( "WHERE category=%s", $cat_filter ) : '';
        $all_count   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $tbl" );
        $page_total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $tbl $where" );
        $total_pages = max( 1, (int) ceil( $page_total / $per_page ) );
        $paged       = min( $paged, $total_pages );
        $offset      = ( $paged - 1 ) * $per_page;
        $foods       = $wpdb->get_results( "SELECT * FROM $tbl $where ORDER BY sort_order ASC, id ASC LIMIT $per_page OFFSET $offset", ARRAY_A ) ?: [];
        $cat_counts  = [];
        foreach ( $wpdb->get_results( "SELECT category, COUNT(*) as cnt FROM $tbl GROUP BY category ORDER BY cnt DESC" ) as $row ) {
            $cat_counts[ $row->category ] = (int) $row->cnt;
        }
        $eco_opts   = ['good'=>'Good (Sustainable)','ok'=>'OK (Responsible)','avoid'=>'Avoid'];
        $src_opts   = ['wild'=>'Wild','farmed'=>'Farmed','mixed'=>'Mixed'];
        $merc_opts  = ['low'=>'Low','moderate'=>'Moderate','high'=>'High'];
        $show_form  = ( $action === 'add' || ( $action === 'edit' && $edit_food ) );
        ?>
<style>
/* ── Base ──────────────────────────────────────────────── */
.fcc-adm{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#1e293b}
/* ── Hero header ───────────────────────────────────────── */
.fcc-hero{background:linear-gradient(135deg,#1e293b 0%,#334155 100%);border-radius:12px;padding:20px 24px;margin-bottom:20px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:14px;box-shadow:0 4px 20px rgba(0,0,0,.15)}
.fcc-hero-left{display:flex;align-items:center;gap:14px}
.fcc-hero-icon{width:48px;height:48px;background:linear-gradient(135deg,#ff914d,#e07a3d);border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:24px;flex-shrink:0;box-shadow:0 4px 12px rgba(255,145,77,.35)}
.fcc-hero-title{color:#fff;font-size:19px;font-weight:700;margin:0;line-height:1.2}
.fcc-hero-sub{color:rgba(255,255,255,.5);font-size:12px;margin-top:3px}
.fcc-hero-right{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
/* ── Buttons ───────────────────────────────────────────── */
.fcc-btn-or{background:#ff914d!important;color:#fff!important;border:none!important;padding:9px 18px!important;border-radius:7px!important;font-weight:700!important;cursor:pointer!important;text-decoration:none!important;font-size:13px!important;display:inline-flex!important;align-items:center!important;gap:5px!important;line-height:1.4!important;transition:background .15s!important;white-space:nowrap!important}
.fcc-btn-or:hover{background:#e07a3d!important;color:#fff!important}
.fcc-btn-gr{background:#545454!important;color:#fff!important;border:none!important;padding:9px 18px!important;border-radius:7px!important;font-weight:700!important;cursor:pointer!important;text-decoration:none!important;font-size:13px!important;display:inline-flex!important;align-items:center!important;gap:5px!important;line-height:1.4!important;transition:background .15s!important;white-space:nowrap!important}
.fcc-btn-gr:hover{background:#333!important;color:#fff!important}
.fcc-btn-gr2{background:#16a34a!important;color:#fff!important;border:none!important;padding:9px 18px!important;border-radius:7px!important;font-weight:700!important;cursor:pointer!important;text-decoration:none!important;font-size:13px!important;display:inline-flex!important;align-items:center!important;gap:5px!important;line-height:1.4!important;transition:background .15s!important;white-space:nowrap!important}
.fcc-btn-gr2:hover{background:#15803d!important;color:#fff!important}
.fcc-btn-bl{background:rgba(255,255,255,.12)!important;color:#fff!important;border:1.5px solid rgba(255,255,255,.2)!important;padding:8px 16px!important;border-radius:7px!important;font-weight:700!important;cursor:pointer!important;text-decoration:none!important;font-size:13px!important;display:inline-flex!important;align-items:center!important;gap:5px!important;line-height:1.4!important;transition:all .15s!important;white-space:nowrap!important}
.fcc-btn-bl:hover{background:rgba(255,255,255,.22)!important;border-color:rgba(255,255,255,.35)!important}
/* ── Toolbar ───────────────────────────────────────────── */
.fcc-toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:16px;flex-wrap:wrap}
.fcc-srch-wrap{position:relative;flex:1;min-width:200px;max-width:360px}
.fcc-srch-wrap svg{position:absolute;left:11px;top:50%;transform:translateY(-50%);width:15px;height:15px;stroke:#94a3b8;fill:none;pointer-events:none}
#fcc-tbl-search{width:100%;padding:8px 12px 8px 34px;border:1.5px solid #e2e8f0;border-radius:7px;font-size:13px;background:#fff;box-sizing:border-box;outline:none;transition:border-color .15s}
#fcc-tbl-search:focus{border-color:#ff914d}
.fcc-chip-row{display:flex;gap:6px;flex-wrap:wrap;align-items:center}
.fcc-chip{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:999px;font-size:11.5px;color:#64748b;font-weight:600;white-space:nowrap;cursor:pointer;transition:all .15s;text-decoration:none}
.fcc-chip:hover{border-color:#ff914d;color:#c2521e;background:#fff3ec;text-decoration:none}
.fcc-chip.fcc-chip-active{background:#ff914d;border-color:#ff914d;color:#fff}
.fcc-chip.fcc-chip-active:hover{color:#fff}
.fcc-chip.fcc-chip-active b{color:#fff}
.fcc-chip b{color:#1e293b}
/* ── Pagination ────────────────────────────────────────────── */
.fcc-pager{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;padding:14px 16px;border-top:1px solid #f1f5f9;background:#fafbfd;border-radius:0 0 10px 10px}
.fcc-pager-info{font-size:12.5px;color:#64748b}
.fcc-pager-btns{display:flex;align-items:center;gap:6px;flex-wrap:wrap}
.fcc-pager-btn{display:inline-flex;align-items:center;gap:4px;padding:5px 12px;border-radius:6px;border:1.5px solid #e2e8f0;background:#fff;color:#475569;font-size:12px;font-weight:600;text-decoration:none;transition:all .12s;white-space:nowrap}
.fcc-pager-btn:hover{border-color:#ff914d;color:#ff914d;background:#fff6f2;text-decoration:none}
.fcc-pager-btn.disabled{opacity:.4;pointer-events:none}
.fcc-pager-pages{display:flex;gap:4px}
.fcc-pager-page{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:6px;border:1.5px solid #e2e8f0;background:#fff;color:#475569;font-size:12px;font-weight:600;text-decoration:none;transition:all .12s}
.fcc-pager-page:hover{border-color:#ff914d;color:#ff914d;background:#fff6f2;text-decoration:none}
.fcc-pager-page.current{background:#ff914d;border-color:#ff914d;color:#fff;font-weight:700}
.fcc-pager-ellipsis{display:inline-flex;align-items:center;justify-content:center;width:24px;height:30px;color:#94a3b8;font-size:13px}
.fcc-srch-count{font-size:12px;color:#94a3b8;white-space:nowrap}
/* ── Notices ───────────────────────────────────────────── */
.fcc-notice-ok{background:#f0fdf4;color:#166534;border-left:4px solid #22c55e;padding:11px 16px;border-radius:0 6px 6px 0;margin-bottom:16px;font-weight:600;font-size:13px}
.fcc-notice-del{background:#fffbeb;color:#92400e;border-left:4px solid #f59e0b;padding:11px 16px;border-radius:0 6px 6px 0;margin-bottom:16px;font-weight:600;font-size:13px}
.fcc-notice-err{background:#fef2f2;color:#991b1b;border-left:4px solid #ef4444;padding:11px 16px;border-radius:0 6px 6px 0;margin-bottom:16px;font-weight:600;font-size:13px}
/* ── Import panel ──────────────────────────────────────── */
.fcc-imp-panel{display:none}
.fcc-imp-panel.fcc-imp-open{display:block}
/* ── Form card ─────────────────────────────────────────── */
.fcc-form-card{background:#fff;border:1px solid #e2e8f0;border-top:4px solid #ff914d;border-radius:10px;padding:24px;margin-bottom:22px;box-shadow:0 2px 10px rgba(0,0,0,.06)}
.fcc-form-card h2{margin:0 0 20px;font-size:17px;color:#1e293b;font-weight:700}
.fcc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:14px}
.fcc-fg{display:flex;flex-direction:column;gap:5px}
.fcc-fg label{font-weight:700;font-size:11.5px;color:#64748b;text-transform:uppercase;letter-spacing:.4px}
.fcc-fg input,.fcc-fg select,.fcc-fg textarea{border:1.5px solid #e2e8f0;border-radius:6px;padding:8px 11px;font-size:13px;color:#1e293b;background:#fafafa;width:100%;box-sizing:border-box;transition:border-color .15s}
.fcc-fg input:focus,.fcc-fg select:focus,.fcc-fg textarea:focus{border-color:#ff914d;outline:none;background:#fff}
.fcc-fg textarea{min-height:72px;resize:vertical}
.fcc-fullrow{grid-column:1/-1}
.fcc-form-acts{margin-top:22px;display:flex;gap:8px;align-items:center}
/* ── Table ─────────────────────────────────────────────── */
.fcc-tbl-wrap{background:#fff;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,.06)}
.fcc-tbl{width:100%;border-collapse:collapse;font-size:12.5px}
.fcc-tbl thead th{background:#1e293b;color:#cbd5e1;padding:11px 13px;text-align:left;font-weight:700;white-space:nowrap;font-size:11.5px;letter-spacing:.3px;text-transform:uppercase}
.fcc-tbl thead th:first-child{color:#64748b}
.fcc-tbl tbody tr{border-bottom:1px solid #f1f5f9;transition:background .1s}
.fcc-tbl tbody tr:last-child{border-bottom:none}
.fcc-tbl tbody tr:hover{background:#fff8f4}
.fcc-tbl td{padding:10px 13px;vertical-align:middle}
.fcc-tbl td:first-child{color:#94a3b8;font-size:11px;font-weight:600}
.fcc-tbl td strong{color:#1e293b;font-weight:600}
/* ── Eco & mercury badges ──────────────────────────────── */
.eco-g{display:inline-block;padding:2px 9px;border-radius:999px;font-size:11px;font-weight:700;background:#dcfce7;color:#166534}
.eco-o{display:inline-block;padding:2px 9px;border-radius:999px;font-size:11px;font-weight:700;background:#fef9c3;color:#854d0e}
.eco-a{display:inline-block;padding:2px 9px;border-radius:999px;font-size:11px;font-weight:700;background:#fee2e2;color:#991b1b}
.merc-low{color:#16a34a;font-weight:700}.merc-moderate{color:#d97706;font-weight:700}.merc-high{color:#dc2626;font-weight:700}
/* ── Row action buttons ────────────────────────────────── */
.fcc-acts{display:flex;gap:6px;align-items:center}
.fcc-act-edit{display:inline-flex;align-items:center;padding:4px 11px;background:#fff8f4;color:#e07a3d;border:1.5px solid #ffb085;border-radius:5px;font-size:11.5px;font-weight:700;text-decoration:none;transition:all .15s;white-space:nowrap}
.fcc-act-edit:hover{background:#ff914d;color:#fff;border-color:#ff914d}
.fcc-act-del{display:inline-flex;align-items:center;padding:4px 11px;background:#fff5f5;color:#dc2626;border:1.5px solid #fecaca;border-radius:5px;font-size:11.5px;font-weight:700;text-decoration:none;transition:all .15s;white-space:nowrap}
.fcc-act-del:hover{background:#dc2626;color:#fff;border-color:#dc2626}
</style>
<script>
function fccFilterTable(q){
    q=q.toLowerCase();
    var rows=document.querySelectorAll('.fcc-tbl tbody tr'),n=0;
    rows.forEach(function(r){
        var show=!q||r.textContent.toLowerCase().indexOf(q)!==-1;
        r.style.display=show?'':'none';
        if(show)n++;
    });
    var el=document.getElementById('fcc-srch-count');
    if(el)el.textContent=q?n+' result'+(n===1?'':'s'):'';
}
</script>
<div class="fcc-adm wrap">
<!-- Hero header -->
<div class="fcc-hero">
    <div class="fcc-hero-left">
        <div class="fcc-hero-icon">🦞</div>
        <div>
            <div class="fcc-hero-title">Manage Seafood Database</div>
            <div class="fcc-hero-sub"><?php
                echo esc_html( $all_count . ' items across ' . count($cat_counts) . ' categories' );
            ?></div>
        </div>
    </div>
    <?php if ( !$show_form ): ?>
    <div class="fcc-hero-right">
        <a href="<?php echo esc_url( admin_url('admin.php?page=fcc-foods&action=add') ); ?>" class="fcc-btn-or">＋ Add New Food</a>
        <a href="<?php echo esc_url( wp_nonce_url( admin_url('admin-post.php?action=fcc_export_foods'), 'fcc_export_foods' ) ); ?>" class="fcc-btn-gr2">↓ Export CSV</a>
        <button type="button" class="fcc-btn-bl" id="fcc-imp-toggle" onclick="var p=document.getElementById('fcc-imp-panel');p.classList.toggle('fcc-imp-open');this.textContent=p.classList.contains('fcc-imp-open')?'✕ Close Import':'↑ Import CSV';">↑ Import CSV</button>
    </div>
    <?php endif; ?>
</div>
<?php if ( !$show_form ): ?>
<!-- Toolbar: search + category chips -->
<div class="fcc-toolbar">
    <div class="fcc-srch-wrap">
        <svg viewBox="0 0 24 24" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
        <input type="text" id="fcc-tbl-search" placeholder="Search this page…" oninput="fccFilterTable(this.value)">
    </div>
    <div class="fcc-chip-row">
        <?php $foods_base_url = admin_url('admin.php?page=fcc-foods'); ?>
        <a href="<?php echo esc_url($foods_base_url); ?>" class="fcc-chip <?php echo $cat_filter===''?'fcc-chip-active':''; ?>">
            <b><?php echo $all_count; ?></b> All
        </a>
        <?php foreach ($cat_counts as $cat => $cnt): ?>
        <a href="<?php echo esc_url(add_query_arg('cat', urlencode($cat), $foods_base_url)); ?>"
           class="fcc-chip <?php echo $cat_filter===$cat?'fcc-chip-active':''; ?>">
            <b><?php echo $cnt; ?></b> <?php echo esc_html($cat); ?>
        </a>
        <?php endforeach; ?>
        <span class="fcc-srch-count" id="fcc-srch-count"></span>
    </div>
</div>
<?php endif; ?>

<?php
$msg = sanitize_text_field( $_GET['msg'] ?? '' );
if ( $msg === 'saved' ) {
    echo '<div class="fcc-notice-ok">Food item saved successfully.</div>';
} elseif ( $msg === 'deleted' ) {
    echo '<div class="fcc-notice-del">Food item deleted.</div>';
} elseif ( $msg === 'error' ) {
    echo '<div class="fcc-notice-err">An error occurred. Please try again.</div>';
} elseif ( strpos( $msg, 'imported_' ) === 0 ) {
    $parts = explode( '_', substr( $msg, 9 ) );
    $ins   = (int) ( $parts[0] ?? 0 );
    $upd   = (int) ( $parts[1] ?? 0 );
    echo '<div class="fcc-notice-ok">' . esc_html( $ins . ' item(s) added, ' . $upd . ' item(s) updated from CSV.' ) . '</div>';
} elseif ( $msg === 'imp_err' ) {
    echo '<div class="fcc-notice-err">Import failed — please check the CSV format and try again.</div>';
} elseif ( strpos( $msg, 'seeded_' ) === 0 ) {
    $n = (int) substr( $msg, 7 );
    echo '<div class="fcc-notice-ok">✅ Default seafood database loaded — ' . $n . ' items ready.</div>';
} elseif ( strpos( $msg, 'seed_failed' ) === 0 ) {
    $dberr = sanitize_text_field( urldecode( $_GET['dberr'] ?? '' ) );
    echo '<div class="fcc-notice-err">❌ Seed failed. DB error: ' . esc_html( $dberr ?: 'unknown' ) . '</div>';
}
?>

<?php if ( !$show_form && count($foods) === 0 && strpos($msg, 'seeded_') !== 0 ): ?>
<div style="display:flex;align-items:center;justify-content:space-between;background:#fff8f4;border:1.5px solid #ffb085;border-radius:6px;padding:14px 18px;margin-bottom:16px">
    <span style="font-size:13.5px;color:#c4612a;font-weight:600">⚠️ No seafood items found. Load the built-in database to get started.</span>
    <a href="<?php echo esc_url( wp_nonce_url( admin_url('admin-post.php?action=fcc_seed_foods'), 'fcc_seed_foods' ) ); ?>"
       class="fcc-btn-or" style="white-space:nowrap;margin-left:16px">Load Default Fish Data (90 items)</a>
</div>
<?php endif; ?>

<?php if ( !$show_form ): ?>
<div id="fcc-imp-panel" class="fcc-imp-panel">
<div class="fcc-form-card" style="margin-bottom:20px;border-top-color:#2563eb">
    <h2 style="margin-top:0;color:#1e293b;display:flex;align-items:center;gap:8px">↑ Import from CSV</h2>
    <p style="color:#64748b;font-size:13px;margin-top:0;margin-bottom:16px;line-height:1.6">Upload a CSV file exported from this page. Rows matched by <strong>name</strong> are updated; unmatched names are inserted as new items.<br>Required columns: <code style="background:#f1f5f9;padding:1px 5px;border-radius:3px;font-size:12px">name</code>, <code style="background:#f1f5f9;padding:1px 5px;border-radius:3px;font-size:12px">category</code> — all other columns optional.</p>
    <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" enctype="multipart/form-data">
        <?php wp_nonce_field('fcc_import_foods'); ?>
        <input type="hidden" name="action" value="fcc_import_foods">
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
            <input type="file" name="fcc_csv" accept=".csv,text/csv" style="font-size:13px;border:1.5px solid #e2e8f0;border-radius:6px;padding:7px 11px;background:#fafafa;flex:1;min-width:200px">
            <button type="submit" class="fcc-btn-or">Import File</button>
        </div>
    </form>
</div>
</div>
<?php endif; ?>

<?php if ( $show_form ): ?>
<div class="fcc-form-card">
<h2><?php echo ( $action === 'edit' ) ? 'Edit Food Item' : 'Add New Food Item'; ?></h2>
<form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
    <?php wp_nonce_field('fcc_save_food'); ?>
    <input type="hidden" name="action" value="fcc_save_food">
    <?php if ( $edit_food ): ?><input type="hidden" name="food_id" value="<?php echo (int)$edit_food['id']; ?>"><?php endif; ?>
    <div class="fcc-grid">
        <div class="fcc-fg fcc-fullrow">
            <label>Name *</label>
            <input type="text" name="name" required maxlength="100" value="<?php echo esc_attr($edit_food['name'] ?? sanitize_text_field($_GET['prefill_name'] ?? '')); ?>">
        </div>
        <div class="fcc-fg">
            <label>Category *</label>
            <select name="category" required>
                <?php foreach ( $categories as $c ): ?>
                <option value="<?php echo esc_attr($c); ?>" <?php selected(($edit_food['category'] ?? ''), $c); ?>><?php echo esc_html($c); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="fcc-fg"><label>Calories (kcal/100g)</label><input type="number" name="calories" step="0.1" min="0" value="<?php echo esc_attr($edit_food['calories'] ?? '0'); ?>"></div>
        <div class="fcc-fg"><label>Protein (g)</label><input type="number" name="protein" step="0.1" min="0" value="<?php echo esc_attr($edit_food['protein'] ?? '0'); ?>"></div>
        <div class="fcc-fg"><label>Carbs (g)</label><input type="number" name="carbs" step="0.1" min="0" value="<?php echo esc_attr($edit_food['carbs'] ?? '0'); ?>"></div>
        <div class="fcc-fg"><label>Fat (g)</label><input type="number" name="fat" step="0.1" min="0" value="<?php echo esc_attr($edit_food['fat'] ?? '0'); ?>"></div>
        <div class="fcc-fg"><label>Fiber (g)</label><input type="number" name="fiber" step="0.1" min="0" value="<?php echo esc_attr($edit_food['fiber'] ?? '0'); ?>"></div>
        <div class="fcc-fg"><label>Sugar (g)</label><input type="number" name="sugar" step="0.1" min="0" value="<?php echo esc_attr($edit_food['sugar'] ?? '0'); ?>"></div>
        <div class="fcc-fg"><label>Omega-3 (g)</label><input type="number" name="omega3" step="0.01" min="0" value="<?php echo esc_attr($edit_food['omega3'] ?? '0'); ?>"></div>
        <div class="fcc-fg">
            <label>Mercury</label>
            <select name="mercury">
                <?php foreach ( $merc_opts as $v => $l ): ?><option value="<?php echo esc_attr($v); ?>" <?php selected(($edit_food['mercury'] ?? 'low'), $v); ?>><?php echo esc_html($l); ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="fcc-fg">
            <label>Allergens</label>
            <input type="text" name="allergens" maxlength="100" placeholder="Fish, Crustaceans, Fish+Gluten…" value="<?php echo esc_attr($edit_food['allergens'] ?? 'Fish'); ?>">
        </div>
        <div class="fcc-fg">
            <label>Eco Rating</label>
            <select name="eco_rating">
                <?php foreach ( $eco_opts as $v => $l ): ?><option value="<?php echo esc_attr($v); ?>" <?php selected(($edit_food['eco_rating'] ?? 'ok'), $v); ?>><?php echo esc_html($l); ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="fcc-fg">
            <label>Eco Source</label>
            <select name="eco_source">
                <?php foreach ( $src_opts as $v => $l ): ?><option value="<?php echo esc_attr($v); ?>" <?php selected(($edit_food['eco_source'] ?? 'wild'), $v); ?>><?php echo esc_html($l); ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="fcc-fg">
            <label>Season (e.g. Oct–May or All year)</label>
            <input type="text" name="season" maxlength="50" value="<?php echo esc_attr($edit_food['season'] ?? 'All year'); ?>">
        </div>
        <div class="fcc-fg">
            <label>Sort Order</label>
            <input type="number" name="sort_order" min="0" value="<?php echo esc_attr($edit_food['sort_order'] ?? '0'); ?>">
        </div>
        <div class="fcc-fg">
            <label>Sort Priority <small style="font-weight:400;color:#94a3b8">(search ranking boost)</small></label>
            <input type="number" name="sort_priority" min="0" max="999" value="<?php echo esc_attr($edit_food['sort_priority'] ?? '0'); ?>">
            <small style="color:#94a3b8;font-size:11px">Higher = appears first in search. Default 0. Set to 10 to pin this item to the top of matching results.</small>
        </div>
        <div class="fcc-fg fcc-fullrow">
            <label>Health Tip</label>
            <textarea name="health_tip" rows="3"><?php echo esc_textarea($edit_food['health_tip'] ?? ''); ?></textarea>
        </div>
    </div>
    <div class="fcc-form-acts">
        <button type="submit" class="fcc-btn-or">💾 Save Food</button>
        <a href="<?php echo esc_url( admin_url('admin.php?page=fcc-foods') ); ?>" class="fcc-btn-gr">Cancel</a>
    </div>
</form>
</div>
<?php endif; ?>

<div class="fcc-tbl-wrap" id="fcc-foods-content">
<table class="fcc-tbl">
<thead><tr>
    <th style="width:36px">#</th><th>Name</th><th>Category</th>
    <th>kcal</th><th>Protein</th><th>Ω-3</th><th>Mercury</th><th>Eco</th><th>Season</th>
    <th style="width:72px" title="Search ranking boost — higher = appears first in search results">Priority ↑</th>
    <th style="width:90px">Actions</th>
</tr></thead>
<tbody>
<?php foreach ( $foods as $f ):
    $ecoc = ($f['eco_rating'] === 'good') ? 'eco-g' : (($f['eco_rating'] === 'avoid') ? 'eco-a' : 'eco-o');
?>
<tr data-cat="<?php echo esc_attr($f['category']); ?>">
    <td style="color:#aaa;font-size:11px"><?php echo (int)$f['id']; ?></td>
    <td><strong><?php echo esc_html($f['name']); ?></strong></td>
    <td style="color:#545454"><?php echo esc_html($f['category']); ?></td>
    <td><?php echo (float)$f['calories']; ?></td>
    <td><?php echo (float)$f['protein']; ?>g</td>
    <td><?php echo (float)$f['omega3']; ?>g</td>
    <td class="merc-<?php echo esc_attr($f['mercury']); ?>"><?php echo ucfirst($f['mercury']); ?></td>
    <td><span class="<?php echo $ecoc; ?>"><?php echo ucfirst($f['eco_rating']); ?></span></td>
    <td style="color:#545454;font-size:11.5px"><?php echo esc_html($f['season']); ?></td>
    <td>
        <input type="number" class="fcc-priority-inp" data-id="<?php echo (int)$f['id']; ?>"
               min="0" max="999" value="<?php echo intval($f['sort_priority'] ?? 0); ?>"
               title="Search priority — higher = first in results"
               style="width:52px;padding:4px 5px;border:1.5px solid #e2e8f0;border-radius:6px;font-size:12px;text-align:center;color:#1e293b;font-family:inherit;transition:border-color .15s">
    </td>
    <td>
        <div class="fcc-acts">
            <a href="<?php echo esc_url( admin_url('admin.php?page=fcc-foods&action=edit&id=' . (int)$f['id']) ); ?>" class="fcc-act-edit">Edit</a>
            <a href="<?php echo esc_url( wp_nonce_url( admin_url('admin-post.php?action=fcc_delete_food&food_id=' . (int)$f['id']), 'fcc_delete_food_' . (int)$f['id'] ) ); ?>"
               class="fcc-act-del"
               onclick="return confirm('Delete <?php echo esc_js($f['name']); ?>? This cannot be undone.');">Delete</a>
        </div>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php
// Pagination bar
if ( $total_pages > 1 ):
    $from       = $offset + 1;
    $to         = min( $paged * $per_page, $page_total );
    $foods_pg_url = admin_url('admin.php?page=fcc-foods') . ($cat_filter ? '&cat=' . urlencode($cat_filter) : '');
?>
<div class="fcc-pager">
    <span class="fcc-pager-info">Showing <strong><?php echo $from; ?>–<?php echo $to; ?></strong> of <strong><?php echo number_format($page_total); ?></strong> items</span>
    <div class="fcc-pager-btns">
        <a href="<?php echo esc_url($foods_pg_url.'&paged='.($paged-1)); ?>" class="fcc-pager-btn <?php echo $paged<=1?'disabled':''; ?>">← Prev</a>
        <div class="fcc-pager-pages">
        <?php
        $ps = max(1,$paged-2); $pe = min($total_pages,$paged+2);
        if($ps>1){ echo '<a href="'.esc_url($foods_pg_url.'&paged=1').'" class="fcc-pager-page">1</a>'; if($ps>2) echo '<span class="fcc-pager-ellipsis">…</span>'; }
        for($p=$ps;$p<=$pe;$p++) echo '<a href="'.esc_url($foods_pg_url.'&paged='.$p).'" class="fcc-pager-page'.($p===$paged?' current':'').'">'.$p.'</a>';
        if($pe<$total_pages){ if($pe<$total_pages-1) echo '<span class="fcc-pager-ellipsis">…</span>'; echo '<a href="'.esc_url($foods_pg_url.'&paged='.$total_pages).'" class="fcc-pager-page">'.$total_pages.'</a>'; }
        ?>
        </div>
        <a href="<?php echo esc_url($foods_pg_url.'&paged='.($paged+1)); ?>" class="fcc-pager-btn <?php echo $paged>=$total_pages?'disabled':''; ?>">Next →</a>
    </div>
</div>
<?php endif; ?>
</div>
</div>
<script>
(function($){
    var nonce   = '<?php echo wp_create_nonce("fcc_admin_nonce"); ?>';
    var ajaxUrl = '<?php echo esc_js( admin_url("admin-ajax.php") ); ?>';

    /* ── Inline priority save ── */
    $(document).on('change', '.fcc-priority-inp', function(){
        var $inp = $(this);
        var id   = parseInt($inp.data('id'));
        var val  = Math.max(0, parseInt($inp.val()) || 0);
        $inp.val(val).css('border-color','#ff914d');
        $.post(ajaxUrl, { action:'fcc_update_sort_priority', nonce:nonce, id:id, priority:val })
            .done(function(r){
                $inp.css('border-color', r.success ? '#22c55e' : '#ef4444');
                setTimeout(function(){ $inp.css('border-color','#e2e8f0'); }, 1800);
            });
    });

    /* ── Shared AJAX table loader ── */
    function fccLoadTable(url, updateChip) {
        var $content = $('#fcc-foods-content');
        $content.css({opacity:'0.45', pointerEvents:'none'});
        fetch(url, {credentials:'same-origin'})
            .then(function(r){ return r.text(); })
            .then(function(html){
                var doc   = (new DOMParser()).parseFromString(html, 'text/html');
                var fresh = doc.getElementById('fcc-foods-content');
                if (fresh) $('#fcc-foods-content').replaceWith(fresh.outerHTML);
                // Update active chip if a category tab was clicked
                if (updateChip !== undefined) {
                    $('.fcc-chip').removeClass('fcc-chip-active');
                    if (updateChip) $(updateChip).addClass('fcc-chip-active');
                    else $('.fcc-chip[href*="page=fcc-foods"]:not([href*="cat="])').first().addClass('fcc-chip-active');
                }
                history.pushState({fccPage:url}, '', url);
                $('html,body').animate({scrollTop:($('#fcc-foods-content').offset()||{top:0}).top - 30}, 180);
            });
    }

    /* ── Category chip clicks ── */
    $(document).on('click', '.fcc-chip', function(e){
        e.preventDefault();
        fccLoadTable($(this).attr('href'), this);
    });

    /* ── Pagination clicks ── */
    $(document).on('click', '.fcc-pager-btn:not(.disabled), .fcc-pager-page', function(e){
        e.preventDefault();
        fccLoadTable($(this).attr('href'));
    });

    /* ── Browser back/forward ── */
    window.addEventListener('popstate', function(e){
        if (e.state && e.state.fccPage) {
            var $content = $('#fcc-foods-content');
            $content.css({opacity:'0.45', pointerEvents:'none'});
            fetch(e.state.fccPage, {credentials:'same-origin'})
                .then(function(r){ return r.text(); })
                .then(function(html){
                    var doc = (new DOMParser()).parseFromString(html, 'text/html');
                    var fresh = doc.getElementById('fcc-foods-content');
                    if (fresh) $('#fcc-foods-content').replaceWith(fresh.outerHTML);
                });
        }
    });
})(jQuery);
</script>
        <?php
    }

    // ── AJAX: Update Sort Priority (inline table edit) ────────────────────────
    public function ajaxUpdateSortPriority() {
        check_ajax_referer( 'fcc_admin_nonce', 'nonce' );
        if ( ! current_user_can('manage_options') ) wp_send_json_error('Forbidden');
        global $wpdb;
        $id  = intval( $_POST['id']       ?? 0 );
        $val = intval( $_POST['priority'] ?? 0 );
        if ( ! $id ) wp_send_json_error('Invalid ID');
        $wpdb->update( $wpdb->prefix . 'fcc_foods', [ 'sort_priority' => $val ], [ 'id' => $id ], [ '%d' ], [ '%d' ] );
        wp_send_json_success();
    }

    // ── Admin: Save Food ──────────────────────────────────────────────────────
    public function adminSaveFood() {
        if ( ! current_user_can('manage_options') ) wp_die('Forbidden');
        check_admin_referer('fcc_save_food');
        global $wpdb;
        $tbl     = $wpdb->prefix . 'fcc_foods';
        $food_id = intval( $_POST['food_id'] ?? 0 );
        $data    = [
            'name'       => sanitize_text_field(   $_POST['name']       ?? '' ),
            'category'   => sanitize_text_field(   $_POST['category']   ?? '' ),
            'calories'   => floatval(              $_POST['calories']   ?? 0  ),
            'protein'    => floatval(              $_POST['protein']    ?? 0  ),
            'carbs'      => floatval(              $_POST['carbs']      ?? 0  ),
            'fat'        => floatval(              $_POST['fat']        ?? 0  ),
            'fiber'      => floatval(              $_POST['fiber']      ?? 0  ),
            'sugar'      => floatval(              $_POST['sugar']      ?? 0  ),
            'omega3'     => floatval(              $_POST['omega3']     ?? 0  ),
            'mercury'    => sanitize_text_field(   $_POST['mercury']    ?? 'low'   ),
            'allergens'  => sanitize_text_field(   $_POST['allergens']  ?? ''      ),
            'eco_rating' => sanitize_text_field(   $_POST['eco_rating'] ?? 'ok'    ),
            'eco_source' => sanitize_text_field(   $_POST['eco_source'] ?? 'wild'  ),
            'season'     => sanitize_text_field(   $_POST['season']     ?? 'All year' ),
            'health_tip' => sanitize_textarea_field( $_POST['health_tip'] ?? '' ),
            'sort_order'    => intval( $_POST['sort_order']    ?? 0 ),
            'sort_priority' => intval( $_POST['sort_priority'] ?? 0 ),
        ];
        $fmt = ['%s','%s','%f','%f','%f','%f','%f','%f','%f','%s','%s','%s','%s','%s','%s','%d','%d'];
        if ( $food_id ) {
            // Capture old name before overwriting so we can sync analytics
            $old_name = $wpdb->get_var( $wpdb->prepare( "SELECT name FROM $tbl WHERE id=%d", $food_id ) );
            $result   = $wpdb->update( $tbl, $data, ['id'=>$food_id], $fmt, ['%d'] );
            $new_name = $data['name'];
            if ( $result !== false && $old_name && $old_name !== $new_name ) {
                $anal  = $wpdb->prefix . 'fcc_analytics';
                $daily = $wpdb->prefix . 'fcc_analytics_daily';
                $wpdb->update( $anal,  [ 'food_name' => $new_name ], [ 'food_name' => $old_name ], [ '%s' ], [ '%s' ] );
                $wpdb->update( $daily, [ 'food_name' => $new_name ], [ 'food_name' => $old_name ], [ '%s' ], [ '%s' ] );
                delete_transient( 'fcc_top_trending' );
            }
        } else {
            $result = $wpdb->insert( $tbl, $data, $fmt );
        }
        $msg = ( $result !== false ) ? 'saved' : 'error';
        wp_redirect( admin_url('admin.php?page=fcc-foods&msg=' . $msg) );
        exit;
    }

    // ── Admin: Delete Food ────────────────────────────────────────────────────
    public function adminDeleteFood() {
        if ( ! current_user_can('manage_options') ) wp_die('Forbidden');
        $food_id = intval( $_GET['food_id'] ?? 0 );
        check_admin_referer( 'fcc_delete_food_' . $food_id );
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'fcc_foods', ['id' => $food_id], ['%d'] );
        wp_redirect( admin_url('admin.php?page=fcc-foods&msg=deleted') );
        exit;
    }

    // ── Admin: Seed Foods (force load default data) ──────────────────────────
    public function adminSeedFoods() {
        if ( ! current_user_can('manage_options') ) wp_die('Forbidden');
        check_admin_referer('fcc_seed_foods');
        global $wpdb;
        $tbl = $wpdb->prefix . 'fcc_foods';
        $cs  = $wpdb->get_charset_collate();
        // Guarantee table exists before seeding
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( "CREATE TABLE $tbl (
            id         mediumint(9)  NOT NULL AUTO_INCREMENT,
            name       varchar(100)  NOT NULL DEFAULT '',
            category   varchar(50)   NOT NULL DEFAULT '',
            calories   decimal(7,2)  DEFAULT 0,
            protein    decimal(6,2)  DEFAULT 0,
            carbs      decimal(6,2)  DEFAULT 0,
            fat        decimal(6,2)  DEFAULT 0,
            fiber      decimal(6,2)  DEFAULT 0,
            sugar      decimal(6,2)  DEFAULT 0,
            omega3     decimal(6,2)  DEFAULT 0,
            mercury    varchar(20)   DEFAULT 'low',
            allergens  varchar(100)  DEFAULT '',
            eco_rating varchar(10)   DEFAULT 'ok',
            eco_source varchar(10)   DEFAULT 'wild',
            season     varchar(50)   DEFAULT 'All year',
            health_tip text,
            sort_order     int        DEFAULT 0,
            sort_priority  int        DEFAULT 0,
            PRIMARY KEY (id)
        ) $cs;" );
        $this->seedFoodsTable();
        $this->foodCache = null;
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $tbl" );
        if ( $count > 0 ) {
            update_option( 'fcc_foods_seeded', 1 );
            wp_redirect( admin_url('admin.php?page=fcc-foods&msg=seeded_' . $count) );
        } else {
            $err = urlencode( $wpdb->last_error ?: 'unknown error' );
            wp_redirect( admin_url('admin.php?page=fcc-foods&msg=seed_failed&dberr=' . $err) );
        }
        exit;
    }

    // ── Admin: Export Foods CSV ───────────────────────────────────────────────
    public function adminExportFoods() {
        if ( ! current_user_can('manage_options') ) wp_die('Forbidden');
        check_admin_referer('fcc_export_foods');
        global $wpdb;
        $tbl   = $wpdb->prefix . 'fcc_foods';
        $foods = $wpdb->get_results( "SELECT * FROM $tbl ORDER BY sort_order ASC, id ASC", ARRAY_A ) ?: [];
        $cols  = ['name','category','calories','protein','carbs','fat','fiber','sugar','omega3','mercury','allergens','eco_rating','eco_source','season','health_tip','sort_order'];
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="seafood-database-' . date('Y-m-d') . '.csv"');
        header('Pragma: no-cache');
        $out = fopen('php://output', 'w');
        fputcsv($out, $cols);
        foreach ( $foods as $f ) {
            $row = array_map( function($c) use ($f) { return $f[$c] ?? ''; }, $cols );
            fputcsv($out, $row);
        }
        fclose($out);
        exit;
    }

    // ── Admin: Import Foods CSV ───────────────────────────────────────────────
    public function adminImportFoods() {
        if ( ! current_user_can('manage_options') ) wp_die('Forbidden');
        check_admin_referer('fcc_import_foods');
        if ( empty($_FILES['fcc_csv']['tmp_name']) || ! is_uploaded_file($_FILES['fcc_csv']['tmp_name']) ) {
            wp_redirect( admin_url('admin.php?page=fcc-foods&msg=imp_err') );
            exit;
        }
        global $wpdb;
        $tbl  = $wpdb->prefix . 'fcc_foods';
        $file = fopen( $_FILES['fcc_csv']['tmp_name'], 'r' );
        $raw_header = fgetcsv($file);
        if ( ! $raw_header ) {
            fclose($file);
            wp_redirect( admin_url('admin.php?page=fcc-foods&msg=imp_err') );
            exit;
        }
        $header   = array_map('strtolower', array_map('trim', $raw_header));
        $hcount   = count($header);
        $fmt      = ['%s','%s','%f','%f','%f','%f','%f','%f','%f','%s','%s','%s','%s','%s','%s','%d'];
        $inserted = 0;
        $updated  = 0;
        while ( ($raw = fgetcsv($file)) !== false ) {
            $raw    = array_pad( array_slice($raw, 0, $hcount), $hcount, '' );
            $mapped = array_combine($header, $raw);
            if ( empty($mapped['name']) ) continue;
            $data = [
                'name'       => sanitize_text_field(     $mapped['name']       ?? '' ),
                'category'   => sanitize_text_field(     $mapped['category']   ?? '' ),
                'calories'   => floatval(                $mapped['calories']   ?? 0  ),
                'protein'    => floatval(                $mapped['protein']    ?? 0  ),
                'carbs'      => floatval(                $mapped['carbs']      ?? 0  ),
                'fat'        => floatval(                $mapped['fat']        ?? 0  ),
                'fiber'      => floatval(                $mapped['fiber']      ?? 0  ),
                'sugar'      => floatval(                $mapped['sugar']      ?? 0  ),
                'omega3'     => floatval(                $mapped['omega3']     ?? 0  ),
                'mercury'    => sanitize_text_field(     $mapped['mercury']    ?? 'low'      ),
                'allergens'  => sanitize_text_field(     $mapped['allergens']  ?? ''         ),
                'eco_rating' => sanitize_text_field(     $mapped['eco_rating'] ?? 'ok'       ),
                'eco_source' => sanitize_text_field(     $mapped['eco_source'] ?? 'wild'     ),
                'season'     => sanitize_text_field(     $mapped['season']     ?? 'All year' ),
                'health_tip' => sanitize_textarea_field( $mapped['health_tip'] ?? ''         ),
                'sort_order' => intval(                  $mapped['sort_order'] ?? 0          ),
            ];
            $exists = $wpdb->get_var( $wpdb->prepare("SELECT id FROM $tbl WHERE name=%s", $data['name']) );
            if ( $exists ) {
                $wpdb->update( $tbl, $data, ['id' => (int)$exists], $fmt, ['%d'] );
                $updated++;
            } else {
                $wpdb->insert( $tbl, $data, $fmt );
                $inserted++;
            }
        }
        fclose($file);
        $this->foodCache = null;
        wp_redirect( admin_url('admin.php?page=fcc-foods&msg=imported_' . $inserted . '_' . $updated) );
        exit;
    }

} // end class

register_activation_hook( __FILE__, [ 'SeafoodCalorieCalculator', 'activate' ] );
SeafoodCalorieCalculator::getInstance();
