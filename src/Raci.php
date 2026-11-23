<?php

namespace GlpiPlugin\Vmo;

/**
 * RACI do cronograma: para cada tarefa, quem é R (executa), A (responde pelo resultado, um só), C (consultado) e I (informado).
 */
class Raci
{
    public const ROLES = [
        'R' => ['Responsável (executa)', '#3b82f6'],
        'A' => ['Aprovador (responde pelo resultado)', '#ef4444'],
        'C' => ['Consultado', '#f59e0b'],
        'I' => ['Informado', '#94a3b8'],
    ];

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_vmo_raci';
    }

    /** @return array<int,array<int,string>> tarefa => [usuário => papel] */
    public static function map(int $initiativeId): array
    {
        $m = [];
        foreach (Db::rows('raci', ['initiatives_id' => $initiativeId]) as $r) {
            $m[(int) $r['tasks_id']][(int) $r['users_id']] = $r['role'];
        }
        return $m;
    }

    /** Alertas por tarefa: sem A, mais de um A, sem R. */
    public static function check(array $roles): array
    {
        $c = array_count_values($roles);
        $w = [];
        if (empty($c['A'])) {
            $w[] = 'sem A';
        } elseif ($c['A'] > 1) {
            $w[] = 'mais de um A';
        }
        if (empty($c['R'])) {
            $w[] = 'sem R';
        }
        return $w;
    }

    /** Grava a matriz enviada: $matrix[tarefa][usuário] = R|A|C|I|''. */
    public static function save(int $initiativeId, array $matrix): void
    {
        global $DB;
        $init = Initiative::load($initiativeId, true);
        if (!Access::canEditInitiative($init)) {
            throw RuleException::denied();
        }
        $tasks = Task::forInitiative($initiativeId);
        foreach ($matrix as $tid => $cols) {
            $tid = (int) $tid;
            if (!isset($tasks[$tid]) || !is_array($cols)) {
                continue;
            }
            foreach ($cols as $uid => $role) {
                $uid = (int) $uid;
                $role = strtoupper((string) $role);
                if ($uid <= 0) {
                    continue;
                }
                $DB->delete(self::getTable(), ['tasks_id' => $tid, 'users_id' => $uid]);
                if (isset(self::ROLES[$role])) {
                    $DB->insert(self::getTable(), ['initiatives_id' => $initiativeId, 'tasks_id' => $tid, 'users_id' => $uid, 'role' => $role, 'date_creation' => Db::now()]);
                }
            }
        }
        Audit::log('Initiative', $initiativeId, 'raci_save', [], 1, (int) $init['entities_id']);
    }

    /** Preenche o que está vazio: responsável da tarefa = R e gestor da iniciativa = A (o A vence se for a mesma pessoa). */
    public static function suggest(int $initiativeId): int
    {
        global $DB;
        $init = Initiative::load($initiativeId, true);
        if (!Access::canEditInitiative($init)) {
            throw RuleException::denied();
        }
        $map = self::map($initiativeId);
        $mgr = (int) $init['users_id_manager'] ?: (int) $init['users_id_sponsor'];
        $n = 0;
        foreach (Task::forInitiative($initiativeId) as $tid => $t) {
            if (!empty($map[$tid])) {
                continue;
            }
            $resp = (int) $t['users_id_responsible'];
            foreach ([[$resp, 'R'], [$mgr, 'A']] as [$u, $role]) {
                if ($u > 0) {
                    $DB->delete(self::getTable(), ['tasks_id' => $tid, 'users_id' => $u]);
                    $DB->insert(self::getTable(), ['initiatives_id' => $initiativeId, 'tasks_id' => $tid, 'users_id' => $u, 'role' => $role, 'date_creation' => Db::now()]);
                }
            }
            $n++;
        }
        return $n;
    }
}
