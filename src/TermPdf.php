<?php

namespace GlpiPlugin\Vmo;

/**
 * Termos de encerramento e de aceite em PDF (A4 retrato), no mesmo padrão do TAP: faixa azul com a logo, resumo da iniciativa
 * (prazos, avanço, tarefas), os campos do termo, assinatura e rodapé com página x de y.
 */
class TermPdf
{
    private const NAVY = [15, 42, 82];
    private const MUTED = [100, 116, 139];
    private const LINE = [203, 213, 225];
    private const TITLES = ['close' => 'TERMO DE ENCERRAMENTO', 'accept' => 'TERMO DE ACEITE'];

    private static function t($v): string
    {
        return str_replace(['→', '≥', '≤', '−', '—', '–', '…', '•', '“', '”', '‘', '’', '✔'], ['>', '>=', '<=', '-', '-', '-', '...', '-', '"', '"', "'", "'", ''], (string) $v);
    }

    public static function fileName(array $init, array $rec): string
    {
        return ($rec['kind'] === 'accept' ? 'Termo de Aceite ' : 'Termo de Encerramento ') . $init['code'] . ' - ' . date('d-m-Y', strtotime($rec['record_date'] ?: 'now')) . '.pdf';
    }

    public static function render(array $init, array $rec, string $author): string
    {
        $kind = $rec['kind'];
        $spec = Phase::kinds()[$kind];
        $d = $rec['d'] ?? [];
        $tasks = Task::forInitiative((int) $init['id']);
        $p = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8');
        $p->SetCreator('VMO');
        $p->SetAuthor(self::t($author));
        $p->SetTitle(self::t(self::TITLES[$kind] . ' ' . $init['code']));
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
        $signed = $rec['status'] === 'signed';
        $page = function () use ($p, $logo, $ratio, $kind, $init, $rec, $signed): void {
            $p->AddPage();
            $p->SetFillColor(...self::NAVY);
            $p->Rect(0, 0, 210, 30, 'F');
            $p->SetFillColor(56, 189, 248);
            $p->Rect(0, 30, 210, 1.2, 'F');
            if ($logo) {
                $p->Image('@' . $logo, 14, 7, 16 * $ratio, 16, 'PNG');
            }
            $x = 14 + 16 * $ratio + 5;
            $p->SetTextColor(147, 197, 253);
            $p->SetFont('helvetica', '', 7.5);
            $p->SetXY($x, 8);
            $p->Cell(100, 4, 'OAKMONT GROUP  -  VMO', 0, 0, 'L');
            $p->SetTextColor(255, 255, 255);
            $p->SetFont('helvetica', 'B', 15);
            $p->SetXY($x, 13);
            $p->Cell(115, 8, self::TITLES[$kind], 0, 0, 'L');
            $p->SetFont('helvetica', '', 8);
            $p->SetTextColor(203, 213, 225);
            $p->SetXY(120, 9);
            $p->Cell(76, 4, self::t($init['code']), 0, 0, 'R');
            $p->SetXY(120, 14);
            $p->Cell(76, 4, $signed ? 'Assinado' : 'Rascunho - nao assinado', 0, 0, 'R');
            $p->SetY(38);
        };
        $page();
        $sec = function (string $title, string $body) use ($p, $page): void {
            if ($p->GetY() > 256) {
                $page();
            }
            $y = $p->GetY() + 3;
            $p->SetFillColor(...self::NAVY);
            $p->Rect(14, $y, 2.2, 5, 'F');
            $p->SetFont('helvetica', 'B', 9.5);
            $p->SetTextColor(...self::NAVY);
            $p->SetXY(18, $y);
            $p->Cell(170, 5, mb_strtoupper(self::t($title)), 0, 1, 'L');
            $nil = trim($body) === '';
            $p->SetFont('helvetica', '', 9.3);
            $p->SetTextColor($nil ? 148 : 30, $nil ? 163 : 41, $nil ? 184 : 59);
            $p->SetX(18);
            $p->MultiCell(178, 4.6, self::t($nil ? 'Nao informado.' : $body), 0, 'L', false, 1);
        };
        $p->SetFont('helvetica', 'B', 13.5);
        $p->SetTextColor(...self::NAVY);
        $p->SetXY(14, 36);
        $p->MultiCell(182, 6, self::t($init['code'] . ' - ' . $init['name']), 0, 'L', false, 1);
        $p->SetFont('helvetica', '', 8.5);
        $p->SetTextColor(...self::MUTED);
        $p->SetX(14);
        $p->Cell(182, 4.5, self::t($rec['title']), 0, 1, 'L');
        // resumo da iniciativa
        $done = count(array_filter($tasks, fn ($t) => $t['status'] === 'done'));
        $live = count(array_filter($tasks, fn ($t) => $t['status'] !== 'cancelled'));
        $dt = fn (?string $x) => $x ? date('d/m/Y', strtotime($x)) : '-';
        $cells = [['Gestor', Db::userName((int) $init['users_id_manager'])], ['Patrocinador', Db::userName((int) $init['users_id_sponsor'])],
            ['Inicio planejado', $dt($init['planned_start'])], ['Termino planejado', $dt($init['planned_end'])],
            ['Inicio real', $dt($init['actual_start'])], ['Termino real', $dt($init['actual_end'])],
            ['Tarefas concluidas', "$done de $live"], ['Data do termo', $dt($rec['record_date'])]];
        $y = $p->GetY() + 2;
        foreach ($cells as $k => [$l, $v]) {
            $x = 14 + ($k % 4) * 45.5;
            $yy = $y + intdiv($k, 4) * 13;
            $p->SetDrawColor(...self::LINE);
            $p->SetFillColor(255, 255, 255);
            $p->Rect($x, $yy, 45.5, 12.5, 'DF');
            $p->SetFont('helvetica', '', 6.5);
            $p->SetTextColor(...self::MUTED);
            $p->SetXY($x + 1.5, $yy + 1);
            $p->Cell(42, 3, mb_strtoupper(self::t($l)), 0, 0, 'L');
            $p->SetFont('helvetica', 'B', 8.5);
            $p->SetTextColor(15, 23, 42);
            $p->SetXY($x + 1.5, $yy + 5.5);
            $txt = self::t($v);
            while (mb_strlen($txt) > 1 && $p->GetStringWidth($txt) > 42) {
                $txt = mb_substr($txt, 0, -2) . '.';
            }
            $p->Cell(42, 4, $txt, 0, 0, 'L');
        }
        $p->SetY($y + 26.5);
        // campos do termo
        foreach ($spec[4] as $f => $def) {
            if ($f === $spec[2]) {
                continue;
            }
            $v = $d[$f] ?? '';
            $text = match ($def[1]) {
                'select' => (string) ($def[2][$v] ?? $v),
                'user'   => (int) $v > 0 ? Db::userName((int) $v) : '',
                default  => (string) $v,
            };
            $sec($def[0], $text);
        }
        // lições aprendidas (se existirem) entram no termo de encerramento
        if ($kind === 'close') {
            $ls = [];
            foreach (Phase::forInitiative((int) $init['id'], 'lessons') as $l) {
                $ls[] = '- ' . $l['title'] . (!empty($l['d']['recommend']) ? ' > ' . $l['d']['recommend'] : '');
            }
            if ($ls) {
                $sec('Licoes aprendidas', implode("\n", $ls));
            }
        }
        // assinatura
        if ($p->GetY() > 245) {
            $page();
        }
        $y = $p->GetY() + 8;
        $names = $kind === 'accept' ? [[(int) ($d['acceptor_id'] ?? 0) ?: 0, (string) ($d['acceptor'] ?? ''), 'Quem aceita'], [(int) $init['users_id_manager'], '', 'Gestor da iniciativa']]
            : [[(int) $init['users_id_sponsor'], '', 'Patrocinador'], [(int) $init['users_id_manager'], '', 'Gestor da iniciativa']];
        foreach ($names as $k => [$u, $alt, $role]) {
            $x = 14 + $k * 92;
            $p->SetDrawColor(...self::LINE);
            $p->Rect($x, $y, 90, 22);
            $p->SetFont('helvetica', '', 6.5);
            $p->SetTextColor(...self::MUTED);
            $p->SetXY($x + 2, $y + 1.5);
            $p->Cell(86, 3, mb_strtoupper($role), 0, 0, 'L');
            $p->SetFont('helvetica', 'B', 9.5);
            $p->SetTextColor(15, 23, 42);
            $p->SetXY($x + 2, $y + 5.5);
            $p->Cell(86, 4, self::t($u ? Db::userName($u) : ($alt ?: '-')), 0, 0, 'L');
            $p->SetFont('helvetica', '', 7);
            $p->SetTextColor(148, 163, 184);
            $p->SetXY($x + 2, $y + 16);
            $p->Cell(86, 3.5, 'Assinatura', 'T', 0, 'L');
        }
        if ($signed) {
            $p->SetFont('helvetica', 'B', 8);
            $p->SetTextColor(22, 101, 52);
            $p->SetXY(14, $y + 25);
            $p->Cell(182, 4, self::t('Assinado e registrado no GLPI em ' . Menu::datetime($rec['signed_at']) . ' por ' . Db::userName((int) $rec['signed_by'])), 0, 0, 'L');
        }
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
        return $p->Output('termo.pdf', 'S');
    }
}
