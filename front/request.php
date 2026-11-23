<?php

/**
 * Requisição de iniciativa: as demandas chegam por chamado do GLPI e o time de VMO registra a iniciativa
 * a partir do que veio no chamado. A tela lista chamados abertos ainda sem iniciativa; escolher um traz o
 * conteúdo para o formulário, e ao gravar a iniciativa nasce na Captura, já vinculada ao chamado.
 */

use GlpiPlugin\Vmo\Access;
use GlpiPlugin\Vmo\Catalog;
use GlpiPlugin\Vmo\Db;
use GlpiPlugin\Vmo\GroupView;
use GlpiPlugin\Vmo\Department;
use GlpiPlugin\Vmo\Initiative;
use GlpiPlugin\Vmo\Menu;

Session::checkLoginUser();
if (!Access::canView()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}
if (!Access::canCreate()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}
global $DB;
$e = fn ($v) => Menu::e($v);
$field = fn (string $label, string $input, string $class = '') => "<div class='vmo-f $class'><label class='form-label'>" . $e($label) . "</label><div class='vmo-ctl'>$input</div></div>";
$input = fn (string $name, $value = '', string $attrs = '') => "<input class='form-control' name='" . $e($name) . "' value='" . $e($value) . "' $attrs>";
$area = fn (string $name, $value = '', int $rows = 3, string $ph = '') => "<textarea class='form-control' name='" . $e($name) . "' rows='$rows'" . ($ph !== '' ? " placeholder='" . $e($ph) . "'" : '') . ">" . $e($value) . "</textarea>";

Menu::header('Requisições de Iniciativa', 'requests');
$tid = (int) ($_GET['ticket'] ?? 0);

// chamados já ligados a alguma iniciativa
$linked = [];
foreach ($DB->request(['SELECT' => ['items_id', 'initiatives_id'], 'FROM' => Db::t('item_links'), 'WHERE' => ['itemtype' => 'Ticket']]) as $r) {
    $linked[(int) $r['items_id']] = (int) $r['initiatives_id'];
}

$ticket = null;
if ($tid > 0) {
    $t = new Ticket();
    if ($t->getFromDB($tid) && $t->can($tid, READ)) {
        $ticket = $t;
    } else {
        Session::addMessageAfterRedirect('Chamado não encontrado ou sem acesso a ele.', false, ERROR);
    }
}

echo Menu::title('Requisições de Iniciativa', 'Os pedidos chegam por chamado. Clique em um chamado para ver o resumo ao lado e registre a iniciativa: ela nasce na Captura, já ligada ao chamado de origem.',
    "<button type='button' class='btn btn-outline-primary' id='wc-open'><i class='ti ti-cloud me-1'></i>Nuvem de Palavras</button>");

// ---- busca por número + lista de chamados abertos (clique abre o resumo na gaveta)
$where = ['is_deleted' => 0, 'status' => [Ticket::INCOMING, Ticket::ASSIGNED, Ticket::PLANNED, Ticket::WAITING]] + getEntitiesRestrictCriteria('glpi_tickets');
if ($linked) {
    $where[] = ['NOT' => ['id' => array_keys($linked)]];
}
$templates = '';
$groupsTk = [];
$n = 0;
$plain = fn (string $html): string => trim(preg_replace("/\n{3,}/", "\n\n", html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>', '</li>'], "\n", html_entity_decode($html)), ), ENT_QUOTES | ENT_HTML5)));
foreach ($DB->request(['FROM' => 'glpi_tickets', 'WHERE' => $where, 'ORDER' => ['date DESC'], 'LIMIT' => 25]) as $r) {
    $tk = new Ticket();
    if (!$tk->getFromDB((int) $r['id']) || !$tk->canViewItem()) {
        continue;
    }
    $n++;
    $tid2 = (int) $r['id'];
    $reqs = [];
    $reqId = 0;
    foreach ($DB->request(['SELECT' => ['users_id'], 'FROM' => 'glpi_tickets_users', 'WHERE' => ['tickets_id' => $tid2, 'type' => CommonITILActor::REQUESTER]]) as $u) {
        $reqs[] = Db::userName((int) $u['users_id']);
        $reqId = $reqId ?: (int) $u['users_id'];
    }
    $assigned = [];
    foreach ($DB->request(['SELECT' => ['users_id'], 'FROM' => 'glpi_tickets_users', 'WHERE' => ['tickets_id' => $tid2, 'type' => CommonITILActor::ASSIGN]]) as $u) {
        $assigned[] = Db::userName((int) $u['users_id']);
    }
    $cat = $r['itilcategories_id'] ? Dropdown::getDropdownName('glpi_itilcategories', (int) $r['itilcategories_id']) : '—';
    $grid = "<div class='vmo-dr-grid'>";
    foreach (['Situação' => Ticket::getStatus((int) $r['status']), 'Prioridade' => Ticket::getPriorityName((int) $r['priority']), 'Tipo' => Ticket::getTicketTypeName((int) $r['type']), 'Categoria' => $cat,
        'Solicitante' => implode(', ', $reqs) ?: '—', 'Atribuído a' => implode(', ', $assigned) ?: '—', 'Abertura' => Menu::datetime($r['date']), 'Entidade' => Dropdown::getDropdownName('glpi_entities', (int) $r['entities_id'])] as $l => $v) {
        $grid .= "<div class='c'><div class='l'>" . $e($l) . "</div><div class='v'>" . $e($v) . "</div></div>";
    }
    $grid .= "</div>";
    $fups = '';
    foreach ($DB->request(['FROM' => 'glpi_itilfollowups', 'WHERE' => ['itemtype' => 'Ticket', 'items_id' => $tid2], 'ORDER' => ['date DESC'], 'LIMIT' => 3]) as $f) {
        $fups .= "<div class='vmo-dr-item'><div class='small vmo-muted'>" . $e(Menu::datetime($f['date'])) . " · " . $e(Db::userName((int) $f['users_id'])) . "</div><div>" . nl2br($e(mb_substr($plain((string) $f['content']), 0, 600))) . "</div></div>";
    }
    $body = "<div class='vmo-dr-lead'><b>#$tid2</b> · " . $e($r['name']) . "</div>$grid<h4 class='mt-3'>Descrição</h4><div class='vmo-dr-item'>" . nl2br($e(mb_substr($plain((string) $r['content']), 0, 4000)) ?: '—') . "</div>"
        . ($fups ? "<h4 class='mt-3'>Últimos acompanhamentos</h4>$fups" : '')
        . "<div class='d-flex gap-2 mt-3'><a class='btn btn-primary' href='" . $e(Menu::url('/front/request.php?ticket=' . $tid2)) . "'><i class='ti ti-file-plus me-1'></i>Registrar iniciativa</a>"
        . "<a class='btn btn-outline-secondary' target='_blank' href='" . $e(Ticket::getFormURLWithID($tid2)) . "'><i class='ti ti-external-link me-1'></i>Abrir chamado</a></div>";
    $templates .= "<template id='dr-tk-$tid2' data-title='" . $e('Chamado #' . $tid2) . "' data-icon='ti-ticket' data-color='#6366f1'><div class='vmo-dr'>$body</div></template>";
    $prio = (int) $r['priority'];
    $stColors = [Ticket::INCOMING => '#3b82f6', Ticket::ASSIGNED => '#6366f1', Ticket::PLANNED => '#a855f7', Ticket::WAITING => '#f59e0b'];
    $ageDays = max(0, (int) floor((time() - strtotime($r['date'])) / 86400));
    // novos: abertos nas últimas 48 horas e ainda não abertos na gaveta neste navegador (o JS tira o destaque ao ver o resumo)
    $isNew = (time() - strtotime($r['date'])) <= 48 * 3600;
    $groupsTk[$prio][] = "<div class='vg-item" . ($ageDays > 15 ? ' late' : '') . ($isNew ? ' vg-new' : '') . "' data-tk='$tid2' data-drawer='tk-$tid2' tabindex='0' role='button' style='cursor:pointer'><div class='vg-row'>"
        . "<div class='vg-cell-name'><b class='vg-nm'>" . ($isNew ? "<span class='vg-badge-new'>NOVO</span>" : '') . $e($r['name']) . "</b><small>#$tid2 · " . $e($cat) . " · " . $e($reqs ? implode(', ', $reqs) : 'sem solicitante') . "</small></div>"
        . "<div>" . GroupView::avatar($reqId) . "</div>" . GroupView::statusCell(Ticket::getStatus((int) $r['status']), $stColors[(int) $r['status']] ?? '#94a3b8')
        . "<div class='vg-cell-dl" . ($ageDays > 15 ? ' late' : '') . "' title='Tempo desde a abertura'>há " . $ageDays . ($ageDays === 1 ? ' dia' : ' dias') . "</div>"
        . "<div class='vg-cell-dl'>" . $e(GroupView::shortDate(substr((string) $r['date'], 0, 10))) . "</div>"
        . "<div class='vg-cell-pr' title='" . $e('Prioridade: ' . Ticket::getPriorityName($prio)) . "'>" . GroupView::stars(max(1, min(5, $prio))) . "</div></div></div>";
}
$prioInfo = [6 => ['Prioridade máxima', '#ef4444'], 5 => ['Prioridade muito alta', '#f97316'], 4 => ['Prioridade alta', '#f59e0b'], 3 => ['Prioridade média', '#6366f1'], 2 => ['Prioridade baixa', '#06b6d4'], 1 => ['Prioridade muito baixa', '#94a3b8']];
krsort($groupsTk);
$tkHtml = '';
foreach ($groupsTk as $prio => $items) {
    [$ttl, $clr] = $prioInfo[$prio] ?? ['Prioridade ' . $prio, '#94a3b8'];
    $tkHtml .= "<section class='vg-grp' style='--g:$clr'>" . GroupView::groupHead($ttl, count($items), ['Solic.', 'Situação', 'Aberto', 'Abertura', 'Prioridade']) . "<div class='vg-grp-body'>" . implode('', $items) . "</div></section>";
}
// ---- Nuvem de Palavras e estatísticas: o que os chamados abertos ainda sem iniciativa estão pedindo
$wcTickets = \GlpiPlugin\Vmo\TicketWords::openTickets(250);
$wcData = array_map(fn ($t) => ['id' => $t['id'], 'n' => $t['name'], 'd' => substr($t['date'], 0, 10), 'r' => $t['requester'], 'new' => $t['new'], 'auto' => $t['auto'], 'p' => $t['priority'], 'w' => $t['words'], 'u' => Ticket::getFormURLWithID($t['id']), 'q' => Menu::url('/front/request.php?ticket=' . $t['id'])], $wcTickets);
$wcPrio = [];
foreach ([1, 2, 3, 4, 5, 6] as $pp) {
    $wcPrio[$pp] = Ticket::getPriorityName($pp);
}
echo "<div class='card vg-card mb-3' id='wc-panel' style='display:none'><div class='card-header'><h3 class='card-title'><i class='ti ti-cloud me-1'></i>Nuvem de Palavras <span class='text-secondary small' id='wc-sub'></span></h3>"
    . "<div class='ms-auto d-flex flex-wrap gap-3 align-items-center'><div class='wc-tabs'><button type='button' class='on' data-v='cloud'><i class='ti ti-cloud me-1'></i>Nuvem</button><button type='button' data-v='stats'><i class='ti ti-chart-bar me-1'></i>Estatísticas</button></div>"
    . "<label class='form-check m-0'><input class='form-check-input' type='checkbox' id='wc-new'><span class='form-check-label'>só os novos (48 h)</span></label>"
    . "<label class='form-check m-0' title='Alertas do Zabbix/Wazuh e chamados de processo de outros módulos'><input class='form-check-input' type='checkbox' id='wc-auto'><span class='form-check-label'>incluir alertas e chamados de processo</span></label>"
    . "<button type='button' class='btn btn-sm btn-outline-secondary' id='wc-close'>Fechar</button></div></div>"
    . "<div class='card-body'><div id='wc-cloud'></div><div id='wc-stats' style='display:none'></div><div id='wc-list' class='mt-3'></div></div></div>"
    . "<style>
#wc-panel .wc-tabs{display:inline-flex;border:1px solid var(--tblr-border-color);border-radius:8px;overflow:hidden}#wc-panel .wc-tabs button{border:0;background:transparent;padding:.3rem .8rem;font-size:.85rem;color:var(--tblr-body-color)}#wc-panel .wc-tabs button.on{background:var(--tblr-primary);color:#fff}
.wc-hint{font-size:.8rem;color:var(--tblr-secondary-color);margin-bottom:.4rem}
.wc-cloud{display:flex;flex-wrap:wrap;gap:.2rem .9rem;align-items:baseline;justify-content:center;padding:1.4rem 1rem;border:1px solid var(--tblr-border-color);border-radius:12px;background:color-mix(in srgb,var(--tblr-primary) 4%,transparent);min-height:180px}
.wc-svg{width:100%;height:auto;display:block}.wc-box{padding:.2rem 0}.wc-edge{fill:none;stroke:color-mix(in srgb,var(--tblr-primary) 35%,var(--tblr-border-color));stroke-width:5}.wc-fill{fill:color-mix(in srgb,var(--tblr-primary) 5%,var(--tblr-bg-surface,#fff));stroke:none}.wc-w{cursor:pointer;font-family:Inter,'Segoe UI',Arial,sans-serif;font-weight:800;paint-order:stroke;transition:opacity .12s}.wc-w:hover{opacity:.7}.wc-w.on{fill:var(--tblr-warning,#f59e0b)!important;text-decoration:underline}
.wc-w:hover{background:color-mix(in srgb,var(--tblr-primary) 14%,transparent);transform:translateY(-1px)}.wc-w.on{background:var(--tblr-primary);color:#fff!important}
.wc-t1{fill:var(--tblr-primary);font-weight:700}.wc-t2{fill:var(--tblr-body-color);font-weight:600}.wc-t3{fill:var(--tblr-secondary-color);font-weight:500}
.wc-leg{display:flex;gap:1.2rem;justify-content:center;font-size:.78rem;color:var(--tblr-secondary-color);margin-top:.6rem}.wc-leg i{display:inline-block;width:9px;height:9px;border-radius:50%;margin-right:.3rem}
.wc-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:.7rem;margin-bottom:1rem}.wc-k{border:1px solid var(--tblr-border-color);border-left:4px solid var(--c,#3b82f6);border-radius:10px;padding:.6rem .8rem}.wc-k b{display:block;font-size:1.5rem;line-height:1.1}.wc-k span{font-size:.75rem;color:var(--tblr-secondary-color);text-transform:uppercase;letter-spacing:.05em}
.wc-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:1rem}.wc-card{border:1px solid var(--tblr-border-color);border-radius:12px;padding:.8rem 1rem}.wc-card h5{font-size:.85rem;margin:0 0 .6rem;text-transform:uppercase;letter-spacing:.05em;color:var(--tblr-secondary-color)}
.wc-bar{display:grid;grid-template-columns:110px 1fr 56px;gap:.5rem;align-items:center;font-size:.85rem;margin-bottom:.3rem;cursor:default}.wc-bar.clk{cursor:pointer}.wc-bar.clk:hover .wc-nm{color:var(--tblr-primary)}.wc-nm{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.wc-tr{height:10px;border-radius:6px;background:color-mix(in srgb,var(--tblr-body-color) 8%,transparent);overflow:hidden}.wc-tr i{display:block;height:100%;border-radius:6px;background:var(--c,var(--tblr-primary))}.wc-n{text-align:right;color:var(--tblr-secondary-color);font-size:.8rem}
.wc-pairs{display:flex;flex-wrap:wrap;gap:.4rem}.wc-pair{border:1px solid var(--tblr-border-color);border-radius:999px;padding:.15rem .7rem;font-size:.82rem;cursor:pointer}.wc-pair:hover{border-color:var(--tblr-primary);color:var(--tblr-primary)}.wc-pair b{color:var(--tblr-primary);margin-left:.3rem}
.wc-wk{display:flex;align-items:flex-end;gap:6px;height:110px}.wc-wk div{flex:1;text-align:center;font-size:.7rem;color:var(--tblr-secondary-color)}.wc-wk i{display:block;border-radius:5px 5px 0 0;background:var(--tblr-primary);margin-bottom:3px;min-height:2px}.wc-wk b{display:block;color:var(--tblr-body-color);font-size:.78rem}
</style>"
    . "<script>(function(){var T=" . json_encode($wcData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . ",PR=" . json_encode($wcPrio, JSON_UNESCAPED_UNICODE) . ";"
    . "var P=document.getElementById('wc-panel'),C=document.getElementById('wc-cloud'),ST=document.getElementById('wc-stats'),L=document.getElementById('wc-list'),S=document.getElementById('wc-sub'),NW=document.getElementById('wc-new'),AU=document.getElementById('wc-auto');var cur=null,view='cloud';"
    . "function esc(s){return String(s).replace(/[&<>\"']/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;',\"'\":'&#39;'}[c]})}"
    . "function dmy(s){return s.split('-').reverse().join('/')}function days(s){return Math.max(0,Math.floor((Date.now()-new Date(s+'T00:00:00').getTime())/864e5))}"
    . "function data(){return T.filter(function(t){return(!NW.checked||t.new)&&(AU.checked||!t.auto)})}"
    . "function count(d){var c={};d.forEach(function(t){t.w.forEach(function(w){c[w]=(c[w]||0)+1})});return c}"
    . "function ranked(d){var c=count(d),n=d.length,lim=n>=12?Math.ceil(n*.6):n+1;var ws=Object.keys(c).filter(function(w){return(c[w]>1||n<10)&&c[w]<=lim}).sort(function(a,b){return c[b]-c[a]||a.localeCompare(b)});return{c:c,ws:ws}}"
    . "function pick(w){cur=cur===w?null:w;draw()}"
    . "function cloud(d){"
    . "var r=ranked(d),c=r.c,top=r.ws.slice(0,60);"
    . "if(!top.length){C.innerHTML='<div class=vmo-muted text-center py-4>Nenhum chamado para montar a nuvem com os filtros atuais.</div>';return}"
    . "var NS='http://www.w3.org/2000/svg',W=Math.max(560,Math.min(1120,C.clientWidth||900)),H=Math.round(W*.46);"
    . "var mk=function(n,a){var e=document.createElementNS(NS,n);for(var k in a)e.setAttribute(k,a[k]);return e};"
    . "var inside=function(x,y){var ex=(x-W/2)/(W*.5),ey=(y-H/2)/(H*.5);return Math.pow(ex,4)+Math.pow(ey,4)<=1};"
    . "var svg=mk('svg',{viewBox:'0 0 '+W+' '+H,'class':'wc-svg'});"
    . "var PAL=['#3b82f6','#8b5cf6','#ec4899','#f97316','#10b981','#06b6d4','#eab308','#ef4444'],ctx=document.createElement('canvas').getContext('2d'),max=c[top[0]],min=c[top[top.length-1]],best=null;"
    . "for(var sc=1.7;sc>=.5;sc-=.05){var placed=[],ok=0;"
    . "top.forEach(function(w,i){var f=Math.round((15+Math.pow(c[w]/max,1.2)*52)*sc);if(f<11)return;ctx.font='800 '+f+'px Inter,Segoe UI,Arial,sans-serif';var tw=ctx.measureText(w).width+4,th=f*1.0,rot=i>=2&&i%4===1;var bw=rot?th:tw,bh=rot?tw:th,hit=false;"
    . "for(var t=0;t<6000&&!hit;t+=1){var ang=t*.13,rad=t*.17,px=W*.5+rad*Math.cos(ang)*1.7,py=H*.5+rad*Math.sin(ang)*.9,x0=px-bw/2,y0=py-bh/2;"
    . "if(!inside(x0,y0)||!inside(x0+bw,y0)||!inside(x0,y0+bh)||!inside(x0+bw,y0+bh)||!inside(px,py))continue;"
    . "var clash=false;for(var k=0;k<placed.length;k++){var q=placed[k];if(x0<q.x+q.w+2&&x0+bw+2>q.x&&y0<q.y+q.h+1&&y0+bh+1>q.y){clash=true;break}}"
    . "if(clash)continue;placed.push({x:x0,y:y0,w:bw,h:bh,t:w,f:f,px:px,py:py,rot:rot,i:i});hit=true;ok++}});"
    . "if(!best||ok>best.ok)best={ok:ok,p:placed};if(ok===top.length)break}"
    . "best.p.forEach(function(q){var hh=0;for(var z=0;z<q.t.length;z++)hh=(hh*31+q.t.charCodeAt(z))%997;var t=mk('text',{x:q.px,y:q.py,'text-anchor':'middle','dominant-baseline':'central','class':'wc-w'+(q.t===cur?' on':''),'font-size':q.f,'data-w':q.t,style:'fill:'+PAL[(hh+q.i)%PAL.length]});if(q.rot)t.setAttribute('transform','rotate(-90 '+q.px+' '+q.py+')');t.textContent=q.t;var ti=mk('title',{});ti.textContent=c[q.t]+' chamado(s)';t.appendChild(ti);svg.appendChild(t)});"
    . "C.innerHTML='<div class=wc-hint>Palavras dos títulos e textos dos chamados abertos sem iniciativa. Quanto maior a palavra, mais chamados falam dela: é por onde dá para começar. Clique para ver os chamados.</div>';var bx=document.createElement('div');bx.className='wc-box';bx.appendChild(svg);C.appendChild(bx);"
    . "var lg=document.createElement('div');lg.className='wc-leg';lg.textContent='Tamanho da palavra = número de chamados que a citam';C.appendChild(lg)}"
    . "function bars(rows,max,color,click){return rows.map(function(x){return'<div class=\"wc-bar'+(click?' clk':'')+'\"'+(click?' data-w=\"'+esc(x[0])+'\"':'')+'><span class=\"wc-nm\" title=\"'+esc(x[0])+'\">'+esc(x[0])+'</span><div class=\"wc-tr\"><i style=\"width:'+Math.max(3,Math.round(x[1]/max*100))+'%;'+(color?'--c:'+color:'')+'\"></i></div><span class=\"wc-n\">'+x[1]+(x[2]?' · '+x[2]:'')+'</span></div>'}).join('')||'<div class=\"vmo-muted\">Sem dados.</div>'}"
    . "function stats(d){var n=d.length,ag=d.map(function(t){return days(t.d)}),avg=n?Math.round(ag.reduce(function(a,b){return a+b},0)/n):0,old=n?Math.max.apply(null,ag):0,nw=d.filter(function(t){return t.new}).length,r=ranked(d),topw=r.ws.slice(0,12);"
    . "var h='<div class=\"wc-kpis\"><div class=\"wc-k\" style=\"--c:#3b82f6\"><b>'+n+'</b><span>chamados analisados</span></div><div class=\"wc-k\" style=\"--c:#f59e0b\"><b>'+nw+'</b><span>novos (48 h)</span></div><div class=\"wc-k\" style=\"--c:#6366f1\"><b>'+avg+' d</b><span>idade média</span></div><div class=\"wc-k\" style=\"--c:#ef4444\"><b>'+old+' d</b><span>mais antigo</span></div><div class=\"wc-k\" style=\"--c:#14b8a6\"><b>'+r.ws.length+'</b><span>termos recorrentes</span></div></div><div class=\"wc-grid\">';"
    . "h+='<div class=\"wc-card\"><h5>Termos mais citados</h5>'+bars(topw.map(function(w){return[w,r.c[w],Math.round(r.c[w]/n*100)+'%']}),topw.length?r.c[topw[0]]:1,'',true)+'</div>';"
    // pares
    . "var pc={},top40=r.ws.slice(0,40),set={};top40.forEach(function(w){set[w]=1});d.forEach(function(t){var u=t.w.filter(function(w){return set[w]});for(var i=0;i<u.length;i++)for(var j=i+1;j<u.length;j++){var k=[u[i],u[j]].sort().join(' + ');pc[k]=(pc[k]||0)+1}});"
    . "var pairs=Object.keys(pc).filter(function(k){return pc[k]>1}).sort(function(a,b){return pc[b]-pc[a]}).slice(0,14);"
    . "h+='<div class=\"wc-card\"><h5>Temas que aparecem juntos</h5><div class=\"wc-pairs\">'+(pairs.map(function(k){return'<span class=\"wc-pair\" data-w=\"'+esc(k.split(' + ')[0])+'\">'+esc(k)+'<b>'+pc[k]+'</b></span>'}).join('')||'<span class=\"vmo-muted\">Poucos chamados para cruzar temas.</span>')+'</div></div>';"
    // semanas
    . "var wk=[];for(var i=7;i>=0;i--){var e=new Date();e.setHours(0,0,0,0);e.setDate(e.getDate()-i*7);var s=new Date(e);s.setDate(s.getDate()-6);var cnt=d.filter(function(t){var x=new Date(t.d+'T00:00:00');return x>=s&&x<=e}).length;wk.push([s.getDate()+'/'+(s.getMonth()+1),cnt])}"
    . "var wm=Math.max.apply(null,wk.map(function(x){return x[1]}))||1;h+='<div class=\"wc-card\"><h5>Chamados abertos por semana</h5><div class=\"wc-wk\">'+wk.map(function(x){return'<div><b>'+x[1]+'</b><i style=\"height:'+Math.round(x[1]/wm*80)+'px\"></i>'+x[0]+'</div>'}).join('')+'</div></div>';"
    // idade
    . "var bk=[['até 2 dias',0,2],['3 a 7 dias',3,7],['8 a 15 dias',8,15],['16 a 30 dias',16,30],['mais de 30 dias',31,1e9]].map(function(b){return[b[0],ag.filter(function(a){return a>=b[1]&&a<=b[2]}).length]});h+='<div class=\"wc-card\"><h5>Tempo de espera</h5>'+bars(bk,Math.max.apply(null,bk.map(function(x){return x[1]}))||1,'#f59e0b',false)+'</div>';"
    // prioridade
    . "var pp={};d.forEach(function(t){pp[t.p]=(pp[t.p]||0)+1});var pr=Object.keys(pp).sort(function(a,b){return b-a}).map(function(k){return[PR[k]||('Prioridade '+k),pp[k]]});h+='<div class=\"wc-card\"><h5>Por prioridade</h5>'+bars(pr,Math.max.apply(null,pr.map(function(x){return x[1]}))||1,'#6366f1',false)+'</div>';"
    // solicitantes
    . "var rq={};d.forEach(function(t){var k=t.r||'sem solicitante';rq[k]=(rq[k]||0)+1});var rr=Object.keys(rq).sort(function(a,b){return rq[b]-rq[a]}).slice(0,7).map(function(k){return[k,rq[k]]});h+='<div class=\"wc-card\"><h5>Quem mais pede</h5>'+bars(rr,rr.length?rr[0][1]:1,'#14b8a6',false)+'</div></div>';ST.innerHTML=h}"
    . "function list(d){if(!cur){L.innerHTML='';return}var m=d.filter(function(t){return t.w.indexOf(cur)>-1});L.innerHTML='<h4 style=\"font-size:.95rem\">'+m.length+' chamado(s) com \"'+esc(cur)+'\" <button type=\"button\" class=\"btn btn-sm btn-link\" id=\"wc-clear\">limpar</button></h4><div class=\"table-responsive\"><table class=\"table table-sm table-vcenter\"><tbody>'+m.map(function(t){return'<tr><td><a href=\"'+t.u+'\" target=\"_blank\"><b>#'+t.id+'</b></a> '+esc(t.n)+(t.new?' <span class=\"badge bg-warning-lt\">NOVO</span>':'')+'</td><td class=\"small\">'+esc(t.r||'—')+'</td><td class=\"small\">'+dmy(t.d)+'</td><td class=\"text-end\"><a class=\"btn btn-sm btn-primary\" href=\"'+t.q+'\">Registrar iniciativa</a></td></tr>'}).join('')+'</tbody></table></div>';document.getElementById('wc-clear').onclick=function(){cur=null;draw()}}"
    . "function draw(){var d=data();S.textContent='('+d.length+' chamado(s))';C.style.display=view==='cloud'?'':'none';ST.style.display=view==='stats'?'':'none';if(view==='cloud')cloud(d);else stats(d);list(d)}"
    . "P.addEventListener('click',function(e){var b=e.target.closest('[data-w]');if(b){pick(b.getAttribute('data-w'));return}var v=e.target.closest('.wc-tabs button');if(v){view=v.getAttribute('data-v');[].forEach.call(P.querySelectorAll('.wc-tabs button'),function(x){x.classList.toggle('on',x===v)});draw()}});"
    . "var rz;window.addEventListener('resize',function(){clearTimeout(rz);rz=setTimeout(function(){if(P.style.display!=='none'&&view==='cloud')draw()},200)});document.getElementById('wc-open').onclick=function(){P.style.display='';draw();P.scrollIntoView({behavior:'smooth',block:'start'})};document.getElementById('wc-close').onclick=function(){P.style.display='none'};NW.onchange=AU.onchange=function(){cur=null;draw()};})();</script>";
echo "<div class='card vg-card mb-3'><div class='card-header'><h3 class='card-title'>$n chamado(s) abertos sem iniciativa <span class='text-secondary small'>(clique para ver o resumo)</span></h3>"
    . "<form class='ms-auto d-flex gap-2' method='get' action='" . $e(Menu::url('/front/request.php')) . "'><input class='form-control form-control-sm' name='ticket' type='number' min='1' placeholder='Nº do chamado' style='width:150px'>"
    . "<button class='btn btn-sm btn-outline-primary'>Abrir</button></form></div><div class='card-body'><div class='vg-table vg-rq'>"
    . ($tkHtml ?: "<div class='text-center text-secondary py-4'>Nenhum chamado aberto aguardando iniciativa.</div>") . "</div></div></div>";
echo $templates;

// ---- formulário
if ($ticket) {
    $tf = $ticket->fields;
    if (isset($linked[$tid])) {
        echo "<div class='alert alert-warning'>Este chamado já está ligado a uma iniciativa: <a href='" . $e(Menu::url('/front/initiative.php?id=' . $linked[$tid])) . "'>abrir</a>. Registrar outra criará uma segunda iniciativa para o mesmo chamado.</div>";
    }
    $req = $DB->request(['SELECT' => ['users_id'], 'FROM' => 'glpi_tickets_users', 'WHERE' => ['tickets_id' => $tid, 'type' => CommonITILActor::REQUESTER], 'LIMIT' => 1])->current();
    $text = trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '</p>', '</div>'], "\n", html_entity_decode((string) $tf['content']))), ENT_QUOTES | ENT_HTML5));
    $dopts = [];
    foreach (Department::all(true) as $d) {
        $dopts[$d['id']] = $d['code'] . ' · ' . $d['name'];
    }
    $hidden = Menu::csrf() . "<input type='hidden' name='do' value='request_save'><input type='hidden' name='tickets_id' value='$tid'><input type='hidden' name='return' value='/front/request.php'>";
    $inner = "<div class='vmo-grid2 vmo-cols4'>" . $field('Título da iniciativa', $input('name', $tf['name'], 'required'), 'required vmo-span2')
        . $field('Solicitante (chamado)', $input('requested_by', $req ? Db::userName((int) $req['users_id']) : ''))
        . $field('Departamento', Menu::select('departments_id', $dopts, '', '', true))
        . $field('Perspectiva', Menu::select('catalogs_id_perspective', Catalog::options('perspective'), '', '', true))
        . $field('Objetivo estratégico', Menu::select('catalogs_id_objective', Catalog::options('objective'), '', '', true), 'vmo-span2')
        . $field('Dono executivo', Menu::select('catalogs_id_owner', Catalog::names('owner'), '', '', true))
        . $field('Líder da iniciativa', Menu::userSelect('users_id_manager', (int) Session::getLoginUserID()))
        . $field('Status de entrega', Menu::select('delivery_stage', Initiative::DELIVERY, 'wip'))
        . $field('Faixa', Menu::select('band', Initiative::BANDS, '', '', true))
        . $field('Prioridade', Menu::select('priority', Initiative::PRIORITIES, 3))
        . "</div><div class='vmo-grid2 vmo-cols2 mt-3'>"
        . $field('Demanda do chamado (vira a oportunidade)', $area('opportunity', $text, 6))
        . $field('Dores / problema', $area('problem', '', 6, 'O que não funciona hoje e o que isso gera: atraso, retrabalho, falhas...'))
        . "</div>";
    echo "<div class='card'><div class='card-header'><h3 class='card-title'>Registrar iniciativa a partir do chamado #" . $tid . "</h3>"
        . "<a class='ms-auto' target='_blank' href='" . $e(Ticket::getFormURLWithID($tid)) . "'>abrir chamado</a></div><div class='card-body'>"
        . "<form method='post' action='" . $e(Menu::url('/front/action.php')) . "'>$hidden$inner<button class='btn btn-primary mt-3'>Registrar na captura e vincular ao chamado</button></form></div></div>";
}
echo Menu::script('drawer.js') . Menu::script('grupos.js');
echo "<script>(function(){var K='vmo_rq_seen',seen={};try{seen=JSON.parse(localStorage.getItem(K)||'{}')}catch(e){}function save(){try{localStorage.setItem(K,JSON.stringify(seen))}catch(e){}}"
    . "document.querySelectorAll('.vg-item.vg-new').forEach(function(el){if(seen[el.dataset.tk]){el.classList.remove('vg-new');var b=el.querySelector('.vg-badge-new');if(b)b.remove();}});"
    . "document.addEventListener('click',function(ev){var el=ev.target.closest('.vg-item.vg-new');if(!el)return;seen[el.dataset.tk]=Date.now();save();el.classList.remove('vg-new');var b=el.querySelector('.vg-badge-new');if(b)b.remove();});})();</script>";
Html::footer();
