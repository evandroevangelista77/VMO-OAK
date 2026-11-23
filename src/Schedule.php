<?php

namespace GlpiPlugin\Vmo;

/**
 * Cronograma (barras por tarefa) e matriz RACI em leitura: a mesma visão aparece na ficha da iniciativa e no Comitê.
 */
class Schedule
{
    public static function gantt(array $tasks): string
    {
        $e = fn ($v) => Menu::e($v);
        $today = Db::today();
        $dated = array_filter($tasks, fn ($t) => $t['planned_start'] && $t['planned_end']);
        if (!$dated) {
            return "<div class='vmo-muted'>Nenhuma tarefa com início e término planejados.</div>";
        }
        $t0 = strtotime(date('Y-m-01', min(array_map(fn ($t) => strtotime($t['planned_start']), $dated))));
        $t1 = max(array_map(fn ($t) => strtotime($t['planned_end']), $dated));
        $span = max(86400, $t1 - $t0 + 86400);
        $pct = fn (int $t) => max(0, min(100, 100 * ($t - $t0) / $span));
        $out = "<div class='vmo-gantt'><div class='vmo-g-axis'>";
        for ($m = $t0; $m <= $t1; $m = strtotime('+1 month', $m)) {
            $out .= "<span style='left:" . round($pct($m), 2) . "%'>" . date('m/y', $m) . "</span>";
        }
        $out .= '</div>';
        foreach (Task::tree($tasks) as [$tid, $lvl]) {
            $t = $tasks[$tid];
            if (!$t['planned_start'] || !$t['planned_end']) {
                continue;
            }
            $a = $pct(strtotime($t['planned_start']));
            $b = $pct(strtotime($t['planned_end']) + 86400);
            $late = in_array($t['status'], ['todo', 'doing'], true) && $t['planned_end'] < $today;
            $col = $late ? '#ef4444' : ($t['status'] === 'done' ? '#22c55e' : ($t['is_milestone'] ? '#f59e0b' : '#6366f1'));
            $prog = max(0, min(100, (float) $t['progress']));
            $out .= "<div class='vmo-g-row'><div class='vmo-g-l' title='" . $e($t['name']) . "' style='padding-left:" . (min(4, $lvl) * 10) . "px'>" . ($t['is_milestone'] ? "<i class='ti ti-flag text-warning'></i> " : '') . $e(mb_strimwidth($t['name'], 0, 34, '…')) . '</div>'
                . "<div class='vmo-g-t'><div class='vmo-g-bar' style='left:" . round($a, 2) . "%;width:" . round(max(1.2, $b - $a), 2) . "%;--c:$col' title='" . $e(Menu::date($t['planned_start']) . ' → ' . Menu::date($t['planned_end'])) . "'><span style='width:$prog%'></span><em>" . number_format($prog, 0) . '%</em></div></div></div>';
        }
        return $out . "<div class='vmo-g-today' style='left:calc(220px + (100% - 220px) * " . round($pct(time()) / 100, 4) . ")'><span>hoje</span></div></div>";
    }

    /** Matriz RACI somente leitura. */
    public static function raciReadonly(int $initiativeId): string
    {
        $e = fn ($v) => Menu::e($v);
        $tasks = Task::forInitiative($initiativeId);
        $map = Raci::map($initiativeId);
        $people = [];
        foreach ($map as $cols) {
            foreach ($cols as $u => $r) {
                $people[$u] = true;
            }
        }
        $people = array_keys($people);
        if (!$tasks || !$people) {
            return "<div class='vmo-muted'>RACI ainda não definida.</div>";
        }
        $head = '';
        foreach ($people as $u) {
            $head .= "<th class='text-center'>" . $e(Db::userName($u)) . '</th>';
        }
        $rows = '';
        foreach (Task::tree($tasks) as [$tid, $lvl]) {
            $row = $map[$tid] ?? [];
            $cells = '';
            foreach ($people as $u) {
                $r = $row[$u] ?? '';
                $cells .= "<td class='text-center'>" . ($r ? "<span class='badge' style='background:" . Raci::ROLES[$r][1] . ";color:#fff'>$r</span>" : '·') . '</td>';
            }
            $w = Raci::check($row);
            $rows .= "<tr><td style='padding-left:" . (8 + min(4, $lvl) * 14) . "px'>" . $e($tasks[$tid]['name']) . "</td>$cells<td>" . ($w ? "<span class='badge bg-warning-lt'>" . $e(implode(' · ', $w)) . '</span>' : "<span class='badge bg-success-lt'>ok</span>") . '</td></tr>';
        }
        return "<div class='table-responsive'><table class='table table-vcenter card-table'><thead><tr><th>Tarefa</th>$head<th>Conferência</th></tr></thead><tbody>$rows</tbody></table></div>";
    }
}
