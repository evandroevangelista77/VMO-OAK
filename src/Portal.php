<?php

namespace GlpiPlugin\Vmo;

/**
 * Portal nativo do GLPI (Início do autoatendimento): cartão "Nova iniciativa" do VMO.
 *
 * Grava direto nas tabelas dos cartões do GLPI (helpdesks_tiles_*), porque a instalação por linha de
 * comando não tem sessão com direito de configuração. Só existe UM cartão de exemplo, ligado à entidade
 * raiz; o administrador pode editar texto/ilustração/ordem em Configuração → Entidades → Início do
 * autoatendimento, ou removê-lo lá. Só mexe no que é do VMO (identificado pelo endereço).
 */
class Portal
{
    private const PAGE = '/plugins/vmo/front/initiative.php?id=0';

    private static function tables(): ?array
    {
        global $DB;
        if (!class_exists(\Glpi\Helpdesk\Tile\ExternalPageTile::class) || !class_exists(\Glpi\Helpdesk\Tile\Item_Tile::class)) {
            return null; // GLPI sem portal por cartões
        }
        $tile = \Glpi\Helpdesk\Tile\ExternalPageTile::getTable();
        $link = \Glpi\Helpdesk\Tile\Item_Tile::getTable();
        return $DB->tableExists($tile) && $DB->tableExists($link) ? [$tile, $link] : null;
    }

    private static function url(): string
    {
        global $CFG_GLPI;
        return ($CFG_GLPI['root_doc'] ?? '') . self::PAGE;
    }

    /** Id do cartão do VMO já criado (0 se não existe). */
    public static function tileId(): int
    {
        global $DB;
        $t = self::tables();
        if (!$t) {
            return 0;
        }
        $row = $DB->request(['FROM' => $t[0], 'WHERE' => ['url' => ['LIKE', '%' . self::PAGE]], 'LIMIT' => 1])->current();
        return $row ? (int) $row['id'] : 0;
    }

    /**
     * Cria o cartão de exemplo na entidade raiz, uma única vez.
     *
     * @return string criado | ja-existe | indisponivel
     */
    public static function ensureExample(): string
    {
        global $DB;
        $t = self::tables();
        if (!$t) {
            return 'indisponivel';
        }
        if (self::tileId() > 0) {
            return 'ja-existe';
        }
        $DB->insert($t[0], [
            'title'        => 'Nova iniciativa (VMO)',
            'description'  => 'Tem uma ideia? Registre no brainstorm: dali nasce a oportunidade, que vira iniciativa e é acompanhada até a comprovação de valor.',
            'illustration' => 'request-service',
            'url'          => self::url(),
        ]);
        $tileId = (int) $DB->insertId();
        $max = $DB->request(['SELECT' => ['MAX' => 'rank AS m'], 'FROM' => $t[1], 'WHERE' => ['itemtype_item' => 'Entity', 'items_id_item' => 0]])->current();
        $DB->insert($t[1], [
            'itemtype_item' => 'Entity',
            'items_id_item' => 0,
            'itemtype_tile' => \Glpi\Helpdesk\Tile\ExternalPageTile::class,
            'items_id_tile' => $tileId,
            'rank'          => (int) ($max['m'] ?? 0) + 1,
        ]);
        return 'criado';
    }

    /** Remove o cartão do VMO e o vínculo (desinstalação). */
    public static function remove(): void
    {
        global $DB;
        $t = self::tables();
        $id = $t ? self::tileId() : 0;
        if (!$t || !$id) {
            return;
        }
        $DB->delete($t[1], ['itemtype_tile' => \Glpi\Helpdesk\Tile\ExternalPageTile::class, 'items_id_tile' => $id]);
        $DB->delete($t[0], ['id' => $id]);
    }
}
