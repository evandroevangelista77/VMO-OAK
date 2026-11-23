<?php

/**
 * Configuração e diagnóstico do VMO (RF01, RF24): parâmetros, limites de saúde versionados,
 * catálogos, departamentos, câmbio, delegações e verificação de dependências e rotinas.
 */

use GlpiPlugin\Vmo\Access;
use GlpiPlugin\Vmo\Calc;
use GlpiPlugin\Vmo\Catalog;
use GlpiPlugin\Vmo\Db;
use GlpiPlugin\Vmo\Department;
use GlpiPlugin\Vmo\Menu;
use GlpiPlugin\Vmo\Settings;

Session::checkLoginUser();
if (!Access::canView() || !Access::canConfigure()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}
$e = fn ($v) => Menu::e($v);

// exportação da configuração (sem segredos)
if (!empty($_GET['export'])) {
    header('Content-Type: application/json; charset=UTF-8');
    header('Content-Disposition: attachment; filename="vmo-configuracao.json"');
    echo json_encode([
        'plugin' => 'vmo', 'version' => PLUGIN_VMO_VERSION, 'currency' => Settings::currency(), 'cadence_days' => Settings::cadenceDays(), 'holidays' => Settings::holidays(),
        'report_retention_days' => Settings::reportRetentionDays(), 'audit_retention_months' => Settings::auditRetentionMonths(),
        'thresholds' => Settings::thresholds(), 'thresholds_version' => Settings::thresholdsVersion(),
        'catalogs' => array_map(fn ($c) => ['kind' => $c['kind'], 'code' => $c['code'], 'name' => $c['name'], 'active' => (bool) $c['is_active']], Db::rows('catalogs')),
        'departments' => array_map(fn ($d) => ['code' => $d['code'], 'name' => $d['name'], 'active' => (bool) $d['is_active']], Department::all()),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    return;
}

$form = function (string $do, array $hidden, string $inner, string $btn = 'Salvar') use ($e): string {
    $h = '';
    foreach ($hidden as $k => $v) {
        $h .= "<input type='hidden' name='" . $e($k) . "' value='" . $e($v) . "'>";
    }
    return "<form method='post' action='" . $e(Menu::url('/front/action.php')) . "'>" . Menu::csrf() . "<input type='hidden' name='do' value='" . $e($do) . "'><input type='hidden' name='return' value='/front/config.php'>$h$inner"
        . ($btn !== '' ? "<button class='btn btn-primary btn-sm mt-2'>" . $e($btn) . "</button>" : '') . "</form>";
};
$field = fn (string $label, string $input) => "<div><label class='form-label'>" . $e($label) . "</label>$input</div>";
$input = fn (string $name, $value = '', string $attrs = '') => "<input class='form-control' name='" . $e($name) . "' value='" . $e($value) . "' $attrs>";

Menu::header('Configuração', 'config');
echo Menu::title('Configuração do VMO', 'Parâmetros por instalação, limites de saúde versionados, governança, catálogos, departamentos, delegações e diagnóstico.',
    Menu::button('Exportar configuração (sem segredos)', Menu::url('/front/config.php?export=1'), 'ti-download', 'btn-outline-secondary'));

// ---- parâmetros gerais + limites
$th = Settings::thresholds();
$labels = ['delay_red' => 'Atraso crítico (> dias úteis)', 'delay_yellow' => 'Atraso de atenção (> dias úteis)', 'cost_red' => 'Desvio de custo crítico (> %)', 'cost_yellow' => 'Desvio de custo de atenção (> %)',
    'risk_red' => 'Risco crítico (exposição ≥)', 'risk_yellow' => 'Risco de atenção (exposição ≥)'];
echo "<div class='row'><div class='col-lg-6'><div class='card mb-3'><div class='card-header'><h3 class='card-title'>Parâmetros gerais</h3></div><div class='card-body'>"
    . $form('config_settings', [], "<div class='vmo-grid2'>" . $field('Moeda funcional (ISO)', $input('currency', Settings::currency(), 'maxlength=3')) . $field('Cadência de atualização (dias)', $input('cadence_days', Settings::cadenceDays(), 'type=number min=1'))
        . $field('Retenção dos relatórios (dias)', $input('report_retention_days', Settings::reportRetentionDays(), 'type=number min=1')) . $field('Retenção da auditoria (meses)', $input('audit_retention_months', Settings::auditRetentionMonths(), 'type=number min=1')) . '</div>'
        . "<div class='mt-2'>" . $field('Feriados (AAAA-MM-DD, separados por vírgula ou linha) — usados nos dias úteis', "<textarea class='form-control' name='holidays' rows='3'>" . $e(implode(', ', Settings::holidays())) . "</textarea>") . '</div>')
    . "</div></div></div><div class='col-lg-6'><div class='card mb-3'><div class='card-header'><h3 class='card-title'>Limites de saúde (versão " . Settings::thresholdsVersion() . ")</h3></div><div class='card-body'>";
$inner = "<div class='vmo-grid2'>";
foreach ($th as $k => $v) {
    $inner .= $field($labels[$k], $input("th[$k]", $v, 'inputmode=decimal'));
}
echo $form('config_thresholds', [], $inner . '</div>', 'Salvar nova versão')
    . "<div class='form-hint mt-2'>Mudar um limite cria uma nova versão: cálculos novos usam a vigente; baselines e relatórios anteriores mantêm a versão com que foram gerados.</div></div></div></div></div>";

// ---- escalonamento (plugin Escalations)
$escOn = class_exists(\GlpiPlugin\Escalations\Api::class) && \GlpiPlugin\Escalations\Api::available();
$types = '';
foreach (\GlpiPlugin\Vmo\Escalation::TYPES as $tk => [$tl, $ts, $td]) {
    $types .= "<tr><td><b>" . $e($tl) . "</b><div class='small vmo-muted'>" . $e($td) . "</div></td><td><code>$tk</code></td><td>$ts</td></tr>";
}
echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-flame me-1'></i>Escalonamento (plugin Escalations)</h3></div><div class='card-body'>"
    . ($escOn ? "<div class='alert alert-success py-2'>Escalations ativo: as situações abaixo viram itens de plugin <code>vmo</code> a cada rodada da rotina <b>vmonotify</b> (6 em 6 horas) e são resolvidas quando acabam. Defina quem recebe em <i>Escalations → Hierarquias → Regras</i> (origem “Item de plugin”).</div>"
        : "<div class='alert alert-warning py-2'>O plugin Escalations não está instalado ou ativo. O VMO funciona igual; instale o Escalations para escalar as situações críticas abaixo.</div>")
    . $form('config_escalation', [], "<div class='vmo-grid2 vmo-cols4'>" . $field('Escalonamento', Menu::select('esc_on', ['1' => 'Ligado', '0' => 'Desligado'], Settings::get('esc_on', '1')))
        . $field('TAP sem enviar após (dias em execução)', $input('esc_tap_days', Settings::get('esc_tap_days', '3'), 'type=number min=1')) . $field('Assinatura do TAP pendente após (dias)', $input('esc_sign_days', Settings::get('esc_sign_days', '5'), 'type=number min=1'))
        . $field('Proposta parada após (dias)', $input('esc_review_days', Settings::get('esc_review_days', '7'), 'type=number min=1')) . $field('Requisição sem triagem após (dias)', $input('esc_request_days', Settings::get('esc_request_days', '5'), 'type=number min=1')) . '</div>', 'Salvar')
    . "<div class='table-responsive mt-3'><table class='table table-sm'><thead><tr><th>Situação escalada</th><th>Tipo</th><th>Gravidade padrão</th></tr></thead><tbody>$types</tbody></table></div>"
    . ($escOn ? Menu::actionForm('escalation_sync', ['return' => '/front/config.php'], "<button class='btn btn-outline-primary'><i class='ti ti-send me-1'></i>Escalonar agora</button>") : '')
    . "</div></div>";

// ---- governança do portfólio
echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>Governança do portfólio</h3></div><div class='card-body'>"
    . $form('config_governance', [], "<div class='vmo-grid2 vmo-cols4'>" . $field('Limite de iniciativas em execução por dono executivo', $input('wip_limit', GlpiPlugin\Vmo\Committee::wipLimit(), 'type=number min=1')) . '</div>'
        . "<div class='mt-2'><label class='form-check'><input class='form-check-input' type='checkbox' name='oak_rules' value='1'" . (Settings::get('oak_rules', '0') === '1' ? ' checked' : '') . "><span class='form-check-label'>Exigir regras do portfólio: só aprova com dono, indicador, linha de base, meta e prazo; só entra em execução se houver vaga no limite do dono</span></label></div>", 'Salvar')
    . "</div></div>";

// ---- pesos da priorização (comitê estratégico)
$pw = Settings::priorityWeights();
$winner = '';
foreach (GlpiPlugin\Vmo\Initiative::CRITERIA as $f => $c) {
    $winner .= $field($c[0] . ' (%)', $input("pw[$f]", $pw[$f], 'inputmode=decimal'));
}
echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>Pesos da priorização (somam 100)</h3></div><div class='card-body'>"
    . $form('config_weights', [], "<div class='vmo-grid2 vmo-cols4'>$winner</div>", 'Salvar pesos')
    . "<div class='form-hint mt-2'>Score = soma de (nota 1/3/5 ÷ 5 × peso). 70 a 100: prioridade alta · 40 a 69: média · abaixo de 40: baixa.</div></div></div>";

// ---- catálogos
echo "<div class='row'>";
foreach (Catalog::KINDS as $kind => $title) {
    echo "<div class='col-lg-6'><div class='card mb-3'><div class='card-header'><h3 class='card-title'>" . $e($title) . "</h3></div><div class='table-responsive'><table class='table card-table'><tbody>";
    foreach (Catalog::all($kind, false) as $c) {
        echo "<tr class='" . ($c['is_active'] ? '' : 'opacity-50') . "'><td>" . $e($c['code']) . "</td><td>" . $e($c['name']) . "</td><td class='text-end'>"
            . Menu::actionForm('catalog_toggle', ['id' => $c['id'], 'return' => '/front/config.php'], "<button class='btn btn-sm btn-outline-secondary'>" . ($c['is_active'] ? 'Desativar' : 'Ativar') . "</button>") . "</td></tr>";
    }
    echo "</tbody></table></div><div class='card-body border-top'>" . $form('catalog_add', ['kind' => $kind], "<div class='vmo-grid2'>" . $field('Código', $input('code', '', 'required')) . $field('Nome', $input('name', '', 'required')) . '</div>', 'Adicionar') . "</div></div></div>";
}
echo "</div>";

// ---- departamentos
echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>Departamentos</h3></div><div class='table-responsive'><table class='table card-table'><thead><tr><th>Código</th><th>Nome</th><th>Responsável</th><th></th></tr></thead><tbody>";
foreach (Department::all() as $d) {
    echo "<tr class='" . ($d['is_active'] ? '' : 'opacity-50') . "'><td>" . $e($d['code']) . "</td><td>" . $e($d['name']) . "</td><td>" . $e(Db::userName((int) $d['users_id_responsible'])) . "</td><td class='text-end'>"
        . Menu::actionForm('dept_toggle', ['id' => $d['id'], 'return' => '/front/config.php'], "<button class='btn btn-sm btn-outline-secondary'>" . ($d['is_active'] ? 'Desativar' : 'Ativar') . "</button>") . "</td></tr>";
}
echo "</tbody></table></div><div class='card-body border-top'>" . $form('dept_add', [], "<div class='vmo-grid2'>" . $field('Código', $input('code', '', 'required')) . $field('Nome', $input('name', '', 'required')) . $field('Responsável', Menu::userSelect('users_id_responsible')) . '</div>', 'Adicionar departamento') . "</div></div>";

// ---- delegações
echo "<div class='row'>";
echo "<div class='col-lg-6'><div class='card mb-3'><div class='card-header'><h3 class='card-title'>Delegações de alçada</h3></div><div class='table-responsive'><table class='table card-table'><tbody>";
foreach (Db::rows('delegations', [], ['id DESC'], 30) as $d) {
    echo "<tr><td>" . $e(Db::userName((int) $d['users_id_from'])) . " → " . $e(Db::userName((int) $d['users_id_to'])) . "</td><td>" . $e(Menu::date($d['valid_from'])) . " a " . $e(Menu::date($d['valid_to'])) . "</td><td class='text-end'>"
        . Menu::actionForm('delegation_remove', ['id' => $d['id'], 'return' => '/front/config.php'], "<button class='btn btn-sm btn-outline-danger'>Remover</button>") . "</td></tr>";
}
echo "</tbody></table></div><div class='card-body border-top'>" . $form('delegation_add', [], "<div class='vmo-grid2'>" . $field('Titular', Menu::userSelect('users_id_from')) . $field('Substituto', Menu::userSelect('users_id_to'))
    . $field('Vigência: início', $input('valid_from', Db::today(), "type='date'")) . $field('Vigência: fim', $input('valid_to', Db::today(), "type='date'")) . $field('Motivo', $input('reason')) . '</div>', 'Cadastrar delegação') . "</div></div></div></div>";

// ---- diagnóstico (RF24)
global $DB;
$exts = ['zip' => 'DOCX dos relatórios', 'mbstring' => 'textos UTF-8', 'gd' => 'gráficos/PDF', 'json' => 'JSON'];
echo "<div class='card'><div class='card-header'><h3 class='card-title'>Diagnóstico</h3></div><div class='card-body'><table class='table table-sm'><tbody>"
    . "<tr><td>Versão do plugin</td><td>" . $e(PLUGIN_VMO_VERSION) . " (fórmulas v" . $e(Calc::FORMULA_VERSION) . ")</td></tr>"
    . "<tr><td>GLPI</td><td>" . $e(GLPI_VERSION) . " (suportado: " . $e(PLUGIN_VMO_MIN_GLPI . ' a ' . PLUGIN_VMO_MAX_GLPI) . ")</td></tr>"
    . "<tr><td>PHP</td><td>" . $e(PHP_VERSION) . "</td></tr>";
foreach ($exts as $x => $why) {
    echo "<tr><td>Extensão $x ($why)</td><td>" . (extension_loaded($x) ? "<span class='text-success'>ok</span>" : "<span class='text-danger'>ausente</span>") . "</td></tr>";
}
$missing = array_filter(PLUGIN_VMO_TABLES, fn ($t) => !$DB->tableExists(Db::t($t)));
echo "<tr><td>Tabelas do plugin</td><td>" . ($missing ? "<span class='text-danger'>faltando: " . $e(implode(', ', $missing)) . "</span>" : "<span class='text-success'>" . count(PLUGIN_VMO_TABLES) . " de " . count(PLUGIN_VMO_TABLES) . " presentes</span>") . "</td></tr>";
foreach ($DB->request(['FROM' => 'glpi_crontasks', 'WHERE' => ['itemtype' => 'GlpiPlugin\\Vmo\\Jobs']]) as $c) {
    echo "<tr><td>Rotina <code>" . $e($c['name']) . "</code></td><td>" . ($c['lastrun'] ? 'último ciclo ' . $e($c['lastrun']) : "<span class='text-warning'>ainda não executou</span>") . ' · ' . ((int) $c['state'] === 1 ? 'ativa' : 'desativada') . "</td></tr>";
}
echo "<tr><td>Eventos de auditoria</td><td>" . (int) countElementsInTable(Db::t('audit_events')) . " (retenção " . Settings::auditRetentionMonths() . " meses)</td></tr></tbody></table></div></div>";
Html::footer();
