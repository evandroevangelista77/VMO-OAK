<?php

namespace GlpiPlugin\Vmo;

/**
 * Dados do painel executivo. Tudo parte das iniciativas que o usuário pode ver (RN09) e as
 * somas por moeda só consideram a moeda funcional; as demais aparecem separadas.
 */
class Dashboard
{
    /**
     * @return array{portfolio:array,cur:string,finance:bool,series:array,risks:array,milestones:array,benefits:array,attention:array,sums:array}
     */
    public static function build(): array
    {
        global $DB;
        $finance = Access::canFinance();
        $cur = Settings::currency();
        $p = Report::portfolio([]);
        $rows = $p['rows'];
        $ids = array_column($rows, 'id');

        // riscos abertos, marcos e benefícios por iniciativa (para a gaveta de cada uma)
        $risks = [];
        $miles = [];
        $bens = [];
        if ($ids) {
            foreach ($DB->request(['FROM' => Db::t('risks'), 'WHERE' => ['initiatives_id' => $ids, 'is_deleted' => 0, 'status' => ['open', 'mitigating']]]) as $r) {
                $r['exposure'] = Calc::riskExposure((int) $r['probability'], (int) $r['impact']);
                $risks[(int) $r['initiatives_id']][] = $r;
            }
            foreach ($risks as &$list) {
                usort($list, fn ($a, $b) => $b['exposure'] <=> $a['exposure']);
            }
            unset($list);
            foreach ($DB->request(['FROM' => Db::t('tasks'), 'WHERE' => ['initiatives_id' => $ids, 'is_deleted' => 0, 'is_milestone' => 1, 'status' => ['todo', 'doing']], 'ORDER' => ['planned_end ASC']]) as $t) {
                $miles[(int) $t['initiatives_id']][] = $t;
            }
            $names = [];
            foreach ($DB->request(['FROM' => Db::t('allocations'), 'WHERE' => ['initiatives_id' => $ids]]) as $a) {
                $b = Db::row('benefits', (int) $a['benefits_id']);
                if ($b && !$b['is_deleted']) {
                    $bens[(int) $a['initiatives_id']][] = ['benefit' => $b, 'percent' => (float) $a['percent']];
                }
            }
        }

        // atenção: críticas e de atenção primeiro, depois maior risco
        $attention = [];
        foreach ($rows as $r) {
            $why = [];
            if (($r['delay'] ?? 0) > 0) {
                $why[] = $r['delay'] . ' d.ú. de atraso';
            }
            if (($r['max_risk'] ?? 0) >= (int) Settings::thresholds()['risk_yellow']) {
                $why[] = 'risco ' . $r['max_risk'];
            }
            if ($finance && isset($r['budget'], $r['forecast']) && $r['budget'] > 0 && ($r['forecast'] - $r['budget']) / $r['budget'] * 100 > Settings::thresholds()['cost_yellow']) {
                $why[] = 'custo ' . number_format(($r['forecast'] - $r['budget']) / $r['budget'] * 100, 0) . '% acima';
            }
            if ($r['health'] === 'red' || $r['health'] === 'yellow' || $why) {
                $attention[] = $r + ['why' => $why];
            }
        }
        $rank = ['red' => 0, 'yellow' => 1, 'gray' => 2, 'green' => 3];
        usort($attention, fn ($a, $b) => [$rank[$a['health']] ?? 4, -($a['max_risk'] ?? 0)] <=> [$rank[$b['health']] ?? 4, -($b['max_risk'] ?? 0)]);

        // somas na moeda funcional (as outras moedas ficam à parte)
        $sums = ['planned' => 0.0, 'validated' => 0.0, 'actual' => 0.0, 'budget' => 0.0, 'forecast' => 0.0, 'forecast_b' => 0.0, 'other' => false];
        if ($finance) {
            foreach ($rows as $r) {
                if ($r['currency'] !== $cur) {
                    $sums['other'] = true;
                    continue;
                }
                $sums['planned'] += (float) ($r['value_planned'] ?? 0);
                $sums['validated'] += (float) ($r['value_validated'] ?? 0);
                $sums['actual'] += (float) ($r['actual'] ?? 0);
                $sums['forecast'] += (float) ($r['forecast'] ?? 0);
                // a comparação com o orçamento só vale para iniciativas que já têm baseline
                if (($r['budget'] ?? 0) > 0) {
                    $sums['budget'] += (float) $r['budget'];
                    $sums['forecast_b'] += (float) ($r['forecast'] ?? 0);
                }
            }
        }

        return [
            'portfolio' => $p, 'cur' => $cur, 'finance' => $finance, 'rows' => $rows, 'risks' => $risks, 'milestones' => $miles, 'benefits' => $bens,
            'attention' => $attention, 'sums' => $sums, 'series' => $finance ? self::series($ids, $cur) : [],
        ];
    }

    /**
     * Série mensal (últimos 8 meses e próximos 3): previsto e validado, e o acumulado do validado.
     *
     * @return array<int,array{label:string,planned:float,validated:float,cum:float,future:bool}>
     */
    public static function series(array $ids, string $cur, int $back = 8, int $ahead = 3): array
    {
        global $DB;
        if (!$ids) {
            return [];
        }
        $share = [];
        foreach ($DB->request(['FROM' => Db::t('allocations'), 'WHERE' => ['initiatives_id' => $ids]]) as $a) {
            $share[(int) $a['benefits_id']] = ($share[(int) $a['benefits_id']] ?? 0) + (float) $a['percent'] / 100;
        }
        if (!$share) {
            return [];
        }
        $okBen = [];
        foreach ($DB->request(['FROM' => Db::t('benefits'), 'WHERE' => ['id' => array_keys($share), 'status' => ['approved', 'closed'], 'type' => 'financial', 'currency' => $cur, 'is_deleted' => 0]]) as $b) {
            $okBen[(int) $b['id']] = true;
        }
        $planned = [];
        $validated = [];
        if ($okBen) {
            foreach ($DB->request(['FROM' => Db::t('benefit_plans'), 'WHERE' => ['benefits_id' => array_keys($okBen)]]) as $r) {
                $planned[$r['period']] = ($planned[$r['period']] ?? 0) + (float) $r['planned_amount'] * $share[(int) $r['benefits_id']];
            }
            foreach ($DB->request(['FROM' => Db::t('measurements'), 'WHERE' => ['benefits_id' => array_keys($okBen), 'status' => 'validated', 'is_current' => 1]]) as $r) {
                $validated[$r['period']] = ($validated[$r['period']] ?? 0) + (float) $r['validated_amount'] * $share[(int) $r['benefits_id']];
            }
        }
        $out = [];
        $thisMonth = date('Y-m-01');
        $cum = 0.0;
        $cumP = 0.0;
        // os acumulados consideram tudo o que veio antes da janela
        $start = date('Y-m-01', strtotime("-$back months"));
        foreach ($validated as $period => $v) {
            if ($period < $start) {
                $cum += $v;
            }
        }
        foreach ($planned as $period => $v) {
            if ($period < $start) {
                $cumP += $v;
            }
        }
        for ($i = -$back; $i <= $ahead; $i++) {
            $m = date('Y-m-01', strtotime(($i >= 0 ? '+' : '') . $i . ' months'));
            $cum += $validated[$m] ?? 0;
            $cumP += $planned[$m] ?? 0;
            $out[] = ['label' => date('m/y', strtotime($m)), 'planned' => round($planned[$m] ?? 0, 2), 'validated' => round($validated[$m] ?? 0, 2), 'cum' => round($cum, 2), 'cumPlanned' => round($cumP, 2), 'future' => $m > $thisMonth];
        }
        return $out;
    }
}
