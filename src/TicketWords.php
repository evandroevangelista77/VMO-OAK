<?php

namespace GlpiPlugin\Vmo;

/**
 * Apoio às Requisições de Iniciativa: chamados abertos ainda sem iniciativa (candidatos para associar) e as palavras deles
 * (nuvem de palavras para ver por onde começar).
 */
class TicketWords
{
    private const STOP = 'para pelo pela pelos pelas como mais muito muita muitos muitas esta este estes estas essa esse isso isto aqui ali onde quando quem qual quais porque pois mas por com sem sobre entre ate apos desde dos das nos nas uns umas uma um que foi ser sao tem tinha temos ter fazer feito faz podem pode poder precisa preciso precisamos gostaria gostariamos solicito solicitamos solicitar favor boa bom tarde dia noite obrigado obrigada att atenciosamente prezados prezado ola chamado chamados ticket abrir aberto abertura novo nova novos novas todo toda todos todas cada seria sera serao vai vao vamos esta estao estou estamos ainda tambem apenas somente algum alguma alguns algumas nao sim nosso nossa nossos nossas seu sua seus suas meu minha entao assim fica ficar deve devem deveria possivel possivel existe existem sendo estar havia houve pra pro numa num dele dela deles delas ele ela eles elas voce voces gente coisa coisas forma modo vez vezes dados dado informacao informacoes email mail http https www com br demo teste mock the and for you your are was were been being have has had not but with from this that these those will can could should would may might shall must any all one two also into out over under then than there their them they what which who whom whose why how when where its our ours his her him she let get got use used using set sets new old see via per per not no yes high low warning problema information average disaster severidade status host data false true changed defined errored installed version stuck redacted erro fase etapa solicitante resposta sugerido recebimento iniciado conclua concluido minutos segundos horas dias numero total form front main execu demonstracao queremos leva hoje falta pedidos pedido area liberar avaliar e-mail emails glpi plugin';

    private static ?array $stop = null;

    private static function strip(string $s): string
    {
        $t = html_entity_decode(html_entity_decode($s, ENT_QUOTES | ENT_HTML5), ENT_QUOTES | ENT_HTML5);
        $t = preg_replace('/<[^>]*>/', ' ', $t);          // tags viram espaço: não colam palavras de linhas diferentes
        $t = preg_replace('/\[[^\]]{0,40}\]/u', ' ', $t);   // prefixos como [Demo], [High], [CF-00026]
        $t = preg_replace('#https?://\S+|\S+@\S+|[0-9][\w.\-:/]*#u', ' ', $t);
        return mb_strtolower($t);
    }

    private static function plain(string $w): string
    {
        return strtr($w, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ü' => 'u', 'ç' => 'c']);
    }

    /** Palavras significativas (únicas) de um texto. */
    public static function words(string $text): array
    {
        self::$stop ??= array_flip(explode(' ', self::STOP));
        preg_match_all('/\p{L}[\p{L}\-]{3,}/u', self::strip($text), $m);
        $out = [];
        foreach ($m[0] as $w) {
            $w = trim($w, '-');
            if (mb_strlen($w) < 4 || mb_strlen($w) > 30 || isset(self::$stop[self::plain($w)])) {
                continue;
            }
            $out[$w] = true;
        }
        return array_keys($out);
    }

    /** Alertas de monitoramento (Zabbix, Wazuh) e chamados de processo de outros módulos: não são pedidos de iniciativa. */
    public static function isAutomated(string $name): bool
    {
        return (bool) preg_match('/^\[(High|Warning|Information|Average|Disaster|Not classified|Wazuh|Zabbix)\]|\b(SRV|Wazuh|Zabbix)\s*\||^\[[A-Z]{2}-\d+\]/iu', trim($name));
    }

    /** Chamados abertos sem iniciativa, com solicitante e palavras. */
    public static function openTickets(int $limit = 200): array
    {
        global $DB;
        $linked = [];
        foreach ($DB->request(['SELECT' => ['items_id'], 'FROM' => Db::t('item_links'), 'WHERE' => ['itemtype' => 'Ticket']]) as $r) {
            $linked[] = (int) $r['items_id'];
        }
        $where = ['is_deleted' => 0, 'status' => [\Ticket::INCOMING, \Ticket::ASSIGNED, \Ticket::PLANNED, \Ticket::WAITING]] + getEntitiesRestrictCriteria('glpi_tickets');
        if ($linked) {
            $where[] = ['NOT' => ['id' => $linked]];
        }
        $out = [];
        foreach ($DB->request(['FROM' => 'glpi_tickets', 'WHERE' => $where, 'ORDER' => ['date DESC'], 'LIMIT' => $limit]) as $r) {
            $tk = new \Ticket();
            if (!$tk->getFromDB((int) $r['id']) || !$tk->canViewItem()) {
                continue;
            }
            $req = '';
            foreach ($DB->request(['SELECT' => ['users_id'], 'FROM' => 'glpi_tickets_users', 'WHERE' => ['tickets_id' => (int) $r['id'], 'type' => \CommonITILActor::REQUESTER], 'LIMIT' => 1]) as $u) {
                $req = Db::userName((int) $u['users_id']);
            }
            $out[] = ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'date' => (string) $r['date'], 'status' => (int) $r['status'], 'requester' => $req,
                'priority' => (int) $r['priority'], 'auto' => self::isAutomated((string) $r['name']), 'new' => (time() - strtotime($r['date'])) <= 48 * 3600, 'words' => self::words($r['name'] . ' ' . $r['content'])];
        }
        return $out;
    }
}
