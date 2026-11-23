<?php

namespace GlpiPlugin\Vmo;

use Session;

/**
 * Conexão entre iniciativas (plugins) no roadmap: "A alimenta B" ou "A e B trocam dados", com um rótulo curto e o detalhe do que trafega.
 * É diferente da dependência (Dependency), que é cronograma término→início: a conexão mostra integração, e as duas aparecem no roadmap.
 */
class Connection
{
    public static function getTable($classname = null)
    {
        return Db::t('connections');
    }

    /** Conexões entre iniciativas que o usuário pode ver. */
    public static function all(bool $onlyActive = true): array
    {
        $out = [];
        foreach (Db::rows('connections', $onlyActive ? ['is_active' => 1] : [], ['id ASC']) as $c) {
            $a = Db::row('initiatives', (int) $c['from_id']);
            $b = Db::row('initiatives', (int) $c['to_id']);
            if ($a && $b && empty($a['is_deleted']) && empty($b['is_deleted']) && Access::canSeeInitiative($a) && Access::canSeeInitiative($b)) {
                $out[] = $c + ['from_name' => Roadmap::label($a['name']), 'to_name' => Roadmap::label($b['name']), 'from_code' => $a['code'], 'to_code' => $b['code']];
            }
        }
        return $out;
    }

    public static function add(int $from, int $to, string $label, string $detail, bool $twoWay): int
    {
        global $DB;
        if (!Access::canUpdate()) {
            throw RuleException::denied();
        }
        $a = Db::row('initiatives', $from);
        $b = Db::row('initiatives', $to);
        if (!$a || !$b || !Access::canSeeInitiative($a) || !Access::canSeeInitiative($b)) {
            throw new RuleException('As duas iniciativas precisam existir e estar acessíveis a você.', 404);
        }
        if ($from === $to) {
            throw new RuleException('Uma iniciativa não se conecta a ela mesma.');
        }
        $label = Db::text($label, 120);
        if ($label === '') {
            throw new RuleException('Informe o que a conexão faz (ex.: "venda ganha vira contrato").');
        }
        if (countElementsInTable(self::getTable(), ['from_id' => $from, 'to_id' => $to, 'label' => $label])) {
            throw new RuleException('Essa conexão já existe.');
        }
        $DB->insert(self::getTable(), ['entities_id' => (int) $a['entities_id'], 'from_id' => $from, 'to_id' => $to, 'label' => $label, 'detail' => Db::text($detail, 500),
            'two_way' => $twoWay ? 1 : 0, 'users_id' => (int) Session::getLoginUserID(), 'date_creation' => Db::now()]);
        $id = (int) $DB->insertId();
        Audit::log('Connection', $id, 'create', ['de' => $a['code'], 'para' => $b['code'], 'rotulo' => $label], 0, (int) $a['entities_id']);
        return $id;
    }

    public static function toggle(int $id, bool $on): void
    {
        global $DB;
        if (!Access::canUpdate()) {
            throw RuleException::denied();
        }
        $DB->update(self::getTable(), ['is_active' => $on ? 1 : 0], ['id' => $id]);
    }

    public static function remove(int $id): void
    {
        global $DB;
        if (!Access::canUpdate()) {
            throw RuleException::denied();
        }
        $c = Db::row('connections', $id);
        if ($c) {
            $DB->delete(self::getTable(), ['id' => $id]);
            Audit::log('Connection', $id, 'delete', ['de' => $c['from_id'], 'para' => $c['to_id'], 'rotulo' => $c['label']], 0, (int) $c['entities_id']);
        }
    }
}
