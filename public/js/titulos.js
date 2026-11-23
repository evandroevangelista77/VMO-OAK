/**
 * Padrão de títulos do VMO: ícone + título em destaque (como o cartão "O que fazer agora").
 * Acrescenta o ícone a cada título de tela, cartão e painel, escolhido pelas palavras do título.
 */
(() => {
    const MAP = [
        [/fazer agora|brainstorm|oportunidade/i, 'ti-bulb'], [/saúde|saudáve/i, 'ti-heartbeat'], [/funil/i, 'ti-filter'], [/perspectiva/i, 'ti-target-arrow'], [/faixa|composição/i, 'ti-chart-donut-3'],
        [/disciplina|atualiza/i, 'ti-refresh'], [/capacidade|limite|dono executivo/i, 'ti-users'], [/linha do tempo|cronograma/i, 'ti-timeline'], [/atenção|pauta/i, 'ti-flag-3'],
        [/kpi|performance|desempenho|indicador/i, 'ti-gauge'], [/entregas|vazão/i, 'ti-chart-bar'], [/tarefa|atividade/i, 'ti-checklist'], [/custo|lançamento|investimento/i, 'ti-coin'],
        [/comitê|ata/i, 'ti-users-group'], [/decis/i, 'ti-gavel'], [/checkpoint/i, 'ti-flag'], [/prioriza|score/i, 'ti-list-numbers'], [/requisi|chamado/i, 'ti-inbox'],
        [/dashboard|painel|resumo/i, 'ti-layout-dashboard'], [/iniciativa|portf/i, 'ti-briefcase'], [/depend/i, 'ti-link'], [/organiza|departamento|equipe|vínculo/i, 'ti-sitemap'],
        [/hist[óo]rico|auditoria/i, 'ti-history'], [/aviso|pendência|aguardando/i, 'ti-bell'], [/config|parâmetro|governança|pesos|catálogo/i, 'ti-settings'], [/qualidade|dados/i, 'ti-clipboard-check'],
        [/risco/i, 'ti-alert-triangle'], [/relat/i, 'ti-file-analytics'], [/mudar situação|situação/i, 'ti-arrows-exchange'], [/novo|nova|registrar/i, 'ti-plus'],
    ];
    const SELECTORS = ['.vmo-title h2', '.card-header .card-title', '.vmo-panel > h3', '.vmo-panel > .ex-head h3', '.kp-panel-head h3', '#kp-detail h3'];
    function decorate() {
        document.querySelectorAll(SELECTORS.join(',')).forEach((el) => {
            if (el.dataset.vth || el.closest('.oak-drawer')) return;
            el.dataset.vth = '1';
            if (el.querySelector('i.ti')) { el.classList.add('vth'); return; }
            const text = el.textContent || '';
            const hit = MAP.find(([re]) => re.test(text));
            const i = document.createElement('i');
            i.className = `ti ${hit ? hit[1] : 'ti-layout-list'}`;
            el.prepend(i);
            el.classList.add('vth');
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', decorate); else decorate();
    new MutationObserver(decorate).observe(document.documentElement, {childList: true, subtree: true});
})();
