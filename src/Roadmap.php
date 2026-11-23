<?php

namespace GlpiPlugin\Vmo;

/**
 * Roadmap de integração: cada iniciativa visível aparece sozinha, pelo seu "Status de entrega"
 * (campo delivery_stage da iniciativa): Em desenvolvimento, Desenvolvido ou Em produção.
 */
class Roadmap
{
    public const STAGES = [
        'prod' => ['Em produção', '#84cc16'],
        'dev'  => ['Desenvolvido', '#3b82f6'],
        'wip'  => ['Em desenvolvimento', '#64748b'],
    ];

    /** Nome curto para o nó: tira o prefixo "Plugin " e a versão final ("v1.2"). */
    public static function label(string $name): string
    {
        $n = trim(preg_replace(['/^plugin\s+/iu', '/\s+v\d[\d.]*$/iu'], '', $name));
        return mb_strimwidth($n !== '' ? $n : $name, 0, 34, '…');
    }

    /** Cor de cada perspectiva estratégica (as quatro da planilha OAK); o resto cai em cinza. */
    public const PERSPECTIVES = [
        'Econômico-Financeira' => '#f59e0b',
        'Mercado e Cliente' => '#22d3ee',
        'Processos e Tecnologia' => '#6366f1',
        'Patrimônio Humano' => '#f472b6',
    ];
    public const NO_PERSPECTIVE = ['Sem perspectiva', '#94a3b8'];

    /** Ícone pelo nome da iniciativa (primeira palavra-chave que casar). */
    public static function icon(string $name): string
    {
        $map = ['noc' => 'ti-world-www', 'crm' => 'ti-users-group', 'financ' => 'ti-coin', 'evento' => 'ti-calendar-event', 'reembolso' => 'ti-receipt-2',
            'medi' => 'ti-ruler-measure', 'rh' => 'ti-heart-handshake', 'indicador' => 'ti-chart-bar', 'vmo' => 'ti-shield-check', 'marketing' => 'ti-speakerphone',
            'demanda' => 'ti-speakerphone', 'csat' => 'ti-mood-smile', 'nps' => 'ti-mood-smile', 'pmo' => 'ti-briefcase', 'sistemas internos' => 'ti-lock',
            'operacional' => 'ti-activity', 'aviso' => 'ti-bell-ringing', 'assinatura' => 'ti-signature', 'cronograma' => 'ti-timeline', 'equipamento' => 'ti-device-desktop',
            'pdf' => 'ti-file-type-pdf', 'field' => 'ti-forms', 'automa' => 'ti-robot', 'ativo' => 'ti-server-2'];
        $n = mb_strtolower($name);
        foreach ($map as $k => $ic) {
            if (str_contains($n, $k)) {
                return $ic;
            }
        }
        return 'ti-hexagon';
    }

    /** Todas as iniciativas candidatas (visíveis, sem canceladas/recusadas/excluídas), inclusive as ocultas por configuração. */
    public static function all(): array
    {
        $out = [];
        foreach (Access::visibleInitiatives() as $i) {
            if (!empty($i['is_deleted']) || in_array($i['status'], ['cancelled', 'rejected'], true)) {
                continue;
            }
            $st = isset(self::STAGES[$i['delivery_stage'] ?? '']) ? $i['delivery_stage'] : 'wip';
            $out[] = ['id' => (int) $i['id'], 'code' => $i['code'], 'name' => self::label($i['name']), 'full' => $i['name'], 'stage' => $st];
        }
        usort($out, fn ($a, $b) => strcmp($a['code'], $b['code']));
        return $out;
    }

    /** Iniciativas que entram no roadmap: todas as visíveis, menos excluídas, canceladas, recusadas e as ocultas na configuração (roadmap_hidden). */
    public static function items(): array
    {
        $pers = Catalog::names('perspective');
        $out = [];
        $hidden = array_filter(array_map(fn ($c) => strtoupper(trim($c)), explode(',', Settings::get('roadmap_hidden'))));
        foreach (Access::visibleInitiatives() as $i) {
            if (in_array(strtoupper((string) $i['code']), $hidden, true)) {
                continue; // ocultas do roadmap por configuração (ex.: plugins nativos de terceiros)
            }
            if (!empty($i['is_deleted']) || in_array($i['status'], ['cancelled', 'rejected'], true)) {
                continue;
            }
            $st = isset(self::STAGES[$i['delivery_stage'] ?? '']) ? $i['delivery_stage'] : 'wip';
            $pn = $pers[(int) ($i['catalogs_id_perspective'] ?? 0)] ?? '';
            $out[] = ['id' => (int) $i['id'], 'code' => $i['code'], 'name' => self::label($i['name']), 'full' => $i['name'], 'stage' => $st,
                'persp' => $pn, 'color' => self::PERSPECTIVES[$pn] ?? self::NO_PERSPECTIVE[1], 'icon' => self::icon($i['name'])];
        }
        // agrupa por perspectiva (as quatro na ordem da planilha, depois as sem perspectiva); dentro, por código
        $ord = array_flip(array_keys(self::PERSPECTIVES));
        usort($out, fn ($a, $b) => [$ord[$a['persp']] ?? 99, $a['code']] <=> [$ord[$b['persp']] ?? 99, $b['code']]);
        // cada iniciativa ganha uma cor própria (ângulo áureo: vizinhas sempre bem diferentes)
        foreach ($out as $t => &$o) {
            $o['color'] = 'hsl(' . (int) fmod($t * 137.508 + 200, 360) . ' 82% 62%)';
        }
        unset($o);
        return $out;
    }

    /** Posições (em uma grade 1880 x 1040, núcleo no centro) e ligações; usado pelo book PDF. */
    public static function layout(array $items): array
    {
        $W = 1880; $H = 1180; $C = [940, 590];
        $n = count($items);
        $pos = [];
        foreach ($items as $t => $it) {
            $ang = 2 * M_PI * ($t + .5) / max(1, $n) - M_PI / 2;
            $outer = $t % 2 === 0;
            $pos[$it['id']] = [$C[0] + ($outer ? 840 : 500) * cos($ang), $C[1] + ($outer ? 480 : 255) * sin($ang)];
        }
        $links = [];
        foreach ($items as $t => $it) {
            $links[] = [$it['id'], null, $it['stage'] === 'prod' ? 'spoke-prod' : 'spoke'];
            $nx = $items[($t + 1) % $n];
            if ($n > 1) {
                $links[] = [$it['id'], $nx['id'], $nx['persp'] === $it['persp'] ? 'area' : 'bridge'];
            }
            $nx2 = $items[($t + 2) % $n];
            if ($n > 3 && $nx2['persp'] === $it['persp']) {
                $links[] = [$it['id'], $nx2['id'], 'area'];
            }
        }
        return ['W' => $W, 'H' => $H, 'C' => $C, 'pos' => $pos, 'links' => $links];
    }

    /** "hsl(h 82% 62%)" -> [r, g, b]. */
    public static function rgb(string $hsl): array
    {
        if (!preg_match('/hsl\((\d+) (\d+)% (\d+)%\)/', $hsl, $m)) {
            return [148, 163, 184];
        }
        [$h, $sat, $l] = [(int) $m[1] / 360, (int) $m[2] / 100, (int) $m[3] / 100];
        $q = $l < .5 ? $l * (1 + $sat) : $l + $sat - $l * $sat;
        $pp = 2 * $l - $q;
        $f = function (float $t) use ($pp, $q) {
            $t = fmod($t + 1, 1);
            return round(255 * ($t < 1 / 6 ? $pp + ($q - $pp) * 6 * $t : ($t < .5 ? $q : ($t < 2 / 3 ? $pp + ($q - $pp) * (2 / 3 - $t) * 6 : $pp))));
        };
        return [(int) $f($h + 1 / 3), (int) $f($h), (int) $f($h - 1 / 3)];
    }
}
