<style>
    .kc-modal-summary .kc-nowrap {
        white-space: nowrap;
    }
</style>

<?php
// ==== Normalizaciones para el modal ====

// 1) Género -> español
$rawGender = strtolower(trim((string) ($patient['gender'] ?? '')));
$genderMap = [
    'male'   => 'Masculino',
    'm'      => 'Masculino',
    'h'      => 'Masculino',
    '1'      => 'Masculino',
    'female' => 'Femenino',
    'f'      => 'Femenino',
    'mujer'  => 'Femenino',
    '2'      => 'Femenino',
    'other'  => 'otro',
    'o'      => 'otro',
    '3'      => 'otro',
];
$genderEs = $genderMap[$rawGender] ?? (in_array($rawGender, ['masculino', 'femenino', 'otro']) ? $rawGender : '');

// 2) Fecha de nacimiento + edad (edad al momento del encuentro; si no hay fecha del encuentro, hoy)
$dobRaw = $patient['dob'] ?? '';
$dobOut = '';
if (!empty($dobRaw)) {
    try {
        $dob = new DateTime($dobRaw);
        $ref = !empty($encounter['encounter_date'] ?? null) ? new DateTime($encounter['encounter_date']) : new DateTime();
        $age = $dob->diff($ref)->y;
        $dobOut = $dob->format('Y-m-d') . ' (' . $age . ' años)';
    } catch (Exception $e) {
        $dobOut = (string) $dobRaw;
    }
}

// ** NUEVO: Obtener ID del doctor y sus campos personalizados **
$doctor_id = intval($doctor['id'] ?? 0);
$doctor_mpps = $doctor_cm = $doctor_ci = $doctor_specialty = '';
if ($doctor_id) {
    // MPPS y CM desde campos personalizados (IDs 7 y 8)
    if (function_exists('obtener_custom_field_doctor')) {
        $doctor_mpps = (string) obtener_custom_field_doctor($doctor_id, 7);  // MPPS
        $doctor_cm   = (string) obtener_custom_field_doctor($doctor_id, 8);  // CM
    } else {
        global $wpdb;
        $table = $wpdb->prefix . "kc_custom_fields_data";
        // MPPS
        $doctor_mpps = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT fields_data FROM $table WHERE module_type=%s AND module_id=%d AND field_id=%d",
            'doctor_module', $doctor_id, 7
        ));
        // CM
        $doctor_cm = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT fields_data FROM $table WHERE module_type=%s AND module_id=%d AND field_id=%d",
            'doctor_module', $doctor_id, 8
        ));
    }

    // Cédula (por ejemplo, usamos el meta 'nickname' como en la impresión)
    $doctor_ci = (string) get_user_meta($doctor_id, 'nickname', true);

    // Especialidad (primer elemento de 'specialties' en basic_data, si existe)
    $basic_data = json_decode((string) get_user_meta($doctor_id, 'basic_data', true), true);
    if (!empty($basic_data['specialties']) && is_array($basic_data['specialties'])) {
        $firstSpec = $basic_data['specialties'][0];
        // Tomar el label de la primera especialidad
        if (is_array($firstSpec) && !empty($firstSpec['label'])) {
            $doctor_specialty = (string) $firstSpec['label'];
        } elseif (is_string($firstSpec)) {
            $doctor_specialty = $firstSpec;
        }
    }
}
?>

<div class="kc-modal kc-modal-summary" role="dialog" aria-modal="true"
    data-patient-email="<?= esc_attr($patient['email'] ?? '') ?>"
    data-encounter-id="<?= isset($encounter['id']) ? esc_attr($encounter['id']) : '' ?>"
    data-doctor-name="<?= esc_attr($doctor['name'] ?? '') ?>"
    data-doctor-specialty="<?= esc_attr($doctor_specialty) ?>"
    data-doctor-mpps="<?= esc_attr($doctor_mpps) ?>"
    data-doctor-cm="<?= esc_attr($doctor_cm) ?>"
    data-doctor-ci="<?= esc_attr($doctor_ci) ?>"
>
    <div class="kc-modal__dialog">
        <div class="kc-modal__header">
            <h3>Resumen de atención</h3>
            <button type="button" class="kc-modal__close js-kc-summary-close" aria-label="Cerrar">×</button>
        </div>

        <div class="kc-modal__body">
            <section class="kc-card">
                <div class="kc-card__header">Detalles del paciente</div>
                <div class="kc-card__body">
                    <div class="kc-grid kc-grid-3">
                        <div><strong>Nombre:</strong> <span id="kc-sum-name"><?= esc_html($patient['name'] ?? '') ?></span></div>
                        <div><strong>C.I.:</strong> <span id="kc-sum-ci"><?= esc_html($patient['dni'] ?? '') ?></span></div>
                        <div><strong>Correo:</strong> <span id="kc-sum-email"><?= esc_html($patient['email'] ?? '') ?></span></div>
                        <div><strong>Género:</strong> <span id="kc-sum-gender"><?= esc_html($genderEs) ?></span></div>
                        <div><strong>Fecha de nacimiento:</strong> <span id="kc-sum-dob" class="kc-nowrap"><?= esc_html($dobOut) ?></span></div>
                    </div>
                </div>
            </section>

            <section class="kc-card">
                <div class="kc-card__header">Detalles de la consulta</div>
                <div class="kc-card__body">
                    <div class="kc-grid kc-grid-3">
                        <div><strong>Fecha:</strong> <?= esc_html($encounter['encounter_date'] ?? $encounter['date'] ?? '') ?></div>
                        <div><strong>Clínica:</strong> <?= esc_html($clinic['name'] ?? '') ?></div>
                        <div><strong>Doctor:</strong> <?= esc_html($doctor['name'] ?? '') ?></div>
                        <div class="kc-grid-span-3"><strong>Descripción:</strong> <?= esc_html($encounter['description'] ?? '') ?></div>
                    </div>
                </div>
            </section>

            <section class="kc-card">
                <div class="kc-card__header">Diagnóstico(s)</div>
                <div class="kc-card__body">
                    <ul class="kc-list" id="kc-sum-dx-list">
                        <?php if (!empty($diagnoses)): ?>
                            <?php foreach ($diagnoses as $d): ?>
                                <li><?= esc_html($d['title'] ?? '') ?></li>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <li>No se encontraron registros</li>
                        <?php endif; ?>
                    </ul>
                </div>
            </section>

            <!-- INDICACIONES (NOTES) — usa $indications -->
            <section class="kc-card">
                <div class="kc-card__header">Indicaciones</div>
                <div class="kc-card__body">
                    <ul class="kc-list" id="kc-sum-ind-list">
                        <?php if (!empty($indications)): ?>
                            <?php foreach ($indications as $i): ?>
                                <li><?= esc_html($i['title'] ?? '') ?></li>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <li>No se encontraron registros</li>
                        <?php endif; ?>
                    </ul>
                </div>
            </section>

            <!-- ÓRDENES CLÍNICAS (OBSERVATIONS) — usa $orders -->
            <section class="kc-card">
                <div class="kc-card__header">Órdenes clínicas</div>
                <div class="kc-card__body">
                    <ul class="kc-list" id="kc-sum-orders-list">
                        <?php if (!empty($orders)): ?>
                            <?php foreach ($orders as $o): ?>
                                <li><?= esc_html($o['title'] ?? '') ?></li>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <li>No se encontraron registros</li>
                        <?php endif; ?>
                    </ul>
                </div>
            </section>

            <section class="kc-card">
                <div class="kc-card__header">Receta médica</div>
                <div class="kc-card__body">
                    <?php if (!empty($prescriptions)): ?>
                        <table class="kc-table">
                            <thead>
                                <tr>
                                    <th>Nombre</th>
                                    <th>Frecuencia</th>
                                    <th>Duración</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($prescriptions as $p): ?>
                                    <tr>
                                        <td><?= esc_html($p['name'] ?? '') ?></td>
                                        <td><?= esc_html($p['frequency'] ?? '') ?></td>
                                        <td><?= esc_html($p['duration'] ?? '') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p class="kc-empty">No se encontró receta</p>
                    <?php endif; ?>
                </div>
            </section>
        </div>

        <div class="kc-modal__footer">
            <button type="button" class="button button-secondary js-kc-summary-email">
                <span class="dashicons dashicons-email"></span> Correo electrónico
            </button>
            <button type="button" class="button button-secondary js-kc-summary-print">
                <span class="dashicons dashicons-printer"></span> Imprimir
            </button>
            <button type="button" class="button button-primary js-kc-summary-close">
                <span class="dashicons dashicons-no"></span> Cerrar
            </button>
        </div>
    </div>
</div>
