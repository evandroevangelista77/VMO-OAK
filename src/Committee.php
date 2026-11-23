<?php

namespace GlpiPlugin\Vmo;

use Session;

/**
 * Governança do Comitê Estratégico: checkpoints quinzenais, ata de decisões, farol calculado e
 * limite de iniciativas em execução por dono executivo (regras da planilha do Portfólio Estratégico).
 */
class Committee
{
    public const FAROL = ['green' => ['Saudável', '#22c55e'], 'yellow' => ['Atenção', '#f59e0b'], 'red' => ['Crítico', '#ef4444'], 'done' => ['Concluída', '#64748b']];
    public const DECISIONS = ['enter' => 'Aprovar entrada', 'follow' => 'Seguir', 'adjust' => 'Ajustar', 'reinforce' => 'Reforçar', 'reprioritize' => 'Repriorizar', 'close' => 'Encerrar', 'refuse' => 'Recusar'];
    public const SIGNALS = ['green' => 'Saudável', 'yellow' => 'Atenção', 'red' => 'Crítico'];

    /** Dono executivo de uma iniciativa: o cadastrado no catálogo "Donos executivos" ou, na falta, o patrocinador (usuário). @return array{0:string,1:string,2:int} [chave, nome, id do usuário ou 0] */
    public static function owner(array $r): array
    {
        if ((int) ($r['catalogs_id_owner'] ?? 0) > 0) {
            $c = Db::row('catalogs', (int) $r['catalogs_id_owner']);
            if ($c) {
                return ['c' . (int) $c['id'], (string) $c['name'], 0];
            }
        }
        if ((int) ($r['users_id_sponsor'] ?? 0) > 0) {
            return ['u' . (int) $r['users_id_sponsor'], Db::userName((int) $r['users_id_sponsor']), (int) $r['users_id_sponsor']];
        }
        return ['0', 'Sem dono executivo', 0];
    }

    public static function wipLimit(): int
    {
        return max(1, (int) Settings::get('wip_limit', '3'));
    }

    // ---------- checkpoints ----------

    public static function checkpoints(int $initiativeId): array
    {
        return Db::rows('checkpoints', ['initiatives_id' => $initiativeId], ['checkpoint_date DESC', 'id DESC']);
    }

    public static function addCheckpoint(int $initiativeId, array $in): int
    {
        global $DB;
        $init = Initiative::load($initiativeId);
        if (!Access::canUpdate() || !Access::canSeeInitiative($init)) {
            throw RuleException::denied();
        }
        $date = Db::date($in['checkpoint_date'] ?? '') ?: Db::today();
        $pct = Calc::parseNumber((string) ($in['progress'] ?? ''));
        if ($pct !== null && ($pct < 0 || $pct > 100)) {
            throw new RuleException('O % de avanço deve estar entre 0 e 100.');
        }
        $block = Db::text($in['blocker'] ?? '', 4000);
        $id = Db::insert('checkpoints', [
            'initiatives_id' => $initiativeId, 'checkpoint_date' => $date, 'users_id' => (int) Session::getLoginUserID(), 'progress' => $pct,
            'farol_declared' => isset(self::SIGNALS[$in['farol_declared'] ?? '']) ? $in['farol_declared'] : null,
            'advanced' => Db::text($in['advanced'] ?? '', 4000) ?: null, 'blocker' => $block ?: null, 'has_block' => empty($in['has_block']) ? 0 : 1,
            'action_needed' => Db::text($in['action_needed'] ?? '', 4000) ?: null, 'needs_committee' => empty($in['needs_committee']) ? 0 : 1, 'date_creation' => Db::now(),
        ]);
        $DB->update(Initiative::getTable(), ['last_update_at' => Db::now()], ['id' => $initiativeId]);
        Audit::log('Checkpoint', $id, 'create', ['data' => $date, 'farol' => $in['farol_declared'] ?? null], 0, (int) $init['entities_id']);
        return $id;
    }

    // ---------- farol calculado (não negociado) ----------

    /**
     * Crítico: sem atualização há mais de 30 dias ou marco atrasado há mais de 15; atenção: mais de 15 dias sem atualização,
     * marco atrasado ou bloqueio aberto; saudável caso contrário. Iniciativa concluída/encerrada aparece como "Concluída".
     *
     * @return array{key:?string,label:string,color:string,days:?int,delay:int,block:bool}
     */
    public static function farol(array $init, ?array $lastCheckpoint = null): array
    {
        $none = ['key' => null, 'label' => '—', 'color' => '#94a3b8', 'days' => null, 'delay' => 0, 'block' => false];
        if (in_array($init['status'], ['delivered', 'measuring', 'closed'], true)) {
            return ['key' => 'done', 'label' => 'Concluída', 'color' => self::FAROL['done'][1]] + $none;
        }
        if (!in_array($init['status'], ['approved', 'running', 'suspended'], true)) {
            return $none;
        }
        $lastCheckpoint ??= (self::checkpoints((int) $init['id'])[0] ?? null);
        $ref = $lastCheckpoint['checkpoint_date'] ?? substr((string) ($init['last_update_at'] ?: $init['date_creation']), 0, 10);
        $days = $ref ? max(0, (int) floor((strtotime(Db::today()) - strtotime($ref)) / 86400)) : null;
        $ms = Db::rows('tasks', ['initiatives_id' => (int) $init['id'], 'is_deleted' => 0, 'is_milestone' => 1, 'status' => ['todo', 'doing']], ['planned_end ASC'], 1)[0] ?? null;
        $delay = $ms && $ms['planned_end'] ? max(0, (int) floor((strtotime(Db::today()) - strtotime($ms['planned_end'])) / 86400)) : 0;
        $block = !empty($lastCheckpoint['has_block']);
        $key = ($days ?? 0) > 30 || $delay > 15 ? 'red' : (($days ?? 0) > 15 || $delay > 0 || $block ? 'yellow' : 'green');
        return ['key' => $key, 'label' => self::FAROL[$key][0], 'color' => self::FAROL[$key][1], 'days' => $days, 'delay' => $delay, 'block' => $block];
    }

    // ---------- ata de decisões ----------

    public static function addDecision(array $in): int
    {
        if (!Access::canUpdate()) {
            throw RuleException::denied();
        }
        $iid = max(0, (int) ($in['initiatives_id'] ?? 0));
        $entity = 0;
        if ($iid > 0) {
            $init = Initiative::load($iid);
            if (!Access::canSeeInitiative($init)) {
                throw RuleException::denied();
            }
            $entity = (int) $init['entities_id'];
        }
        $title = Db::text($in['title'] ?? '', 255);
        if ($title === '') {
            throw new RuleException('Informe a decisão ou o tema.');
        }
        $kind = isset(self::DECISIONS[$in['decision_kind'] ?? '']) ? $in['decision_kind'] : null;
        $id = Db::insert('decisions', ['initiatives_id' => $iid, 'title' => $title, 'decision_text' => Db::text($in['decision_text'] ?? '', 8000) ?: null,
            'decision_kind' => $kind, 'committee_date' => Db::date($in['committee_date'] ?? '') ?: Db::today(), 'users_id_owner' => max(0, (int) ($in['users_id_owner'] ?? 0)),
            'due_date' => Db::date($in['due_date'] ?? ''), 'users_id' => (int) Session::getLoginUserID(), 'decided_at' => Db::now()]);
        Audit::log('Decision', $id, 'create', ['titulo' => $title, 'tipo' => $kind], 0, $entity);
        return $id;
    }

    public static function toggleDecision(int $id): void
    {
        global $DB;
        $d = Db::row('decisions', $id) ?? throw new RuleException('Decisão não encontrada.', 404);
        if (!Access::canUpdate()) {
            throw RuleException::denied();
        }
        if ((int) $d['initiatives_id'] > 0 && !Access::canSeeInitiative(Initiative::load((int) $d['initiatives_id']))) {
            throw RuleException::denied();
        }
        $DB->update(Db::t('decisions'), ['is_done' => $d['is_done'] ? 0 : 1], ['id' => $id]);
        Audit::log('Decision', $id, $d['is_done'] ? 'reopen' : 'done', [], 0, 0);
    }

    /** Decisões da ata visíveis ao usuário (as gerais e as de iniciativas que ele vê). */
    public static function decisions(array $visibleIds, bool $onlyOpen = false): array
    {
        $where = ['OR' => [['initiatives_id' => 0], ['initiatives_id' => $visibleIds ?: [-1]]]];
        if ($onlyOpen) {
            $where = ['AND' => [$where, ['is_done' => 0]]];
        }
        return Db::rows('decisions', $where, ['committee_date DESC', 'id DESC'], 200);
    }

    // ---------- atas em anexo ----------

    public const MINUTES_EXT = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'txt', 'png', 'jpg', 'jpeg'];
    public const MINUTES_MAX = 20 * 1024 * 1024;

    private static function minutesDir(): string
    {
        $dir = rtrim(GLPI_VAR_DIR, '/') . '/_plugins/vmo/atas';
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new RuleException('Não foi possível criar a pasta das atas no servidor.');
        }
        return $dir;
    }

    /** Atas do comitê (as do brainstorm e a documentação ficam na iniciativa). */
    public static function minutes(): array
    {
        return Db::rows('minutes', ['kind' => 'committee'] + Access::entityCriteria(Db::t('minutes')), ['meeting_date DESC', 'id DESC'], 100);
    }

    /** Atas do brainstorm anexadas a uma iniciativa. */
    public static function brainstormMinutes(int $initiativeId): array
    {
        return Db::rows('minutes', ['kind' => 'brainstorm', 'initiatives_id' => $initiativeId], ['meeting_date DESC', 'id DESC']);
    }

    /** Documentação anexada a uma iniciativa (especificação, manual, apresentação...), em qualquer situação. */
    public static function docs(int $initiativeId): array
    {
        return Db::rows('minutes', ['kind' => 'doc', 'initiatives_id' => $initiativeId], ['meeting_date DESC', 'id DESC']);
    }

    public static function addMinutes(array $in, array $file): int
    {
        $kind = in_array($in['kind'] ?? '', ['brainstorm', 'doc'], true) ? $in['kind'] : 'committee';
        $iid = $kind !== 'committee' ? max(0, (int) ($in['initiatives_id'] ?? 0)) : 0;
        $init = null;
        if ($kind !== 'committee') {
            // a ata do brainstorm acompanha a iniciativa: quem pode criar ou editar a iniciativa pode anexá-la
            $init = Initiative::load($iid);
            if ((!Access::canUpdate() && !Access::canCreate()) || !Access::canSeeInitiative($init)) {
                throw RuleException::denied();
            }
        } elseif (!Access::canUpdate()) {
            throw RuleException::denied();
        }
        if (empty($file['tmp_name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            throw new RuleException('Escolha o arquivo da ata (falha no envio ou arquivo grande demais).');
        }
        $orig = Db::text(basename((string) $file['name']), 255);
        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        if (!in_array($ext, self::MINUTES_EXT, true)) {
            throw new RuleException('Tipo de arquivo não permitido. Use: ' . implode(', ', self::MINUTES_EXT) . '.');
        }
        if ((int) $file['size'] > self::MINUTES_MAX) {
            throw new RuleException('O arquivo passa de 20 MB.');
        }
        $stored = bin2hex(random_bytes(16)) . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], self::minutesDir() . '/' . $stored)) {
            throw new RuleException('Não foi possível gravar o arquivo da ata.');
        }
        $date = Db::date($in['meeting_date'] ?? '') ?: Db::today();
        $title = Db::text($in['title'] ?? '', 255) ?: ($kind === 'doc' ? pathinfo($orig, PATHINFO_FILENAME) : ($kind === 'brainstorm' ? 'Ata do brainstorm de ' : 'Ata do comitê de ') . date('d/m/Y', strtotime($date)));
        $entity = $init ? (int) $init['entities_id'] : (int) ($_SESSION['glpiactive_entity'] ?? 0);
        $id = Db::insert('minutes', ['entities_id' => $entity, 'kind' => $kind, 'initiatives_id' => $iid, 'meeting_date' => $date, 'title' => $title, 'notes' => Db::text($in['notes'] ?? '', 4000) ?: null, 'section' => $kind === 'doc' && isset(Phase::SECTIONS[$in['section'] ?? '']) ? $in['section'] : null, 'original_name' => $orig,
            'stored_name' => $stored, 'mime' => Db::text((string) ($file['type'] ?? ''), 120) ?: null, 'size' => (int) $file['size'], 'users_id' => (int) Session::getLoginUserID(), 'date_creation' => Db::now()]);
        Audit::log('Minutes', $id, 'create', ['titulo' => $title, 'arquivo' => $orig], 0, $entity);
        return $id;
    }

    /**
     * Guarda um arquivo gerado pelo plugin (PDF do TAP, do status report) na Documentação da iniciativa. Se $existing apontar para um
     * documento já gerado, troca o conteúdo dele (mesmo registro). Quem chama já conferiu o direito de editar a iniciativa.
     */
    public static function storeGenerated(array $init, string $section, string $title, string $fileName, string $bytes, int $existing = 0): int
    {
        global $DB;
        $dir = self::minutesDir();
        $cur = $existing ? Db::row('minutes', $existing) : null;
        if ($cur && ((int) $cur['initiatives_id'] !== (int) $init['id'] || $cur['kind'] !== 'doc')) {
            $cur = null;
        }
        $stored = bin2hex(random_bytes(16)) . '.pdf';
        if (file_put_contents($dir . '/' . $stored, $bytes) === false) {
            throw new RuleException('Não foi possível gravar o PDF na documentação.');
        }
        $row = ['title' => Db::text($title, 255), 'original_name' => Db::text($fileName, 255), 'stored_name' => $stored, 'mime' => 'application/pdf', 'size' => strlen($bytes), 'meeting_date' => Db::today()];
        if ($cur) {
            @unlink($dir . '/' . basename($cur['stored_name']));
            $DB->update(Db::t('minutes'), $row, ['id' => (int) $cur['id']]);
            return (int) $cur['id'];
        }
        return Db::insert('minutes', $row + ['entities_id' => (int) $init['entities_id'], 'kind' => 'doc', 'initiatives_id' => (int) $init['id'], 'section' => $section, 'users_id' => (int) Session::getLoginUserID(), 'date_creation' => Db::now()]);
    }

    /** Linha da ata + caminho do arquivo, após conferir o acesso à entidade. */
    public static function minutesFile(int $id): array
    {
        $m = Db::row('minutes', $id) ?? throw new RuleException('Ata não encontrada.', 404);
        if (!Session::haveAccessToEntity((int) $m['entities_id'])) {
            throw new RuleException('Ata não encontrada.', 404);
        }
        if (in_array($m['kind'] ?? '', ['brainstorm', 'doc'], true) && (int) $m['initiatives_id'] > 0) {
            try {
                $init = Initiative::load((int) $m['initiatives_id']);
            } catch (\Throwable) {
                throw new RuleException('Ata não encontrada.', 404);
            }
            if (!Access::canSeeInitiative($init)) {
                throw new RuleException('Ata não encontrada.', 404);
            }
        }
        $path = self::minutesDir() . '/' . basename($m['stored_name']);
        if (!is_file($path)) {
            throw new RuleException('O arquivo desta ata não está mais no servidor.', 404);
        }
        return [$m, $path];
    }

    public static function deleteMinutes(int $id): void
    {
        global $DB;
        $m = Db::row('minutes', $id) ?? throw new RuleException('Ata não encontrada.', 404);
        if (!Session::haveAccessToEntity((int) $m['entities_id']) || (!Access::canConfigure() && (int) $m['users_id'] !== (int) Session::getLoginUserID())) {
            throw RuleException::denied();
        }
        @unlink(self::minutesDir() . '/' . basename($m['stored_name']));
        $DB->delete(Db::t('minutes'), ['id' => $id]);
        Audit::log('Minutes', $id, 'delete', ['titulo' => $m['title']], 0, (int) $m['entities_id']);
    }
}
