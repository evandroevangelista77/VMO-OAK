<?php

namespace GlpiPlugin\Vmo;

/**
 * KPIs de performance das atividades (tarefas) e das iniciativas. Cálculo puro (sem banco): recebe as linhas e a data
 * de referência. Dado ausente vira null ("sem dado"), nunca zero. Só tarefas-folha contam; canceladas ficam de fora.
 */
class Kpi
{
    /** Semáforo: [verde a partir de, amarelo a partir de, maior é melhor?]. */
    public const TARGETS = [
        'otd' => [85, 70, true], 'completion' => [70, 40, true], 'overdue_pct' => [10, 25, false], 'effort' => [110, 130, false],
        'no_owner' => [5, 15, false], 'no_due' => [5, 15, false], 'updated' => [80, 60, true], 'spi' => [0.9, 0.75, true], 'ms_otd' => [85, 70, true],
    ];

    public static function status(?float $v, string $kpi): string
    {
        if ($v === null) {
            return 'nodata';
        }
        [$g, $y, $high] = self::TARGETS[$kpi];
        if ($high) {
            return $v >= $g ? 'green' : ($v >= $y ? 'yellow' : 'red');
        }
        return $v <= $g ? 'green' : ($v <= $y ? 'yellow' : 'red');
    }

    private static function days(?string $from, ?string $to): ?int
    {
        return $from && $to ? (int) round((strtotime($to) - strtotime($from)) / 86400) : null;
    }

    private static function pct(int $n, int $d): ?float
    {
        return $d > 0 ? round($n / $d * 100, 1) : null;
    }

    private static function avg(array $v): ?float
    {
        return $v ? round(array_sum($v) / count($v), 1) : null;
    }

    /** Folhas ativas (sem canceladas, sem tarefas-resumo). */
    public static function leaves(array $tasks): array
    {
        $byId = [];
        foreach ($tasks as $t) {
            $byId[(int) $t['id']] = $t;
        }
        return array_filter(Calc::leaves($byId), fn ($t) => $t['status'] !== 'cancelled');
    }

    /**
     * @return array<string,mixed>
     */
    public static function tasks(array $tasks, string $today, int $window = 30): array
    {
        $leaf = self::leaves($tasks);
        $done = array_filter($leaf, fn ($t) => $t['status'] === 'done');
        $open = array_filter($leaf, fn ($t) => in_array($t['status'], ['todo', 'doing'], true));
        $late = array_filter($open, fn ($t) => $t['planned_end'] && $t['planned_end'] < $today);
        $soon = array_filter($open, fn ($t) => $t['planned_end'] && $t['planned_end'] >= $today && $t['planned_end'] <= date('Y-m-d', strtotime($today . ' +7 days')));
        $withDates = array_filter($done, fn ($t) => $t['actual_end'] && $t['planned_end']);
        $onTime = array_filter($withDates, fn ($t) => $t['actual_end'] <= $t['planned_end']);
        $cycle = array_filter($done, fn ($t) => $t['actual_start'] && $t['actual_end'] && $t['planned_start'] && $t['planned_end']);
        $lead = array_map(fn ($t) => max(0, self::days($t['actual_start'], $t['actual_end'])), $cycle);
        $plan = array_map(fn ($t) => max(1, self::days($t['planned_start'], $t['planned_end'])), $cycle);
        $withEffort = array_filter($done, fn ($t) => (float) $t['effort_plan'] > 0);
        $since = date('Y-m-d', strtotime($today . " -$window days"));
        $recent = array_filter($done, fn ($t) => $t['actual_end'] && $t['actual_end'] >= $since && $t['actual_end'] <= $today);
        $ms = array_filter($leaf, fn ($t) => !empty($t['is_milestone']));
        $msDone = array_filter($ms, fn ($t) => $t['status'] === 'done' && $t['actual_end'] && $t['planned_end']);
        $msOnTime = array_filter($msDone, fn ($t) => $t['actual_end'] <= $t['planned_end']);
        $msLate = array_filter($ms, fn ($t) => in_array($t['status'], ['todo', 'doing'], true) && $t['planned_end'] && $t['planned_end'] < $today);
        $leadAvg = self::avg($lead);
        $planAvg = self::avg($plan);
        $effPlan = array_sum(array_map(fn ($t) => (float) $t['effort_plan'], $withEffort));
        $effAct = array_sum(array_map(fn ($t) => (float) $t['effort_actual'], $withEffort));
        return [
            'total' => count($leaf), 'done' => count($done), 'open' => count($open), 'doing' => count(array_filter($leaf, fn ($t) => $t['status'] === 'doing')),
            'completion' => self::pct(count($done), count($leaf)),
            'otd' => self::pct(count($onTime), count($withDates)), 'otd_n' => count($withDates),
            'overdue' => count($late), 'overdue_pct' => self::pct(count($late), count($open)),
            'overdue_avg' => self::avg(array_map(fn ($t) => (int) self::days($t['planned_end'], $today), $late)),
            'due_soon' => count($soon),
            'lead' => $leadAvg, 'plan_cycle' => $planAvg, 'cycle_dev' => $leadAvg !== null && $planAvg ? round(($leadAvg / $planAvg - 1) * 100, 1) : null,
            'effort' => $effPlan > 0 ? round($effAct / $effPlan * 100, 1) : null,
            'throughput' => $done ? round(count($recent) / ($window / 7), 1) : null, 'recent' => count($recent), 'window' => $window,
            'ms_total' => count($ms), 'ms_otd' => self::pct(count($msOnTime), count($msDone)), 'ms_late' => count($msLate),
            'no_owner' => self::pct(count(array_filter($open, fn ($t) => (int) $t['users_id_responsible'] === 0)), count($open)),
            'no_due' => self::pct(count(array_filter($open, fn ($t) => empty($t['planned_end']))), count($open)),
        ];
    }

    /** Performance por responsável (tarefas-folha ativas). */
    public static function byOwner(array $tasks, string $today): array
    {
        $out = [];
        foreach (self::leaves($tasks) as $t) {
            $u = (int) $t['users_id_responsible'];
            $o = &$out[$u];
            $o ??= ['user' => $u, 'open' => 0, 'done' => 0, 'late' => 0, 'withDates' => 0, 'onTime' => 0];
            if ($t['status'] === 'done') {
                $o['done']++;
                if ($t['actual_end'] && $t['planned_end']) {
                    $o['withDates']++;
                    $o['onTime'] += $t['actual_end'] <= $t['planned_end'] ? 1 : 0;
                }
            } else {
                $o['open']++;
                $o['late'] += $t['planned_end'] && $t['planned_end'] < $today ? 1 : 0;
            }
            unset($o);
        }
        foreach ($out as &$o) {
            $o['otd'] = self::pct($o['onTime'], $o['withDates']);
        }
        unset($o);
        uasort($out, fn ($a, $b) => [$b['late'], $b['open']] <=> [$a['late'], $a['open']]);
        return $out;
    }

    /** Fração do prazo planejado já decorrida (0–1); null sem datas. */
    public static function elapsed(?string $start, ?string $end, string $today): ?float
    {
        if (!$start || !$end || $end <= $start) {
            return null;
        }
        return max(0.0, min(1.0, (strtotime($today) - strtotime($start)) / (strtotime($end) - strtotime($start))));
    }

    /** Índice de prazo: progresso real ÷ progresso esperado pelo calendário (null sem dados ou antes do início). */
    public static function spi(?float $progress, ?float $elapsed): ?float
    {
        return $progress === null || $elapsed === null || $elapsed <= 0 ? null : round(($progress / 100) / $elapsed, 2);
    }

    /** Tarefas concluídas por semana nas últimas $weeks semanas (a mais antiga primeiro); cada barra cobre 7 dias terminando em $today - 7×k. */
    public static function weekly(array $tasks, string $today, int $weeks = 8): array
    {
        $done = array_filter(self::leaves($tasks), fn ($t) => $t['status'] === 'done' && $t['actual_end']);
        $out = [];
        for ($k = $weeks - 1; $k >= 0; $k--) {
            $end = date('Y-m-d', strtotime($today . " -" . ($k * 7) . ' days'));
            $start = date('Y-m-d', strtotime($end . ' -6 days'));
            $out[] = ['label' => date('d/m', strtotime($end)), 'done' => count(array_filter($done, fn ($t) => $t['actual_end'] >= $start && $t['actual_end'] <= $end)),
                'late' => count(array_filter($done, fn ($t) => $t['actual_end'] >= $start && $t['actual_end'] <= $end && $t['planned_end'] && $t['actual_end'] > $t['planned_end']))];
        }
        return $out;
    }
}
