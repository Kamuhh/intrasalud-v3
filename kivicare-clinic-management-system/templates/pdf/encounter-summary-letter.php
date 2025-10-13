<?php
/** $payload proviene del controller */
$P = $payload;
$esc = fn($s) => esc_html((string)($s ?? ''));
$li  = fn($arr) => empty($arr)
  ? '<div class="muted">No se encontraron registros</div>'
  : '<ul>' . implode('', array_map(fn($i) => '<li>'.$esc($i['title'] ?? '').'</li>', $arr)) . '</ul>';
$rx  = function($arr) use ($esc) {
    if (empty($arr)) return '<div class="muted">No se encontró receta</div>';
    $rows = '';
    foreach ($arr as $r) {
        $rows .= '<tr><td>'.$esc($r['name'] ?? '').'</td><td>'.$esc($r['frequency'] ?? '').'</td><td>'.$esc($r['duration'] ?? '').'</td></tr>';
    }
    return '<table class="tbl"><thead><tr><th>Nombre</th><th>Frecuencia</th><th>Duración</th></tr></thead><tbody>'.$rows.'</tbody></table>';
};
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Resumen de la atención</title>
<style>
/* Carta con márgenes superiores/ inferiores amplios para header/footer */
@page { size: letter; margin: 24mm 16mm 30mm 16mm; }
body { font-family: Arial, sans-serif; font-size:12px; color:#222; }

#page-header { position: fixed; top:-22mm; left:0; right:0; }
#page-footer { position: fixed; bottom:-24mm; left:0; right:0; text-align:center; }

.header-row { display:flex; align-items:center; justify-content:space-between; }
.brand { display:flex; align-items:flex-start; gap:10px; }
.brand img { height:28px; }
.brand-meta { font-size:11px; line-height:1.2; color:#666; }
.footer-line { border-top:1px solid #ccc; margin:10px auto 4px; width:220px; }
.footer-name { font-weight:700; margin-top:4px; }
.footer-spec { font-size:11px; color:#666; }
.footer-cred { font-size:11px; color:#444; }

/* Tarjetas */
.card { border:1px solid #ddd; border-radius:6px; padding:10px 12px; margin:10px 0; }
.card h3 { margin:0 0 6px; font-size:13px; }
.grid3 { display:grid; grid-template-columns:1fr 1fr 1fr; gap:8px 16px; }
.muted{ color:#666; }
.tbl { width:100%; border-collapse:collapse; }
.tbl th,.tbl td { border:1px solid #ddd; padding:6px 8px; text-align:left; }
.tbl th { background:#f6f6f6; }

.page-break { page-break-before: always; }
</style>
</head>
<body>

<!-- Membrete (repetido) -->
<div id="page-header">
  <div class="header-row">
    <div class="brand">
      <?php if ($P['clinic']['logo']): ?>
        <img src="<?php echo esc_url($P['clinic']['logo']); ?>" alt="">
      <?php endif; ?>
      <div class="brand-meta">
        <strong><?php echo $esc($P['clinic']['name']); ?></strong><br>
        <?php echo $esc($P['clinic']['addr']); ?><br>
        Paciente: <?php echo $esc($P['patient']['name']); ?> &nbsp; C.I.: <?php echo $esc($P['patient']['dni']); ?>
      </div>
    </div>
    <div style="text-align:right;font-size:11px;">
      Fecha: <?php echo $esc($P['date']); ?><br>
      <?php echo $esc($P['patient']['email']); ?>
    </div>
  </div>
  <hr style="margin-top:4px;border:none;border-top:1px solid #ddd;">
</div>

<!-- Pie con firma/credenciales -->
<div id="page-footer">
  <?php if ($P['doctor']['sign']): ?>
    <img src="<?php echo esc_url($P['doctor']['sign']); ?>" alt="" style="height:55px;display:block;margin:0 auto 4px;">
  <?php endif; ?>
  <div class="footer-line"></div>
  <div class="footer-name"><?php echo $esc($P['doctor']['name']); ?></div>
  <?php if(!empty($P['doctor']['spec'])): ?>
    <div class="footer-spec"><?php echo $esc($P['doctor']['spec']); ?></div>
  <?php endif; ?>
  <div class="footer-cred">
    <?php
      $creds = [];
      if ($P['doctor']['mpps']) $creds[] = 'MPPS: '.$P['doctor']['mpps'];
      if ($P['doctor']['cm'])   $creds[] = 'CM: '.$P['doctor']['cm'];
      if ($P['doctor']['ci'])   $creds[] = 'C.I. '.$P['doctor']['ci'];
      echo esc_html(implode(' · ', $creds));
    ?>
  </div>
</div>

<!-- Contenido -->
<div class="content">

  <div class="card">
    <h3>Detalles del paciente</h3>
    <div class="grid3">
      <div><strong>Nombre:</strong> <?php echo $esc($P['patient']['name']); ?></div>
      <div><strong>C.I.:</strong> <?php echo $esc($P['patient']['dni']); ?></div>
      <div><strong>Correo:</strong> <?php echo $esc($P['patient']['email']); ?></div>
    </div>
  </div>

  <div class="card">
    <h3>Detalles de la consulta</h3>
    <div class="grid3">
      <div><strong>Fecha:</strong> <?php echo $esc($P['date']); ?></div>
      <div><strong>Clínica:</strong> <?php echo $esc($P['clinic']['name']); ?></div>
      <div><strong>Doctor:</strong> <?php echo $esc($P['doctor']['name']); ?></div>
      <?php if(!empty($P['enc']['desc'])): ?>
        <div style="grid-column:1/-1"><strong>Descripción:</strong> <span class="muted"><?php echo $esc($P['enc']['desc']); ?></span></div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <h3>Diagnóstico(s)</h3>
    <?php echo $li($P['diagnoses']); ?>
  </div>

  <div class="card">
    <h3>Indicaciones</h3>
    <?php echo $li($P['indications']); ?>
  </div>

  <div class="card">
    <h3>Receta médica</h3>
    <?php echo $rx($P['prescriptions']); ?>
  </div>

  <div class="page-break"></div>
  <div class="card">
    <h3>Órdenes clínicas</h3>
    <?php echo $li($P['orders']); ?>
  </div>

</div>
</body>
</html>
