<?php

namespace GlpiPlugin\Vmo;

/**
 * TAP em PDF (A4 retrato): o mesmo documento da folha da tela, com as assinaturas eletrônicas já registradas.
 * Uma cópia é guardada na Documentação da iniciativa ao enviar e atualizada a cada assinatura.
 */
class CharterPdf
{
    private const NAVY = [15, 42, 82];
    private const MUTED = [100, 116, 139];
    private const LINE = [203, 213, 225];

    private \TCPDF $p;
    private ?string $logo = null;
    private float $ratio = 1.55;
    private array $head = [];

    private static function t($v): string
    {
        return str_replace(['→', '≥', '≤', '−', '—', '–', '…', '•', '“', '”', '‘', '’', '✔'], ['>', '>=', '<=', '-', '-', '-', '...', '-', '"', '"', "'", "'", ''], (string) $v);
    }

    public static function fileName(array $init, array $c): string
    {
        return 'TAP ' . $init['code'] . ' - Termo de Abertura (rev ' . max(1, (int) $c['revision']) . ').pdf';
    }

    private function page(): void
    {
        $p = $this->p;
        $p->AddPage();
        $p->SetFillColor(...self::NAVY);
        $p->Rect(0, 0, 210, 30, 'F');
        $p->SetFillColor(56, 189, 248);
        $p->Rect(0, 30, 210, 1.2, 'F');
        if ($this->logo) {
            $p->Image('@' . $this->logo, 14, 7, 16 * $this->ratio, 16, 'PNG');
        }
        $x = 14 + 16 * $this->ratio + 5;
        $p->SetTextColor(147, 197, 253);
        $p->SetFont('helvetica', '', 7.5);
        $p->SetXY($x, 8);
        $p->Cell(100, 4, 'OAKMONT GROUP  -  VMO', 0, 0, 'L');
        $p->SetTextColor(255, 255, 255);
        $p->SetFont('helvetica', 'B', 15);
        $p->SetXY($x, 13);
        $p->Cell(110, 8, 'TERMO DE ABERTURA DO PROJETO', 0, 0, 'L');
        $p->SetFont('helvetica', '', 8);
        $p->SetTextColor(203, 213, 225);
        $p->SetXY(120, 9);
        $p->Cell(76, 4, self::t($this->head[0]), 0, 0, 'R');
        $p->SetXY(120, 14);
        $p->Cell(76, 4, self::t($this->head[1]), 0, 0, 'R');
        $p->SetY(38);
    }

    private function sec(string $n, string $title, string $body, bool $nil = false): void
    {
        $p = $this->p;
        if ($p->GetY() > 258) {
            $this->page();
        }
        $y = $p->GetY() + 3;
        $p->SetFillColor(...self::NAVY);
        $p->Rect(14, $y, 5, 5, 'F');
        $p->SetFont('helvetica', 'B', 8);
        $p->SetTextColor(255, 255, 255);
        $p->SetXY(14, $y + .6);
        $p->Cell(5, 4, $n, 0, 0, 'C');
        $p->SetFont('helvetica', 'B', 9.5);
        $p->SetTextColor(...self::NAVY);
        $p->SetXY(21, $y + .2);
        $p->Cell(170, 5, mb_strtoupper(self::t($title)), 0, 1, 'L');
        $p->SetDrawColor(...self::NAVY);
        $p->Line(14, $y + 5.6, 196, $y + 5.6);
        $p->SetFont('helvetica', '', 9.3);
        $nil = $nil || trim($body) === '';
        $p->SetTextColor($nil ? 148 : 30, $nil ? 163 : 41, $nil ? 184 : 59);
        $p->SetXY(14, $y + 7.5);
        $p->MultiCell(182, 4.5, self::t($nil ? 'Nao informado.' : $body), 0, 'L', false, 1);
    }

    private function table(string $n, string $title, array $head, array $w, array $lines): void
    {
        $p = $this->p;
        $this->sec($n, $title, '');
        $p->SetY($p->GetY() - 6.5);
        $p->SetFont('helvetica', 'B', 7.5);
        $p->SetFillColor(241, 245, 249);
        $p->SetTextColor(...self::MUTED);
        $p->SetDrawColor(...self::LINE);
        $p->SetX(14);
        foreach ($head as $i => $h) {
            $p->Cell($w[$i], 5.5, mb_strtoupper($h), 1, 0, 'L', true);
        }
        $p->Ln();
        $p->SetFont('helvetica', '', 8.5);
        $p->SetTextColor(30, 41, 59);
        foreach ($lines as $l) {
            if ($p->GetY() > 272) {
                $this->page();
            }
            $p->SetX(14);
            foreach ($l as $i => $c) {
                $txt = self::t($c);
                while (mb_strlen($txt) > 1 && $p->GetStringWidth($txt) > $w[$i] - 2) {
                    $txt = mb_substr($txt, 0, -2) . '.';
                }
                $p->Cell($w[$i], 5.5, $txt, 1, 0, 'L');
            }
            $p->Ln();
        }
    }

    public static function render(array $init, array $c, array $D, array $signers, array $sigs, string $author): string
    {
        $o = new self();
        $sent = $c['status'] === 'issued';
        $rev = max(1, (int) $c['revision']);
        $o->head = ['TAP-' . $init['code'] . ($sent ? '-R' . $rev : '-RASCUNHO'), $sent ? 'Enviado em ' . Menu::datetime($c['issued_at']) : 'Rascunho - nao enviado'];
        $p = $o->p = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8');
        $p->SetCreator('VMO');
        $p->SetAuthor(self::t($author));
        $p->SetTitle(self::t('TAP ' . $init['code'] . ' - Termo de Abertura do Projeto'));
        $p->setPrintHeader(false);
        $p->setPrintFooter(false);
        $p->SetAutoPageBreak(true, 18);
        $p->SetMargins(14, 14, 14);
        $logo = @file_get_contents(__DIR__ . '/../public/img/oak-tree-white.png');
        if ($logo !== false && ($info = @getimagesizefromstring($logo))) {
            $o->logo = $logo;
            $o->ratio = $info[0] / max(1, $info[1]);
        }
        $o->page();
        $F = $D['fields'];
        $dt = fn (?string $d) => $d ? date('d/m/Y', strtotime($d)) : '-';
        $p->SetFont('helvetica', 'B', 13.5);
        $p->SetTextColor(...self::NAVY);
        $p->SetXY(14, 36);
        $p->MultiCell(182, 6, self::t($D['code'] . ' - ' . $D['name']), 0, 'L', false, 1);
        // identificação
        $cells = [['Quem demandou', $D['requester']], ['Quem vai acompanhar', $D['follower']], ['Gestor', $D['manager']], ['Patrocinador', $D['sponsor']],
            ['Inicio', $dt($F['start_date'] ?? null)], ['Prazo de entrega', $dt($F['delivery_date'] ?? null)], ['Orcamento estimado', ($F['budget_note'] ?? '') ?: '-'], ['Situacao do termo', $sent ? 'Enviado - revisao ' . $rev : 'Rascunho']];
        $y = $p->GetY() + 2;
        foreach ($cells as $k => [$l, $v]) {
            $x = 14 + ($k % 4) * 45.5;
            $yy = $y + intdiv($k, 4) * 13;
            $p->SetDrawColor(...self::LINE);
            $p->SetFillColor($k >= 4 && $k < 6 ? 239 : 255, $k >= 4 && $k < 6 ? 246 : 255, $k >= 4 && $k < 6 ? 255 : 255);
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
        $o->sec('1', 'Justificativa e contexto', trim(($D['justification'] ?? '') . "\n" . ($D['problem'] ?? '')));
        $o->sec('2', 'Objetivo', (string) ($F['objective'] ?? ''));
        $crit = '';
        foreach ($D['criteria'] as $l => $v) {
            $crit .= $l . ': ' . $v . "\n";
        }
        $o->sec('3', 'Alinhamento estrategico', trim($crit));
        $o->sec('4', 'Escopo', 'Dentro do escopo: ' . (trim((string) ($F['scope'] ?? '')) ?: '-') . "\n" . 'Fora do escopo: ' . (trim((string) ($F['out_of_scope'] ?? '')) ?: '-'));
        $o->sec('5', 'Entregas principais', implode("\n", array_map(fn ($l) => '- ' . ltrim(trim($l), "-*• "), array_filter(preg_split('/\R/', (string) ($F['deliverables'] ?? '')), fn ($l) => trim($l) !== ''))));
        $o->sec('6', 'Premissas e restricoes', 'Premissas: ' . (trim((string) ($F['premises'] ?? '')) ?: '-') . "\n" . 'Restricoes: ' . (trim((string) ($F['constraints'] ?? '')) ?: '-'));
        $o->sec('7', 'Riscos iniciais e criterios de sucesso', 'Riscos iniciais: ' . (trim((string) ($F['risks_initial'] ?? '')) ?: '-') . "\n" . 'Criterios de sucesso: ' . (trim((string) ($F['success_criteria'] ?? '')) ?: '-'));
        $ms = [['Inicio da iniciativa', $dt($F['start_date'] ?? null)]];
        foreach ($D['milestones'] as [$mn, $md]) {
            $ms[] = [$mn, $dt($md)];
        }
        $ms[] = ['Entrega prevista', $dt($F['delivery_date'] ?? null)];
        $o->table('8', 'Marcos e prazos', ['Marco', 'Data'], [152, 30], $ms);
        $lv = ['3' => 'Alto', '2' => 'Medio', '1' => 'Baixo'];
        $o->table('9', 'Partes interessadas', ['Parte interessada', 'Papel', 'Interesse', 'Influencia', 'Comunicacao'], [50, 40, 20, 22, 50],
            array_map(fn ($s) => [$s['name'], $s['role'] ?? '', $lv[$s['interest'] ?? ''] ?? '', $lv[$s['influence'] ?? ''] ?? '', $s['comm'] ?? ''], $D['stakeholders']) ?: [['Nenhuma registrada', '', '', '', '']]);
        // assinaturas
        $o->sec('10', 'Aprovacao e assinaturas', '');
        $y = $p->GetY() - 6.5;
        $k = 0;
        foreach ($signers as $u => $role) {
            $x = 14 + ($k % 2) * 92;
            $yy = $y + intdiv($k, 2) * 24;
            if ($yy > 262) {
                $o->page();
                $y = 40;
                $yy = $y + intdiv($k % 8, 2) * 24;
            }
            $s = $sigs[$u] ?? null;
            $p->SetDrawColor($s ? 34 : 203, $s ? 197 : 213, $s ? 94 : 225);
            $p->SetFillColor($s ? 240 : 255, $s ? 253 : 255, $s ? 244 : 255);
            $p->Rect($x, $yy, 90, 21, 'DF');
            $p->SetFont('helvetica', '', 6.5);
            $p->SetTextColor(...self::MUTED);
            $p->SetXY($x + 2, $yy + 1.5);
            $p->Cell(86, 3, mb_strtoupper(self::t($role)), 0, 0, 'L');
            $p->SetFont('helvetica', 'B', 9.5);
            $p->SetTextColor(15, 23, 42);
            $p->SetXY($x + 2, $yy + 5.5);
            $p->Cell(86, 4, self::t(Db::userName($u)), 0, 0, 'L');
            $p->SetFont('helvetica', '', 7);
            if ($s) {
                $p->SetTextColor(22, 101, 52);
                $p->SetXY($x + 2, $yy + 11);
                $p->MultiCell(86, 3.4, self::t('Assinado digitalmente em ' . Menu::datetime($s['signed_at']) . ' - IP ' . ($s['ip'] ?? '-') . "\nCodigo " . strtoupper(substr($s['sign_hash'], 0, 24))), 0, 'L', false, 1);
            } else {
                $p->SetTextColor(148, 163, 184);
                $p->SetXY($x + 2, $yy + 15);
                $p->Cell(86, 3.5, $sent ? 'Aguardando assinatura' : 'Assinatura disponivel apos o envio', 'T', 0, 'L');
            }
            $k++;
        }
        $p->SetY($y + intdiv($k + 1, 2) * 24 + 2);
        if ($sent) {
            $p->SetFont('helvetica', '', 7);
            $p->SetTextColor(...self::MUTED);
            $p->SetX(14);
            $p->MultiCell(182, 3.5, self::t('Assinatura eletronica registrada no GLPI (nome, papel, data, hora, IP e codigo calculado sobre o conteudo enviado). Codigo de verificacao do documento: ' . strtoupper(substr(Charter::docHash($c), 0, 12)) . ' - Hash: ' . Charter::docHash($c)), 0, 'L', false, 1);
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
        return $p->Output('tap.pdf', 'S');
    }
}
