<?php

namespace GlpiPlugin\Vmo;

/**
 * Book "KPI's VMO" em PDF (A4 paisagem), no layout do book do Marketing: capa com painel azul e índice, sumário (índice de conteúdo),
 * logotipo e faixa de título em todas as páginas, cartões, gráficos vetoriais, tabelas completas, ficha de cada iniciativa, checkpoints,
 * comitê, priorização e glossário. Usa os mesmos números da tela (KpiData) e informa o período dos dados contidos.
 */
class KpiBook
{
    private const NAVY = [15, 23, 42];
    private const BLUE = [37, 99, 235];
    private const MUTED = [100, 116, 139];
    private const LIGHT = [241, 245, 249];
    private const LINE = [203, 213, 225];
    private const W = 273;
    private const COL = ['green' => [34, 197, 94], 'yellow' => [245, 158, 11], 'red' => [239, 68, 68], 'nodata' => [148, 163, 184]];
    private const PILL = ['green' => 'NA META', 'yellow' => 'ATENÇÃO', 'red' => 'CRÍTICO', 'nodata' => 'SEM DADO'];

    private \TCPDF $p;
    private array $d;
    private string $scopeLabel;
    private string $author;
    private ?string $logo = null;
    private float $logoRatio = 1.55;
    private string $logoType = 'PNG';
    private string $periodLabel;
    private array $cards = [];

    public static function render(array $d, string $scopeLabel, string $author): string
    {
        return (new self($d, $scopeLabel, $author))->build();
    }

    private function __construct(array $d, string $scopeLabel, string $author)
    {
        $this->d = $d;
        $this->scopeLabel = $scopeLabel;
        $this->author = $author;
        $bytes = @file_get_contents(__DIR__ . '/../public/img/oak-tree.png');
        if ($bytes !== false && ($info = @getimagesizefromstring($bytes))) {
            $this->logo = $bytes;
            $this->logoRatio = $info[0] / max(1, $info[1]);
        }
        $f = $d['period']['from'];
        $this->periodLabel = ($f ? date('d/m/Y', strtotime($f)) : 'sem datas') . ' a ' . date('d/m/Y', strtotime($d['period']['to']));
        $this->p = new \TCPDF('L', 'mm', 'A4', true, 'UTF-8');
        $this->cards = $this->buildCards();
    }

    // ------------------------------------------------------------------ utilidades

    /** Título do arquivo: "Book VMO - Novembro 2026" (mês do fim do período, limitado à data de corte). */
    public static function title(array $d): string
    {
        $m = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
        // mês do fim do período filtrado, mas nunca depois da data de corte (planos com prazo futuro não mudam o mês)
        $ts = min(strtotime($d['period']['to'] ?? 'now') ?: time(), strtotime($d['today'] ?? 'now') ?: time());
        return 'Book VMO - ' . $m[(int) date('n', $ts) - 1] . ' ' . date('Y', $ts);
    }

    private static function t($v): string
    {
        return str_replace(['→', '≥', '≤', '−', '÷', '×', '—', '…', '•'], ['>', '>=', '<=', '-', '/', 'x', '-', '...', '-'], (string) $v);
    }

    private function num(?float $v, string $suf = '', int $dec = 1): string
    {
        return $v === null ? 'sem dado' : rtrim(rtrim(number_format($v, $dec, ',', '.'), '0'), ',') . $suf;
    }

    private function dt(?string $d): string
    {
        return $d ? date('d/m/Y', strtotime($d)) : '-';
    }

    private static function status(?float $v, string $k): string
    {
        return Kpi::status($v, $k);
    }

    private function txt(float $x, float $y, float $w, string $text, float $size = 9, bool $bold = false, array $color = self::NAVY, string $align = 'L'): void
    {
        $p = $this->p;
        $p->SetFont('helvetica', $bold ? 'B' : '', $size);
        $text = self::t($text);
        if ($p->GetStringWidth($text) > $w) {
            $cut = $text;
            while (mb_strlen($cut) > 1 && $p->GetStringWidth(rtrim($cut) . '...') > $w) {
                $cut = mb_substr($cut, 0, -1);
            }
            $text = rtrim($cut, " ,;:-") . '...';
        }
        $p->SetTextColor(...$color);
        $p->SetXY($x, $y);
        $p->Cell($w, $size * 0.45, $text, 0, 0, $align);
    }

    private function para(float $x, float $y, float $w, string $text, float $size = 9, array $color = self::NAVY, bool $bold = false): float
    {
        $p = $this->p;
        $p->SetFont('helvetica', $bold ? 'B' : '', $size);
        $p->SetTextColor(...$color);
        $p->SetXY($x, $y);
        $p->MultiCell($w, $size * 0.5, self::t($text), 0, 'L', false, 1);
        return $p->GetY();
    }

    private function box(float $x, float $y, float $w, float $h, array $fill = [255, 255, 255], bool $border = true): void
    {
        $p = $this->p;
        $p->SetFillColor(...$fill);
        $p->SetDrawColor(...self::LINE);
        $p->SetLineWidth(0.2);
        $p->RoundedRect($x, $y, $w, $h, 2, '1111', $border ? 'DF' : 'F');
    }

    private function bar(float $x, float $y, float $w, float $h, float $v, float $max, array $c): void
    {
        $p = $this->p;
        $p->SetFillColor(226, 232, 240);
        $p->Rect($x, $y, $w, $h, 'F');
        $f = $max > 0 ? max(0.0, min(1.0, $v / $max)) : 0.0;
        if ($f > 0) {
            $p->SetFillColor(...$c);
            $p->Rect($x, $y, $w * $f, $h, 'F');
        }
    }

    private function dot(float $x, float $y, float $r, array $c): void
    {
        $this->p->SetFillColor(...$c);
        $this->p->Circle($x, $y, $r, 0, 360, 'F');
    }

    // ------------------------------------------------------------------ cabeçalho com logo em todas as páginas

    private function pageHeader(string $title, string $sub = ''): void
    {
        $p = $this->p;
        $p->SetFillColor(...self::NAVY);
        $p->Rect(0, 0, 297, 17, 'F');
        $p->SetFillColor(...self::BLUE);
        $p->Rect(0, 16.3, 297, 0.9, 'F');
        $tx = 12;
        if ($this->logo) {
            $h = 12.5;
            $w = $h * $this->logoRatio;
            $p->Image('@' . $this->logo, 11, 2.2, $w, $h, 'PNG', '', '', false, 200);
            $tx = 11 + $w + 5;
            $p->SetDrawColor(71, 85, 105);
            $p->SetLineWidth(0.3);
            $p->Line($tx - 2.6, 3.8, $tx - 2.6, 13.2);
        }
        $this->txt($tx, 4.6, 165, $title, 13, true, [255, 255, 255]);
        $this->txt(190, 3.4, 95, "KPI's VMO", 9, true, [147, 197, 253], 'R');
        $this->txt(170, 8.4, 115, 'Período dos dados: ' . $this->periodLabel, 7.5, false, [203, 213, 225], 'R');
        if ($sub !== '') {
            $this->txt(12, 19.6, self::W, $sub, 8.5, false, self::MUTED);
        }
        $p->SetY(24);
    }

    private function page(string $title, string $sub = ''): void
    {
        $this->p->AddPage();
        if (!str_contains($title, 'continuação')) {
            $this->p->Bookmark(self::t($title), 0, 0);
        }
        $this->pageHeader($title, $sub);
    }

    private function section(string $t, float $x, float $y): void
    {
        $this->txt($x, $y, 200, $t, 11, true, self::NAVY);
        $this->p->SetFillColor(...self::BLUE);
        $this->p->Rect($x, $y + 6.2, 14, 0.8, 'F');
    }

    /** Caixa "o que mostra / como ler" que abre cada seção, como no book do Marketing. */
    private function explain(float $y, string $what, string $how): float
    {
        $this->box(12, $y, self::W, 15, [239, 246, 255], false);
        $this->p->SetFillColor(...self::BLUE);
        $this->p->Rect(12, $y, 1.4, 15, 'F');
        $this->txt(16, $y + 1.6, 40, 'O QUE MOSTRA', 6.5, true, self::BLUE);
        $this->para(16, $y + 5, 124, $what, 7.8, self::NAVY);
        $this->txt(148, $y + 1.6, 40, 'COMO LER', 6.5, true, self::BLUE);
        $this->para(148, $y + 5, 134, $how, 7.8, self::NAVY);
        return $y + 18;
    }

    private function card(float $x, float $y, float $w, float $h, string $label, string $value, string $sub, string $st): void
    {
        $p = $this->p;
        $c = self::COL[$st];
        $p->SetFillColor(...self::LIGHT);
        $p->RoundedRect($x, $y, $w, $h, 2, '1111', 'F');
        $p->SetFillColor(...$c);
        $p->Rect($x, $y + 2, 1.6, $h - 4, 'F');
        $this->txt($x + 5, $y + 3, $w - 30, mb_strtoupper($label), 7, true, self::MUTED);
        $this->txt($x + 5, $y + 8.5, $w - 8, $value, 15, true, self::NAVY);
        $this->txt($x + 5, $y + 18.5, $w - 8, $sub, 7, false, self::MUTED);
        $this->txt($x + $w - 27, $y + 3, 25, self::PILL[$st], 6.2, true, $c, 'R');
    }

    // ------------------------------------------------------------------ tabela genérica com quebra de página

    /** @param array<int,array{0:string,1:float,2:string}> $cols */
    private function table(array $cols, array $rows, string $title, string $empty = 'Nada a mostrar.', float $y = 0.0, float $rh = 6.3): float
    {
        $p = $this->p;
        $y = $y ?: $p->GetY();
        $head = function (float $y) use ($cols, $p): float {
            $p->SetFillColor(...self::NAVY);
            $p->Rect(12, $y, self::W, 7, 'F');
            $x = 12;
            foreach ($cols as [$label, $w, $al]) {
                $this->txt($x + 1, $y + 1.9, $w - 2, $label, 7.3, true, [255, 255, 255], $al);
                $x += $w;
            }
            return $y + 7;
        };
        $y = $head($y);
        if (!$rows) {
            $this->txt(14, $y + 3, 200, $empty, 8.5, false, self::MUTED);
            return $y + 10;
        }
        foreach ($rows as $i => $vals) {
            if ($y > 188) {
                $this->page($title . ' (continuação)');
                $y = $head(26);
            }
            if ($i % 2 === 1) {
                $p->SetFillColor(...self::LIGHT);
                $p->Rect(12, $y, self::W, $rh, 'F');
            }
            $x = 12;
            foreach ($cols as $k => [$label, $w, $al]) {
                $this->txt($x + 1, $y + 1.7, $w - 2, (string) ($vals[$k] ?? ''), 7.6, false, self::NAVY, $al);
                $x += $w;
            }
            $y += $rh;
        }
        return $y + 3;
    }

    // ------------------------------------------------------------------ dados derivados

    /** @return array<int,array{0:string,1:string,2:string,3:string,4:string}> [rótulo, valor, sub, status, grupo] */
    private function buildCards(): array
    {
        $d = $this->d;
        $K = $d['K'];
        $good = fn (?float $v, array $limits) => $v === null ? 'nodata' : ($v >= $limits[0] ? 'green' : ($v >= $limits[1] ? 'yellow' : 'red'));
        return [
            ['Entrega no prazo', $this->num($K['otd'], '%'), $K['otd_n'] . ' concluídas com datas', self::status($K['otd'], 'otd'), 'Entrega'],
            ['Conclusão', $this->num($K['completion'], '%'), $K['done'] . ' de ' . $K['total'] . ' tarefas', $K['completion'] === null ? 'nodata' : self::status($K['completion'], 'completion'), 'Entrega'],
            ['Atrasadas', $K['overdue_pct'] === null ? 'sem dado' : $K['overdue'] . ' · ' . $this->num($K['overdue_pct'], '%'), $K['overdue_avg'] !== null ? 'atraso médio ' . $this->num($K['overdue_avg'], ' d') : 'nenhuma atrasada', self::status($K['overdue_pct'], 'overdue_pct'), 'Entrega'],
            ['Vencem em 7 dias', (string) $K['due_soon'], 'tarefas abertas', $K['total'] ? ($K['due_soon'] ? 'yellow' : 'green') : 'nodata', 'Entrega'],
            ['Entregas por semana', $this->num($K['throughput'], '/sem'), $K['recent'] . ' concluídas em ' . $d['window'] . ' dias', $K['throughput'] === null ? 'nodata' : 'green', 'Fluxo'],
            ['Tempo de ciclo', $this->num($K['lead'], ' d'), $K['plan_cycle'] !== null ? 'planejado ' . $this->num($K['plan_cycle'], ' d') : 'início ao fim', $K['lead'] === null ? 'nodata' : 'green', 'Fluxo'],
            ['Em andamento (WIP)', (string) $K['doing'], $K['open'] . ' abertas', $K['total'] ? 'green' : 'nodata', 'Fluxo'],
            ['Aderência de esforço', $this->num($K['effort'], '%'), 'horas reais / planejadas', self::status($K['effort'], 'effort'), 'Fluxo'],
            ['Sem responsável', $this->num($K['no_owner'], '%'), 'tarefas abertas', self::status($K['no_owner'], 'no_owner'), 'Qualidade do plano'],
            ['Sem prazo', $this->num($K['no_due'], '%'), 'tarefas abertas', self::status($K['no_due'], 'no_due'), 'Qualidade do plano'],
            ['Marcos no prazo', $this->num($K['ms_otd'], '%'), $K['ms_total'] . ' marcos · ' . $K['ms_late'] . ' atrasados', self::status($K['ms_otd'], 'ms_otd'), 'Qualidade do plano'],
            ['Atualização em dia', $this->num($d['freshPct'], '%'), $d['fresh'] . ' de ' . $d['nRun'] . ' em execução', self::status($d['freshPct'], 'updated'), 'Governança'],
            ['Iniciativas saudáveis', $this->num($d['healthyPct'], '%'), $d['healthy'] . ' de ' . $d['nRun'] . ' em execução', $good($d['healthyPct'], [70, 40]), 'Governança'],
            ['SPI (desempenho de prazo)', $this->num($d['spi'], '', 2), 'real / esperado pelo calendário', self::status($d['spi'], 'spi'), 'Governança'],
            ['Bloqueios abertos', (string) $d['blocked'], 'último checkpoint', $d['nRun'] ? ($d['blocked'] ? 'red' : 'green') : 'nodata', 'Governança'],
        ];
    }

    private function health(array $init): array
    {
        return Committee::farol($init);
    }

    // ------------------------------------------------------------------ montagem

    private function build(): string
    {
        $p = $this->p;
        $p->SetCreator('VMO');
        $p->SetAuthor($this->author);
        $p->SetTitle(self::title($this->d));
        $p->setPrintHeader(false);
        $p->setPrintFooter(false);
        $p->SetMargins(12, 16, 12);
        $p->SetAutoPageBreak(false);

        $this->cover();
        $this->summary();
        $this->portfolio();
        $this->roadmap();
        $this->delivery();
        $this->flow();
        $this->planQuality();
        $this->governance();
        $this->byInitiative();
        $this->byOwner();
        $this->overdueList();
        $this->milestones();
        $this->sheets();
        $this->checkpoints();
        $this->committee();
        $this->priorities();
        $this->glossary();
        // o sumário é criado no fim e o TCPDF o move para a página 2
        $p->addTOCPage();
        $this->pageHeader('Sumário', 'Conteúdo deste book. Cada seção abre com a explicação do que mostra e de como ler.');
        $p->SetY(26);
        $p->endTOCPage();
        $p->SetFont('helvetica', '', 10);
        $p->SetTextColor(...self::NAVY);
        $p->addTOC(2, 'helvetica', '.', 'Sumário', '', self::NAVY);
        $this->footers();
        return $p->Output(self::title($this->d) . '.pdf', 'S');
    }

    private function cover(): void
    {
        $p = $this->p;
        $d = $this->d;
        $p->AddPage();
        $p->SetFillColor(255, 255, 255);
        $p->Rect(0, 0, 297, 210, 'F');
        $p->SetFillColor(...self::NAVY);
        $p->Rect(0, 0, 190, 210, 'F');
        $p->SetFillColor(...self::BLUE);
        $p->Rect(0, 0, 7, 210, 'F');
        $p->SetFillColor(30, 58, 138);
        $p->Rect(190, 0, 107, 210, 'F');
        if ($this->logo) {
            $p->Image('@' . $this->logo, 24, 18, 0, 52, 'PNG', '', '', true, 300);
        }
        $this->txt(24, 84, 160, 'OAKMONT GROUP · VALUE MANAGEMENT OFFICE', 10, true, [148, 163, 184]);
        $this->txt(24, 93, 165, "KPI's VMO", 44, true, [255, 255, 255]);
        $p->SetFillColor(...self::BLUE);
        $p->Rect(24, 114, 44, 1.4, 'F');
        $this->txt(24, 120, 160, 'Book executivo de performance das atividades e das iniciativas:', 13, false, [226, 232, 240]);
        $this->txt(24, 127, 160, 'entrega, fluxo, qualidade do plano, governança e comitê', 13, false, [226, 232, 240]);
        $this->txt(24, 144, 160, 'PERÍODO DOS DADOS', 8, true, [148, 163, 184]);
        $this->txt(24, 150, 160, $this->periodLabel, 20, true, [255, 255, 255]);
        $this->txt(24, 163, 160, $this->scopeLabel . ' · corte em ' . $this->dt($d['today']) . ($d['period']['custom'] ? ' · período filtrado' : ' · todo o histórico disponível'), 9, false, [203, 213, 225]);
        $this->txt(24, 184, 160, 'Gerado em ' . date('d/m/Y H:i') . ' · ' . $this->author, 8.5, false, [203, 213, 225]);
        $this->txt(24, 190, 160, 'Documento confidencial · uso exclusivo da diretoria e do comitê estratégico', 8.5, false, [148, 163, 184]);
        // índice: KPIs na meta entre os que têm dado
        $with = array_filter($this->cards, fn ($c) => $c[3] !== 'nodata');
        $ok = count(array_filter($with, fn ($c) => $c[3] === 'green'));
        $score = $with ? round($ok / count($with) * 100) : null;
        $this->txt(196, 70, 95, 'KPIs NA META', 9, true, [191, 219, 254], 'C');
        $p->SetFillColor(226, 232, 240);
        $p->PieSector(243.5, 130, 36, 0, 180, 'F', true, 180);
        if ($score !== null) {
            $p->SetFillColor(...($score >= 70 ? self::COL['green'] : ($score >= 40 ? self::COL['yellow'] : self::COL['red'])));
            $p->PieSector(243.5, 130, 36, 0, max(1, 180 * $score / 100), 'F', true, 180);
        }
        $p->SetFillColor(30, 58, 138);
        $p->PieSector(243.5, 130, 25, 0, 180, 'F', true, 180);
        $this->txt(196, 108, 95, $score === null ? '-' : $score . '%', 34, true, [255, 255, 255], 'C');
        $this->txt(196, 133, 95, $score === null ? 'sem dados para medir' : $ok . ' de ' . count($with) . ' KPIs com dado', 9, false, [191, 219, 254], 'C');
        $cnt = ['green' => 0, 'yellow' => 0, 'red' => 0, 'nodata' => 0];
        foreach ($this->cards as $c) {
            $cnt[$c[3]]++;
        }
        // leitura dos KPIs: quatro cartões com barra de cor, número grande e rótulo
        $p->SetDrawColor(59, 100, 190);
        $p->SetLineWidth(0.3);
        $p->Line(198, 156, 289, 156);
        $this->txt(196, 159, 95, 'LEITURA DOS ' . count($this->cards) . ' KPIs CONTRA A META', 6.8, true, [191, 219, 254], 'C');
        $x = 197;
        foreach ([['green', 'Na meta'], ['yellow', 'Atenção'], ['red', 'Críticos'], ['nodata', 'Sem dado']] as [$st, $lb]) {
            $p->SetFillColor(44, 82, 170);
            $p->RoundedRect($x, 168, 21.5, 24, 1.8, '1111', 'F');
            $p->SetFillColor(...self::COL[$st]);
            $p->RoundedRect($x, 168, 21.5, 2.2, 1.1, '1100', 'F');
            $this->txt($x, 173.5, 21.5, (string) $cnt[$st], 17, true, [255, 255, 255], 'C');
            $this->txt($x, 184.5, 21.5, mb_strtoupper($lb), 5.8, true, [191, 219, 254], 'C');
            $x += 23.5;
        }
    }

    private function summary(): void
    {
        $d = $this->d;
        $K = $d['K'];
        $this->page('Sumário executivo', 'O que os números dizem, contra as metas de referência, e onde agir primeiro.');
        $y = $this->explain(22, 'Os 15 KPIs de performance, agrupados por tema, com a leitura contra a meta de referência: NA META, ATENÇÃO ou CRÍTICO. Dado ausente aparece como "sem dado", nunca como zero.', 'Comece pelos cartões críticos. Cada tema tem uma seção própria neste book, com a tabela de apoio e o detalhe das tarefas por trás do número.');
        $w = (self::W - 3 * 5) / 4;
        foreach ($this->cards as $i => [$l, $v, $s, $st]) {
            $this->card(12 + ($i % 4) * ($w + 5), $y + intdiv($i, 4) * 26, $w, 22, $l, $v, $s, $st);
        }
        $y += 4 * 26 + 2;
        // leitura automática
        $this->section('Leitura do período', 12, $y);
        $lines = [];
        $lines[] = $d['scope'] ? count($d['scope']) . ' iniciativa(s) no recorte, ' . $d['nRun'] . ' em execução; ' . $K['total'] . ' tarefa(s) ativa(s), das quais ' . $K['done'] . ' concluída(s) e ' . $K['open'] . ' aberta(s).' : 'Nenhuma iniciativa visível no recorte.';
        $lines[] = $K['otd'] !== null ? 'Entrega no prazo de ' . $this->num($K['otd'], '%') . ' (referência: 85% ou mais).' : 'Ainda não há tarefas concluídas com datas para medir a entrega no prazo.';
        $lines[] = $K['overdue'] ? $K['overdue'] . ' tarefa(s) aberta(s) atrasada(s), com atraso médio de ' . $this->num($K['overdue_avg'], ' dias') . '; ' . $K['due_soon'] . ' vence(m) nos próximos 7 dias.' : 'Nenhuma tarefa aberta está atrasada; ' . $K['due_soon'] . ' vence(m) nos próximos 7 dias.';
        $lines[] = $d['spi'] !== null ? 'SPI médio de ' . $this->num($d['spi'], '', 2) . ' nas iniciativas em execução (1,00 = no ritmo do calendário).' : 'SPI indisponível: faltam datas planejadas ou progresso nas iniciativas em execução.';
        $lines[] = $d['blocked'] ? $d['blocked'] . ' iniciativa(s) com bloqueio aberto no último checkpoint.' : 'Nenhum bloqueio aberto declarado nos checkpoints.';
        $lines[] = 'Atualização em dia: ' . $this->num($d['freshPct'], '%') . ' das iniciativas em execução (' . $d['fresh'] . ' de ' . $d['nRun'] . ').';
        $yy = $y + 10;
        foreach ($lines as $l) {
            $this->dot(15, $yy + 1.6, 0.9, self::BLUE);
            $yy = $this->para(19, $yy, 262, $l, 8.8) + 1.6;
        }
    }

    private function portfolio(): void
    {
        $d = $this->d;
        $this->page('Visão do portfólio', 'Como as iniciativas se distribuem por fase, perspectiva, faixa e dono executivo.');
        $y = $this->explain(22, 'Quantas iniciativas há em cada fase do funil, por perspectiva estratégica e faixa, e a carga de cada dono executivo contra o limite de iniciativas em execução.', 'Barras mais longas = mais iniciativas. Donos acima do limite aparecem em vermelho: a capacidade de execução está saturada.');
        $scope = $d['scope'];
        // fases
        $this->section('Iniciativas por fase', 12, $y);
        $cnt = [];
        foreach (Initiative::STATUSES as $k => $st) {
            $cnt[$k] = count(array_filter($scope, fn ($r) => $r['status'] === $k));
        }
        $max = max(1, ...array_values($cnt));
        $yy = $y + 10;
        foreach (Initiative::STATUSES as $k => $st) {
            $this->txt(12, $yy, 52, $st[0], 8, false, self::NAVY);
            $this->bar(66, $yy + 1, 52, 3.4, $cnt[$k], $max, self::rgb($st[1]));
            $this->txt(120, $yy, 12, (string) $cnt[$k], 8, true, self::NAVY, 'R');
            $yy += 6.4;
        }
        // perspectivas
        $this->section('Por perspectiva (em execução / total)', 146, $y);
        $yy = $y + 10;
        $active = array_filter($scope, fn ($r) => !in_array($r['status'], ['closed', 'cancelled', 'rejected'], true));
        $rows = Db::rows('catalogs', ['kind' => 'perspective', 'is_active' => 1], ['code ASC']);
        $pmax = 1;
        foreach ($rows as $pc) {
            $pmax = max($pmax, count(array_filter($active, fn ($r) => (int) $r['catalogs_id_perspective'] === (int) $pc['id'])));
        }
        foreach ($rows as $pc) {
            $mine = array_filter($active, fn ($r) => (int) $r['catalogs_id_perspective'] === (int) $pc['id']);
            $this->txt(146, $yy, 52, $pc['name'], 8, false, self::NAVY);
            $this->bar(200, $yy + 1, 52, 3.4, count($mine), $pmax, self::BLUE);
            $this->txt(254, $yy, 28, count(array_filter($mine, fn ($r) => $r['status'] === 'running')) . ' / ' . count($mine), 8, true, self::NAVY, 'R');
            $yy += 6.4;
        }
        $yy += 4;
        $this->section('Por faixa (em execução / total)', 146, $yy);
        $yy += 10;
        foreach (Initiative::BANDS as $b => $lbl) {
            $mine = array_filter($active, fn ($r) => ($r['band'] ?? '') === $b);
            $this->txt(146, $yy, 52, $lbl, 8, false, self::NAVY);
            $this->bar(200, $yy + 1, 52, 3.4, count($mine), max(1, count($active)), [14, 165, 233]);
            $this->txt(254, $yy, 28, count(array_filter($mine, fn ($r) => $r['status'] === 'running')) . ' / ' . count($mine), 8, true, self::NAVY, 'R');
            $yy += 6.4;
        }
        // dono executivo
        $limit = Committee::wipLimit();
        $owners = [];
        foreach ($scope as $r) {
            if ($r['status'] === 'running') {
                [$ok, $on] = Committee::owner($r);
                $owners[$ok] ??= ['name' => $on, 'n' => 0];
                $owners[$ok]['n']++;
            }
        }
        uasort($owners, fn ($a, $b) => [$b['n'], $a['name']] <=> [$a['n'], $b['name']]);
        $rows2 = [];
        foreach ($owners as $o) {
            $rows2[] = [$o['name'], (string) $o['n'], (string) $limit, $o['n'] > $limit ? 'ACIMA DO LIMITE' : 'OK'];
        }
        $this->p->SetY(max($yy, $y + 10 + 11 * 6.4) + 4);
        $this->section('Limite de trabalho em andamento por dono executivo', 12, $this->p->GetY());
        $this->table([['Dono executivo', 120, 'L'], ['Em execução', 50, 'R'], ['Limite acordado', 50, 'R'], ['Situação', 53, 'L']], $rows2, 'Visão do portfólio', 'Nenhuma iniciativa em execução.', $this->p->GetY() + 9);
    }

    /** Roadmap de integração: todas as iniciativas em volta do núcleo, pelo Status de entrega. */
    private function roadmap(): void
    {
        $p = $this->p;
        $items = Roadmap::items();
        $cnt = array_count_values(array_column($items, 'stage'));
        $this->page('Roadmap de integração', 'Todas as iniciativas ligadas ao núcleo OAK, pelo Status de entrega de cada uma.');
        $y = $this->explain(24, 'Cada círculo é uma iniciativa (menos canceladas e recusadas). O ponto no canto é o Status de entrega: Em produção, Desenvolvido ou Em desenvolvimento. Mesma visão do botão Roadmap do Dashboard.', 'Linha cheia liga iniciativas da mesma perspectiva; tracejada liga perspectivas diferentes; fio fino vai ao núcleo (verde se em produção). Círculo tracejado = em desenvolvimento.');
        $px = 12; $py = $y + 1; $pw = self::W; $ph = 192 - $py;
        $p->SetFillColor(11, 23, 48);
        $p->SetDrawColor(...self::LINE);
        $p->RoundedRect($px, $py, $pw, $ph, 2.5, '1111', 'DF');
        $g = Roadmap::layout($items);
        $sx = ($pw - 6) / $g['W'];
        $sy = ($ph - 14) / $g['H'];
        $k = min($sx, $sy);
        $ox = $px + 3 + (($pw - 6) - $g['W'] * $k) / 2;
        $oy = $py + 10;
        $X = fn (float $x) => $ox + $x * $k;
        $Y = fn (float $y) => $oy + $y * $k;
        $stageCol = ['prod' => [132, 204, 22], 'dev' => [59, 130, 246], 'wip' => [100, 116, 139]];
        // legenda
        $lx = $px + 4;
        foreach (Roadmap::STAGES as $key => [$label]) {
            $this->dot($lx + 1.4, $py + 4.4, 1.4, $stageCol[$key]);
            $this->txt($lx + 4, $py + 2.4, 60, $label . ' (' . (int) ($cnt[$key] ?? 0) . ')', 7, false, [203, 213, 225]);
            $lx += 8 + strlen($label) * 1.5 + 9;
        }
        $this->txt($px + $pw - 94, $py + 2.4, 90, 'Linha cheia: mesma perspectiva · tracejada: entre perspectivas', 6.5, false, [148, 163, 184], 'R');
        // logo no centro
        if ($this->logo) {
            $lw = 26;
            $p->Image('@' . $this->logo, $X($g['C'][0]) - $lw / 2, $Y($g['C'][1]) - $lw / $this->logoRatio / 2 - 3, $lw, $lw / $this->logoRatio, 'PNG', '', '', false, 200);
        }
        $this->txt($X($g['C'][0]) - 25, $Y($g['C'][1]) + 6.5, 50, 'OAKMONT GROUP', 8, true, [226, 232, 240], 'C');
        $byId = [];
        foreach ($items as $it) {
            $byId[$it['id']] = $it;
        }
        // ligações (primeiro, para ficarem atrás dos círculos)
        foreach ($g['links'] as [$a, $b, $kind]) {
            [$x1, $y1] = [$X($g['pos'][$a][0]), $Y($g['pos'][$a][1])];
            [$x2, $y2] = $b === null ? [$X($g['C'][0]), $Y($g['C'][1])] : [$X($g['pos'][$b][0]), $Y($g['pos'][$b][1])];
            if ($kind === 'area') {
                $p->SetDrawColor(...Roadmap::rgb($byId[$a]['color']));
                $p->SetLineStyle(['width' => 0.35, 'dash' => 0]);
            } elseif ($kind === 'bridge') {
                $p->SetDrawColor(120, 134, 156);
                $p->SetLineStyle(['width' => 0.2, 'dash' => '1.2,1']);
            } elseif ($kind === 'spoke-prod') {
                $p->SetDrawColor(110, 160, 40);
                $p->SetLineStyle(['width' => 0.15, 'dash' => 0]);
            } else {
                $p->SetDrawColor(45, 62, 96);
                $p->SetLineStyle(['width' => 0.12, 'dash' => 0]);
            }
            $p->Line($x1, $y1, $x2, $y2);
        }
        $p->SetLineStyle(['width' => 0.2, 'dash' => 0]);
        // nós
        $r = 4.6;
        foreach ($items as $it) {
            $cx = $X($g['pos'][$it['id']][0]);
            $cy = $Y($g['pos'][$it['id']][1]);
            $c = Roadmap::rgb($it['color']);
            $tint = [(int) (11 + ($c[0] - 11) * .2), (int) (23 + ($c[1] - 23) * .2), (int) (48 + ($c[2] - 48) * .2)];
            if ($it['stage'] === 'prod') {
                $p->SetDrawColor(...array_map(fn ($v, $b) => (int) ($b + ($v - $b) * .35), $c, [11, 23, 48]));
                $p->SetLineStyle(['width' => 0.9, 'dash' => 0]);
                $p->Circle($cx, $cy, $r + 0.9, 0, 360, 'D');
            }
            $p->SetFillColor(...$tint);
            $p->SetDrawColor(...$c);
            $p->SetLineStyle(['width' => 0.4, 'dash' => $it['stage'] === 'wip' ? '1,0.8' : 0]);
            $p->Circle($cx, $cy, $r, 0, 360, 'DF');
            $p->SetLineStyle(['width' => 0.2, 'dash' => 0]);
            $this->txt($cx - $r, $cy - 1.7, $r * 2, substr($it['code'], -2) === '' ? '' : ltrim(substr($it['code'], 4), '0'), 7.5, true, $c, 'C');
            $p->SetFillColor(...$stageCol[$it['stage']]);
            $p->SetDrawColor(11, 23, 48);
            $p->SetLineWidth(0.4);
            $p->Circle($cx + $r * .78, $cy - $r * .78, 1.35, 0, 360, 'DF');
            $p->SetLineWidth(0.2);
            // nome abaixo, em até duas linhas
            $lines = explode("\n", wordwrap(self::t($it['name']), 17, "\n", true));
            $lines = array_slice($lines, 0, 3);
            foreach ($lines as $i => $ln) {
                $this->txt($cx - 14, $cy + $r + 0.9 + $i * 2.7, 28, $ln, 5.6, true, [226, 232, 240], 'C');
            }
        }
        $this->txt($px + $pw - 94, $py + $ph - 5, 90, 'Número no círculo = código da iniciativa (INI-00xx)', 6.2, false, [148, 163, 184], 'R');
    }

    private function delivery(): void
    {
        $d = $this->d;
        $K = $d['K'];
        $this->page('Entrega', 'Quanto do trabalho foi concluído e se foi no prazo.');
        $y = $this->explain(22, 'Entrega no prazo (concluídas até a data planejada), conclusão geral, tarefas atrasadas e as que vencem em 7 dias.', 'Entrega no prazo acima de 85% é a referência. Atrasadas acima de 25% das abertas é crítico; o atraso médio mostra a gravidade.');
        $w = (self::W - 3 * 5) / 4;
        foreach (array_slice($this->cards, 0, 4) as $i => [$l, $v, $s, $st]) {
            $this->card(12 + $i * ($w + 5), $y, $w, 22, $l, $v, $s, $st);
        }
        $this->section('Entrega no prazo por iniciativa (percentual)', 12, $y + 28);
        $rank = array_values(array_filter($d['rows'], fn ($r) => $r['k']['otd'] !== null));
        usort($rank, fn ($a, $b) => $a['k']['otd'] <=> $b['k']['otd']);
        $yy = $y + 38;
        foreach (array_slice($rank, 0, 12) as $r) {
            $this->txt(12, $yy, 90, $r['ini']['code'] . ' ' . $r['ini']['name'], 8, false, self::NAVY);
            $this->bar(106, $yy + 1, 110, 3.4, $r['k']['otd'], 100, self::COL[self::status($r['k']['otd'], 'otd')]);
            $this->txt(220, $yy, 16, number_format($r['k']['otd'], 0) . '%', 8, true, self::NAVY, 'R');
            $this->txt(240, $yy, 42, $r['k']['otd_n'] . ' concluída(s) com datas', 7, false, self::MUTED);
            $yy += 6.4;
        }
        if (!$rank) {
            $this->txt(12, $yy, 250, 'Sem tarefas concluídas com datas no período: o percentual de entrega no prazo ainda não pode ser medido.', 8.5, false, self::MUTED);
        }
    }

    private function flow(): void
    {
        $d = $this->d;
        $this->page('Fluxo', 'Ritmo de entrega, tempo de ciclo, trabalho em andamento e aderência de esforço.');
        $y = $this->explain(22, 'Entregas por semana (tarefas concluídas em cada semana), tempo de ciclo real contra o planejado, tarefas em andamento (WIP) e horas realizadas contra as planejadas.', 'Barras estáveis indicam ritmo previsível. Ciclo muito acima do planejado e esforço acima de 110% sinalizam estimativas otimistas ou retrabalho.');
        $w = (self::W - 3 * 5) / 4;
        foreach (array_slice($this->cards, 4, 4) as $i => [$l, $v, $s, $st]) {
            $this->card(12 + $i * ($w + 5), $y, $w, 22, $l, $v, $s, $st);
        }
        $p = $this->p;
        $this->section('Entregas por semana (últimas 8 semanas)', 12, $y + 28);
        $weekly = $d['weekly'];
        $max = max(1, ...array_column($weekly, 'done'));
        $x0 = 18;
        $y0 = $y + 80;
        foreach ($weekly as $k => $r) {
            $x = $x0 + $k * 17;
            $hOk = ($r['done'] - $r['late']) / $max * 34;
            $hLate = $r['late'] / $max * 34;
            $p->SetFillColor(99, 102, 241);
            $p->Rect($x, $y0 - $hOk, 11, $hOk, 'F');
            $p->SetFillColor(245, 158, 11);
            $p->Rect($x, $y0 - $hOk - $hLate, 11, $hLate, 'F');
            $this->txt($x - 2, $y0 - $hOk - $hLate - 4.5, 15, $r['done'] ? (string) $r['done'] : '', 7.5, true, self::NAVY, 'C');
            $this->txt($x - 2, $y0 + 1.2, 15, $r['label'], 6.5, false, self::MUTED, 'C');
        }
        $p->SetDrawColor(...self::LINE);
        $p->Line($x0 - 2, $y0, $x0 + 8 * 17, $y0);
        $this->txt(18, $y0 + 7, 130, 'Azul: concluídas no prazo · Laranja: concluídas com atraso', 7, false, self::MUTED);
        $this->section('Composição das tarefas', 170, $y + 28);
        $leaf = Kpi::leaves($d['tasks']);
        $sc = ['todo' => 0, 'doing' => 0, 'done' => 0];
        foreach ($leaf as $t) {
            $sc[$t['status']]++;
        }
        $tot = max(1, array_sum($sc));
        $yy = $y + 40;
        foreach ($sc as $st => $n) {
            $this->txt(170, $yy, 30, Task::STATUSES[$st][0], 8.5, false, self::NAVY);
            $this->bar(202, $yy + 1, 56, 3.6, $n, $tot, self::rgb(Task::STATUSES[$st][1]));
            $this->txt(260, $yy, 22, $n . ' (' . round($n / $tot * 100) . '%)', 8, true, self::NAVY, 'R');
            $yy += 8;
        }
        $K = $d['K'];
        $this->txt(170, $yy + 4, 112, 'Tempo de ciclo médio: ' . $this->num($K['lead'], ' dias') . ' · planejado: ' . $this->num($K['plan_cycle'], ' dias'), 8.5, false, self::NAVY);
        $this->txt(170, $yy + 11, 112, 'Desvio do ciclo: ' . ($K['cycle_dev'] === null ? 'sem dado' : ($K['cycle_dev'] > 0 ? '+' : '') . $this->num($K['cycle_dev'], '%')) . ' · aderência de esforço: ' . $this->num($K['effort'], '%'), 8.5, false, self::NAVY);
    }

    private function planQuality(): void
    {
        $d = $this->d;
        $this->page('Qualidade do plano', 'Tarefas sem dono ou sem prazo não podem ser cobradas nem medidas.');
        $y = $this->explain(22, 'Percentual de tarefas abertas sem responsável e sem data final, e marcos (entregas-chave) concluídos no prazo.', 'Quanto menor o percentual sem dono e sem prazo, mais confiável é todo o resto dos KPIs. Marcos atrasados pedem ação imediata.');
        $w = (self::W - 2 * 5) / 3;
        foreach (array_slice($this->cards, 8, 3) as $i => [$l, $v, $s, $st]) {
            $this->card(12 + $i * ($w + 5), $y, $w, 22, $l, $v, $s, $st);
        }
        $leaf = Kpi::leaves($d['tasks']);
        $open = array_filter($leaf, fn ($t) => in_array($t['status'], ['todo', 'doing'], true));
        $iniCode = [];
        foreach ($d['scope'] as $r) {
            $iniCode[(int) $r['id']] = $r['code'];
        }
        $rows = [];
        foreach ($open as $t) {
            $why = [];
            if ((int) $t['users_id_responsible'] === 0) {
                $why[] = 'sem responsável';
            }
            if (empty($t['planned_end'])) {
                $why[] = 'sem prazo';
            }
            if ($why) {
                $rows[] = [$iniCode[(int) $t['initiatives_id']] ?? '', $t['name'], implode(' e ', $why), Task::STATUSES[$t['status']][0]];
            }
        }
        $this->section('Tarefas abertas com lacunas de plano', 12, $y + 28);
        $this->table([['Iniciativa', 40, 'L'], ['Tarefa', 140, 'L'], ['Lacuna', 55, 'L'], ['Situação', 38, 'L']], $rows, 'Qualidade do plano', 'Nenhuma lacuna encontrada.', $y + 38);
    }

    private function governance(): void
    {
        $d = $this->d;
        $this->page('Governança das iniciativas em execução', 'Atualização, saúde, SPI e bloqueios.');
        $y = $this->explain(22, 'Quantas iniciativas em execução têm checkpoint recente, quantas estão saudáveis, o SPI médio e os bloqueios abertos.', 'SPI = progresso real ÷ progresso esperado pelo calendário. 1,00 = no ritmo; abaixo de 0,90 = atrás do plano; acima de 1,00 = adiantado.');
        $w = (self::W - 3 * 5) / 4;
        foreach (array_slice($this->cards, 11, 4) as $i => [$l, $v, $s, $st]) {
            $this->card(12 + $i * ($w + 5), $y, $w, 22, $l, $v, $s, $st);
        }
        $rows = [];
        foreach ($d['rows'] as $r) {
            if ($r['ini']['status'] !== 'running') {
                continue;
            }
            $f = $this->health($r['ini']);
            $rows[] = [$r['ini']['code'] . ' ' . $r['ini']['name'], $f['label'], ($f['days'] ?? '-') . ' d', $f['delay'] ? '+' . $f['delay'] . ' d' : '-', $f['block'] ? 'SIM' : '-', $r['progress'] === null ? '-' : number_format($r['progress'], 0) . '%', $r['spi'] === null ? '-' : number_format($r['spi'], 2, ',', '.')];
        }
        $this->section('Saúde e SPI por iniciativa em execução', 12, $y + 28);
        $this->table([['Iniciativa', 108, 'L'], ['Saúde', 30, 'L'], ['Sem atualização', 32, 'R'], ['Marco atrasado', 32, 'R'], ['Bloqueio', 22, 'C'], ['Progresso', 24, 'R'], ['SPI', 25, 'R']], $rows, 'Governança das iniciativas', 'Nenhuma iniciativa em execução.', $y + 38);
    }

    private function byInitiative(): void
    {
        $this->page('Performance por iniciativa', 'Progresso, tarefas, entrega no prazo, atrasos e SPI de cada iniciativa.');
        $rows = [];
        foreach ($this->d['rows'] as $r) {
            $k = $r['k'];
            $rows[] = [$r['ini']['code'] . ' ' . $r['ini']['name'], preg_replace('/^\d+\.\s*/', '', Initiative::STATUSES[$r['ini']['status']][0]), $r['progress'] === null ? '-' : number_format($r['progress'], 0) . '%',
                $k['done'] . '/' . $k['total'], (string) $k['doing'], $k['otd'] === null ? '-' : number_format($k['otd'], 0) . '%', (string) $k['overdue'], $this->num($k['lead'], ' d'), $r['spi'] === null ? '-' : number_format($r['spi'], 2, ',', '.')];
        }
        $this->table([['Iniciativa', 96, 'L'], ['Situação', 32, 'L'], ['Progresso', 20, 'R'], ['Concluídas', 22, 'R'], ['Andam.', 16, 'R'], ['No prazo', 20, 'R'], ['Atras.', 16, 'R'], ['Ciclo', 25, 'R'], ['SPI', 26, 'R']], $rows, 'Performance por iniciativa', 'Nenhuma iniciativa visível.', 26);
    }

    private function byOwner(): void
    {
        $this->page('Performance por responsável', 'Carga e entrega das tarefas ativas de cada pessoa.');
        $rows = [];
        foreach ($this->d['owners'] as $o) {
            $rows[] = [$o['user'] ? Db::userName($o['user']) : 'Sem responsável', (string) $o['open'], (string) $o['late'], (string) $o['done'], $o['otd'] === null ? '-' : number_format($o['otd'], 0) . '%'];
        }
        $this->table([['Responsável', 100, 'L'], ['Abertas', 40, 'R'], ['Atrasadas', 40, 'R'], ['Concluídas', 45, 'R'], ['Entrega no prazo', 48, 'R']], $rows, 'Performance por responsável', 'Nenhuma tarefa cadastrada ainda.', 26);
    }

    private function overdueList(): void
    {
        $d = $this->d;
        $today = $d['today'];
        $this->page('Tarefas atrasadas e a vencer', 'Lista completa das tarefas abertas com prazo vencido e das que vencem em 7 dias.');
        $iniCode = [];
        foreach ($d['scope'] as $r) {
            $iniCode[(int) $r['id']] = $r['code'];
        }
        $open = array_filter(Kpi::leaves($d['tasks']), fn ($t) => in_array($t['status'], ['todo', 'doing'], true));
        $late = array_values(array_filter($open, fn ($t) => $t['planned_end'] && $t['planned_end'] < $today));
        usort($late, fn ($a, $b) => strcmp($a['planned_end'], $b['planned_end']));
        $rows = array_map(fn ($t) => [$iniCode[(int) $t['initiatives_id']] ?? '', $t['name'], $t['users_id_responsible'] ? Db::userName((int) $t['users_id_responsible']) : '-', $this->dt($t['planned_end']),
            (int) round((strtotime($today) - strtotime($t['planned_end'])) / 86400) . ' d', Task::STATUSES[$t['status']][0]], $late);
        $this->section('Atrasadas (' . count($late) . ')', 12, 26);
        $y = $this->table([['Iniciativa', 32, 'L'], ['Tarefa', 120, 'L'], ['Responsável', 50, 'L'], ['Prazo', 24, 'R'], ['Atraso', 22, 'R'], ['Situação', 25, 'L']], $rows, 'Tarefas atrasadas e a vencer', 'Nenhuma tarefa atrasada.', 35);
        $soon = array_values(array_filter($open, fn ($t) => $t['planned_end'] && $t['planned_end'] >= $today && $t['planned_end'] <= date('Y-m-d', strtotime($today . ' +7 days'))));
        $rows = array_map(fn ($t) => [$iniCode[(int) $t['initiatives_id']] ?? '', $t['name'], $t['users_id_responsible'] ? Db::userName((int) $t['users_id_responsible']) : '-', $this->dt($t['planned_end']), Task::STATUSES[$t['status']][0]], $soon);
        if ($y > 150) {
            $this->page('Tarefas atrasadas e a vencer (continuação)');
            $y = 26;
        }
        $this->section('Vencem em 7 dias (' . count($soon) . ')', 12, $y);
        $this->table([['Iniciativa', 32, 'L'], ['Tarefa', 142, 'L'], ['Responsável', 50, 'L'], ['Prazo', 24, 'R'], ['Situação', 25, 'L']], $rows, 'Tarefas atrasadas e a vencer', 'Nenhuma tarefa vence nos próximos 7 dias.', $y + 9);
    }

    private function milestones(): void
    {
        $d = $this->d;
        $this->page('Marcos', 'Entregas-chave do plano: concluídos, abertos e atrasados.');
        $iniCode = [];
        foreach ($d['scope'] as $r) {
            $iniCode[(int) $r['id']] = $r['code'];
        }
        $ms = array_values(array_filter(Kpi::leaves($d['tasks']), fn ($t) => !empty($t['is_milestone'])));
        usort($ms, fn ($a, $b) => strcmp((string) $a['planned_end'], (string) $b['planned_end']));
        $rows = array_map(function ($t) use ($iniCode, $d) {
            $res = $t['status'] === 'done' ? ($t['actual_end'] && $t['planned_end'] ? ($t['actual_end'] <= $t['planned_end'] ? 'No prazo' : 'Com atraso') : 'Concluído') : ($t['planned_end'] && $t['planned_end'] < $d['today'] ? 'ATRASADO' : 'No plano');
            return [$iniCode[(int) $t['initiatives_id']] ?? '', $t['name'], $t['users_id_responsible'] ? Db::userName((int) $t['users_id_responsible']) : '-', $this->dt($t['planned_end']), $this->dt($t['actual_end']), $res];
        }, $ms);
        $this->table([['Iniciativa', 32, 'L'], ['Marco', 120, 'L'], ['Responsável', 50, 'L'], ['Previsto', 24, 'R'], ['Realizado', 24, 'R'], ['Resultado', 23, 'L']], $rows, 'Marcos', 'Nenhum marco cadastrado nas tarefas.', 26);
    }

    /** Ficha de cada iniciativa: duas por página. */
    private function sheets(): void
    {
        $rows = $this->d['rows'];
        if (!$rows) {
            return;
        }
        $cps = [];
        foreach ($this->d['checkpoints'] as $c) {
            $cps[(int) $c['initiatives_id']][] = $c;
        }
        foreach (array_chunk($rows, 2) as $n => $pair) {
            $this->page('Fichas das iniciativas' . ($n ? ' (continuação)' : ''), 'Dados estratégicos, prazos, priorização, saúde e tarefas de cada iniciativa.');
            foreach ($pair as $i => $r) {
                $this->sheet($r, $cps[(int) $r['ini']['id']] ?? [], 24 + $i * 86);
            }
        }
    }

    private function sheet(array $r, array $cps, float $y): void
    {
        $ini = $r['ini'];
        $k = $r['k'];
        $f = $this->health($ini);
        $this->box(12, $y, self::W, 82, [255, 255, 255]);
        $this->p->SetFillColor(...self::BLUE);
        $this->p->Rect(12, $y, 1.8, 82, 'F');
        $this->txt(17, $y + 2.5, 190, $ini['code'] . ' · ' . $ini['name'], 10.5, true, self::NAVY);
        $this->txt(212, $y + 2.8, 68, preg_replace('/^\d+\.\s*/', '', Initiative::STATUSES[$ini['status']][0]) . ($f['key'] ? ' · ' . $f['label'] : ''), 8, true, $f['key'] ? self::rgb($f['color']) : self::MUTED, 'R');
        $score = Initiative::score($ini);
        $kv = [
            ['Perspectiva', $ini['catalogs_id_perspective'] ? Catalog::name((int) $ini['catalogs_id_perspective']) : '-'], ['Objetivo estratégico', $ini['catalogs_id_objective'] ? Catalog::name((int) $ini['catalogs_id_objective']) : '-'],
            ['Faixa', $ini['band'] ? Initiative::BANDS[$ini['band']] : '-'], ['Meta', $ini['target_value'] ?: '-'],
            ['Status de entrega', Initiative::DELIVERY[$ini['delivery_stage'] ?? 'wip'] ?? '-'], ['Prioridade', Initiative::PRIORITIES[(int) $ini['priority']] ?? '-'],
            ['Tipo de benefício', $ini['benefit_type'] ? Initiative::BENEFIT_TYPES[$ini['benefit_type']] : '-'], ['Benefício declarado', $ini['benefit_declared'] ?: '-'],
            ['Dono executivo', Committee::owner($ini)[1]], ['Líder', $ini['users_id_manager'] ? Db::userName((int) $ini['users_id_manager']) : '-'],
            ['Áreas envolvidas', $ini['areas'] ?: '-'], ['Solicitante', $ini['requested_by'] ?: '-'],
            ['Início planejado', $this->dt($ini['planned_start'])], ['Término planejado', $this->dt($ini['planned_end'])],
            ['Previsão de término', $this->dt($ini['forecast_end'])], ['Início real', $this->dt($ini['actual_start'])],
        ];
        foreach ($kv as $i => [$l, $v]) {
            $col = $i % 2;
            $row = intdiv($i, 2);
            $x = 17 + $col * 125;
            $this->txt($x, $y + 10 + $row * 6.1, 34, mb_strtoupper($l), 6, true, self::MUTED);
            $this->txt($x + 35, $y + 9.7 + $row * 6.1, 85, $v, 7.8, false, self::NAVY);
        }
        // coluna direita: priorização, tarefas e progresso
        $x = 17;
        $yy = $y + 60;
        $this->txt($x, $yy, 40, 'PRIORIZAÇÃO', 6.5, true, self::BLUE);
        $this->txt($x, $yy + 5, 70, $score === null ? 'Notas incompletas (score não calculado)' : 'Score ' . number_format($score, 1, ',', '') . ' · prioridade ' . Initiative::scoreBand($score), 8, true, self::NAVY);
        $parts = [];
        foreach (Initiative::CRITERIA as $fld => $c) {
            $parts[] = $c[0] . ': ' . ($ini[$fld] ?: '-');
        }
        $this->txt($x, $yy + 10.5, 120, implode(' · ', $parts), 6.6, false, self::MUTED);
        $this->txt($x + 125, $yy, 40, 'TAREFAS E DESEMPENHO', 6.5, true, self::BLUE);
        $this->txt($x + 125, $yy + 5, 130, $k['done'] . ' de ' . $k['total'] . ' concluídas · ' . $k['doing'] . ' em andamento · ' . $k['overdue'] . ' atrasadas · no prazo: ' . $this->num($k['otd'], '%'), 8, false, self::NAVY);
        $this->bar($x + 125, $yy + 11.5, 90, 3.2, (float) ($r['progress'] ?? 0), 100, [99, 102, 241]);
        $this->txt($x + 217, $yy + 10.6, 40, ($r['progress'] === null ? 'sem dado' : number_format($r['progress'], 0) . '%') . ' · SPI ' . ($r['spi'] === null ? '-' : number_format($r['spi'], 2, ',', '.')), 7.5, true, self::NAVY);
        $c = $cps[0] ?? null;
        $this->txt($x, $yy + 16, 255, $c ? 'Último checkpoint (' . $this->dt($c['checkpoint_date']) . '): ' . (($c['advanced'] ?: 'sem texto') . ($c['has_block'] ? ' | BLOQUEIO: ' . ($c['blocker'] ?: 'declarado') : '')) : 'Sem checkpoint registrado.', 7, false, self::MUTED);
    }

    private function checkpoints(): void
    {
        $this->page('Checkpoints quinzenais', 'Memória do portfólio: o que avançou, bloqueios e ações necessárias (mais recentes primeiro).');
        $code = [];
        foreach ($this->d['scope'] as $r) {
            $code[(int) $r['id']] = $r['code'];
        }
        $rows = [];
        foreach (array_slice($this->d['checkpoints'], 0, 60) as $c) {
            $rows[] = [$this->dt($c['checkpoint_date']), $code[(int) $c['initiatives_id']] ?? '', $c['users_id'] ? Db::userName((int) $c['users_id']) : '-', $c['progress'] === null ? '-' : number_format((float) $c['progress'], 0) . '%',
                $c['farol_declared'] ? Committee::SIGNALS[$c['farol_declared']] : '-', $c['advanced'] ?: '-', ($c['has_block'] ? '[BLOQ] ' : '') . ($c['blocker'] ?: '-'), $c['action_needed'] ?: '-', $c['needs_committee'] ? 'Sim' : 'Não'];
        }
        $this->table([['Data', 22, 'L'], ['Iniciativa', 24, 'L'], ['Quem', 30, 'L'], ['Avanço', 16, 'R'], ['Saúde decl.', 22, 'L'], ['O que avançou', 62, 'L'], ['Bloqueio ou risco', 44, 'L'], ['Ação', 37, 'L'], ['Comitê', 16, 'C']], $rows, 'Checkpoints quinzenais', 'Nenhum checkpoint registrado no período.', 26);
    }

    private function committee(): void
    {
        $this->page('Comitê estratégico', 'Decisões da ata e atas anexadas.');
        $ids = array_keys($this->d['scope']);
        $code = [];
        foreach ($this->d['scope'] as $r) {
            $code[(int) $r['id']] = $r['code'];
        }
        $dec = Committee::decisions($ids);
        $rows = array_map(fn ($x) => [$this->dt($x['committee_date'] ?: substr((string) $x['decided_at'], 0, 10)), $x['initiatives_id'] ? ($code[(int) $x['initiatives_id']] ?? '') : 'Tema geral', $x['title'],
            $x['decision_kind'] ? (Committee::DECISIONS[$x['decision_kind']] ?? '') : '-', $x['users_id_owner'] ? Db::userName((int) $x['users_id_owner']) : '-', $this->dt($x['due_date']), $x['is_done'] ? 'Concluída' : 'Em aberto'], array_slice($dec, 0, 40));
        $this->section('Ata de decisões (' . count($dec) . ')', 12, 26);
        $y = $this->table([['Data', 24, 'L'], ['Iniciativa', 30, 'L'], ['Decisão', 100, 'L'], ['Tipo', 30, 'L'], ['Responsável', 40, 'L'], ['Prazo', 24, 'R'], ['Situação', 25, 'L']], $rows, 'Comitê estratégico', 'Nenhuma decisão registrada.', 35);
        $mins = Committee::minutes();
        if ($y > 160) {
            $this->page('Comitê estratégico (continuação)');
            $y = 26;
        }
        $this->section('Atas anexadas (' . count($mins) . ')', 12, $y);
        $rows = array_map(fn ($m) => [$this->dt($m['meeting_date']), $m['title'], $m['original_name'], number_format($m['size'] / 1024, 0, ',', '.') . ' KB', $m['users_id'] ? Db::userName((int) $m['users_id']) : '-'], array_slice($mins, 0, 30));
        $this->table([['Reunião', 26, 'L'], ['Ata', 110, 'L'], ['Arquivo', 70, 'L'], ['Tamanho', 25, 'R'], ['Enviada por', 42, 'L']], $rows, 'Comitê estratégico', 'Nenhuma ata anexada.', $y + 9);
    }

    private function priorities(): void
    {
        $this->page('Priorização', 'Score por iniciativa: média ponderada das 5 notas (1, 3 ou 5) e o filtro de renúncia.');
        $w = Settings::priorityWeights();
        $lab = [];
        foreach (Initiative::CRITERIA as $f => $c) {
            $lab[] = $c[0] . ' ' . rtrim(rtrim(number_format($w[$f], 1, ',', ''), '0'), ',') . '%';
        }
        $this->txt(12, 24, self::W, 'Pesos: ' . implode(' · ', $lab) . ' · 70 a 100: prioridade alta · 40 a 69: média · abaixo de 40: baixa', 8, false, self::MUTED);
        $rank = [];
        foreach ($this->d['scope'] as $r) {
            $rank[] = [$r, Initiative::score($r)];
        }
        usort($rank, fn ($a, $b) => ($b[1] ?? -1) <=> ($a[1] ?? -1));
        $rows = array_map(fn ($x) => [$x[0]['code'] . ' ' . $x[0]['name'], $x[1] === null ? '-' : number_format($x[1], 1, ',', ''), Initiative::scoreBand($x[1]), (string) ($x[0]['sc_strategic'] ?: '-'), (string) ($x[0]['sc_impact'] ?: '-'),
            (string) ($x[0]['sc_window'] ?: '-'), (string) ($x[0]['sc_feasibility'] ?: '-'), (string) ($x[0]['sc_risk'] ?: '-'), ($x[0]['waiver'] ?? '') !== '' ? 'SIM' : 'Não'], $rank);
        $this->table([['Iniciativa', 108, 'L'], ['Score', 20, 'R'], ['Prioridade', 28, 'L'], ['Valor', 15, 'C'], ['Impacto', 17, 'C'], ['Janela', 16, 'C'], ['Viab.', 15, 'C'], ['Risco', 15, 'C'], ['Renúncia', 39, 'C']], $rows, 'Priorização', 'Nenhuma iniciativa.', 30);
    }

    private function glossary(): void
    {
        $this->page('Glossário e metodologia', 'O que cada KPI mede, como é calculado e a meta de referência.');
        $p = $this->p;
        $y = 24;
        foreach (KpiData::GLOSSARY as $g) {
            if ($y > 178) {
                $this->page('Glossário e metodologia (continuação)');
                $y = 24;
            }
            $this->txt(12, $y, 66, $g[0], 8.5, true, self::NAVY);
            $yy = $this->para(80, $y, 150, $g[1] . ' Cálculo: ' . $g[2] . '.', 8, self::MUTED);
            $this->txt(236, $y, 49, 'Ref.: ' . $g[3], 7.5, true, self::BLUE, 'R');
            $y = max($yy, $y + 5) + 2.5;
            $p->SetDrawColor(...self::LINE);
            $p->Line(12, $y - 1, 285, $y - 1);
        }
        $y += 3;
        if ($y > 150) {
            $this->page('Glossário e metodologia (continuação)');
            $y = 24;
        }
        $this->section('Como o SPI é calculado (exemplo)', 12, $y);
        $this->para(12, $y + 10, 270, 'Exemplo: uma iniciativa planejada de 01/09 a 30/11 (91 dias). Em 15/10 já passaram 44 dias, ou 48% do prazo. Se o progresso real é 40%, o SPI = 0,40 ÷ 0,48 = 0,83 (atrás do plano). Se fosse 55%, SPI = 1,15 (adiantada). O book calcula o SPI de cada iniciativa em execução e mostra a média. Iniciativas sem datas planejadas ou que ainda não começaram ficam sem SPI.', 8.5, self::NAVY);
        $this->para(12, $y + 30, 270, 'Período dos dados: ' . $this->periodLabel . '. O período vai da menor à maior data encontrada nas tarefas, iniciativas e checkpoints do recorte (ou do filtro De/Até, aplicado ao fim real ou, se aberta, ao fim planejado da tarefa). O corte é a data de geração; "dias sem atualização" e "atrasada" contam até essa data. Tarefas canceladas e tarefas-resumo não entram nos KPIs.', 8.5, self::MUTED);
    }

    private function footers(): void
    {
        $p = $this->p;
        $n = $p->getNumPages();
        for ($i = 2; $i <= $n; $i++) {
            $p->setPage($i);
            $p->SetDrawColor(...self::LINE);
            $p->SetLineWidth(0.2);
            $p->Line(12, 198, 285, 198);
            $this->txt(12, 199.8, 215, "KPI's VMO · Oakmont Group · " . $this->periodLabel . ' · ' . $this->scopeLabel . ' · confidencial · gerado em ' . date('d/m/Y H:i'), 7, false, self::MUTED);
            $this->txt(225, 199.8, 60, 'Página ' . $i . ' de ' . $n, 7, false, self::MUTED, 'R');
        }
        $p->SetTextColor(0);
    }

    private static function rgb(string $hex): array
    {
        $h = ltrim($hex, '#');
        return [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))];
    }
}
