<?php

/**
 * Ficha da iniciativa: resumo/edição, tarefas, dependências, custos, benefícios, riscos e decisões,
 * baseline e mudanças, organização (departamentos, equipes, vínculos) e histórico.
 */

use GlpiPlugin\Vmo\Access;
use GlpiPlugin\Vmo\Audit;
use GlpiPlugin\Vmo\Baseline;
use GlpiPlugin\Vmo\Benefit;
use GlpiPlugin\Vmo\Calc;
use GlpiPlugin\Vmo\Catalog;
use GlpiPlugin\Vmo\ChangeRequest;
use GlpiPlugin\Vmo\Committee;
use GlpiPlugin\Vmo\Charter;
use GlpiPlugin\Vmo\Raci;
use GlpiPlugin\Vmo\Phase;
use GlpiPlugin\Vmo\Schedule;
use GlpiPlugin\Vmo\Cost;
use GlpiPlugin\Vmo\Db;
use GlpiPlugin\Vmo\GroupView;
use GlpiPlugin\Vmo\Department;
use GlpiPlugin\Vmo\Dependency;
use GlpiPlugin\Vmo\Initiative;
use GlpiPlugin\Vmo\ItemLink;
use GlpiPlugin\Vmo\Menu;
use GlpiPlugin\Vmo\Risk;
use GlpiPlugin\Vmo\Settings;
use GlpiPlugin\Vmo\Task;

Session::checkLoginUser();
if (!Access::canView()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

$id = (int) ($_GET['id'] ?? 0);
$e = fn ($v) => Menu::e($v);
$tabs = ['brainstorm' => 'Registro de Brainstorm', 'strategy' => 'Planejamento Estratégico', 'initiation' => 'Iniciação e Alinhamento', 'tasks' => 'Tarefas',
    'monitor' => 'Monitoramento e Controle', 'costs' => 'Custos', 'docs' => 'Documentação', 'closure' => 'Encerramento e Transição', 'history' => 'Histórico'];
// endereços antigos continuam funcionando
$tabAlias = ['resumo' => 'brainstorm', 'tap' => 'initiation', 'org' => 'initiation', 'raci' => 'tasks', 'deps' => 'tasks', 'risks' => 'monitor', 'checkpoints' => 'monitor', 'baseline' => 'monitor', 'benefits' => 'costs'];
$tab = (string) ($_GET['tab'] ?? '');
$tab = $tabAlias[$tab] ?? $tab;
$tab = isset($tabs[$tab]) ? $tab : 'brainstorm';

/** Formulário pequeno que envia para action.php. */
$form = function (string $do, array $hidden, string $inner, string $btn = 'Salvar', string $btnClass = 'btn-primary btn-sm') use ($e): string {
    $h = '';
    foreach ($hidden as $k => $v) {
        $h .= "<input type='hidden' name='" . $e($k) . "' value='" . $e($v) . "'>";
    }
    return "<form method='post' enctype='multipart/form-data' action='" . $e(Menu::url('/front/action.php')) . "'>" . Menu::csrf() . "<input type='hidden' name='do' value='" . $e($do) . "'>$h$inner"
        . ($btn !== '' ? "<button class='btn $btnClass mt-2'>" . $e($btn) . "</button>" : '') . "</form>";
};
$field = fn (string $label, string $input, string $class = '') => "<div class='vmo-f $class'><label class='form-label'>" . $e($label) . "</label><div class='vmo-ctl'>$input</div></div>";
$input = fn (string $name, $value = '', string $attrs = '') => "<input class='form-control' name='" . $e($name) . "' value='" . $e($value) . "' $attrs>";
$area = fn (string $name, $value = '', int $rows = 3, string $ph = '') => "<textarea class='form-control' name='" . $e($name) . "' rows='$rows'" . ($ph !== '' ? " placeholder='" . $e($ph) . "'" : '') . ">" . $e($value) . "</textarea>";

// ---------------------------------------------------------------- nova iniciativa
if ($id === 0) {
    if (!Access::canCreate()) {
        throw new \Glpi\Exception\Http\AccessDeniedHttpException();
    }
    Menu::header('Nova iniciativa', 'initiatives');
    echo Menu::title('Nova iniciativa', 'Tudo começa no brainstorm: registre a ideia e a oportunidade que nasce dela. Gestor, patrocinador, prazos e valores vêm depois, quando a oportunidade for formalizada.',
        Menu::button('Voltar', Menu::url('/front/initiatives.php'), 'ti-arrow-left', 'btn-outline-secondary'));
    $dopts = [];
    foreach (Department::all(true) as $d) {
        $dopts[$d['id']] = $d['code'] . ' · ' . $d['name'];
    }
    $inner = "<div class='vmo-grid2 vmo-cols4'>" . $field('Título da ideia', $input('name', '', 'required'), 'required')
        . $field('Departamento', Menu::select('departments_id', $dopts, '', '', true))
        . $field('Usuário solicitante', Menu::userSelect('users_id_manager', (int) Session::getLoginUserID()))
        . $field('Prioridade', Menu::select('priority', Initiative::PRIORITIES, 3))
        . "</div><div class='vmo-grid2 vmo-cols2 mt-3'>"
        . $field('Dores do departamento', $area('problem', '', 3, 'O que não funciona hoje e o que isso gera: atraso, retrabalho, falhas...'))
        . $field('Descrição da ideia', $area('description', '', 3))
        . $field('Brainstorm: ideias, participantes e discussão', $area('brainstorm_notes', '', 3))
        . $field('Oportunidade que nasceu do brainstorm', $area('opportunity', '', 3))
        . "</div><div class='vmo-grid2 vmo-cols4 mt-3'>" . $field('Data do brainstorm', $input('brainstorm_date', Db::today(), "type='date'"))
        . $field('Ata do brainstorm em anexo (opcional)', "<input class='form-control' type='file' name='brainstorm_file' accept='.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.odt,.txt,.png,.jpg,.jpeg'>", 'vmo-span2')
        . "</div><div class='form-hint mt-1'>PDF, Word, Excel, PowerPoint, texto ou imagem, até 20 MB. A ata fica anexada à iniciativa e pode ser baixada na ficha.</div><input type='hidden' name='start_stage' value='brainstorm'>";
    echo "<div class='card'><div class='card-body'>" . $form('initiative_save', ['id' => 0], $inner, 'Registrar no brainstorm', 'btn-primary') . "</div></div>";
    Html::footer();
    return;
}

// ---------------------------------------------------------------- carregamento
$init = Initiative::load($id);
$canEdit = Access::canEditInitiative($init);
$metrics = Initiative::metricsFor([$id => $init], null, Access::canFinance())[$id];
$cur = $init['currency'];
$self = '/front/initiative.php?id=' . $id;

Menu::header($init['code'] . ' · ' . $init['name'], 'initiatives');
$actions = Menu::button('Portfólio', Menu::url('/front/initiatives.php'), 'ti-arrow-left', 'btn-outline-secondary')
    . Menu::button('Visão do Comitê', Menu::url('/front/stages.php?id=' . $id), 'ti-gavel', 'btn-outline-primary');
if (Access::canCreate()) {
    $actions .= Menu::actionForm('initiative_copy', ['id' => $id], "<button class='btn btn-outline-secondary'><i class='ti ti-copy me-1'></i>Copiar</button>");
}
echo Menu::title($init['code'] . ' · ' . $init['name'], 'Responsável: ' . Db::userName((int) $init['users_id_manager']) . ' · Patrocinador: ' . Db::userName((int) $init['users_id_sponsor'])
    . ' · Entidade: ' . Dropdown::getDropdownName('glpi_entities', (int) $init['entities_id']), $actions);

// ---- cartões
$k = fn (string $label, string $value, string $sub = '', string $color = '#6366f1') => "<div class='vmo-kpi' style='--c:$color'><div class='vmo-kpi-label'>" . $e($label) . "</div><div class='vmo-kpi-value'>$value</div><div class='vmo-kpi-sub'>$sub</div></div>";
echo "<div class='vmo-kpis'>"
    . $k('Situação', Initiative::statusBadge($init['status']), $init['review_date'] ? 'revisão em ' . $e(Menu::date($init['review_date'])) : '')
    . $k('Saúde', Menu::healthBadge($metrics['health']), $metrics['stale'] ? 'atualização vencida' : ($metrics['max_risk'] ? 'maior risco: ' . $metrics['max_risk'] : ''), Menu::HEALTH[$metrics['health']][1])
    . (($f = Committee::farol($init))['key'] ? $k('Saúde (comitê)', Menu::badge($f['label'], $f['color']), $f['key'] === 'done' ? '' : ($f['days'] ?? '?') . ' d sem atualização' . ($f['delay'] ? ' · marco +' . $f['delay'] . ' d' : '') . ($f['block'] ? ' · bloqueio' : ''), $f['color']) : '')
    . $k('Progresso', Menu::pct($metrics['progress']), $init['progress_method'] === 'manual' ? 'método manual' : $metrics['tasks'] . ' tarefa(s)')
    . $k('Atraso', $metrics['delay'] === null ? "<span class='vmo-muted'>sem baseline</span>" : $metrics['delay'] . ' d.ú.', 'previsão ' . $e(Menu::date($init['forecast_end'] ?: $init['planned_end'])), '#f59e0b');
if (Access::canFinance() && $init['status'] !== 'brainstorm') {
    $var = $metrics['cost_variance'];
    echo $k('Custo real', Menu::money($metrics['actual'], $cur), $var ? 'variação ' . Menu::money($var[0], $cur) . ($var[1] !== null ? ' (' . number_format($var[1], 1, ',', '.') . '%)' : '') : 'sem baseline de custo', '#0ea5e9');
}
echo "</div>";

echo "<div class='vmo-tabs'>";
foreach ($tabs as $key => $label) {
    echo "<a class='" . ($tab === $key ? 'active' : '') . "' href='" . $e(Menu::url($self . '&tab=' . $key)) . "'>" . $e($label) . "</a>";
}
echo "</div>";

// ---- mudar situação (recolhido, visível em todas as etapas)
$statusCard = function () use ($init, $id, $form, $field, $input, $area, $self, $e): string {
    $html = '';
    $allowed = array_values(array_filter(Initiative::TRANSITIONS[$init['status']], fn ($to) => Initiative::canTransition($init, $to)));
    if ($allowed) {
        $labels = ['review' => 'Submeter à análise', 'approved' => 'Aprovar (congela a baseline)', 'draft' => $init['status'] === 'review' ? 'Devolver ao autor' : ($init['status'] === 'brainstorm' ? 'Formalizar oportunidade (vira rascunho de iniciativa)' : 'Reabrir como rascunho'), 'rejected' => 'Rejeitar',
            'running' => $init['status'] === 'closed' ? 'Reabrir (exige nova versão do plano)' : ($init['status'] === 'suspended' ? 'Retomar' : 'Iniciar execução'), 'suspended' => 'Suspender',
            'delivered' => 'Registrar entrega (aceite do patrocinador)', 'measuring' => 'Iniciar mensuração de benefícios', 'closed' => 'Encerrar', 'cancelled' => 'Cancelar'];
        $opts = [];
        foreach ($allowed as $to) {
            $opts[$to] = $labels[$to] ?? Initiative::STATUSES[$to][0];
        }
        $inner = "<div class='vmo-grid2 vmo-cols4'>" . $field('Ação', Menu::select('to', $opts, array_key_first($opts)))
            . $field('Justificativa', $area('comment', '', 1, 'Obrigatória para devolver, rejeitar, suspender, cancelar, reabrir e dispensar horizonte'))
            . $field('Revisão (suspensão)', $input('review_date', '', "type='date'")) . $field('Nova previsão (retomada)', $input('forecast_end', '', "type='date'"))
            . "</div><div class='mt-2 d-flex flex-wrap gap-3'>"
            . "<label class='form-check'><input class='form-check-input' type='checkbox' name='cancel_tasks' value='1'><span class='form-check-label'>Cancelar as tarefas pendentes (ao cancelar)</span></label>"
            . "<label class='form-check'><input class='form-check-input' type='checkbox' name='exception' value='1'><span class='form-check-label'>Exceção: entregar com tarefas obrigatórias abertas (exige direito de aprovar)</span></label></div>";
        $html .= "<details class='card mb-3 vmo-status'><summary class='card-header' style='cursor:pointer'><h3 class='card-title'><i class='ti ti-arrows-exchange me-1'></i>Mudar situação <span class='text-secondary small'>· atual: " . $e(Initiative::STATUSES[$init['status']][0]) . "</span></h3></summary><div class='card-body'>"
            . $form('transition', ['id' => $id, 'return' => $self], $inner, 'Confirmar', 'btn-primary') . "</div></details>";
    } elseif ($init['status'] === 'review') {
        $html .= "<div class='alert alert-info'>Aguardando decisão do patrocinador/VMO. Quem submeteu a proposta não pode decidir sobre ela.</div>";
    }
    if (!empty($init['status_reason'])) {
        $html .= "<div class='card mb-3'><div class='card-body'><b>Última justificativa:</b> " . nl2br($e($init['status_reason'])) . "</div></div>";
    }

    return $html;
};
echo $statusCard();

// ---- anexos de cada etapa: tudo vai para a Documentação da iniciativa (e aparece no Comitê)
$attach = function (string $section) use ($id, $self, $e, $canEdit, $field, $input, $init): string {
    $docs = array_values(array_filter(Committee::docs($id), fn ($d) => ($d['section'] ?? '') === $section));
    $o = "<div class='card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-paperclip me-1'></i>Anexos desta etapa <span class='text-secondary small'>(" . count($docs) . ") · seguem para a aba Documentação</span></h3></div>";
    if ($docs) {
        $o .= "<div class='table-responsive'><table class='table card-table table-vcenter'><tbody>";
        foreach ($docs as $d) {
            $o .= "<tr><td><i class='ti ti-file me-1 text-primary'></i><a href='" . $e(Menu::url('/front/minutes.php?id=' . (int) $d['id'])) . "'>" . $e($d['title']) . "</a><div class='small text-secondary'>" . $e($d['original_name']) . "</div></td>"
                . "<td class='small'>" . $e(Menu::date($d['meeting_date'])) . " · " . $e(Db::userName((int) $d['users_id'])) . "</td><td class='text-end'>"
                . (Access::canConfigure() || (int) $d['users_id'] === (int) Session::getLoginUserID() ? Menu::actionForm('minutes_delete', ['id' => $d['id'], 'return' => $self], "<button class='btn btn-sm btn-outline-danger' onclick=\"return confirm('Remover este documento?')\">Remover</button>") : '') . "</td></tr>";
        }
        $o .= "</tbody></table></div>";
    }
    if ($canEdit && !in_array($init['status'], ['closed', 'cancelled'], true)) {
        $o .= "<div class='card-body border-top'><form method='post' enctype='multipart/form-data' action='" . $e(Menu::url('/front/action.php')) . "'>" . Menu::csrf()
            . "<input type='hidden' name='do' value='minutes_add'><input type='hidden' name='kind' value='doc'><input type='hidden' name='section' value='" . $e($section) . "'><input type='hidden' name='initiatives_id' value='$id'><input type='hidden' name='return' value='" . $e($self) . "'>"
            . "<div class='vmo-grid2 vmo-cols4'>" . $field('Título (opcional)', $input('title', '', "placeholder='Nome do documento'")) . $field('Arquivo', "<input class='form-control' type='file' name='file' required accept='.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.odt,.txt,.png,.jpg,.jpeg'>", 'vmo-span2')
            . "<div class='vmo-f'><label class='form-label'>&nbsp;</label><div class='vmo-ctl'><button class='btn btn-outline-primary'><i class='ti ti-upload me-1'></i>Anexar</button></div></div></div></form></div>";
    }
    return $o . '</div>';
};

// ================================================================ REGISTRO DE BRAINSTORM
if ($tab === 'brainstorm') {
    $dis = $canEdit && !in_array($init['status'], ['closed', 'cancelled'], true) ? '' : 'disabled';
    // ata(s) do brainstorm em anexo
    $bsRows = '';
    foreach (Committee::brainstormMinutes($id) as $m) {
        $bsRows .= "<div class='d-flex align-items-center gap-2 py-1 border-bottom'><i class='ti ti-file-text text-primary'></i><a href='" . $e(Menu::url('/front/minutes.php?id=' . (int) $m['id'])) . "'>" . $e($m['title']) . "</a>"
            . "<span class='text-secondary small'>" . $e(Menu::date($m['meeting_date'])) . " · " . $e($m['original_name']) . " · " . number_format($m['size'] / 1024, 0, ',', '.') . " KB · " . $e(Db::userName((int) $m['users_id'])) . "</span><span class='ms-auto'>"
            . (Access::canConfigure() || (int) $m['users_id'] === (int) Session::getLoginUserID() ? Menu::actionForm('minutes_delete', ['id' => $m['id'], 'return' => $self], "<button class='btn btn-sm btn-outline-danger' onclick=\"return confirm('Remover esta ata?')\">Remover</button>") : '') . "</span></div>";
    }
    $bsUpload = '';
    if ($canEdit && !in_array($init['status'], ['closed', 'cancelled'], true)) {
        $bsUpload = "<form method='post' enctype='multipart/form-data' action='" . $e(Menu::url('/front/action.php')) . "' class='mt-2'>" . Menu::csrf() . "<input type='hidden' name='do' value='minutes_add'><input type='hidden' name='kind' value='brainstorm'><input type='hidden' name='initiatives_id' value='$id'><input type='hidden' name='return' value='" . $e($self) . "'>"
            . "<div class='vmo-grid2 vmo-cols4'>" . $field('Data do brainstorm', $input('meeting_date', Db::today(), "type='date'")) . $field('Título (opcional)', $input('title', '', "placeholder='Ata do brainstorm de ...'"))
            . $field('Arquivo da ata', "<input class='form-control' type='file' name='file' required accept='.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.odt,.txt,.png,.jpg,.jpeg'>", 'vmo-span2') . "</div><button class='btn btn-outline-primary mt-2'><i class='ti ti-upload me-1'></i>Anexar ata do brainstorm</button></form>";
    }
    $bsMinutes = "<div class='mt-3'><h4 class='mb-2' style='font-size:.95rem'><i class='ti ti-paperclip me-1'></i>Ata do brainstorm em anexo</h4>" . ($bsRows ?: "<div class='text-secondary small'>Nenhuma ata anexada.</div>") . "$bsUpload</div>";
    $depts = Department::forInitiative($id);
    $deptNames = implode(', ', array_map(fn ($d) => $d['dept_code'] . ' · ' . $d['dept_name'], $depts)) ?: '—';
    $dopts = [];
    foreach (Department::all(true) as $d) {
        $dopts[$d['id']] = $d['code'] . ' · ' . $d['name'];
    }
    $bsInner = "<fieldset $dis><div class='vmo-grid2 vmo-cols4'>"
        . $field('Título da ideia', $input('name', $init['name'], 'required'), 'required vmo-span2')
        . $field('Usuário solicitante', Menu::userSelect('users_id_manager', (int) $init['users_id_manager']))
        . $field('Prioridade', Menu::select('priority', Initiative::PRIORITIES, $init['priority']))
        . $field('Departamento', "<div class='form-control-plaintext'>" . $e($deptNames) . "</div>")
        . "</div><div class='vmo-grid2 vmo-cols2 mt-3'>"
        . $field('Dores do departamento', $area('problem', $init['problem'] ?? '', 3, 'O que não funciona hoje e o que isso gera: atraso, retrabalho, falhas...'))
        . $field('Descrição da ideia', $area('description', $init['description'] ?? '', 3))
        . $field('Brainstorm: ideias, participantes e discussão', $area('brainstorm_notes', $init['brainstorm_notes'] ?? '', 3))
        . $field('Oportunidade que nasceu do brainstorm', $area('opportunity', $init['opportunity'] ?? '', 3))
        . "</div></fieldset>";
    echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-bulb me-1 text-warning'></i>Registro de brainstorm <span class='text-secondary small'>(primeira captura)</span></h3><div class='ms-auto text-secondary small'>versão " . (int) $init['version'] . " · atualizada " . $e(Menu::datetime($init['date_mod'])) . "</div></div><div class='card-body'>"
        . ($init['status'] === 'brainstorm' ? "<div class='alert alert-warning py-2'>Esta iniciativa está na <b>captura</b>. Registre a oportunidade e use \"Mudar situação\" (no alto) para formalizá-la: o planejamento começa e o rascunho do TAP é composto.</div>" : '')
        . $form('initiative_save', ['id' => $id, 'version' => $init['version'], 'return' => $self], $bsInner, $dis ? '' : 'Salvar registro de brainstorm')
        . "</div></div>";
    // chamado de requisição: chamados associados + lista dos abertos pertinentes (mais parecidos com a ideia primeiro)
    echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-ticket me-1'></i>Chamado de requisição</h3><div class='ms-auto text-secondary small'>O chamado de origem do pedido (o requisitante é lido dele para o TAP).</div></div><div class='card-body'>";
    $links = ItemLink::forInitiative($id);
    foreach ($links as $l) {
        echo "<div class='d-flex gap-2 align-items-center mb-1'>" . ($l['url'] ? "<a href='" . $e($l['url']) . "'>" . $e($l['label']) . "</a>" : "<span class='vmo-muted'>" . $e($l['label']) . "</span>")
            . ($l['relation'] === 'origin' ? " <span class='badge bg-primary-lt'>origem</span>" : '')
            . ($canEdit ? Menu::actionForm('link_remove', ['id' => $l['id'], 'return' => $self], "<button class='btn btn-sm btn-outline-danger'>×</button>") : '') . "</div>";
    }
    if (!$links) {
        echo "<div class='vmo-muted mb-2'>Nenhum chamado associado.</div>";
    }
    if ($canEdit) {
        $mine = array_flip(\GlpiPlugin\Vmo\TicketWords::words(($init['name'] ?? '') . ' ' . ($init['problem'] ?? '') . ' ' . ($init['description'] ?? '') . ' ' . ($init['opportunity'] ?? '')));
        $cands = array_values(array_filter(\GlpiPlugin\Vmo\TicketWords::openTickets(160), fn ($x) => !$x['auto']));
        foreach ($cands as &$cd) {
            $cd['score'] = count(array_filter($cd['words'], fn ($w) => isset($mine[$w])));
        }
        unset($cd);
        usort($cands, fn ($x, $y) => [$y['score'], $y['date']] <=> [$x['score'], $x['date']]);
        $cands = array_slice($cands, 0, 100);
        $stN = [\Ticket::INCOMING => 'Novo', \Ticket::ASSIGNED => 'Em atendimento', \Ticket::PLANNED => 'Planejado', \Ticket::WAITING => 'Pendente'];
        // um campo só: digite o número ou parte do título, ou abra a lista dos chamados abertos (mais parecidos com a ideia primeiro)
        $opts = '';
        foreach ($cands as $cd) {
            $label = '#' . $cd['id'] . ' · ' . mb_strimwidth($cd['name'], 0, 80, '…') . ' (' . ($cd['requester'] ?: 'sem solicitante') . ' · ' . ($stN[$cd['status']] ?? '') . ' · ' . date('d/m', strtotime($cd['date'])) . ')'
                . ($cd['new'] ? ' · NOVO' : '') . ($cd['score'] > 0 ? ' · ' . $cd['score'] . ' palavra(s) em comum' : '');
            $opts .= "<option value='" . $e($label) . "'></option>";
        }
        echo "<form method='post' action='" . $e(Menu::url('/front/action.php')) . "' class='mt-2' onsubmit=\"var v=this.elements.chamado.value.match(/#?(\\d+)/);if(!v){alert('Escolha um chamado da lista ou digite o número.');return false}this.elements.items_id.value=v[1]\">" . Menu::csrf()
            . "<input type='hidden' name='do' value='link_add'><input type='hidden' name='initiatives_id' value='$id'><input type='hidden' name='itemtype' value='Ticket'><input type='hidden' name='relation' value='origin'><input type='hidden' name='return' value='" . $e($self) . "'><input type='hidden' name='items_id' value=''>"
            . "<div class='vmo-f'><label class='form-label'>Chamado de requisição <span class='text-secondary small'>(" . count($cands) . " abertos sem iniciativa · clique no campo para listar, ou digite o número ou parte do título)</span></label><div class='vmo-ctl'>"
            . "<input class='form-control' name='chamado' list='vmo-tk-list' autocomplete='off' placeholder='Escolha o chamado da lista…' required><datalist id='vmo-tk-list'>$opts</datalist></div></div>"
            . "<button class='btn btn-primary btn-sm mt-2'>Associar chamado</button></form>"
            . "<div class='small vmo-muted mt-2'>A lista mostra primeiro os chamados mais parecidos com esta ideia. Para ver todos, use <a href='" . $e(Menu::url('/front/request.php')) . "'>Requisições de Iniciativa</a> (lá há a <b>Nuvem de Palavras</b>).</div>";
    }
    echo "</div></div>";
    echo "<div class='card mb-3'><div class='card-body'>" . $bsMinutes . "</div></div>";
    echo $attach('brainstorm');
}
// ================================================================ PLANEJAMENTO ESTRATÉGICO
if ($tab === 'strategy') {
    $dis = $canEdit && !in_array($init['status'], ['closed', 'cancelled'], true) ? '' : 'disabled';
    $planInner = "<fieldset $dis><div class='vmo-grid2 vmo-cols2'>"
        . $field('Objetivo', $area('objective', $init['objective'], 3)) . $field('Justificativa', $area('justification', $init['justification'], 3))
        . $field('Descrição / escopo', $area('description', $init['description'], 3)) . $field('Caso de negócio', $area('business_case', $init['business_case'], 3, 'Benefício esperado, custo estimado, alternativas'))
        . "</div></fieldset>";
    echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-route me-1 text-primary'></i>Planejamento estratégico</h3></div><div class='card-body'>"
        . $form('initiative_save', ['id' => $id, 'version' => $init['version'], 'return' => $self . '&tab=strategy'], $planInner, $dis ? '' : 'Salvar planejamento') . "</div></div>";
    // portfólio estratégico: perspectiva, objetivo, indicador, benefício, faixa e priorização (score)
    $scOpts = ['' => '—', '1' => '1 · baixo', '3' => '3 · médio', '5' => '5 · alto'];
    $crit = '';
    foreach (Initiative::CRITERIA as $f => $c) {
        $crit .= $field($c[0] . ' (' . rtrim(rtrim(number_format(Settings::priorityWeights()[$f], 1, ',', ''), '0'), ',') . '%)',
            Menu::select($f, $scOpts, (string) ($init[$f] ?? '')) . "<div class='form-hint small vmo-muted mt-1'>1: " . $e($c[2]) . "<br>3: " . $e($c[3]) . "<br>5: " . $e($c[4]) . "</div>");
    }
    $score = Initiative::score($init);
    $waived = $init['waiver'] !== null && $init['waiver'] !== '' ? array_map('intval', explode(',', $init['waiver'])) : [];
    $wv = '';
    foreach (Initiative::WAIVER as $i => $q) {
        $wv .= "<label class='form-check'><input class='form-check-input' type='checkbox' name='waiver_flags[$i]' value='1'" . (in_array($i, $waived, true) ? ' checked' : '') . "><span class='form-check-label'>" . $e($q) . "</span></label>";
    }
    $stInner = "<fieldset $dis><div class='vmo-grid2 vmo-cols4'>"
        . $field('Perspectiva', Menu::select('catalogs_id_perspective', Catalog::options('perspective'), $init['catalogs_id_perspective'], '', true))
        . $field('Objetivo estratégico', Menu::select('catalogs_id_objective', Catalog::options('objective'), $init['catalogs_id_objective'], '', true), 'vmo-span2')
        . $field('Status de entrega', Menu::select('delivery_stage', Initiative::DELIVERY, $init['delivery_stage'] ?? 'wip'))
        . $field('Faixa', Menu::select('band', Initiative::BANDS, $init['band'] ?? '', '', true))
        . $field('Dono executivo', Menu::select('catalogs_id_owner', Catalog::names('owner'), $init['catalogs_id_owner'] ?? 0, '', true))
        . $field('Meta', $input('target_value', $init['target_value'] ?? ''))
        . $field('Tipo de benefício', Menu::select('benefit_type', Initiative::BENEFIT_TYPES, $init['benefit_type'] ?? '', '', true))
        . $field('Benefício declarado', $input('benefit_declared', $init['benefit_declared'] ?? ''))
        . $field('Áreas envolvidas', $input('areas', $init['areas'] ?? ''))
        . "</div><h4 class='mt-3 mb-2'>Priorização" . ($score !== null ? " · score <b>" . number_format($score, 1, ',', '') . "</b> · prioridade " . Initiative::scoreBand($score) : ' · preencha as 5 notas para calcular o score') . "</h4>"
        . "<div class='vmo-grid2 vmo-cols4' style='--vmo-hint:1'>$crit</div>"
        . "<h4 class='mt-3 mb-2'>Filtro de renúncia <span class='vmo-muted small'>(antes do score: \"sim\" em qualquer linha devolve a iniciativa com justificativa)</span></h4>"
        . "<input type='hidden' name='waiver_form' value='1'><div class='d-flex flex-column gap-1'>$wv</div></fieldset>";
    echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-target-arrow me-1 text-primary'></i>Definição de Critérios Estratégicos</h3></div><div class='card-body'>"
        . ($waived ? "<div class='alert alert-danger py-2'>Filtro de renúncia: há resposta \"sim\". A iniciativa deve ser devolvida com justificativa.</div>" : '')
        . $form('initiative_save', ['id' => $id, 'version' => $init['version'], 'return' => $self . '&tab=strategy'], $stInner, $dis ? '' : 'Salvar critérios estratégicos')
        . "</div></div>";
    echo $attach('strategy');
}

// ================================================================ INICIAÇÃO E ALINHAMENTO
if ($tab === 'initiation') {
    $dis = $canEdit && !in_array($init['status'], ['closed', 'cancelled'], true) ? '' : 'disabled';
    $locked = (int) $init['baseline_revision'] > 0 ? 'readonly' : '';
    $inner = "<fieldset $dis><div class='vmo-grid2'>"
        . $field('Gestor', Menu::userSelect('users_id_manager', (int) $init['users_id_manager']))
        . $field('Patrocinador', Menu::userSelect('users_id_sponsor', (int) $init['users_id_sponsor']))
        . $field('Início planejado' . ($locked ? ' (baseline)' : ''), $input('planned_start', $init['planned_start'], "type='date' $locked"))
        . $field('Término planejado' . ($locked ? ' (baseline)' : ''), $input('planned_end', $init['planned_end'], "type='date' $locked"))
        . $field('Previsão de término', $input('forecast_end', $init['forecast_end'], "type='date'"))
        . $field('Início real', $input('actual_start', $init['actual_start'], "type='date'"))
        . $field('Término real', $input('actual_end', $init['actual_end'], "type='date'"))
        . $field('Método de progresso', Menu::select('progress_method', ['auto' => 'Automático (tarefas)', 'manual' => 'Manual (justificado)'], $init['progress_method']))
        . $field('Progresso manual (%)', $input('progress_manual', $init['progress_manual'], 'inputmode=decimal'))
        . $field('Justificativa do progresso', $input('progress_justification', $init['progress_justification']))
        . $field('Confidencial', "<label class='form-check'><input class='form-check-input' type='checkbox' name='confidentiality' value='1'" . ($init['confidentiality'] ? ' checked' : '') . "><span class='form-check-label'>Visível só a gestor, patrocinador, participantes e administração</span></label>")
        . "</div></fieldset>";
    echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>Responsáveis e prazos</h3><div class='ms-auto text-secondary small'>versão " . (int) $init['version'] . " · atualizada " . $e(Menu::datetime($init['date_mod'])) . "</div></div><div class='card-body'>"
        . ($dis ? "<div class='alert alert-info py-2'>Somente leitura: você não edita esta iniciativa ou ela está " . $e(mb_strtolower(ltrim(Initiative::STATUSES[$init['status']][0], '0123456789. '))) . ".</div>" : '')
        . $form('initiative_save', ['id' => $id, 'version' => $init['version'], 'return' => $self . '&tab=initiation'], $inner, $dis ? '' : 'Salvar alterações')
        . "</div></div>";

    $c = Charter::ensure($id);
    $sent = $c['status'] === 'issued';
    $snap = Charter::snap($c);
    $tk = Charter::originTicket($id);
    $ro = $dis ? ' disabled' : '';
    $tapTa = fn (string $n, string $l, int $r = 3) => $field($l, "<textarea class='form-control' name='$n' rows='$r'$ro>" . $e($c[$n] ?? '') . "</textarea>");
    $critHtml = '';
    foreach (Charter::criteria($init) as $l => $v) {
        $critHtml .= "<div class='vmo-f'><div class='small vmo-muted'>" . $e($l) . "</div><div>" . $e($v) . "</div></div>";
    }
    echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-file-certificate me-1 text-primary'></i>TAP · Termo de Abertura do Projeto</h3><div class='ms-auto d-flex gap-2'>"
        . ($sent ? Menu::badge('Enviado · rev. ' . (int) $c['revision'], '#22c55e', 'ti-circle-check') : Menu::badge('Rascunho · não enviado', '#f59e0b', 'ti-pencil'))
        . "<a class='btn btn-sm btn-outline-primary' target='_blank' href='" . $e(Menu::url('/front/charter.php?id=' . $id)) . "'><i class='ti ti-eye me-1'></i>Pré-visualizar TAP antes de enviar</a></div></div><div class='card-body'>"
        . "<div class='small vmo-muted mb-3'>O termo é composto com os dados da captura e dos critérios estratégicos. Revise, envie à parte interessada e o registro segue para o Comitê.</div>"
        . "<form method='post' action='" . $e(Menu::url('/front/action.php')) . "'>" . Menu::csrf() . "<input type='hidden' name='initiatives_id' value='$id'><input type='hidden' name='return' value='" . $e($self . '&tab=initiation') . "'>"
        . "<h4 class='tap-h'>1. Identificação</h4><div class='vmo-grid2 vmo-cols4'>"
        . $field('Iniciativa', "<div class='form-control-plaintext'><b>" . $e($init['code'] . ' · ' . $init['name']) . "</b></div>", 'vmo-span2')
        . $field('Gestor / patrocinador', "<div class='form-control-plaintext'>" . $e(Db::userName((int) $init['users_id_manager']) . ' / ' . Db::userName((int) $init['users_id_sponsor'])) . "</div>", 'vmo-span2')
        . $field('Quem demandou (usuário)', Menu::userSelect('requester_users_id', (int) $c['requester_users_id'], true, $ro))
        . $field('Ou área / pessoa externa', $input('requester_name', $c['requester_name'] ?? '', $ro . " placeholder='ex.: Diretoria Comercial'"))
        . $field('Quem vai acompanhar', Menu::userSelect('follower_users_id', (int) $c['follower_users_id'], true, $ro))
        . $field('Chamado de origem', $tk ? "<a href='" . $e(Ticket::getFormURLWithID($tk)) . "' target='_blank'>#$tk</a>" : "<span class='vmo-muted'>sem chamado associado (aba Registro de Brainstorm)</span>")
        . "</div><h4 class='tap-h'>2. Justificativa e contexto</h4><div class='mb-2'>" . ($init['justification'] || $init['problem'] ? nl2br($e(trim(($init['justification'] ?? '') . "\n" . ($init['problem'] ?? '')))) : "<span class='vmo-muted'>Preencha a justificativa em Planejamento Estratégico.</span>") . "</div>"
        . "<h4 class='tap-h'>3. Objetivo</h4>" . $tapTa('objective', 'Objetivo (o que se quer alcançar e como será medido)')
        . "<h4 class='tap-h'>4. Alinhamento estratégico <span class='vmo-muted small'>(vem dos critérios definidos no Planejamento Estratégico)</span></h4><div class='vmo-grid2 vmo-cols4'>$critHtml</div>"
        . "<h4 class='tap-h'>5. Escopo</h4><div class='vmo-grid2 vmo-cols2'>" . $tapTa('scope', 'Escopo (o que será feito)') . $tapTa('out_of_scope', 'Fora do escopo') . "</div>"
        . "<h4 class='tap-h'>6. Entregas principais</h4>" . $tapTa('deliverables', 'Uma por linha')
        . "<h4 class='tap-h'>7. Premissas e restrições</h4><div class='vmo-grid2 vmo-cols2'>" . $tapTa('premises', 'Premissas') . $tapTa('constraints', 'Restrições') . "</div>"
        . "<h4 class='tap-h'>8. Riscos iniciais e critérios de sucesso</h4><div class='vmo-grid2 vmo-cols2'>" . $tapTa('risks_initial', 'Riscos iniciais identificados') . $tapTa('success_criteria', 'Critérios de sucesso') . "</div>"
        . "<h4 class='tap-h'>9. Prazos e orçamento</h4><div class='vmo-grid2 vmo-cols4'>"
        . $field('Início', $input('start_date', $c['start_date'] ?? '', "type='date'$ro")) . $field('Prazo de entrega', $input('delivery_date', $c['delivery_date'] ?? '', "type='date'$ro"), 'required')
        . $field('Orçamento estimado', $input('budget_note', $c['budget_note'] ?? '', $ro . " placeholder='valor ou faixa; detalhes na aba Custos'"), 'vmo-span2')
        . "</div><h4 class='tap-h'>10. Partes interessadas</h4><div class='small vmo-muted'>Registradas no cartão abaixo (" . count(Phase::forInitiative($id, 'stake')) . "). Entram no termo enviado.</div>"
        . "<h4 class='tap-h'>11. Envio e aprovação</h4>"
        . ($sent ? "<div class='alert alert-success py-2'><i class='ti ti-send me-1'></i><b>Enviado em " . $e(Menu::datetime($c['issued_at'])) . "</b> por " . $e(Db::userName((int) $c['issued_by'])) . ($c['sent_to'] ? ' · ' . $e($c['sent_to']) : '') . " · registro disponível no Comitê. Assinaturas digitais: <b>" . count(Charter::signatures($c)) . ' de ' . count(Charter::signers($init, $c)) . "</b> · <a target='_blank' href='" . $e(Menu::url('/front/charter.php?id=' . $id)) . "'>abrir para assinar</a></div>" : '')
        . "<div class='d-flex flex-wrap gap-3 align-items-center border rounded p-2 mb-2'><div><div class='small vmo-muted'>Data e hora do envio</div><b id='tap-now' data-sent='" . ($sent ? '1' : '0') . "'>" . $e($sent ? Menu::datetime($c['issued_at']) : date('d/m/Y H:i')) . "</b>"
        . ($sent ? '' : " <span class='small vmo-muted'>(horário do servidor: é registrado no momento do envio)</span>") . "</div><div><div class='small vmo-muted'>Enviar para</div><b>"
        . $e(((int) $c['requester_users_id'] ? Db::userName((int) $c['requester_users_id']) : ($c['requester_name'] ?: '—')) . ' · ' . Db::userName((int) $c['follower_users_id'])) . "</b></div></div>"
        . ($dis ? '' : "<div class='d-flex gap-2'><button class='btn btn-outline-primary' name='do' value='charter_save'><i class='ti ti-device-floppy me-1'></i>Salvar rascunho</button>"
            . "<button class='btn btn-primary' name='do' value='charter_issue' onclick=\"return confirm('Enviar o TAP agora? A data e a hora do envio serão registradas e o termo segue para o Comitê.')\"><i class='ti ti-send me-1'></i>" . ($sent ? 'Reenviar TAP (nova revisão)' : 'Enviar TAP à parte interessada') . "</button></div>")
        . "</form></div></div>";
    echo "<script>(function(){var el=document.getElementById('tap-now');if(!el||el.dataset.sent==='1')return;setInterval(function(){var d=new Date(),p=function(n){return String(n).padStart(2,'0')};el.textContent=p(d.getDate())+'/'+p(d.getMonth()+1)+'/'+d.getFullYear()+' '+p(d.getHours())+':'+p(d.getMinutes())},15000)})();</script>";
    echo Phase::render($id, 'stake', $self . '&tab=initiation', $canEdit);
    $depts2 = Department::forInitiative($id);
    echo "<div class='row'><div class='col-lg-6'><div class='card mb-3'><div class='card-header'><h3 class='card-title'>Departamentos</h3></div><div class='table-responsive'><table class='table card-table'><thead><tr><th>Departamento</th><th>Papel</th><th>Participação</th><th></th></tr></thead><tbody>";
    foreach ($depts2 as $d) {
        echo "<tr><td>" . $e($d['dept_code'] . ' · ' . $d['dept_name']) . ($d['dept_active'] ? '' : " <span class='badge bg-secondary-lt'>inativo</span>") . "</td><td>" . $e(Department::ROLES[$d['role']] ?? $d['role']) . "</td><td>" . $e(rtrim(rtrim($d['participation_percent'], '0'), '.')) . "%</td>"
            . "<td>" . ($canEdit ? Menu::actionForm('dept_dissoc', ['id' => $d['id'], 'return' => $self . '&tab=initiation'], "<button class='btn btn-sm btn-outline-danger'>Remover</button>") : '') . "</td></tr>";
    }
    echo ($depts2 ? '' : "<tr><td colspan='4' class='text-center text-secondary py-3'>Nenhum departamento associado.</td></tr>") . "</tbody></table></div>";
    if ($canEdit) {
        $dopts2 = [];
        foreach (Department::all(true) as $d) {
            $dopts2[$d['id']] = $d['code'] . ' · ' . $d['name'];
        }
        echo "<div class='card-body border-top'>" . $form('dept_assoc', ['initiatives_id' => $id, 'return' => $self . '&tab=initiation'], "<div class='vmo-grid2'>" . $field('Departamento', Menu::select('departments_id', $dopts2, '')) . $field('Papel', Menu::select('role', Department::ROLES, 'beneficiary'))
            . $field('Participação (%)', $input('participation_percent', '0', 'inputmode=decimal')) . $field('Responsável', Menu::userSelect('users_id_responsible')) . '</div>', 'Associar') . "</div>";
    }
    echo "</div></div><div class='col-lg-6'><div class='card mb-3'><div class='card-header'><h3 class='card-title'>Equipes e participantes</h3></div><div class='table-responsive'><table class='table card-table'><thead><tr><th>Grupo / usuário</th><th>Papel</th><th></th></tr></thead><tbody>";
    foreach (Department::teams($id) as $t) {
        echo "<tr><td>" . ($t['groups_id'] ? "<i class='ti ti-users me-1'></i>" . $e(Dropdown::getDropdownName('glpi_groups', (int) $t['groups_id'])) : "<i class='ti ti-user me-1'></i>" . $e(Db::userName((int) $t['users_id']))) . "</td><td>" . $e($t['role']) . "</td>"
            . "<td>" . ($canEdit ? Menu::actionForm('team_remove', ['id' => $t['id'], 'return' => $self . '&tab=initiation'], "<button class='btn btn-sm btn-outline-danger'>Remover</button>") : '') . "</td></tr>";
    }
    echo "</tbody></table></div>";
    if ($canEdit) {
        echo "<div class='card-body border-top'>" . $form('team_add', ['initiatives_id' => $id, 'return' => $self . '&tab=initiation'], "<div class='vmo-grid2'>" . $field('Grupo GLPI', Group::dropdown(['name' => 'groups_id', 'display' => false]))
            . $field('ou Usuário', Menu::userSelect('users_id')) . $field('Papel', $input('role', 'member')) . '</div><div class="form-hint">Associar um grupo não concede acesso à iniciativa.</div>', 'Adicionar') . "</div>";
    }
    echo "</div></div></div>";
    echo $attach('initiation');
}

// ================================================================ CRONOGRAMA
if ($tab === 'tasks') {
    echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-timeline me-1'></i>Cronograma</h3><div class='ms-auto vmo-muted small'>Barras planejadas com o progresso preenchido, marcos e linha de hoje. Também aparece no Comitê.</div></div><div class='card-body'>" . Schedule::gantt(Task::forInitiative($id)) . "</div></div>";
}

// ================================================================ TAREFAS
if ($tab === 'tasks') {
    $tasks = Task::forInitiative($id);
    $roll = Calc::rollup($tasks);
    $parentsWithKids = array_flip(array_filter(array_map(fn ($t) => (int) $t['parent_id'], $tasks)));
    $taskOpts = ['0' => '— nenhuma (raiz) —'];
    foreach (Task::tree($tasks) as [$tid, $lvl]) {
        $taskOpts[$tid] = str_repeat('— ', $lvl) . $tasks[$tid]['name'];
    }
    $taskForm = function (array $t = []) use ($field, $input, $area, $taskOpts, $form, $id, $self) {
        $tid = (int) ($t['id'] ?? 0);
        $opts = $taskOpts;
        unset($opts[$tid]);
        $inner = "<div class='vmo-grid2 vmo-cols4'>" . $field('Título', $input('name', $t['name'] ?? '', 'required'), 'required')
            . $field('Tarefa-pai', Menu::select('parent_id', $opts, $t['parent_id'] ?? 0))
            . $field('Responsável', Menu::userSelect('users_id_responsible', (int) ($t['users_id_responsible'] ?? 0)))
            . $field('Início', $input('planned_start', $t['planned_start'] ?? '', "type='date'")) . $field('Término', $input('planned_end', $t['planned_end'] ?? '', "type='date'"))
            . $field('Esforço planejado (h)', $input('effort_plan', $t['effort_plan'] ?? '0', 'inputmode=decimal')) . $field('Peso', $input('weight', $t ? $t['weight'] : '1', 'inputmode=decimal'))
            . $field('Prioridade', Menu::select('priority', Initiative::PRIORITIES, $t['priority'] ?? 3))
            . $field('Tipo', "<label class='form-check'><input class='form-check-input' type='checkbox' name='is_milestone' value='1'" . (!empty($t['is_milestone']) ? ' checked' : '') . "><span class='form-check-label'>Marco (peso zero por padrão)</span></label>")
            . $field('Obrigatória para a entrega', Menu::select('is_required', ['1' => 'Sim', '0' => 'Não'], ($t['is_required'] ?? 1) ? '1' : '0'))
            . "</div><div class='vmo-grid2 vmo-cols2 mt-2'>" . $field('Descrição', $area('description', $t['description'] ?? '', 2))
            . $field('Checklist (um item por linha)', $area('checklist', $t && $t['checklist'] ? implode("\n", json_decode($t['checklist'], true) ?: []) : '', 2)) . '</div>';
        return $form('task_save', ['initiatives_id' => $id, 'id' => $tid, 'version' => $t['version'] ?? 0, 'return' => $self . '&tab=tasks'], $inner, $tid ? 'Salvar tarefa' : 'Adicionar tarefa');
    };

    $taskActions = function (array $t, int $tid, bool $summary) use ($form, $field, $input, $area, $self, $id, $canEdit, $taskForm): string {
        $out = '';
        if (!$summary && $t['status'] !== 'cancelled' && Access::canUpdate()) {
            $out .= "<details class='vmo-inline'><summary>Registrar progresso</summary>"
                . $form('task_progress', ['id' => $tid, 'version' => $t['version'], 'return' => $self . '&tab=tasks'],
                    $field('Percentual (0–100)', $input('progress', $t['progress'], 'inputmode=decimal required')) . $field('Esforço realizado nesta atualização (h)', $input('effort_delta', '0', 'inputmode=decimal'))
                    . $field('Data de referência', $input('reference_date', Db::today(), "type='date'")) . $field('Evidência (link ou referência)', $input('evidence')) . $field('Comentário', $area('comment', '', 2)), 'Registrar')
                . "</details>";
        }
        if ($canEdit) {
            if ($t['status'] === 'todo' && !$summary) {
                $out .= Menu::actionForm('task_start', ['id' => $tid, 'return' => $self . '&tab=tasks'], "<button class='btn btn-sm btn-outline-primary mt-1'>Iniciar</button>");
            }
            $out .= "<details class='vmo-inline'><summary>Editar</summary>" . $taskForm($t) . "</details>";
            $out .= Menu::actionForm($t['status'] === 'cancelled' ? 'task_reactivate' : 'task_cancel', ['initiatives_id' => $id, 'id' => $tid, 'return' => $self . '&tab=tasks'],
                "<button class='btn btn-sm btn-outline-secondary mt-1'>" . ($t['status'] === 'cancelled' ? 'Reativar' : 'Cancelar') . "</button>");
            $out .= Menu::actionForm('task_delete', ['initiatives_id' => $id, 'id' => $tid, 'return' => $self . '&tab=tasks'], "<button class='btn btn-sm btn-outline-danger mt-1' onclick=\"return confirm('Remover a tarefa?')\">Remover</button>");
        }
        return $out;
    };

    $tview = ($_GET['v'] ?? 'grupos') === 'arvore' ? 'arvore' : 'grupos';
    $tq = ['id' => $id, 'tab' => 'tasks'];
    echo "<div class='card vg-card'><div class='card-header'><h3 class='card-title'>" . count($tasks) . " tarefa(s)</h3><div class='vg-view ms-auto'>"
        . "<a class='" . ($tview === 'grupos' ? 'on' : '') . "' href='" . $e(Menu::url('/front/initiative.php?' . http_build_query($tq + ['v' => 'grupos']))) . "'><i class='ti ti-layout-rows me-1'></i>Tabela agrupada</a>"
        . "<a class='" . ($tview === 'arvore' ? 'on' : '') . "' href='" . $e(Menu::url('/front/initiative.php?' . http_build_query($tq + ['v' => 'arvore']))) . "'><i class='ti ti-list-tree me-1'></i>Árvore</a></div></div><div class='card-body'>";
    if ($tview === 'grupos') {
        // grupos por mês do prazo: meses anteriores, este mês, próximo mês, demais meses e sem prazo
        $today = Db::today();
        $thisMonth = substr($today, 0, 7);
        $nextMonth = date('Y-m', strtotime($today . ' +1 month'));
        $groups = [];
        foreach ($tasks as $tid => $t) {
            $dl = $t['planned_end'] ?: null;
            $key = $dl === null ? 'zz-none' : (substr($dl, 0, 7) < $thisMonth ? '0-past' : substr($dl, 0, 7));
            $groups[$key][$tid] = $t;
        }
        ksort($groups);
        $palette = GroupView::PALETTE;
        $pi = 0;
        echo "<div class='vg-table vg-tk'>";
        if (!$groups) {
            echo "<div class='card card-body text-center text-secondary'>Sem tarefas: o progresso aparece como \"sem dado\" (nunca 0%).</div>";
        }
        foreach ($groups as $key => $list) {
            if ($key === '0-past') {
                [$title, $color] = ['Meses anteriores', '#ef4444'];
            } elseif ($key === 'zz-none') {
                [$title, $color] = ['Sem prazo', '#94a3b8'];
            } elseif ($key === $thisMonth) {
                [$title, $color] = ['Este mês', '#3b82f6'];
            } elseif ($key === $nextMonth) {
                [$title, $color] = ['Próximo mês', '#a855f7'];
            } else {
                [$title, $color] = [ucfirst(GroupView::MONTHS_FULL[(int) substr($key, 5, 2)]) . ' de ' . substr($key, 0, 4), $palette[$pi++ % count($palette)]];
            }
            uasort($list, fn ($a, $b) => [$a['planned_end'] ?? '9999', -(int) $a['priority'], $a['id']] <=> [$b['planned_end'] ?? '9999', -(int) $b['priority'], $b['id']]);
            echo "<section class='vg-grp' style='--g:" . $e($color) . "'>" . GroupView::groupHead($title, count($list), ['Resp.', 'Status', 'Timeline', 'Prazo', 'Prioridade', '']) . "<div class='vg-grp-body'>";
            foreach ($list as $tid => $t) {
                $summary = isset($parentsWithKids[$tid]);
                $prog = $summary ? ($roll[$tid] ?? null) : (float) $t['progress'];
                $isLate = in_array($t['status'], ['todo', 'doing'], true) && $t['planned_end'] && $t['planned_end'] < $today;
                [$stLabel, $stColor] = $isLate ? ['Atrasada', '#ef4444'] : [Task::STATUSES[$t['status']][0], Task::STATUSES[$t['status']][1]];
                $sub = ['T' . $tid];
                if ($t['parent_id'] && isset($tasks[(int) $t['parent_id']])) {
                    $sub[] = $e($tasks[(int) $t['parent_id']]['name']);
                }
                if ($t['is_milestone']) {
                    $sub[] = 'marco';
                }
                if (!$t['is_required']) {
                    $sub[] = 'opcional';
                }
                $act = $taskActions($t, $tid, $summary);
                echo "<div class='vg-item" . ($isLate ? ' late' : '') . ($t['status'] === 'cancelled' ? ' opacity-50' : '') . "'><div class='vg-row'>"
                    . "<div class='vg-cell-name'><b class='vg-nm'>" . ($t['is_milestone'] ? "<i class='ti ti-flag text-warning me-1'></i>" : ($summary ? "<i class='ti ti-folder me-1 text-secondary'></i>" : '')) . $e($t['name']) . "</b><small>" . implode(' · ', $sub) . "</small></div>"
                    . "<div>" . GroupView::avatar((int) $t['users_id_responsible']) . "</div>" . GroupView::statusCell($stLabel, $stColor)
                    . "<div class='vg-cell-tl'>" . GroupView::bar($prog, $color, ($summary ? 'Progresso derivado das subtarefas' : 'Progresso da tarefa')) . "</div>"
                    . "<div class='vg-cell-dl" . ($isLate ? ' late' : '') . "'>" . $e(GroupView::shortDate($t['planned_end'])) . "</div>"
                    . "<div class='vg-cell-pr' title='" . $e('Prioridade: ' . (Initiative::PRIORITIES[(int) $t['priority']] ?? '')) . "'>" . GroupView::stars((int) $t['priority']) . "</div>"
                    . "<div class='vg-cell-more'>" . ($act !== '' ? "<button type='button' data-more aria-expanded='false' title='Ações da tarefa'><i class='ti ti-chevron-down'></i></button>" : '') . "</div></div>"
                    . ($act !== '' ? "<div class='vg-more'><div class='vmo-acts'>$act</div></div>" : '') . "</div>";
            }
            echo "</div></section>";
        }
        echo "</div></div></div>" . Menu::script('grupos.js');
        if ($canEdit) {
            echo "<div class='card mt-3'><div class='card-header'><h3 class='card-title'>Nova tarefa</h3></div><div class='card-body'>" . $taskForm() . "</div></div>";
        }
        $tasks = [];
        $skipTree = true;
    }
    if (empty($skipTree)) {
        echo "</div></div>";
    echo "<div class='card mb-3'><div class='table-responsive'><table class='table table-vcenter card-table vmo-tasks'><thead><tr><th>Tarefa</th><th>Situação</th><th>Responsável</th><th>Prazo</th><th>Peso</th><th style='min-width:130px'>Progresso</th><th>Ações</th></tr></thead><tbody>";
    foreach (Task::tree($tasks) as [$tid, $lvl]) {
        $t = $tasks[$tid];
        $summary = isset($parentsWithKids[$tid]);
        $prog = $summary ? ($roll[$tid] ?? null) : (float) $t['progress'];
        $late = in_array($t['status'], ['todo', 'doing'], true) && $t['planned_end'] && $t['planned_end'] < Db::today();
        echo "<tr class='" . ($t['status'] === 'cancelled' ? 'opacity-50' : '') . "'><td class='vmo-tree-" . min(4, $lvl) . "'>" . ($t['is_milestone'] ? "<i class='ti ti-flag text-warning me-1' title='Marco'></i>" : ($summary ? "<i class='ti ti-folder me-1 text-secondary'></i>" : ''))
            . "<b>" . $e($t['name']) . "</b>" . ($t['is_required'] ? '' : " <span class='badge bg-secondary-lt'>opcional</span>") . "</td>"
            . "<td>" . Menu::badge(Task::STATUSES[$t['status']][0], Task::STATUSES[$t['status']][1], Task::STATUSES[$t['status']][2]) . ($late ? " <span class='badge bg-danger-lt'>atrasada</span>" : '') . "</td>"
            . "<td>" . $e(Db::userName((int) $t['users_id_responsible'])) . "</td><td>" . $e(Menu::date($t['planned_end'])) . "</td><td>" . $e(rtrim(rtrim($t['weight'], '0'), '.')) . "</td>"
            . "<td>" . ($prog === null ? "<span class='vmo-muted'>sem dado</span>" : "<div class='vmo-bar'><span style='width:" . min(100, $prog) . "%'></span></div><small>" . number_format($prog, 0) . "%" . ($summary ? ' (derivado)' : '') . "</small>") . "</td><td><div class='vmo-acts'>";
        echo $taskActions($t, $tid, $summary);
        echo "</div></td></tr>";
    }
    if (!$tasks) {
        echo "<tr><td colspan='7' class='text-center text-secondary py-4'>Sem tarefas: o progresso aparece como \"sem dado\" (nunca 0%).</td></tr>";
    }
    echo "</tbody></table></div></div>";
    if ($canEdit) {
        echo "<div class='card'><div class='card-header'><h3 class='card-title'>Nova tarefa</h3></div><div class='card-body'>" . $taskForm() . "</div></div>";
    }
    }
}

// ================================================================ RACI
if ($tab === 'tasks') {
    $tasks = Task::forInitiative($id);
    $map = Raci::map($id);
    $c = Charter::forInitiative($id);
    $people = [];
    foreach ([(int) $init['users_id_manager'], (int) $init['users_id_sponsor'], (int) ($c['requester_users_id'] ?? 0), (int) ($c['follower_users_id'] ?? 0)] as $u) {
        $people[$u] = true;
    }
    foreach ($tasks as $t) {
        $people[(int) $t['users_id_responsible']] = true;
    }
    foreach ($map as $cols) {
        foreach ($cols as $u => $r) {
            $people[$u] = true;
        }
    }
    foreach (array_filter(array_map('intval', array_merge(explode(',', (string) ($_GET['add'] ?? '')), [(int) ($_GET['add_user'] ?? 0)]))) as $u) {
        $people[$u] = true;
    }
    unset($people[0]);
    $people = array_keys($people);
    $today = Db::today();
    $today = Db::today();
    // ---- matriz RACI
    $legend = '';
    foreach (Raci::ROLES as $k => [$lab, $col]) {
        $legend .= "<span class='me-3'><span class='badge' style='background:$col;color:#fff'>$k</span> " . $e($lab) . "</span>";
    }
    echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>RACI por tarefa</h3><div class='ms-auto small'>$legend</div></div>";
    if (!$tasks) {
        echo "<div class='card-body vmo-muted'>Sem tarefas: cadastre-as na aba Tarefas e volte para atribuir a RACI.</div></div>";
    } else {
        $rowsHtml = '';
        foreach (Task::tree($tasks) as [$tid, $lvl]) {
            $t = $tasks[$tid];
            $row = $map[$tid] ?? [];
            $warn = Raci::check($row);
            $cells = '';
            foreach ($people as $u) {
                $cur = $row[$u] ?? '';
                $sel = "<select class='form-select form-select-sm raci-sel' name='raci[$tid][$u]' data-r='$cur'" . ($canEdit ? '' : ' disabled') . "><option value=''>·</option>";
                foreach (Raci::ROLES as $k => $_) {
                    $sel .= "<option value='$k'" . ($cur === $k ? ' selected' : '') . ">$k</option>";
                }
                $cells .= "<td class='text-center'>$sel</td>";
            }
            $rowsHtml .= "<tr class='" . ($t['status'] === 'cancelled' ? 'opacity-50' : '') . "'><td style='padding-left:" . (8 + min(4, $lvl) * 14) . "px'>" . ($t['is_milestone'] ? "<i class='ti ti-flag text-warning me-1'></i>" : '') . "<b>" . $e($t['name']) . "</b>"
                . "<div class='small vmo-muted'>" . $e(Menu::date($t['planned_start'])) . ' → ' . $e(Menu::date($t['planned_end'])) . ' · resp.: ' . $e(Db::userName((int) $t['users_id_responsible'])) . "</div></td>$cells"
                . "<td>" . ($warn ? "<span class='badge bg-warning-lt' title='Cada tarefa precisa de um A e de pelo menos um R'>" . $e(implode(' · ', $warn)) . "</span>" : "<span class='badge bg-success-lt'>ok</span>") . "</td></tr>";
        }
        $head = '';
        foreach ($people as $u) {
            $head .= "<th class='text-center' style='min-width:84px'>" . $e(Db::userName($u)) . "</th>";
        }
        echo "<form method='post' action='" . $e(Menu::url('/front/action.php')) . "'>" . Menu::csrf() . "<input type='hidden' name='do' value='raci_save'><input type='hidden' name='initiatives_id' value='$id'>"
            . "<input type='hidden' name='return' value='" . $e($self . '&tab=tasks&add=' . implode(',', $people)) . "'>"
            . "<div class='table-responsive'><table class='table table-vcenter card-table'><thead><tr><th style='min-width:260px'>Tarefa</th>$head<th>Conferência</th></tr></thead><tbody>$rowsHtml</tbody></table></div>"
            . ($canEdit ? "<div class='card-body d-flex flex-wrap gap-2 border-top'><button class='btn btn-primary'><i class='ti ti-device-floppy me-1'></i>Salvar RACI</button></div>" : '') . "</form>"
            . "<script>document.querySelectorAll('.raci-sel').forEach(function(s){var p=function(){s.setAttribute('data-r',s.value)};s.addEventListener('change',p)});</script>";
        if ($canEdit) {
            echo "<div class='card-body border-top d-flex flex-wrap gap-3 align-items-end'>"
                . Menu::actionForm('raci_suggest', ['initiatives_id' => $id, 'return' => $self . '&tab=tasks'], "<button class='btn btn-outline-secondary'><i class='ti ti-wand me-1'></i>Sugerir: responsável = R, gestor = A</button>")
                . "<form method='get' action='" . $e(Menu::url('/front/initiative.php')) . "' class='d-flex gap-2 align-items-end'><input type='hidden' name='id' value='$id'><input type='hidden' name='tab' value='raci'>"
                . "<input type='hidden' name='add' value='" . $e(implode(',', $people)) . "'>"
                . "<div><label class='form-label mb-1 small'>Adicionar pessoa à matriz</label>" . Menu::userSelect('add_user', 0) . "</div><button class='btn btn-outline-primary'><i class='ti ti-user-plus me-1'></i>Adicionar</button></form></div>";
        }
        echo "</div>";
    }
}

// ================================================================ DEPENDÊNCIAS
if ($tab === 'tasks') {
    $tasks = Task::forInitiative($id);
    $deps = Dependency::forInitiative($id);
    echo "<div class='card mb-3'><div class='table-responsive'><table class='table card-table'><thead><tr><th>Tipo</th><th>Predecessora</th><th></th><th>Sucessora</th><th>Defasagem</th><th>Vínculo</th><th></th></tr></thead><tbody>";
    foreach ($deps as $d) {
        echo "<tr><td>" . ($d['kind'] === 'task' ? 'Tarefa' : 'Iniciativa') . "</td><td>" . $e($d['pred_name']) . "</td><td>→</td><td>" . $e($d['succ_name']) . "</td><td>" . (int) $d['lag_days'] . " d.ú.</td>"
            . "<td>" . ($d['is_hard'] ? 'Rígido' : 'Informativo') . (!empty($d['exception_reason']) ? " <span class='badge bg-warning-lt' title='" . $e($d['exception_reason']) . "'>com exceção</span>" : '') . "</td><td class='text-nowrap'>";
        if ($canEdit) {
            echo Menu::actionForm('dep_remove', ['id' => $d['id'], 'return' => $self . '&tab=tasks'], "<button class='btn btn-sm btn-outline-danger'>Remover</button>");
            if ($d['is_hard'] && Access::canApprove() && empty($d['exception_reason'])) {
                echo "<details class='vmo-inline'><summary>Exceção</summary>" . $form('dep_exception', ['id' => $d['id'], 'return' => $self . '&tab=tasks'], $field('Motivo', $input('reason', '', 'required')), 'Liberar') . "</details>";
            }
        }
        echo "</td></tr>";
    }
    if (!$deps) {
        echo "<tr><td colspan='7' class='text-center text-secondary py-4'>Sem dependências.</td></tr>";
    }
    echo "</tbody></table></div></div>";
    if ($canEdit) {
        $topts = [];
        foreach ($tasks as $t) {
            $topts[$t['id']] = $t['name'];
        }
        $iopts = [];
        foreach (Access::visibleInitiatives() as $i) {
            if ((int) $i['id'] !== $id) {
                $iopts[$i['id']] = $i['code'] . ' · ' . $i['name'];
            }
        }
        $common = $field('Defasagem (dias úteis)', $input('lag_days', '0', 'type=number')) . $field('Vínculo', Menu::select('is_hard', ['1' => 'Rígido (bloqueia o início)', '0' => 'Informativo'], '1'));
        echo "<div class='row'><div class='col-lg-6'><div class='card mb-3'><div class='card-header'><h3 class='card-title'>Entre tarefas desta iniciativa (término → início)</h3></div><div class='card-body'>"
            . $form('dep_add', ['kind' => 'task', 'return' => $self . '&tab=tasks'], "<div class='vmo-grid2'>" . $field('Predecessora', Menu::select('pred_id', $topts, '')) . $field('Sucessora', Menu::select('succ_id', $topts, '')) . $common . '</div>', 'Criar vínculo')
            . "</div></div></div><div class='col-lg-6'><div class='card mb-3'><div class='card-header'><h3 class='card-title'>Entre iniciativas (esta iniciativa depende de…)</h3></div><div class='card-body'>"
            . $form('dep_add', ['kind' => 'initiative', 'succ_id' => $id, 'return' => $self . '&tab=tasks'], "<div class='vmo-grid2'>" . $field('Iniciativa predecessora', Menu::select('pred_id', $iopts, '')) . $common . '</div>', 'Criar vínculo')
            . "</div></div></div></div>";
    }
}

// ================================================================ ANEXOS TAREFAS
if ($tab === 'tasks') {
    echo $attach('tasks');
}

// ================================================================ MONITORAMENTO E CONTROLE
if ($tab === 'monitor') {
    echo "<div class='alert alert-info py-2'><b>Monitoramento e controle:</b> status report, riscos ligados a tarefas e entregas, requisições de mudança de implantação e checkpoints quinzenais. Tudo isto aparece no Comitê.</div>";
    echo Phase::render($id, 'status', $self . '&tab=monitor', $canEdit, ['health' => 'green', 'progress' => $metrics['progress'] === null ? '' : number_format((float) $metrics['progress'], 0), 'schedule' => $metrics['delay'] === null ? '' : ($metrics['delay'] > 0 ? 'atraso de ' . $metrics['delay'] . ' dias úteis' : 'no prazo')]);
}

// ================================================================ RISCOS E DECISÕES
if ($tab === 'monitor') {
    $risks = Risk::forInitiative($id);
    echo "<div class='card mb-3'><div class='table-responsive'><table class='table card-table'><thead><tr><th>Tipo</th><th>Título</th><th>Tarefa / entrega</th><th>Prob.</th><th>Impacto</th><th>Exposição</th><th>Responsável</th><th>Prazo</th><th>Situação</th><th></th></tr></thead><tbody>";
    $riskTasks = ['0' => '— nenhuma —'];
    foreach (Task::forInitiative($id) as $rtid => $rt) {
        $riskTasks[$rtid] = $rt['name'];
    }
    $riskForm = function (array $r = []) use ($form, $field, $input, $area, $id, $self, $riskTasks) {
        $inner = "<div class='vmo-grid2 vmo-cols4'>" . $field('Tipo', Menu::select('kind', Risk::KINDS, $r['kind'] ?? 'risk')) . $field('Título', $input('title', $r['title'] ?? '', 'required'))
            . $field('Probabilidade (1–5)', Menu::select('probability', Risk::SCALE, $r['probability'] ?? 3)) . $field('Impacto (1–5)', Menu::select('impact', Risk::SCALE, $r['impact'] ?? 3))
            . $field('Responsável', Menu::userSelect('users_id_owner', (int) ($r['users_id_owner'] ?? 0))) . $field('Prazo da resposta', $input('due_date', $r['due_date'] ?? '', "type='date'"))
            . $field('Situação', Menu::select('status', Risk::STATUSES, $r['status'] ?? 'open'))
            . $field('Tarefa relacionada', Menu::select('tasks_id', $riskTasks, $r['tasks_id'] ?? 0)) . $field('Entrega relacionada', $input('delivery', $r['delivery'] ?? '', "placeholder='ex.: Módulo de relatórios'")) . '</div><div class="mt-2 vmo-grid2">'
            . $field('Causa', $area('cause', $r['cause'] ?? '', 2)) . $field('Impacto (descrição)', $area('impact_text', $r['impact_text'] ?? '', 2))
            . $field('Resposta', $area('response', $r['response'] ?? '', 2)) . $field('Resultado da resposta (obrigatório para encerrar)', $area('outcome', $r['outcome'] ?? '', 2)) . '</div>';
        return $form('risk_save', ['initiatives_id' => $id, 'id' => $r['id'] ?? 0, 'version' => $r['version'] ?? 1, 'return' => $self . '&tab=monitor'], $inner, ($r['id'] ?? 0) ? 'Salvar' : 'Registrar');
    };
    foreach ($risks as $r) {
        $x = Calc::riskExposure((int) $r['probability'], (int) $r['impact']);
        echo "<tr><td>" . $e(Risk::KINDS[$r['kind']]) . "</td><td>" . $e($r['title']) . "</td><td class='small'>" . ($r['tasks_id'] ? "<i class='ti ti-list-check me-1'></i>" . $e($riskTasks[(int) $r['tasks_id']] ?? '#' . $r['tasks_id']) : '') . ($r['delivery'] ? "<div><i class='ti ti-package me-1'></i>" . $e($r['delivery']) . "</div>" : '') . "</td><td>" . (int) $r['probability'] . "</td><td>" . (int) $r['impact'] . "</td>"
            . "<td>" . Menu::badge((string) $x, Risk::exposureColor($x)) . "</td><td>" . $e(Db::userName((int) $r['users_id_owner'])) . "</td><td>" . $e(Menu::date($r['due_date'])) . "</td><td>" . $e(Risk::STATUSES[$r['status']]) . "</td>"
            . "<td>" . (Access::canUpdate() ? "<details class='vmo-inline'><summary>Editar</summary>" . $riskForm($r) . "</details>" : '') . "</td></tr>";
    }
    if (!$risks) {
        echo "<tr><td colspan='10' class='text-center text-secondary py-4'>Nenhum risco ou problema registrado.</td></tr>";
    }
    echo "</tbody></table></div></div>";
    if (Access::canUpdate()) {
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>Novo risco ou problema</h3></div><div class='card-body'>" . $riskForm() . "</div></div>";
    }
    echo "<div class='card'><div class='card-header'><h3 class='card-title'>Decisões do comitê</h3></div><div class='card-body'>";
    foreach (Risk::decisions($id) as $d) {
        echo "<div class='mb-2'><b>" . $e($d['title']) . "</b>" . ($d['decision_kind'] ? " " . Menu::badge(Committee::DECISIONS[$d['decision_kind']] ?? $d['decision_kind'], '#6366f1') : '')
            . " <span class='vmo-muted small'>" . $e($d['committee_date'] ? Menu::date($d['committee_date']) : Menu::datetime($d['decided_at'])) . " · " . $e(Db::userName((int) $d['users_id'])) . ($d['users_id_owner'] ? ' · resp.: ' . $e(Db::userName((int) $d['users_id_owner'])) : '') . ($d['due_date'] ? ' · prazo ' . $e(Menu::date($d['due_date'])) : '') . ($d['is_done'] ? ' · <span class="text-success">concluída</span>' : '') . "</span><div>" . nl2br($e($d['decision_text'])) . "</div></div>";
    }
    if (Access::canUpdate()) {
        echo $form('decision_add', ['initiatives_id' => $id, 'return' => $self . '&tab=monitor'], "<div class='vmo-grid2 vmo-cols4'>" . $field('Data do comitê', $input('committee_date', Db::today(), "type='date'"))
            . $field('Decisão', Menu::select('decision_kind', Committee::DECISIONS, '', '', true)) . $field('Responsável', Menu::userSelect('users_id_owner', 0)) . $field('Prazo', $input('due_date', '', "type='date'"))
            . $field('Título da decisão', $input('title', '', 'required'), 'vmo-span2') . $field('Descrição', $area('decision_text', '', 1), 'vmo-span2') . '</div>', 'Registrar decisão');
    }
    echo "</div></div>";
}

// ================================================================ RM
if ($tab === 'monitor') {
    echo Phase::render($id, 'rm', $self . '&tab=monitor', $canEdit);
}

// ================================================================ CHECKPOINTS
if ($tab === 'monitor') {
    $cps = Committee::checkpoints($id);
    echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>Checkpoints quinzenais</h3><div class='ms-auto text-secondary small'>Um registro por quinzena: duas quinzenas sem atualização levam a iniciativa à pauta do comitê.</div></div><div class='table-responsive'><table class='table table-vcenter card-table'><thead><tr><th>Data</th><th>Quem reportou</th><th>Avanço</th><th>Saúde declarada</th><th>O que avançou</th><th>Bloqueio ou risco</th><th>Ação necessária</th><th>Comitê?</th></tr></thead><tbody>";
    foreach ($cps as $c) {
        $fd = $c['farol_declared'];
        echo "<tr><td>" . $e(Menu::date($c['checkpoint_date'])) . "</td><td>" . $e(Db::userName((int) $c['users_id'])) . "</td><td>" . ($c['progress'] === null ? '—' : number_format((float) $c['progress'], 0) . '%') . "</td><td>" . ($fd ? Menu::badge(Committee::SIGNALS[$fd], Committee::FAROL[$fd][1]) : '—') . "</td>"
            . "<td>" . nl2br($e($c['advanced'])) . "</td><td>" . ($c['has_block'] ? "<span class='badge bg-danger-lt'>bloqueio</span> " : '') . nl2br($e($c['blocker'])) . "</td><td>" . nl2br($e($c['action_needed'])) . "</td><td>" . ($c['needs_committee'] ? 'Sim' : 'Não') . "</td></tr>";
    }
    if (!$cps) {
        echo "<tr><td colspan='8' class='text-center text-secondary py-4'>Nenhum checkpoint registrado.</td></tr>";
    }
    echo "</tbody></table></div></div>";
    if (Access::canUpdate()) {
        $inner = "<div class='vmo-grid2 vmo-cols4'>" . $field('Data', $input('checkpoint_date', Db::today(), "type='date'")) . $field('% de avanço', $input('progress', '', 'inputmode=decimal'))
            . $field('Saúde declarada', Menu::select('farol_declared', Committee::SIGNALS, '', '', true))
            . $field('Decisão do comitê', "<label class='form-check'><input class='form-check-input' type='checkbox' name='needs_committee' value='1'><span class='form-check-label'>Precisa de decisão do comitê</span></label>")
            . "</div><div class='vmo-grid2 vmo-cols4 mt-3'>" . $field('O que avançou na quinzena', $area('advanced', '', 3), 'vmo-span2') . $field('Bloqueio ou risco', $area('blocker', '', 3))
            . $field('Ação necessária', $area('action_needed', '', 3)) . "</div><div class='mt-2'><label class='form-check'><input class='form-check-input' type='checkbox' name='has_block' value='1'><span class='form-check-label'>Há bloqueio aberto (deixa a saúde em atenção)</span></label></div>";
        echo "<div class='card'><div class='card-header'><h3 class='card-title'>Novo checkpoint</h3></div><div class='card-body'>" . $form('checkpoint_add', ['initiatives_id' => $id, 'return' => $self . '&tab=monitor'], $inner, 'Registrar checkpoint') . "</div></div>";
    }
}

// ================================================================ ANEXOS MONITORAMENTO
if ($tab === 'monitor') {
    echo $attach('monitor');
}

// ================================================================ CUSTOS
if ($tab === 'costs') {
    if (!Access::canFinance()) {
        echo "<div class='alert alert-warning'>Os valores financeiros exigem o direito financeiro do VMO no seu perfil.</div>";
    } else {
        $totals = Cost::totals($id);
        $b = Baseline::latest($id);
        $budget = $b ? (json_decode((string) $b['budget_json'], true) ?: []) : [];
        echo "<div class='vmo-kpis'>";
        foreach ($totals + array_fill_keys(array_keys($budget), ['forecast' => 0, 'actual' => 0, 'capex' => 0, 'opex' => 0]) as $c => $t) {
            $bud = $budget[$c] ?? null;
            $var = $bud !== null ? Calc::costVariance($t['forecast'], (float) $bud) : null;
            echo $k("Orçamento da baseline ($c)", $bud === null ? "<span class='vmo-muted'>sem baseline</span>" : Menu::money((float) $bud, $c), 'congelado na aprovação', '#64748b')
                . $k("Previsão atual ($c)", Menu::money($t['forecast'], $c), $var ? 'variação ' . Menu::money($var[0], $c) . ($var[1] !== null ? ' (' . number_format($var[1], 1, ',', '.') . '%)' : '') : '', '#0ea5e9')
                . $k("Real ($c)", Menu::money($t['actual'], $c), 'CAPEX ' . Menu::money($t['capex'], $c) . ' · OPEX ' . Menu::money($t['opex'], $c), '#22c55e')
                . $k("Saldo da previsão ($c)", Menu::money($t['forecast'] - $t['actual'], $c), 'previsão − real', '#f59e0b');
        }
        echo "</div><div class='card mb-3'><div class='table-responsive'><table class='table card-table'><thead><tr><th>Competência</th><th>Tipo</th><th>Categoria</th><th>Descrição</th><th class='text-end'>Valor/hora</th><th class='text-end'>Horas</th><th class='text-end'>Valor</th><th>Origem</th><th></th></tr></thead><tbody>";
        foreach (Cost::forInitiative($id) as $c) {
            $neg = (float) $c['amount'] < 0;
            echo "<tr><td>" . $e(Menu::date($c['competence_date'])) . "</td><td>" . $e(Cost::KINDS[$c['kind']]) . ' · ' . $e(Cost::TYPES[$c['cost_type']]) . "</td><td>" . $e($c['category']) . "</td><td>" . $e($c['description']) . "</td>"
                . "<td class='text-end'>" . ($c['hourly_rate'] !== null ? $e(Menu::money((float) $c['hourly_rate'], $c['currency'])) : '<span class="vmo-muted">—</span>') . "</td><td class='text-end'>" . ($c['hours'] !== null ? $e(rtrim(rtrim(number_format((float) $c['hours'], 2, ',', '.'), '0'), ',')) . ' h' : '<span class="vmo-muted">—</span>') . "</td>"
                . "<td class='text-end " . ($neg ? 'vmo-money-neg' : '') . "'>" . $e(Menu::money((float) $c['amount'], $c['currency'])) . "</td><td>" . $e($c['source']) . "</td><td>";
            if ($c['kind'] === 'actual' && !$neg && !(int) $c['reversal_of_id'] && $canEdit && !countElementsInTable(Db::t('costs'), ['reversal_of_id' => (int) $c['id']])) {
                echo "<details class='vmo-inline'><summary>Estornar</summary>" . $form('cost_reverse', ['id' => $c['id'], 'return' => $self . '&tab=costs'], $field('Motivo do estorno', $input('reason', '', 'required')), 'Estornar') . "</details>";
            }
            echo "</td></tr>";
        }
        echo "</tbody></table></div></div>";
        if ($canEdit) {
            $inner = "<div class='vmo-grid2 vmo-cols4'>" . $field('Tipo', Menu::select('kind', Cost::KINDS, 'forecast')) . $field('Classificação', Menu::select('cost_type', Cost::TYPES, 'opex'))
                . $field('Competência', $input('competence_date', Db::today(), "type='date' required"))
                . $field('Valor/hora', $input('hourly_rate', '', 'inputmode=decimal placeholder="0,00" data-calc="rate"'))
                . $field('Total de horas', $input('hours', '', 'inputmode=decimal placeholder="0" data-calc="hours"'))
                . $field('Valor (calculado: valor/hora × horas)', $input('amount', '', 'inputmode=decimal placeholder="0,00" data-calc="amount"'))
                . $field('Categoria', $input('category')) . $field('Descrição', $input('description'))
                . $field('Origem', $input('source', 'manual')) . $field('Evidência', $input('evidence')) . '</div>'
                . "<div class='form-hint mt-2'>Informando valor/hora e total de horas, o valor do lançamento é calculado automaticamente; sem eles, digite o valor.</div>"
                . "<script>(function(){var f=document.currentScript.closest('form'),r=f.querySelector('[data-calc=rate]'),h=f.querySelector('[data-calc=hours]'),a=f.querySelector('[data-calc=amount]');function n(v){v=(v||'').trim();if(v.indexOf(',')>-1)v=v.replace(/\\./g,'').replace(',','.');return parseFloat(v)}function upd(){var x=n(r.value),y=n(h.value);if(x>0&&y>0){a.value=(Math.round(x*y*100)/100).toFixed(2).replace('.',',');a.readOnly=true}else{a.readOnly=false}}r.addEventListener('input',upd);h.addEventListener('input',upd);})();</script>";
            echo "<div class='card'><div class='card-header'><h3 class='card-title'>Novo lançamento</h3></div><div class='card-body'>" . $form('cost_add', ['initiatives_id' => $id, 'return' => $self . '&tab=costs'], $inner, 'Lançar') . "</div></div>";
        }
    }
}

// ================================================================ ANEXOS CUSTOS
if ($tab === 'costs') {
    echo $attach('costs');
}

// ================================================================ DOCUMENTAÇÃO
if ($tab === 'docs') {
    $tapc = Charter::forInitiative($id);
    if ($tapc && $tapc['status'] === 'issued' && !(int) $tapc['doc_id']) {
        try {
            Charter::syncDoc($id);
        } catch (\Throwable $ex) {
        }
    }
    $docs = Committee::docs($id);
    $icons = ['pdf' => 'ti-file-type-pdf', 'doc' => 'ti-file-type-doc', 'docx' => 'ti-file-type-doc', 'xls' => 'ti-file-type-xls', 'xlsx' => 'ti-file-type-xls', 'ppt' => 'ti-file-type-ppt', 'pptx' => 'ti-file-type-ppt',
        'png' => 'ti-photo', 'jpg' => 'ti-photo', 'jpeg' => 'ti-photo', 'txt' => 'ti-file-text', 'odt' => 'ti-file-text'];
    echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>Documentação da iniciativa</h3><div class='card-actions small text-secondary'>" . count($docs) . " arquivo(s)</div></div>";
    echo "<div class='table-responsive'><table class='table card-table table-vcenter table-hover'><thead><tr><th>Documento</th><th>Etapa</th><th>Arquivo</th><th>Data</th><th>Tamanho</th><th>Enviado por</th><th></th></tr></thead><tbody>";
    foreach ($docs as $d) {
        $ext = strtolower(pathinfo((string) $d['original_name'], PATHINFO_EXTENSION));
        echo "<tr><td><i class='ti " . ($icons[$ext] ?? 'ti-file') . " me-1 text-primary'></i><a href='" . $e(Menu::url('/front/minutes.php?id=' . (int) $d['id'])) . "'>" . $e($d['title']) . "</a>" . ($d['notes'] ? "<div class='small text-secondary'>" . $e($d['notes']) . "</div>" : '') . "</td>"
            . "<td class='small'>" . $e(Phase::SECTIONS[$d['section'] ?? ''] ?? 'Geral') . "</td><td class='small'>" . $e($d['original_name']) . "</td><td>" . $e(Menu::date($d['meeting_date'])) . "</td><td>" . number_format($d['size'] / 1024, 0, ',', '.') . " KB</td><td>" . $e(Db::userName((int) $d['users_id'])) . "</td><td class='text-end'>"
            . (Access::canConfigure() || (int) $d['users_id'] === (int) Session::getLoginUserID() ? Menu::actionForm('minutes_delete', ['id' => $d['id'], 'return' => $self . '&tab=docs'], "<button class='btn btn-sm btn-outline-danger' onclick=\"return confirm('Remover este documento?')\">Remover</button>") : '') . "</td></tr>";
    }
    if (!$docs) {
        echo "<tr><td colspan='7' class='text-center text-secondary py-4'>Nenhum documento anexado. Anexe a especificação, o manual, apresentações e planilhas desta iniciativa.</td></tr>";
    }
    echo "</tbody></table></div>";
    if ($canEdit) {
        echo "<div class='card-body border-top'><form method='post' enctype='multipart/form-data' action='" . $e(Menu::url('/front/action.php')) . "'>" . Menu::csrf()
            . "<input type='hidden' name='do' value='minutes_add'><input type='hidden' name='kind' value='doc'><input type='hidden' name='initiatives_id' value='$id'><input type='hidden' name='return' value='" . $e($self . '&tab=docs') . "'>"
            . "<div class='vmo-grid2 vmo-cols4'>" . $field('Data do documento', $input('meeting_date', Db::today(), "type='date'")) . $field('Título (opcional)', $input('title', '', "placeholder='Nome do documento'"))
            . $field('Arquivo', "<input class='form-control' type='file' name='file' required accept='.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.odt,.txt,.png,.jpg,.jpeg'>", 'vmo-span2') . "</div>"
            . "<div class='form-hint mt-1'>PDF, Word, Excel, PowerPoint, texto ou imagem, até 20 MB. O arquivo fica na iniciativa e é baixado pela ficha.</div><button class='btn btn-primary mt-2'><i class='ti ti-upload me-1'></i>Anexar documento</button></form></div>";
    }
    echo "</div>";
}

// ================================================================ ENCERRAMENTO E TRANSIÇÃO
if ($tab === 'closure') {
    echo "<div class='alert alert-info py-2'>Termo de aceite, lições aprendidas (se aplicável) e termo de encerramento. Os termos assinados ficam registrados e visíveis ao Comitê.</div>";
    echo "<div class='card mb-3'><div class='card-body d-flex flex-wrap gap-2 align-items-center'><div><b>Ver os termos antes de enviar</b><div class='small vmo-muted'>Modelo já preenchido com os dados da iniciativa (prazos, tarefas, entregas do TAP). Revise, registre abaixo e envie.</div></div><div class='ms-auto d-flex gap-2'>"
        . "<a class='btn btn-primary' target='_blank' href='" . $e(Menu::url('/front/statusreport.php?ini=' . $id . '&kind=close')) . "'><i class='ti ti-eye me-1'></i>Pré-visualizar Termo de Encerramento</a>"
        . "<a class='btn btn-outline-primary' target='_blank' href='" . $e(Menu::url('/front/statusreport.php?ini=' . $id . '&kind=accept')) . "'><i class='ti ti-eye me-1'></i>Termo de Aceite</a></div></div></div>";
    echo Phase::render($id, 'accept', $self . '&tab=closure', $canEdit);
    echo Phase::render($id, 'lessons', $self . '&tab=closure', $canEdit);
    echo Phase::render($id, 'close', $self . '&tab=closure', $canEdit);
    echo $attach('closure');
}

// ================================================================ HISTÓRICO
if ($tab === 'history') {
    echo "<div class='card'><div class='card-header'><h3 class='card-title'>Trilha de auditoria</h3></div><div class='table-responsive'><table class='table card-table'><thead><tr><th>Quando</th><th>Quem</th><th>Ação</th><th>Detalhe</th></tr></thead><tbody>";
    $ev = Audit::forItem('Initiative', $id);
    foreach ($ev as $a) {
        $det = json_decode((string) $a['diff'], true);
        $txt = [];
        foreach ((array) $det as $kk => $vv) {
            $txt[] = $kk . ': ' . (is_array($vv) ? (isset($vv['de']) ? ($vv['de'] ?? '∅') . ' → ' . ($vv['para'] ?? '∅') : json_encode($vv, JSON_UNESCAPED_UNICODE)) : $vv);
        }
        echo "<tr><td class='text-nowrap'>" . $e(Menu::datetime($a['date_creation'])) . "</td><td>" . $e(Db::userName((int) $a['users_id'])) . "</td><td>" . $e($a['action']) . "</td><td class='small'>" . $e(mb_strimwidth(implode(' · ', $txt), 0, 300, '…')) . "</td></tr>";
    }
    if (!$ev) {
        echo "<tr><td colspan='4' class='text-center text-secondary py-4'>Sem eventos.</td></tr>";
    }
    echo "</tbody></table></div></div>";
}
Html::footer();
