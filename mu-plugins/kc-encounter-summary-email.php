<?php
/**
 * Plugin Name: KC Encounter Summary Email (PDF)
 * Description: Envía el "Resumen de la atención" por correo con PDF adjunto, igual que factura.
 * Author: Intrasalud
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class KC_Encounter_Summary_Email {

  public function __construct() {
    // AJAX (logged-in y no logged-in si procede)
    add_action('wp_ajax_kc_encounter_summary_email',       [$this, 'ajax_send']);
    add_action('wp_ajax_nopriv_kc_encounter_summary_email',[$this, 'ajax_send']);

    // REST (por si tu JS usa kcGlobals.apiBase)
    add_action('rest_api_init', function() {
      register_rest_route('kc/v1', '/encounter/summary/email', [
        'methods'  => 'POST',
        'callback' => [$this, 'rest_send'],
        'permission_callback' => function() { return is_user_logged_in() || !is_user_logged_in(); }, // abre/ajusta según tu flujo
      ]);
    });
  }

  /** -------------------- ENTRYPOINTS -------------------- */

  public function ajax_send() {
    // Sanitizar entrada
    $to           = isset($_POST['to']) ? sanitize_email(wp_unslash($_POST['to'])) : '';
    $encounter_id = isset($_POST['encounter_id']) ? absint($_POST['encounter_id']) : 0;
    $subject      = isset($_POST['subject']) ? sanitize_text_field(wp_unslash($_POST['subject'])) : __('Resumen de la atención','intrasalud');
    $filename     = isset($_POST['filename']) ? sanitize_file_name(wp_unslash($_POST['filename'])) : "Resumen_$encounter_id.pdf";
    $html         = isset($_POST['html']) ? wp_kses_post(wp_unslash($_POST['html'])) : '';

    $this->maybe_json($to, $encounter_id, $subject, $filename, $html);
  }

  public function rest_send(\WP_REST_Request $req) {
    $to           = sanitize_email($req->get_param('to'));
    $encounter_id = absint($req->get_param('encounter_id'));
    $subject      = $req->get_param('subject') ? sanitize_text_field($req->get_param('subject')) : __('Resumen de la atención','intrasalud');
    $filename     = $req->get_param('filename') ? sanitize_file_name($req->get_param('filename')) : "Resumen_$encounter_id.pdf";
    $html         = $req->get_param('html') ? wp_kses_post($req->get_param('html')) : '';

    return $this->maybe_json($to, $encounter_id, $subject, $filename, $html, true);
  }

  /** -------------------- CORE -------------------- */

  private function maybe_json($to, $encounter_id, $subject, $filename, $html, $is_rest = false) {
    if ( empty($to) || !is_email($to) ) {
      return $this->end(false, 'Correo destino inválido', $is_rest);
    }
    if ( empty($encounter_id) ) {
      return $this->end(false, 'encounter_id requerido', $is_rest);
    }

    // 1) Obtener HTML del resumen (preferimos el que manda el JS porque es idéntico a la vista de impresión)
    $html_doc = $this->get_summary_html($encounter_id, $html);
    if ( empty($html_doc) ) {
      return $this->end(false, 'No se pudo componer el HTML del resumen', $is_rest);
    }

    // 2) Generar PDF con la misma librería que factura (intentamos Dompdf, luego mPDF)
    $pdf_path = $this->render_pdf($html_doc, $filename);
    if ( ! $pdf_path || ! file_exists($pdf_path) ) {
      return $this->end(false, 'No se pudo generar el PDF', $is_rest);
    }

    // 3) Preparar cuerpo del correo (mismo estilo que factura: título + mensaje breve)
    $body = $this->email_body_template();

    // 4) Enviar
    $headers = ['Content-Type: text/html; charset=UTF-8'];
    $sent = wp_mail($to, $subject, $body, $headers, [$pdf_path]);

    // 5) Limpieza
    @unlink($pdf_path);

    if ( ! $sent ) {
      return $this->end(false, 'Fallo al enviar el correo', $is_rest);
    }
    return $this->end(true, 'Correo enviado', $is_rest);
  }

  private function get_summary_html($encounter_id, $html_from_client = '') {
    // Si el front ya envía el HTML final (idéntico a "Imprimir"), úsalo
    if ( ! empty($html_from_client) ) {
      return $this->normalize_html($html_from_client);
    }

    // Si no recibimos HTML, intenta usar una función del plugin (si existe) para renderizar el resumen
    // Ajusta estos nombres si en tu plugin hay helpers equivalentes:
    if ( function_exists('kc_render_encounter_summary_html') ) {
      $raw = kc_render_encounter_summary_html($encounter_id);
      if ( ! empty($raw) ) return $this->normalize_html($raw);
    }

    // Último recurso: HTML básico (evita enviar texto plano)
    $patient = $this->get_patient_name_by_encounter($encounter_id);
    $clinic  = get_bloginfo('name');
    $fallback = '<!doctype html><html><head><meta charset="utf-8"><title>Resumen</title></head><body>'.
      '<h2 style="margin:0 0 12px 0;font-family:Arial">Resumen de la atención</h2>'.
      '<p style="font-family:Arial">Clínica: '.esc_html($clinic).'</p>'.
      '<p style="font-family:Arial">Paciente: '.esc_html($patient).'</p>'.
      '<hr><p style="font-family:Arial">Contenido no disponible en detalle.</p>'.
      '</body></html>';
    return $fallback;
  }

  private function normalize_html($html) {
    // Asegurar DOCTYPE y meta charset para que Dompdf/mPDF no “rompan” tildes
    if ( stripos($html, '<html') === false ) {
      $html = '<!doctype html><html><head><meta charset="utf-8"></head><body>'.$html.'</body></html>';
    } else {
      // Inyecta meta charset si no existe
      if ( stripos($html, '<meta charset=') === false ) {
        $html = preg_replace('/<head([^>]*)>/', '<head$1><meta charset="utf-8">', $html, 1);
      }
    }
    return $html;
  }

  private function render_pdf($html, $filename) {
    // Ruta temporal
    $upload_dir = wp_upload_dir();
    $tmp_dir = trailingslashit($upload_dir['basedir']).'intrasalud_tmp/';
    if ( ! file_exists($tmp_dir) ) @wp_mkdir_p($tmp_dir);
    $pdf_path = $tmp_dir . ( $filename ?: ('Resumen_'.time().'.pdf') );

    // Prioridad 1: Dompdf (igual que suelen usar para factura)
    if ( class_exists('\Dompdf\Dompdf') ) {
      try {
        $dompdf = new \Dompdf\Dompdf([
          'isRemoteEnabled' => true,
          'isHtml5ParserEnabled' => true,
        ]);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('letter'); // mismo tamaño que impresión
        $dompdf->render();
        $output = $dompdf->output();
        file_put_contents($pdf_path, $output);
        return $pdf_path;
      } catch (\Throwable $e) { /* cae a mPDF */ }
    }

    // Prioridad 2: mPDF
    if ( class_exists('\Mpdf\Mpdf') ) {
      try {
        $mpdf = new \Mpdf\Mpdf(['format' => 'Letter', 'tempDir' => $tmp_dir]);
        $mpdf->WriteHTML($html);
        $mpdf->Output($pdf_path, \Mpdf\Output\Destination::FILE);
        return $pdf_path;
      } catch (\Throwable $e) { /* último recurso abajo */ }
    }

    // Sin librerías: aborta
    return false;
  }

  private function email_body_template() {
    // Cuerpo HTML similar al de la factura (encabezado + breve texto)
    $brand = esc_html(get_bloginfo('name'));
    $body = '
      <div style="font-family:Arial,Helvetica,sans-serif">
        <table width="100%" cellpadding="0" cellspacing="0" role="presentation">
          <tr><td style="padding:24px 0;text-align:center;">
            <h2 style="margin:0;color:#0a58ca;">Resumen adjuntado</h2>
          </td></tr>
          <tr><td style="padding:8px 16px;">
            <p>Estimado(a),</p>
            <p>Adjuntamos su resumen de la atención en formato PDF.</p>
            <p>Gracias.</p>
            <p style="color:#6c757d;font-size:12px;margin-top:24px;">
              Este e-mail ha sido generado automáticamente, por favor no responder. 
              Este correo electrónico fue enviado por '.$brand.'.
            </p>
          </td></tr>
        </table>
      </div>';
    return $body;
  }

  private function get_patient_name_by_encounter($encounter_id) {
    // Si tu plugin tiene funciones/clases para esto, úsalas aquí.
    // Ejemplo genérico:
    $name = '';
    if ( function_exists('kc_get_patient_name_by_encounter') ) {
      $name = kc_get_patient_name_by_encounter($encounter_id);
    }
    if ( empty($name) ) $name = __('Paciente', 'intrasalud');
    return $name;
  }

  private function end($success, $message, $is_rest = false) {
    if ( $is_rest ) {
      return new \WP_REST_Response([
        'success' => (bool)$success,
        'status'  => $success ? 'success' : 'error',
        'message' => $message,
      ], $success ? 200 : 400);
    }
    wp_send_json([
      'success' => (bool)$success,
      'status'  => $success ? 'success' : 'error',
      'message' => $message,
    ], $success ? 200 : 400);
  }
}
new KC_Encounter_Summary_Email();
