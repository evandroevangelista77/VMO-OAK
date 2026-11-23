<?php

/**
 * TAP · Termo de Abertura do Projeto em folha A4, para conferir antes de enviar, imprimir ou salvar em PDF e assinar digitalmente.
 * Rascunho: mostra os dados atuais. Enviado: mostra exatamente o que foi enviado (a fotografia), que é o que se assina.
 */

use GlpiPlugin\Vmo\Access;
use GlpiPlugin\Vmo\Charter;
use GlpiPlugin\Vmo\Db;
use GlpiPlugin\Vmo\Initiative;
use GlpiPlugin\Vmo\Menu;

Session::checkLoginUser();
if (!Access::canView()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}
$id = (int) ($_GET['id'] ?? 0);
$init = Initiative::load($id);
$c = Charter::forInitiative($id) ?: Charter::ensure($id);
$sent = $c['status'] === 'issued';
$D = ($sent ? Charter::snap($c) : null) ?: Charter::build($init, $c);
$F = $D['fields'];
$e = fn ($v) => Menu::e($v);
$dt = fn (?string $d) => $d ? date('d/m/Y', strtotime($d)) : '—';
$txt = fn (?string $t) => trim((string) $t) === '' ? '<span class="nil">Não informado</span>' : nl2br($e(trim((string) $t)));
$list = function (?string $t) use ($e) {
    $items = array_filter(array_map(fn ($l) => ltrim(trim($l), "-•* "), preg_split('/\R/', (string) $t)), fn ($l) => $l !== '');
    return $items ? '<ul>' . implode('', array_map(fn ($l) => '<li>' . $e($l) . '</li>', $items)) . '</ul>' : '<span class="nil">Não informado</span>';
};
$rev = max(1, (int) $c['revision']);
$docNo = 'TAP-' . $init['code'] . ($sent ? '-R' . $rev : '-RASCUNHO');
$sigs = $sent ? Charter::signatures($c) : [];
$signers = Charter::signers($init, $c);
$me = (int) Session::getLoginUserID();
$canSign = $sent && isset($signers[$me]) && !isset($sigs[$me]);
$verify = $sent ? strtoupper(substr(Charter::docHash($c), 0, 12)) : '';
$logo = Menu::url('/img/oak-tree-white.png');
$lv = ['3' => 'Alto', '2' => 'Médio', '1' => 'Baixo'];
$n = 0;
$sec = function (string $title, string $body) use (&$n, $e): string {
    $n++;
    return "<section><h2><span>$n</span>" . $e($title) . "</h2>$body</section>";
};
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= $e($docNo) ?> · Termo de Abertura do Projeto</title>
<style>
@page{size:A4;margin:0}
*{box-sizing:border-box}
html{background:#e2e8f0}
body{margin:0;font:12.5px/1.55 'Segoe UI',Arial,Helvetica,sans-serif;color:#1e293b}
.sheet{width:210mm;min-height:297mm;margin:16px auto;background:#fff;box-shadow:0 4px 24px rgba(15,23,42,.18);padding:0 0 14mm}
.band{background:#0f2a52;color:#fff;padding:9mm 14mm 7mm;display:flex;align-items:center;gap:14px}
.band img{height:46px}.band .t{flex:1}.band .t small{display:block;letter-spacing:.18em;font-size:10px;color:#93c5fd;text-transform:uppercase}
.band .t b{display:block;font-size:21px;letter-spacing:.02em;line-height:1.2}
.band .doc{text-align:right;font-size:11px;color:#cbd5e1}.band .doc b{display:block;color:#fff;font-size:13px}
.stripe{height:4px;background:linear-gradient(90deg,#38bdf8,#6366f1)}
.body{padding:8mm 14mm 0}
.proj{font-size:19px;font-weight:700;color:#0f2a52;margin:0 0 2px}.pill{display:inline-block;border-radius:999px;padding:1px 10px;font-size:11px;font-weight:600;margin-left:6px;vertical-align:middle}
.pill.draft{background:#fef3c7;color:#92400e}.pill.ok{background:#dcfce7;color:#166534}
table.id{width:100%;border-collapse:collapse;margin:10px 0 2px}table.id td{border:1px solid #cbd5e1;padding:6px 9px;vertical-align:top;width:25%}
table.id small{display:block;color:#64748b;font-size:9.5px;letter-spacing:.07em;text-transform:uppercase}table.id b{font-size:13px;color:#0f172a}
section{margin-top:12px;break-inside:avoid}
h2{font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:#0f2a52;margin:0 0 5px;padding-bottom:3px;border-bottom:1.5px solid #0f2a52;display:flex;align-items:center;gap:8px}
h2 span{background:#0f2a52;color:#fff;border-radius:4px;min-width:20px;text-align:center;font-size:11px;padding:0 4px}
p,ul{margin:0}ul{padding-left:18px}.nil{color:#94a3b8;font-style:italic}
.two{display:grid;grid-template-columns:1fr 1fr;gap:12px}.two h3{font-size:11px;color:#475569;margin:0 0 2px;text-transform:uppercase;letter-spacing:.05em}
table.g{width:100%;border-collapse:collapse}table.g th,table.g td{border:1px solid #cbd5e1;padding:4px 8px;text-align:left;font-size:12px}table.g th{background:#f1f5f9;font-size:10.5px;text-transform:uppercase;letter-spacing:.04em;color:#475569}
.kv{display:grid;grid-template-columns:repeat(3,1fr);gap:6px}.kv div{border:1px solid #e2e8f0;border-radius:4px;padding:4px 8px;background:#f8fafc}.kv small{display:block;color:#64748b;font-size:9.5px;text-transform:uppercase;letter-spacing:.06em}
.sig{display:grid;grid-template-columns:1fr 1fr;gap:10px 18px;margin-top:6px}
.sbox{border:1px solid #cbd5e1;border-radius:6px;padding:8px 10px;min-height:64px;break-inside:avoid}.sbox.done{border-color:#22c55e;background:#f0fdf4}
.sbox .role{font-size:9.5px;text-transform:uppercase;letter-spacing:.07em;color:#64748b}.sbox .who{font-weight:700;font-size:13px}
.sbox .stamp{font-size:10.5px;color:#166534;margin-top:3px;word-break:break-all}.sbox .wait{font-size:11px;color:#94a3b8;margin-top:14px;border-top:1px solid #94a3b8;padding-top:2px}
.verify{margin-top:12px;border:1px dashed #94a3b8;border-radius:6px;padding:7px 10px;font-size:10.5px;color:#475569}.verify code{font-size:10px;word-break:break-all}
.foot{margin:10px 14mm 0;border-top:1px solid #e2e8f0;padding-top:5px;font-size:10px;color:#94a3b8;display:flex;justify-content:space-between}
.tools{position:fixed;top:12px;right:14px;display:flex;gap:8px;z-index:9}.tools a,.tools button{padding:8px 14px;border:0;border-radius:6px;background:#2563eb;color:#fff;cursor:pointer;font:600 12px Arial;text-decoration:none}
.tools .sign{background:#16a34a}.tools .alt{background:#475569}
@media print{html{background:#fff}.sheet{margin:0;box-shadow:none;width:auto;min-height:0}.tools{display:none}}
@media(max-width:820px){.sheet{width:100%;margin:0}.two,.sig{grid-template-columns:1fr}.kv{grid-template-columns:1fr 1fr}}
</style></head><body>
<div class="tools">
<?php if ($canSign) : ?><form method="post" action="<?= $e(Menu::url('/front/action.php')) ?>" onsubmit="return confirm('Assinar digitalmente este termo? Fica registrado seu nome, data, hora e o código do documento.')"><?= Menu::csrf() ?><input type="hidden" name="do" value="charter_sign"><input type="hidden" name="initiatives_id" value="<?= $id ?>"><input type="hidden" name="return" value="/front/charter.php?id=<?= $id ?>"><button class="sign">Assinar digitalmente</button></form><?php endif; ?>
<a class="alt" href="<?= $e(Menu::url('/front/initiative.php?id=' . $id . '&tab=initiation')) ?>">Voltar à ficha</a>
<button onclick="window.print()">Imprimir / salvar PDF</button>
</div>
<div class="sheet">
<div class="band"><img src="<?= $e($logo) ?>" alt=""><div class="t"><small>Oakmont Group · VMO</small><b>Termo de Abertura do Projeto</b></div>
<div class="doc"><b><?= $e($docNo) ?></b><?= $sent ? 'Enviado em ' . $e(Menu::datetime($c['issued_at'])) : 'Ainda não enviado' ?></div></div>
<div class="stripe"></div>
<div class="body">
<div class="proj"><?= $e($D['code'] . ' · ' . $D['name']) ?><span class="pill <?= $sent ? 'ok' : 'draft' ?>"><?= $sent ? 'Enviado · revisão ' . $rev : 'Rascunho' ?></span></div>
<table class="id"><tr><td><small>Quem demandou</small><b><?= $e($D['requester']) ?></b></td><td><small>Quem vai acompanhar</small><b><?= $e($D['follower']) ?></b></td>
<td><small>Gestor</small><b><?= $e($D['manager']) ?></b></td><td><small>Patrocinador</small><b><?= $e($D['sponsor']) ?></b></td></tr>
<tr><td><small>Início</small><b><?= $e($dt($F['start_date'] ?? null)) ?></b></td><td><small>Prazo de entrega</small><b><?= $e($dt($F['delivery_date'] ?? null)) ?></b></td>
<td colspan="2"><small>Orçamento estimado</small><b><?= $e($F['budget_note'] ?? '') ?: '—' ?></b></td></tr></table>

<?= $sec('Justificativa e contexto', '<p>' . $txt(trim(($D['justification'] ?? '') . "\n" . ($D['problem'] ?? ''))) . '</p>') ?>
<?= $sec('Objetivo', '<p>' . $txt($F['objective'] ?? '') . '</p>') ?>
<?php $kv = ''; foreach ($D['criteria'] as $l => $v) { $kv .= '<div><small>' . $e($l) . '</small>' . $e($v) . '</div>'; } ?>
<?= $sec('Alinhamento estratégico', '<div class="kv">' . $kv . '</div>') ?>
<?= $sec('Escopo', '<div class="two"><div><h3>Dentro do escopo</h3><p>' . $txt($F['scope'] ?? '') . '</p></div><div><h3>Fora do escopo</h3><p>' . $txt($F['out_of_scope'] ?? '') . '</p></div></div>') ?>
<?= $sec('Entregas principais', $list($F['deliverables'] ?? '')) ?>
<?= $sec('Premissas e restrições', '<div class="two"><div><h3>Premissas</h3><p>' . $txt($F['premises'] ?? '') . '</p></div><div><h3>Restrições</h3><p>' . $txt($F['constraints'] ?? '') . '</p></div></div>') ?>
<?= $sec('Riscos iniciais e critérios de sucesso', '<div class="two"><div><h3>Riscos iniciais</h3><p>' . $txt($F['risks_initial'] ?? '') . '</p></div><div><h3>Critérios de sucesso</h3><p>' . $txt($F['success_criteria'] ?? '') . '</p></div></div>') ?>
<?php
$ms = '';
foreach ($D['milestones'] as [$mn, $md]) { $ms .= '<tr><td>' . $e($mn) . '</td><td>' . $e($dt($md)) . '</td></tr>'; }
echo $sec('Marcos e prazos', '<table class="g"><thead><tr><th>Marco</th><th style="width:30mm">Data</th></tr></thead><tbody><tr><td><b>Início da iniciativa</b></td><td>' . $e($dt($F['start_date'] ?? null)) . '</td></tr>' . $ms . '<tr><td><b>Entrega prevista</b></td><td><b>' . $e($dt($F['delivery_date'] ?? null)) . '</b></td></tr></tbody></table>');
$st = '';
foreach ($D['stakeholders'] as $s) { $st .= '<tr><td>' . $e($s['name']) . '</td><td>' . $e($s['role'] ?? '') . '</td><td>' . $e($lv[$s['interest'] ?? ''] ?? '') . '</td><td>' . $e($lv[$s['influence'] ?? ''] ?? '') . '</td><td>' . $e($s['comm'] ?? '') . '</td></tr>'; }
echo $sec('Partes interessadas', $st ? '<table class="g"><thead><tr><th>Parte interessada</th><th>Papel</th><th>Interesse</th><th>Influência</th><th>Comunicação</th></tr></thead><tbody>' . $st . '</tbody></table>' : '<span class="nil">Nenhuma parte interessada registrada.</span>');
$sb = '';
foreach ($signers as $u => $role) {
    $s = $sigs[$u] ?? null;
    $sb .= '<div class="sbox' . ($s ? ' done' : '') . '"><div class="role">' . $e($role) . '</div><div class="who">' . $e(Db::userName($u)) . '</div>'
        . ($s ? '<div class="stamp">✔ Assinado digitalmente em ' . $e(Menu::datetime($s['signed_at'])) . ' · IP ' . $e($s['ip'] ?? '—') . '<br>Código ' . $e(strtoupper(substr($s['sign_hash'], 0, 16))) . '</div>' : '<div class="wait">' . ($sent ? 'Aguardando assinatura' : 'Assinatura disponível após o envio') . '</div>') . '</div>';
}
echo $sec('Aprovação e assinaturas', '<div class="sig">' . $sb . '</div>');
?>
<?php if ($sent) : ?><div class="verify"><b>Assinatura eletrônica registrada no GLPI.</b> Cada assinatura guarda nome, papel, data, hora, IP e um código calculado sobre o conteúdo enviado: se o termo for alterado, o código deixa de conferir.<br>Código de verificação do documento: <code><?= $e($verify) ?></code> · Hash completo: <code><?= $e(Charter::docHash($c)) ?></code></div><?php endif; ?>
</div>
<div class="foot"><span>Confidencial · Gerado em <?= date('d/m/Y H:i') ?> por <?= $e(Db::userName($me)) ?></span><span><?= $e($docNo) ?><?= $sent ? ' · Cód. ' . $e($verify) : '' ?></span></div>
</div></body></html>
