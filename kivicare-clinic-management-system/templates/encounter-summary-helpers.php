<?php
if (!function_exists('kc_render_encounter_summary_html')) {
    function kc_render_encounter_summary_html($encounter_id) {
        if (!function_exists('kc_get_encounter_by_id')) {
            require_once KIVI_CARE_DIR . 'app/helpers/encounter-summary-helpers.php';
        }

        $encounter     = kc_get_encounter_by_id($encounter_id);
        $patient       = kc_get_patient_by_id($encounter['patient_id'] ?? 0);
        $doctor        = kc_get_doctor_by_id($encounter['doctor_id'] ?? 0);
        $clinic        = kc_get_clinic_by_id($encounter['clinic_id'] ?? 0);
        $diagnoses     = kc_get_encounter_problems($encounter_id);
        $indications   = kc_get_encounter_indications($encounter_id);
        $orders        = kc_get_encounter_orders($encounter_id);
        $prescriptions = kc_get_encounter_prescriptions($encounter_id);

        ob_start();
        include __DIR__ . '/encounter-summary-print.php';
        return ob_get_clean();
    }
}
