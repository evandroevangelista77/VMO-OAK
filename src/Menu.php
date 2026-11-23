<?php

namespace GlpiPlugin\Vmo;

use Session;

/**
 * Seção "VMO" do menu lateral, endereços e peças de interface compartilhadas pelas telas.
 */
class Menu
{
    public const SECTOR = 'vmo';

    public static function url(string $path): string
    {
        global $CFG_GLPI;
        return $CFG_GLPI['root_doc'] . '/plugins/vmo' . $path;
    }

    /** Endereço relativo, como o menu do GLPI espera. */
    public static function page(string $path): string
    {
        return '/plugins/vmo' . $path;
    }

    public static function e($v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES);
    }

    public static function content(): array
    {
        $items = [];
        if (Access::canView()) {
            $items['dashboard'] = ['title' => 'Dashboard executivo', 'page' => self::page('/front/dashboard.php'), 'icon' => 'ti ti-chart-bar'];
            $items['initiatives'] = ['title' => 'Iniciativas', 'page' => self::page('/front/initiatives.php'), 'icon' => 'ti ti-briefcase',
                'links' => Access::canCreate() ? ['add' => self::page('/front/initiative.php?id=0'), 'search' => self::page('/front/initiatives.php')] : []];
            if (Access::canCreate()) {
                $items['requests'] = ['title' => 'Requisições de Iniciativa', 'page' => self::page('/front/request.php'), 'icon' => 'ti ti-inbox'];
            }
            $items['inbox'] = ['title' => 'Meu Painel', 'page' => self::page('/front/inbox.php'), 'icon' => 'ti ti-layout-dashboard'];
            $items['committee'] = ['title' => 'Comitê', 'page' => self::page('/front/committee.php'), 'icon' => 'ti ti-users-group'];
            $items['indicators'] = ['title' => 'KPIs', 'page' => self::page('/front/indicators.php'), 'icon' => 'ti ti-gauge'];
            if (Access::canConfigure()) {
                $items['config'] = ['title' => 'Configuração', 'page' => self::page('/front/config.php'), 'icon' => 'ti ti-settings'];
            }
        }
        return $items;
    }

    public static function header(string $title, string $item): void
    {
        \Html::header($title, '', self::SECTOR, $item);
        echo "<link rel='stylesheet' href='" . self::e(self::url('/css/vmo.css') . '?v=' . PLUGIN_VMO_VERSION . '-' . @filemtime(dirname(__DIR__) . '/public/css/vmo.css')) . "'>"
            . "<script defer src='" . self::e(self::url('/js/titulos.js') . '?v=' . PLUGIN_VMO_VERSION . '-' . @filemtime(dirname(__DIR__) . '/public/js/titulos.js')) . "'></script>";
    }

    public static function script(string $file): string
    {
        $f = dirname(__DIR__) . '/public/js/' . $file;
        return \Html::script(self::url('/js/' . $file), ['version' => PLUGIN_VMO_VERSION . '-' . (is_file($f) ? filemtime($f) : 0)], false);
    }

    public static function title(string $title, string $subtitle = '', string $actions = '', string $crumb = 'VMO'): string
    {
        return "<div class='vmo-title'><div><div class='vmo-crumb'>" . self::e($crumb) . "</div><h2>" . self::e($title) . "</h2>"
            . ($subtitle !== '' ? "<div class='text-secondary small vmo-subtitle'>" . self::e($subtitle) . "</div>" : '')
            . "</div>" . ($actions !== '' ? "<div class='d-flex flex-wrap gap-2 align-items-center'>$actions</div>" : '') . "</div>";
    }

    public static function button(string $label, string $url, string $icon = 'ti-plus', string $cls = 'btn-primary'): string
    {
        return "<a class='btn $cls' href='" . self::e($url) . "'><i class='ti $icon me-1'></i>" . self::e($label) . "</a>";
    }

    public static function badge(string $label, string $color, string $icon = ''): string
    {
        return "<span class='badge vmo-badge' style='--c:" . self::e($color) . "'>" . ($icon ? "<i class='ti $icon me-1'></i>" : '') . self::e($label) . "</span>";
    }

    public static function csrf(): string
    {
        return \Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
    }

    /** Formulário de uma ação simples (botão) enviado a front/action.php. */
    public static function actionForm(string $do, array $hidden, string $buttonHtml, string $extra = '', string $class = 'd-inline'): string
    {
        $h = '';
        foreach ($hidden as $k => $v) {
            $h .= "<input type='hidden' name='" . self::e($k) . "' value='" . self::e($v) . "'>";
        }
        return "<form method='post' action='" . self::e(self::url('/front/action.php')) . "' class='$class'>" . self::csrf()
            . "<input type='hidden' name='do' value='" . self::e($do) . "'>$h$extra$buttonHtml</form>";
    }

    // ---------- formatação ----------

    public static function date(?string $d): string
    {
        return $d ? \Html::convDate($d) : '—';
    }

    /** Data/hora gravada em UTC, exibida no fuso do usuário/servidor. */
    public static function datetime(?string $utc): string
    {
        if (!$utc) {
            return '—';
        }
        $dt = new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
        return $dt->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('d/m/Y H:i');
    }

    public static function money(?float $v, string $currency): string
    {
        return Calc::formatMoney($v, $currency);
    }

    public static function pct(?float $v, string $none = 'sem dado'): string
    {
        return $v === null ? "<span class='text-secondary'>" . self::e($none) . "</span>" : number_format($v, 1, ',', '.') . '%';
    }

    public const HEALTH = [
        'green'  => ['No prazo', '#22c55e', 'ti-circle-check'],
        'yellow' => ['Atenção', '#f59e0b', 'ti-alert-circle'],
        'red'    => ['Crítica', '#ef4444', 'ti-alert-triangle'],
        'gray'   => ['Sem dados', '#94a3b8', 'ti-help-circle'],
    ];

    public static function healthBadge(string $h): string
    {
        [$label, $color, $icon] = self::HEALTH[$h] ?? self::HEALTH['gray'];
        return self::badge($label, $color, $icon);
    }

    public static function userSelect(string $name, int $selected = 0, bool $allowEmpty = true, string $extra = ''): string
    {
        global $DB;
        $opts = $allowEmpty ? "<option value='0'>—</option>" : '';
        foreach ($DB->request(['SELECT' => ['id', 'name', 'realname', 'firstname'], 'FROM' => 'glpi_users', 'WHERE' => ['is_active' => 1, 'is_deleted' => 0], 'ORDER' => ['realname ASC', 'name ASC'], 'LIMIT' => 500]) as $u) {
            $label = trim(($u['firstname'] ?? '') . ' ' . ($u['realname'] ?? '')) ?: $u['name'];
            $opts .= "<option value='" . (int) $u['id'] . "'" . ((int) $u['id'] === $selected ? ' selected' : '') . '>' . self::e($label) . '</option>';
        }
        return "<select class='form-select' name='" . self::e($name) . "' $extra>$opts</select>";
    }

    public static function select(string $name, array $options, $selected = '', string $extra = '', bool $empty = false): string
    {
        $o = $empty ? "<option value=''>—</option>" : '';
        foreach ($options as $k => $label) {
            $label = is_array($label) ? $label[0] : $label;
            $o .= "<option value='" . self::e($k) . "'" . ((string) $k === (string) $selected ? ' selected' : '') . '>' . self::e($label) . '</option>';
        }
        return "<select class='form-select' name='" . self::e($name) . "' $extra>$o</select>";
    }
}
