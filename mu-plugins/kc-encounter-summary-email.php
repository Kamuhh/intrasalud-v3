<?php
/**
 * Plugin Name: KC Encounter Summary Email (PDF)
 * Description: Envía/visualiza el "Resumen de la atención" en PDF (igual que factura) y permite configurar asunto/cuerpo del email.
 * Author: Intrasalud
 * Version: 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class KC_Encounter_Summary_Email {

  public function __construct() {
    // AJAX (logged-in y nologged)
    add_action('wp_ajax_kc_encounter_summary_email',        [$this, 'ajax_entry']);
    add_action('wp_ajax_nopriv_kc_encounter_summary_email', [$this, 'ajax_entry']);

    // REST opcional (si tu JS usa kcGlobals.apiBase)
    add_action('rest_api_init', function () {
      register_rest_route('kc/v1', '/encounter/summary/email', [
        'methods'  => 'POST',
        'callback' => [$this, 'rest_entry'],
        'permission_callback' => '__return_true',
      ]);
    });

    // Página de ajustes: asunto/cuerpo
    add_action('admin_menu', [$this, 'add_settings_page']);
  }

  /* ---------------- Ajustes (Asunto/Cuerpo) --------------- */

  public function add_settings_page() {
    if ( ! current_user_can('manage_options') ) return;
    add_options_page('Resumen (email)', 'Resumen (email)', 'manage_options', 'kc-summary-email', function () {
      if ( isset($_POST['kc_summary_email_nonce']) && wp_verify_nonce(sanitize_text_field($_POST['kc_summary_email_nonce']), 'kc_summary_email_save') ) {
        update_option('kc_summary_email_subject', wp_kses_post(wp_unslash($_POST['kc_summary_email_subject'] ?? '')));
        update_option('kc_summary_email_body',    wp_kses_post(wp_unslash($_POST['kc_summary_email_body'] ?? '')));
        echo '<div class="updated"><p>Guardado.</p></div>';
      }
      $subject = get_option('kc_summary_email_subject', 'Resumen de la atención');
      $body    = get_option('kc_summary_email_body', 'Estimado(a),<br>Adjuntamos su resumen de la atención en formato PDF.<br><br>Gracias.');
      ?>
      <div class="wrap">
        <h1>Resumen de la atención (email)</h1>
        <form method="post">
          <?php wp_nonce_field('kc_summary_email_save','kc_summary_email_nonce'); ?>
          <table class="form-table" role="presentation">
            <tr>
              <th><label for="kc_summary_email_subject">Asunto</label></th>
              <td><input type="text" class="regular-text" id="kc_summary_email_subject" name="kc_summary_email_subject" value="<?php echo esc_attr($subject); ?>"></td>
            </tr>
            <tr>
              <th><label for="kc_summary_email_body">Cuerpo (HTML)</label></th>
              <td><textarea class="large-text code" rows="8" id="kc_summary_email_body" name="kc_summary_email_body"><?php echo esc_textarea($body); ?></textarea></td>
            </tr>
          </table>
          <?php submit_button('Guardar cambios'); ?>
        </form>
      </div>
      <?php
    });
  }

  /* ---------------- Entradas (AJAX/REST) ------------------- */

  public function ajax_entry() {
    $this->handle([
      'to'           => isset($_POST['to']) ? sanitize_email(wp_unslash($_POST['to'])) : '',
      'encounter_id' => isset($_POST['encounter_id']) ? absint($_POST['encounter_id']) : 0,
      'subject'      => isset($_POST['subject']) ? sanitize_text_field(wp_unslash($_POST['subject'])) : '',
      'filename'     => isset($_POST['filename']) ? sanitize_file_name(wp_unslash($_POST['filename'])) : '',
      'html'         => isset($_POST['html']) ? wp_unslash($_POST['html']) : '',
      'view'         => !empty($_POST['view']) || (isset($_POST['mode']) && $_POST['mode']==='view'),
      'send'         => !empty($_POST['send']) || !empty($_POST['to']),
      'transport'    => 'ajax',
    ]);
  }

  public function rest_entry(\WP_REST_Request $req) {
    return $this->handle([
      'to'           => sanitize_email($req->get_param('to')),
      'encounter_id' => absint($req->get_param('encounter_id')),
      'subject'      => sanitize_text_field($req->get_param('subject') ?: ''),
      'filename'     => sanitize_file_name($req->get_param('filename') ?: ''),
      'html'         => wp_unslash($req->get_param('html') ?: ''),
      'view'         => (bool)$req->get_param('view'),
      'send'         => (bool)$req->get_param('send') || (bool)$req->get_param('to'),
      'transport'    => 'rest',
    ]);
  }

  /* ---------------- Núcleo ------------------- */

  private function handle(array $in) {
    try {
      $to           = $in['to'];
      $encounter_id = $in['encounter_id'];
      $subject      = $in['subject'] ?: get_option('kc_summary_email_subject','Resumen de la atención');
      $filename     = $in['filename'] ?: "Resumen_{$encounter_id}.pdf";
      $html_in      = (string)$in['html'];
      $is_view      = (bool)$in['view'];
      $is_send      = (bool)$in['send'];
      $transport    = $in['transport']; // 'ajax' | 'rest'

      if ( $is_send && ( empty($to) || !is_email($to) ) ) return $this->end(false, 'Correo destino inválido', $transport);
      if ( empty($encounter_id) )                                        return $this->end(false, 'encounter_id requerido', $transport);

      // 1) HTML del resumen (ideal: viene del front ya como “Imprimir”)
      $html_doc = $this->get_summary_html($encounter_id, $html_in);
      if ( empty($html_doc) ) return $this->end(false, 'No se pudo componer el HTML del resumen', $transport);

      // 2) Generar PDF
      $pdf = $this->render_pdf($html_doc, $filename);
      if (!$pdf || !file_exists($pdf)) return $this->end(false, 'No se pudo generar el PDF', $transport);

      // 3) Ver PDF inline
      if ($is_view) {
        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="'.basename($filename).'"');
        readfile($pdf);
        @unlink($pdf);
        exit;
      }

      // 4) Enviar correo
      if ($is_send) {
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        $body = get_option('kc_summary_email_body', 'Estimado(a),<br>Adjuntamos su resumen de la atención en formato PDF.<br><br>Gracias.');
        $sent = wp_mail($to, wp_strip_all_tags($subject), wp_kses_post($body), $headers, [$pdf]);
        @unlink($pdf);
        if (!$sent) return $this->end(false, 'Fallo al enviar el correo', $transport);
        return $this->end(true, 'Correo enviado', $transport);
      }

      // 5) OK genérico
      @unlink($pdf);
      return $this->end(true, 'OK', $transport);

    } catch (\Throwable $e) {
      return $this->end(false, 'Excepción: '.$e->getMessage(), $in['transport']);
    }
  }

  private function get_summary_html($encounter_id, $html_from_client = '') {
    $html = $html_from_client;

    // Limpia botones y “cromo” si viene del front:
    if (!empty($html)) {
      $html = $this->strip_controls($html);
      return $this->normalize_html($html);
    }

    // Fallback: función del tema/plugin (si existe)
    if ( function_exists('kc_render_encounter_summary_html') ) {
      $raw = kc_render_encounter_summary_html($encounter_id);
      if (!empty($raw)) {
        $raw = $this->strip_controls($raw);
        return $this->normalize_html($raw);
      }
    }

    // Último recurso (plantilla mínima)
    $clinic  = get_bloginfo('name');
    $patient = __('Paciente', 'intrasalud');
    return '<!doctype html><html><head><meta charset="utf-8"></head><body>'
      .'<h2>Resumen de la atención</h2>'
      .'<p>Clínica: '.esc_html($clinic).'</p>'
      .'<p>Paciente: '.esc_html($patient).'</p>'
      .'<p>Contenido no disponible.</p>'
      .'</body></html>';
  }

  private function strip_controls($html) {
    // Quitar scripts/links/estilos embebidos peligrosos
    $html = preg_replace('~<(script)[^>]*>.*?</\1>~is', '', $html);
    // Quitar botones típicos por clase/atributo/texto
    $html = preg_replace('~<(a|button)[^>]+?(js-kc-summary-|data-kc-summary-|kc-modal__close|data-kc-bill-)[^>]*>.*?</\1>~is', '', $html);
    $html = preg_replace('~<(a|button)[^>]*>(\s*(Correo electrónico|Imprimir|Cerrar|Email|Print|Close)\s*)</\1>~iu', '', $html);
    return $html;
  }

  private function normalize_html($html) {
    // Si llega sólo fragmento, envolverlo en un documento válido
    $has_html = stripos($html, '<html') !== false;
    if (!$has_html) {
      $html = '<!doctype html><html><head><meta charset="utf-8"></head><body>'.$html.'</body></html>';
    } else {
      // Asegurar meta charset
      if ( stripos($html, '<meta charset=') === false ) {
        $html = preg_replace('/<head([^>]*)>/', '<head$1><meta charset="utf-8">', $html, 1);
      }
    }

    // Mover <style> que estén en <body> a <head> (evita que Dompdf los imprima como texto)
    if (preg_match('~<head[^>]*>~i', $html)) {
      $styles = [];
      if (preg_match_all('~<style[^>]*>.*?</style>~is', $html, $m)) {
        $styles = $m[0];
        $html = preg_replace('~<style[^>]*>.*?</style>~is', '', $html); // quitamos todos
      }
      if ($styles) {
        $inject = implode("\n", $styles);
        $html = preg_replace('~<head([^>]*)>~i', '<head$1>'.$inject, $html, 1);
      }
    }

    // CSS base de impresión (por si el front no lo incluyó)
    if (strpos($html, '.kc-print-root') === false) {
      $base = '<style>@page{size:Letter;margin:1cm}html,body{height:100%}body{margin:0;-webkit-print-color-adjust:exact;print-color-adjust:exact}'
        .'*{box-sizing:border-box}.kc-print-root{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,"Helvetica Neue",Helvetica,sans-serif;font-size:12pt;line-height:1.45;color:#111}'
        .'.kc-print-root,.kc-print-root *{background:transparent!important;border:0!important;box-shadow:none!important}'
        .'.kc-print-root *{margin:0!important;padding:0!important;max-width:100%!important}'
        .'.kc-header-logo{width:100%;margin:0 0 12px 0!important;text-align:left!important}.kc-header-logo img{height:60px;display:block}'
        .'.kc-footer{position:fixed;bottom:1cm;left:0;width:100%;text-align:center;line-height:1.1}.kc-footer img{max-height:80px;display:inline-block;padding:10px}'
        .'</style>';
      $html = preg_replace('~<head([^>]*)>~i', '<head$1>'.$base, $html, 1);
      // Asegurar wrapper si no existe
      if (strpos($html, 'kc-print-root') === false) {
        $html = preg_replace('~<body([^>]*)>~i', '<body$1><div class="kc-print-root">', $html, 1);
        $html = str_replace('</body>', '</div></body>', $html);
      }
    }
    return $html;
  }

  private function render_pdf($html, $filename) {
    $upload = wp_upload_dir();
    $tmpdir = trailingslashit($upload['basedir']).'intrasalud_tmp/';
    if ( ! file_exists($tmpdir) ) @wp_mkdir_p($tmpdir);
    $pdf = $tmpdir . ($filename ?: ('Resumen_'.time().'.pdf'));

    // Cargar librerías como lo hace factura
    $this->maybe_load_pdfsdk();

    // Dompdf primero
    if (class_exists('\Dompdf\Dompdf')) {
      try {
        $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled'=>true,'isHtml5ParserEnabled'=>true]);
        $dompdf->loadHtml($html,'UTF-8');
        $dompdf->setPaper('letter');
        $dompdf->render();
        file_put_contents($pdf, $dompdf->output());
        return $pdf;
      } catch (\Throwable $e) { /* probar mPDF */ }
    }

    // mPDF luego
    if (class_exists('\Mpdf\Mpdf')) {
      try {
        $mpdf = new \Mpdf\Mpdf(['format'=>'Letter','tempDir'=>$tmpdir]);
        $mpdf->WriteHTML($html);
        $mpdf->Output($pdf, \Mpdf\Output\Destination::FILE);
        return $pdf;
      } catch (\Throwable $e) { /* nada */ }
    }

    return false;
  }

  private function maybe_load_pdfsdk() {
    // Intenta rutas típicas de KiviCare/factura
    $candidates = [
      WP_PLUGIN_DIR.'/kivicare-clinic-management-system/vendor/autoload.php',
      WP_PLUGIN_DIR.'/kivicare/vendor/autoload.php',
      WP_PLUGIN_DIR.'/kivicare-clinic-management-system/autoload.php',
    ];
    foreach ($candidates as $file) {
      if (is_readable($file)) { require_once $file; break; }
    }
  }

  private function end($ok, $msg, $transport) {
    if ($transport === 'rest') {
      return new \WP_REST_Response([
        'success' => (bool)$ok,
        'status'  => $ok ? 'success' : 'error',
        'message' => $msg,
      ], $ok ? 200 : 400);
    }
    wp_send_json([
      'success' => (bool)$ok,
      'status'  => $ok ? 'success' : 'error',
      'message' => $msg,
    ], $ok ? 200 : 400);
  }
}

new KC_Encounter_Summary_Email();
