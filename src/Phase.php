<?php

namespace GlpiPlugin\Vmo;

use Session;

/**
 * Registros das etapas da ficha da iniciativa: partes interessadas, status report, requisição de mudança de implantação,
 * termo de aceite, lições aprendidas e termo de encerramento. Um modelo só (tabela records, dados em JSON conforme o tipo),
 * mostrado igual na ficha (editável) e no Comitê (somente leitura).
 */
class Phase
{
    /** Etapas da ficha: chave => rótulo. A chave também marca os anexos de cada etapa. */
    public const SECTIONS = [
        'brainstorm' => 'Registro de Brainstorm',
        'strategy'   => 'Planejamento Estratégico',
        'initiation' => 'Iniciação e Alinhamento',
        'tasks'      => 'Tarefas',
        'monitor'    => 'Monitoramento e Controle',
        'costs'      => 'Custos',
        'closure'    => 'Encerramento e Transição',
    ];

    private const H = ['green' => 'Verde · no rumo', 'yellow' => 'Amarelo · atenção', 'red' => 'Vermelho · crítico'];
    private const LEVEL = ['3' => 'Alto', '2' => 'Médio', '1' => 'Baixo'];

    /**
     * tipo => [rótulo, ícone, campo-título, assina?, campos]; campo = [rótulo, tipo, opções/dica, largura].
     */
    public static function kinds(): array
    {
        return [
            'stake' => ['Registro das partes interessadas', 'ti-users-group', 'name', false, [
                'name'        => ['Parte interessada (pessoa ou área)', 'text', null, 'req'],
                'users_id'    => ['Usuário do GLPI (se houver)', 'user'],
                'category'    => ['Categoria', 'select', ['sponsor' => 'Patrocinador', 'client' => 'Cliente / requisitante', 'team' => 'Equipe do projeto', 'user' => 'Usuário final', 'supplier' => 'Fornecedor', 'regulator' => 'Governança / regulador', 'other' => 'Outro']],
                'role'        => ['Papel na iniciativa', 'text'],
                'interest'    => ['Interesse', 'select', self::LEVEL],
                'influence'   => ['Influência', 'select', self::LEVEL],
                'attitude'    => ['Postura atual', 'select', ['supporter' => 'Apoiador', 'neutral' => 'Neutro', 'resistant' => 'Resistente']],
                'expectation' => ['Expectativas e requisitos', 'area'],
                'strategy'    => ['Estratégia de engajamento', 'area'],
                'comm'        => ['Comunicação (canal e frequência)', 'text'],
            ]],
            'status' => ['Status report', 'ti-report', 'auto', false, [
                'health'      => ['Saúde geral', 'select', self::H],
                'progress'    => ['Avanço (%)', 'text'],
                'accomplished' => ['Realizado no período', 'area'],
                'next'        => ['Próximos passos', 'area'],
                'issues'      => ['Problemas, riscos e bloqueios', 'area'],
                'decisions'   => ['Decisões / apoio necessário', 'area'],
                'schedule'    => ['Situação do prazo', 'text'],
                'cost'        => ['Situação do custo', 'text'],
            ]],
            'rm' => ['Requisição de mudança de implantação', 'ti-git-pull-request', 'title', false, [
                'title'       => ['Título da mudança', 'text', null, 'req'],
                'type'        => ['Tipo', 'select', ['normal' => 'Normal', 'emergency' => 'Emergencial', 'standard' => 'Padrão']],
                'reason'      => ['Motivo / justificativa', 'area'],
                'description' => ['O que será mudado', 'area'],
                'areas'       => ['Áreas e sistemas impactados', 'text'],
                'tasks'       => ['Tarefas relacionadas', 'tasks'],
                'window_start' => ['Janela: início', 'datetime'],
                'window_end'  => ['Janela: fim', 'datetime'],
                'impact'      => ['Impacto e risco da mudança', 'area'],
                'rollback'    => ['Plano de retorno (rollback)', 'area'],
                'validation'  => ['Testes e validação', 'area'],
                'approver'    => ['Aprovador', 'user'],
                'rm_status'   => ['Situação', 'select', ['draft' => 'Rascunho', 'requested' => 'Solicitada', 'approved' => 'Aprovada', 'executed' => 'Executada', 'rejected' => 'Rejeitada', 'cancelled' => 'Cancelada']],
            ]],
            'accept' => ['Termo de aceite', 'ti-certificate', 'title', true, [
                'title'       => ['Identificação do termo', 'text', null, 'req'],
                'decision'    => ['Decisão', 'select', ['full' => 'Aceite total', 'partial' => 'Aceite com ressalvas', 'refused' => 'Não aceito']],
                'deliverables' => ['Entregas avaliadas', 'area'],
                'criteria'    => ['Critérios de aceite verificados', 'area'],
                'pending'     => ['Pendências e ressalvas', 'area'],
                'acceptor_id' => ['Quem aceita (usuário)', 'user'],
                'acceptor'    => ['ou nome / área externa', 'text'],
            ]],
            'lessons' => ['Lições aprendidas', 'ti-bulb', 'title', false, [
                'title'       => ['Lição (resumo)', 'text', null, 'req'],
                'type'        => ['Tipo', 'select', ['good' => 'Deu certo', 'improve' => 'Melhorar', 'risk' => 'Evitar']],
                'category'    => ['Área', 'select', ['plan' => 'Planejamento', 'exec' => 'Execução', 'comm' => 'Comunicação', 'people' => 'Pessoas', 'tech' => 'Tecnologia', 'supplier' => 'Fornecedores', 'cost' => 'Custos', 'other' => 'Outra']],
                'what'        => ['O que aconteceu', 'area'],
                'recommend'   => ['Recomendação para próximas iniciativas', 'area'],
            ]],
            'close' => ['Termo de encerramento', 'ti-lock', 'title', true, [
                'title'       => ['Identificação do termo', 'text', null, 'req'],
                'results'     => ['Resultados alcançados frente ao objetivo', 'area'],
                'deliverables' => ['Entregas finais', 'area'],
                'cost'        => ['Custo final e variação', 'text'],
                'schedule'    => ['Prazo final e variação', 'text'],
                'pending'     => ['Pendências e quem assume', 'area'],
                'transition'  => ['Transição para operação / suporte', 'area'],
                'owner_id'    => ['Responsável pela operação', 'user'],
                'resources'   => ['Liberação de recursos e equipe', 'area'],
            ]],
        ];
    }

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_vmo_records';
    }

    public static function forInitiative(int $initiativeId, string $kind): array
    {
        $out = [];
        foreach (Db::rows('records', ['initiatives_id' => $initiativeId, 'kind' => $kind, 'is_deleted' => 0], ['record_date DESC', 'id DESC']) as $r) {
            $r['d'] = json_decode((string) $r['data'], true) ?: [];
            $out[] = $r;
        }
        return $out;
    }

    public static function save(int $initiativeId, string $kind, int $id, array $in): int
    {
        global $DB;
        $spec = self::kinds()[$kind] ?? throw new RuleException('Tipo de registro inválido.');
        $init = Initiative::load($initiativeId, true);
        if (!Access::canEditInitiative($init)) {
            throw RuleException::denied();
        }
        $cur = $id ? Db::row('records', $id) : null;
        if ($id && (!$cur || (int) $cur['initiatives_id'] !== $initiativeId || $cur['kind'] !== $kind || $cur['is_deleted'])) {
            throw new RuleException('Registro não encontrado.', 404);
        }
        if ($cur && $cur['status'] === 'signed') {
            throw new RuleException('Registro já assinado: não pode mais ser alterado.');
        }
        $data = [];
        $tasks = Task::forInitiative($initiativeId);
        foreach ($spec[4] as $f => $def) {
            $v = $in[$f] ?? '';
            switch ($def[1]) {
                case 'area': $v = Db::text($v, 5000); break;
                case 'text': $v = Db::text($v, 255); break;
                case 'date': $v = Db::date($v) ?: ''; break;
                case 'datetime': $v = preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d$/', (string) $v) ? $v : ''; break;
                case 'user': $v = max(0, (int) $v); break;
                case 'select': $v = isset($def[2][$v]) ? (string) $v : (string) array_key_first($def[2]); break;
                case 'tasks': $v = array_values(array_filter(array_map('intval', (array) $v), fn ($t) => isset($tasks[$t]))); break;
            }
            $data[$f] = $v;
        }
        foreach ((array) (json_decode((string) ($cur['data'] ?? ''), true) ?: []) as $k => $v) {
            if (str_starts_with((string) $k, 'sent_')) {
                $data[$k] = $v;
            }
        }
        $date = Db::date($in['record_date'] ?? '') ?: Db::today();
        $title = $spec[2] === 'auto' ? self::label($kind) . ' ' . date('d/m/Y', strtotime($date)) : Db::text((string) ($data[$spec[2]] ?? ''), 255);
        if ($title === '') {
            throw new RuleException('Preencha o campo principal (' . $spec[4][$spec[2]][0] . ').');
        }
        $status = $kind === 'rm' ? (string) $data['rm_status'] : ($cur['status'] ?? 'draft');
        if ($kind === 'status' && !empty($data['sent_at'])) {
            $status = 'sent';
        }
        $row = ['title' => $title, 'record_date' => $date, 'data' => json_encode($data, JSON_UNESCAPED_UNICODE), 'status' => $status, 'date_mod' => Db::now()];
        if ($cur) {
            $DB->update(self::getTable(), $row, ['id' => $id]);
        } else {
            $id = Db::insert('records', $row + ['initiatives_id' => $initiativeId, 'entities_id' => (int) $init['entities_id'], 'kind' => $kind, 'users_id' => (int) Session::getLoginUserID(), 'date_creation' => Db::now()]);
        }
        $DB->update(Initiative::getTable(), ['last_update_at' => Db::now()], ['id' => $initiativeId]);
        Audit::log('Initiative', $initiativeId, 'record_' . $kind, ['titulo' => $title], 1, (int) $init['entities_id']);
        return $id;
    }

    /** Quem pode receber o status report: partes interessadas com usuário, requisitante, acompanhante, patrocinador e gestor. */
    public static function recipients(int $initiativeId): array
    {
        $init = Initiative::load($initiativeId);
        $c = Charter::forInitiative($initiativeId);
        $out = [];
        foreach ([[(int) ($c['requester_users_id'] ?? 0), 'requisitante'], [(int) ($c['follower_users_id'] ?? 0), 'acompanhamento'], [(int) $init['users_id_sponsor'], 'patrocinador'], [(int) $init['users_id_manager'], 'gestor']] as [$u, $r]) {
            if ($u > 0) {
                $out[$u] = Db::userName($u) . ' · ' . $r;
            }
        }
        foreach (self::forInitiative($initiativeId, 'stake') as $s) {
            $u = (int) ($s['d']['users_id'] ?? 0);
            if ($u > 0 && !isset($out[$u])) {
                $out[$u] = Db::userName($u) . ' · ' . $s['title'];
            }
        }
        return $out;
    }

    /** Rascunho preenchido com os dados da iniciativa (para pré-visualizar antes de existir o registro). */
    public static function draft(int $initiativeId, string $kind): array
    {
        $init = Initiative::load($initiativeId);
        $tasks = Task::forInitiative($initiativeId);
        $open = array_filter($tasks, fn ($t) => in_array($t['status'], ['todo', 'doing'], true));
        $c = Charter::forInitiative($initiativeId);
        $done = count(array_filter($tasks, fn ($t) => $t['status'] === 'done'));
        $fmt = fn (?string $d) => $d ? date('d/m/Y', strtotime($d)) : '-';
        $pending = $open ? implode("\n", array_map(fn ($t) => '- ' . $t['name'] . ' (prazo ' . $fmt($t['planned_end']) . ')', array_slice($open, 0, 8))) : 'Nenhuma pendência.';
        if ($kind === 'accept') {
            return ['title' => 'Aceite - ' . $init['name'], 'decision' => 'full', 'deliverables' => (string) ($c['deliverables'] ?? ''), 'criteria' => (string) ($c['success_criteria'] ?? ''), 'pending' => $pending, 'acceptor_id' => (int) ($c['requester_users_id'] ?? 0), 'acceptor' => (string) ($c['requester_name'] ?? '')];
        }
        return ['title' => 'Encerramento - ' . $init['name'], 'results' => trim((string) $init['objective']) !== '' ? 'Objetivo: ' . $init['objective'] . "\nTarefas concluídas: $done de " . count($tasks) . '.' : "Tarefas concluídas: $done de " . count($tasks) . '.',
            'deliverables' => (string) ($c['deliverables'] ?? ''), 'cost' => '', 'schedule' => 'Planejado ' . $fmt($init['planned_end']) . ' · real ' . $fmt($init['actual_end']), 'pending' => $pending,
            'transition' => '', 'owner_id' => (int) $init['users_id_manager'], 'resources' => ''];
    }

    /** PDF de um registro (status report, termo de aceite ou de encerramento): [bytes, nome do arquivo, título na documentação]. */
    public static function pdf(array $init, array $r): array
    {
        $author = (string) getUserName((int) Session::getLoginUserID());
        $date = date('d/m/Y', strtotime($r['record_date'] ?: 'now'));
        if ($r['kind'] === 'status') {
            return [StatusReportPdf::render($init, $r, $author), StatusReportPdf::fileName($init, $r), 'Status report ' . $date . ' (enviado)'];
        }
        return [TermPdf::render($init, $r, $author), TermPdf::fileName($init, $r), self::label($r['kind']) . ' ' . $date . ($r['status'] === 'signed' ? ' (assinado)' : ' (enviado)')];
    }

    /** Envia o status report: guarda o PDF na Documentação, avisa os destinatários e, se pedido, registra no chamado de origem. */
    public static function sendStatus(int $id, array $to, string $message, bool $ticket): string
    {
        global $DB;
        $r = Db::row('records', $id);
        if (!$r || !in_array($r['kind'], ['status', 'close', 'accept'], true) || $r['is_deleted']) {
            throw new RuleException('Registro não encontrado.', 404);
        }
        $init = Initiative::load((int) $r['initiatives_id'], true);
        if (!Access::canEditInitiative($init)) {
            throw RuleException::denied();
        }
        $cand = self::recipients((int) $init['id']);
        $to = array_values(array_filter(array_unique(array_map('intval', $to)), fn ($u) => isset($cand[$u])));
        if (!$to) {
            throw new RuleException('Escolha pelo menos um destinatário.');
        }
        $r['d'] = json_decode((string) $r['data'], true) ?: [];
        [$pdf, $fname, $dtitle] = self::pdf($init, $r);
        $doc = Committee::storeGenerated($init, $r['kind'] === 'status' ? 'monitor' : 'closure', $dtitle, $fname, $pdf, (int) ($r['d']['sent_doc'] ?? 0));
        $names = array_map(fn ($u) => Db::userName($u), $to);
        $title = $init['code'] . ': ' . mb_strtolower(self::label($r['kind'])) . ' de ' . date('d/m/Y', strtotime($r['record_date'] ?: 'now'));
        $body = Db::text($message, 300) ?: 'Novo documento da iniciativa ' . $init['name'] . '.';
        foreach ($to as $u) {
            Outbox::push($u, 'status_report', $title, 'sr:' . $id . ':' . date('YmdHis'), 'Initiative', (int) $init['id'], $body, (int) $init['entities_id']);
        }
        $tk = $ticket ? Charter::originTicket((int) $init['id']) : 0;
        if ($tk > 0) {
            try {
                (new \ITILFollowup())->add(['itemtype' => 'Ticket', 'items_id' => $tk, 'is_private' => 0,
                    'content' => self::label($r['kind']) . ' da iniciativa ' . $init['code'] . ' (' . date('d/m/Y', strtotime($r['record_date'] ?: 'now')) . ')' . ($r['kind'] === 'status' ? ': saúde ' . StatusReportPdf::healthLabel($r['d']['health'] ?? 'green') . ', avanço ' . ($r['d']['progress'] ?? '-') . '%' : '') . '. ' . $body]);
            } catch (\Throwable $ex) {
            }
        }
        $d = $r['d'] + ['sent_at' => Db::now(), 'sent_by' => (int) Session::getLoginUserID(), 'sent_to' => implode(', ', $names), 'sent_doc' => $doc];
        $DB->update(self::getTable(), ['status' => $r['kind'] === 'status' ? 'sent' : $r['status'], 'data' => json_encode($d, JSON_UNESCAPED_UNICODE), 'date_mod' => Db::now()], ['id' => $id]);
        Audit::log('Initiative', (int) $init['id'], 'status_send', ['para' => implode(', ', $names)], 1, (int) $init['entities_id']);
        return self::label($r['kind']) . ' enviado em ' . Menu::datetime(Db::now()) . ' para: ' . implode(', ', $names) . '. O PDF foi para a Documentação.';
    }

    public static function sign(int $id): void
    {
        global $DB;
        $r = Db::row('records', $id) ?? throw new RuleException('Registro não encontrado.', 404);
        $init = Initiative::load((int) $r['initiatives_id'], true);
        if (!Access::canEditInitiative($init) || !(self::kinds()[$r['kind']][3] ?? false)) {
            throw RuleException::denied();
        }
        $DB->update(self::getTable(), ['status' => 'signed', 'signed_at' => Db::now(), 'signed_by' => (int) Session::getLoginUserID(), 'date_mod' => Db::now()], ['id' => $id]);
        $r2 = Db::row('records', $id);
        $r2['d'] = json_decode((string) $r2['data'], true) ?: [];
        if (!empty($r2['d']['sent_doc'])) {
            try {
                [$pdf, $fname, $dtitle] = self::pdf($init, $r2);
                Committee::storeGenerated($init, 'closure', $dtitle, $fname, $pdf, (int) $r2['d']['sent_doc']);
            } catch (\Throwable $ex) {
            }
        }
        Audit::log('Initiative', (int) $init['id'], 'record_sign', ['titulo' => $r['title']], 1, (int) $init['entities_id']);
        Outbox::toOwners($init, 'record', $init['code'] . ': ' . $r['title'] . ' registrado', 'sign:' . $id);
    }

    public static function remove(int $id): void
    {
        global $DB;
        $r = Db::row('records', $id) ?? throw new RuleException('Registro não encontrado.', 404);
        $init = Initiative::load((int) $r['initiatives_id'], true);
        if (!Access::canEditInitiative($init) || $r['status'] === 'signed') {
            throw RuleException::denied($r['status'] === 'signed' ? 'Registro assinado não pode ser removido.' : 'Sem permissão para esta operação.');
        }
        $DB->update(self::getTable(), ['is_deleted' => 1, 'date_mod' => Db::now()], ['id' => $id]);
    }

    public static function label(string $kind): string
    {
        return self::kinds()[$kind][0] ?? $kind;
    }

    /** Valor de um campo como texto, para a leitura. */
    private static function show(array $def, $v, array $tasks): string
    {
        if ($v === '' || $v === null || $v === []) {
            return '<span class="vmo-muted">—</span>';
        }
        return match ($def[1]) {
            'select'   => Menu::e($def[2][$v] ?? $v),
            'user'     => Menu::e(Db::userName((int) $v)),
            'datetime' => Menu::e(date('d/m/Y H:i', strtotime((string) $v))),
            'date'     => Menu::e(Menu::date((string) $v)),
            'tasks'    => Menu::e(implode(', ', array_map(fn ($t) => $tasks[$t]['name'] ?? '#' . $t, (array) $v))),
            default    => nl2br(Menu::e((string) $v)),
        };
    }

    /**
     * Lista + formulário de um tipo de registro. $edit = false é a leitura do Comitê.
     */
    public static function render(int $initiativeId, string $kind, string $self, bool $edit, array $defaults = []): string
    {
        $spec = self::kinds()[$kind];
        $e = fn ($v) => Menu::e($v);
        $tasks = Task::forInitiative($initiativeId);
        $form = function (array $r = []) use ($spec, $kind, $initiativeId, $self, $tasks, $e, $defaults): string {
            $d = $r['d'] ?? (!$r ? $defaults : []);
            $inner = "<div class='vmo-grid2 vmo-cols4'>";
            if ($spec[2] === 'auto') {
                $inner .= "<div class='vmo-f'><label class='form-label'>Data do relatório</label><div class='vmo-ctl'><input class='form-control' type='date' name='record_date' value='" . $e($r['record_date'] ?? Db::today()) . "'></div></div>";
            } else {
                $inner .= "<input type='hidden' name='record_date' value='" . $e($r['record_date'] ?? Db::today()) . "'>";
            }
            foreach ($spec[4] as $f => $def) {
                $v = $d[$f] ?? '';
                $name = $e($f);
                switch ($def[1]) {
                    case 'text': $ctl = "<input class='form-control' name='$name' value='" . $e($v) . "'" . (($def[3] ?? '') === 'req' ? ' required' : '') . ">"; break;
                    case 'area': $ctl = "<textarea class='form-control' name='$name' rows='3'>" . $e($v) . "</textarea>"; break;
                    case 'date': $ctl = "<input class='form-control' type='date' name='$name' value='" . $e($v) . "'>"; break;
                    case 'datetime': $ctl = "<input class='form-control' type='datetime-local' name='$name' value='" . $e($v) . "'>"; break;
                    case 'user': $ctl = Menu::userSelect($f, (int) $v); break;
                    case 'select': $ctl = Menu::select($f, $def[2], $v); break;
                    case 'tasks':
                        $ctl = "<select class='form-select' name='{$name}[]' multiple size='4'>";
                        foreach (Task::tree($tasks) as [$tid, $lvl]) {
                            $ctl .= "<option value='$tid'" . (in_array($tid, (array) $v, true) ? ' selected' : '') . '>' . $e(str_repeat('— ', $lvl) . $tasks[$tid]['name']) . '</option>';
                        }
                        $ctl .= '</select>';
                        break;
                    default: $ctl = '';
                }
                $cls = $def[1] === 'area' ? 'vmo-span2' : '';
                $inner .= "<div class='vmo-f $cls'><label class='form-label'>" . $e($def[0]) . "</label><div class='vmo-ctl'>$ctl</div></div>";
            }
            $inner .= '</div>';
            return "<form method='post' action='" . $e(Menu::url('/front/action.php')) . "'>" . Menu::csrf() . "<input type='hidden' name='do' value='record_save'><input type='hidden' name='kind' value='" . $e($kind) . "'>"
                . "<input type='hidden' name='initiatives_id' value='$initiativeId'><input type='hidden' name='id' value='" . (int) ($r['id'] ?? 0) . "'><input type='hidden' name='return' value='" . $e($self) . "'>$inner"
                . "<button class='btn btn-primary btn-sm mt-2'>" . (($r['id'] ?? 0) ? 'Salvar' : 'Registrar') . '</button></form>';
        };

        $rows = self::forInitiative($initiativeId, $kind);
        $out = "<div class='card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti {$spec[1]} me-1'></i>" . $e($spec[0]) . " <span class='text-secondary small'>(" . count($rows) . ")</span></h3></div>";
        if (!$rows) {
            $out .= "<div class='card-body vmo-muted'>Nenhum registro ainda.</div>";
        }
        foreach ($rows as $r) {
            $badge = '';
            if ($r['status'] === 'signed') {
                $badge = Menu::badge('Assinado ' . Menu::datetime($r['signed_at']), '#22c55e', 'ti-circle-check');
            } elseif ($kind === 'rm') {
                $badge = Menu::badge($spec[4]['rm_status'][2][$r['d']['rm_status'] ?? 'draft'] ?? '', '#6366f1');
            } elseif (!empty($r['d']['sent_at'])) {
                $badge = Menu::badge('Enviado ' . Menu::datetime($r['d']['sent_at']), '#22c55e', 'ti-send');
            } elseif ($kind === 'status' && !empty($r['d']['health'])) {
                $badge = Menu::badge($spec[4]['health'][2][$r['d']['health']] ?? '', ['green' => '#22c55e', 'yellow' => '#f59e0b', 'red' => '#ef4444'][$r['d']['health']] ?? '#94a3b8');
            }
            $view = "<div class='vmo-grid2 vmo-cols4 mt-2'>";
            foreach ($spec[4] as $f => $def) {
                if ($f === $spec[2] && $spec[2] !== 'auto') {
                    continue;
                }
                $view .= "<div class='vmo-f " . ($def[1] === 'area' ? 'vmo-span2' : '') . "'><div class='small vmo-muted'>" . $e($def[0]) . "</div><div>" . self::show($def, $r['d'][$f] ?? '', $tasks) . '</div></div>';
            }
            $view .= '</div>';
            $acts = '';
            if ($edit && $r['status'] !== 'signed') {
                $acts .= "<details class='vmo-inline'><summary>Editar</summary>" . $form($r) . '</details>';
                if ($spec[3]) {
                    $acts .= Menu::actionForm('record_sign', ['id' => $r['id'], 'return' => $self], "<button class='btn btn-sm btn-success mt-1' onclick=\"return confirm('Assinar e registrar? Depois disso o termo não pode mais ser alterado.')\"><i class='ti ti-signature me-1'></i>Assinar / registrar</button>");
                }
                $acts .= Menu::actionForm('record_remove', ['id' => $r['id'], 'return' => $self], "<button class='btn btn-sm btn-outline-danger mt-1' onclick=\"return confirm('Remover este registro?')\">Remover</button>");
            }
            if (in_array($kind, ['status', 'close', 'accept'], true)) {
                $pdfUrl = Menu::url('/front/statusreport.php?id=' . (int) $r['id']);
                $sendInfo = !empty($r['d']['sent_at']) ? "<div class='small mt-2'><i class='ti ti-send me-1 text-success'></i>Enviado em <b>" . $e(Menu::datetime($r['d']['sent_at'])) . "</b> por " . $e(Db::userName((int) ($r['d']['sent_by'] ?? 0))) . " para " . $e($r['d']['sent_to'] ?? '—') . '.</div>' : '';
                if ($edit) {
                    $cb = '';
                    foreach (self::recipients($initiativeId) as $u => $lab) {
                        $cb .= "<label class='form-check me-3'><input class='form-check-input' type='checkbox' name='to[]' value='$u'><span class='form-check-label'>" . $e($lab) . "</span></label>";
                    }
                    $hasTk = Charter::originTicket($initiativeId) > 0;
                    $acts .= "<details class='vmo-inline'><summary>" . (!empty($r['d']['sent_at']) ? 'Reenviar à área interessada' : 'Enviar à área interessada') . "</summary>"
                        . "<form method='post' action='" . $e(Menu::url('/front/action.php')) . "'>" . Menu::csrf() . "<input type='hidden' name='do' value='status_send'><input type='hidden' name='id' value='" . (int) $r['id'] . "'><input type='hidden' name='return' value='" . $e($self) . "'>"
                        . "<div class='alert alert-info py-2 mb-2'>Confira antes: use <b>Pré-visualizar antes de enviar</b>. Ao enviar, o PDF vai para a Documentação e os destinatários recebem um aviso no GLPI.</div>"
                        . "<div class='mb-2'><div class='small vmo-muted mb-1'>Destinatários</div><div class='d-flex flex-wrap'>$cb</div></div>"
                        . "<div class='vmo-f'><label class='form-label'>Mensagem (opcional)</label><div class='vmo-ctl'><textarea class='form-control' name='message' rows='2'></textarea></div></div>"
                        . ($hasTk ? "<label class='form-check mt-2'><input class='form-check-input' type='checkbox' name='ticket' value='1' checked><span class='form-check-label'>Registrar também no chamado de origem</span></label>" : '')
                        . "<button class='btn btn-primary btn-sm mt-2' onclick=\"return confirm('Enviar agora? Será registrada a data e a hora do envio e o PDF vai para a Documentação.')\"><i class='ti ti-send me-1'></i>Enviar documento</button></form></details>";
                }
                $acts = $sendInfo . $acts;
            }
            $prev = '';
            if (in_array($kind, ['status', 'close', 'accept'], true)) {
                $pu = $e(Menu::url('/front/statusreport.php?id=' . (int) $r['id']));
                $prev = "<a class='btn btn-sm btn-primary' target='_blank' href='$pu'><i class='ti ti-eye me-1'></i>" . (!empty($r['d']['sent_at']) ? 'Abrir PDF enviado' : 'Pré-visualizar antes de enviar') . "</a>"
                    . "<details class='vmo-inline' ontoggle=\"if(this.open){var f=this.querySelector('iframe');if(f&&!f.getAttribute('src'))f.setAttribute('src',f.dataset.src)}\"><summary>Ver o PDF aqui</summary><iframe data-src='$pu' style='width:100%;height:680px;border:1px solid #cbd5e1;border-radius:6px;background:#fff'></iframe></details>";
            }
            $out .= "<div class='card-body border-top'><div class='d-flex flex-wrap gap-2 align-items-center'><b>" . $e($r['title']) . "</b> $badge" . $prev . "<span class='vmo-muted small ms-auto'>" . $e(Menu::date($r['record_date'])) . ' · ' . $e(Db::userName((int) $r['users_id'])) . "</span></div>$view<div class='vmo-acts'>$acts</div></div>";
        }
        if ($edit) {
            $draft = in_array($kind, ['close', 'accept'], true) ? " <a class='btn btn-sm btn-outline-primary ms-2' target='_blank' href='" . $e(Menu::url('/front/statusreport.php?ini=' . $initiativeId . '&kind=' . $kind)) . "'><i class='ti ti-eye me-1'></i>Pré-visualizar modelo preenchido</a>" : '';
            $out .= "<div class='card-body border-top'><h4 class='mb-2' style='font-size:.95rem'>Novo registro$draft</h4>" . $form(!$rows && in_array($kind, ['close', 'accept'], true) ? ['d' => self::draft($initiativeId, $kind)] : []) . '</div>';
        }
        return $out . '</div>';
    }
}
