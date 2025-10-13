<?php
/**
 * Encounter Summary Helpers
 * -----------------------------------------
 * Funciones utilitarias para armar el resumen:
 * - Diagnósticos  (problem)
 * - Órdenes       (observation / clinical_observations)
 * - Indicaciones  (note / notes)
 * - Recetas       (JSON en la tabla de encuentros)
 *
 * Robusto a instalaciones con tablas legacy y sin columna `status`.
 */

if (!function_exists('kc__db_table_exists')) {
    function kc__db_table_exists($table) {
        global $wpdb;
        $like = $wpdb->esc_like($table);
        return (bool) $wpdb->get_var( $wpdb->prepare("SHOW TABLES LIKE %s", $like) );
    }
}

if (!function_exists('kc__db_columns')) {
    function kc__db_columns($table) {
        global $wpdb;
        $cols = $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);
        return is_array($cols) ? $cols : [];
    }
}

/* =========================
 * Entidades base por ID
 * ========================= */
if (!function_exists('kc_get_encounter_by_id')) {
    function kc_get_encounter_by_id($id){
        global $wpdb;
        $table = $wpdb->prefix . 'kc_patient_encounters';
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", (int)$id),
            ARRAY_A
        );
        return $row ?: [];
    }
}

/* ============================================================
 * NUEVO: resolver C.I. (dni) desde varias claves/meta/fallback
 * ============================================================ */
if (!function_exists('kc_get_patient_document')) {
    function kc_get_patient_document($user_id) {
        $user_id = (int)$user_id;

        // 1) Intentar desde basic_data (JSON)
        $basic = json_decode(get_user_meta($user_id, 'basic_data', true), true);
        $candidates = [];
        if (is_array($basic)) {
            $candidates[] = $basic['dni']      ?? null;
            $candidates[] = $basic['ci']       ?? null;
            $candidates[] = $basic['cedula']   ?? null;
            $candidates[] = $basic['document'] ?? null;
        }

        // 2) Metadatos sueltos
        $candidates[] = get_user_meta($user_id, 'dni', true);
        $candidates[] = get_user_meta($user_id, 'ci', true);
        $candidates[] = get_user_meta($user_id, 'cedula', true);
        $candidates[] = get_user_meta($user_id, 'document', true);

        foreach ($candidates as $v) {
            $v = is_string($v) ? trim($v) : '';
            if ($v !== '') return $v;
        }

        // 3) Fallback final: user_login (opcional)
        $u = get_userdata($user_id);
        if ($u && !empty($u->user_login)) {
            return (string)$u->user_login;
        }
        return '';
    }
}

if (!function_exists('kc_get_patient_by_id')) {
    function kc_get_patient_by_id($id){
        $id   = (int)$id;
        $user = get_userdata($id);
        if(!$user){ return []; }

        $basic = json_decode(get_user_meta($id, 'basic_data', true), true) ?: [];

        // C.I. resuelta con helper (dni/ci/cedula/document -> meta suelto -> user_login)
        $dni = kc_get_patient_document($id);

        return [
            'id'     => $id,
            'name'   => $user->display_name,
            'email'  => $user->user_email,
            'gender' => $basic['gender'] ?? '',
            'dob'    => $basic['dob'] ?? '',
            'dni'    => $dni,
        ];
    }
}

if (!function_exists('kc_get_doctor_by_id')) {
    function kc_get_doctor_by_id($id){
        // Para nuestro caso, estructura igual que paciente (display_name, email, basic_data)
        return kc_get_patient_by_id($id);
    }
}

if (!function_exists('kc_get_clinic_by_id')) {
    function kc_get_clinic_by_id($id){
        global $wpdb;
        $table = $wpdb->prefix . 'kc_clinics';
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", (int)$id),
            ARRAY_A
        );
        return $row ?: [];
    }
}

/* ============================================
 * Lector genérico de medical history / problems
 * ============================================ */
if (!function_exists('kc__get_encounter_items')) {
    /**
     * Devuelve filas normalizadas ['title'=>..., 'note'=>...]
     * Buscando en kc_medical_history (si existe) y, si no, en kc_medical_problems (legacy).
     * @param int   $encounter_id
     * @param array $types lista de tipos a incluir (e.g. ['observation','clinical_observations'])
     * @return array
     */
    function kc__get_encounter_items($encounter_id, array $types){
        global $wpdb;

        $tbl_history  = $wpdb->prefix . 'kc_medical_history';
        $tbl_problems = $wpdb->prefix . 'kc_medical_problems';

        $table = kc__db_table_exists($tbl_history) ? $tbl_history : $tbl_problems;

        $cols      = kc__db_columns($table);
        $hasStatus = in_array('status', $cols, true);
        $hasNote   = in_array('note',   $cols, true);

        if (empty($types)) { $types = ['observation', 'clinical_observations']; }

        // SELECT dinámico
        $select = 'title';
        if ($hasNote) { $select .= ', note'; }

        // placeholders para el IN(...)
        $ph = implode(',', array_fill(0, count($types), '%s'));

        $where = "encounter_id = %d AND type IN ($ph)";
        if ($hasStatus) {
            $where .= " AND (status = 1 OR status = '1' OR status = 'Active' OR status IS NULL)";
        }

        $sql     = "SELECT {$select} FROM {$table} WHERE {$where} ORDER BY id ASC";
        $params  = array_merge([(int)$encounter_id], $types);
        $prepared = call_user_func_array([$wpdb,'prepare'], array_merge([$sql], $params));

        $rows = $wpdb->get_results($prepared, ARRAY_A) ?: [];

        // Normalizar
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'title' => (string)($r['title'] ?? ''),
                'note'  => $hasNote ? (string)($r['note'] ?? '') : '',
            ];
        }
        return $out;
    }
}

/* =========================
 * Wrappers semánticos
 * ========================= */
if (!function_exists('kc_get_encounter_problems')) {
    // Diagnósticos (en tu BD están como type='problem')
    function kc_get_encounter_problems($encounter_id){
        return kc__get_encounter_items((int)$encounter_id, ['problem','clinical_problems']);
    }
}

if (!function_exists('kc_get_encounter_orders')) {
    // ÓRDENES CLÍNICAS = Observations
    function kc_get_encounter_orders($encounter_id){
        $list = kc__get_encounter_items((int)$encounter_id, ['observation','clinical_observations']);

        // Fallback a la columna antigua del encuentro si no hay filas
        if (empty($list)) {
            $enc = kc_get_encounter_by_id($encounter_id);
            $legacy = $enc['observations'] ?? $enc['observation'] ?? ($enc['clinical_observations'] ?? '');
            if (!empty($legacy)) {
                $list = [[ 'title' => (string)$legacy, 'note' => '' ]];
            }
        }
        return $list;
    }
}

if (!function_exists('kc_get_encounter_indications')) {
    // INDICACIONES = Notes
    function kc_get_encounter_indications($encounter_id){
        $list = kc__get_encounter_items((int)$encounter_id, ['note','notes']);

        // Fallback a la columna antigua del encuentro si no hay filas
        if (empty($list)) {
            $enc = kc_get_encounter_by_id($encounter_id);
            $legacy = $enc['notes'] ?? $enc['note'] ?? '';
            if (!empty($legacy)) {
                $list = [[ 'title' => (string)$legacy, 'note' => '' ]];
            }
        }
        return $list;
    }
}

/* =============
 * Prescripciones
 * =============
 */
if (!function_exists('kc_get_encounter_prescriptions')) {
    function kc_get_encounter_prescriptions($encounter_id){
        $encounter_id = (int)$encounter_id;
        $out = [];

        // 1) JSON en la fila del encuentro (campo 'prescription')
        $enc = kc_get_encounter_by_id($encounter_id);
        if (!empty($enc['prescription'])) {
            $decoded = json_decode($enc['prescription'], true);
            if (is_array($decoded)) {
                foreach ($decoded as $p) {
                    $name = trim((string)($p['name'] ?? $p['medicine'] ?? $p['medicine_name'] ?? ''));
                    $freq = trim((string)($p['frequency'] ?? $p['dose_frequency'] ?? $p['dosage'] ?? ''));
                    $dur  = trim((string)($p['duration'] ?? $p['days'] ?? $p['period'] ?? ''));
                    if ($name !== '' || $freq !== '' || $dur !== '') {
                        $out[] = ['name' => $name, 'frequency' => $freq, 'duration' => $dur];
                    }
                }
            }
        }

        if (!empty($out)) {
            return $out;
        }

        // 2) Fallback a tablas típicas de recetas
        global $wpdb;
        $prefix = $wpdb->prefix;

        $tryTables = [
            $prefix . 'kc_prescription_medicine',
            $prefix . 'kc_prescription_medicines',
            $prefix . 'kc_prescriptions',
            $prefix . 'kc_prescription',
        ];

        // posibles nombres de columnas
        $idCols    = ['encounter_id','patient_encounter_id','appointment_id','visit_id'];
        $nameCols  = ['name','medicine','medicine_name','title'];
        $freqCols  = ['frequency','dose_frequency','dosage','dose'];
        $durCols   = ['duration','days','period'];

        foreach ($tryTables as $tbl) {
            if (!kc__db_table_exists($tbl)) { continue; }

            $cols = kc__db_columns($tbl);
            if (empty($cols)) { continue; }

            // columna del encuentro
            $encCol = '';
            foreach ($idCols as $c) { if (in_array($c, $cols, true)) { $encCol = $c; break; } }
            if ($encCol === '') { continue; }

            // columnas de datos
            $selName = '';
            foreach ($nameCols as $c) { if (in_array($c, $cols, true)) { $selName = $c; break; } }
            $selFreq = '';
            foreach ($freqCols as $c) { if (in_array($c, $cols, true)) { $selFreq = $c; break; } }
            $selDur  = '';
            foreach ($durCols as $c) { if (in_array($c, $cols, true)) { $selDur  = $c; break; } }

            $selectParts = [];
            if ($selName) { $selectParts[] = $selName; }
            if ($selFreq) { $selectParts[] = $selFreq; }
            if ($selDur)  { $selectParts[] = $selDur; }
            if (empty($selectParts)) { continue; }

            $sql  = "SELECT " . implode(',', $selectParts) . " FROM {$tbl} WHERE {$encCol} = %d ORDER BY id ASC";
            $rows = $wpdb->get_results($wpdb->prepare($sql, $encounter_id), ARRAY_A) ?: [];

            foreach ($rows as $r) {
                $out[] = [
                    'name'      => $selName && isset($r[$selName]) ? (string)$r[$selName] : '',
                    'frequency' => $selFreq && isset($r[$selFreq]) ? (string)$r[$selFreq] : '',
                    'duration'  => $selDur  && isset($r[$selDur])  ? (string)$r[$selDur]  : '',
                ];
            }

            if (!empty($out)) {
                break; // ya encontramos recetas en esta tabla
            }
        }

        return $out;
    }
}


/* =================================
 * Texto plano para enviar por email
 * ================================= */
if (!function_exists('kc_build_encounter_summary_text')) {
    function kc_build_encounter_summary_text($encounter_id){
        $e = kc_get_encounter_by_id($encounter_id);
        $p = kc_get_patient_by_id($e['patient_id'] ?? 0);
        $lines = [];
        $lines[] = 'Resumen de atención';
        $lines[] = 'Paciente: ' . ($p['name'] ?? '');
        $lines[] = 'Fecha: ' . ($e['encounter_date'] ?? $e['date'] ?? '');

        $diagnoses = kc_get_encounter_problems($encounter_id);
        if ($diagnoses) {
            $lines[] = 'Diagnósticos:';
            foreach ($diagnoses as $d) { $lines[] = '- ' . ($d['title'] ?? ''); }
        }

        $indications = kc_get_encounter_indications($encounter_id);
        if ($indications) {
            $lines[] = 'Indicaciones:';
            foreach ($indications as $i) { $lines[] = '- ' . ($i['title'] ?? ''); }
        }

        $orders = kc_get_encounter_orders($encounter_id);
        if ($orders) {
            $lines[] = 'Órdenes clínicas:';
            foreach ($orders as $o) {
                $line = '- ' . ($o['title'] ?? '');
                if (!empty($o['note'])) { $line .= ' — ' . $o['note']; }
                $lines[] = $line;
            }
        }

        $prescriptions = kc_get_encounter_prescriptions($encounter_id);
        if ($prescriptions) {
            $lines[] = 'Receta:';
            foreach ($prescriptions as $pr) {
                $lines[] = '- ' . trim(($pr['name'] ?? '') . ' ' . ($pr['frequency'] ?? '') . ' ' . ($pr['duration'] ?? ''));
            }
        }
        return implode("\n", $lines);
    }
}

/* ==========================
 * HTML del modal (si lo usas)
 * ========================== */
if (!function_exists('kc_render_encounter_summary_html')) {
    function kc_render_encounter_summary_html($encounter_id){
        $encounter     = kc_get_encounter_by_id($encounter_id);
        $patient       = kc_get_patient_by_id($encounter['patient_id'] ?? 0);
        $doctor        = kc_get_doctor_by_id($encounter['doctor_id'] ?? 0);
        $clinic        = kc_get_clinic_by_id($encounter['clinic_id'] ?? 0);
        $diagnoses     = kc_get_encounter_problems($encounter_id);
        $orders        = kc_get_encounter_orders($encounter_id);       // ÓRDENES CLÍNICAS
        $indications   = kc_get_encounter_indications($encounter_id);  // INDICACIONES
        $prescriptions = kc_get_encounter_prescriptions($encounter_id);

        ob_start();
        $base = defined('KIVI_CARE_DIR') ? KIVI_CARE_DIR : plugin_dir_path(__FILE__);
        include trailingslashit($base) . 'templates/encounter-summary-modal.php';
        return ob_get_clean();
    }
}
