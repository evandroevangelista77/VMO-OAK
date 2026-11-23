<?php
/**
 * Integração com o Escalations: detecta as situações críticas, envia e confere o resolve.
 *   sudo -u www-data env GLPI_ROOT=/var/www/glpi php plugins/vmo/tests/escalation.php
 */
$root = getenv('GLPI_ROOT') ?: '/var/www/glpi';
chdir($root);
require $root . '/vendor/autoload.php';
$kernel = new Glpi\Kernel\Kernel('production');
$kernel->boot();
global $DB;

use GlpiPlugin\Vmo\{Db, Escalation, Settings};

// ---- cenário controlado: cria dados de teste, confere que cada situação é detectada e remove tudo
use GlpiPlugin\Vmo\{Access, Initiative, Risk, Task};
$user = new User(); $user->getFromDBbyName('glpi');
$auth = new Auth(); $auth->auth_succeded = true; $auth->user = $user;
Session::init($auth);
Session::changeActiveEntities(0, true);
$_SESSION['glpiactiveprofile'][Access::$rightname] = (int) (ProfileRight::getProfileRights($_SESSION['glpiactiveprofile']['id'], [Access::$rightname])[Access::$rightname] ?? 0);
$me = (int) $user->getID();
$old = date('Y-m-d H:i:s', strtotime('-40 days'));
$i1 = Initiative::create(['name' => 'Teste escalonamento A', 'users_id_manager' => $me, 'users_id_sponsor' => $me, 'objective' => 'x', 'planned_start' => date('Y-m-d', strtotime('-60 days')), 'planned_end' => date('Y-m-d', strtotime('-10 days'))]);
$DB->update(Db::t('initiatives'), ['status' => 'running', 'actual_start' => date('Y-m-d', strtotime('-50 days')), 'last_update_at' => $old], ['id' => $i1]);
$DB->insert(Db::t('checkpoints'), ['initiatives_id' => $i1, 'checkpoint_date' => date('Y-m-d', strtotime('-40 days')), 'users_id' => $me, 'has_block' => 1, 'needs_committee' => 1, 'blocker' => 'teste', 'date_creation' => Db::now()]);
Task::save($i1, 0, ['name' => 'Marco de teste', 'is_milestone' => 1, 'planned_start' => date('Y-m-d', strtotime('-40 days')), 'planned_end' => date('Y-m-d', strtotime('-20 days'))]);
Risk::save($i1, 0, ['title' => 'Risco de teste', 'probability' => 5, 'impact' => 5]);
$i2 = Initiative::create(['name' => 'Teste escalonamento B', 'users_id_manager' => $me, 'users_id_sponsor' => $me, 'objective' => 'x']);
$DB->update(Db::t('initiatives'), ['status' => 'review', 'last_update_at' => $old], ['id' => $i2]);
$found = [];
foreach (Escalation::detect() as $it) {
    $found[$it['type'] . '|' . $it['key']] = $it;
}
foreach (['iniciativa_sem_atualizacao|ini:' . $i1, 'iniciativa_prazo_critico|ini:' . $i1, 'bloqueio_aberto|ini:' . $i1, 'tap_nao_enviado|ini:' . $i1, 'proposta_parada|ini:' . $i2] as $k) {
    echo (isset($found[$k]) ? 'OK   ' : 'FALHA') . " detecta $k\n";
}
$hasMarco = count(array_filter(array_keys($found), fn ($k) => str_starts_with($k, 'marco_atrasado|task:')));
$hasRisco = count(array_filter(array_keys($found), fn ($k) => str_starts_with($k, 'risco_critico|risk:')));
echo ($hasMarco ? 'OK   ' : 'FALHA') . " detecta marco_atrasado\n" . ($hasRisco ? 'OK   ' : 'FALHA') . " detecta risco_critico\n";
echo (($found['bloqueio_aberto|ini:' . $i1]['severity'] ?? 0) === 4 ? 'OK   ' : 'FALHA') . " pedido de decisão do comitê = gravidade 4\n";
foreach ([$i1, $i2] as $iid) {
    foreach (['tasks', 'checkpoints', 'risks', 'charters', 'records', 'audit_events'] as $tb) {
        if ($DB->tableExists(Db::t($tb)) && $DB->fieldExists(Db::t($tb), 'initiatives_id')) { $DB->delete(Db::t($tb), ['initiatives_id' => $iid]); }
    }
    $DB->delete(Db::t('initiatives'), ['id' => $iid]);
}

$types = [];
foreach (Escalation::detect() as $it) {
    $types[$it['type']] = ($types[$it['type']] ?? 0) + 1;
    foreach (['type', 'key', 'title', 'severity', 'url'] as $f) {
        if (($it[$f] ?? '') === '') { echo "FALHA item sem $f: " . json_encode($it) . "\n"; }
    }
}
ksort($types);
echo "Situações detectadas: " . (json_encode($types, JSON_UNESCAPED_UNICODE) ?: '{}') . "\n";
echo "Escalations ativo: " . (Escalation::enabled() ? 'sim' : 'não') . "\n";
if (!Escalation::enabled()) { exit(0); }
Escalation::registerTypes();
$reg = countElementsInTable('glpi_plugin_escalations_sources', ['plugin_key' => 'vmo']);
echo ($reg >= count(Escalation::TYPES) ? 'OK   ' : 'FALHA') . " tipos registrados no Escalations ($reg)\n";
[$new, $res] = Escalation::sync();
echo "OK    1ª rodada: $new novo(s), $res resolvido(s)\n";
[$new2, $res2] = Escalation::sync();
echo ($new2 === 0 ? 'OK   ' : 'FALHA') . " 2ª rodada não repete ($new2 novo(s), $res2 resolvido(s))\n";
$open = json_decode(Settings::get('esc_open', '{}'), true) ?: [];
echo "Itens em aberto no controle do VMO: " . count($open) . "\n";
$rows = countElementsInTable('glpi_plugin_escalations_escalations', ['source' => 'plugin', 'state' => ['open', 'acked']]);
echo "Escalonamentos abertos (origem plugin): $rows\n";
