<?php

/** Gera e baixa o Book de KPIs (PDF). Respeita as iniciativas visíveis ao usuário. */

use GlpiPlugin\Vmo\Access;
use GlpiPlugin\Vmo\Audit;
use GlpiPlugin\Vmo\KpiBook;
use GlpiPlugin\Vmo\KpiData;

Session::checkLoginUser();
if (!Access::canView()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}
$sel = (int) ($_GET['ini'] ?? 0);
$window = (int) ($_GET['days'] ?? 30);
if (!in_array($window, [7, 30, 60, 90], true)) {
    $window = 30;
}
$visible = Access::visibleInitiatives();
$d = KpiData::build($visible, $sel, $window, $_GET['from'] ?? null, $_GET['to'] ?? null);
$label = $sel && isset($visible[$sel]) ? $visible[$sel]['code'] . ' · ' . $visible[$sel]['name'] : 'Todas as iniciativas (' . count($visible) . ')';
$author = getUserName((int) Session::getLoginUserID());
$pdf = KpiBook::render($d, $label, $author);
Audit::log('Kpi', 0, 'book', ['escopo' => $label, 'janela' => $window, 'periodo' => ($d['period']['from'] ?? '') . ' a ' . ($d['period']['to'] ?? '')]);
header('Content-Type: application/pdf');
$fname = KpiBook::title($d) . '.pdf';
header('Content-Disposition: attachment; filename="' . str_replace('ç', 'c', $fname) . '"; filename*=UTF-8\'\'' . rawurlencode($fname));
header('Content-Length: ' . strlen($pdf));
echo $pdf;
