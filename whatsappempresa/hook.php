<?php
/**
 * whatsappempresa - instalacao, desinstalacao e ganchos
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access this file directly");
}

function plugin_whatsappempresa_coluna(string $tabela, string $coluna, string $definicao): void {
   global $DB;

   if (!$DB->tableExists($tabela)) {
      return;
   }

   $resultado = $DB->doQuery("SHOW COLUMNS FROM `$tabela` LIKE '$coluna'");
   if ($resultado && $DB->numrows($resultado) === 0) {
      $DB->doQuery("ALTER TABLE `$tabela` ADD `$coluna` $definicao");
   }
}

function plugin_whatsappempresa_indice(string $tabela, string $indice, string $coluna): void {
   global $DB;

   if (!$DB->tableExists($tabela)) {
      return;
   }

   $resultado = $DB->doQuery("SHOW INDEX FROM `$tabela` WHERE Key_name = '$indice'");
   if ($resultado && $DB->numrows($resultado) === 0) {
      $DB->doQuery("ALTER TABLE `$tabela` ADD KEY `$indice` (`$coluna`)");
   }
}

function plugin_whatsappempresa_alargar(string $tabela, string $coluna, string $definicao): void {
   global $DB;

   if (!$DB->tableExists($tabela)) {
      return;
   }

   $resultado = $DB->doQuery("SHOW COLUMNS FROM `$tabela` LIKE '$coluna'");
   if ($resultado && $DB->numrows($resultado) > 0) {
      $DB->doQuery("ALTER TABLE `$tabela` MODIFY `$coluna` $definicao");
   }
}

/**
 * Ajusta as tabelas de instalacoes anteriores e preenche a chave de comparacao
 */
function plugin_whatsappempresa_migrar(): void {
   global $DB;

   $sessoes   = 'glpi_plugin_whatsappempresa_sessoes';
   $conversas = 'glpi_plugin_whatsappempresa_conversas';
   $mensagens = 'glpi_plugin_whatsappempresa_mensagens';

   plugin_whatsappempresa_coluna($sessoes, 'jid', "varchar(80) DEFAULT NULL");
   plugin_whatsappempresa_coluna($sessoes, 'chave', "varchar(20) DEFAULT NULL");
   plugin_whatsappempresa_coluna($sessoes, 'fluxo', "varchar(30) NOT NULL DEFAULT 'menu'");
   plugin_whatsappempresa_coluna($sessoes, 'conversas_id', "int unsigned NOT NULL DEFAULT 0");
   plugin_whatsappempresa_coluna($sessoes, 'tickets_id', "int unsigned NOT NULL DEFAULT 0");
   plugin_whatsappempresa_coluna($sessoes, 'autenticado_ate', "timestamp NULL DEFAULT NULL");
   plugin_whatsappempresa_coluna($sessoes, 'fluxos_id', "int unsigned NOT NULL DEFAULT 0");
   plugin_whatsappempresa_coluna($sessoes, 'passo', "varchar(40) DEFAULT NULL");
   // Cliente (entidade) e contato identificados: sobrevivem as trocas de etapa
   plugin_whatsappempresa_coluna($sessoes, 'entities_id', "int unsigned NOT NULL DEFAULT 0");
   plugin_whatsappempresa_coluna($sessoes, 'contatos_id', "int unsigned NOT NULL DEFAULT 0");
   plugin_whatsappempresa_alargar($sessoes, 'fluxo', "varchar(30) NOT NULL DEFAULT 'menu'");
   plugin_whatsappempresa_alargar($sessoes, 'etapa', "varchar(60) NOT NULL DEFAULT 'inicio'");
   plugin_whatsappempresa_indice($sessoes, 'jid', 'jid');
   plugin_whatsappempresa_indice($sessoes, 'chave', 'chave');
   plugin_whatsappempresa_indice($sessoes, 'fluxo', 'fluxo');
   plugin_whatsappempresa_indice($sessoes, 'fluxos_id', 'fluxos_id');

   plugin_whatsappempresa_coluna($conversas, 'chave', "varchar(20) DEFAULT NULL");
   plugin_whatsappempresa_coluna($conversas, 'jid', "varchar(80) DEFAULT NULL");
   plugin_whatsappempresa_coluna($conversas, 'date_encerramento', "timestamp NULL DEFAULT NULL");
   plugin_whatsappempresa_coluna($conversas, 'encerrada_por', "int unsigned NOT NULL DEFAULT 0");
   plugin_whatsappempresa_coluna($conversas, 'motivo_encerramento', "varchar(255) DEFAULT NULL");
   plugin_whatsappempresa_indice($conversas, 'chave', 'chave');
   plugin_whatsappempresa_indice($conversas, 'status', 'status');

   // Identificacao de quem enviou e de quem recebeu cada mensagem
   plugin_whatsappempresa_coluna($mensagens, 'numero_host', "varchar(30) DEFAULT NULL");
   plugin_whatsappempresa_coluna($mensagens, 'numero_cliente', "varchar(30) DEFAULT NULL");
   plugin_whatsappempresa_coluna($mensagens, 'remetente', "varchar(30) DEFAULT NULL");
   plugin_whatsappempresa_coluna($mensagens, 'destinatario', "varchar(30) DEFAULT NULL");
   plugin_whatsappempresa_coluna($mensagens, 'origem_tipo', "varchar(20) NOT NULL DEFAULT 'automacao'");
   plugin_whatsappempresa_coluna($mensagens, 'fluxos_id', "int unsigned NOT NULL DEFAULT 0");
   // Imagens e audios: arquivo na pasta de midia do plugin (caminho relativo AAAAMM/arquivo)
   plugin_whatsappempresa_coluna($mensagens, 'tipo_midia', "varchar(10) DEFAULT NULL");
   plugin_whatsappempresa_coluna($mensagens, 'midia_arquivo', "varchar(255) DEFAULT NULL");
   plugin_whatsappempresa_coluna($mensagens, 'midia_mime', "varchar(100) DEFAULT NULL");
   plugin_whatsappempresa_alargar($mensagens, 'fluxo', "varchar(60) NOT NULL DEFAULT 'menu'");
   plugin_whatsappempresa_indice($mensagens, 'direcao', 'direcao');
   plugin_whatsappempresa_indice($mensagens, 'origem_tipo', 'origem_tipo');

   // Chave e os ultimos 8 digitos: e ela que liga o numero em qualquer formato
   if ($DB->tableExists($sessoes)) {
      $DB->doQuery("UPDATE `$sessoes` SET `chave` = RIGHT(`telefone`, 8) WHERE `chave` IS NULL OR `chave` = ''");
      $DB->doQuery("UPDATE `$sessoes` SET `fluxo` = 'conversa' WHERE `etapa` = 'conversa'");
      $DB->doQuery("UPDATE `$sessoes` SET `fluxo` = 'silenciado' WHERE `etapa` = 'silenciado'");
   }

   if ($DB->tableExists($conversas)) {
      $DB->doQuery("UPDATE `$conversas` SET `chave` = RIGHT(`telefone`, 8) WHERE `chave` IS NULL OR `chave` = ''");
   }

   // O WhatsApp comum descarta mensagem interativa sem avisar: texto passa a ser o padrao
   $configs = 'glpi_plugin_whatsappempresa_configs';
   if ($DB->tableExists($configs)) {
      $jaFeito = false;
      foreach ($DB->request([
         'FROM'  => $configs,
         'WHERE' => ['chave' => 'migracao_botoes'],
         'LIMIT' => 1
      ]) as $linha) {
         $jaFeito = true;
      }

      if (!$jaFeito) {
         $DB->update($configs, ['valor' => '0'], ['chave' => 'botoes_whatsapp']);

         // Textos padrao da versao anterior que nao combinam mais com o comportamento
         $trocas = [
            'msg_saida' => [
               'Voce saiu do atendimento automatico. Envie ATENDIMENTO quando quiser voltar.',
               'Voce saiu do atendimento. Envie qualquer mensagem quando quiser voltar.'
            ],
            'msg_rodape' => [
               'Digite 0 para voltar ao menu.',
               'Escolha uma das opcoes abaixo.'
            ],
            'palavras_saida' => [
               'sair, parar, encerrar, stop',
               'sair, encerrar, stop, parar, finalizar'
            ]
         ];

         foreach ($trocas as $chave => $par) {
            $DB->update($configs, ['valor' => $par[1]], ['chave' => $chave, 'valor' => $par[0]]);
         }

         $DB->insert($configs, ['chave' => 'migracao_botoes', 'valor' => '1']);
      }
   }

   // Preenche remetente e destinatario dos registros antigos
   if ($DB->tableExists($mensagens)) {
      $DB->doQuery("UPDATE `$mensagens` SET `numero_cliente` = `telefone` WHERE `numero_cliente` IS NULL OR `numero_cliente` = ''");
      $DB->doQuery("UPDATE `$mensagens` SET `remetente` = `telefone` WHERE `direcao` = 'entrada' AND (`remetente` IS NULL OR `remetente` = '')");
      $DB->doQuery("UPDATE `$mensagens` SET `destinatario` = `telefone` WHERE `direcao` = 'saida' AND (`destinatario` IS NULL OR `destinatario` = '')");
      $DB->doQuery("UPDATE `$mensagens` SET `origem_tipo` = 'humano' WHERE `direcao` = 'entrada'");
      $DB->doQuery("UPDATE `$mensagens` SET `origem_tipo` = 'humano' WHERE `direcao` = 'saida' AND `fluxo` = 'conversa' AND `users_id` > 0");
      $DB->doQuery("UPDATE `$mensagens` SET `origem_tipo` = 'sistema' WHERE `fluxo` IN ('teste', 'aviso')");
   }
}

/**
 * Classes usadas na instalacao e desinstalacao: carregadas direto porque o autoloader
 * do GLPI so atende plugins ja carregados
 */
function plugin_whatsappempresa_carregar_classes(): void {
   foreach (['listatrait', 'config', 'log', 'servidor', 'construtor', 'cliente'] as $classe) {
      require_once(__DIR__ . '/inc/' . $classe . '.class.php');
   }
}

function plugin_whatsappempresa_install(): bool {
   global $DB;

   plugin_whatsappempresa_carregar_classes();

   $charset = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC';

   if (!$DB->tableExists('glpi_plugin_whatsappempresa_configs')) {
      $DB->doQuery("CREATE TABLE `glpi_plugin_whatsappempresa_configs` (
         `id` int unsigned NOT NULL AUTO_INCREMENT,
         `chave` varchar(100) NOT NULL,
         `valor` longtext,
         `date_mod` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
         PRIMARY KEY (`id`),
         UNIQUE KEY `chave` (`chave`)
      ) $charset");
   }

   if (!$DB->tableExists('glpi_plugin_whatsappempresa_codigos')) {
      $DB->doQuery("CREATE TABLE `glpi_plugin_whatsappempresa_codigos` (
         `id` int unsigned NOT NULL AUTO_INCREMENT,
         `codigo` varchar(60) NOT NULL,
         `descricao` varchar(255) DEFAULT NULL,
         `entities_id` int unsigned NOT NULL DEFAULT 0,
         `is_ativo` tinyint(1) NOT NULL DEFAULT 1,
         `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
         `date_creation` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
         `date_mod` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
         PRIMARY KEY (`id`),
         KEY `codigo` (`codigo`),
         KEY `is_ativo` (`is_ativo`)
      ) $charset");
   }

   if (!$DB->tableExists('glpi_plugin_whatsappempresa_conversas')) {
      $DB->doQuery("CREATE TABLE `glpi_plugin_whatsappempresa_conversas` (
         `id` int unsigned NOT NULL AUTO_INCREMENT,
         `telefone` varchar(30) NOT NULL,
         `chave` varchar(20) DEFAULT NULL,
         `jid` varchar(80) DEFAULT NULL,
         `nome_contato` varchar(255) DEFAULT NULL,
         `users_id` int unsigned NOT NULL DEFAULT 0,
         `tickets_id` int unsigned NOT NULL DEFAULT 0,
         `tecnicos_id` int unsigned NOT NULL DEFAULT 0,
         `entities_id` int unsigned NOT NULL DEFAULT 0,
         `origem` varchar(30) NOT NULL DEFAULT 'cliente',
         `status` varchar(30) NOT NULL DEFAULT 'aberta',
         `nao_lidas` int unsigned NOT NULL DEFAULT 0,
         `ultima_mensagem` longtext,
         `date_ultima` timestamp NULL DEFAULT NULL,
         `date_encerramento` timestamp NULL DEFAULT NULL,
         `encerrada_por` int unsigned NOT NULL DEFAULT 0,
         `motivo_encerramento` varchar(255) DEFAULT NULL,
         `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
         `date_creation` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
         `date_mod` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
         PRIMARY KEY (`id`),
         KEY `telefone` (`telefone`),
         KEY `chave` (`chave`),
         KEY `tickets_id` (`tickets_id`),
         KEY `tecnicos_id` (`tecnicos_id`),
         KEY `status` (`status`)
      ) $charset");
   }

   if (!$DB->tableExists('glpi_plugin_whatsappempresa_mensagens')) {
      $DB->doQuery("CREATE TABLE `glpi_plugin_whatsappempresa_mensagens` (
         `id` int unsigned NOT NULL AUTO_INCREMENT,
         `conversas_id` int unsigned NOT NULL DEFAULT 0,
         `telefone` varchar(30) NOT NULL,
         `numero_host` varchar(30) DEFAULT NULL,
         `numero_cliente` varchar(30) DEFAULT NULL,
         `remetente` varchar(30) DEFAULT NULL,
         `destinatario` varchar(30) DEFAULT NULL,
         `direcao` varchar(10) NOT NULL DEFAULT 'entrada',
         `origem_tipo` varchar(20) NOT NULL DEFAULT 'automacao',
         `fluxo` varchar(60) NOT NULL DEFAULT 'menu',
         `fluxos_id` int unsigned NOT NULL DEFAULT 0,
         `conteudo` longtext,
         `tipo_midia` varchar(10) DEFAULT NULL,
         `midia_arquivo` varchar(255) DEFAULT NULL,
         `midia_mime` varchar(100) DEFAULT NULL,
         `tickets_id` int unsigned NOT NULL DEFAULT 0,
         `users_id` int unsigned NOT NULL DEFAULT 0,
         `status_envio` varchar(20) NOT NULL DEFAULT 'ok',
         `erro` varchar(255) DEFAULT NULL,
         `date_creation` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
         PRIMARY KEY (`id`),
         KEY `conversas_id` (`conversas_id`),
         KEY `telefone` (`telefone`),
         KEY `direcao` (`direcao`),
         KEY `origem_tipo` (`origem_tipo`),
         KEY `date_creation` (`date_creation`)
      ) $charset");
   }

   if (!$DB->tableExists('glpi_plugin_whatsappempresa_sessoes')) {
      $DB->doQuery("CREATE TABLE `glpi_plugin_whatsappempresa_sessoes` (
         `id` int unsigned NOT NULL AUTO_INCREMENT,
         `telefone` varchar(30) NOT NULL,
         `chave` varchar(20) DEFAULT NULL,
         `jid` varchar(80) DEFAULT NULL,
         `fluxo` varchar(30) NOT NULL DEFAULT 'menu',
         `etapa` varchar(60) NOT NULL DEFAULT 'inicio',
         `fluxos_id` int unsigned NOT NULL DEFAULT 0,
         `passo` varchar(40) DEFAULT NULL,
         `autenticado` tinyint(1) NOT NULL DEFAULT 0,
         `autenticado_ate` timestamp NULL DEFAULT NULL,
         `tentativas` int unsigned NOT NULL DEFAULT 0,
         `bloqueado_ate` timestamp NULL DEFAULT NULL,
         `users_id` int unsigned NOT NULL DEFAULT 0,
         `conversas_id` int unsigned NOT NULL DEFAULT 0,
         `tickets_id` int unsigned NOT NULL DEFAULT 0,
         `contexto` longtext,
         `date_mod` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
         `date_creation` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
         PRIMARY KEY (`id`),
         UNIQUE KEY `telefone` (`telefone`),
         KEY `chave` (`chave`),
         KEY `jid` (`jid`),
         KEY `fluxo` (`fluxo`),
         KEY `fluxos_id` (`fluxos_id`)
      ) $charset");
   }

   if (!$DB->tableExists('glpi_plugin_whatsappempresa_fluxos')) {
      $DB->doQuery("CREATE TABLE `glpi_plugin_whatsappempresa_fluxos` (
         `id` int unsigned NOT NULL AUTO_INCREMENT,
         `nome` varchar(120) NOT NULL,
         `descricao` varchar(255) DEFAULT NULL,
         `gatilho` varchar(30) NOT NULL DEFAULT 'menu',
         `palavras` varchar(255) DEFAULT NULL,
         `definicao` longtext,
         `entities_id` int unsigned NOT NULL DEFAULT 0,
         `users_id` int unsigned NOT NULL DEFAULT 0,
         `ordem` int unsigned NOT NULL DEFAULT 0,
         `is_ativo` tinyint(1) NOT NULL DEFAULT 1,
         `is_padrao` tinyint(1) NOT NULL DEFAULT 0,
         `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
         `date_creation` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
         `date_mod` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
         PRIMARY KEY (`id`),
         KEY `gatilho` (`gatilho`),
         KEY `is_ativo` (`is_ativo`),
         KEY `ordem` (`ordem`)
      ) $charset");
   }

   // Clientes do autoatendimento: uma linha por entidade, com o requerente padrao
   if (!$DB->tableExists('glpi_plugin_whatsappempresa_clientes')) {
      $DB->doQuery("CREATE TABLE `glpi_plugin_whatsappempresa_clientes` (
         `id` int unsigned NOT NULL AUTO_INCREMENT,
         `entities_id` int unsigned NOT NULL DEFAULT 0,
         `users_id_requerente` int unsigned NOT NULL DEFAULT 0,
         `is_ativo` tinyint(1) NOT NULL DEFAULT 1,
         `observacao` varchar(255) DEFAULT NULL,
         `date_creation` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
         `date_mod` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
         PRIMARY KEY (`id`),
         UNIQUE KEY `entities_id` (`entities_id`)
      ) $charset");
   }

   // Contatos de cada cliente: atendidos pelo telefone mesmo sem usuario no GLPI
   if (!$DB->tableExists('glpi_plugin_whatsappempresa_contatos')) {
      $DB->doQuery("CREATE TABLE `glpi_plugin_whatsappempresa_contatos` (
         `id` int unsigned NOT NULL AUTO_INCREMENT,
         `entities_id` int unsigned NOT NULL DEFAULT 0,
         `nome` varchar(100) NOT NULL,
         `telefone` varchar(20) NOT NULL,
         `chave` varchar(8) NOT NULL,
         `is_ativo` tinyint(1) NOT NULL DEFAULT 1,
         `date_creation` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
         `date_mod` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
         PRIMARY KEY (`id`),
         KEY `entities_id` (`entities_id`),
         KEY `chave` (`chave`)
      ) $charset");
   }

   plugin_whatsappempresa_migrar();

   if (!$DB->tableExists('glpi_plugin_whatsappempresa_validacoes')) {
      $DB->doQuery("CREATE TABLE `glpi_plugin_whatsappempresa_validacoes` (
         `id` int unsigned NOT NULL AUTO_INCREMENT,
         `itemtype` varchar(60) NOT NULL DEFAULT 'TicketValidation',
         `items_id` int unsigned NOT NULL DEFAULT 0,
         `objetos_id` int unsigned NOT NULL DEFAULT 0,
         `users_id` int unsigned NOT NULL DEFAULT 0,
         `telefone` varchar(30) NOT NULL,
         `titulo` varchar(255) DEFAULT NULL,
         `status` varchar(20) NOT NULL DEFAULT 'aguardando',
         `comentario` longtext,
         `date_envio` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
         `date_resposta` timestamp NULL DEFAULT NULL,
         PRIMARY KEY (`id`),
         KEY `telefone` (`telefone`),
         KEY `status` (`status`),
         KEY `items_id` (`items_id`)
      ) $charset");
   }

   if (!$DB->tableExists('glpi_plugin_whatsappempresa_logs')) {
      $DB->doQuery("CREATE TABLE `glpi_plugin_whatsappempresa_logs` (
         `id` int unsigned NOT NULL AUTO_INCREMENT,
         `nivel` varchar(20) NOT NULL DEFAULT 'info',
         `origem` varchar(40) NOT NULL DEFAULT 'plugin',
         `evento` varchar(255) NOT NULL,
         `detalhe` longtext,
         `users_id` int unsigned NOT NULL DEFAULT 0,
         `date_creation` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
         PRIMARY KEY (`id`),
         KEY `nivel` (`nivel`),
         KEY `date_creation` (`date_creation`)
      ) $charset");
   }

   if (!$DB->tableExists('glpi_plugin_whatsappempresa_alertas')) {
      $DB->doQuery("CREATE TABLE `glpi_plugin_whatsappempresa_alertas` (
         `id` int unsigned NOT NULL AUTO_INCREMENT,
         `users_id` int unsigned NOT NULL DEFAULT 0,
         `conversas_id` int unsigned NOT NULL DEFAULT 0,
         `tickets_id` int unsigned NOT NULL DEFAULT 0,
         `telefone` varchar(30) DEFAULT NULL,
         `titulo` varchar(255) DEFAULT NULL,
         `mensagem` longtext,
         `lido` tinyint(1) NOT NULL DEFAULT 0,
         `date_creation` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
         PRIMARY KEY (`id`),
         KEY `users_id` (`users_id`),
         KEY `lido` (`lido`)
      ) $charset");
   }

   PluginWhatsappempresaConfig::instalarPadroes();
   PluginWhatsappempresaConstrutor::instalarPadroes();

   // Chaves de versoes anteriores que nao sao mais usadas (inclusive tokens de API guardados a toa)
   $DB->delete('glpi_plugin_whatsappempresa_configs', ['chave' => PluginWhatsappempresaConfig::OBSOLETAS]);

   // Pastas de trabalho e migracao da sessao pareada que ficava dentro do plugin
   PluginWhatsappempresaServidor::prepararPastas();

   // Tarefa automatica no modo interno: roda mesmo sem o cron do sistema configurado
   CronTask::register(
      'PluginWhatsappempresaMonitor',
      'monitorar',
      300,
      ['state' => CronTask::STATE_WAITING, 'mode' => CronTask::MODE_INTERNAL]
   );
   $DB->update(
      'glpi_crontasks',
      ['mode' => CronTask::MODE_INTERNAL],
      ['itemtype' => 'PluginWhatsappempresaMonitor', 'name' => 'monitorar', 'mode' => CronTask::MODE_EXTERNAL]
   );

   plugin_whatsappempresa_colunas_padrao();

   return true;
}

/**
 * Colunas exibidas por padrao nas listas nativas (quando o usuario ainda nao escolheu as suas)
 */
function plugin_whatsappempresa_colunas_padrao(): void {
   global $DB;

   $colunas = [
      'PluginWhatsappempresaCodigo'    => [3, 80, 4, 19],
      'PluginWhatsappempresaConversa'  => [3, 4, 5, 6, 7, 11],
      'PluginWhatsappempresaMensagem'  => [3, 4, 5, 6, 7, 8, 10],
      'PluginWhatsappempresaValidacao' => [3, 7, 6, 5, 4, 8],
      'PluginWhatsappempresaSessao'    => [3, 4, 8, 5, 6, 7, 19],
      'PluginWhatsappempresaLog'       => [3, 4, 5, 6, 7]
   ];

   foreach ($colunas as $itemtype => $numeros) {
      if (countElementsInTable('glpi_displaypreferences', ['itemtype' => $itemtype, 'users_id' => 0]) > 0) {
         continue;
      }
      foreach ($numeros as $posicao => $numero) {
         $DB->insert('glpi_displaypreferences', [
            'itemtype'  => $itemtype,
            'num'       => $numero,
            'rank'      => $posicao + 1,
            'users_id'  => 0,
            'interface' => 'central'
         ]);
      }
   }
}

function plugin_whatsappempresa_uninstall(): bool {
   global $DB;

   plugin_whatsappempresa_carregar_classes();

   // Para o servidor e tira o vigia do crontab; tabelas, Node e sessao do aparelho sao mantidos
   PluginWhatsappempresaServidor::desligarTudo();

   CronTask::unregister('whatsappempresa');

   return true;
}

/**
 * Disparado quando uma validacao de ticket/mudanca e criada
 */
function plugin_whatsappempresa_item_add($item) {
   if (!($item instanceof TicketValidation) && !($item instanceof ChangeValidation)) {
      return $item;
   }
   PluginWhatsappempresaValidacao::enviarPedido($item);
   return $item;
}

/**
 * Avisa o cliente quando o chamado muda de status
 */
function plugin_whatsappempresa_item_update($item) {
   if (!($item instanceof Ticket)) {
      return $item;
   }
   if (in_array('status', $item->updates ?? [], true)) {
      PluginWhatsappempresaConversa::avisarMudancaStatus((int)$item->fields['id'], (int)$item->fields['status']);
   }
   return $item;
}
