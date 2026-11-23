<?php

/** Download autenticado do arquivo de uma ata do comitê (acesso à entidade reavaliado a cada download). */

use GlpiPlugin\Vmo\Access;
use GlpiPlugin\Vmo\Audit;
use GlpiPlugin\Vmo\Committee;
use GlpiPlugin\Vmo\RuleException;

Session::checkLoginUser();
if (!Access::canView()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}
try {
    [$m, $path] = Committee::minutesFile((int) ($_GET['id'] ?? 0));
} catch (RuleException $ex) {
    http_response_code($ex->getCode() >= 400 && $ex->getCode() < 600 ? $ex->getCode() : 404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $ex->getMessage();
    return;
}
Audit::log('Minutes', (int) $m['id'], 'download', [], 0, (int) $m['entities_id']);
$ext = strtolower(pathinfo($m['original_name'], PATHINFO_EXTENSION));
$types = ['pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'txt' => 'text/plain; charset=UTF-8'];
header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: attachment; filename="' . str_replace(['"', "\r", "\n"], '', $m['original_name']) . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
