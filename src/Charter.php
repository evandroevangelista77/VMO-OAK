<?php

namespace GlpiPlugin\Vmo;

use Session;

/**
 * TAP: Termo de Abertura do Projeto. Nasce em rascunho junto com a iniciativa (quem demandou, quem acompanha, objetivo,
 * escopo, entregas, início e prazo de entrega) e, ao ser emitido, comunica o requisitante: aviso no GLPI, acompanhamento no
 * chamado de origem e uma página imprimível ("Comunicado de início").
 */
class Charter
{
    public static function getTable($classname = null)
    {
        return 'glpi_plugin_vmo_charters';
    }

    public static function forInitiative(int $initiativeId): ?array
    {
        return Db::rows('charters', ['initiatives_id' => $initiativeId], ['id ASC'], 1)[0] ?? null;
    }

    /** Chamado de origem da iniciativa (vínculo "origin"), se houver. */
    public static function originTicket(int $initiativeId): int
    {
        $r = Db::rows('item_links', ['initiatives_id' => $initiativeId, 'itemtype' => 'Ticket'], ['relation = \'origin\' DESC', 'id ASC'], 1)[0] ?? null;
        return $r ? (int) $r['items_id'] : 0;
    }

    private static function ticketRequester(int $ticketId): int
    {
        global $DB;
        if ($ticketId <= 0) {
            return 0;
        }
        $r = $DB->request(['SELECT' => ['users_id'], 'FROM' => 'glpi_tickets_users', 'WHERE' => ['tickets_id' => $ticketId, 'type' => \CommonITILActor::REQUESTER], 'LIMIT' => 1])->current();
        return $r ? (int) $r['users_id'] : 0;
    }

    /** Critérios estratégicos definidos na etapa de planejamento: entram no TAP como "Alinhamento estratégico". */
    public static function criteria(array $i): array
    {
        $score = Initiative::score($i);
        return [
            'Perspectiva'          => $i['catalogs_id_perspective'] ? Catalog::name((int) $i['catalogs_id_perspective']) : '—',
            'Objetivo estratégico' => $i['catalogs_id_objective'] ? Catalog::name((int) $i['catalogs_id_objective']) : '—',
            'Dono executivo'       => Committee::owner($i)[1] ?: '—',
            'Faixa'                => $i['band'] ? (Initiative::BANDS[$i['band']] ?? $i['band']) : '—',
            'Tipo de benefício'    => $i['benefit_type'] ? (Initiative::BENEFIT_TYPES[$i['benefit_type']] ?? '—') : '—',
            'Benefício declarado'  => $i['benefit_declared'] ?: '—',
            'Áreas envolvidas'     => $i['areas'] ?: '—',
            'Meta'                 => $i['target_value'] ?: '—',
            'Priorização (score)'  => $score === null ? '—' : number_format($score, 1, ',', '') . ' · ' . Initiative::scoreBand($score),
        ];
    }

    /** Fotografia do termo no momento do envio: é o que o Comitê passa a ver. */
    public static function build(array $init, array $c): array
    {
        $stake = [];
        foreach (Phase::forInitiative((int) $init['id'], 'stake') as $s) {
            $stake[] = ['name' => $s['title']] + $s['d'];
        }
        $ms = [];
        foreach (Task::forInitiative((int) $init['id']) as $t) {
            if ($t['is_milestone'] && $t['status'] !== 'cancelled') {
                $ms[] = [$t['name'], $t['planned_end']];
            }
        }
        return ['code' => $init['code'], 'name' => $init['name'], 'manager' => Db::userName((int) $init['users_id_manager']), 'sponsor' => Db::userName((int) $init['users_id_sponsor']),
            'requester' => (int) $c['requester_users_id'] ? Db::userName((int) $c['requester_users_id']) : ($c['requester_name'] ?: '—'), 'follower' => Db::userName((int) $c['follower_users_id']),
            'justification' => $init['justification'], 'problem' => $init['problem'], 'criteria' => self::criteria($init), 'stakeholders' => $stake, 'milestones' => $ms,
            'fields' => array_intersect_key($c, array_flip(['objective', 'scope', 'out_of_scope', 'deliverables', 'premises', 'constraints', 'risks_initial', 'success_criteria', 'budget_note', 'start_date', 'delivery_date']))];
    }

    public static function snap(?array $c): ?array
    {
        return $c && !empty($c['snapshot']) ? (json_decode((string) $c['snapshot'], true) ?: null) : null;
    }

    /** Garante o rascunho do TAP (cria se não existir) e devolve a linha. */
    public static function ensure(int $initiativeId): ?array
    {
        $c = self::forInitiative($initiativeId);
        if ($c) {
            return $c;
        }
        $i = Db::row('initiatives', $initiativeId);
        if (!$i) {
            return null;
        }
        $requester = self::ticketRequester(self::originTicket($initiativeId)) ?: (int) $i['users_id_manager'] ?: (int) $i['users_id_author'];
        $follower = (int) $i['users_id_sponsor'] ?: (int) $i['users_id_manager'] ?: (int) $i['users_id_author'];
        Db::insert('charters', [
            'initiatives_id'     => $initiativeId,
            'entities_id'        => (int) $i['entities_id'],
            'requester_users_id' => $requester,
            'follower_users_id'  => $follower,
            'objective'          => $i['objective'] ?: ($i['opportunity'] ?: null),
            'scope'              => $i['description'] ?: null,
            'start_date'         => $i['planned_start'] ?: null,
            'delivery_date'      => $i['forecast_end'] ?: ($i['planned_end'] ?: null),
            'status'             => 'draft',
            'date_creation'      => Db::now(),
            'date_mod'           => Db::now(),
        ]);
        return self::forInitiative($initiativeId);
    }

    public static function save(int $initiativeId, array $in): void
    {
        $init = Initiative::load($initiativeId, true);
        if (!Access::canEditInitiative($init)) {
            throw RuleException::denied();
        }
        $c = self::ensure($initiativeId);
        $d = [
            'requester_users_id' => max(0, (int) ($in['requester_users_id'] ?? 0)),
            'requester_name'     => Db::text($in['requester_name'] ?? '', 255) ?: null,
            'follower_users_id'  => max(0, (int) ($in['follower_users_id'] ?? 0)),
            'objective'          => Db::text($in['objective'] ?? '', 5000) ?: null,
            'scope'              => Db::text($in['scope'] ?? '', 5000) ?: null,
            'out_of_scope'       => Db::text($in['out_of_scope'] ?? '', 5000) ?: null,
            'deliverables'       => Db::text($in['deliverables'] ?? '', 5000) ?: null,
            'premises'           => Db::text($in['premises'] ?? '', 5000) ?: null,
            'constraints'        => Db::text($in['constraints'] ?? '', 5000) ?: null,
            'risks_initial'      => Db::text($in['risks_initial'] ?? '', 5000) ?: null,
            'success_criteria'   => Db::text($in['success_criteria'] ?? '', 5000) ?: null,
            'budget_note'        => Db::text($in['budget_note'] ?? '', 1000) ?: null,
            'start_date'         => Db::date($in['start_date'] ?? ''),
            'delivery_date'      => Db::date($in['delivery_date'] ?? ''),
            'date_mod'           => Db::now(),
        ];
        if ($d['start_date'] && $d['delivery_date'] && $d['start_date'] > $d['delivery_date']) {
            throw new RuleException('O início não pode ser posterior ao prazo de entrega.');
        }
        global $DB;
        $DB->update(self::getTable(), $d, ['id' => (int) $c['id']]);
        Audit::log('Initiative', $initiativeId, 'charter_save', [], 1, (int) $init['entities_id']);
    }

    /** Emite (ou reemite) o TAP e comunica requisitante e acompanhante. */
    public static function issue(int $initiativeId): string
    {
        global $DB;
        $init = Initiative::load($initiativeId, true);
        if (!Access::canEditInitiative($init)) {
            throw RuleException::denied();
        }
        $c = self::ensure($initiativeId);
        if (!$c['requester_users_id'] && !$c['requester_name']) {
            throw new RuleException('Informe quem demandou a iniciativa.');
        }
        if (!$c['follower_users_id']) {
            throw new RuleException('Informe quem vai acompanhar a iniciativa.');
        }
        if (!$c['delivery_date']) {
            throw new RuleException('Informe o prazo de entrega: o requisitante precisa saber quando receberá.');
        }
        if (trim((string) $c['objective']) === '') {
            throw new RuleException('Descreva o objetivo no termo de abertura.');
        }
        $rev = (int) $c['revision'] + 1;
        $DB->update(self::getTable(), ['status' => 'issued', 'revision' => $rev, 'issued_at' => Db::now(), 'issued_by' => (int) Session::getLoginUserID(), 'date_mod' => Db::now(),
            'snapshot' => json_encode(self::build($init, $c), JSON_UNESCAPED_UNICODE)], ['id' => (int) $c['id']]);
        $c = self::forInitiative($initiativeId);

        $when = ($c['start_date'] ? 'início ' . Menu::date($c['start_date']) . ' · ' : '') . 'entrega prevista ' . Menu::date($c['delivery_date']);
        $title = 'TAP ' . $init['code'] . ($rev > 1 ? " (rev. $rev)" : '') . ': iniciativa aberta';
        $sent = [];
        foreach (array_unique(array_filter([(int) $c['requester_users_id'], (int) $c['follower_users_id']])) as $u) {
            if (Outbox::push($u, 'charter', $title, "tap:$initiativeId:$rev", 'Initiative', $initiativeId, $init['name'] . ' · ' . $when, (int) $init['entities_id'])) {
                $sent[] = Db::userName($u);
            }
        }
        // acompanhamento público no chamado de origem: o requisitante vê no próprio chamado
        $msg = 'Sua demanda virou a iniciativa ' . $init['code'] . ' — ' . $init['name'] . '. ' . ucfirst($when) . '. Acompanhamento: ' . Db::userName((int) $c['follower_users_id']) . '.';
        $tk = self::originTicket($initiativeId);
        if ($tk > 0) {
            try {
                $fu = new \ITILFollowup();
                $fu->add(['itemtype' => 'Ticket', 'items_id' => $tk, 'content' => $msg, 'is_private' => 0]);
                $sent[] = 'chamado #' . $tk;
            } catch (\Throwable $ex) {
                // o aviso interno já foi feito; o chamado é só um canal a mais
            }
        }
        $DB->update(self::getTable(), ['sent_to' => mb_substr(implode(', ', $sent), 0, 255) ?: null, 'doc_id' => 0], ['id' => (int) $c['id']]);
        try {
            self::syncDoc($initiativeId);
        } catch (\Throwable $ex) {
            // o termo foi enviado; a cópia em PDF é refeita na próxima abertura da Documentação
        }
        Audit::log('Initiative', $initiativeId, 'charter_issue', ['revision' => $rev], 1, (int) $init['entities_id']);
        return 'TAP enviado em ' . Menu::datetime($c['issued_at']) . ' e registrado no Comitê' . ($sent ? '. Comunicado a: ' . implode(', ', $sent) : '') . '.';
    }

    /** Mantém na Documentação da iniciativa o PDF do TAP enviado (com as assinaturas já feitas). */
    public static function syncDoc(int $initiativeId): void
    {
        global $DB;
        $init = Initiative::load($initiativeId);
        $c = self::forInitiative($initiativeId);
        if (!$c || $c['status'] !== 'issued') {
            return;
        }
        $D = self::snap($c) ?: self::build($init, $c);
        $pdf = CharterPdf::render($init, $c, $D, self::signers($init, $c), self::signatures($c), (string) getUserName((int) Session::getLoginUserID()));
        $doc = Committee::storeGenerated($init, 'initiation', 'TAP - Termo de Abertura do Projeto (rev. ' . (int) $c['revision'] . ')', CharterPdf::fileName($init, $c), $pdf, (int) $c['doc_id']);
        if ($doc !== (int) $c['doc_id']) {
            $DB->update(self::getTable(), ['doc_id' => $doc], ['id' => (int) $c['id']]);
        }
    }

    /** Quem assina o TAP: requisitante, acompanhante, patrocinador e gestor (cada pessoa uma vez). */
    public static function signers(array $init, array $c): array
    {
        $out = [];
        foreach ([[(int) $c['requester_users_id'], 'Requisitante'], [(int) $c['follower_users_id'], 'Acompanhamento'], [(int) $init['users_id_sponsor'], 'Patrocinador'], [(int) $init['users_id_manager'], 'Gestor da iniciativa']] as [$u, $role]) {
            if ($u > 0 && !isset($out[$u])) {
                $out[$u] = $role;
            }
        }
        return $out;
    }

    public static function docHash(array $c): string
    {
        return hash('sha256', ($c['snapshot'] ?? '') . '|rev' . (int) $c['revision'] . '|' . $c['initiatives_id']);
    }

    /** Assinaturas da revisão em vigor: usuário => linha. */
    public static function signatures(array $c): array
    {
        $out = [];
        foreach (Db::rows('signatures', ['charters_id' => (int) $c['id'], 'revision' => (int) $c['revision']], ['signed_at ASC']) as $s) {
            $out[(int) $s['users_id']] = $s;
        }
        return $out;
    }

    /** Assinatura eletrônica do usuário logado sobre a revisão enviada. */
    public static function sign(int $initiativeId): string
    {
        $init = Initiative::load($initiativeId);
        $c = self::forInitiative($initiativeId);
        if (!$c || $c['status'] !== 'issued') {
            throw new RuleException('O TAP precisa ser enviado antes de ser assinado.');
        }
        $me = (int) Session::getLoginUserID();
        $signers = self::signers($init, $c);
        if (!isset($signers[$me])) {
            throw RuleException::denied('Você não consta entre os signatários deste termo.');
        }
        if (isset(self::signatures($c)[$me])) {
            throw new RuleException('Você já assinou esta revisão do termo.');
        }
        $doc = self::docHash($c);
        $now = Db::now();
        $hash = hash('sha256', $doc . '|' . $me . '|' . $now . '|' . $c['id']);
        Db::insert('signatures', ['initiatives_id' => $initiativeId, 'charters_id' => (int) $c['id'], 'revision' => (int) $c['revision'], 'users_id' => $me, 'role_label' => $signers[$me],
            'signed_at' => $now, 'ip' => mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null, 'doc_hash' => $doc, 'sign_hash' => $hash]);
        try {
            self::syncDoc($initiativeId);
        } catch (\Throwable $ex) {
        }
        Audit::log('Initiative', $initiativeId, 'charter_sign', ['revision' => (int) $c['revision'], 'papel' => $signers[$me]], 1, (int) $init['entities_id']);
        return 'TAP assinado digitalmente em ' . Menu::datetime($now) . '.';
    }
}
