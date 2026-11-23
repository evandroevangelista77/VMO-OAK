<?php

namespace GlpiPlugin\Vmo;

use GlpiPlugin\Escalations\Api;

/**
 * Integração com o plugin Escalations (opcional: sem ele, o VMO funciona igual). A cada rodada da rotina vmonotify as situações
 * críticas do portfólio viram itens de plugin ("vmo") no Escalations; quando a situação acaba, o escalonamento é resolvido.
 * Quem decide hierarquia e níveis é o administrador, em Escalations → Hierarquias → Regras (origem "Item de plugin").
 */
class Escalation
{
    public const PLUGIN = 'vmo';

    /** tipo => [rótulo, severidade padrão, descrição] */
    public const TYPES = [
        'iniciativa_sem_atualizacao' => ['Iniciativa em execução sem atualização', 3, 'Mais de 15 dias sem checkpoint (gravidade 3) ou mais de 30 dias (gravidade 4).'],
        'iniciativa_prazo_critico'   => ['Iniciativa com prazo vencido ou saúde crítica', 4, 'Término planejado vencido, ou atraso, custo ou risco acima do limite de saúde.'],
        'marco_atrasado'             => ['Marco atrasado', 3, 'Tarefa marcada como marco com prazo vencido (gravidade 4 acima de 15 dias).'],
        'bloqueio_aberto'            => ['Bloqueio aberto / decisão do comitê', 3, 'Último checkpoint com bloqueio ou pedindo decisão do comitê (gravidade 4).'],
        'risco_critico'              => ['Risco crítico', 4, 'Risco aberto com exposição igual ou acima do limite crítico.'],
        'tap_nao_enviado'            => ['TAP sem enviar', 2, 'Iniciativa em execução sem o termo de abertura enviado.'],
        'assinatura_pendente'        => ['Assinatura do TAP pendente', 2, 'TAP enviado, mas sem a assinatura de algum signatário depois do prazo.'],
        'proposta_parada'            => ['Proposta aguardando decisão', 3, 'Iniciativa em Qualificação esperando aprovação além do prazo.'],
        'requisicao_parada'          => ['Requisição sem triagem', 2, 'Chamado novo de requisição sem iniciativa além do prazo.'],
        'dono_acima_limite'          => ['Dono acima do limite de iniciativas', 3, 'Dono executivo com mais iniciativas em execução do que o limite acordado.'],
    ];

    /** Prazos (dias) editáveis em Configuração → Escalonamento. */
    public const DEFAULTS = ['esc_on' => '1', 'esc_tap_days' => '3', 'esc_sign_days' => '5', 'esc_review_days' => '7', 'esc_request_days' => '5'];

    public static function enabled(): bool
    {
        return Settings::get('esc_on', '1') === '1' && class_exists(Api::class) && Api::available();
    }

    private static function days(string $key): int
    {
        return max(1, (int) Settings::get($key, self::DEFAULTS[$key]));
    }

    public static function registerTypes(): void
    {
        if (!class_exists(Api::class) || !Api::available()) {
            return;
        }
        foreach (self::TYPES as $type => [$label, $sev, $desc]) {
            Api::registerType(self::PLUGIN, $type, $label, $sev, $desc);
        }
    }

    private static function item(string $type, string $key, string $title, int $sev, array $facts, string $url, int $entity, string $summary = ''): array
    {
        return ['type' => $type, 'key' => $key, 'title' => $title, 'severity' => max(1, min(5, $sev)), 'summary' => $summary ?: $title, 'facts' => $facts, 'url' => $url, 'entities_id' => $entity];
    }

    /**
     * Situações críticas de agora (sem sessão: roda na rotina). Cada item: type, key (estável), title, severity, summary, facts, url, entities_id.
     */
    public static function detect(): array
    {
        global $DB;
        $today = Db::today();
        $out = [];
        $rows = [];
        foreach ($DB->request(['FROM' => Db::t('initiatives'), 'WHERE' => ['is_deleted' => 0]]) as $i) {
            $rows[(int) $i['id']] = $i;
        }
        $link = fn (array $i, string $tab = '') => '/plugins/vmo/front/initiative.php?id=' . (int) $i['id'] . ($tab ? '&tab=' . $tab : '');
        $running = array_filter($rows, fn ($i) => $i['status'] === 'running');
        $metrics = $running ? Initiative::metricsFor($running, null, false) : [];
        $th = Settings::thresholds();

        foreach ($running as $id => $i) {
            $cp = Committee::checkpoints($id)[0] ?? null;
            $f = Committee::farol($i, $cp);
            $name = $i['code'] . ' · ' . $i['name'];
            $who = ['Iniciativa' => $name, 'Gestor' => Db::userName((int) $i['users_id_manager']), 'Patrocinador' => Db::userName((int) $i['users_id_sponsor'])];
            // 1. sem atualização
            if (($f['days'] ?? 0) > 15) {
                $out[] = self::item('iniciativa_sem_atualizacao', 'ini:' . $id, $i['code'] . ': ' . $f['days'] . ' dias sem atualização', $f['days'] > 30 ? 4 : 3, $who + ['Dias sem atualização' => (string) $f['days']], $link($i, 'monitor'), (int) $i['entities_id']);
            }
            // 2. prazo vencido ou saúde crítica
            $m = $metrics[$id] ?? [];
            $late = $i['planned_end'] && $i['planned_end'] < $today && empty($i['forecast_end']) || ($i['forecast_end'] && $i['forecast_end'] < $today);
            if ($late || ($m['health'] ?? '') === 'red') {
                $why = [];
                if ($late) {
                    $why[] = 'prazo vencido (' . Menu::date($i['forecast_end'] ?: $i['planned_end']) . ')';
                }
                if (($m['health'] ?? '') === 'red') {
                    $why[] = 'saúde crítica (atraso, custo ou risco acima do limite)';
                }
                $out[] = self::item('iniciativa_prazo_critico', 'ini:' . $id, $i['code'] . ': ' . implode(' e ', $why), 4, $who + ['Motivo' => implode('; ', $why)], $link($i), (int) $i['entities_id']);
            }
            // 4. bloqueio / decisão do comitê
            if ($cp && (!empty($cp['has_block']) || !empty($cp['needs_committee']))) {
                $out[] = self::item('bloqueio_aberto', 'ini:' . $id, $i['code'] . ': ' . (!empty($cp['needs_committee']) ? 'pede decisão do comitê' : 'bloqueio aberto'), !empty($cp['needs_committee']) ? 4 : 3,
                    $who + ['Bloqueio' => mb_strimwidth((string) ($cp['blocker'] ?? ''), 0, 200, '…'), 'Ação necessária' => mb_strimwidth((string) ($cp['action_needed'] ?? ''), 0, 200, '…')], $link($i, 'monitor'), (int) $i['entities_id']);
            }
            // 6. TAP sem enviar
            $ch = Charter::forInitiative($id);
            $since = $i['actual_start'] ?: substr((string) $i['date_mod'], 0, 10);
            if ((!$ch || $ch['status'] !== 'issued') && $since && (strtotime($today) - strtotime($since)) / 86400 > self::days('esc_tap_days')) {
                $out[] = self::item('tap_nao_enviado', 'ini:' . $id, $i['code'] . ': TAP ainda não enviado', 2, $who + ['Em execução desde' => Menu::date($since)], $link($i, 'initiation'), (int) $i['entities_id']);
            }
        }
        // 3. marcos atrasados
        foreach ($DB->request(['FROM' => Db::t('tasks'), 'WHERE' => ['is_deleted' => 0, 'is_milestone' => 1, 'status' => ['todo', 'doing'], 'planned_end' => ['<', $today]]]) as $t) {
            $i = $rows[(int) $t['initiatives_id']] ?? null;
            if (!$i || $i['status'] !== 'running') {
                continue;
            }
            $d = (int) floor((strtotime($today) - strtotime($t['planned_end'])) / 86400);
            $out[] = self::item('marco_atrasado', 'task:' . $t['id'], $i['code'] . ': marco atrasado — ' . $t['name'], $d > 15 ? 4 : 3,
                ['Iniciativa' => $i['code'] . ' · ' . $i['name'], 'Marco' => $t['name'], 'Prazo' => Menu::date($t['planned_end']), 'Atraso (dias)' => (string) $d, 'Responsável' => Db::userName((int) $t['users_id_responsible'])], $link($i, 'tasks'), (int) $i['entities_id']);
        }
        // 5. risco crítico
        foreach ($DB->request(['FROM' => Db::t('risks'), 'WHERE' => ['is_deleted' => 0, 'kind' => 'risk', 'status' => ['open', 'mitigating']]]) as $r) {
            $i = $rows[(int) $r['initiatives_id']] ?? null;
            $x = Calc::riskExposure((int) $r['probability'], (int) $r['impact']);
            if (!$i || in_array($i['status'], ['closed', 'cancelled', 'rejected'], true) || $x < (int) $th['risk_red']) {
                continue;
            }
            $out[] = self::item('risco_critico', 'risk:' . $r['id'], $i['code'] . ': risco crítico (' . $x . ') — ' . $r['title'], 4,
                ['Iniciativa' => $i['code'] . ' · ' . $i['name'], 'Risco' => $r['title'], 'Exposição' => (string) $x, 'Responsável' => Db::userName((int) $r['users_id_owner']), 'Resposta' => mb_strimwidth((string) ($r['response'] ?? ''), 0, 200, '…')], $link($i, 'monitor'), (int) $i['entities_id']);
        }
        // 7. assinatura pendente
        foreach ($DB->request(['FROM' => Db::t('charters'), 'WHERE' => ['status' => 'issued']]) as $c) {
            $i = $rows[(int) $c['initiatives_id']] ?? null;
            if (!$i || in_array($i['status'], ['closed', 'cancelled', 'rejected'], true) || !$c['issued_at'] || (time() - strtotime($c['issued_at'] . ' UTC')) / 86400 <= self::days('esc_sign_days')) {
                continue;
            }
            $missing = array_diff_key(Charter::signers($i, $c), Charter::signatures($c));
            if ($missing) {
                $out[] = self::item('assinatura_pendente', 'ini:' . $i['id'], $i['code'] . ': TAP sem assinatura de ' . count($missing) . ' signatário(s)', 2,
                    ['Iniciativa' => $i['code'] . ' · ' . $i['name'], 'Falta assinar' => implode(', ', array_map(fn ($u, $r) => Db::userName($u) . ' (' . $r . ')', array_keys($missing), $missing)), 'Enviado em' => Menu::datetime($c['issued_at'])], $link($i, 'initiation'), (int) $i['entities_id']);
            }
        }
        // 8. proposta parada
        foreach ($rows as $i) {
            if ($i['status'] === 'review') {
                $ref = $i['last_update_at'] ?: $i['date_mod'];
                if ($ref && (time() - strtotime($ref . ' UTC')) / 86400 > self::days('esc_review_days')) {
                    $out[] = self::item('proposta_parada', 'ini:' . $i['id'], $i['code'] . ': proposta aguardando decisão há ' . (int) ((time() - strtotime($ref . ' UTC')) / 86400) . ' dias', 3,
                        ['Iniciativa' => $i['code'] . ' · ' . $i['name'], 'Patrocinador' => Db::userName((int) $i['users_id_sponsor'])], $link($i), (int) $i['entities_id']);
                }
            }
        }
        // 9. requisição parada (chamado novo, sem iniciativa)
        $linked = [];
        foreach ($DB->request(['SELECT' => ['items_id'], 'FROM' => Db::t('item_links'), 'WHERE' => ['itemtype' => 'Ticket']]) as $l) {
            $linked[] = (int) $l['items_id'];
        }
        $w = ['is_deleted' => 0, 'status' => \Ticket::INCOMING, 'date' => ['<', date('Y-m-d H:i:s', time() - self::days('esc_request_days') * 86400)]];
        if ($linked) {
            $w[] = ['NOT' => ['id' => $linked]];
        }
        foreach ($DB->request(['FROM' => 'glpi_tickets', 'WHERE' => $w, 'ORDER' => ['date ASC'], 'LIMIT' => 50]) as $t) {
            if (TicketWords::isAutomated((string) $t['name'])) {
                continue;
            }
            $d = (int) floor((time() - strtotime($t['date'])) / 86400);
            $out[] = self::item('requisicao_parada', 'ticket:' . $t['id'], 'Requisição #' . $t['id'] . ' sem triagem há ' . $d . ' dias', 2, ['Chamado' => '#' . $t['id'] . ' ' . $t['name'], 'Aberto em' => Menu::datetime($t['date'])],
                '/plugins/vmo/front/request.php?ticket=' . (int) $t['id'], (int) $t['entities_id']);
        }
        // 10. dono acima do limite
        $limit = Committee::wipLimit();
        $by = [];
        foreach ($running as $i) {
            [$ok, $on] = Committee::owner($i);
            $by[$ok]['name'] = $on;
            $by[$ok]['n'] = ($by[$ok]['n'] ?? 0) + 1;
            $by[$ok]['entity'] = (int) $i['entities_id'];
        }
        foreach ($by as $ok => $o) {
            if ($o['n'] > $limit && $o['name'] !== '') {
                $out[] = self::item('dono_acima_limite', 'owner:' . $ok, $o['name'] . ': ' . $o['n'] . ' iniciativas em execução (limite ' . $limit . ')', 3, ['Dono' => $o['name'], 'Em execução' => (string) $o['n'], 'Limite' => (string) $limit], '/plugins/vmo/front/committee.php', $o['entity']);
            }
        }
        return $out;
    }

    /**
     * Envia as situações ao Escalations e resolve as que acabaram. Só escala o que é novo ou mudou de gravidade (a rotina roda de 6 em 6 horas).
     * Devolve [novos escalonamentos, resolvidos].
     */
    public static function sync(): array
    {
        if (!self::enabled()) {
            return [0, 0];
        }
        self::registerTypes();
        $prev = json_decode(Settings::get('esc_open', '{}'), true) ?: [];
        $now = [];
        $sent = 0;
        foreach (self::detect() as $it) {
            $k = $it['type'] . '|' . $it['key'];
            if (isset($prev[$k]) && (int) $prev[$k] === $it['severity']) {
                $now[$k] = $it['severity'];
                continue;
            }
            $r = Api::escalate(['plugin' => self::PLUGIN, 'type' => $it['type'], 'key' => $it['key'], 'title' => $it['title'], 'severity' => $it['severity'], 'summary' => $it['summary'],
                'facts' => $it['facts'], 'url' => $it['url'], 'entities_id' => $it['entities_id'], 'label' => self::TYPES[$it['type']][0]]);
            if ($r !== null) {   // sem regra/hierarquia: tenta de novo na próxima rodada
                $now[$k] = $it['severity'];
                $sent += !empty($r['created']) ? 1 : 0;
            }
        }
        $resolved = 0;
        foreach (array_diff_key($prev, $now) as $k => $_) {
            [$type, $key] = explode('|', $k, 2);
            Api::resolve(self::PLUGIN, $type, $key, 'Situação encerrada no VMO.');
            $resolved++;
        }
        Settings::set(['esc_open' => json_encode($now)]);
        return [$sent, $resolved];
    }
}
