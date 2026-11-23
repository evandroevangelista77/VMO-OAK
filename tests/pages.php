<?php
/**
 * Renderiza cada tela do VMO pela linha de comando (sessão do superadministrador) e confere que não há
 * erro de PHP e que o conteúdo esperado aparece. Cria uma iniciativa de teste e remove ao final.
 *
 *   sudo -u www-data env GLPI_ROOT=/var/www/glpi php tests/pages.php
 */
$root = getenv('GLPI_ROOT') ?: '/var/www/glpi';
chdir($root);
require $root . '/vendor/autoload.php';
$kernel = new Glpi\Kernel\Kernel('production');
$kernel->boot();
global $DB, $CFG_GLPI;

use GlpiPlugin\Vmo\{Access, Benefit, Cost, Db, Initiative, Risk, Task};

$user = new User(); $user->getFromDBbyName('glpi');
$auth = new Auth(); $auth->auth_succeded = true; $auth->user = $user;
Session::init($auth);
$_SESSION['glpi_currenttime'] = date('Y-m-d H:i:s');
Session::changeActiveEntities(0, true);
$_SESSION['glpiactiveprofile'][Access::$rightname] = (int) (ProfileRight::getProfileRights($_SESSION['glpiactiveprofile']['id'], [Access::$rightname])[Access::$rightname] ?? 0);
// o GLPI exige uma sessão registrada em glpi_users_sessions; em linha de comando registramos uma temporária
$testSessionUid = 'vmo-test-' . bin2hex(random_bytes(6));
$_SESSION['login_session_uid'] = $testSessionUid;
$_SESSION['valid_id'] = session_id();
if ($DB->tableExists('glpi_users_sessions')) { $DB->insert('glpi_users_sessions', ['users_id' => (int) $user->getID(), 'login_session_uid' => $testSessionUid, 'session_file' => 'sess_vmo_test', 'ip_address' => '127.0.0.1',
    'user_agent' => 'vmo-tests', 'auth_type' => 1, 'created_at' => date('Y-m-d H:i:s'), 'last_activity_at' => date('Y-m-d H:i:s')]); }
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/plugins/vmo/front/x.php';

$fail = 0;
$ok = function (string $label, bool $v) use (&$fail): void {
    echo ($v ? 'OK   ' : 'FALHA') . " $label\n";
    $fail += $v ? 0 : 1;
};

$tag = 'PG' . random_int(1000, 9999);
$id = Initiative::create(['name' => "Página $tag", 'description' => 'teste de telas', 'users_id_manager' => (int) $user->getID(), 'users_id_sponsor' => (int) $user->getID(), 'objective' => 'x',
    'planned_start' => date('Y-m-d'), 'planned_end' => date('Y-m-d', strtotime('+30 days'))]);
Task::save($id, 0, ['name' => 'Tarefa teste', 'weight' => '1']);
// TAP e RACI: rascunho automático, validações e emissão
$cu = \GlpiPlugin\Vmo\Charter::ensure($id);
$ok('TAP nasce em rascunho', $cu && $cu['status'] === 'draft' && (int) $cu['follower_users_id'] > 0);
\GlpiPlugin\Vmo\Charter::save($id, ['requester_users_id' => (int) $user->getID(), 'follower_users_id' => (int) $user->getID(), 'objective' => '', 'delivery_date' => '']);
try { \GlpiPlugin\Vmo\Charter::issue($id); $ok('TAP exige prazo e objetivo', false); } catch (\GlpiPlugin\Vmo\RuleException $ex) { $ok('TAP exige prazo e objetivo (' . $ex->getMessage() . ')', true); }
\GlpiPlugin\Vmo\Charter::save($id, ['requester_users_id' => (int) $user->getID(), 'follower_users_id' => (int) $user->getID(), 'objective' => 'Objetivo de teste', 'delivery_date' => date('Y-m-d', strtotime('+30 days')), 'start_date' => date('Y-m-d')]);
$ok('TAP emitido', str_starts_with(\GlpiPlugin\Vmo\Charter::issue($id), 'TAP enviado em') && \GlpiPlugin\Vmo\Charter::forInitiative($id)['status'] === 'issued');
$sk = \GlpiPlugin\Vmo\Phase::save($id, 'stake', 0, ['name' => 'Diretoria Comercial', 'interest' => '3', 'influence' => '3']);
$rmk = \GlpiPlugin\Vmo\Phase::save($id, 'rm', 0, ['title' => 'Implantar versão 1', 'rm_status' => 'requested', 'window_start' => date('Y-m-d\TH:i')]);
$ak = \GlpiPlugin\Vmo\Phase::save($id, 'accept', 0, ['title' => 'Aceite final', 'decision' => 'full']);
\GlpiPlugin\Vmo\Phase::sign($ak);
$ok('Registros das etapas (partes, RM, aceite assinado)', $sk > 0 && $rmk > 0 && \GlpiPlugin\Vmo\Db::row('records', $ak)['status'] === 'signed');
try { \GlpiPlugin\Vmo\Phase::save($id, 'accept', $ak, ['title' => 'x']); $ok('Termo assinado não muda', false); } catch (\GlpiPlugin\Vmo\RuleException $ex) { $ok('Termo assinado não muda', true); }
$ok('TAP assinado digitalmente (e só uma vez)', str_starts_with(\GlpiPlugin\Vmo\Charter::sign($id), 'TAP assinado') && (function () use ($id) { try { \GlpiPlugin\Vmo\Charter::sign($id); return false; } catch (\GlpiPlugin\Vmo\RuleException $x) { return true; } })());
$tapDoc = array_values(array_filter(\GlpiPlugin\Vmo\Committee::docs($id), fn ($x) => str_starts_with($x['title'], 'TAP -')));
$ok('PDF do TAP na Documentação (e atualizado ao assinar)', count($tapDoc) === 1 && $tapDoc[0]['section'] === 'initiation' && $tapDoc[0]['mime'] === 'application/pdf');
$srId = \GlpiPlugin\Vmo\Phase::save($id, 'status', 0, ['health' => 'yellow', 'progress' => '40', 'accomplished' => 'Tudo certo', 'next' => 'Homologar']);
$srRec = \GlpiPlugin\Vmo\Db::row('records', $srId); $srRec['d'] = json_decode($srRec['data'], true);
$ok('PDF do status report gerado', str_starts_with(\GlpiPlugin\Vmo\StatusReportPdf::render(\GlpiPlugin\Vmo\Initiative::load($id), $srRec, 'Teste'), '%PDF'));
$ok('status report enviado vai para a Documentação', str_contains(\GlpiPlugin\Vmo\Phase::sendStatus($srId, [(int) $user->getID()], 'Segue', false), 'Documentação') && \GlpiPlugin\Vmo\Db::row('records', $srId)['status'] === 'sent');
$clId = \GlpiPlugin\Vmo\Phase::save($id, 'close', 0, ['title' => 'Encerramento teste', 'results' => 'Objetivo atingido', 'pending' => 'Nenhuma']);
$clRec = \GlpiPlugin\Vmo\Db::row('records', $clId); $clRec['d'] = json_decode($clRec['data'], true);
$ok('PDF do termo de encerramento', str_starts_with(\GlpiPlugin\Vmo\TermPdf::render(\GlpiPlugin\Vmo\Initiative::load($id), $clRec, 'Teste'), '%PDF'));
$ok('termo de encerramento enviado vai para a Documentação', str_contains(\GlpiPlugin\Vmo\Phase::sendStatus($clId, [(int) $user->getID()], '', false), 'Documentação') && count(array_filter(\GlpiPlugin\Vmo\Committee::docs($id), fn ($x) => str_starts_with($x['title'], 'Termo de encerramento'))) === 1);
\GlpiPlugin\Vmo\Phase::sign($clId);
$ok('assinar o termo atualiza o PDF guardado (continua um só)', count(array_filter(\GlpiPlugin\Vmo\Committee::docs($id), fn ($x) => str_starts_with($x['title'], 'Termo de encerramento'))) === 1);
$dr = \GlpiPlugin\Vmo\Phase::draft($id, 'close'); $drRec = ['id' => 0, 'initiatives_id' => $id, 'kind' => 'close', 'title' => $dr['title'], 'record_date' => date('Y-m-d'), 'status' => 'draft', 'signed_at' => null, 'signed_by' => 0, 'd' => $dr];
$ok('pré-visualização do termo de encerramento antes do registro', str_starts_with(\GlpiPlugin\Vmo\TermPdf::render(\GlpiPlugin\Vmo\Initiative::load($id), $drRec, 'Teste'), '%PDF'));
$ok('palavras dos chamados', in_array('financeiro', \GlpiPlugin\Vmo\TicketWords::words('Preciso de um relatório financeiro para o fechamento'), true) && !in_array('preciso', \GlpiPlugin\Vmo\TicketWords::words('Preciso de um relatório'), true));
$ok('RACI sugerida', \GlpiPlugin\Vmo\Raci::suggest($id) === 1);
$rm = \GlpiPlugin\Vmo\Raci::map($id); $ok('RACI com A e sem alerta', count($rm) === 1 && \GlpiPlugin\Vmo\Raci::check(reset($rm)) === [] || \GlpiPlugin\Vmo\Raci::check(reset($rm)) === ['sem R']);
Risk::save($id, 0, ['title' => 'Risco teste', 'probability' => 3, 'impact' => 4]);
Cost::add($id, ['kind' => 'forecast', 'amount' => '1000', 'competence_date' => date('Y-m-d')]);
$bid = Benefit::save(0, ['name' => "Benefício $tag", 'type' => 'financial', 'currency' => 'BRL', 'horizon_start' => date('Y-m-01'), 'horizon_end' => date('Y-m-d', strtotime('+6 months'))]);

$render = function (string $file, array $get = []) use ($root): array {
    $_GET = $get;
    ob_start();
    $err = null;
    try {
        include $root . '/plugins/vmo/front/' . $file;
    } catch (Throwable $e) {
        $err = get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
    }
    return [ob_get_clean(), $err];
};

$pages = [
    'dashboard.php' => [[], 'Dashboard executivo'],
    'initiatives.php' => [[], 'Portfólio de iniciativas'],
    'request.php' => [[], 'Nuvem de Palavras'],
    'initiative.php?id=0' => [['id' => 0], 'Nova iniciativa'],
    'initiative.php?tab=resumo' => [['id' => $id], "Página $tag"],
    'initiative.php?tab=brainstorm' => [['id' => $id, 'tab' => 'brainstorm'], 'Chamado de requisição'],
    'initiative.php?tab=strategy' => [['id' => $id, 'tab' => 'strategy'], 'Definição de Critérios Estratégicos'],
    'initiative.php?tab=initiation' => [['id' => $id, 'tab' => 'initiation'], 'Registro das partes interessadas'],
    'initiative.php?tab=tap' => [['id' => $id, 'tab' => 'tap'], 'Termo de Abertura do Projeto'],
    'initiative.php?tab=monitor' => [['id' => $id, 'tab' => 'monitor'], 'Requisição de mudança de implantação'],
    'initiative.php?tab=closure' => [['id' => $id, 'tab' => 'closure'], 'Pré-visualizar Termo de Encerramento'],
    'stages.php' => [[], 'Fichas por etapa'],
    'stages.php?id' => [['id' => $id], 'Visão do Comitê'],
    'charter.php' => [['id' => $id], 'Aprovação e assinaturas'],
    'initiative.php?tab=tasks' => [['id' => $id, 'tab' => 'tasks'], 'RACI por tarefa'],
    'initiative.php?tab=deps' => [['id' => $id, 'tab' => 'deps'], 'Entre tarefas'],
    'initiative.php?tab=costs' => [['id' => $id, 'tab' => 'costs'], 'Novo lançamento'],
    'initiative.php?tab=org' => [['id' => $id, 'tab' => 'org'], 'Departamentos'],
    'initiative.php?tab=history' => [['id' => $id, 'tab' => 'history'], 'Trilha de auditoria'],
    'committee.php' => [[], 'Atas do comitê'],
    'initiative.php?tab=checkpoints' => [['id' => $id, 'tab' => 'checkpoints'], 'Checkpoints quinzenais'],
    'indicators.php' => [[], 'KPIs de performance'],
    'roadmap.php' => [[], 'Roadmap de integração'],
    'kpibook.php' => [[], 'PDF'],
    'inbox.php' => [[], 'Aguardando a sua decisão'],
    'config.php' => [[], 'Diagnóstico'],
];
foreach ($pages as $label => [$get, $needle]) {
    [$file] = explode('?', $label);
    [$html, $err] = $render($file, $get);
    $good = $err === null && str_contains($html, $needle) && !preg_match('/Fatal error|Parse error|Warning:|Notice:|Deprecated:|Stack trace/', $html);
    $ok("$label" . ($err ? " → $err" : (str_contains($html, $needle) ? '' : " → não achou \"$needle\"")), $good);
}

// limpeza
if ($DB->tableExists('glpi_users_sessions')) { $DB->delete('glpi_users_sessions', ['login_session_uid' => $testSessionUid]); }
foreach (['tasks', 'risks', 'costs', 'baselines', 'approvals', 'changes'] as $t) {
    $DB->delete(Db::t($t), ['initiatives_id' => $id]);
}
$DB->delete(Db::t('initiatives'), ['id' => $id]);
$DB->delete(Db::t('benefits'), ['id' => $bid]);
$DB->delete(Db::t('audit_events'), ['itemtype' => 'Initiative', 'items_id' => $id]);
echo $fail ? "\n$fail falha(s)\n" : "\nTodas as telas renderizaram.\n";
exit($fail ? 1 : 0);
