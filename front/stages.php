<?php

/**
 * Visão do Comitê por etapas: a ficha da iniciativa em leitura, na mesma ordem das abas (brainstorm, estratégia, TAP enviado e partes
 * interessadas, cronograma e RACI, monitoramento, custos, documentos, encerramento). Sem id, lista as iniciativas e o estado do TAP.
 */

use GlpiPlugin\Vmo\Access;
use GlpiPlugin\Vmo\Charter;
use GlpiPlugin\Vmo\Committee;
use GlpiPlugin\Vmo\Db;
use GlpiPlugin\Vmo\Initiative;
use GlpiPlugin\Vmo\ItemLink;
use GlpiPlugin\Vmo\Menu;
use GlpiPlugin\Vmo\Phase;
use GlpiPlugin\Vmo\Risk;
use GlpiPlugin\Vmo\Schedule;
use GlpiPlugin\Vmo\Task;

Session::checkLoginUser();
if (!Access::canView()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}
$e = fn ($v) => Menu::e($v);
$id = (int) ($_GET['id'] ?? 0);

if ($id === 0) {
    Menu::header('Fichas por etapa', 'committee');
    echo Menu::title('Fichas por etapa', 'Visão do Comitê: escolha uma iniciativa para ver todas as etapas, o TAP enviado, o cronograma e a RACI.', Menu::button('Comitê', Menu::url('/front/committee.php'), 'ti-arrow-left', 'btn-outline-secondary'));
    echo "<div class='card'><div class='table-responsive'><table class='table card-table table-hover'><thead><tr><th>Iniciativa</th><th>Situação</th><th>TAP</th><th>Enviado em</th><th>Prazo de entrega</th></tr></thead><tbody>";
    foreach (Access::visibleInitiatives() as $i) {
        $c = Charter::forInitiative((int) $i['id']);
        echo "<tr><td><a href='" . $e(Menu::url('/front/stages.php?id=' . $i['id'])) . "'><b>" . $e($i['code']) . "</b> · " . $e($i['name']) . "</a></td><td>" . Initiative::statusBadge($i['status']) . "</td><td>"
            . ($c && $c['status'] === 'issued' ? Menu::badge('Enviado', '#22c55e', 'ti-circle-check') : Menu::badge($c ? 'Rascunho' : 'Sem TAP', '#94a3b8')) . "</td><td>" . ($c && $c['issued_at'] ? $e(Menu::datetime($c['issued_at'])) : ($c ? "<a target='_blank' href='" . $e(Menu::url('/front/charter.php?id=' . $i['id'])) . "'><i class='ti ti-eye me-1'></i>ver TAP (rascunho)</a>" : '—')) . "</td><td>" . $e(Menu::date($c['delivery_date'] ?? null)) . "</td></tr>";
    }
    echo "</tbody></table></div></div>";
    Html::footer();
    return;
}

$init = Initiative::load($id);
$self = '/front/stages.php?id=' . $id;
Menu::header($init['code'] . ' · etapas', 'committee');
echo Menu::title($init['code'] . ' · ' . $init['name'], 'Visão do Comitê (somente leitura) · responsável: ' . Db::userName((int) $init['users_id_manager']) . ' · ' . strip_tags(Initiative::STATUSES[$init['status']][0]),
    Menu::button('Fichas', Menu::url('/front/stages.php'), 'ti-arrow-left', 'btn-outline-secondary') . Menu::button('Abrir a ficha', Menu::url('/front/initiative.php?id=' . $id), 'ti-external-link', 'btn-outline-primary'));

$card = fn (string $icon, string $title, string $body) => "<div class='card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti $icon me-1'></i>" . $e($title) . "</h3></div><div class='card-body'>$body</div></div>";
$kv = function (array $pairs) use ($e): string {
    $o = "<div class='vmo-grid2 vmo-cols2'>";
    foreach ($pairs as $l => $v) {
        $o .= "<div class='vmo-f'><div class='small vmo-muted'>" . $e($l) . "</div><div>" . (trim((string) $v) === '' ? '<span class="vmo-muted">—</span>' : nl2br($e((string) $v))) . "</div></div>";
    }
    return $o . '</div>';
};

// 1. brainstorm
$tk = [];
foreach (ItemLink::forInitiative($id) as $l) {
    $tk[] = $l['url'] ? "<a href='" . $e($l['url']) . "'>" . $e($l['label']) . "</a>" : $e($l['label']);
}
echo $card('ti-bulb', '1. Registro de Brainstorm', $kv(['Dores do departamento' => $init['problem'], 'Descrição' => $init['description'], 'Brainstorm' => $init['brainstorm_notes'], 'Oportunidade' => $init['opportunity']])
    . "<div class='mt-2'><b>Chamado de requisição:</b> " . ($tk ? implode(' · ', $tk) : '<span class="vmo-muted">—</span>') . "</div>");
// 2. estratégia
echo $card('ti-target-arrow', '2. Planejamento Estratégico', $kv(['Objetivo' => $init['objective'], 'Justificativa' => $init['justification'], 'Caso de negócio' => $init['business_case']]) . "<h4 class='mt-3 mb-2' style='font-size:.95rem'>Critérios estratégicos</h4>" . $kv(Charter::criteria($init)));
// 3. iniciação
$c = Charter::forInitiative($id);
$snap = Charter::snap($c);
if ($c && $c['status'] === 'issued' && $snap) {
    $f = $snap['fields'];
    $body = "<div class='alert alert-success py-2'><i class='ti ti-send me-1'></i><b>TAP enviado em " . $e(Menu::datetime($c['issued_at'])) . "</b> por " . $e(Db::userName((int) $c['issued_by'])) . " · revisão " . (int) $c['revision'] . ($c['sent_to'] ? ' · ' . $e($c['sent_to']) : '') . "</div>"
        . $kv(['Quem demandou' => $snap['requester'], 'Quem acompanha' => $snap['follower'], 'Início' => Menu::date($f['start_date'] ?? null), 'Prazo de entrega' => Menu::date($f['delivery_date'] ?? null),
            'Objetivo' => $f['objective'] ?? '', 'Escopo' => $f['scope'] ?? '', 'Fora do escopo' => $f['out_of_scope'] ?? '', 'Entregas' => $f['deliverables'] ?? '', 'Premissas' => $f['premises'] ?? '', 'Restrições' => $f['constraints'] ?? '',
            'Riscos iniciais' => $f['risks_initial'] ?? '', 'Critérios de sucesso' => $f['success_criteria'] ?? '', 'Orçamento estimado' => $f['budget_note'] ?? ''])
        . "<a class='btn btn-sm btn-outline-primary mt-2' target='_blank' href='" . $e(Menu::url('/front/charter.php?id=' . $id)) . "'><i class='ti ti-printer me-1'></i>Versão para imprimir</a>";
} else {
    $body = "<div class='alert alert-warning py-2'>O TAP ainda não foi enviado" . ($c ? ' (rascunho, prazo de entrega ' . $e(Menu::date($c['delivery_date'])) . ')' : '') . '.</div>';
}
echo $card('ti-file-certificate', '3. Iniciação e Alinhamento · TAP', $body);
echo Phase::render($id, 'stake', $self, false);
// 4. tarefas
echo $card('ti-timeline', '4. Tarefas · cronograma', Schedule::gantt(Task::forInitiative($id)));
echo $card('ti-grid-dots', 'RACI', Schedule::raciReadonly($id));
// 5. monitoramento
echo Phase::render($id, 'status', $self, false);
$rk = '';
$tasks = Task::forInitiative($id);
foreach (Risk::forInitiative($id) as $r) {
    $x = \GlpiPlugin\Vmo\Calc::riskExposure((int) $r['probability'], (int) $r['impact']);
    $rk .= "<tr><td>" . $e(Risk::KINDS[$r['kind']]) . "</td><td>" . $e($r['title']) . "</td><td class='small'>" . $e(($r['tasks_id'] ? ($tasks[(int) $r['tasks_id']]['name'] ?? '') : '') . ($r['delivery'] ? ' · ' . $r['delivery'] : '')) . "</td><td>" . Menu::badge((string) $x, Risk::exposureColor($x)) . "</td><td>" . $e(Db::userName((int) $r['users_id_owner'])) . "</td><td>" . $e(Risk::STATUSES[$r['status']]) . "</td></tr>";
}
echo $card('ti-alert-triangle', '5. Monitoramento e Controle · riscos', "<div class='table-responsive'><table class='table'><thead><tr><th>Tipo</th><th>Título</th><th>Tarefa / entrega</th><th>Exposição</th><th>Responsável</th><th>Situação</th></tr></thead><tbody>"
    . ($rk ?: "<tr><td colspan='6' class='text-center vmo-muted'>Nenhum risco registrado.</td></tr>") . "</tbody></table></div>");
echo Phase::render($id, 'rm', $self, false);
$cp = '';
foreach (array_slice(Committee::checkpoints($id), 0, 5) as $k) {
    $cp .= "<tr><td>" . $e(Menu::date($k['checkpoint_date'])) . "</td><td>" . ($k['progress'] === null ? '—' : number_format((float) $k['progress'], 0) . '%') . "</td><td>" . nl2br($e($k['advanced'])) . "</td><td>" . nl2br($e($k['blocker'])) . "</td></tr>";
}
echo $card('ti-checkup-list', 'Checkpoints recentes', "<div class='table-responsive'><table class='table'><thead><tr><th>Data</th><th>Avanço</th><th>O que avançou</th><th>Bloqueio</th></tr></thead><tbody>" . ($cp ?: "<tr><td colspan='4' class='text-center vmo-muted'>Nenhum checkpoint.</td></tr>") . "</tbody></table></div>");
// 6. custos
if (Access::canFinance()) {
    $m = Initiative::metricsFor([$id => $init], null, true)[$id];
    echo $card('ti-coin', '6. Custos', "<div class='d-flex flex-wrap gap-4'><div><div class='small vmo-muted'>Custo real</div><b>" . $e(Menu::money($m['actual'], $init['currency'])) . "</b></div>"
        . ($m['cost_variance'] ? "<div><div class='small vmo-muted'>Variação</div><b>" . $e(Menu::money($m['cost_variance'][0], $init['currency'])) . "</b></div>" : '') . "</div>");
}
// 7. documentos
$dr = '';
foreach (Committee::docs($id) as $d) {
    $dr .= "<tr><td><a href='" . $e(Menu::url('/front/minutes.php?id=' . (int) $d['id'])) . "'><i class='ti ti-file me-1'></i>" . $e($d['title']) . "</a></td><td>" . $e(Phase::SECTIONS[$d['section'] ?? ''] ?? 'Geral') . "</td><td class='small'>" . $e($d['original_name']) . "</td><td>" . $e(Menu::date($d['meeting_date'])) . "</td></tr>";
}
echo $card('ti-paperclip', '7. Documentação', "<div class='table-responsive'><table class='table'><thead><tr><th>Documento</th><th>Etapa</th><th>Arquivo</th><th>Data</th></tr></thead><tbody>" . ($dr ?: "<tr><td colspan='4' class='text-center vmo-muted'>Nenhum documento.</td></tr>") . "</tbody></table></div>");
// 8. encerramento
echo Phase::render($id, 'accept', $self, false);
echo Phase::render($id, 'lessons', $self, false);
echo Phase::render($id, 'close', $self, false);
Html::footer();
