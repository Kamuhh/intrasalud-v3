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
    'male' => 'Masculino',
    'm' => 'Masculino',
    'h' => 'Masculino',
    '1' => 'Masculino',
    'female' => 'Femenino',
    'f' => 'Femenino',
    'mujer' => 'Femenino',
    '2' => 'Femenino',
    'other' => 'otro',
    'o' => 'otro',
    '3' => 'otro',
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
?>

<div class="kc-modal kc-modal-summary" role="dialog" aria-modal="true"
    data-patient-email="<?= esc_attr($patient['email'] ?? '') ?>"
    data-encounter-id="<?= isset($encounter['id']) ? esc_attr($encounter['id']) : '' ?>">
    <div class="kc-modal__dialog">
        <div class="kc-modal__header">
            <h3>Resumen de la atención</h3>
            <button type="button" class="kc-modal__close js-kc-summary-close" aria-label="Cerrar">×</button>
        </div>

        <div class="kc-modal__body">
            <section class="kc-card">
                <div class="kc-card__header">Detalles del paciente</div>
                <div class="kc-card__body">
                    <div class="kc-grid kc-grid-3">
                        <div><strong>Nombre:</strong> <span
                                id="kc-sum-name"><?= esc_html($patient['name'] ?? '') ?></span></div>
                        <div><strong>C.I.:</strong> <span id="kc-sum-ci"><?= esc_html($patient['dni'] ?? '') ?></span>
                        </div>
                        <div><strong>Correo:</strong> <span
                                id="kc-sum-email"><?= esc_html($patient['email'] ?? '') ?></span></div>
                        <div><strong>Género:</strong> <span id="kc-sum-gender"><?= esc_html($genderEs) ?></span></div>
                        <div><strong>Fecha de nacimiento:</strong> <span id="kc-sum-dob"
                                class="kc-nowrap"><?= esc_html($dobOut) ?></span></div>
                    </div>
                </div>
            </section>

            <section class="kc-card">
                <div class="kc-card__header">Detalles de la consulta</div>
                <div class="kc-card__body">
                    <div class="kc-grid kc-grid-3">
                        <div><strong>Fecha:</strong>
                            <?= esc_html($encounter['encounter_date'] ?? $encounter['date'] ?? '') ?></div>
                        <div><strong>Clínica:</strong> <?= esc_html($clinic['name'] ?? '') ?></div>
                        <div><strong>Doctor:</strong> <?= esc_html($doctor['name'] ?? '') ?></div>
                        <div class="kc-grid-span-3"><strong>Descripción:</strong>
                            <?= esc_html($encounter['description'] ?? '') ?>
                        </div>
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

            <!-- INDICACIONES (NOTES) — usa $orders -->
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


            <!-- ÓRDENES CLÍNICAS (OBSERVATIONS) — usa $indications -->
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