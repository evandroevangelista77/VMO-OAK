<?php

use GlpiPlugin\Vmo\Access;
use GlpiPlugin\Vmo\Committee;
use GlpiPlugin\Vmo\GroupView;
use GlpiPlugin\Vmo\Initiative;
use GlpiPlugin\Vmo\Kpi;
use GlpiPlugin\Vmo\KpiData;
use GlpiPlugin\Vmo\Task;
use GlpiPlugin\Vmo\Db;
use GlpiPlugin\Vmo\Indicator;
use GlpiPlugin\Vmo\Menu;

Session::checkLoginUser();
if (!Access::canView()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}
$e = fn ($v) => Menu::e($v);
$form = function (string $do, array $hidden, string $inner, string $btn) use ($e): string {
    $h = '';
    foreach ($hidden as $k => $v) {
        $h .= "<input type='hidden' name='" . $e($k) . "' value='" . $e($v) . "'>";
    }
    return "<form method='post' action='" . $e(Menu::url('/front/action.php')) . "'>" . Menu::csrf() . "<input type='hidden' name='do' value='" . $e($do) . "'><input type='hidden' name='return' value='/front/indicators.php'>$h$inner<button class='btn btn-primary btn-sm mt-2'>" . $e($btn) . "</button></form>";
};
$field = fn (string $label, string $input) => "<div><label class='form-label'>" . $e($label) . "</label>$input</div>";
$input = fn (string $name, $value = '', string $attrs = '') => "<input class='form-control' name='" . $e($name) . "' value='" . $e($value) . "' $attrs>";

Menu::header('KPIs', 'indicators');
$sel = (int) ($_GET['ini'] ?? 0);
$window = (int) ($_GET['days'] ?? 30);
if (!in_array($window, [7, 30, 60, 90], true)) {
    $window = 30;
}
$visible = Access::visibleInitiatives();
$pf = Db::date($_GET['from'] ?? '');
$pt = Db::date($_GET['to'] ?? '');
$D = KpiData::build($visible, $sel, $window, $pf, $pt);
$K = $D['K'];
$today = $D['today'];
$q = http_build_query(['ini' => $sel, 'days' => $window, 'from' => $pf ?: '', 'to' => $pt ?: '']);
$actions = Menu::button('Gerar book (PDF)', Menu::url('/front/kpibook.php?' . $q), 'ti-book-download', 'btn-primary')
;
echo Menu::title('KPIs de performance', 'Como as atividades estão andando: entrega, fluxo, qualidade do plano e governança. Calculados das tarefas e checkpoints das iniciativas que você pode ver; dado ausente aparece como "sem dado".', $actions);

// filtros
$opt = "<option value='0'>Todas as iniciativas</option>";
foreach ($visible as $r) {
    $opt .= "<option value='" . (int) $r['id'] . "'" . ($sel === (int) $r['id'] ? ' selected' : '') . ">" . $e($r['code'] . ' · ' . $r['name']) . "</option>";
}
$dopt = '';
foreach ([7, 30, 60, 90] as $d) {
    $dopt .= "<option value='$d'" . ($window === $d ? ' selected' : '') . ">Últimos $d dias</option>";
}
echo "<form method='get' class='d-flex flex-wrap gap-2 mb-3'><select class='form-select' name='ini' style='max-width:420px' onchange='this.form.submit()'>$opt</select><select class='form-select' name='days' style='max-width:160px' onchange='this.form.submit()'>$dopt</select>"
    . "<label class='d-flex align-items-center gap-1 small text-secondary'>De <input class='form-control' type='date' name='from' value='" . $e($pf ?: '') . "' style='max-width:150px' onchange='this.form.submit()'></label>"
    . "<label class='d-flex align-items-center gap-1 small text-secondary'>Até <input class='form-control' type='date' name='to' value='" . $e($pt ?: '') . "' style='max-width:150px' onchange='this.form.submit()'></label></form>"
    . "<div class='small text-secondary mb-2'>Período dos dados: <b>" . $e($D['period']['from'] ? Menu::date($D['period']['from']) : '—') . "</b> a <b>" . $e(Menu::date($D['period']['to'])) . "</b>" . ($D['period']['custom'] ? ' (filtro aplicado)' : ' (todo o histórico disponível)') . "</div>";

// ---------------------------------------------------------------- apoio
$iniCode = [];
foreach ($D['scope'] as $r) {
    $iniCode[(int) $r['id']] = $r['code'];
}
$col = ['green' => '#22c55e', 'yellow' => '#f59e0b', 'red' => '#ef4444', 'nodata' => '#64748b'];
$pill = ['green' => 'Na meta', 'yellow' => 'Atenção', 'red' => 'Crítico', 'nodata' => 'Sem dado'];
$fmt = fn (?float $v, string $suf = '', int $dec = 1) => $v === null ? "<span class='vmo-muted' style='font-size:1rem'>sem dado</span>" : rtrim(rtrim(number_format($v, $dec, ',', '.'), '0'), ',') . $suf;
$plainFmt = fn (?float $v, string $suf = '') => $v === null ? '—' : rtrim(rtrim(number_format($v, 1, ',', '.'), '0'), ',') . $suf;
$leaf = Kpi::leaves($D['tasks']);
$openT = array_filter($leaf, fn ($t) => in_array($t['status'], ['todo', 'doing'], true));
$doneT = array_filter($leaf, fn ($t) => $t['status'] === 'done');
$tbl = fn (array $head, array $rows) => "<div class='table-responsive'><table class='table table-vcenter table-sm'><thead><tr><th>" . implode('</th><th>', array_map($e, $head)) . "</th></tr></thead><tbody>"
    . ($rows ? implode('', array_map(fn ($r) => '<tr><td>' . implode('</td><td>', $r) . '</td></tr>', array_slice($rows, 0, 30))) : "<tr><td colspan='" . count($head) . "' class='text-center text-secondary py-3'>Nada a mostrar.</td></tr>") . "</tbody></table></div>"
    . (count($rows) > 30 ? "<div class='small vmo-muted'>Mostrando 30 de " . count($rows) . ".</div>" : '');
$taskRow = fn (array $t, array $extra) => array_merge([$e($iniCode[(int) $t['initiatives_id']] ?? ''), $e($t['name']), $e($t['users_id_responsible'] ? Db::userName((int) $t['users_id_responsible']) : '—')], $extra);
$daysLate = fn (array $t) => $t['planned_end'] ? (int) round((strtotime($today) - strtotime($t['planned_end'])) / 86400) : 0;
$dmy = fn (?string $d) => $e(Menu::date($d));

// detalhe por KPI: [definição (glossário), tabela do que está por trás]
$detail = [];
$detail['otd'] = $tbl(['Iniciativa', 'Tarefa', 'Responsável', 'Prazo', 'Concluída em', 'Resultado'], array_map(fn ($t) => $taskRow($t, [$dmy($t['planned_end']), $dmy($t['actual_end']),
    $t['actual_end'] <= $t['planned_end'] ? Menu::badge('No prazo', '#22c55e') : Menu::badge('+' . (int) round((strtotime($t['actual_end']) - strtotime($t['planned_end'])) / 86400) . ' d', '#ef4444')]),
    array_values(array_filter($doneT, fn ($t) => $t['actual_end'] && $t['planned_end']))));
$detail['completion'] = $tbl(['Situação', 'Tarefas'], array_map(fn ($st) => [Menu::badge(Task::STATUSES[$st][0], Task::STATUSES[$st][1]), count(array_filter($leaf, fn ($t) => $t['status'] === $st))], ['todo', 'doing', 'done']));
$lateRows = array_values(array_filter($openT, fn ($t) => $t['planned_end'] && $t['planned_end'] < $today));
usort($lateRows, fn ($a, $b) => $daysLate($b) <=> $daysLate($a));
$detail['overdue'] = $tbl(['Iniciativa', 'Tarefa', 'Responsável', 'Prazo', 'Atraso'], array_map(fn ($t) => $taskRow($t, [$dmy($t['planned_end']), Menu::badge($daysLate($t) . ' d', '#ef4444')]), $lateRows));
$soonRows = array_values(array_filter($openT, fn ($t) => $t['planned_end'] && $t['planned_end'] >= $today && $t['planned_end'] <= date('Y-m-d', strtotime($today . ' +7 days'))));
$detail['due_soon'] = $tbl(['Iniciativa', 'Tarefa', 'Responsável', 'Prazo'], array_map(fn ($t) => $taskRow($t, [$dmy($t['planned_end'])]), $soonRows));
$detail['throughput'] = $tbl(['Iniciativa', 'Tarefa', 'Responsável', 'Concluída em'], array_map(fn ($t) => $taskRow($t, [$dmy($t['actual_end'])]),
    array_values(array_filter($doneT, fn ($t) => $t['actual_end'] && $t['actual_end'] >= date('Y-m-d', strtotime($today . " -$window days"))))));
$cyc = array_values(array_filter($doneT, fn ($t) => $t['actual_start'] && $t['actual_end'] && $t['planned_start'] && $t['planned_end']));
$detail['lead'] = $tbl(['Iniciativa', 'Tarefa', 'Responsável', 'Planejado', 'Real'], array_map(fn ($t) => $taskRow($t, [(int) round((strtotime($t['planned_end']) - strtotime($t['planned_start'])) / 86400) . ' d',
    (int) round((strtotime($t['actual_end']) - strtotime($t['actual_start'])) / 86400) . ' d']), $cyc));
$detail['wip'] = $tbl(['Iniciativa', 'Tarefa', 'Responsável', 'Prazo'], array_map(fn ($t) => $taskRow($t, [$dmy($t['planned_end'])]), array_values(array_filter($leaf, fn ($t) => $t['status'] === 'doing'))));
$detail['effort'] = $tbl(['Iniciativa', 'Tarefa', 'Responsável', 'Planejado (h)', 'Real (h)'], array_map(fn ($t) => $taskRow($t, [$plainFmt((float) $t['effort_plan']), $plainFmt((float) $t['effort_actual'])]),
    array_values(array_filter($doneT, fn ($t) => (float) $t['effort_plan'] > 0))));
$detail['no_owner'] = $tbl(['Iniciativa', 'Tarefa', 'Responsável', 'Prazo'], array_map(fn ($t) => $taskRow($t, [$dmy($t['planned_end'])]), array_values(array_filter($openT, fn ($t) => (int) $t['users_id_responsible'] === 0))));
$detail['no_due'] = $tbl(['Iniciativa', 'Tarefa', 'Responsável'], array_map(fn ($t) => $taskRow($t, []), array_values(array_filter($openT, fn ($t) => empty($t['planned_end'])))));
$detail['ms'] = $tbl(['Iniciativa', 'Marco', 'Responsável', 'Prazo', 'Situação'], array_map(fn ($t) => $taskRow($t, [$dmy($t['planned_end']), Menu::badge(Task::STATUSES[$t['status']][0], Task::STATUSES[$t['status']][1])]),
    array_values(array_filter($leaf, fn ($t) => !empty($t['is_milestone'])))));
$runRows = [];
foreach ($D['rows'] as $r) {
    if ($r['ini']['status'] === 'running') {
        $f = Committee::farol($r['ini']);
        $runRows[] = [$e($r['ini']['code']), $e($r['ini']['name']), Menu::badge($f['label'], $f['color']), ($f['days'] ?? '—') . ' d', $f['block'] ? Menu::badge('Bloqueio', '#ef4444') : '—', $r['spi'] === null ? '—' : number_format($r['spi'], 2, ',', '.')];
    }
}
$iniTbl = $tbl(['Código', 'Iniciativa', 'Saúde', 'Sem atualização', 'Bloqueio', 'SPI'], $runRows);
foreach (['updated', 'health', 'spi', 'block'] as $kx) {
    $detail[$kx] = $iniTbl;
}

// cartões: chave => [rótulo, valor, sub, status, ícone, cor do ícone, glossário]
$T = [
    'Entrega' => [
        'otd' => ['Entrega no prazo', $fmt($K['otd'], '%'), $K['otd_n'] . ' concluída(s) com datas', Kpi::status($K['otd'], 'otd'), 'ti-target-arrow', '#6366f1', 'otd'],
        'completion' => ['Conclusão', $fmt($K['completion'], '%'), $K['done'] . ' de ' . $K['total'] . ' tarefas', $K['completion'] === null ? 'nodata' : Kpi::status($K['completion'], 'completion'), 'ti-circle-check', '#22c55e', 'completion'],
        'overdue' => ['Atrasadas', $K['overdue_pct'] === null ? $fmt(null) : $K['overdue'] . ' · ' . $fmt($K['overdue_pct'], '%'), $K['overdue_avg'] !== null ? 'atraso médio de ' . $plainFmt($K['overdue_avg'], ' dias') : 'nenhuma atrasada', Kpi::status($K['overdue_pct'], 'overdue_pct'), 'ti-alarm', '#ef4444', 'overdue'],
        'due_soon' => ['Vencem em 7 dias', (string) $K['due_soon'], 'tarefas abertas com prazo próximo', $K['total'] ? ($K['due_soon'] ? 'yellow' : 'green') : 'nodata', 'ti-calendar-due', '#f59e0b', 'overdue'],
    ],
    'Fluxo' => [
        'throughput' => ['Entregas por semana', $fmt($K['throughput'], '/sem'), $K['recent'] . ' concluída(s) em ' . $window . ' dias', $K['throughput'] === null ? 'nodata' : 'green', 'ti-arrows-right', '#0ea5e9', 'throughput'],
        'lead' => ['Tempo de ciclo', $fmt($K['lead'], ' d'), $K['cycle_dev'] !== null ? ($K['cycle_dev'] > 0 ? '+' : '') . $plainFmt($K['cycle_dev'], '% sobre o planejado') : 'início a fim das concluídas', $K['lead'] === null ? 'nodata' : ($K['cycle_dev'] === null || $K['cycle_dev'] <= 10 ? 'green' : ($K['cycle_dev'] <= 30 ? 'yellow' : 'red')), 'ti-clock', '#a855f7', 'lead'],
        'wip' => ['Em andamento (WIP)', (string) $K['doing'], $K['open'] . ' abertas no total', $K['total'] ? 'green' : 'nodata', 'ti-player-play', '#14b8a6', 'throughput'],
        'effort' => ['Aderência de esforço', $fmt($K['effort'], '%'), 'horas reais ÷ planejadas', Kpi::status($K['effort'], 'effort'), 'ti-hourglass', '#ec4899', 'effort'],
    ],
    'Qualidade do plano' => [
        'no_owner' => ['Sem responsável', $fmt($K['no_owner'], '%'), 'tarefas abertas sem dono', Kpi::status($K['no_owner'], 'no_owner'), 'ti-user-off', '#f97316', 'plan'],
        'no_due' => ['Sem prazo', $fmt($K['no_due'], '%'), 'tarefas abertas sem data final', Kpi::status($K['no_due'], 'no_due'), 'ti-calendar-off', '#eab308', 'plan'],
        'ms' => ['Marcos no prazo', $fmt($K['ms_otd'], '%'), $K['ms_total'] . ' marco(s) · ' . $K['ms_late'] . ' atrasado(s)', Kpi::status($K['ms_otd'], 'ms_otd'), 'ti-flag', '#06b6d4', 'ms'],
    ],
    'Governança das iniciativas em execução' => [
        'updated' => ['Atualização em dia', $fmt($D['freshPct'], '%'), $D['fresh'] . ' de ' . $D['nRun'] . ' com checkpoint em 15 dias', Kpi::status($D['freshPct'], 'updated'), 'ti-refresh', '#3b82f6', 'updated'],
        'health' => ['Iniciativas saudáveis', $fmt($D['healthyPct'], '%'), $D['healthy'] . ' de ' . $D['nRun'] . ' em execução', $D['healthyPct'] === null ? 'nodata' : ($D['healthyPct'] >= 70 ? 'green' : ($D['healthyPct'] >= 40 ? 'yellow' : 'red')), 'ti-heartbeat', '#10b981', 'health'],
        'spi' => ['SPI · desempenho de prazo', $fmt($D['spi'], '', 2), 'real ÷ esperado pelo calendário', Kpi::status($D['spi'], 'spi'), 'ti-gauge', '#8b5cf6', 'spi'],
        'block' => ['Bloqueios abertos', (string) $D['blocked'], 'declarados no último checkpoint', $D['nRun'] ? ($D['blocked'] ? 'red' : 'green') : 'nodata', 'ti-hand-stop', '#ef4444', 'block'],
    ],
];
$gloss = fn (string $g) => (function () use ($g, $e) {
    [$t, $what, $how, $ref] = KpiData::GLOSSARY[$g];
    return "<div class='kp-def'><div><b>" . $e($t) . "</b></div><div>" . $e($what) . "</div><div class='small vmo-muted mt-1'>Cálculo: " . $e($how) . " · Referência: " . $e($ref) . "</div></div>";
})();

echo "<div class='kp-intro'><i class='ti ti-info-circle'></i><div><b>Como ler:</b> clique em um cartão para destacá-lo e ver as tarefas ou iniciativas por trás do número. "
    . "<b>SPI</b> (Schedule Performance Index, índice de desempenho de prazo) compara o progresso real com o que o calendário esperava até hoje: <b>1,00</b> = no ritmo; abaixo de <b>0,90</b> = atrás do plano; acima de 1,00 = adiantado.</div></div>";
$panels = '';
foreach ($T as $section => $tiles) {
    echo "<h4 class='kp-sec'>" . $e($section) . "</h4><div class='kp-tiles'>";
    foreach ($tiles as $key => [$label, $value, $sub, $st, $icon, $ic, $g]) {
        echo "<div class='kp-tile' data-kpi='$key' tabindex='0' role='button' style='--c:" . $col[$st] . ";--ic:$ic'><span class='kp-ic'><i class='ti $icon'></i></span><div class='kp-body'><div class='kp-v'>$value</div><div class='kp-l'>" . $e($label) . "</div>"
            . "<div class='kp-s'>" . $e($sub) . "</div></div><span class='kp-pill'>" . $pill[$st] . "</span></div>";
        $panels .= "<div class='kp-panel' id='kp-$key' hidden><div class='kp-panel-head' style='--ic:$ic'><span class='kp-ic'><i class='ti $icon'></i></span><h3>" . $e($label) . "</h3><button type='button' class='btn-close' data-close></button></div>"
            . $gloss($g) . ($key === 'throughput' ? '' : '') . $detail[$key] . "</div>";
    }
    echo "</div>";
}
echo "<div id='kp-detail'>$panels</div>";

// ---------------------------------------------------------------- visão geral (gráficos)
$maxW = max(1, ...array_map(fn ($w) => $w['done'], $D['weekly']));
$bw = '';
foreach ($D['weekly'] as $k => $w) {
    $x = 14 + $k * 44;
    $hOk = round(($w['done'] - $w['late']) / $maxW * 100);
    $hLate = round($w['late'] / $maxW * 100);
    $bw .= "<rect x='$x' y='" . (118 - $hOk - $hLate) . "' width='28' height='$hLate' rx='3' fill='#f59e0b'/><rect x='$x' y='" . (118 - $hOk) . "' width='28' height='$hOk' rx='3' fill='#6366f1'/>"
        . ($w['done'] ? "<text x='" . ($x + 14) . "' y='" . (114 - $hOk - $hLate) . "' text-anchor='middle' font-size='10' fill='currentColor'>" . $w['done'] . "</text>" : '')
        . "<text x='" . ($x + 14) . "' y='134' text-anchor='middle' font-size='9' fill='currentColor' opacity='.65'>" . $e($w['label']) . "</text>";
}
$chartWeekly = "<div class='card cm-card'><div class='card-header'><h3 class='card-title'>Entregas por semana</h3></div><div class='card-body'><svg viewBox='0 0 370 142' class='kp-svg'>$bw</svg>"
    . "<div class='cm-legend'><span><i style='background:#6366f1'></i>No prazo</span><span><i style='background:#f59e0b'></i>Com atraso</span><span>tarefas concluídas por semana</span></div></div></div>";
$sc = ['todo' => 0, 'doing' => 0, 'done' => 0];
foreach ($leaf as $t) {
    $sc[$t['status']]++;
}
$tot = max(1, array_sum($sc));
$off = 0;
$arcs = '';
foreach ($sc as $st => $n) {
    $len = $n / $tot * 100;
    $arcs .= "<circle cx='60' cy='60' r='42' fill='none' stroke='" . Task::STATUSES[$st][1] . "' stroke-width='16' stroke-dasharray='$len " . (100 - $len) . "' stroke-dashoffset='" . (25 - $off) . "' pathLength='100'/>";
    $off += $len;
}
$leg = '';
foreach ($sc as $st => $n) {
    $leg .= "<div class='cm-row'><span class='cm-name'><i class='kp-dot' style='background:" . Task::STATUSES[$st][1] . "'></i>" . $e(Task::STATUSES[$st][0]) . "</span><div class='cm-track'><i style='width:" . round($n / $tot * 100) . "%;background:" . Task::STATUSES[$st][1] . "'></i></div><b>$n</b></div>";
}
$chartMix = "<div class='card cm-card'><div class='card-header'><h3 class='card-title'>Composição das tarefas</h3></div><div class='card-body d-flex align-items-center gap-3'>"
    . "<svg viewBox='0 0 120 120' width='120' height='120'><circle cx='60' cy='60' r='42' fill='none' stroke='rgba(148,163,184,.2)' stroke-width='16'/>$arcs<text x='60' y='58' text-anchor='middle' font-size='22' font-weight='700' fill='currentColor'>" . array_sum($sc) . "</text><text x='60' y='74' text-anchor='middle' font-size='9' fill='currentColor' opacity='.65'>tarefas</text></svg>"
    . "<div style='flex:1'>$leg</div></div></div>";
$ranked = array_values(array_filter($D['rows'], fn ($r) => $r['k']['otd'] !== null));
usort($ranked, fn ($a, $b) => $a['k']['otd'] <=> $b['k']['otd']);
$rk = '';
foreach (array_slice($ranked, 0, 6) as $r) {
    $rk .= "<div class='cm-row'><span class='cm-name'>" . $e($r['ini']['code']) . "</span><div class='cm-track'><i style='width:" . round($r['k']['otd']) . "%;background:" . $col[Kpi::status($r['k']['otd'], 'otd')] . "'></i></div><b>" . number_format($r['k']['otd'], 0) . "%</b></div>";
}
$chartIni = "<div class='card cm-card'><div class='card-header'><h3 class='card-title'>Entrega no prazo por iniciativa</h3></div><div class='card-body'>" . ($rk ?: "<div class='text-secondary'>Sem tarefas concluídas com datas ainda.</div>") . "<div class='cm-hint'>as 6 com menor percentual</div></div></div>";
echo "<h4 class='kp-sec'>Visão geral</h4><div class='row'><div class='col-lg-5'>$chartWeekly</div><div class='col-lg-4'>$chartMix</div><div class='col-lg-3'>$chartIni</div></div>";

// performance por iniciativa: tabela agrupada (por perspectiva), no mesmo formato das demais telas
$persp = [];
foreach (Db::rows('catalogs', ['kind' => 'perspective', 'is_active' => 1], ['code ASC']) as $pi => $pc) {
    $persp[(int) $pc['id']] = [$pc['name'], GroupView::PALETTE[$pi % count(GroupView::PALETTE)]];
}
$persp[0] = ['Sem perspectiva', '#94a3b8'];
$byP = [];
foreach ($D['rows'] as $r) {
    $key = isset($persp[(int) $r['ini']['catalogs_id_perspective']]) ? (int) $r['ini']['catalogs_id_perspective'] : 0;
    $byP[$key][] = $r;
}
uksort($byP, fn ($a, $b) => ($a === 0) <=> ($b === 0) ?: $a <=> $b);
$kbadge = fn (?float $v, string $key, string $suf = '%', int $dec = 0) => $v === null ? "<span class='vmo-muted'>—</span>" : Menu::badge(number_format($v, $dec, ',', '.') . $suf, $col[Kpi::status($v, $key)]);
echo "<div class='card vg-card cm-card mt-3'><div class='card-header'><h3 class='card-title'>Performance por iniciativa</h3><span class='ms-auto text-secondary small'>" . count($D['rows']) . " iniciativa(s)</span></div><div class='card-body'><div class='vg-table vg-kp'>";
if (!$byP) {
    echo "<div class='text-center text-secondary py-3'>Nenhuma iniciativa visível.</div>";
}
foreach ($byP as $key => $list) {
    [$title, $color] = $persp[$key];
    echo "<section class='vg-grp' style='--g:" . $e($color) . "'>" . GroupView::groupHead($title, count($list), ['Resp.', 'Situação', 'Progresso', 'Concluídas', 'No prazo', 'Atras.', 'SPI']) . "<div class='vg-grp-body'>";
    foreach ($list as $r) {
        $k = $r['k'];
        $ini = $r['ini'];
        $o = Committee::owner($ini);
        echo "<div class='vg-item" . ($k['overdue'] ? ' late' : '') . "'><div class='vg-row'>"
            . "<div class='vg-cell-name'><a href='" . $e(Menu::url('/front/initiative.php?tab=tasks&id=' . $ini['id'])) . "'>" . $e($ini['name']) . "</a><small>" . $e($ini['code']) . " · " . $e($o[1]) . "</small></div>"
            . "<div>" . ($o[2] ? GroupView::avatar($o[2]) : GroupView::avatarName($o[1])) . "</div>"
            . GroupView::statusCell(preg_replace('/^\d+\.\s*/', '', Initiative::STATUSES[$ini['status']][0]), Initiative::STATUSES[$ini['status']][1])
            . "<div class='vg-cell-tl'>" . GroupView::bar($r['progress'], $color, 'Progresso da iniciativa') . "</div>"
            . "<div class='vg-cell-n'>" . $k['done'] . "<small class='text-secondary'>/" . $k['total'] . "</small></div>"
            . "<div>" . $kbadge($k['otd'], 'otd') . "</div><div class='vg-cell-n'>" . ($k['overdue'] ? "<span class='text-danger'>" . $k['overdue'] . "</span>" : '0') . "</div>"
            . "<div>" . $kbadge($r['spi'], 'spi', '', 2) . "</div></div></div>";
    }
    echo "</div></section>";
}
echo "</div></div></div>";

// por responsável: tabela no mesmo formato (uma linha por pessoa, carga empilhada)
$omax = max(1, ...array_map(fn ($o) => $o['open'] + $o['done'], $D['owners'] ?: [['open' => 1, 'done' => 0]]));
echo "<div class='card vg-card cm-card'><div class='card-header'><h3 class='card-title'>Performance por responsável</h3><div class='cm-legend ms-auto'><span><i style='background:#22c55e'></i>Concluídas</span><span><i style='background:#6366f1'></i>Abertas</span><span><i style='background:#ef4444'></i>Atrasadas</span></div></div><div class='card-body'><div class='vg-table vg-ow'>";
if (!$D['owners']) {
    echo "<div class='text-center text-secondary py-3'>Nenhuma tarefa cadastrada ainda: os KPIs aparecem quando as iniciativas tiverem tarefas.</div>";
} else {
    echo "<section class='vg-grp' style='--g:#6366f1'>" . GroupView::groupHead('Carga e entrega das tarefas', count($D['owners']), ['Resp.', 'Carga', 'Abertas', 'Atras.', 'Concluídas', 'No prazo']) . "<div class='vg-grp-body'>";
    foreach ($D['owners'] as $o) {
        $tot2 = $o['open'] + $o['done'];
        $stack = "<div class='kp-stack' style='width:" . round($tot2 / $omax * 100) . "%'><i style='flex:" . $o['done'] . ";background:#22c55e' title='Concluídas'></i><i style='flex:" . max(0, $o['open'] - $o['late']) . ";background:#6366f1' title='Abertas'></i><i style='flex:" . $o['late'] . ";background:#ef4444' title='Atrasadas'></i></div>";
        echo "<div class='vg-item" . ($o['late'] ? ' late' : '') . "'><div class='vg-row'><div class='vg-cell-name'><b class='vg-nm'>" . $e($o['user'] ? Db::userName($o['user']) : 'Sem responsável') . "</b><small>" . $tot2 . " tarefa(s)</small></div>"
            . "<div>" . GroupView::avatar((int) $o['user']) . "</div><div class='vg-cell-tl'>$stack</div><div class='vg-cell-n'>" . $o['open'] . "</div><div class='vg-cell-n'>" . ($o['late'] ? "<span class='text-danger'>" . $o['late'] . "</span>" : '0') . "</div>"
            . "<div class='vg-cell-n'>" . $o['done'] . "</div><div>" . $kbadge($o['otd'], 'otd') . "</div></div></div>";
    }
    echo "</div></section>";
}
echo "</div></div></div>";
echo Menu::script('grupos.js');

echo "<script>(function(){var tiles=document.querySelectorAll('.kp-tile'),cur=null;function show(k){document.querySelectorAll('.kp-panel').forEach(function(p){p.hidden=true});tiles.forEach(function(t){t.classList.remove('sel')});if(k&&k!==cur){var p=document.getElementById('kp-'+k);p.hidden=false;document.querySelector('.kp-tile[data-kpi=\"'+k+'\"]').classList.add('sel');cur=k;p.scrollIntoView({behavior:'smooth',block:'nearest'});}else{cur=null;}}"
    . "tiles.forEach(function(t){t.addEventListener('click',function(){show(t.dataset.kpi)});t.addEventListener('keydown',function(e){if(e.key==='Enter'||e.key===' '){e.preventDefault();show(t.dataset.kpi)}})});document.querySelectorAll('[data-close]').forEach(function(b){b.addEventListener('click',function(){show(null)})});})();</script>";

echo "<h3 class='mt-4'>KPIs manuais e metas</h3><p class='text-secondary'>Indicadores de negócio com meta, lançados à mão. Dado ausente aparece como \"sem dado\" e dado vencido como \"desatualizado\". Cumprimento = (observado − baseline) ÷ (meta − baseline).</p>";

$items = Indicator::visible();
echo "<div class='card mb-3'><div class='table-responsive'><table class='table table-vcenter card-table'><thead><tr><th>Código</th><th>Indicador</th><th>Direção</th><th>Baseline</th><th>Meta</th><th>Atual</th><th>Cumprimento</th><th>Situação</th><th></th></tr></thead><tbody>";
foreach ($items as $i) {
    $s = Indicator::state($i);
    $badge = ['nodata' => ['sem dado', '#94a3b8'], 'stale' => ['desatualizado', '#f59e0b'], 'ok' => ['atualizado', '#22c55e']][$s['status']];
    echo "<tr><td>" . $e($i['code']) . "</td><td><b>" . $e($i['name']) . "</b><div class='small vmo-muted'>" . $e($i['unit']) . ((int) $i['initiatives_id'] ? ' · ' . $e(Db::row('initiatives', (int) $i['initiatives_id'])['code'] ?? '') : ' · global') . "</div></td>"
        . "<td>" . ($i['direction'] === 'reduce' ? 'Reduzir' : 'Aumentar') . "</td><td>" . $e($i['baseline_value'] ?? '—') . "</td><td>" . $e($i['target'] ?? '—') . "</td>"
        . "<td>" . ($s['value'] === null ? "<span class='vmo-muted'>sem dado</span>" : $e(rtrim(rtrim(number_format($s['value'], 4, ',', '.'), '0'), ',')) . "<div class='small vmo-muted'>" . $e(Menu::date($s['last'])) . "</div>") . "</td>"
        . "<td>" . Menu::pct($s['progress'], 'sem dado') . "</td><td>" . Menu::badge($badge[0], $badge[1]) . "</td><td>";
    if (Access::canUpdate()) {
        echo "<details class='vmo-inline'><summary>Registrar medição</summary>" . $form('indicator_value', ['indicators_id' => $i['id']], $field('Data do período', $input('period', Db::today(), "type='date'")) . $field('Valor', $input('observed', '', 'inputmode=decimal required')), 'Registrar') . "</details>";
    }
    echo "</td></tr>";
}
if (!$items) {
    echo "<tr><td colspan='9' class='text-center text-secondary py-4'>Nenhum KPI manual cadastrado.</td></tr>";
}
echo "</tbody></table></div></div>";

if (Access::canCreate()) {
    $iopts = [0 => 'Global (portfólio)'];
    foreach (Access::visibleInitiatives() as $r) {
        $iopts[$r['id']] = $r['code'] . ' · ' . $r['name'];
    }
    $inner = "<div class='vmo-grid2'>" . $field('Código', $input('code', '', 'required')) . $field('Nome', $input('name', '', 'required')) . $field('Unidade', $input('unit'))
        . $field('Direção desejada', Menu::select('direction', ['increase' => 'Aumentar', 'reduce' => 'Reduzir'], 'increase')) . $field('Baseline', $input('baseline_value', '', 'inputmode=decimal')) . $field('Meta', $input('target', '', 'inputmode=decimal'))
        . $field('Cadência (dias)', $input('cadence_days', '30', 'type=number')) . $field('Fonte', $input('source')) . $field('Dono', Menu::userSelect('users_id_owner')) . $field('Iniciativa', Menu::select('initiatives_id', $iopts, 0)) . '</div>';
    echo "<div class='card'><div class='card-header'><h3 class='card-title'>Novo KPI manual</h3></div><div class='card-body'>" . $form('indicator_save', [], $inner, 'Criar KPI') . "</div></div>";
}
Html::footer();
