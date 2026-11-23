<?php

namespace GlpiPlugin\Vmo;

/** Reúne os números do painel de KPIs (tela e book em PDF usam exatamente os mesmos). */
class KpiData
{
    /**
     * @param array<int,array> $visible iniciativas visíveis (Access::visibleInitiatives)
     */
    public static function build(array $visible, int $sel = 0, int $window = 30, ?string $from = null, ?string $to = null): array
    {
        $today = Db::today();
        $scope = $sel && isset($visible[$sel]) ? [$sel => $visible[$sel]] : $visible;
        $ids = array_keys($scope);
        $from = Db::date($from ?? '');
        $to = Db::date($to ?? '');
        $tasks = $ids ? Db::rows('tasks', ['initiatives_id' => $ids, 'is_deleted' => 0]) : [];
        if ($from || $to) {
            // o período filtra pela data que situa a tarefa: o fim real (se concluída) ou o fim planejado; sem nenhuma data, a tarefa fica
            $tasks = array_values(array_filter($tasks, function ($t) use ($from, $to) {
                $d = $t['actual_end'] ?: $t['planned_end'];
                return !$d || ((!$from || $d >= $from) && (!$to || $d <= $to));
            }));
        }
        $K = Kpi::tasks($tasks, $today, $window);
        $metrics = $scope ? Initiative::metricsFor($scope, null, false) : [];
        $run = array_filter($scope, fn ($r) => $r['status'] === 'running');
        $spis = [];
        $healthy = 0;
        $fresh = 0;
        $blocked = 0;
        foreach ($run as $r) {
            $sp = Kpi::spi($metrics[$r['id']]['progress'] ?? null, Kpi::elapsed($r['planned_start'], $r['planned_end'], $today));
            if ($sp !== null) {
                $spis[] = $sp;
            }
            $f = Committee::farol($r);
            $healthy += $f['key'] === 'green' ? 1 : 0;
            $fresh += ($f['days'] ?? 99) <= 15 ? 1 : 0;
            $blocked += $f['block'] ? 1 : 0;
        }
        $nRun = count($run);
        $byIni = [];
        foreach ($tasks as $t) {
            $byIni[(int) $t['initiatives_id']][] = $t;
        }
        $rows = [];
        foreach ($scope as $r) {
            $k = Kpi::tasks($byIni[$r['id']] ?? [], $today, $window);
            $rows[] = ['ini' => $r, 'k' => $k, 'progress' => $metrics[$r['id']]['progress'] ?? null,
                'spi' => Kpi::spi($metrics[$r['id']]['progress'] ?? null, Kpi::elapsed($r['planned_start'], $r['planned_end'], $today))];
        }
        // período coberto pelos dados: menor e maior data entre tarefas, iniciativas e checkpoints
        $dates = [];
        foreach ($tasks as $t) {
            foreach (['planned_start', 'planned_end', 'actual_start', 'actual_end'] as $f) {
                if (!empty($t[$f])) {
                    $dates[] = $t[$f];
                }
            }
        }
        foreach ($scope as $r) {
            foreach (['planned_start', 'planned_end', 'actual_start', 'actual_end'] as $f) {
                if (!empty($r[$f])) {
                    $dates[] = $r[$f];
                }
            }
        }
        $cps = $ids ? Db::rows('checkpoints', ['initiatives_id' => $ids], ['checkpoint_date DESC']) : [];
        foreach ($cps as $c) {
            $dates[] = $c['checkpoint_date'];
        }
        sort($dates);
        $period = ['from' => $from ?: ($dates[0] ?? null), 'to' => $to ?: ($dates ? max(end($dates), $today) : $today), 'custom' => (bool) ($from || $to), 'first' => $dates[0] ?? null, 'last' => $dates ? end($dates) : null];
        return [
            'period' => $period, 'checkpoints' => $cps, 'sel' => $sel,
            'today' => $today, 'window' => $window, 'scope' => $scope, 'tasks' => $tasks, 'K' => $K, 'rows' => $rows, 'owners' => Kpi::byOwner($tasks, $today), 'weekly' => Kpi::weekly($tasks, $today, 8),
            'nRun' => $nRun, 'healthy' => $healthy, 'fresh' => $fresh, 'blocked' => $blocked,
            'healthyPct' => $nRun ? round($healthy / $nRun * 100, 1) : null, 'freshPct' => $nRun ? round($fresh / $nRun * 100, 1) : null,
            'spi' => $spis ? round(array_sum($spis) / count($spis), 2) : null,
        ];
    }

    /** Definições para tela e book: [chave => [título, o que mede, como é calculado, referência]]. */
    public const GLOSSARY = [
        'otd' => ['Entrega no prazo (OTD)', 'Das tarefas concluídas, quantas terminaram até a data planejada.', 'concluídas no prazo ÷ concluídas com datas', '85% ou mais'],
        'completion' => ['Conclusão', 'Quanto do trabalho já foi concluído.', 'tarefas concluídas ÷ tarefas ativas', '—'],
        'overdue' => ['Atrasadas', 'Tarefas abertas com a data final no passado.', 'abertas com prazo vencido ÷ abertas', 'até 10%'],
        'throughput' => ['Entregas por semana', 'Ritmo de entrega: tarefas concluídas por semana na janela escolhida.', 'concluídas na janela ÷ semanas da janela', '—'],
        'lead' => ['Tempo de ciclo', 'Dias entre o início real e o fim real das tarefas concluídas, comparados ao planejado.', 'média de (fim real − início real)', '—'],
        'effort' => ['Aderência de esforço', 'Horas realizadas em relação às planejadas nas tarefas concluídas.', 'horas reais ÷ horas planejadas', 'até 110%'],
        'plan' => ['Qualidade do plano', 'Tarefas abertas sem responsável ou sem prazo não podem ser cobradas nem medidas.', 'abertas sem dono (ou sem data) ÷ abertas', 'até 5%'],
        'ms' => ['Marcos no prazo', 'Marcos (entregas-chave) concluídos até a data planejada.', 'marcos no prazo ÷ marcos concluídos', '85% ou mais'],
        'updated' => ['Atualização em dia', 'Iniciativas em execução com checkpoint ou atualização nos últimos 15 dias.', 'atualizadas em 15 dias ÷ em execução', '80% ou mais'],
        'health' => ['Iniciativas saudáveis', 'Iniciativas em execução sem atraso relevante, sem bloqueio e atualizadas.', 'saudáveis ÷ em execução', '70% ou mais'],
        'spi' => ['SPI — Índice de Desempenho de Prazo', 'Compara o progresso real com o que o calendário esperava até hoje. 1,00 = no ritmo; abaixo de 1,00 = atrás do plano; acima = adiantado.', 'progresso real (%) ÷ prazo decorrido (%) de cada iniciativa, em média', '0,90 ou mais'],
        'block' => ['Bloqueios abertos', 'Iniciativas em execução cujo último checkpoint declara bloqueio.', 'contagem', 'zero'],
    ];
}
