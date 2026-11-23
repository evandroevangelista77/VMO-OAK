<?php

/** Status report em PDF: abre no navegador (pré-visualização, antes de enviar) ou baixa com ?download=1. */

use GlpiPlugin\Vmo\Access;
use GlpiPlugin\Vmo\Db;
use GlpiPlugin\Vmo\Initiative;
use GlpiPlugin\Vmo\Phase;

Session::checkLoginUser();
if (!Access::canView()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}
if ((int) ($_GET['id'] ?? 0) === 0 && (int) ($_GET['ini'] ?? 0) > 0 && in_array($_GET['kind'] ?? '', ['close', 'accept'], true)) {
    // modelo preenchido com os dados da iniciativa, antes de existir o registro
    $init = Initiative::load((int) $_GET['ini']);
    $d = Phase::draft((int) $init['id'], $_GET['kind']);
    $r = ['id' => 0, 'initiatives_id' => (int) $init['id'], 'kind' => $_GET['kind'], 'title' => $d['title'], 'record_date' => Db::today(), 'status' => 'draft', 'signed_at' => null, 'signed_by' => 0, 'd' => $d];
} else {
    $r = Db::row('records', (int) ($_GET['id'] ?? 0));
    if (!$r || !in_array($r['kind'], ['status', 'close', 'accept'], true) || $r['is_deleted']) {
        throw new \Glpi\Exception\Http\NotFoundHttpException();
    }
    $init = Initiative::load((int) $r['initiatives_id']);
    $r['d'] = json_decode((string) $r['data'], true) ?: [];
}
[$pdf, $name] = Phase::pdf($init, $r);
header('Content-Type: application/pdf');
header('Content-Disposition: ' . (isset($_GET['download']) ? 'attachment' : 'inline') . '; filename="' . $name . '"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
