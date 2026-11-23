<?php

/**
 * Painel executivo (RF15). Cada cartão e cada linha abrem uma gaveta lateral com toda a composição,
 * o mesmo padrão dos demais plugins. Acesso aplicado antes de somar (RN09).
 */

use GlpiPlugin\Vmo\Access;
use GlpiPlugin\Vmo\Committee;
use GlpiPlugin\Vmo\Calc;
use GlpiPlugin\Vmo\Dashboard;
use GlpiPlugin\Vmo\Db;
use GlpiPlugin\Vmo\GroupView;
use GlpiPlugin\Vmo\Initiative;
use GlpiPlugin\Vmo\Measurement;
use GlpiPlugin\Vmo\Menu;
use GlpiPlugin\Vmo\Outbox;
use GlpiPlugin\Vmo\Settings;

Session::checkLoginUser();
if (!Access::canView()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}
$e = fn ($v) => Menu::e($v);
$d = Dashboard::build();
$finance = $d['finance'];
$cur = $d['cur'];
$rows = $d['rows'];
$tot = $d['portfolio']['totals'];
$s = $d['sums'];
$th = Settings::thresholds();
$money = fn (?float $v, string $c = '') => Menu::money($v, $c ?: $cur);
$link = fn (int $id, string $tab = '') => Menu::url('/front/initiative.php?id=' . $id . ($tab ? '&tab=' . $tab : ''));
$count = fn (array $by, string $k) => (int) ($by[$k] ?? 0);

$running = $count($tot['by_status'], 'running');
$critical = $count($tot['by_health'], 'red');
$attentionN = count($d['attention']);
$realization = $finance ? Calc::valueRealization($s['validated'], $s['planned']) : null;
$roi = $finance ? Calc::roi($s['validated'], $s['actual'] > 0 ? $s['actual'] : null) : null;
$budgetVar = ($finance && $s['budget'] > 0) ? ($s['forecast_b'] - $s['budget']) / $s['budget'] * 100 : null;
$riskRelevant = count(array_filter($rows, fn ($r) => ($r['max_risk'] ?? 0) >= (int) $th['risk_yellow']));

// ------------------------------------------------------------------ gavetas
$templates = '';
$dr = function (string $key, string $title, string $icon, string $color, string $html) use (&$templates, $e): void {
    $templates .= "<template id='dr-" . $e($key) . "' data-title='" . $e($title) . "' data-icon='" . $e($icon) . "' data-color='" . $e($color) . "'><div class='vmo-dr'>$html</div></template>";
};
$item = fn (string $title, string $meta, string $right, string $color = '#3b82f6', string $href = '') => "<div class='vmo-dr-item' style='--oc:$color'><div class='d-flex justify-content-between gap-2'><div>"
    . ($href ? "<a class='t' href='" . $e($href) . "'>" . $e($title) . "</a>" : "<div class='t'>" . $e($title) . "</div>") . "<div class='m'>$meta</div></div><div class='text-end fw-bold'>$right</div></div></div>";
$grid = fn (array $cells) => "<div class='vmo-dr-grid'>" . implode('', array_map(fn ($l, $v) => "<div class='c'><div class='l'>" . Menu::e($l) . "</div><div class='v'>$v</div></div>", array_keys($cells), $cells)) . "</div>";

// carteira completa
$html = "<div class='vmo-dr-lead'>Todas as iniciativas que você pode ver, da mais crítica para a mais saudável.</div>";
$sorted = $rows;
usort($sorted, fn ($a, $b) => ['red' => 0, 'yellow' => 1, 'gray' => 2, 'green' => 3][$a['health']] <=> ['red' => 0, 'yellow' => 1, 'gray' => 2, 'green' => 3][$b['health']]);
foreach ($sorted as $r) {
    $html .= $item($r['code'] . ' · ' . $r['name'], Initiative::statusBadge($r['status']) . ' ' . Menu::healthBadge($r['health']) . ' · gestor ' . $e($r['manager']),
        $r['progress'] === null ? "<span class='vmo-muted'>sem dado</span>" : number_format($r['progress'], 0) . '%', Menu::HEALTH[$r['health']][1], $link((int) $r['id']));
}
$dr('portfolio', 'Portfólio de iniciativas (' . $tot['count'] . ')', 'ti-briefcase', '#6366f1', $html);

if ($finance) {
    // valor validado
    $html = "<div class='vmo-dr-lead'>Só entra aqui o benefício <b>validado por outra pessoa</b>, com a revisão vigente de cada mês, multiplicado pela atribuição a cada iniciativa.</div>";
    $sum = 0.0;
    foreach ($rows as $r) {
        if (($r['value_validated'] ?? 0) > 0) {
            $html .= $item($r['code'] . ' · ' . $r['name'], 'previsto ' . $e($money($r['value_planned'] ?? null, $r['currency'])) . ' · realização ' . ($r['realization'] === null ? 'n/a' : number_format($r['realization'], 0) . '%'),
                $e($money($r['value_validated'], $r['currency'])), '#22c55e', $link((int) $r['id'], 'benefits'));
            $sum += $r['currency'] === $cur ? (float) $r['value_validated'] : 0;
        }
    }
    $html .= "<div class='vmo-dr-total'><span>Total (" . $e($cur) . ")</span><span>" . $e($money($s['validated'])) . "</span></div>" . ($s['other'] ? "<div class='vmo-dr-lead'>Existem iniciativas em outras moedas; elas não são somadas sem taxa de câmbio registrada.</div>" : '');
    $dr('validated', 'Benefício validado', 'ti-circle-check', '#22c55e', $html);

    // previsto
    $html = "<div class='vmo-dr-lead'>O que os benefícios aprovados deveriam ter entregue até hoje, conforme o plano mensal.</div>";
    foreach ($rows as $r) {
        if (($r['value_planned'] ?? 0) > 0) {
            $html .= $item($r['code'] . ' · ' . $r['name'], 'validado ' . $e($money($r['value_validated'] ?? 0.0, $r['currency'])), $e($money($r['value_planned'], $r['currency'])), '#0ea5e9', $link((int) $r['id'], 'benefits'));
        }
    }
    $html .= "<div class='vmo-dr-total'><span>Total (" . $e($cur) . ")</span><span>" . $e($money($s['planned'])) . "</span></div>";
    $dr('planned', 'Benefício previsto', 'ti-target', '#0ea5e9', $html);

    // investimento
    $html = $grid(['Orçamento (baseline)' => $e($money($s['budget'])), 'Previsão atual (todas)' => $e($money($s['forecast'])), 'Realizado' => $e($money($s['actual'])), 'Saldo da previsão' => $e($money($s['forecast'] - $s['actual']))]);
    foreach ($rows as $r) {
        if (($r['actual'] ?? 0) > 0 || ($r['forecast'] ?? 0) > 0) {
            $var = ($r['budget'] ?? 0) > 0 ? (($r['forecast'] ?? 0) - $r['budget']) / $r['budget'] * 100 : null;
            $html .= $item($r['code'] . ' · ' . $r['name'], 'orçamento ' . $e($money($r['budget'] ?? null, $r['currency'])) . ' · previsão ' . $e($money($r['forecast'] ?? null, $r['currency'])) . ($var === null ? '' : ' (' . ($var >= 0 ? '+' : '') . number_format($var, 1, ',', '.') . '%)'),
                $e($money((float) ($r['actual'] ?? 0), $r['currency'])), '#f59e0b', $link((int) $r['id'], 'costs'));
        }
    }
    $dr('invest', 'Investimento realizado', 'ti-coin', '#f59e0b', $html);

    // ROI
    $html = "<div class='vmo-dr-lead'>ROI = (benefício validado − custo real) ÷ custo real. Negativo significa que o benefício ainda não cobriu o investimento; é normal no início da mensuração.</div>"
        . $grid(['ROI do portfólio' => $roi === null ? 'n/a' : number_format($roi, 1, ',', '.') . '%', 'Realização de valor' => $realization === null ? 'n/a' : number_format($realization, 1, ',', '.') . '%']);
    foreach ($rows as $r) {
        if (($r['actual'] ?? 0) > 0) {
            $html .= $item($r['code'] . ' · ' . $r['name'], 'validado ' . $e($money($r['value_validated'] ?? 0.0, $r['currency'])) . ' ÷ custo ' . $e($money((float) $r['actual'], $r['currency'])),
                $r['roi'] === null ? 'n/a' : number_format($r['roi'], 1, ',', '.') . '%', ($r['roi'] ?? -1) >= 0 ? '#22c55e' : '#ef4444', $link((int) $r['id']));
        }
    }
    $dr('roi', 'Retorno sobre o investimento', 'ti-chart-line', '#a855f7', $html);
}

// riscos e atenção
$html = "<div class='vmo-dr-lead'>Iniciativas com atraso, custo acima do limite ou risco relevante (exposição ≥ " . (int) $th['risk_yellow'] . "). Limites configuráveis na tela de Configuração.</div>";
foreach ($d['attention'] as $r) {
    $html .= $item($r['code'] . ' · ' . $r['name'], Menu::healthBadge($r['health']) . ' · ' . $e(implode(' · ', $r['why']) ?: 'sem dados críticos'), $e($r['max_risk'] ? 'risco ' . $r['max_risk'] : ''), Menu::HEALTH[$r['health']][1], $link((int) $r['id'], 'risks'));
}
$dr('risk', 'Pedem atenção (' . $attentionN . ')', 'ti-alert-triangle', '#ef4444', $html . ($attentionN ? '' : "<div class='vmo-muted'>Nenhuma iniciativa em atenção.</div>"));

// decisões pendentes para o usuário
$pend = [];
foreach (Access::visibleInitiatives(['status' => 'review']) as $i) {
    if (Initiative::canDecide($i)) {
        $pend[] = ['t' => $i['code'] . ' · ' . $i['name'], 'm' => 'proposta para aprovar', 'u' => $link((int) $i['id'])];
    }
}
foreach ([] as $pm) {
    $pend[] = ['t' => $pm['benefit']['name'], 'm' => 'medição de ' . substr($pm['measurement']['period'], 0, 7) . ' para validar', 'u' => Menu::url('/front/benefit.php?id=' . $pm['benefit']['id'])];
}
$html = "<div class='vmo-dr-lead'>O que depende de uma decisão sua agora.</div>";
foreach ($pend as $x) {
    $html .= $item($x['t'], $e($x['m']), "<i class='ti ti-chevron-right'></i>", '#14b8a6', $x['u']);
}
$dr('pending', 'Aguardando a sua decisão (' . count($pend) . ')', 'ti-gavel', '#14b8a6', $html . ($pend ? '' : "<div class='vmo-muted'>Nada pendente.</div>"));

// gavetas por saúde e por estágio
foreach (Menu::HEALTH as $hk => [$hl, $hc, $hi]) {
    $html = "<div class='vmo-dr-lead'>Iniciativas com saúde <b>" . $e($hl) . "</b>.</div>";
    $n = 0;
    foreach ($rows as $r) {
        if ($r['health'] === $hk) {
            $n++;
            $html .= $item($r['code'] . ' · ' . $r['name'], Initiative::statusBadge($r['status']) . ' · gestor ' . $e($r['manager']) . ($r['delay'] ? ' · ' . $r['delay'] . ' d.ú. de atraso' : ''), $r['progress'] === null ? "<span class='vmo-muted'>sem dado</span>" : number_format($r['progress'], 0) . '%', $hc, $link((int) $r['id']));
        }
    }
    $dr('health-' . $hk, 'Saúde: ' . $hl . ' (' . $n . ')', $hi, $hc, $html . ($n ? '' : "<div class='vmo-muted'>Nenhuma iniciativa.</div>"));
}
foreach (Initiative::STATUSES as $sk => [$sl, $sc, $si]) {
    $html = "<div class='vmo-dr-lead'>Iniciativas na situação <b>" . $e($sl) . "</b>.</div>";
    $n = 0;
    foreach ($rows as $r) {
        if ($r['status'] === $sk) {
            $n++;
            $html .= $item($r['code'] . ' · ' . $r['name'], Menu::healthBadge($r['health']) . ' · gestor ' . $e($r['manager']), $r['progress'] === null ? "<span class='vmo-muted'>sem dado</span>" : number_format($r['progress'], 0) . '%', $sc, $link((int) $r['id']));
        }
    }
    if ($n) {
        $dr('stage-' . $sk, $sl . ' (' . $n . ')', $si, $sc, $html);
    }
}

// uma gaveta por iniciativa
foreach ($rows as $r) {
    $id = (int) $r['id'];
    $cells = ['Situação' => Initiative::statusBadge($r['status']), 'Saúde' => Menu::healthBadge($r['health']),
        'Progresso' => $r['progress'] === null ? 'sem dado' : number_format($r['progress'], 1, ',', '.') . '%', 'Atraso' => $r['delay'] === null ? 'sem baseline' : $r['delay'] . ' d.ú.',
        'Gestor' => $e($r['manager']), 'Patrocinador' => $e($r['sponsor']), 'Término previsto' => $e(Menu::date($r['forecast_end'] ?: $r['planned_end'])), 'Maior risco' => $e($r['max_risk'] ?? '—')];
    if ($finance) {
        $cells += ['Orçamento' => $e($money($r['budget'] ?? null, $r['currency'])), 'Custo real' => $e($money((float) ($r['actual'] ?? 0), $r['currency'])),
            'Benefício validado' => $e($money($r['value_validated'] ?? 0.0, $r['currency'])), 'ROI' => ($r['roi'] ?? null) === null ? 'n/a' : number_format($r['roi'], 1, ',', '.') . '%'];
    }
    $html = $grid($cells);
    $html .= '<h4>Riscos abertos</h4>';
    foreach (array_slice($d['risks'][$id] ?? [], 0, 3) as $k) {
        $html .= $item($k['title'], 'responsável ' . $e(Db::userName((int) $k['users_id_owner'])) . ($k['due_date'] ? ' · prazo ' . $e(Menu::date($k['due_date'])) : ''), (string) $k['exposure'], \GlpiPlugin\Vmo\Risk::exposureColor((int) $k['exposure']));
    }
    $html .= empty($d['risks'][$id]) ? "<div class='vmo-muted'>Sem riscos abertos.</div>" : '';
    $html .= '<h4>Próximos marcos</h4>';
    foreach (array_slice($d['milestones'][$id] ?? [], 0, 3) as $k) {
        $html .= $item($k['name'], 'responsável ' . $e(Db::userName((int) $k['users_id_responsible'])), $e(Menu::date($k['planned_end'])), '#f59e0b');
    }
    $html .= empty($d['milestones'][$id]) ? "<div class='vmo-muted'>Sem marcos abertos.</div>" : '';
    $html .= '<h4>Benefícios</h4>';
    foreach ($d['benefits'][$id] ?? [] as $b) {
        $html .= $item($b['benefit']['name'], \GlpiPlugin\Vmo\Benefit::TYPES[$b['benefit']['type']] . ' · atribuição ' . rtrim(rtrim(number_format($b['percent'], 3, ',', ''), '0'), ',') . '%', '', '#22c55e', Menu::url('/front/benefit.php?id=' . $b['benefit']['id']));
    }
    $html .= empty($d['benefits'][$id]) ? "<div class='vmo-muted'>Nenhum benefício alocado.</div>" : '';
    $html .= "<div class='mt-3 d-flex gap-2'>" . Menu::button('Abrir ficha completa', $link($id), 'ti-external-link') . "</div>";
    $dr('ini-' . $id, $r['code'] . ' · ' . $r['name'], 'ti-briefcase', Menu::HEALTH[$r['health']][1], $html);
}

// ------------------------------------------------------------------ gráficos executivos (SVG, sem dependências)
$k = fn (float $v): string => $v >= 1000000 ? number_format($v / 1000000, 1, ',', '.') . ' mi' : ($v >= 1000 ? number_format($v / 1000, $v >= 10000 ? 0 : 1, ',', '.') . ' mil' : number_format($v, 0, ',', '.'));

/** Curva de valor: acumulado previsto (tracejado) × validado (área), com o "hoje" e a distância entre as duas. */
$curve = function (array $series) use ($e, $cur, $k): string {
    $w = 760; $h = 400; $pl = 52; $pr = 70; $pt = 24; $pb = 30;
    $n = count($series);
    $max = max(1.0, ...array_map(fn ($p) => max($p['cum'], $p['cumPlanned']), $series)) * 1.12;
    $x = fn (int $i) => $pl + ($w - $pl - $pr) * $i / max(1, $n - 1);
    $y = fn (float $v) => $pt + ($h - $pt - $pb) * (1 - $v / $max);
    $svg = "<svg class='vmo-chart' viewBox='0 0 $w $h' width='100%' role='img' aria-label='Curva de valor acumulado: previsto e validado'>"
        . "<defs><linearGradient id='gv' x1='0' y1='0' x2='0' y2='1'><stop offset='0%' stop-color='#22c55e' stop-opacity='.55'/><stop offset='100%' stop-color='#22c55e' stop-opacity='0'/></linearGradient>"
        . "<linearGradient id='gp' x1='0' y1='0' x2='0' y2='1'><stop offset='0%' stop-color='#38bdf8' stop-opacity='.22'/><stop offset='100%' stop-color='#38bdf8' stop-opacity='0'/></linearGradient></defs>";
    for ($g = 0; $g <= 4; $g++) {
        $gy = $pt + ($h - $pt - $pb) * $g / 4;
        $svg .= "<line x1='$pl' x2='" . ($w - $pr) . "' y1='$gy' y2='$gy' stroke='rgba(148,163,184,.16)'/><text x='" . ($pl - 8) . "' y='" . ($gy + 3) . "' text-anchor='end'>" . $e($k($max * (1 - $g / 4))) . "</text>";
    }
    $todayI = 0;
    foreach ($series as $i => $p) {
        if (!$p['future']) {
            $todayI = $i;
        }
        $svg .= "<text x='" . $x($i) . "' y='" . ($h - 8) . "' text-anchor='middle'>" . $e($p['label']) . "</text>";
    }
    $path = fn (string $key) => implode(' ', array_map(fn ($p, $i) => ($i ? 'L' : 'M') . round($x($i), 1) . ' ' . round($y($p[$key]), 1), $series, array_keys($series)));
    $base = $h - $pb;
    $svg .= "<path d='" . $path('cumPlanned') . " L" . round($x($n - 1), 1) . " $base L" . round($x(0), 1) . " $base Z' fill='url(#gp)'/>"
        . "<path d='" . $path('cumPlanned') . "' fill='none' stroke='#38bdf8' stroke-width='2' stroke-dasharray='6 5'/>";
    // validado só até hoje
    $past = array_slice($series, 0, $todayI + 1, true);
    $vp = implode(' ', array_map(fn ($p, $i) => ($i ? 'L' : 'M') . round($x($i), 1) . ' ' . round($y($p['cum']), 1), $past, array_keys($past)));
    $svg .= "<path d='$vp L" . round($x($todayI), 1) . " $base L" . round($x(0), 1) . " $base Z' fill='url(#gv)'/><path d='$vp' fill='none' stroke='#22c55e' stroke-width='3.2' stroke-linejoin='round' stroke-linecap='round'/>";
    foreach ($past as $i => $p) {
        $svg .= "<circle cx='" . round($x($i), 1) . "' cy='" . round($y($p['cum']), 1) . "' r='3.4' fill='#0b1220' stroke='#22c55e' stroke-width='2'><title>" . $e($p['label']) . ': validado acumulado ' . $e(Calc::formatMoney($p['cum'], $cur)) . "</title></circle>";
    }
    // hoje
    $tx = round($x($todayI), 1);
    $cv = $series[$todayI]['cum'];
    $cp = $series[$todayI]['cumPlanned'];
    $svg .= "<line x1='$tx' x2='$tx' y1='$pt' y2='$base' stroke='#f59e0b' stroke-dasharray='3 4'/><text x='$tx' y='" . ($pt - 8) . "' text-anchor='middle' style='fill:#f59e0b;font-weight:700'>hoje</text>";
    if ($cp > $cv) {
        $svg .= "<line x1='$tx' x2='$tx' y1='" . round($y($cp), 1) . "' y2='" . round($y($cv), 1) . "' stroke='#f59e0b' stroke-width='3'/>";
    }
    $last = $n - 1;
    $svg .= "<text x='" . ($x($last) + 8) . "' y='" . ($y($series[$last]['cumPlanned']) + 3) . "' style='fill:#38bdf8;font-weight:700'>" . $e($k($series[$last]['cumPlanned'])) . "</text>"
        . "<text x='" . ($tx + 8) . "' y='" . ($y($cv) - 8) . "' style='fill:#22c55e;font-weight:800;font-size:12px'>" . $e($k($cv)) . "</text>";
    return $svg . '</svg>';
};

/** Medidor semicircular (realização de valor). */
$gauge = function (?float $pct, string $color) use ($e): string {
    $len = M_PI * 80;
    $v = $pct === null ? 0 : max(0, min(100, $pct));
    return "<svg viewBox='0 0 200 118' width='210' role='img' aria-label='Realização de valor'><path d='M20 100 A80 80 0 0 1 180 100' fill='none' stroke='rgba(148,163,184,.2)' stroke-width='16' stroke-linecap='round'/>"
        . "<path d='M20 100 A80 80 0 0 1 180 100' fill='none' stroke='$color' stroke-width='16' stroke-linecap='round' stroke-dasharray='" . round($len * $v / 100, 1) . " $len'/>"
        . "<text x='100' y='84' text-anchor='middle' style='font-size:30px;font-weight:800;fill:currentColor'>" . ($pct === null ? 'n/a' : number_format($pct, 0) . '%') . "</text>"
        . "<text x='100' y='104' text-anchor='middle' style='font-size:9px;fill:#94a3b8'>do valor previsto já comprovado</text></svg>";
};

/** Mini gráfico de linha dentro dos cartões. */
$spark = function (array $vals, string $color): string {
    $n = count($vals);
    if ($n < 2) {
        return '';
    }
    $max = max(1.0, ...$vals);
    $pts = implode(' ', array_map(fn ($v, $i) => round(100 * $i / ($n - 1), 1) . ',' . round(26 - 24 * $v / $max, 1), $vals, array_keys($vals)));
    return "<svg viewBox='0 0 100 28' preserveAspectRatio='none' width='100%' height='30' style='margin-top:.35rem'><polyline points='$pts' fill='none' stroke='$color' stroke-width='2' vector-effect='non-scaling-stroke' stroke-linejoin='round'/></svg>";
};

$donut = function (array $by, int $total) use ($e): string {
    $r = 52; $c = 2 * M_PI * $r; $off = 0;
    $svg = "<svg viewBox='0 0 140 140' width='150' role='img' aria-label='Iniciativas por saúde'><g transform='rotate(-90 70 70)'><circle cx='70' cy='70' r='$r' fill='none' stroke='rgba(148,163,184,.15)' stroke-width='16'/>";
    foreach (['green', 'yellow', 'red', 'gray'] as $kk) {
        $n = (int) ($by[$kk] ?? 0);
        if ($total && $n) {
            $len = $c * $n / $total;
            $svg .= "<circle cx='70' cy='70' r='$r' fill='none' stroke='" . Menu::HEALTH[$kk][1] . "' stroke-width='16' stroke-dasharray='" . round($len - 2, 2) . ' ' . round($c - $len + 2, 2) . "' stroke-dashoffset='" . round(-$off, 2) . "'><title>" . $e(Menu::HEALTH[$kk][0]) . ": $n</title></circle>";
            $off += $len;
        }
    }
    return $svg . "</g><text x='70' y='68' text-anchor='middle' style='font-size:26px;font-weight:800;fill:currentColor'>$total</text><text x='70' y='86' text-anchor='middle' style='font-size:9px;fill:#94a3b8'>iniciativas</text></svg>";
};

/** Barras horizontais "bullet": trilho = previsto, preenchimento = validado. */
$bullets = function (array $items, string $color) use ($e): string {
    $out = '';
    foreach ($items as $it) {
        $pct = $it['max'] > 0 ? max(0, min(100, 100 * $it['value'] / $it['max'])) : 0;
        $mark = isset($it['mark']) && $it['max'] > 0 ? max(0, min(100, 100 * $it['mark'] / $it['max'])) : null;
        $out .= "<div class='vmo-bl'><div class='vmo-bl-h'><span class='n'>" . $e($it['label']) . "</span><span class='v'>" . $it['right'] . "</span></div>"
            . "<div class='vmo-bl-track'><span style='width:$pct%;background:" . ($it['color'] ?? $color) . "'></span>" . ($mark !== null ? "<i style='left:$mark%' title='" . $e($it['markTitle'] ?? '') . "'></i>" : '') . "</div>"
            . "<div class='vmo-bl-s'>" . $it['sub'] . "</div></div>";
    }
    return $out ?: "<div class='vmo-muted'>Sem dados para exibir.</div>";
};

/** Linha do tempo do portfólio (Gantt resumido): período planejado, progresso, previsão e hoje. */
$timeline = function (array $rows) use ($e): string {
    $rows = array_values(array_filter($rows, fn ($r) => $r['planned_start'] && $r['planned_end'] && !in_array($r['status'], ['rejected', 'cancelled'], true)));
    if (!$rows) {
        return "<div class='vmo-muted'>Nenhuma iniciativa com datas planejadas.</div>";
    }
    $t0 = min(array_map(fn ($r) => strtotime($r['planned_start']), $rows));
    $t1 = max(array_map(fn ($r) => strtotime($r['forecast_end'] && $r['forecast_end'] > $r['planned_end'] ? $r['forecast_end'] : $r['planned_end']), $rows));
    $t0 = strtotime(date('Y-m-01', min($t0, time())));
    $t1 = max($t1, time() + 30 * 86400);
    $span = max(86400, $t1 - $t0);
    $pct = fn (int $t) => max(0, min(100, 100 * ($t - $t0) / $span));
    $out = "<div class='vmo-gantt'><div class='vmo-g-axis'>";
    for ($m = $t0; $m <= $t1; $m = strtotime('+1 month', $m)) {
        $out .= "<span style='left:" . round($pct($m), 2) . "%'>" . date('m/y', $m) . "</span>";
    }
    $out .= "</div>";
    foreach (array_slice($rows, 0, 14) as $r) {
        $a = $pct(strtotime($r['planned_start']));
        $b = $pct(strtotime($r['planned_end']));
        $c = Menu::HEALTH[$r['health']][1];
        $prog = $r['progress'] === null ? 0 : max(0, min(100, $r['progress']));
        $fe = $r['forecast_end'] && $r['forecast_end'] > $r['planned_end'] ? $pct(strtotime($r['forecast_end'])) : null;
        $out .= "<div class='vmo-g-row' data-drawer='ini-" . (int) $r['id'] . "' tabindex='0' role='button'><div class='vmo-g-l' title='" . $e($r['name']) . "'><b>" . $e($r['code']) . "</b> " . $e(mb_strimwidth($r['name'], 0, 34, '…')) . "</div>"
            . "<div class='vmo-g-t'><div class='vmo-g-bar' style='left:" . round($a, 2) . "%;width:" . round(max(1.2, $b - $a), 2) . "%;--c:$c'><span style='width:$prog%'></span><em>" . ($r['progress'] === null ? '' : number_format($prog, 0) . '%') . "</em></div>"
            . ($fe !== null ? "<div class='vmo-g-fc' style='left:" . round($b, 2) . "%;width:" . round(max(0, $fe - $b), 2) . "%' title='Previsão além do planejado'></div>" : '') . "</div></div>";
    }
    $out .= "<div class='vmo-g-today' style='left:calc(220px + (100% - 220px) * " . round($pct(time()) / 100, 4) . ")'><span>hoje</span></div></div>";
    return $out;
};

// ------------------------------------------------------------------ página
Menu::header('Dashboard executivo', 'dashboard');
echo Menu::title('Dashboard executivo', 'Atualizado em ' . date('d/m/Y H:i') . ' — clique em qualquer cartão, barra ou linha para abrir o detalhe ao lado.',
    Menu::button('KPIs', Menu::url('/front/indicators.php'), 'ti-gauge', 'btn-outline-primary')
    . Menu::button('Roadmap', Menu::url('/front/roadmap.php'), 'ti-route', 'btn-outline-primary'));

$msg = "O portfólio reúne <b>{$tot['count']} iniciativa(s)</b>" . ($running ? ", <b>$running em execução</b>" : '');
if (false && $finance && $s['planned'] > 0) {
    $msg .= '. Já foram <b>comprovados ' . $e($money($s['validated'])) . '</b> de ' . $e($money($s['planned'])) . ' previstos' . ($s['actual'] > 0 ? ', com ' . $e($money($s['actual'])) . ' investidos' : '');
}
$msg .= $attentionN ? '. <b>' . $attentionN . ' iniciativa(s) pedem atenção</b>' . ($critical ? " ($critical crítica(s))" : '') . '.' : '. Nenhuma iniciativa pede atenção.';
$gColor = $realization === null ? '#64748b' : ($realization >= 80 ? '#22c55e' : ($realization >= 50 ? '#f59e0b' : '#ef4444'));
echo "<div class='vmo-hero d-flex flex-wrap align-items-center justify-content-between gap-3'><div style='flex:1;min-width:280px'><div class='vmo-crumb'>Resumo para a diretoria</div><div class='big'>$msg</div>"
    . ($s['other'] ? "<div class='small'>Há valores em outras moedas; eles não são somados sem taxa de câmbio registrada (veja o detalhe de cada cartão).</div>" : '') . "</div>"
    . (false ? "<div class='text-center' data-drawer='validated' role='button' tabindex='0' style='cursor:pointer'>" . $gauge($realization, $gColor) . "<div class='small vmo-muted'>Realização de valor</div></div>" : '') . "</div>";

$cumVals = $finance ? array_column($d['series'], 'cum') : [];
$card = fn (string $key, string $label, string $value, string $sub, string $color, string $extra = '') => "<div class='vmo-kpi big vmo-click' data-drawer='$key' role='button' tabindex='0' style='--c:$color'><div class='vmo-kpi-label'>" . $e($label) . "</div><div class='vmo-kpi-value'>$value</div>$extra<div class='vmo-kpi-sub'>" . $e($sub) . "</div></div>";

echo "<div class='vmo-kpis6'>";
echo $card('portfolio', 'Portfólio', (string) $tot['count'], $running . ' em execução · ', '#6366f1');
// novas requisições = chamados abertos nas últimas 48 h ainda sem iniciativa vinculada (mesma regra do "NOVO" em Requisições)
global $DB;
$rqLinked = [];
foreach ($DB->request(['SELECT' => ['items_id'], 'FROM' => GlpiPlugin\Vmo\Db::t('item_links'), 'WHERE' => ['itemtype' => 'Ticket']]) as $r) {
    $rqLinked[] = (int) $r['items_id'];
}
$rqWhere = ['is_deleted' => 0, 'status' => [Ticket::INCOMING, Ticket::ASSIGNED, Ticket::PLANNED, Ticket::WAITING]] + getEntitiesRestrictCriteria('glpi_tickets');
if ($rqLinked) {
    $rqWhere[] = ['NOT' => ['id' => $rqLinked]];
}
$rqOpen = countElementsInTable('glpi_tickets', $rqWhere);
$rqNew = countElementsInTable('glpi_tickets', $rqWhere + [['date' => ['>=', date('Y-m-d H:i:s', time() - 48 * 3600)]]]);
echo "<a class='vmo-kpi big vmo-click' href='" . $e(Menu::url('/front/request.php')) . "' style='--c:#0ea5e9'><div class='vmo-kpi-label'>Novas requisições</div><div class='vmo-kpi-value'>$rqNew</div><div class='vmo-kpi-sub'>chamados das últimas 48 h · $rqOpen aguardando triagem</div></a>";
echo $card('risk', 'Pedem atenção', (string) $attentionN, $critical . ' crítica(s) · ' . $riskRelevant . ' com risco relevante', $critical ? '#ef4444' : '#f59e0b');
echo $card('pending', 'Sua decisão', (string) count($pend), 'propostas aguardando você', '#14b8a6');
echo "</div>";

// status de entrega de todas as iniciativas (alimenta o Roadmap)
$dl = array_count_values(array_column(GlpiPlugin\Vmo\Roadmap::items(), 'stage'));
$dlSub = ['prod' => 'já usadas pela operação', 'dev' => 'prontas, aguardando entrada em produção', 'wip' => 'ainda sendo construídas'];
echo "<div class='vmo-kpis6'>";
foreach (GlpiPlugin\Vmo\Roadmap::STAGES as $k => [$lb, $col]) {
    echo "<a class='vmo-kpi big vmo-click' href='" . $e(Menu::url('/front/roadmap.php')) . "' style='--c:$col'><div class='vmo-kpi-label'>" . $e($lb) . "</div><div class='vmo-kpi-value'>" . (int) ($dl[$k] ?? 0) . "</div><div class='vmo-kpi-sub'>" . $e($dlSub[$k]) . " · ver Roadmap</div></a>";
}
echo "</div>";

// linha 1: curva de valor + saúde
echo "<div class='row g-3 mb-3'>";
if (false && $finance && count($d['series']) > 1) {
    $last = end($d['series']);
    $gap = $last['cumPlanned'] - $last['cum'];
    echo "<div class='col-lg-8'><div class='vmo-panel'><h3>Curva de valor acumulado</h3><div class='sub'>Quanto do benefício previsto já foi comprovado, mês a mês (" . $e($cur) . ").</div>" . $curve($d['series'])
        . "<div class='vmo-legend'><span><i style='background:#38bdf8'></i>Previsto (acumulado)</span><span><i style='background:#22c55e'></i>Validado (acumulado)</span><span><i style='background:#f59e0b'></i>Distância hoje</span></div>"
        . "<div class='vmo-stat3'>"
        . "<div style='--c:#38bdf8'><span>Previsto até hoje</span><b>" . $e($money($s['planned'])) . "</b></div>"
        . "<div style='--c:#22c55e'><span>Comprovado até hoje</span><b>" . $e($money($s['validated'])) . "</b></div>"
        . "<div style='--c:#f59e0b'><span>Falta comprovar</span><b>" . $e($money(max(0.0, $s['planned'] - $s['validated']))) . "</b></div>"
        . "<div style='--c:#a78bfa'><span>Previsto no horizonte</span><b>" . $e($money($last['cumPlanned'])) . "</b></div></div></div></div>";
}
$total = max(1, (int) $tot['count']);
$healthyPct = (int) round(100 * $count($tot['by_health'], 'green') / $total);
$classified = $count($tot['by_health'], 'green') + $count($tot['by_health'], 'yellow') + $count($tot['by_health'], 'red');
$gc = !$classified ? '#64748b' : ($healthyPct >= 70 ? '#22c55e' : ($healthyPct >= 40 ? '#f59e0b' : '#ef4444'));
// medidor semicircular (raio 52, meia circunferência ≈ 163,4)
$arc = round(163.4 * $healthyPct / 100, 1);
$gauge2 = "<svg viewBox='0 0 140 84' class='ex-gauge'><path d='M18 70 A52 52 0 0 1 122 70' fill='none' stroke='rgba(148,163,184,.22)' stroke-width='13' stroke-linecap='round'/>"
    . "<path d='M18 70 A52 52 0 0 1 122 70' fill='none' stroke='$gc' stroke-width='13' stroke-linecap='round' stroke-dasharray='" . ($classified ? $arc : 0) . " 200'/>"
    . "<text x='70' y='62' text-anchor='middle' font-size='26' font-weight='700' fill='currentColor'>" . ($classified ? $healthyPct . '%' : '—') . "</text><text x='70' y='78' text-anchor='middle' font-size='8' fill='currentColor' opacity='.65'>" . ($classified ? 'NO PRAZO' : 'SEM DADOS') . "</text></svg>";
$seg = '';
$leg = '';
foreach (Menu::HEALTH as $kk => [$label, $color, $icon]) {
    $n = $count($tot['by_health'], $kk);
    $pc = (int) round(100 * $n / $total);
    if ($n) {
        $seg .= "<i style='flex:$n;background:$color' title='" . $e($label) . ": $n'></i>";
    }
    $leg .= "<div class='ex-hrow' data-drawer='health-$kk' role='button' tabindex='0' style='--c:$color'><span class='ex-dot'></span><span class='ex-hl'>" . $e($label) . "</span><b>$n</b><small>$pc%</small></div>";
}
$crit = $count($tot['by_health'], 'red');
$att = $count($tot['by_health'], 'yellow');
echo "<div class='col-lg-5'><div class='vmo-panel ex-panel'><div class='ex-head'><div><h3>Saúde do portfólio</h3><div class='sub'>Atraso, custo e risco contra os limites vigentes</div></div></div>"
    . "<div class='ex-gauge-wrap'>$gauge2<div class='ex-gauge-note'><b>$total</b> iniciativa(s) no portfólio<br><span class='vmo-muted'>" . $running . " em execução</span></div></div>"
    . "<div class='ex-seg'>$seg</div><div class='ex-hlist'>$leg</div>"
    . "<div class='ex-facts'><div><b style='color:#ef4444'>$crit</b><span>críticas</span></div><div><b style='color:#f59e0b'>$att</b><span>em atenção</span></div><div><b style='color:#22c55e'>" . $count($tot['by_health'], 'green') . "</b><span>no prazo</span></div></div></div></div>";

// funil de evolução: iniciativas que já alcançaram cada etapa (acumulado) e a conversão entre etapas
$order = ['brainstorm', 'draft', 'review', 'approved', 'running', 'delivered', 'measuring', 'closed'];
$stageOf = fn (string $st) => $st === 'suspended' ? array_search('running', $order, true) : array_search($st, $order, true);
$reached = array_fill(0, count($order), 0);
foreach ($tot['by_status'] as $st => $n) {
    $ix = $stageOf((string) $st);
    if ($ix === false) {
        continue;
    }
    for ($z = 0; $z <= $ix; $z++) {
        $reached[$z] += (int) $n;
    }
}
$top = max(1, $reached[0]);
$fun = '';
foreach ($order as $z => $kk) {
    [$label, $color, $icon] = Initiative::STATUSES[$kk];
    $n = $count($tot['by_status'], $kk);
    $w = max(14, round(100 * $reached[$z] / $top));
    $conv = $z > 0 && $reached[$z - 1] > 0 ? round(100 * $reached[$z] / $reached[$z - 1]) . '%' : '';
    $fun .= "<div class='ex-frow'><div class='ex-fname'><i class='ti $icon' style='color:$color'></i> " . $e(preg_replace('/^\d+\.\s*/', '', $label)) . "</div>"
        . "<div class='ex-fbar-wrap'><div class='ex-fbar'" . ($n ? " data-drawer='stage-$kk' role='button' tabindex='0'" : '') . " style='width:$w%;--c:$color'><b>" . $reached[$z] . "</b></div></div>"
        . "<div class='ex-fconv'>" . ($conv ? "<span title='conversão da etapa anterior'>$conv</span>" : '') . "</div><div class='ex-fnow' title='nesta etapa agora'>" . ($n ? $n . ' agora' : '') . "</div></div>";
}
$out = $count($tot['by_status'], 'rejected') + $count($tot['by_status'], 'cancelled');
echo "<div class='col-lg-7'><div class='vmo-panel ex-panel'><div class='ex-head'><div><h3>Funil de evolução</h3><div class='sub'>Iniciativas que já alcançaram cada etapa e a conversão entre elas</div></div></div>"
    . "<div class='ex-funnel'>$fun</div><div class='ex-out'><span><i class='ti ti-ban'></i> Saídas do funil</span><b>$out</b><small>recusadas ou canceladas</small></div></div></div></div>";

// linha 2: governança do portfólio (perspectiva, faixa, disciplina de atualização e limite por dono executivo)
$gAll = array_filter(Access::visibleInitiatives(), fn ($r) => !$r['is_deleted']);
$gRun = array_filter($gAll, fn ($r) => $r['status'] === 'running');
$gActive = array_filter($gAll, fn ($r) => !in_array($r['status'], ['closed', 'cancelled', 'rejected', 'delivered', 'measuring'], true));
$gDays = [];
foreach ($gRun as $r) {
    $gDays[$r['id']] = Committee::farol($r)['days'] ?? null;
}
$g15 = count(array_filter($gDays, fn ($x) => $x !== null && $x <= 15));
$g30 = count(array_filter($gDays, fn ($x) => $x !== null && $x > 30));
$gPct = count($gRun) ? round($g15 / count($gRun) * 100) : null;
$gDec = count(Committee::decisions(array_keys($gAll), true));
// ---- perspectiva (barras)
$pcol = GroupView::PALETTE;
$perspHtml = '';
$prow = [];
foreach (Db::rows('catalogs', ['kind' => 'perspective', 'is_active' => 1], ['code ASC']) as $pi => $pc) {
    $mineA = array_filter($gActive, fn ($r) => (int) $r['catalogs_id_perspective'] === (int) $pc['id']);
    $prow[] = [$pc['name'], count(array_filter($mineA, fn ($r) => $r['status'] === 'running')), count($mineA), $pcol[$pi % count($pcol)]];
}
$pmax = max(1, ...array_map(fn ($x) => $x[2], $prow ?: [[0, 0, 1]]));
foreach ($prow as [$n, $run, $tot2, $c]) {
    $perspHtml .= "<div class='ex-pr'><div class='ex-pr-top'><span>" . $e($n) . "</span><span><b>$run</b> em execução <small>de $tot2</small></span></div>"
        . "<div class='ex-pr-track'><i style='width:" . round($tot2 / $pmax * 100) . "%;background:" . $c . "33'></i><i class='run' style='width:" . round($run / $pmax * 100) . "%;background:$c'></i></div></div>";
}
// ---- faixa (rosca)
$bandCol = ['A' => '#6366f1', 'B' => '#0ea5e9', 'C' => '#94a3b8'];
$bandTot = max(1, count($gActive));
$arcs = '';
$off = 0;
$legend = '';
foreach (Initiative::BANDS as $b => $lbl) {
    $mine = array_filter($gActive, fn ($r) => ($r['band'] ?? '') === $b);
    $len = count($mine) / $bandTot * 100;
    $arcs .= "<circle cx='60' cy='60' r='42' fill='none' stroke='" . $bandCol[$b] . "' stroke-width='16' stroke-dasharray='$len " . (100 - $len) . "' stroke-dashoffset='" . (25 - $off) . "' pathLength='100'/>";
    $off += $len;
    $legend .= "<div class='ex-bl'><i style='background:" . $bandCol[$b] . "'></i><span>" . $e($lbl) . "</span><b>" . count($mine) . "</b><small>" . count(array_filter($mine, fn ($r) => $r['status'] === 'running')) . " em exec.</small></div>";
}
$semBand = count(array_filter($gActive, fn ($r) => empty($r['band'])));
$donut = "<svg viewBox='0 0 120 120' class='ex-donut'><circle cx='60' cy='60' r='42' fill='none' stroke='rgba(148,163,184,.2)' stroke-width='16'/>$arcs"
    . "<text x='60' y='58' text-anchor='middle' font-size='22' font-weight='700' fill='currentColor'>" . count($gActive) . "</text><text x='60' y='74' text-anchor='middle' font-size='9' fill='currentColor' opacity='.65'>ativas</text></svg>";
// ---- disciplina de atualização (anel + indicadores)
$ringC = $gPct === null ? '#64748b' : ($gPct >= 80 ? '#22c55e' : ($gPct >= 60 ? '#f59e0b' : '#ef4444'));
$ringLbl = $gPct === null ? 'Sem iniciativas em execução' : ($gPct >= 80 ? 'Dentro da meta' : ($gPct >= 60 ? 'Atenção' : 'Abaixo da meta'));
// anel de 0 a 100% com marca da meta (80%) e texto dentro do furo
$r0 = 46;
$meta = 80;
$ang = deg2rad(-90 + 360 * $meta / 100);
$mx1 = round(60 + ($r0 - 11) * cos($ang), 2);
$my1 = round(60 + ($r0 - 11) * sin($ang), 2);
$mx2 = round(60 + ($r0 + 11) * cos($ang), 2);
$my2 = round(60 + ($r0 + 11) * sin($ang), 2);
$ring = "<svg viewBox='0 0 120 120' class='ex-donut'><circle cx='60' cy='60' r='$r0' fill='none' stroke='rgba(148,163,184,.2)' stroke-width='10'/>"
    . "<circle cx='60' cy='60' r='$r0' fill='none' stroke='$ringC' stroke-width='10' stroke-linecap='round' stroke-dasharray='" . ($gPct ?? 0) . " " . (100 - ($gPct ?? 0)) . "' stroke-dashoffset='25' pathLength='100'/>"
    . "<line x1='$mx1' y1='$my1' x2='$mx2' y2='$my2' stroke='currentColor' stroke-width='1.6' opacity='.55'/>"
    . "<text x='60' y='64' text-anchor='middle' font-size='19' font-weight='700' fill='currentColor'>" . ($gPct === null ? '—' : $gPct . '%') . "</text>"
    . "<text x='60' y='77' text-anchor='middle' font-size='6.5' fill='currentColor' opacity='.65'>EM 15 DIAS</text></svg>";
// distribuição por tempo sem atualização
$buckets = ['a' => ['Até 7 dias', '#22c55e', 0], 'b' => ['8 a 15 dias', '#84cc16', 0], 'c' => ['16 a 30 dias', '#f59e0b', 0], 'd' => ['Mais de 30 dias', '#ef4444', 0]];
foreach ($gDays as $dd) {
    $k = $dd === null ? 'd' : ($dd <= 7 ? 'a' : ($dd <= 15 ? 'b' : ($dd <= 30 ? 'c' : 'd')));
    $buckets[$k][2]++;
}
$nB = max(1, array_sum(array_column($buckets, 2)));
$segB = '';
$legB = '';
foreach ($buckets as [$lb, $cl, $n2]) {
    if ($n2) {
        $segB .= "<i style='flex:$n2;background:$cl' title='" . $e($lb) . ": $n2'>$n2</i>";
    }
    $legB .= "<div class='ex-bk'><i style='background:$cl'></i><span>" . $e($lb) . "</span><b>$n2</b><small>" . round($n2 / $nB * 100) . "%</small></div>";
}
$facts = "<div class='ex-status' style='--c:$ringC'><i class='ti " . ($gPct === null ? 'ti-minus' : ($gPct >= 80 ? 'ti-circle-check' : 'ti-alert-triangle')) . "'></i>" . $e($ringLbl) . "<small>meta: $meta% ou mais</small></div>"
    . "<div class='ex-seg ex-seg-lg'>" . ($segB ?: '') . "</div><div class='ex-bks'>$legB</div>"
    . "<div class='ex-dec'><i class='ti ti-gavel'></i><span>Decisões do comitê em aberto</span><b style='color:" . ($gDec ? '#a855f7' : '#64748b') . "'>$gDec</b></div>";
echo "<div class='row g-3 mb-3'>"
    . "<div class='col-lg-4'><div class='vmo-panel ex-panel'><h3>Iniciativas ativas por perspectiva</h3><div class='sub'>Em execução (barra cheia) sobre o total ativo (barra clara)</div>" . ($perspHtml ?: "<div class='text-secondary'>Sem perspectivas cadastradas.</div>") . "</div></div>"
    . "<div class='col-lg-4'><div class='vmo-panel ex-panel'><h3>Composição do portfólio por faixa</h3><div class='sub'>Iniciativas ativas por faixa</div><div class='d-flex align-items-center gap-3'>$donut<div class='flex-fill'>$legend" . ($semBand ? "<div class='cm-hint'>$semBand sem faixa definida</div>" : '') . "</div></div></div></div>"
    . "<div class='col-lg-4'><div class='vmo-panel ex-panel'><h3>Disciplina de atualização</h3><div class='sub'>Há quanto tempo cada iniciativa em execução foi atualizada</div><div class='d-flex align-items-center gap-3'>$ring<div class='flex-fill'>$facts</div></div></div></div></div>";

// ---- capacidade por dono executivo
$limit = Committee::wipLimit();
$byOwner = [];
foreach ($gRun as $r) {
    [$ok, $on, $ou] = Committee::owner($r);
    $byOwner[$ok] ??= ['name' => $on, 'user' => $ou, 'n' => 0];
    $byOwner[$ok]['n']++;
}
uasort($byOwner, fn ($a, $b) => [$b['n'], $a['name']] <=> [$a['n'], $b['name']]);
$atLimit = count(array_filter($byOwner, fn ($o) => $o['n'] === $limit));
$over = count(array_filter($byOwner, fn ($o) => $o['n'] > $limit));
$free = array_sum(array_map(fn ($o) => max(0, $limit - $o['n']), $byOwner));
$cards = '';
foreach ($byOwner as $ok => $o) {
    $n = $o['n'];
    $st = $n > $limit ? ['#ef4444', 'Acima do limite'] : ($n === $limit ? ['#f59e0b', 'No limite'] : ['#22c55e', 'Com vaga']);
    $slots = '';
    for ($z = 1; $z <= max($limit, $n); $z++) {
        $slots .= "<i class='" . ($z <= $n ? 'on' : '') . "' style='--c:" . $st[0] . "'></i>";
    }
    $cards .= "<div class='ex-own' style='--c:" . $st[0] . "'><div class='ex-own-head'>" . ($o['user'] ? GroupView::avatar($o['user']) : GroupView::avatarName($o['name'])) . "<div class='ex-own-name'>" . $e($o['name']) . "<small>" . $e($st[1]) . "</small></div><b>$n<span>/$limit</span></b></div><div class='ex-slots'>$slots</div></div>";
}
echo "<div class='vmo-panel mb-3'><div class='ex-head d-flex flex-wrap align-items-end gap-3'><div><h3>Capacidade por dono executivo</h3><div class='sub'>Iniciativas em execução contra o limite acordado de $limit por dono</div></div>"
    . "<div class='ex-chips ms-auto'><span><b>" . count($byOwner) . "</b> donos com iniciativas</span><span style='--c:#22c55e'><b>$free</b> vagas livres</span><span style='--c:#f59e0b'><b>$atLimit</b> no limite</span><span style='--c:#ef4444'><b>$over</b> acima do limite</span></div></div>"
    . ($cards ? "<div class='ex-owners'>$cards</div>" : "<div class='text-secondary py-2'>Nenhuma iniciativa em execução.</div>") . "</div>";

// linha 3: linha do tempo
echo "<div class='vmo-panel mb-3'><h3>Linha do tempo do portfólio</h3><div class='sub'>Período planejado, avanço (preenchimento), previsão além do plano (tracejado) e a data de hoje. Clique em uma linha para abrir o detalhe.</div>" . $timeline($rows) . "</div>";

// atenção
echo "<div class='vmo-panel'><h3>Iniciativas que pedem atenção</h3><div class='sub'>Clique em uma linha para abrir o resumo completo ao lado.</div><div class='table-responsive'><table class='table table-vcenter vmo-attn'><thead><tr><th>Iniciativa</th><th>Situação</th><th>Saúde</th><th>Por quê</th><th>Progresso</th><th>Gestor</th></tr></thead><tbody>";
foreach (array_slice($d['attention'], 0, 8) as $r) {
    echo "<tr data-drawer='ini-" . (int) $r['id'] . "' tabindex='0' role='button'><td><b>" . $e($r['code']) . "</b> " . $e($r['name']) . "</td><td>" . Initiative::statusBadge($r['status']) . "</td><td>" . Menu::healthBadge($r['health']) . "</td>"
        . "<td class='vmo-why'>" . $e(implode(' · ', $r['why']) ?: 'sem dados críticos') . "</td><td>" . ($r['progress'] === null ? "<span class='vmo-muted'>sem dado</span>" : number_format($r['progress'], 0) . '%') . "</td><td>" . $e($r['manager']) . "</td></tr>";
}
if (!$attentionN) {
    echo "<tr><td colspan='6' class='text-center text-secondary py-4'>Tudo em ordem: nenhuma iniciativa pede atenção.</td></tr>";
}
echo "</tbody></table></div></div>";

echo $templates;
echo Menu::script('drawer.js');
Html::footer();
