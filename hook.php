<?php

use GlpiPlugin\Vmo\Access;
use GlpiPlugin\Vmo\Audit;
use GlpiPlugin\Vmo\Db;
use GlpiPlugin\Vmo\Settings;

/** Tabelas do plugin, na ordem de criação (nomes sem o prefixo glpi_plugin_vmo_). */
const PLUGIN_VMO_TABLES = [
    'initiatives', 'tasks', 'task_updates', 'dependencies', 'departments', 'initiative_departments', 'participants',
    'delegations', 'catalogs', 'baselines', 'changes', 'approvals', 'costs', 'benefits', 'allocations', 'benefit_plans',
    'measurements', 'indicators', 'indicator_values', 'risks', 'decisions', 'fx_rates', 'audit_events', 'snapshots',
    'item_links', 'outbox', 'checkpoints', 'minutes', 'charters', 'raci', 'records', 'signatures', 'connections',
];

function plugin_vmo_install(): bool
{
    global $DB;

    $charset   = DBConnection::getDefaultCharset();
    $collation = DBConnection::getDefaultCollation();
    $sign      = DBConnection::getDefaultPrimaryKeySignOption();
    $opts      = "ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collation ROW_FORMAT=DYNAMIC";
    $int       = "int $sign NOT NULL DEFAULT 0";
    $money     = 'decimal(19,4) NOT NULL DEFAULT 0';
    $ts        = '`date_creation` timestamp NULL DEFAULT NULL, `date_mod` timestamp NULL DEFAULT NULL';
    $id        = "`id` int $sign NOT NULL AUTO_INCREMENT";

    $tables = [
        // iniciativa: unidade principal do portfólio. Datas Y-m-d (competência/calendário), timestamps em UTC.
        'initiatives' => "$id, `entities_id` $int, `is_recursive` tinyint NOT NULL DEFAULT 0, `code` varchar(30) NOT NULL, `name` varchar(255) NOT NULL,
            `description` text, `problem` text, `objective` text, `justification` text, `business_case` text, `opportunity` text, `brainstorm_notes` text,
            `catalogs_id_objective` $int, `catalogs_id_category` $int, `priority` tinyint NOT NULL DEFAULT 3,
            `status` varchar(12) NOT NULL DEFAULT 'draft', `prev_status` varchar(12) DEFAULT NULL, `confidentiality` tinyint NOT NULL DEFAULT 0,
            `users_id_manager` $int, `users_id_sponsor` $int, `users_id_author` $int, `currency` char(3) NOT NULL DEFAULT 'BRL',
            `planned_start` date DEFAULT NULL, `planned_end` date DEFAULT NULL, `forecast_end` date DEFAULT NULL, `actual_start` date DEFAULT NULL, `actual_end` date DEFAULT NULL,
            `progress_method` varchar(6) NOT NULL DEFAULT 'auto', `progress_manual` decimal(7,3) DEFAULT NULL, `progress_justification` varchar(500) DEFAULT NULL,
            `review_date` date DEFAULT NULL, `status_reason` text, `baseline_revision` int NOT NULL DEFAULT 0, `last_update_at` timestamp NULL DEFAULT NULL,
            `version` int NOT NULL DEFAULT 1, `is_deleted` tinyint NOT NULL DEFAULT 0, $ts,
            PRIMARY KEY (`id`), UNIQUE KEY `entity_code` (`entities_id`,`code`), KEY `status_idx` (`entities_id`,`status`,`date_mod`), KEY `manager` (`users_id_manager`), KEY `sponsor` (`users_id_sponsor`)",
        'tasks' => "$id, `initiatives_id` $int, `parent_id` $int, `name` varchar(255) NOT NULL, `description` text, `users_id_responsible` $int, `groups_id` $int,
            `status` varchar(10) NOT NULL DEFAULT 'todo', `priority` tinyint NOT NULL DEFAULT 3, `planned_start` date DEFAULT NULL, `planned_end` date DEFAULT NULL,
            `actual_start` date DEFAULT NULL, `actual_end` date DEFAULT NULL, `effort_plan` decimal(10,2) NOT NULL DEFAULT 0, `effort_actual` decimal(10,2) NOT NULL DEFAULT 0,
            `weight` decimal(7,3) NOT NULL DEFAULT 1, `progress` decimal(7,3) NOT NULL DEFAULT 0, `is_milestone` tinyint NOT NULL DEFAULT 0, `is_required` tinyint NOT NULL DEFAULT 1,
            `checklist` text, `version` int NOT NULL DEFAULT 1, `is_deleted` tinyint NOT NULL DEFAULT 0, $ts,
            PRIMARY KEY (`id`), KEY `initiative_idx` (`initiatives_id`,`status`,`planned_end`), KEY `parent_id` (`parent_id`), KEY `responsible` (`users_id_responsible`)",
        'task_updates' => "$id, `tasks_id` $int, `initiatives_id` $int, `progress` decimal(7,3) NOT NULL DEFAULT 0, `effort_delta` decimal(10,2) NOT NULL DEFAULT 0,
            `reference_date` date DEFAULT NULL, `comment` text, `evidence` varchar(500) DEFAULT NULL, `users_id` $int, `date_creation` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`), KEY `tasks_id` (`tasks_id`), KEY `initiatives_id` (`initiatives_id`)",
        // dependência término→início entre tarefas OU entre iniciativas (um par de tipos por registro); defasagem em dias úteis
        'dependencies' => "$id, `entities_id` $int, `kind` varchar(10) NOT NULL DEFAULT 'task', `pred_id` $int, `succ_id` $int, `lag_days` int NOT NULL DEFAULT 0,
            `is_hard` tinyint NOT NULL DEFAULT 1, `exception_reason` varchar(500) DEFAULT NULL, `exception_users_id` $int, `exception_at` timestamp NULL DEFAULT NULL, `date_creation` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`), UNIQUE KEY `pair` (`kind`,`pred_id`,`succ_id`), KEY `succ` (`kind`,`succ_id`)",
        // conexão de integração entre iniciativas (plugins) mostrada no roadmap; a dependência de cronograma continua em 'dependencies'
        'connections' => "$id, `entities_id` $int, `from_id` $int, `to_id` $int, `label` varchar(120) NOT NULL, `detail` varchar(500) DEFAULT NULL, `two_way` tinyint NOT NULL DEFAULT 0, `is_active` tinyint NOT NULL DEFAULT 1,
            `users_id` $int, `date_creation` timestamp NULL DEFAULT NULL, PRIMARY KEY (`id`), UNIQUE KEY `pair` (`from_id`,`to_id`,`label`), KEY `to_id` (`to_id`)",
        'departments' => "$id, `entities_id` $int, `is_recursive` tinyint NOT NULL DEFAULT 0, `code` varchar(30) NOT NULL, `name` varchar(255) NOT NULL, `parent_id` $int,
            `users_id_responsible` $int, `is_active` tinyint NOT NULL DEFAULT 1, $ts,
            PRIMARY KEY (`id`), UNIQUE KEY `entity_code` (`entities_id`,`code`)",
        'initiative_departments' => "$id, `initiatives_id` $int, `departments_id` $int, `role` varchar(20) NOT NULL DEFAULT 'beneficiary', `users_id_responsible` $int,
            `participation_percent` decimal(7,3) NOT NULL DEFAULT 0, `date_creation` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`), UNIQUE KEY `uniq` (`initiatives_id`,`departments_id`,`role`)",
        // equipes (grupos GLPI) e participantes; associar um grupo não concede acesso por si só
        'participants' => "$id, `initiatives_id` $int, `groups_id` $int, `users_id` $int, `role` varchar(20) NOT NULL DEFAULT 'member', `valid_from` date DEFAULT NULL, `valid_to` date DEFAULT NULL,
            `date_creation` timestamp NULL DEFAULT NULL, PRIMARY KEY (`id`), KEY `initiatives_id` (`initiatives_id`), KEY `users_id` (`users_id`)",
        // delegação de aprovação com vigência (RN07)
        'delegations' => "$id, `entities_id` $int, `users_id_from` $int, `users_id_to` $int, `valid_from` date NOT NULL, `valid_to` date NOT NULL, `reason` varchar(255) DEFAULT NULL,
            `date_creation` timestamp NULL DEFAULT NULL, PRIMARY KEY (`id`), KEY `users_id_to` (`users_id_to`)",
        // objetivos estratégicos e categorias (kind = objective | category | benefit_category | cost_category)
        'catalogs' => "$id, `entities_id` $int, `is_recursive` tinyint NOT NULL DEFAULT 1, `kind` varchar(20) NOT NULL, `code` varchar(30) NOT NULL, `name` varchar(255) NOT NULL,
            `parent_id` $int, `is_active` tinyint NOT NULL DEFAULT 1, $ts,
            PRIMARY KEY (`id`), UNIQUE KEY `uniq` (`entities_id`,`kind`,`code`)",
        // baseline aprovada: imutável (nunca atualizada); mudanças geram nova revisão
        'baselines' => "$id, `initiatives_id` $int, `revision` int NOT NULL, `scope` text, `planned_start` date DEFAULT NULL, `planned_end` date DEFAULT NULL,
            `budget_json` text, `currency` char(3) NOT NULL DEFAULT 'BRL', `benefit_plan_snapshot` mediumtext, `tasks_snapshot` mediumtext, `formula_version` varchar(10) NOT NULL,
            `thresholds_version` int NOT NULL DEFAULT 1, `approvals_id` $int, `changes_id` $int, `reason` text, `users_id_approver` $int, `approved_at` timestamp NULL DEFAULT NULL, `hash` char(64) NOT NULL,
            PRIMARY KEY (`id`), UNIQUE KEY `rev` (`initiatives_id`,`revision`)",
        // solicitação de mudança de baseline (prazo, escopo, custo, benefício)
        'changes' => "$id, `initiatives_id` $int, `type` varchar(10) NOT NULL, `proposed` text, `justification` text NOT NULL, `status` varchar(10) NOT NULL DEFAULT 'pending',
            `base_revision` int NOT NULL DEFAULT 0, `users_id_requester` $int, `users_id_decider` $int, `decision_comment` text, `decided_at` timestamp NULL DEFAULT NULL, `date_creation` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`), KEY `initiatives_id` (`initiatives_id`,`status`)",
        // aprovação da proposta (submissão → decisão), uma linha por revisão da solicitação
        'approvals' => "$id, `initiatives_id` $int, `revision` int NOT NULL DEFAULT 1, `status` varchar(10) NOT NULL DEFAULT 'pending', `users_id_requester` $int, `users_id_decider` $int,
            `comment` text, `snapshot` mediumtext, `submitted_version` int NOT NULL DEFAULT 0, `date_creation` timestamp NULL DEFAULT NULL, `decided_at` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`), UNIQUE KEY `rev` (`initiatives_id`,`revision`)",
        // kind: forecast (previsão atual) | actual (lançamento real, por competência). Estorno = linha negativa ligada à original.
        'costs' => "$id, `initiatives_id` $int, `kind` varchar(8) NOT NULL, `category` varchar(60) DEFAULT NULL, `cost_type` varchar(5) NOT NULL DEFAULT 'opex',
            `competence_date` date NOT NULL, `amount` $money, `currency` char(3) NOT NULL DEFAULT 'BRL', `description` varchar(255) DEFAULT NULL, `source` varchar(30) NOT NULL DEFAULT 'manual',
            `external_key` varchar(80) DEFAULT NULL, `reversal_of_id` $int, `evidence` varchar(500) DEFAULT NULL, `users_id` $int, `date_creation` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`), UNIQUE KEY `origin_key` (`source`,`external_key`), KEY `initiative_idx` (`initiatives_id`,`kind`,`competence_date`)",
        'benefits' => "$id, `entities_id` $int, `is_recursive` tinyint NOT NULL DEFAULT 0, `economic_key` varchar(60) NOT NULL, `name` varchar(255) NOT NULL, `description` text,
            `type` varchar(12) NOT NULL DEFAULT 'financial', `unit` varchar(40) DEFAULT NULL, `currency` char(3) DEFAULT NULL, `direction` varchar(8) NOT NULL DEFAULT 'increase',
            `baseline_value` decimal(19,4) DEFAULT NULL, `target` decimal(19,4) DEFAULT NULL, `horizon_start` date DEFAULT NULL, `horizon_end` date DEFAULT NULL,
            `cadence` varchar(10) NOT NULL DEFAULT 'monthly', `method` text, `source` varchar(255) DEFAULT NULL, `rubric` text, `attribution_note` varchar(255) DEFAULT NULL,
            `users_id_owner` $int, `users_id_validator` $int, `users_id_author` $int, `users_id_approver` $int, `status` varchar(10) NOT NULL DEFAULT 'draft', `approved_at` timestamp NULL DEFAULT NULL,
            `version` int NOT NULL DEFAULT 1, `is_deleted` tinyint NOT NULL DEFAULT 0, $ts,
            PRIMARY KEY (`id`), UNIQUE KEY `entity_key` (`entities_id`,`economic_key`), KEY `owner` (`users_id_owner`)",
        // alocação do benefício entre iniciativas: a soma vigente não excede 100% (portfólio soma o benefício uma vez)
        'allocations' => "$id, `benefits_id` $int, `initiatives_id` $int, `percent` decimal(7,3) NOT NULL, `valid_from` date DEFAULT NULL, `valid_to` date DEFAULT NULL, `date_creation` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`), UNIQUE KEY `uniq` (`benefits_id`,`initiatives_id`), KEY `initiatives_id` (`initiatives_id`)",
        'benefit_plans' => "$id, `benefits_id` $int, `period` date NOT NULL, `planned_amount` $money, `revision` int NOT NULL DEFAULT 1, `baselines_id` $int, `date_creation` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`), UNIQUE KEY `uniq` (`benefits_id`,`period`)",
        // medição: declarado × validado; cada retificação cria revisão (is_current marca a vigente)
        'measurements' => "$id, `benefits_id` $int, `period` date NOT NULL, `observed_value` decimal(19,4) DEFAULT NULL, `declared_amount` $money, `validated_amount` decimal(19,4) DEFAULT NULL,
            `status` varchar(10) NOT NULL DEFAULT 'declared', `evidence` varchar(500) DEFAULT NULL, `comment` text, `revision` int NOT NULL DEFAULT 1, `supersedes_id` $int, `is_current` tinyint NOT NULL DEFAULT 1,
            `users_id_author` $int, `users_id_validator` $int, `validation_comment` text, `validated_at` timestamp NULL DEFAULT NULL, `date_creation` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`), KEY `benefit_idx` (`benefits_id`,`period`,`status`)",
        'indicators' => "$id, `entities_id` $int, `is_recursive` tinyint NOT NULL DEFAULT 0, `initiatives_id` $int, `code` varchar(30) NOT NULL, `name` varchar(255) NOT NULL, `unit` varchar(30) DEFAULT NULL,
            `direction` varchar(8) NOT NULL DEFAULT 'increase', `baseline_value` decimal(19,4) DEFAULT NULL, `target` decimal(19,4) DEFAULT NULL, `cadence_days` int NOT NULL DEFAULT 30, `source` varchar(255) DEFAULT NULL,
            `users_id_owner` $int, `thresholds` varchar(255) DEFAULT NULL, `is_deleted` tinyint NOT NULL DEFAULT 0, $ts,
            PRIMARY KEY (`id`), UNIQUE KEY `entity_code` (`entities_id`,`code`)",
        'indicator_values' => "$id, `indicators_id` $int, `period` date NOT NULL, `observed` decimal(19,4) NOT NULL, `quality` varchar(10) NOT NULL DEFAULT 'manual', `source` varchar(30) NOT NULL DEFAULT 'manual',
            `external_key` varchar(80) DEFAULT NULL, `users_id` $int, `date_creation` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`), UNIQUE KEY `uniq` (`indicators_id`,`period`), UNIQUE KEY `origin_key` (`source`,`external_key`)",
        'risks' => "$id, `initiatives_id` $int, `kind` varchar(8) NOT NULL DEFAULT 'risk', `title` varchar(255) NOT NULL, `cause` text, `impact_text` text, `probability` tinyint NOT NULL DEFAULT 3, `impact` tinyint NOT NULL DEFAULT 3,
            `response` text, `users_id_owner` $int, `due_date` date DEFAULT NULL, `status` varchar(10) NOT NULL DEFAULT 'open', `outcome` text, `version` int NOT NULL DEFAULT 1, `is_deleted` tinyint NOT NULL DEFAULT 0, $ts,
            PRIMARY KEY (`id`), KEY `initiative_idx` (`initiatives_id`,`status`)",
        'decisions' => "$id, `initiatives_id` $int, `title` varchar(255) NOT NULL, `decision_text` text, `status` varchar(10) NOT NULL DEFAULT 'decided', `users_id` $int, `decided_at` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`), KEY `initiatives_id` (`initiatives_id`)",
        // taxa de câmbio com data e fonte (RN08); sem taxa, os totais ficam separados por moeda
        'fx_rates' => "$id, `entities_id` $int, `is_recursive` tinyint NOT NULL DEFAULT 1, `from_currency` char(3) NOT NULL, `to_currency` char(3) NOT NULL, `rate_date` date NOT NULL,
            `rate` decimal(19,8) NOT NULL, `source` varchar(120) NOT NULL, `users_id` $int, `date_creation` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`), UNIQUE KEY `uniq` (`entities_id`,`from_currency`,`to_currency`,`rate_date`)",
        // só inserção; nenhuma tela edita a trilha
        'audit_events' => "$id, `entities_id` $int, `itemtype` varchar(30) NOT NULL, `items_id` $int, `action` varchar(30) NOT NULL, `users_id` $int, `version` int NOT NULL DEFAULT 0,
            `diff` mediumtext, `correlation_id` varchar(32) DEFAULT NULL, `date_creation` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`), KEY `target` (`entities_id`,`itemtype`,`items_id`,`date_creation`), KEY `date_creation` (`date_creation`)",
        // relatório: cópia consistente dos números no corte; reprocessar o snapshot gera os mesmos valores (hash)
        'snapshots' => "$id, `entities_id` $int, `users_id` $int, `title` varchar(255) NOT NULL, `filters` text, `cutoff` datetime NOT NULL, `formula_version` varchar(10) NOT NULL,
            `thresholds_version` int NOT NULL DEFAULT 1, `payload` longtext, `hash` char(64) NOT NULL, `status` varchar(10) NOT NULL DEFAULT 'ready', `error` varchar(255) DEFAULT NULL,
            `expires_at` timestamp NULL DEFAULT NULL, `date_creation` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`), KEY `users_id` (`users_id`), KEY `expires_at` (`expires_at`)",
        // vínculos consultivos com objetos do GLPI (tickets, projetos); exige acesso aos dois lados
        'item_links' => "$id, `initiatives_id` $int, `itemtype` varchar(30) NOT NULL, `items_id` $int, `relation` varchar(20) NOT NULL DEFAULT 'related', `users_id` $int, `date_creation` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`), UNIQUE KEY `uniq` (`initiatives_id`,`itemtype`,`items_id`)",
        // checkpoint quinzenal por iniciativa: memória do portfólio e base do histórico de farol
        'checkpoints' => "$id, `initiatives_id` $int, `checkpoint_date` date NOT NULL, `users_id` $int, `progress` decimal(7,3) DEFAULT NULL, `farol_declared` varchar(10) DEFAULT NULL,
            `advanced` text, `blocker` text, `has_block` tinyint NOT NULL DEFAULT 0, `action_needed` text, `needs_committee` tinyint NOT NULL DEFAULT 0, `date_creation` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`), KEY `initiatives_id` (`initiatives_id`,`checkpoint_date`)",
        // atas do comitê (arquivo anexo): o conteúdo fica em files/_plugins/vmo/atas, fora do endereço público, com nome aleatório
        'minutes' => "$id, `entities_id` $int, `meeting_date` date NOT NULL, `title` varchar(255) NOT NULL, `notes` text, `original_name` varchar(255) NOT NULL, `stored_name` varchar(80) NOT NULL,
            `mime` varchar(120) DEFAULT NULL, `size` int $sign NOT NULL DEFAULT 0, `users_id` $int, `date_creation` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`), KEY `meeting_date` (`meeting_date`)",
        // TAP (termo de abertura): um por iniciativa, com revisão a cada emissão
        'charters' => "$id, `initiatives_id` $int, `entities_id` $int, `requester_users_id` $int, `requester_name` varchar(255) DEFAULT NULL, `follower_users_id` $int,
            `objective` text, `scope` text, `out_of_scope` text, `deliverables` text, `premises` text, `start_date` date DEFAULT NULL, `delivery_date` date DEFAULT NULL,
            `status` varchar(10) NOT NULL DEFAULT 'draft', `revision` int NOT NULL DEFAULT 0, `issued_at` timestamp NULL DEFAULT NULL, `issued_by` $int, $ts,
            PRIMARY KEY (`id`), UNIQUE KEY `initiative` (`initiatives_id`)",
        // RACI por tarefa: um papel por pessoa em cada tarefa
        'raci' => "$id, `initiatives_id` $int, `tasks_id` $int, `users_id` $int, `role` char(1) NOT NULL, `date_creation` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`), UNIQUE KEY `task_user` (`tasks_id`,`users_id`), KEY `initiatives_id` (`initiatives_id`)",
        // registros das etapas da iniciativa (partes interessadas, status report, requisição de mudança, aceite, lições, encerramento): dados em JSON conforme o tipo
        'records' => "$id, `initiatives_id` $int, `entities_id` $int, `kind` varchar(12) NOT NULL, `title` varchar(255) NOT NULL, `record_date` date DEFAULT NULL, `status` varchar(12) NOT NULL DEFAULT 'draft',
            `data` mediumtext, `users_id` $int, `signed_at` timestamp NULL DEFAULT NULL, `signed_by` $int, `version` int NOT NULL DEFAULT 1, `is_deleted` tinyint NOT NULL DEFAULT 0, $ts,
            PRIMARY KEY (`id`), KEY `initiative_kind` (`initiatives_id`,`kind`)",
        // assinaturas eletrônicas do TAP: uma por pessoa e revisão, com hash do documento e do ato
        'signatures' => "$id, `initiatives_id` $int, `charters_id` $int, `revision` int NOT NULL DEFAULT 1, `users_id` $int, `role_label` varchar(60) DEFAULT NULL, `signed_at` timestamp NULL DEFAULT NULL,
            `ip` varchar(45) DEFAULT NULL, `doc_hash` char(64) NOT NULL, `sign_hash` char(64) NOT NULL,
            PRIMARY KEY (`id`), UNIQUE KEY `once` (`charters_id`,`revision`,`users_id`), KEY `initiatives_id` (`initiatives_id`)",
        // fila de avisos: dedup_key único evita duplicar o aviso do mesmo evento ao reexecutar a rotina
        'outbox' => "$id, `entities_id` $int, `users_id` $int, `type` varchar(30) NOT NULL, `itemtype` varchar(30) DEFAULT NULL, `items_id` $int, `title` varchar(255) NOT NULL, `body` varchar(500) DEFAULT NULL,
            `dedup_key` varchar(120) NOT NULL, `status` varchar(10) NOT NULL DEFAULT 'pending', `attempts` int NOT NULL DEFAULT 0, `date_creation` timestamp NULL DEFAULT NULL, `read_at` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`), UNIQUE KEY `dedup_key` (`dedup_key`), KEY `users_id` (`users_id`,`read_at`)",
    ];
    foreach ($tables as $name => $def) {
        $table = Db::t($name);
        if (!$DB->tableExists($table)) {
            $DB->doQuery("CREATE TABLE `$table` ($def) $opts");
        }
    }

    // 0.2.0: conexões podem ser desligadas sem apagar
    if ($DB->tableExists(Db::t('connections')) && !$DB->fieldExists(Db::t('connections'), 'is_active')) {
        $DB->doQuery('ALTER TABLE `' . Db::t('connections') . '` ADD `is_active` tinyint NOT NULL DEFAULT 1 AFTER `two_way`');
    }

    // configuração padrão (mantida nas atualizações)
    $defaults = ['currency' => 'BRL', 'cadence_days' => '14', 'report_retention_days' => '90', 'audit_retention_months' => '24', 'holidays' => '', 'thresholds_version' => '1'];
    $missing = array_diff_key($defaults, Config::getConfigurationValues(Settings::CONTEXT));
    if ($missing) {
        Config::setConfigurationValues(Settings::CONTEXT, $missing);
    }

    // direitos: ninguém recebe aprovação, validação, finanças ou configuração implicitamente;
    // super-admin (quem pode alterar a configuração do GLPI) recebe tudo, os demais perfis centrais só leem/criam/atualizam.
    $all   = READ | CREATE | UPDATE | DELETE | PURGE | Access::APPROVE | Access::VALIDATE | Access::FINANCE | Access::CONFIGURE;
    $basic = READ | CREATE | UPDATE;
    if (!countElementsInTable(ProfileRight::getTable(), ['name' => Access::$rightname])) {
        ProfileRight::addProfileRights([Access::$rightname]);
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => Profile::getTable(), 'WHERE' => ['interface' => 'central']]) as $row) {
            $config = ProfileRight::getProfileRights($row['id'], ['config'])['config'] ?? 0;
            $DB->update(ProfileRight::getTable(), ['rights' => ($config & UPDATE) ? $all : $basic], ['profiles_id' => $row['id'], 'name' => Access::$rightname]);
        }
    }
    if (isset($_SESSION['glpiactiveprofile']['id'])) {
        $current = ProfileRight::getProfileRights($_SESSION['glpiactiveprofile']['id'], [Access::$rightname]);
        $_SESSION['glpiactiveprofile'][Access::$rightname] = (int) ($current[Access::$rightname] ?? 0);
    }
    unset($_SESSION['glpimenu']);

    // rotinas agendadas (avisos de atraso e expurgo por retenção)
    CronTask::register('GlpiPlugin\\Vmo\\Jobs', 'vmonotify', DAY_TIMESTAMP / 4, [
        'comment' => 'VMO: avisos de tarefas atrasadas, medições vencidas e aprovações pendentes',
        'mode'    => CronTask::MODE_EXTERNAL,
        'state'   => CronTask::STATE_WAITING,
    ]);
    CronTask::register('GlpiPlugin\\Vmo\\Jobs', 'vmoretention', DAY_TIMESTAMP, [
        'comment' => 'VMO: expira relatórios gerados e trilha de auditoria conforme a retenção configurada',
        'mode'    => CronTask::MODE_EXTERNAL,
        'state'   => CronTask::STATE_WAITING,
    ]);

    // instalações anteriores: campos do brainstorm (oportunidade) na iniciativa
    $initTable = Db::t('initiatives');
    foreach (['opportunity', 'brainstorm_notes'] as $col) {
        if (!$DB->fieldExists($initTable, $col)) {
            $DB->doQuery("ALTER TABLE `$initTable` ADD COLUMN `$col` text NULL");
        }
    }
    // atas anexadas: além das do comitê, a ata do brainstorm fica presa à iniciativa
    $minTable = Db::t('minutes');
    foreach (['initiatives_id' => "int $sign NOT NULL DEFAULT 0", 'kind' => "varchar(12) NOT NULL DEFAULT 'committee'"] as $col => $def) {
        if (!$DB->fieldExists($minTable, $col)) {
            $DB->doQuery("ALTER TABLE `$minTable` ADD COLUMN `$col` $def");
        }
    }
    // custos: valor/hora e total de horas (valor = valor/hora × horas)
    $costTable = Db::t('costs');
    foreach (['hourly_rate' => 'decimal(19,4) NULL', 'hours' => 'decimal(10,2) NULL'] as $col => $def) {
        if (!$DB->fieldExists($costTable, $col)) {
            $DB->doQuery("ALTER TABLE `$costTable` ADD COLUMN `$col` $def");
        }
    }
    // ata do comitê: a decisão ganha data da reunião, tipo, responsável, prazo e conclusão (iniciativa 0 = tema geral)
    $decTable = Db::t('decisions');
    foreach (['committee_date' => 'date NULL', 'decision_kind' => 'varchar(20) NULL', 'users_id_owner' => "int $sign NOT NULL DEFAULT 0", 'due_date' => 'date NULL', 'is_done' => 'tinyint NOT NULL DEFAULT 0'] as $col => $def) {
        if (!$DB->fieldExists($decTable, $col)) {
            $DB->doQuery("ALTER TABLE `$decTable` ADD COLUMN `$col` $def");
        }
    }
    // portfólio estratégico (planilha do escritório de estratégia): perspectiva, indicador, benefício, faixa, notas de priorização e filtro de renúncia
    $strategic = ['catalogs_id_perspective' => "int $sign NOT NULL DEFAULT 0", 'catalogs_id_owner' => "int $sign NOT NULL DEFAULT 0", 'indicator' => 'varchar(255) NULL', 'baseline_value' => 'varchar(100) NULL', 'target_value' => 'varchar(100) NULL',
        'benefit_type' => 'varchar(30) NULL', 'benefit_declared' => 'varchar(255) NULL', 'areas' => 'varchar(255) NULL', 'okr_cycle' => 'varchar(20) NULL', 'band' => 'char(1) NULL',
        'sc_strategic' => 'tinyint NULL', 'sc_impact' => 'tinyint NULL', 'sc_window' => 'tinyint NULL', 'sc_feasibility' => 'tinyint NULL', 'sc_risk' => 'tinyint NULL',
        'waiver' => 'varchar(40) NULL', 'requested_by' => 'varchar(255) NULL', 'delivery_stage' => "varchar(10) NOT NULL DEFAULT 'wip'"];
    foreach ($strategic as $col => $def) {
        if (!$DB->fieldExists($initTable, $col)) {
            $DB->doQuery("ALTER TABLE `$initTable` ADD COLUMN `$col` $def");
        }
    }

    // etapas da ficha: TAP completo (e o que foi enviado ao comitê), risco ligado a tarefa/entrega e anexos por etapa
    foreach (['charters' => ['constraints' => 'text NULL', 'risks_initial' => 'text NULL', 'success_criteria' => 'text NULL', 'budget_note' => 'text NULL', 'snapshot' => 'mediumtext NULL', 'sent_to' => 'varchar(255) NULL', 'doc_id' => "int $sign NOT NULL DEFAULT 0"]] as $tbl => $cols) {
        foreach ($cols as $col => $def) {
            if (!$DB->fieldExists(Db::t($tbl), $col)) {
                $DB->doQuery("ALTER TABLE `" . Db::t($tbl) . "` ADD COLUMN `$col` $def");
            }
        }
    }
    foreach ([['risks', 'tasks_id', "int $sign NOT NULL DEFAULT 0"], ['risks', 'delivery', 'varchar(255) NULL'], ['minutes', 'section', 'varchar(12) NULL']] as [$tbl, $col, $def]) {
        if (!$DB->fieldExists(Db::t($tbl), $col)) {
            $DB->doQuery("ALTER TABLE `" . Db::t($tbl) . "` ADD COLUMN `$col` $def");
        }
    }

    // portal nativo do GLPI: um cartão de exemplo "Nova iniciativa" (falha aqui não impede a instalação)
    try {
        \GlpiPlugin\Vmo\Portal::ensureExample();
    } catch (\Throwable $e) {
        trigger_error('VMO: cartão do portal não criado: ' . $e->getMessage(), E_USER_WARNING);
    }

    try {
        \GlpiPlugin\Vmo\Escalation::registerTypes();   // os tipos já aparecem nas regras do Escalations
    } catch (\Throwable $e) {
    }

    Audit::log('Plugin', 0, 'install', ['version' => PLUGIN_VMO_VERSION], 0, 0);
    return true;
}

/**
 * Desinstalar NÃO apaga dados por padrão (RF24): só retira direitos e rotinas. Para expurgar as tabelas
 * é preciso confirmar de forma explícita com a variável VMO_PURGE=1 (ou pela tela de Configuração).
 */
function plugin_vmo_uninstall(): bool
{
    global $DB;
    CronTask::unregister('vmo');
    try {
        \GlpiPlugin\Vmo\Portal::remove();
    } catch (\Throwable $e) {
    }
    ProfileRight::deleteProfileRights([Access::$rightname]);
    unset($_SESSION['glpimenu']);
    if (getenv('VMO_PURGE') === '1') {
        foreach (array_reverse(PLUGIN_VMO_TABLES) as $name) {
            $DB->dropTable(Db::t($name), true);
        }
        Config::deleteConfigurationValues(Settings::CONTEXT);
    }
    return true;
}
