<?php

/**
 * Roadmap de integração: módulos ligados ao núcleo OAK, separados entre "em produção" e "100% desenvolvido",
 * com pontos animados percorrendo as conexões. O estágio de cada módulo vem de Configuração → Roadmap.
 */

use GlpiPlugin\Vmo\Access;
use GlpiPlugin\Vmo\Connection;
use GlpiPlugin\Vmo\Db;
use GlpiPlugin\Vmo\Menu;
use GlpiPlugin\Vmo\Roadmap;
use GlpiPlugin\Vmo\Settings;

Session::checkLoginUser();
if (!Access::canView()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}
$e = fn ($v) => Menu::e($v);
$self = Menu::url('/front/roadmap.php');
$items = Roadmap::items();
$n = count($items);
$stageCnt = array_count_values(array_column($items, 'stage'));

// geometria: as iniciativas, agrupadas por perspectiva, correm em volta do núcleo alternando duas elipses (externa e interna);
// assim a mesma perspectiva fica junta, o espaço é todo usado e dá para ligar as vizinhas de cada área.
$W = 1880; $H = 1420; $C = [940, 710];
$pos = [];
foreach ($items as $t => $it) {
    $ang = 2 * M_PI * ($t + .5) / max(1, $n) - M_PI / 2;
    $outer = $t % 2 === 0;
    $rx = $outer ? 840 : 500;
    $ry = $outer ? 610 : 330;
    $pos[$it['id']] = [(int) round($C[0] + $rx * cos($ang)), (int) round($C[1] + $ry * sin($ang))];
}

$lines = [];   // [id, d, cor, duração, atraso, tipo]
$i = 0;
$add = function (int $a, ?int $b, string $col, string $kind) use (&$lines, &$i, $pos, $C) {
    [$x1, $y1] = $pos[$a];
    [$x2, $y2] = $b === null ? $C : $pos[$b];
    $i++;
    $lines[] = ['rm' . $i, "M$x1 $y1 L$x2 $y2", $col, 5 + ($i % 5) * 0.8, -($i * 0.9), $kind];
};
foreach ($items as $t => $it) {
    $add($it['id'], null, $it['stage'] === 'prod' ? '#a3e635' : $it['color'], 'spoke' . ($it['stage'] === 'prod' ? ' prod' : ''));
    $nx = $items[($t + 1) % $n] ?? null;
    if ($nx && $n > 1) {
        // vizinha da mesma área: linha da cor da área; vizinha de outra área: ponte tracejada entre áreas
        $same = $nx['persp'] === $it['persp'];
        $add($it['id'], $nx['id'], $same ? $it['color'] : '#94a3b8', $same ? 'area' : 'bridge');
    }
    // cada iniciativa também se liga à da elipse oposta mais próxima (rede entre as áreas)
    $nx2 = $items[($t + 2) % $n] ?? null;
    if ($nx2 && $n > 3 && $nx2['persp'] === $it['persp']) {
        $add($it['id'], $nx2['id'], $it['color'], 'area');
    }
}

$svg = "<svg class='rm-svg' viewBox='0 0 $W $H' preserveAspectRatio='xMidYMid meet' aria-hidden='true'><defs><filter id='rmg'><feGaussianBlur stdDeviation='2'/></filter></defs>";
foreach ($lines as [$id, $d, $col, $dur, $delay, $kind]) {
    $style = match (true) {
        str_starts_with($kind, 'spoke') => "stroke='#60a5fa' stroke-opacity='.22' stroke-width='.8' stroke-dasharray='2 6'",
        $kind === 'area' => "stroke='$col' stroke-opacity='.75' stroke-width='1.4'",
        default => "stroke='#94a3b8' stroke-opacity='.45' stroke-width='1' stroke-dasharray='5 5'",
    };
    $svg .= "<path id='$id' class='rm-deco' d='$d' fill='none' $style/>";
}
foreach ($lines as [$id, $d, $col, $dur, $delay, $kind]) {
    if ($kind === 'spoke') {
        continue;   // pontos só nas ligações entre áreas e nos raios das iniciativas em produção
    }
    $svg .= "<g class='rm-dot'><ellipse rx='5' ry='2' fill='$col' opacity='.95' filter='url(#rmg)'/><ellipse rx='3.4' ry='1.3' fill='#fff'/>"
        . "<animateMotion dur='{$dur}s' begin='{$delay}s' repeatCount='indefinite' rotate='auto'><mpath href='#$id'/></animateMotion></g>";
}
$svg .= '</svg>';

// ------------------------------------------------------------------ conexões (integração) e dependências (cronograma): em evidência
$byId = array_column($items, null, 'id');
$edges = [];   // ['kind' => conn|dep, 'a', 'b', 'label', 'detail', 'two', 'id']
foreach (Connection::all() as $c) {
    if (isset($pos[(int) $c['from_id']], $pos[(int) $c['to_id']])) {
        $edges[] = ['kind' => 'conn', 'id' => (int) $c['id'], 'a' => (int) $c['from_id'], 'b' => (int) $c['to_id'], 'label' => $c['label'], 'detail' => (string) $c['detail'], 'two' => (bool) $c['two_way']];
    }
}
foreach (Db::rows('dependencies', ['kind' => 'initiative']) as $d) {
    if (isset($pos[(int) $d['pred_id']], $pos[(int) $d['succ_id']])) {
        $lag = (int) $d['lag_days'];
        $edges[] = ['kind' => 'dep', 'id' => (int) $d['id'], 'a' => (int) $d['pred_id'], 'b' => (int) $d['succ_id'], 'label' => 'termina antes de começar' . ($lag ? ' (' . ($lag > 0 ? '+' : '') . $lag . ' d)' : ''), 'detail' => $d['is_hard'] ? 'vínculo rígido' : 'vínculo informativo', 'two' => false];
    }
}
$cnt = [];
foreach ($edges as $ed) {
    $cnt[$ed['a']] = ($cnt[$ed['a']] ?? 0) + 1;
    $cnt[$ed['b']] = ($cnt[$ed['b']] ?? 0) + 1;
}
$defs = '';
$evg = '';
$evl = '';
$evd = '';
$trim = 50; // o anel do nó cobre o começo e o fim da linha: a seta fica logo fora dele
foreach ($edges as $k => $ed) {
    [$x1, $y1] = $pos[$ed['a']];
    [$x2, $y2] = $pos[$ed['b']];
    $dx = $x2 - $x1; $dy = $y2 - $y1; $len = max(1.0, sqrt($dx * $dx + $dy * $dy));
    $ux = $dx / $len; $uy = $dy / $len;
    $sx = round($x1 + $ux * $trim, 1); $sy = round($y1 + $uy * $trim, 1);
    $tx = round($x2 - $ux * $trim, 1); $ty = round($y2 - $uy * $trim, 1);
    $col = $ed['kind'] === 'dep' ? '#f59e0b' : $byId[$ed['a']]['color'];
    $mid = "mk$k";
    $arrow = "<path d='M0,0 L10,5 L0,10 z' fill='$col'/>";
    $defs .= "<marker id='$mid' viewBox='0 0 10 10' refX='9' refY='5' markerWidth='6' markerHeight='6' orient='auto'>$arrow</marker>"
        . ($ed['two'] ? "<marker id='{$mid}s' viewBox='0 0 10 10' refX='1' refY='5' markerWidth='6' markerHeight='6' orient='auto-start-reverse'>$arrow</marker>" : '');
    $w = $ed['kind'] === 'dep' ? 2.4 : 3.4;
    $dash = $ed['kind'] === 'dep' ? " stroke-dasharray='9 6'" : '';
    $pid = "ev$k";
    $evg .= "<g class='rm-edge rm-$ed[kind]' data-a='$ed[a]' data-b='$ed[b]'><path id='$pid' d='M$sx $sy L$tx $ty' fill='none' stroke='$col' stroke-width='$w' stroke-opacity='.95'$dash marker-end='url(#$mid)'"
        . ($ed['two'] ? " marker-start='url(#{$mid}s)'" : '') . "/>"
        . "<path d='M$sx $sy L$tx $ty' fill='none' stroke='transparent' stroke-width='16'><title>" . $e($byId[$ed['a']]['name'] . ($ed['two'] ? ' ⇄ ' : ' → ') . $byId[$ed['b']]['name'] . ': ' . $ed['label']) . "</title></path>";
    $dur = 3.2 + ($k % 4) * 0.5;
    $evg .= "<g class='rm-dot'><circle r='5' fill='#fff'/><circle r='9' fill='$col' opacity='.35'/><animateMotion dur='{$dur}s' begin='" . (-$k * 0.6) . "s' repeatCount='indefinite'><mpath href='#$pid'/></animateMotion></g>";
    if ($ed['two']) {
        $evg .= "<path id='{$pid}r' d='M$tx $ty L$sx $sy' fill='none' stroke='none'/><g class='rm-dot'><circle r='5' fill='#fff'/><circle r='9' fill='$col' opacity='.35'/><animateMotion dur='{$dur}s' begin='" . (-$k * 0.6 - 1.4) . "s' repeatCount='indefinite'><mpath href='#{$pid}r'/></animateMotion></g>";
    }
    $evg .= '</g>';
    // rótulo no meio da linha (com contorno escuro para ler sobre qualquer fundo)
    $tpos = 0.5 + (($k % 3) - 1) * 0.1; // escalona os selos ao longo da linha
    $mx = round($sx + ($tx - $sx) * $tpos, 1); $my = round($sy + ($ty - $sy) * $tpos, 1);
    $num = $k + 1;
    $txt = $e($ed['kind'] === 'dep' ? 'precede' : $ed['label']);
    $evl .= "<g class='rm-lab rm-$ed[kind]' data-a='$ed[a]' data-b='$ed[b]'><circle cx='$mx' cy='$my' r='15' fill='$col' stroke='#0b1220' stroke-width='3'/>"
        . "<text x='$mx' y='$my' text-anchor='middle' dominant-baseline='central' fill='#0b1220' class='rm-num'>$num</text>"
        . "<text x='$mx' y='" . ($my - 28) . "' text-anchor='middle' class='rm-lt' fill='" . ($ed['kind'] === 'dep' ? '#fcd34d' : '#f1f5f9') . "' stroke='#0b1220' stroke-width='6' paint-order='stroke' stroke-linejoin='round'>$txt</text></g>";
}
$svg = str_replace('<defs>', '<defs>' . $defs, $svg);
$svg .= "<svg class='rm-svg rm-ev' viewBox='0 0 $W $H' preserveAspectRatio='xMidYMid meet' aria-hidden='true'>$evg$evl</svg>";

$badge = ['prod' => 'ti-rocket', 'dev' => 'ti-check', 'wip' => 'ti-tools'];
$nodes = '';
foreach ($items as $it) {
    [$x, $y] = $pos[$it['id']];
    $st = $it['stage'];
    $nodes .= "<a class='rm-node rm-$st' data-rm-id='" . (int) $it['id'] . "' href='" . $e(Menu::url('/front/initiative.php?id=' . $it['id'])) . "' style='--c:" . $it['color'] . ";left:" . round($x / $W * 100, 3) . "%;top:" . round($y / $H * 100, 3) . "%' title='"
        . $e($it['code'] . ' · ' . $it['full'] . ' · ' . ($it['persp'] ?: 'Sem perspectiva') . ' · ' . Roadmap::STAGES[$st][0]) . "'>"
        . "<div class='rm-ring'><i class='ti {$it['icon']}'></i><span class='rm-bd'><i class='ti {$badge[$st]}'></i></span>" . (!empty($cnt[$it['id']]) ? "<span class='rm-cn' title='conexões e dependências'>" . (int) $cnt[$it['id']] . '</span>' : '') . "</div><div class='rm-name'>" . $e($it['name']) . "</div></a>";
}

Menu::header('Roadmap de integração', 'dashboard');
echo Menu::title('Roadmap de integração', 'Todas as iniciativas aparecem aqui sozinhas, conforme o Status de entrega de cada uma (campo no cartão Portfólio estratégico da ficha).',
    (Access::canConfigure() ? Menu::button('Configurar roadmap', Menu::url('/front/roadmapconfig.php'), 'ti-settings', 'btn-primary') : '')
    . Menu::button('Dashboard', Menu::url('/front/dashboard.php'), 'ti-layout-dashboard', 'btn-outline-primary')
    . Menu::button('KPIs', Menu::url('/front/indicators.php'), 'ti-gauge', 'btn-outline-primary'));

$legend = '';
foreach (Roadmap::STAGES as $k => [$label, $col]) {
    $legend .= "<span class='rm-leg rm-leg-$k'><i><em class='ti {$badge[$k]}'></em></i>" . $e($label) . " <b>" . (int) ($stageCnt[$k] ?? 0) . "</b></span>";
}
$nConn = count(array_filter($edges, fn ($x) => $x['kind'] === 'conn'));
$nDep = count($edges) - $nConn;
echo "<div class='rm-panel'><div class='rm-head'><h3>Roadmap de integração</h3><div class='rm-legend'>$legend</div></div>"
    . "<div class='rm-persp'><span class='rm-ll'><i class='rm-sw conn'></i>Conexão (integração) <b>$nConn</b></span><span class='rm-ll'><i class='rm-sw dep'></i>Dependência (término→início) <b>$nDep</b></span><span class='rm-muted ms-auto'>Os números nas linhas correspondem à lista abaixo · passe o mouse em um plugin ou em uma linha da lista para destacar e ler o que ele liga</span></div>"
    . "<div class='rm-stage' style='aspect-ratio:$W/$H'>$svg"
    . "<div class='rm-core' style='left:" . round($C[0] / $W * 100, 3) . "%;top:" . round($C[1] / $H * 100, 3) . "%'><img src='" . $e(Menu::url('/img/oak-tree-white.png')) . "' alt=''><div class='rm-brand'>OAKMONT</div><div class='rm-sub'>GROUP</div></div>"
    . $nodes . "</div><div class='rm-foot'>Iniciativas VMO · $n iniciativas</div></div>";
$canEdit = Access::canUpdate();
echo "<div class='rm-panel mt-3'><div class='rm-head'><h3>Conexões e dependências</h3><div class='rm-muted'>o que cada plugin entrega ao outro e o que precisa terminar antes</div></div>"
    . "<div class='table-responsive'><table class='table table-vcenter rm-tbl'><thead><tr><th>#</th><th>De</th><th></th><th>Para</th><th>O que acontece</th><th>Tipo</th></tr></thead><tbody>";
$rown = 0;
foreach ($edges as $ed) {
    $A = $byId[$ed['a']]; $B = $byId[$ed['b']];
    echo "<tr data-rm-a='$ed[a]' data-rm-b='$ed[b]'><td><span class='rm-nb'>" . (++$rown) . "</span></td><td><a href='" . $e(Menu::url('/front/initiative.php?id=' . $A['id'])) . "'><b>" . $e($A['name']) . "</b></a><div class='small rm-muted'>" . $e($A['code']) . "</div></td>"
        . "<td class='text-center rm-arr rm-$ed[kind]'><i class='ti " . ($ed['two'] ? 'ti-arrows-left-right' : 'ti-arrow-right') . "'></i></td>"
        . "<td><a href='" . $e(Menu::url('/front/initiative.php?id=' . $B['id'])) . "'><b>" . $e($B['name']) . "</b></a><div class='small rm-muted'>" . $e($B['code']) . "</div></td>"
        . "<td>" . $e($ed['label']) . ($ed['detail'] !== '' ? "<div class='small rm-muted'>" . $e($ed['detail']) . '</div>' : '') . "</td>"
        . "<td><span class='rm-tag rm-$ed[kind]'>" . ($ed['kind'] === 'dep' ? 'Dependência' : ($ed['two'] ? 'Conexão nos dois sentidos' : 'Conexão')) . "</span></td><td class='text-end'>";
    echo '</td></tr>';
}
if (!$edges) {
    echo "<tr><td colspan='6' class='rm-muted text-center py-3'>Nenhuma conexão registrada ainda.</td></tr>";
}
echo "</tbody></table></div>";
echo "<div class='rm-foot'>Dependências de cronograma são criadas na ficha da iniciativa (aba Dependências); as conexões e os plugins exibidos se escolhem em Configurar roadmap.</div></div>";
echo Menu::script('roadmap.js');
Html::footer();
