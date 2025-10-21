<?php
function kc_register_encounter_summary_capability() {
    foreach (['administrator','kivi_doctor','kivi_receptionist','kivi_clinic_admin'] as $roleKey) {
        if ($role = get_role($roleKey)) {
            $role->add_cap('kc_view_encounter_summary');
        }
    }
}
add_action('init', 'kc_register_encounter_summary_capability');

function kc_enqueue_encounter_summary_assets($hook) {
    if (empty($_GET['page']) || $_GET['page'] !== 'patient_encounter_list') {
        return;
    }
    $js_file = KIVI_CARE_DIR . 'assets/js/encounter-summary.js';
    wp_enqueue_script(
        'kc-encounter-summary',
        KIVI_CARE_DIR_URI . 'assets/js/encounter-summary.js',
        [],
        file_exists($js_file) ? filemtime($js_file) : KIVI_CARE_VERSION,
        true
    );
    wp_localize_script('kc-encounter-summary', 'kcGlobals', [
        'apiBase' => '/kc/v1',
        'nonce'   => wp_create_nonce('wp_rest'),
    ]);
    $css_file = KIVI_CARE_DIR . 'assets/css/encounter-summary.css';
    if (file_exists($css_file)) {
        wp_enqueue_style(
            'kc-encounter-summary',
            KIVI_CARE_DIR_URI . 'assets/css/encounter-summary.css',
            [],
            filemtime($css_file)
        );
    }
}
add_action('admin_enqueue_scripts', 'kc_enqueue_encounter_summary_assets');
