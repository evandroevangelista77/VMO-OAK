<?php

namespace GlpiPlugin\Vmo;

/** Peças da "tabela agrupada" (grupos recolhíveis, avatar do responsável, situação em célula colorida, progresso e prazo). */
class GroupView
{
    public const MONTHS = ['', 'jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
    public const MONTHS_FULL = ['', 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
    public const PALETTE = ['#6366f1', '#06b6d4', '#22c55e', '#f59e0b', '#a855f7', '#ec4899', '#14b8a6', '#f97316'];

    public static function avatar(int $userId): string
    {
        if ($userId <= 0) {
            return "<span class='vg-av vg-av-none' title='Sem responsável'>?</span>";
        }
        $name = Db::userName($userId);
        $parts = preg_split('/[\s._-]+/', trim($name)) ?: [$name];
        $ini = mb_strtoupper(mb_substr($parts[0] ?? '?', 0, 1) . (isset($parts[1]) ? mb_substr($parts[1], 0, 1) : mb_substr($parts[0] ?? '', 1, 1)));
        return "<span class='vg-av' style='--h:" . (($userId * 47) % 360) . "' title='" . Menu::e($name) . "'>" . Menu::e($ini) . "</span>";
    }

    /** Avatar com as iniciais de um nome (donos executivos do catálogo, que não são usuários). */
    public static function avatarName(string $name, int $seed = 0): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [$name];
        $ini = mb_strtoupper(mb_substr($parts[0] ?? '?', 0, 1) . (isset($parts[1]) ? mb_substr($parts[1], 0, 1) : ''));
        $h = $seed ?: (crc32($name) % 360);
        return "<span class='vg-av' style='--h:" . ($h % 360) . "' title='" . Menu::e($name) . "'>" . Menu::e($ini) . "</span>";
    }

    public static function shortDate(?string $d): string
    {
        return $d ? (int) substr($d, 8, 2) . ' ' . self::MONTHS[(int) substr($d, 5, 2)] : '—';
    }

    public static function stars(int $n): string
    {
        $n = max(1, min(5, $n));
        return "<span class='on'>" . str_repeat('★', $n) . "</span><span>" . str_repeat('★', 5 - $n) . "</span>";
    }

    public static function bar(?float $pct, string $color, string $title = ''): string
    {
        return $pct === null ? "<span class='vmo-muted small'>sem dado</span>"
            : "<div class='vg-tl' title='" . Menu::e($title) . "'><span style='width:" . min(100, max(0, round($pct))) . "%;background:$color'></span></div><b class='vg-tl-n'>" . number_format($pct, 0) . "%</b>";
    }

    /** Cabeçalho de grupo: título colorido, contador e nomes das colunas. */
    public static function groupHead(string $title, int $count, array $cols): string
    {
        return "<div class='vg-grp-head'><button type='button' class='vg-grp-title' data-toggle aria-expanded='true'><i class='ti ti-chevron-down'></i><span>" . Menu::e($title) . "</span><em>$count</em></button>"
            . "<div class='vg-col-names'>" . implode('', array_map(fn ($c) => '<span>' . Menu::e($c) . '</span>', $cols)) . "</div></div>";
    }

    public static function statusCell(string $label, string $color): string
    {
        return "<div class='vg-cell-st'><span class='vg-st' style='--c:$color'>" . Menu::e($label) . "</span></div>";
    }
}
