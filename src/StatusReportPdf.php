<?php

namespace GlpiPlugin\Vmo;

/**
 * Status report da iniciativa em PDF (A4 retrato): faixa azul com a logo da OAK, indicadores, realizado, próximos passos,
 * problemas, decisões, riscos abertos e próximos marcos. Serve de pré-visualização antes do envio e de cópia no que foi enviado.
 */
class StatusReportPdf
{
    private const NAVY = [15, 42, 82];
    private const MUTED = [100, 116, 139];
    private const LINE = [203, 213, 225];
    private const HEALTH = ['green' => [[34, 197, 94], 'Verde - no rumo'], 'yellow' => [[245, 158, 11], 'Amarelo - atencao'], 'red' => [[239, 68, 68], 'Vermelho - critico']];

    private static function t($v): string
    {
        return str_replace(['→', '≥', '≤', '−', '—', '–', '…', '•', '“', '”', '‘', '’'], ['>', '>=', '<=', '-', '-', '-', '...', '-', '"', '"', "'", "'"], (string) $v);
    }

    public static function healthLabel(string $h): string
    {
        return self::HEALTH[$h][1] ?? $h;
    }

    public static function fileName(array $init, array $rec): string
    {
        return 'Status Report ' . $init['code'] . ' - ' . date('d-m-Y', strtotime($rec['record_date'] ?: 'now')) . '.pdf';
    }

    public static function render(array $init, array $rec, string $author): string
    {
        $d = $rec['d'] ?? [];
        $p = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8');
        $p->SetCreator('VMO');
        $p->SetAuthor(self::t($author));
        $p->SetTitle(self::t('Status Report ' . $init['code'] . ' - ' . date('d/m/Y', strtotime($rec['record_date'] ?: 'now'))));
        $p->setPrintHeader(false);
        $p->setPrintFooter(false);
        $p->SetAutoPageBreak(true, 18);
        $p->SetMargins(14, 14, 14);
        $logo = @file_get_contents(__DIR__ . '/../public/img/oak-tree-white.png');
        $ratio = 1.55;
        if ($logo !== false && ($info = @getimagesizefromstring($logo))) {
            $ratio = $info[0] / max(1, $info[1]);
        } else {
            $logo = null;
        }
        $total = 1;
        $page = function () use ($p, $logo, $ratio, $init, $rec): void {
            $p->AddPage();
            $p->SetFillColor(...self::NAVY);
            $p->Rect(0, 0, 210, 30, 'F');
            $p->SetFillColor(56, 189, 248);
            $p->Rect(0, 30, 210, 1.2, 'F');
            if ($logo) {
                $p->Image('@' . $logo, 14, 7, 16 * $ratio, 16, 'PNG');
            }
            $p->SetTextColor(147, 197, 253);
            $p->SetFont('helvetica', '', 7.5);
            $p->SetXY(14 + 16 * $ratio + 5, 8);
            $p->Cell(100, 4, 'OAKMONT GROUP  -  VMO', 0, 0, 'L');
            $p->SetTextColor(255, 255, 255);
            $p->SetFont('helvetica', 'B', 17);
            $p->SetXY(14 + 16 * $ratio + 5, 13);
            $p->Cell(110, 8, 'STATUS REPORT', 0, 0, 'L');
            $p->SetFont('helvetica', '', 8);
            $p->SetTextColor(203, 213, 225);
            $p->SetXY(120, 9);
            $p->Cell(76, 4, self::t($init['code']), 0, 0, 'R');
            $p->SetXY(120, 14);
            $p->Cell(76, 4, 'Data do relatorio: ' . date('d/m/Y', strtotime($rec['record_date'] ?: 'now')), 0, 0, 'R');
            $p->SetY(38);
        };
        $page();
        $sec = function (string $title, string $body, bool $empty = false) use ($p, $page): void {
            if ($p->GetY() > 255) {
                $page();
            }
            $y = $p->GetY() + 3;
            $p->SetFillColor(...self::NAVY);
            $p->Rect(14, $y, 2.2, 5, 'F');
            $p->SetFont('helvetica', 'B', 10);
            $p->SetTextColor(...self::NAVY);
            $p->SetXY(18, $y);
            $p->Cell(170, 5, mb_strtoupper(self::t($title)), 0, 1, 'L');
            $p->SetFont('helvetica', '', 9.5);
            $p->SetTextColor($empty ? 148 : 30, $empty ? 163 : 41, $empty ? 184 : 59);
            $p->SetX(18);
            $p->MultiCell(178, 4.6, self::t($body !== '' ? $body : 'Nao informado.'), 0, 'L', false, 1);
        };

        // título + identificação
        $p->SetFont('helvetica', 'B', 14);
        $p->SetTextColor(...self::NAVY);
        $p->SetXY(14, 36);
        $p->MultiCell(182, 6.5, self::t($init['code'] . ' - ' . $init['name']), 0, 'L', false, 1);
        $p->SetFont('helvetica', '', 8.5);
        $p->SetTextColor(...self::MUTED);
        $p->SetX(14);
        $p->Cell(182, 4.5, self::t('Gestor: ' . Db::userName((int) $init['users_id_manager']) . '   |   Patrocinador: ' . Db::userName((int) $init['users_id_sponsor']) . '   |   Situacao: ' . preg_replace('/^\d+\.\s*/', '', Initiative::STATUSES[$init['status']][0])), 0, 1, 'L');
        // cartões
        [$hc, $hl] = self::HEALTH[$d['health'] ?? 'green'] ?? self::HEALTH['green'];
        $cards = [['Saude geral', $hl, $hc], ['Avanco', ($d['progress'] ?? '') !== '' ? $d['progress'] . '%' : '-', [37, 99, 235]], ['Prazo', $d['schedule'] ?? '-', [99, 102, 241]], ['Custo', $d['cost'] ?? '-', [14, 165, 233]]];
        $y = $p->GetY() + 3;
        $w = 44.5;
        foreach ($cards as $k => [$lab, $val, $col]) {
            $x = 14 + $k * ($w + 1.3);
            $p->SetFillColor(248, 250, 252);
            $p->SetDrawColor(...self::LINE);
            $p->RoundedRect($x, $y, $w, 17, 1.5, '1111', 'DF');
            $p->SetFillColor(...$col);
            $p->Rect($x, $y + 1.5, 1.4, 14, 'F');
            $p->SetFont('helvetica', '', 7);
            $p->SetTextColor(...self::MUTED);
            $p->SetXY($x + 3.5, $y + 2);
            $p->Cell($w - 5, 3.5, mb_strtoupper($lab), 0, 0, 'L');
            $p->SetFont('helvetica', 'B', 9.5);
            $p->SetTextColor(...self::NAVY);
            $p->SetXY($x + 3.5, $y + 7.5);
            $p->MultiCell($w - 5, 4, self::t($val), 0, 'L', false, 0);
        }
        $p->SetY($y + 19);
        $sec('Realizado no periodo', (string) ($d['accomplished'] ?? ''), empty($d['accomplished']));
        $sec('Proximos passos', (string) ($d['next'] ?? ''), empty($d['next']));
        $sec('Problemas, riscos e bloqueios', (string) ($d['issues'] ?? ''), empty($d['issues']));
        $sec('Decisoes e apoio necessarios', (string) ($d['decisions'] ?? ''), empty($d['decisions']));

        // riscos abertos
        $rows = [];
        foreach (Risk::forInitiative((int) $init['id']) as $r) {
            if ($r['status'] !== 'closed') {
                $rows[] = [Calc::riskExposure((int) $r['probability'], (int) $r['impact']), $r];
            }
        }
        usort($rows, fn ($a, $b) => $b[0] <=> $a[0]);
        $tasks = Task::forInitiative((int) $init['id']);
        $table = function (string $title, array $head, array $widths, array $lines) use ($p, $page): void {
            if ($p->GetY() > 235) {
                $page();
            }
            $y = $p->GetY() + 3;
            $p->SetFillColor(...self::NAVY);
            $p->Rect(14, $y, 2.2, 5, 'F');
            $p->SetFont('helvetica', 'B', 10);
            $p->SetTextColor(...self::NAVY);
            $p->SetXY(18, $y);
            $p->Cell(170, 5, mb_strtoupper(self::t($title)), 0, 1, 'L');
            $p->SetFont('helvetica', 'B', 7.5);
            $p->SetFillColor(241, 245, 249);
            $p->SetTextColor(...self::MUTED);
            $p->SetDrawColor(...self::LINE);
            $p->SetX(18);
            foreach ($head as $i => $h) {
                $p->Cell($widths[$i], 5.5, mb_strtoupper($h), 1, 0, 'L', true);
            }
            $p->Ln();
            $p->SetFont('helvetica', '', 8.5);
            $p->SetTextColor(30, 41, 59);
            if (!$lines) {
                $p->SetX(18);
                $p->Cell(array_sum($widths), 6, 'Nenhum registro.', 1, 1, 'L');
            }
            foreach ($lines as $l) {
                if ($p->GetY() > 270) {
                    $page();
                }
                $p->SetX(18);
                foreach ($l as $i => $c) {
                    $txt = self::t($c);
                    while (mb_strlen($txt) > 1 && $p->GetStringWidth($txt) > $widths[$i] - 2) {
                        $txt = mb_substr($txt, 0, -2) . '.';
                    }
                    $p->Cell($widths[$i], 5.5, $txt, 1, 0, 'L');
                }
                $p->Ln();
            }
        };
        $table('Riscos e problemas abertos', ['Risco / problema', 'Tarefa ou entrega', 'Exposicao', 'Responsavel'], [74, 52, 20, 32],
            array_map(fn ($x) => [$x[1]['title'], ($x[1]['tasks_id'] ? ($tasks[(int) $x[1]['tasks_id']]['name'] ?? '') : '') . ($x[1]['delivery'] ? ' ' . $x[1]['delivery'] : ''), (string) $x[0], Db::userName((int) $x[1]['users_id_owner'])], array_slice($rows, 0, 6)));
        $next = array_filter($tasks, fn ($t) => in_array($t['status'], ['todo', 'doing'], true) && $t['planned_end']);
        usort($next, fn ($a, $b) => strcmp($a['planned_end'], $b['planned_end']));
        $table('Proximas tarefas e marcos', ['Tarefa', 'Prazo', 'Avanco', 'Responsavel'], [86, 24, 20, 48],
            array_map(fn ($t) => [($t['is_milestone'] ? '[Marco] ' : '') . $t['name'], date('d/m/Y', strtotime($t['planned_end'])), number_format((float) $t['progress'], 0) . '%', Db::userName((int) $t['users_id_responsible'])], array_slice($next, 0, 6)));

        // rodapé com "Página x de y" em todas as páginas
        $n = $p->getNumPages();
        for ($i = 1; $i <= $n; $i++) {
            $p->setPage($i);
            $p->SetDrawColor(...self::LINE);
            $p->Line(14, 282, 196, 282);
            $p->SetFont('helvetica', '', 7.5);
            $p->SetTextColor(148, 163, 184);
            $p->SetXY(14, 284);
            $p->Cell(130, 4, self::t('Gerado em ' . date('d/m/Y H:i') . ' por ' . $author . '  |  Confidencial'), 0, 0, 'L');
            $p->Cell(52, 4, "Pagina $i de $n", 0, 0, 'R');
        }
        return $p->Output('status.pdf', 'S');
    }
}
