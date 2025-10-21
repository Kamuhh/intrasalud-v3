<?php
/**
 * Plugin Name: KiviCare - Clinic & Patient Management System (EHR)
 * Plugin URI: https://iqonic.design
 * Description: KiviCare is an impressive clinic and patient management plugin (EHR).
 * Version:3.6.11
 * Author: iqonic
 * Text Domain: kc-lang
 * Domain Path: /languages
 * Author URI: http://iqonic.design/
 **/
use App\baseClasses\KCActivate;
use App\baseClasses\KCDeactivate;
defined( 'ABSPATH' ) or die( 'Something went wrong' );

// ── Definir constantes si no existen (evita E_ERROR en entornos donde no las trae el core del plugin)
if ( ! defined('KC_PLUGIN_FILE') ) define('KC_PLUGIN_FILE', __FILE__);
if ( ! defined('KC_PLUGIN_DIR') )  define('KC_PLUGIN_DIR', plugin_dir_path(__FILE__));
if ( ! defined('KC_PLUGIN_URL') )  define('KC_PLUGIN_URL', plugin_dir_url(__FILE__));

// ── Encuentro: Resumen de atención – assets + fallback AJAX
add_action('admin_enqueue_scripts', function () {
    // Mientras depuramos, encolamos siempre en admin. Luego filtramos por screen/page.
    $js  = KC_PLUGIN_DIR . 'assets/js/encounter-summary.js';
    $css = KC_PLUGIN_DIR . 'assets/css/encounter-summary.css';

    if ( file_exists($js) ) {
        wp_enqueue_script(
            'kc-encounter-summary',
            KC_PLUGIN_URL . 'assets/js/encounter-summary.js',
            [],
            filemtime($js),
            true
        );
        // Expone REST y admin-ajax al JS
        wp_localize_script('kc-encounter-summary', 'kcGlobals', [
            'apiBase' => '/kc/v1',                        // el script usa REST si está disponible
            'nonce'   => wp_create_nonce('wp_rest'),
            'ajaxUrl' => admin_url('admin-ajax.php'),     // fallback por admin-ajax
            'debug'   => true,
        ]);
    }

    if ( file_exists($css) ) {
        wp_enqueue_style(
            'kc-encounter-summary',
            KC_PLUGIN_URL . 'assets/css/encounter-summary.css',
            [],
            filemtime($css)
        );
    }
}, 20);

// ── Fallback AJAX: obtener HTML del resumen
add_action('wp_ajax_kc_encounter_summary', function () {
    if ( ! is_user_logged_in() ) wp_send_json_error(['message' => 'No autorizado'], 401);

    $user = wp_get_current_user();
    $can  = user_can($user, 'kc_view_encounter_summary')
        || in_array('administrator', (array) $user->roles, true)
        || in_array('kivi_doctor',  (array) $user->roles, true)
        || in_array('kivi_receptionist', (array) $user->roles, true)
        || in_array('kivi_clinic_admin', (array) $user->roles, true);

    if ( ! $can ) wp_send_json_error(['message' => 'Permisos insuficientes'], 403);

    $encounter_id = intval($_REQUEST['encounter_id'] ?? 0);
    if ( $encounter_id <= 0 ) wp_send_json_error(['message' => 'encounter_id inválido'], 400);

    // Helpers ya existentes
    $encounter     = kc_get_encounter_by_id($encounter_id);
    $patient       = kc_get_patient_by_id($encounter['patient_id'] ?? 0);
    $doctor        = kc_get_doctor_by_id($encounter['doctor_id'] ?? 0);
    $clinic        = kc_get_clinic_by_id($encounter['clinic_id'] ?? 0);
    $diagnoses     = kc_get_encounter_problems($encounter_id);
    $orders        = kc_get_encounter_orders($encounter_id);
    $indications   = kc_get_encounter_indications($encounter_id);
    $prescriptions = kc_get_encounter_prescriptions($encounter_id);

    ob_start();
    include KC_PLUGIN_DIR . 'templates/encounter-summary-modal.php';
    $html = ob_get_clean();

    wp_send_json_success(['html' => $html]);
});

// ── Fallback AJAX: enviar por correo
add_action('wp_ajax_kc_encounter_summary_email', 'kc_encounter_summary_email_cb');

function kc_encounter_summary_email_cb() {
    if ( ! is_user_logged_in() ) {
        wp_send_json_error(['message' => 'No autorizado'], 401);
    }

    $to = isset($_POST['to']) ? sanitize_email($_POST['to']) : '';
    $encounter_id = isset($_POST['encounter_id']) ? absint($_POST['encounter_id']) : 0;

    if ( ! $to || ! is_email($to) || ! $encounter_id ) {
        wp_send_json_error(['message' => 'Parámetros inválidos'], 400);
    }

    if ( ! function_exists('kc_render_encounter_summary_html') ) {
        require_once KC_PLUGIN_DIR . 'app/helpers/encounter-summary-helpers.php';
    }
    $html = kc_render_encounter_summary_html($encounter_id);
    if ( ! $html ) {
        wp_send_json_error(['message' => 'No se pudo generar el resumen'], 500);
    }

    $body = wp_strip_all_tags($html);
    $subject = 'Resumen de la atención';
    $headers = ['Content-Type: text/plain; charset=UTF-8'];

    $ok = wp_mail($to, $subject, $body, $headers);

    if ( $ok ) {
        wp_send_json_success(['message' => 'Email enviado']);
    }
    wp_send_json_error(['message' => 'Fallo al enviar'], 500);
}

// Require once the Composer Autoload
if ( file_exists( dirname( __FILE__ ) . '/vendor/autoload.php' ) ) {
	require_once dirname( __FILE__ ) . '/vendor/autoload.php';
} else {
	die( 'Something went wrong' );
}

if (!defined('KIVI_CARE_DIR'))
{
	define('KIVI_CARE_DIR', plugin_dir_path(__FILE__));
}

if (!defined('KIVI_CARE_DIR_URI'))
{
	define('KIVI_CARE_DIR_URI', plugin_dir_url(__FILE__));
}

if (!defined('KIVI_CARE_BASE_NAME'))
{
    define('KIVI_CARE_BASE_NAME', plugin_basename(__FILE__));
}

if (!defined('KIVI_CARE_NAMESPACE'))
{
	define('KIVI_CARE_NAMESPACE', "kivi-care");
}

if (!defined('KIVI_CARE_PREFIX'))
{
	define('KIVI_CARE_PREFIX', "kiviCare_");
}

if (!defined('KIVI_CARE_VERSION'))
{
    define('KIVI_CARE_VERSION', "3.6.11");
}

// Include helper and initialization files
require_once KIVI_CARE_DIR . 'app/helpers/encounter-summary-helpers.php';
require_once KIVI_CARE_DIR . 'app/init/Capabilities.php';

/**
 * The code that runs during plugin activation
 */
register_activation_hook( __FILE__, [ KCActivate::class, 'activate'] );

/**
 * The code that runs during plugin deactivation
 */
register_deactivation_hook( __FILE__, [KCDeactivate::class, 'deActivate'] );

( new KCActivate )->init();

( new KCDeactivate() );

do_action('kivicare_activate_init');

// === Encuentro: Resumen de atención – assets + fallback AJAX ===
add_action('admin_enqueue_scripts', function () {
    // Fuerza carga mientras depuramos; luego podemos condicionar por pantalla
    wp_enqueue_script(
        'kc-encounter-summary',
        KC_PLUGIN_URL . 'assets/js/encounter-summary.js',
        [],
        filemtime(KC_PLUGIN_DIR . 'assets/js/encounter-summary.js'),
        true
    );
    wp_enqueue_style(
        'kc-encounter-summary',
        KC_PLUGIN_URL . 'assets/css/encounter-summary.css',
        [],
        filemtime(KC_PLUGIN_DIR . 'assets/css/encounter-summary.css')
    );
    wp_localize_script('kc-encounter-summary', 'kcGlobals', [
        'apiBase' => '/kc/v1',                        // si REST está activo, se usa
        'nonce'   => wp_create_nonce('wp_rest'),
        'ajaxUrl' => admin_url('admin-ajax.php'),     // fallback por admin-ajax
        'debug'   => true,
    ]);
}, 999);

// AJAX fallback: obtener HTML del resumen
add_action('wp_ajax_kc_encounter_summary', function () {
    if (!is_user_logged_in()) wp_send_json_error(['message' => 'No autorizado'], 401);

    $user = wp_get_current_user();
    $can  = user_can($user, 'kc_view_encounter_summary')
        || in_array('administrator', $user->roles, true)
        || in_array('kivi_doctor', $user->roles ?? [], true);
    if (!$can) wp_send_json_error(['message' => 'Permisos insuficientes'], 403);

    $encounter_id = intval($_REQUEST['encounter_id'] ?? 0);
    if ($encounter_id <= 0) wp_send_json_error(['message' => 'encounter_id inválido'], 400);

    // Helpers existentes
    $encounter     = kc_get_encounter_by_id($encounter_id);
    $patient       = kc_get_patient_by_id($encounter['patient_id'] ?? 0);
    $doctor        = kc_get_doctor_by_id($encounter['doctor_id'] ?? 0);
    $clinic        = kc_get_clinic_by_id($encounter['clinic_id'] ?? 0);
    $diagnoses     = kc_get_encounter_problems($encounter_id);
    $orders        = kc_get_encounter_orders($encounter_id);
    $indications   = kc_get_encounter_indications($encounter_id);
    $prescriptions = kc_get_encounter_prescriptions($encounter_id);

    ob_start();
    include KC_PLUGIN_DIR . 'templates/encounter-summary-modal.php';
    $html = ob_get_clean();

    wp_send_json_success(['html' => $html]);
});

// AJAX fallback: enviar por correo
add_action('wp_ajax_kc_encounter_summary_email', function () {
    if (!is_user_logged_in()) wp_send_json_error(['message' => 'No autorizado'], 401);

    $user = wp_get_current_user();
    $can  = user_can($user, 'kc_view_encounter_summary')
        || in_array('administrator', $user->roles, true)
        || in_array('kivi_doctor', $user->roles ?? [], true);
    if (!$can) wp_send_json_error(['message' => 'Permisos insuficientes'], 403);

    $encounter_id = intval($_REQUEST['encounter_id'] ?? 0);
    $to = sanitize_email($_REQUEST['to'] ?? '');
    if ($encounter_id <= 0 || empty($to) || !is_email($to)) {
        wp_send_json_error(['message' => 'Parámetros inválidos'], 400);
    }

    $body = kc_build_encounter_summary_text($encounter_id);
    $ok   = wp_mail($to, 'Resumen de atención', $body, ['Content-Type: text/plain; charset=UTF-8']);
    if (!$ok) wp_send_json_error(['message' => 'No se pudo enviar el correo'], 500);

    wp_send_json_success(['ok' => true]);
});
