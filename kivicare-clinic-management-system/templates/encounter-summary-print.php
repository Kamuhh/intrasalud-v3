<?php
/**
 * Plantilla de impresión — Resumen de la atención (Carta)
 * Espera disponibles: $encounter, $patient, $doctor, $clinic,
 * $diagnoses, $indications, $orders, $prescriptions
 */

// Logo de clínica
$clinicLogo = '';
if (!empty($clinic['profile_image'])) {
    $clinicLogo = wp_get_attachment_url($clinic['profile_image']);
} elseif (!empty($clinic['logo'])) { // por si tu tabla usa otra key
    $clinicLogo = wp_get_attachment_url($clinic['logo']);
}
if (empty($clinicLogo)) {
    $clinicLogo = KIVI_CARE_DIR_URI . 'assets/images/kc-demo-img.png';
}

// Meta del doctor (firma + credenciales)
$doctor_id     = (int)($doctor['id'] ?? 0);
$doc_meta      = json_decode(get_user_meta($doctor_id, 'basic_data', true), true) ?: [];
$signature_id  = get_user_meta($doctor_id, 'doctor_signature', true);
$signature_url = $signature_id ? wp_get_attachment_url($signature_id) : '';

$doc_name       = strtoupper($doctor['name'] ?? '');
$doc_specialty  = $doc_meta['specialization'] ?? ($doc_meta['speciality'] ?? '');
$mpps           = $doc_meta['mpps'] ?? ($doc_meta['mpps_number'] ?? '');
$cm             = $doc_meta['registration_no'] ?? ($doc_meta['cm'] ?? '');
$ci             = $doctor['dni'] ?? ($doc_meta['dni'] ?? '');

// Utilidades
$fmt = function($v){ return esc_html(trim((string)$v)); };
$printDate = $fmt($encounter['encounter_date'] ?? $encounter['date'] ?? current_time('Y-m-d'));
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Resumen de la atención</title>
<style>
  @page { size: Letter; margin: 18mm 16mm; }
  * { box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Inter, Arial, sans-serif; color:#111827; }
  h1,h2,h3 { margin:0 0 8px 0; }
  ul { margin: 6px 0 0 18px; padding:0; }
  li { margin: 4px 0; }
  .muted { color:#6b7280; }
  .card { border:1px solid #e5e7eb; border-radius:10px; padding:14px; margin-bottom:12px; }
  .card h3 { font-size:14px; color:#0f172a; border-bottom:1px solid #e5e7eb; padding-bottom:6px; }
  .header { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:12px; padding-bottom:10px; border-bottom:2px solid #e5e7eb; }
  .header .left { display:flex; gap:14px; align-items:center; }
  .logo { height:42px; }
  .clinic-name { font-weight:700; font-size:14px; }
  .grid-3 { display:grid; grid-template-columns: repeat(3, 1fr); gap:10px; margin-top:8px; }
  .kv { display:flex; gap:6px; }
  .kv b { color:#111827; }
  .page-break { page-break-before: always; }
  /* Footer médico en la 2da página */
  .doctor-footer { margin-top:40mm; text-align:center; }
  .doctor-footer .sig { height:52px; margin-bottom:6px; }
  .doctor-footer .name { font-weight:700; }
  .doctor-footer .line { font-size:11px; color:#374151; }
  /* Tabla mini para receta */
  table.kc { width:100%; border-collapse: collapse; margin-top:6px; }
  table.kc th, table.kc td { border:1px solid #e5e7eb; padding:8px; font-size:12px; text-align:left; }
  table.kc th { background:#f9fafb; }
</style>
</head>
<body>

  <!-- ENCABEZADO -->
  <div class="header">
    <div class="left">
      <img class="logo" src="<?php echo esc_url($clinicLogo); ?>" alt="logo">
      <div>
        <div class="clinic-name"><?php echo $fmt($clinic['name'] ?? ''); ?></div>
        <div class="muted" style="font-size:11px;">
          <?php
            $addr = trim(($clinic['address'] ?? '') . ', ' . ($clinic['city'] ?? '') . ', ' . ($clinic['country'] ?? ''));
            echo $fmt($addr);
          ?>
        </div>
      </div>
    </div>
    <div style="text-align:right; font-size:12px;">
      <div><b>Fecha:</b> <?php echo $printDate; ?></div>
    </div>
  </div>

  <!-- DETALLES DEL PACIENTE -->
  <div class="card">
    <h3>Detalles del paciente</h3>
    <div class="grid-3">
      <div class="kv"><b>Nombre:</b> <span><?php echo $fmt($patient['name'] ?? ''); ?></span></div>
      <div class="kv"><b>C.I.:</b> <span><?php echo $fmt($patient['dni'] ?? ''); ?></span></div>
      <div class="kv"><b>Correo:</b> <span><?php echo $fmt($patient['email'] ?? ''); ?></span></div>
      <div class="kv"><b>Género:</b> <span><?php echo $fmt($patient['gender'] ?? ''); ?></span></div>
      <div class="kv"><b>Fecha de nacimiento:</b> <span><?php echo $fmt($patient['dob'] ?? ''); ?></span></div>
    </div>
  </div>

  <!-- DETALLES DE LA CONSULTA -->
  <div class="card">
    <h3>Detalles de la consulta</h3>
    <div class="grid-3">
      <div class="kv"><b>Fecha:</b> <span><?php echo $printDate; ?></span></div>
      <div class="kv"><b>Clínica:</b> <span><?php echo $fmt($clinic['name'] ?? ''); ?></span></div>
      <div class="kv"><b>Doctor:</b> <span><?php echo $fmt($doctor['name'] ?? ''); ?></span></div>
      <div class="kv" style="grid-column:1 / -1;"><b>Descripción:</b> <span><?php echo $fmt($encounter['description'] ?? ''); ?></span></div>
    </div>
  </div>

  <!-- DIAGNÓSTICOS -->
  <div class="card">
    <h3>Diagnóstico(s)</h3>
    <?php if (!empty($diagnoses)) : ?>
      <ul>
        <?php foreach ($diagnoses as $d): ?>
          <li><?php echo $fmt($d['title'] ?? ''); ?></li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <div class="muted">No se encontraron registros</div>
    <?php endif; ?>
  </div>

  <!-- INDICACIONES -->
  <div class="card">
    <h3>Indicaciones</h3>
    <?php if (!empty($indications)) : ?>
      <ul>
        <?php foreach ($indications as $i): ?>
          <li><?php echo $fmt($i['title'] ?? ''); ?></li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <div class="muted">No se encontraron registros</div>
    <?php endif; ?>
  </div>

  <!-- RECETA -->
  <div class="card">
    <h3>Receta médica</h3>
    <?php if (!empty($prescriptions)) : ?>
      <table class="kc">
        <thead>
        <tr><th>Nombre</th><th>Frecuencia</th><th>Duración</th></tr>
        </thead>
        <tbody>
        <?php foreach ($prescriptions as $p): ?>
          <tr>
            <td><?php echo $fmt($p['name'] ?? ''); ?></td>
            <td><?php echo $fmt($p['frequency'] ?? ''); ?></td>
            <td><?php echo $fmt($p['duration'] ?? ''); ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?>
      <div class="muted">No se encontró receta</div>
    <?php endif; ?>
  </div>

  <!-- PÁGINA 2: ÓRDENES CLÍNICAS + PIE DEL MÉDICO -->
  <div class="page-break"></div>

  <div class="card">
    <h3>Órdenes clínicas</h3>
    <?php if (!empty($orders)) : ?>
      <ul>
        <?php foreach ($orders as $o): ?>
          <li>
            <?php echo $fmt($o['title'] ?? ''); ?>
            <?php if (!empty($o['note'])): ?>
              — <span class="muted"><?php echo $fmt($o['note']); ?></span>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <div class="muted">No se encontraron registros</div>
    <?php endif; ?>
  </div>

  <div class="doctor-footer">
    <?php if ($signature_url): ?>
      <img class="sig" src="<?php echo esc_url($signature_url); ?>" alt="firma">
    <?php endif; ?>
    <div class="name"><?php echo $fmt($doc_name); ?></div>
    <?php if (!empty($doc_specialty)): ?>
      <div class="line"><?php echo $fmt($doc_specialty); ?></div>
    <?php endif; ?>
    <div class="line">
      <?php
        $cred = [];
        if ($mpps) { $cred[] = 'MPPS: ' . $mpps; }
        if ($cm)   { $cred[] = 'CM: '   . $cm;   }
        echo $fmt(implode('  ·  ', $cred));
      ?>
    </div>
    <?php if ($ci): ?><div class="line">C.I. <?php echo $fmt($ci); ?></div><?php endif; ?>
  </div>

</body>
</html>
