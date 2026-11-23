<?php

/**
 * Painel executivo do Comitê Estratégico: indicadores-chave, saúde e fases do portfólio, pauta do comitê,
 * fila de priorização, carga por dono executivo, decisões em aberto e atas em anexo.
 * Tudo parte das iniciativas que o usuário pode ver.
 */

use GlpiPlugin\Vmo\Access;
use GlpiPlugin\Vmo\Committee;
use GlpiPlugin\Vmo\Db;
use GlpiPlugin\Vmo\Initiative;
use GlpiPlugin\Vmo\Menu;

Session::checkLoginUser();
if (!Access::canView()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}
$e = fn ($v) => Menu::e($v);
$field = fn (string $label, string $input, string $class = '') => "<div class='vmo-f $class'><label class='form-label'>" . $e($label) . "</label><div class='vmo-ctl'>$input</div></div>";
$input = fn (string $name, $value = '', string $attrs = '') => "<input class='form-control' name='" . $e($name) . "' value='" . $e($value) . "' $attrs>";
$area = fn (string $name, $value = '', int $rows = 1) => "<textarea class='form-control' name='" . $e($name) . "' rows='$rows'>" . $e($value) . "</textarea>";
$link = fn (array $i, string $tab = '') => "<a href='" . $e(Menu::url('/front/initiative.php?id=' . $i['id'] . ($tab ? '&tab=' . $tab : ''))) . "'>" . $e($i['code']) . "</a>";

$visible = Access::visibleInitiatives();
$all = array_filter($visible, fn ($i) => !$i['is_deleted']);
$active = array_filter($all, fn ($i) => !in_array($i['status'], ['closed', 'cancelled', 'rejected', 'delivered', 'measuring'], true));
$running = array_filter($all, fn ($i) => $i['status'] === 'running');

$farol = [];
$lastCp = [];
foreach ($all as $i) {
    $lastCp[$i['id']] = Committee::checkpoints((int) $i['id'])[0] ?? null;
    $farol[$i['id']] = Committee::farol($i, $lastCp[$i['id']]);
}
$fc = ['green' => 0, 'yellow' => 0, 'red' => 0];
foreach ($running as $i) {
    if (isset($fc[$farol[$i['id']]['key']])) {
        $fc[$farol[$i['id']]['key']]++;
    }
}
$nRun = count($running);
$stale15 = count(array_filter($running, fn ($i) => ($farol[$i['id']]['days'] ?? 0) > 15));
$stale30 = count(array_filter($running, fn ($i) => ($farol[$i['id']]['days'] ?? 0) > 30));
$blocked = count(array_filter($running, fn ($i) => $farol[$i['id']]['block']));
$onTime = $nRun ? (int) round(($nRun - $stale15) / $nRun * 100) : null;
$openDec = Committee::decisions(array_keys($visible), true);
$limit = Committee::wipLimit();
$byOwner = [];
foreach ($running as $i) {
    [$ok, $on] = Committee::owner($i);
    $byOwner[$ok] ??= ['name' => $on, 'n' => 0];
    $byOwner[$ok]['n']++;
}
uasort($byOwner, fn ($a, $b) => [$b['n'], $a['name']] <=> [$a['n'], $b['name']]);
$overOwners = count(array_filter($byOwner, fn ($o) => $o['n'] > $limit));

// pauta: o que o comitê precisa olhar primeiro
$agenda = [];
foreach ($all as $i) {
    $f = $farol[$i['id']];
    $why = [];
    if ($i['status'] === 'running' && ($f['days'] ?? 0) > 30) {
        $why[] = 'duas quinzenas sem atualização';
    }
    if (!empty($lastCp[$i['id']]['needs_committee'])) {
        $why[] = 'checkpoint pede decisão';
    }
    if ($f['key'] === 'red') {
        $why[] = 'saúde crítica';
    }
    if ($f['block']) {
        $why[] = 'bloqueio aberto';
    }
    if ($why) {
        $agenda[] = [$i, $f, $why];
    }
}
usort($agenda, fn ($a, $b) => [$b[1]['key'] === 'red', count($b[2])] <=> [$a[1]['key'] === 'red', count($a[2])]);

Menu::header('Comitê', 'committee');
echo Menu::title('Comitê Estratégico', 'Visão executiva do portfólio: saúde, pauta, prioridades e decisões. A saúde é calculada, não negociada.',
    Menu::button('Fichas por etapa', Menu::url('/front/stages.php'), 'ti-list-details', 'btn-outline-primary')
    . (Access::canUpdate() ? Menu::button('Registrar decisão ou ata', '#cm-ata', 'ti-file-plus', 'btn-primary') : ''));

// ---- indicadores-chave
$tile = fn (string $label, string $value, string $sub, string $color, string $icon) => "<div class='cm-tile' style='--c:$color'><i class='ti $icon'></i><div><div class='cm-v'>$value</div><div class='cm-l'>" . $e($label) . "</div><div class='cm-s'>$sub</div></div></div>";
echo "<div class='cm-tiles'>"
    . $tile('No portfólio', (string) count($active), count($all) . ' iniciativas no total', '#6366f1', 'ti-briefcase')
    . $tile('Em execução', (string) $nRun, $nRun ? $fc['green'] . ' saudável · ' . $fc['yellow'] . ' atenção · ' . $fc['red'] . ' crítico' : 'nenhuma agora', '#22c55e', 'ti-player-play')
    . $tile('Atualizadas no prazo', $onTime === null ? '—' : $onTime . '%', $stale30 ? $stale30 . ' sem movimento há +30 dias' : 'nenhuma parada há +30 dias', $onTime !== null && $onTime < 70 ? '#f59e0b' : '#14b8a6', 'ti-refresh')
    . $tile('Pedem atenção', (string) count($agenda), $blocked ? $blocked . ' com bloqueio aberto' : 'sem bloqueio aberto', count($agenda) ? '#ef4444' : '#64748b', 'ti-alert-triangle')
    . $tile('Decisões em aberto', (string) count($openDec), 'da ata do comitê', count($openDec) ? '#a855f7' : '#64748b', 'ti-gavel')
    . $tile('Donos acima do limite', (string) $overOwners, 'limite de ' . $limit . ' em execução por dono', $overOwners ? '#ef4444' : '#64748b', 'ti-users')
    . "</div>";

// ---- etapas da ficha: quantas iniciativas já têm cada etapa registrada (clique para ver quais)
$ids = array_keys($all);
$grp = function (string $table, array $extra = [], string $col = 'initiatives_id') use ($ids): array {
    global $DB;
    $out = [];
    if (!$ids) {
        return $out;
    }
    foreach ($DB->request(['SELECT' => [$col, new \Glpi\DBAL\QueryExpression('COUNT(*) AS `n`')], 'FROM' => Db::t($table), 'WHERE' => [$col => $ids] + $extra, 'GROUPBY' => [$col]]) as $r) {
        $out[(int) $r[$col]] = (int) $r['n'];
    }
    return $out;
};
$nTasks = $grp('tasks', ['is_deleted' => 0]);
$nDone = $grp('tasks', ['is_deleted' => 0, 'status' => 'done']);
$nRisk = $grp('risks', ['is_deleted' => 0]);
$nCost = $grp('costs');
$nDocs = $grp('minutes', ['kind' => 'doc']);
$nRec = [];
foreach (['status', 'rm', 'accept', 'lessons', 'close'] as $rk) {
    $nRec[$rk] = $grp('records', ['is_deleted' => 0, 'kind' => $rk]);
}
$chart = [];
foreach (Db::rows('charters') as $ch) {
    $chart[(int) $ch['initiatives_id']] = $ch;
}
$stages = [
    ['brainstorm', 'Registro de Brainstorm', 'ti-bulb', '#eab308', 'com ideia e oportunidade registradas', fn ($i) => trim((string) $i['opportunity'] . (string) $i['brainstorm_notes']) !== '' ? Initiative::STATUSES[$i['status']][0] : null],
    ['strategy', 'Planejamento Estratégico', 'ti-target-arrow', '#3b82f6', 'com critérios estratégicos definidos', fn ($i) => (int) $i['catalogs_id_perspective'] > 0 || Initiative::score($i) !== null ? (Initiative::score($i) !== null ? 'score ' . number_format(Initiative::score($i), 1, ',', '') : 'perspectiva definida') : null],
    ['initiation', 'Iniciação e Alinhamento', 'ti-file-certificate', '#14b8a6', 'com TAP enviado', fn ($i) => isset($chart[$i['id']]) && $chart[$i['id']]['status'] === 'issued' ? 'TAP enviado em ' . Menu::datetime($chart[$i['id']]['issued_at']) : null],
    ['tasks', 'Tarefas', 'ti-timeline', '#6366f1', 'com cronograma e tarefas', fn ($i) => !empty($nTasks[$i['id']]) ? $nTasks[$i['id']] . ' tarefa(s), ' . ($nDone[$i['id']] ?? 0) . ' concluída(s)' : null],
    ['monitor', 'Monitoramento e Controle', 'ti-radar', '#f59e0b', 'com status report, riscos ou requisições', function ($i) use ($nRec, $nRisk) {
        $p = [];
        foreach (['status' => 'status report', 'rm' => 'requisição de mudança'] as $k => $l) {
            if (!empty($nRec[$k][$i['id']])) { $p[] = $nRec[$k][$i['id']] . ' ' . $l; }
        }
        if (!empty($nRisk[$i['id']])) { $p[] = $nRisk[$i['id']] . ' risco(s)'; }
        return $p ? implode(' · ', $p) : null;
    }],
    ['costs', 'Custos', 'ti-coin', '#0ea5e9', 'com lançamentos de custo', fn ($i) => !empty($nCost[$i['id']]) ? $nCost[$i['id']] . ' lançamento(s)' : null],
    ['docs', 'Documentação', 'ti-paperclip', '#a855f7', 'com documentos anexados', fn ($i) => !empty($nDocs[$i['id']]) ? $nDocs[$i['id']] . ' arquivo(s)' : null],
    ['closure', 'Encerramento e Transição', 'ti-lock', '#64748b', 'com termo de aceite, lições ou encerramento', function ($i) use ($nRec) {
        $p = [];
        foreach (['accept' => 'aceite', 'lessons' => 'lições', 'close' => 'encerramento'] as $k => $l) {
            if (!empty($nRec[$k][$i['id']])) { $p[] = $l; }
        }
        return $p ? implode(' · ', $p) : null;
    }],
];
$etTiles = '';
$etTpl = '';
foreach ($stages as [$key, $label, $icon, $color, $sub, $fn]) {
    $hit = [];
    foreach ($all as $i) {
        $m = $fn($i);
        if ($m !== null) {
            $hit[] = [$i, $m];
        }
    }
    $extra = $key === 'initiation' ? count(array_filter($chart, fn ($ch, $iid) => $ch['status'] !== 'issued' && isset($all[$iid]), ARRAY_FILTER_USE_BOTH)) . ' em rascunho' : 'de ' . count($all) . ' iniciativas';
    $etTiles .= "<div class='cm-tile vmo-click' data-drawer='etapa-$key' role='button' tabindex='0' style='--c:$color;cursor:pointer'><i class='ti $icon'></i><div><div class='cm-v'>" . count($hit) . "</div><div class='cm-l'>" . $e($label) . "</div><div class='cm-s'>" . $e($sub) . " · $extra</div></div></div>";
    $body = "<div class='vmo-dr-lead'>" . count($hit) . " iniciativa(s) " . $e($sub) . ". Clique em uma para abrir a ficha por etapas.</div>";
    foreach ($hit as [$i, $m]) {
        $body .= "<div class='vmo-dr-item' style='--oc:$color'><div class='d-flex justify-content-between gap-2'><div><div class='t'>" . $e($i['code'] . ' · ' . $i['name']) . "</div><div class='m'>" . $e($m) . " · " . $e(strip_tags(Initiative::STATUSES[$i['status']][0])) . "</div></div>"
            . "<div class='d-flex flex-column gap-1'><a class='btn btn-sm btn-outline-primary' href='" . $e(Menu::url('/front/stages.php?id=' . $i['id'])) . "'>Ver etapas</a><a class='btn btn-sm btn-outline-secondary' href='" . $e(Menu::url('/front/initiative.php?id=' . $i['id'] . '&tab=' . $key)) . "'>Abrir ficha</a></div></div></div>";
    }
    if (!$hit) {
        $body .= "<div class='vmo-muted'>Nenhuma iniciativa ainda.</div>";
    }
    if ($key === 'initiation') {
        $pend = array_filter($all, fn ($i) => !isset($chart[$i['id']]) || $chart[$i['id']]['status'] !== 'issued');
        if ($pend) {
            $body .= "<h4 class='mt-3' style='font-size:.9rem'>Com TAP ainda não enviado (" . count($pend) . ")</h4>";
            foreach ($pend as $i) {
                $body .= "<div class='vmo-dr-item' style='--oc:#94a3b8'><div class='d-flex justify-content-between gap-2'><div><div class='t'>" . $e($i['code'] . ' · ' . $i['name']) . "</div><div class='m'>" . (isset($chart[$i['id']]) ? 'rascunho' : 'sem TAP') . "</div></div><a class='btn btn-sm btn-outline-secondary' href='" . $e(Menu::url('/front/charter.php?id=' . $i['id'])) . "' target='_blank'>Ver TAP</a></div></div>";
            }
        }
    }
    $etTpl .= "<template id='dr-etapa-$key' data-title='" . $e($label) . "' data-icon='$icon' data-color='$color'><div class='vmo-dr'>$body</div></template>";
}
echo "<h4 class='mb-2 mt-1' style='font-size:.95rem'>Etapas da ficha das iniciativas <span class='text-secondary small'>(clique para ver de quais iniciativas)</span></h4><div class='cm-tiles'>$etTiles</div>$etTpl";

// ---- saúde e fases
$bar = fn (string $label, int $v, int $max, string $color, string $right = '') => "<div class='cm-row'><span class='cm-name'>" . $label . "</span><div class='cm-track'><i style='width:" . ($max ? max($v ? 3 : 0, round($v / $max * 100)) : 0) . "%;background:$color'></i></div><b>" . ($right !== '' ? $right : $v) . "</b></div>";
$seg = '';
foreach (['green' => 'Saudável', 'yellow' => 'Atenção', 'red' => 'Crítico'] as $k => $lbl) {
    if ($fc[$k]) {
        $seg .= "<i style='flex:" . $fc[$k] . ";background:" . Committee::FAROL[$k][1] . "' title='$lbl: " . $fc[$k] . "'>" . $fc[$k] . "</i>";
    }
}
$healthCard = "<div class='card cm-card'><div class='card-header'><h3 class='card-title'>Saúde das iniciativas em execução</h3></div><div class='card-body'>"
    . ($nRun ? "<div class='cm-seg'>$seg</div><div class='cm-legend'><span><i style='background:#22c55e'></i>Saudável</span><span><i style='background:#f59e0b'></i>Atenção</span><span><i style='background:#ef4444'></i>Crítico</span></div>" : "<div class='text-secondary'>Nenhuma iniciativa em execução.</div>")
    . "<div class='cm-facts'><div><b>$stale15</b><span>sem atualização há +15 dias</span></div><div><b>$stale30</b><span>sem movimento há +30 dias</span></div><div><b>$blocked</b><span>com bloqueio aberto</span></div></div></div></div>";
$phaseRows = '';
$maxPhase = 1;
$cnt = [];
foreach (Initiative::STATUSES as $k => $st) {
    $cnt[$k] = count(array_filter($all, fn ($i) => $i['status'] === $k));
    $maxPhase = max($maxPhase, $cnt[$k]);
}
foreach (Initiative::STATUSES as $k => $st) {
    $phaseRows .= $bar($e($st[0]), $cnt[$k], $maxPhase, $st[1]);
}
echo "<div class='row'><div class='col-lg-5'>$healthCard</div><div class='col-lg-7'><div class='card cm-card'><div class='card-header'><h3 class='card-title'>Portfólio por fase</h3></div><div class='card-body'>$phaseRows</div></div></div></div>";

// ---- TAPs enviados ao comitê (registro com data e hora do envio)
$taps = [];
foreach (Db::rows('charters', ['status' => 'issued'], ['issued_at DESC'], 50) as $t) {
    if (isset($visible[(int) $t['initiatives_id']])) {
        $taps[] = $t;
    }
}
echo "<div class='card cm-card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-file-certificate me-1'></i>TAPs enviados ao comitê <span class='text-secondary small'>(" . count($taps) . ")</span></h3>"
    . "<div class='ms-auto'><a class='btn btn-sm btn-outline-primary' href='" . $e(Menu::url('/front/stages.php')) . "'>Fichas por etapa (cronograma, RACI, riscos...)</a></div></div>";
if ($taps) {
    echo "<div class='table-responsive'><table class='table card-table'><thead><tr><th>Iniciativa</th><th>Enviado em</th><th>Por</th><th>Requisitante</th><th>Acompanha</th><th>Entrega</th></tr></thead><tbody>";
    foreach (array_slice($taps, 0, 8) as $t) {
        $ii = $visible[(int) $t['initiatives_id']];
        $sn = \GlpiPlugin\Vmo\Charter::snap($t) ?: [];
        echo "<tr><td><a href='" . $e(Menu::url('/front/stages.php?id=' . $ii['id'])) . "'><b>" . $e($ii['code']) . "</b> · " . $e($ii['name']) . "</a></td><td>" . $e(Menu::datetime($t['issued_at'])) . " <span class='text-secondary small'>rev. " . (int) $t['revision'] . "</span></td><td>" . $e(Db::userName((int) $t['issued_by']))
            . "</td><td>" . $e($sn['requester'] ?? '—') . "</td><td>" . $e($sn['follower'] ?? '—') . "</td><td>" . $e(Menu::date($t['delivery_date'])) . "</td></tr>";
    }
    echo "</tbody></table></div>";
} else {
    echo "<div class='card-body text-secondary'>Nenhum TAP enviado ainda.</div>";
}
echo "</div>";

// ---- pauta do comitê
$ag = '';
foreach (array_slice($agenda, 0, 12) as [$i, $f, $why]) {
    $ag .= "<tr><td>" . $link($i, $f['key'] === 'red' || $f['block'] ? 'checkpoints' : '') . "</td><td>" . $e($i['name']) . "</td><td>" . ($f['key'] ? Menu::badge($f['label'], $f['color']) : '—') . "</td><td>" . $e(Committee::owner($i)[1]) . "</td><td>" . $e(implode(' · ', $why)) . "</td></tr>";
}
echo "<div class='card cm-card cm-alert mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-flag-3 me-1'></i>Pauta do comitê <span class='text-secondary small'>(" . count($agenda) . ")</span></h3></div>"
    . ($ag ? "<div class='table-responsive'><table class='table table-vcenter card-table'><thead><tr><th>Iniciativa</th><th>Nome</th><th>Saúde</th><th>Dono executivo</th><th>Motivo</th></tr></thead><tbody>$ag</tbody></table></div>"
        : "<div class='card-body text-secondary'><i class='ti ti-circle-check me-1 text-success'></i>Nada pede atenção do comitê agora.</div>") . "</div>";

// ---- perspectiva, faixa e dono
$persp = '';
$pmax = 1;
$prow = [];
foreach (Db::rows('catalogs', ['kind' => 'perspective', 'is_active' => 1], ['code ASC']) as $pc) {
    $mine = array_filter($active, fn ($i) => (int) $i['catalogs_id_perspective'] === (int) $pc['id']);
    $prow[] = [$pc['code'] . ' · ' . $pc['name'], count($mine), count(array_filter($mine, fn ($i) => $i['status'] === 'running'))];
    $pmax = max($pmax, count($mine));
}
foreach ($prow as [$n, $t, $r]) {
    $persp .= $bar($e($n), $t, $pmax, '#6366f1', $r . '/' . $t);
}
$semP = count(array_filter($active, fn ($i) => !(int) $i['catalogs_id_perspective']));
$band = '';
$bmax = 1;
foreach (Initiative::BANDS as $b => $lbl) {
    $bmax = max($bmax, count(array_filter($active, fn ($i) => ($i['band'] ?? '') === $b)));
}
foreach (Initiative::BANDS as $b => $lbl) {
    $mine = array_filter($active, fn ($i) => ($i['band'] ?? '') === $b);
    $band .= $bar($e($lbl), count($mine), $bmax, ['A' => '#6366f1', 'B' => '#0ea5e9', 'C' => '#94a3b8'][$b], count(array_filter($mine, fn ($i) => $i['status'] === 'running')) . '/' . count($mine));
}
$own = '';
foreach (array_slice($byOwner, 0, 8, true) as $o) {
    $n = $o['n'];
    $own .= $bar($e($o['name']), $n, max($limit, max(array_column($byOwner, 'n') ?: [1])), $n > $limit ? '#ef4444' : '#22c55e', $n . '/' . $limit);
}
$hint = "<div class='cm-hint'>em execução / total no portfólio</div>";
echo "<div class='row'><div class='col-lg-4'><div class='card cm-card'><div class='card-header'><h3 class='card-title'>Por perspectiva</h3></div><div class='card-body'>$persp" . ($semP ? "<div class='cm-hint'>$semP sem perspectiva definida</div>" : '') . "$hint</div></div></div>"
    . "<div class='col-lg-4'><div class='card cm-card'><div class='card-header'><h3 class='card-title'>Por faixa</h3></div><div class='card-body'>$band$hint</div></div></div>"
    . "<div class='col-lg-4'><div class='card cm-card'><div class='card-header'><h3 class='card-title'>Carga por dono executivo</h3></div><div class='card-body'>" . ($own ?: "<div class='text-secondary'>Nenhuma iniciativa em execução.</div>") . "<div class='cm-hint'>em execução / limite acordado (vermelho = acima)</div></div></div></div></div>";

// ---- fila de priorização e decisões em aberto
$ranked = [];
foreach ($active as $i) {
    $sc = Initiative::score($i);
    if ($sc !== null) {
        $ranked[] = [$i, $sc];
    }
}
usort($ranked, fn ($a, $b) => $b[1] <=> $a[1]);
$semScore = count($active) - count($ranked);
$rk = '';
foreach (array_slice($ranked, 0, 8) as [$i, $sc]) {
    $c = $sc >= 70 ? '#22c55e' : ($sc >= 40 ? '#f59e0b' : '#94a3b8');
    $rk .= "<tr><td>" . $link($i) . "</td><td>" . $e($i['name']) . "</td><td style='min-width:130px'><div class='cm-track'><i style='width:" . $sc . "%;background:$c'></i></div></td><td><b>" . number_format($sc, 0) . "</b> " . Menu::badge(Initiative::scoreBand($sc), $c) . "</td></tr>";
}
$dec = '';
foreach (array_slice($openDec, 0, 8) as $d) {
    $di = $visible[(int) $d['initiatives_id']] ?? null;
    $late = $d['due_date'] && $d['due_date'] < Db::today();
    $dec .= "<tr><td>" . $e($d['committee_date'] ? Menu::date($d['committee_date']) : '—') . "</td><td>" . ($di ? $link($di) : 'Tema geral') . "</td><td>" . $e($d['title']) . ($d['decision_kind'] ? " " . Menu::badge(Committee::DECISIONS[$d['decision_kind']] ?? '', '#6366f1') : '') . "</td>"
        . "<td>" . $e($d['users_id_owner'] ? Db::userName((int) $d['users_id_owner']) : '—') . "</td><td>" . ($d['due_date'] ? ($late ? "<span class='text-danger'>" . $e(Menu::date($d['due_date'])) . " (vencida)</span>" : $e(Menu::date($d['due_date']))) : '—') . "</td>"
        . "<td class='text-end'>" . (Access::canUpdate() ? Menu::actionForm('decision_toggle', ['id' => $d['id'], 'return' => '/front/committee.php'], "<button class='btn btn-sm btn-outline-success'>Concluir</button>") : '') . "</td></tr>";
}
echo "<div class='row'><div class='col-lg-6'><div class='card cm-card'><div class='card-header'><h3 class='card-title'>Fila de priorização <span class='text-secondary small'>(score)</span></h3></div>"
    . ($rk ? "<div class='table-responsive'><table class='table table-vcenter card-table'><tbody>$rk</tbody></table></div>" : "<div class='card-body text-secondary'>Nenhuma iniciativa com as 5 notas preenchidas.</div>")
    . ($semScore ? "<div class='card-footer text-secondary small'>$semScore iniciativa(s) sem as 5 notas de priorização</div>" : '') . "</div></div>"
    . "<div class='col-lg-6'><div class='card cm-card'><div class='card-header'><h3 class='card-title'>Decisões em aberto <span class='text-secondary small'>(" . count($openDec) . ")</span></h3></div>"
    . ($dec ? "<div class='table-responsive'><table class='table table-vcenter card-table'><thead><tr><th>Data</th><th>Iniciativa</th><th>Decisão</th><th>Resp.</th><th>Prazo</th><th></th></tr></thead><tbody>$dec</tbody></table></div>" : "<div class='card-body text-secondary'>Nenhuma decisão em aberto.</div>")
    . "</div></div></div>";

// ---- atas em anexo + ata de decisões
$mrows = '';
foreach (Committee::minutes() as $m) {
    $mrows .= "<tr><td>" . $e(Menu::date($m['meeting_date'])) . "</td><td><i class='ti ti-file-text me-1'></i><a href='" . $e(Menu::url('/front/minutes.php?id=' . (int) $m['id'])) . "'>" . $e($m['title']) . "</a>"
        . ($m['notes'] ? "<div class='vmo-muted small'>" . nl2br($e($m['notes'])) . "</div>" : '') . "</td><td class='text-secondary small'>" . $e($m['original_name']) . " · " . number_format($m['size'] / 1024, 0, ',', '.') . " KB</td>"
        . "<td>" . $e(Db::userName((int) $m['users_id'])) . "</td><td class='text-end text-nowrap'><a class='btn btn-sm btn-outline-primary' href='" . $e(Menu::url('/front/minutes.php?id=' . (int) $m['id'])) . "'>Baixar</a> "
        . (Access::canConfigure() || (int) $m['users_id'] === (int) Session::getLoginUserID() ? Menu::actionForm('minutes_delete', ['id' => $m['id'], 'return' => '/front/committee.php'], "<button class='btn btn-sm btn-outline-danger' onclick=\"return confirm('Remover esta ata?')\">Remover</button>") : '') . "</td></tr>";
}
$up = '';
if (Access::canUpdate()) {
    $up = "<div class='card-body border-top'><form method='post' enctype='multipart/form-data' action='" . $e(Menu::url('/front/action.php')) . "'>" . Menu::csrf() . "<input type='hidden' name='do' value='minutes_add'><input type='hidden' name='return' value='/front/committee.php'>"
        . "<div class='vmo-grid2 vmo-cols4'>" . $field('Data da reunião', $input('meeting_date', Db::today(), "type='date'")) . $field('Título', $input('title', '', "placeholder='Ata do comitê de ...'"), 'vmo-span2')
        . $field('Arquivo da ata (PDF, Word, Excel, imagem; até 20 MB)', "<input class='form-control' type='file' name='file' required accept='.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.odt,.txt,.png,.jpg,.jpeg'>")
        . "</div><div class='mt-2'>" . $field('Observações', $area('notes')) . "</div><button class='btn btn-primary mt-2'><i class='ti ti-upload me-1'></i>Anexar ata</button></form></div>";
}
echo "<div class='card cm-card mb-3' id='cm-ata'><div class='card-header'><h3 class='card-title'><i class='ti ti-paperclip me-1'></i>Atas do comitê <span class='text-secondary small'>(" . count(Committee::minutes()) . ")</span></h3></div>"
    . "<div class='table-responsive'><table class='table table-vcenter card-table'><thead><tr><th>Reunião</th><th>Ata</th><th>Arquivo</th><th>Enviada por</th><th></th></tr></thead><tbody>"
    . ($mrows ?: "<tr><td colspan='5' class='text-center text-secondary py-3'>Nenhuma ata anexada ainda.</td></tr>") . "</tbody></table></div>$up</div>";

// ata de decisões completa (recolhida) + registro
$rowsD = '';
foreach (Committee::decisions(array_keys($visible)) as $d) {
    $di = $visible[(int) $d['initiatives_id']] ?? null;
    $rowsD .= "<tr><td>" . $e($d['committee_date'] ? Menu::date($d['committee_date']) : Menu::datetime($d['decided_at'])) . "</td><td>" . ($di ? $link($di) : 'Tema geral') . "</td><td>" . $e($d['title'])
        . ($d['decision_text'] ? "<div class='vmo-muted small'>" . nl2br($e($d['decision_text'])) . "</div>" : '') . "</td><td>" . $e($d['decision_kind'] ? (Committee::DECISIONS[$d['decision_kind']] ?? '') : '—') . "</td>"
        . "<td>" . $e($d['users_id_owner'] ? Db::userName((int) $d['users_id_owner']) : '—') . "</td><td>" . $e($d['due_date'] ? Menu::date($d['due_date']) : '—') . "</td><td>" . ($d['is_done'] ? "<span class='text-success'>Concluída</span>" : 'Em aberto') . "</td></tr>";
}
$iopts = [];
foreach ($all as $i) {
    $iopts[$i['id']] = $i['code'] . ' · ' . $i['name'];
}
$addForm = '';
if (Access::canUpdate()) {
    $inner = "<div class='vmo-grid2 vmo-cols4'>" . $field('Data do comitê', $input('committee_date', Db::today(), "type='date'")) . $field('Iniciativa (opcional)', Menu::select('initiatives_id', $iopts, '', '', true))
        . $field('Decisão', Menu::select('decision_kind', Committee::DECISIONS, '', '', true)) . $field('Responsável', Menu::userSelect('users_id_owner', 0))
        . $field('Título da decisão ou tema', $input('title', '', 'required'), 'vmo-span2') . $field('Descrição', $area('decision_text'), 'vmo-span2') . $field('Prazo', $input('due_date', '', "type='date'")) . '</div>';
    $addForm = "<div class='card-body border-top'><h4 class='mb-2'>Registrar decisão</h4><form method='post' action='" . $e(Menu::url('/front/action.php')) . "'>" . Menu::csrf() . "<input type='hidden' name='do' value='decision_add'><input type='hidden' name='return' value='/front/committee.php'>$inner<button class='btn btn-primary mt-2'>Registrar decisão</button></form></div>";
}
echo "<details class='card cm-card cm-details'><summary class='card-header'><h3 class='card-title'><i class='ti ti-list-details me-1'></i>Ata de decisões completa <span class='text-secondary small'>(clique para abrir)</span></h3></summary>"
    . "<div class='table-responsive'><table class='table table-vcenter card-table'><thead><tr><th>Data</th><th>Iniciativa</th><th>Decisão</th><th>Tipo</th><th>Responsável</th><th>Prazo</th><th>Situação</th></tr></thead><tbody>"
    . ($rowsD ?: "<tr><td colspan='7' class='text-center text-secondary py-3'>Nenhuma decisão registrada.</td></tr>") . "</tbody></table></div>$addForm</details>";
echo Menu::script('drawer.js');
Html::footer();
