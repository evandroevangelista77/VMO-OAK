<?php

/**
 * Configuração do roadmap: quais iniciativas (plugins) aparecem e quais conexões de integração são mostradas.
 */

use GlpiPlugin\Vmo\Access;
use GlpiPlugin\Vmo\Connection;
use GlpiPlugin\Vmo\Db;
use GlpiPlugin\Vmo\Menu;
use GlpiPlugin\Vmo\Roadmap;
use GlpiPlugin\Vmo\Settings;

Session::checkLoginUser();
if (!Access::canView() || !Access::canConfigure()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}
$e = fn ($v) => Menu::e($v);
$self = Menu::url('/front/roadmapconfig.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $go = $self;
    try {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'show') {
            $shown = array_map('strval', (array) ($_POST['shown'] ?? []));
            $hidden = [];
            foreach (Roadmap::all() as $i) {
                if (!in_array((string) $i['id'], $shown, true)) {
                    $hidden[] = $i['code'];
                }
            }
            Settings::set(['roadmap_hidden' => implode(',', $hidden)]);
            Session::addMessageAfterRedirect('Plugins do roadmap atualizados.', false, INFO);
        } elseif ($action === 'conn_active') {
            $on = array_map('intval', (array) ($_POST['active'] ?? []));
            foreach (Connection::all(false) as $c) {
                Connection::toggle((int) $c['id'], in_array((int) $c['id'], $on, true));
            }
            Session::addMessageAfterRedirect('Conexões atualizadas.', false, INFO);
        } elseif ($action === 'conn_add') {
            Connection::add((int) ($_POST['from'] ?? 0), (int) ($_POST['to'] ?? 0), (string) ($_POST['label'] ?? ''), (string) ($_POST['detail'] ?? ''), !empty($_POST['two_way']));
            Session::addMessageAfterRedirect('Conexão registrada.', false, INFO);
        } elseif ($action === 'conn_del') {
            Connection::remove((int) ($_POST['id'] ?? 0));
            Session::addMessageAfterRedirect('Conexão removida.', false, INFO);
        }
    } catch (Throwable $ex) {
        Session::addMessageAfterRedirect($ex->getMessage(), false, ERROR);
    }
    Html::redirect($go); // fora do try: no GLPI 12 o redirect é uma exceção
}

$all = Roadmap::all();
$hidden = array_filter(array_map(fn ($c) => strtoupper(trim($c)), explode(',', Settings::get('roadmap_hidden'))));
$conns = Connection::all(false);

Menu::header('Configurar roadmap', 'dashboard');
echo Menu::title('Configurar roadmap', 'Escolha quais plugins aparecem no roadmap e quais conexões entre eles são mostradas. As dependências de cronograma vêm da ficha de cada iniciativa.',
    Menu::button('Voltar ao roadmap', Menu::url('/front/roadmap.php'), 'ti-arrow-left', 'btn-outline-secondary'));

// 1. plugins exibidos
echo "<form method='post' action='" . $e($self) . "' class='card mb-3'><input type='hidden' name='action' value='show'><div class='card-header'><h3 class='card-title'>Plugins no roadmap</h3><div class='card-actions small text-secondary'>"
    . (count($all) - count(array_filter($all, fn ($i) => in_array(strtoupper($i['code']), $hidden, true)))) . ' de ' . count($all) . " exibidos</div></div><div class='table-responsive'><table class='table table-vcenter card-table'><thead><tr><th style='width:70px'>Exibir</th><th>Código</th><th>Iniciativa</th><th>Status de entrega</th></tr></thead><tbody>";
foreach ($all as $i) {
    $on = !in_array(strtoupper($i['code']), $hidden, true);
    echo "<tr><td><input class='form-check-input' type='checkbox' name='shown[]' value='" . (int) $i['id'] . "'" . ($on ? ' checked' : '') . "></td><td><code>" . $e($i['code']) . "</code></td><td>" . $e($i['full']) . "</td><td><span class='badge' style='background:" . $e(Roadmap::STAGES[$i['stage']][1]) . ";color:#fff'>" . $e(Roadmap::STAGES[$i['stage']][0]) . '</span></td></tr>';
}
echo "</tbody></table></div><div class='card-footer d-flex justify-content-between'><div class='small text-secondary'>Desmarcar só esconde do roadmap e do painel de entrega: a iniciativa continua existindo no VMO.</div><button class='btn btn-primary'><i class='ti ti-device-floppy me-1'></i>Salvar plugins</button></div>";
Html::closeForm();

// 2. conexões
$byId = array_column($all, null, 'id');
echo "<form method='post' action='" . $e($self) . "' class='card mb-3'><input type='hidden' name='action' value='conn_active'><div class='card-header'><h3 class='card-title'>Conexões</h3><div class='card-actions small text-secondary'>o que cada plugin entrega ao outro</div></div><div class='table-responsive'><table class='table table-vcenter card-table'><thead><tr><th style='width:70px'>Mostrar</th><th>De</th><th></th><th>Para</th><th>O que acontece</th></tr></thead><tbody>";
foreach ($conns as $c) {
    echo "<tr><td><input class='form-check-input' type='checkbox' name='active[]' value='" . (int) $c['id'] . "'" . ($c['is_active'] ? ' checked' : '') . "></td><td><b>" . $e($c['from_name']) . "</b></td><td class='text-center'><i class='ti " . ($c['two_way'] ? 'ti-arrows-left-right' : 'ti-arrow-right') . "'></i></td><td><b>" . $e($c['to_name']) . "</b></td><td>"
        . $e($c['label']) . ($c['detail'] !== '' && $c['detail'] !== null ? "<div class='small text-secondary'>" . $e($c['detail']) . '</div>' : '') . "</td></tr>";
}
if (!$conns) {
    echo "<tr><td colspan='6' class='text-secondary text-center py-3'>Nenhuma conexão registrada. Adicione abaixo.</td></tr>";
}
echo "</tbody></table></div><div class='card-footer'><button class='btn btn-primary'><i class='ti ti-device-floppy me-1'></i>Salvar visibilidade</button></div>";
Html::closeForm();

// remover (formulários separados, um por conexão)
if ($conns) {
    echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>Remover conexão</h3></div><div class='card-body d-flex flex-wrap gap-2'>";
    foreach ($conns as $c) {
        echo "<form method='post' action='" . $e($self) . "'><input type='hidden' name='action' value='conn_del'><input type='hidden' name='id' value='" . (int) $c['id'] . "'><button class='btn btn-sm btn-outline-danger' title='" . $e($c['label']) . "'><i class='ti ti-trash me-1'></i>" . $e($c['from_name'] . ($c['two_way'] ? ' ⇄ ' : ' → ') . $c['to_name']) . '</button>';
        Html::closeForm();
    }
    echo '</div></div>';
}

// 3. nova conexão
$opts = '';
foreach ($all as $i) {
    $opts .= "<option value='" . (int) $i['id'] . "'>" . $e($i['name']) . '</option>';
}
echo "<form method='post' action='" . $e($self) . "' class='card mb-4'><input type='hidden' name='action' value='conn_add'><div class='card-header'><h3 class='card-title'>Nova conexão</h3></div><div class='card-body'><div class='row g-3'>"
    . "<div class='col-md-3'><label class='form-label'>De</label><select class='form-select' name='from' required><option value=''>Escolha</option>$opts</select></div>"
    . "<div class='col-md-3'><label class='form-label'>Para</label><select class='form-select' name='to' required><option value=''>Escolha</option>$opts</select></div>"
    . "<div class='col-md-4'><label class='form-label'>O que acontece (curto)</label><input class='form-control' name='label' maxlength='120' required placeholder='venda ganha vira contrato'></div>"
    . "<div class='col-md-2'><label class='form-check mt-4'><input class='form-check-input' type='checkbox' name='two_way' value='1'><span class='form-check-label'>nos dois sentidos</span></label></div>"
    . "<div class='col-12'><label class='form-label'>Detalhe (opcional)</label><input class='form-control' name='detail' maxlength='500'></div></div></div>"
    . "<div class='card-footer'><button class='btn btn-primary'><i class='ti ti-plus me-1'></i>Adicionar conexão</button></div>";
Html::closeForm();
Html::footer();
