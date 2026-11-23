/** Tabela agrupada: recolhe e expande grupos e a área de ações de cada linha. */
(() => {
    document.addEventListener('click', (ev) => {
        const t = ev.target.closest('.vg-grp-title');
        if (t) {
            const grp = t.closest('.vg-grp');
            const closed = grp.classList.toggle('closed');
            t.setAttribute('aria-expanded', closed ? 'false' : 'true');
            return;
        }
        const m = ev.target.closest('[data-more]');
        if (m) {
            const row = m.closest('.vg-item');
            const open = row.classList.toggle('open');
            m.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
    });
})();
