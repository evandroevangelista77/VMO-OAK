/* Roadmap: passar o mouse (ou o foco) em um plugin ou em uma linha da lista destaca as conexões e dependências dele. */
(() => {
    const stage = document.querySelector('.rm-stage');
    if (!stage) { return; }
    const edges = [...stage.querySelectorAll('.rm-edge, .rm-lab')];
    const nodes = [...stage.querySelectorAll('.rm-node')];
    const rows = [...document.querySelectorAll('tr[data-rm-a]')];
    const cap = document.createElement('div');
    cap.className = 'rm-cap';
    cap.hidden = true;
    stage.appendChild(cap);
    const clear = () => {
        cap.hidden = true;
        stage.classList.remove('rm-f');
        edges.forEach((x) => x.classList.remove('on'));
        nodes.forEach((x) => x.classList.remove('rel'));
        rows.forEach((x) => x.classList.remove('on'));
    };
    const show = (match) => {
        clear();
        const rel = new Set();
        edges.forEach((x) => { if (match(x.dataset.a, x.dataset.b)) { x.classList.add('on'); rel.add(x.dataset.a); rel.add(x.dataset.b); } });
        const lines = [];
        rows.forEach((x) => {
            if (match(x.dataset.rmA, x.dataset.rmB)) {
                x.classList.add('on');
                const c = x.cells;
                lines.push('<div><span class="rm-nb">' + c[0].textContent.trim() + '</span><span><b>' + c[1].querySelector('b').textContent + '</b> ' + (c[2].querySelector('i.ti-arrows-left-right') ? '⇄' : '→') + ' <b>' + c[3].querySelector('b').textContent + '</b><br><small>' + c[4].childNodes[0].textContent + '</small></span></div>');
            }
        });
        cap.innerHTML = lines.join('');
        cap.hidden = !lines.length;
        if (!rel.size) { return; }
        stage.classList.add('rm-f');
        nodes.forEach((x) => { if (rel.has(x.dataset.rmId)) { x.classList.add('rel'); } });
    };
    nodes.forEach((n) => {
        const id = n.dataset.rmId;
        ['mouseenter', 'focus'].forEach((ev) => n.addEventListener(ev, () => show((a, b) => a === id || b === id)));
        ['mouseleave', 'blur'].forEach((ev) => n.addEventListener(ev, clear));
    });
    rows.forEach((r) => {
        r.addEventListener('mouseenter', () => show((a, b) => a === r.dataset.rmA && b === r.dataset.rmB));
        r.addEventListener('mouseleave', clear);
    });
})();
