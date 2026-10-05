<?php

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access this file directly");
}

class PluginWhatsappempresaValidacao extends CommonDBTM {

   use PluginWhatsappempresaListaTrait;

   const ROTULOS = [
      'status'   => ['aguardando' => 'Aguardando', 'aprovado' => 'Aprovado', 'recusado' => 'Recusado', 'encerrada' => 'Encerrada no GLPI'],
      'itemtype' => ['TicketValidation' => 'Chamado', 'ChangeValidation' => 'Mudanca']
   ];

   /** A tabela usa o plural em portugues */
   static function getTable($classname = null): string {
      return 'glpi_plugin_whatsappempresa_validacoes';
   }

   static function getTypeName($nb = 0): string {
      return $nb > 1 ? 'Validacoes via WhatsApp' : 'Validacao via WhatsApp';
   }

   static function getIcon() {
      return 'ti ti-checkup-list';
   }

   function rawSearchOptions() {
      $t = self::getTable();
      return [
         ['id' => 'common', 'name' => self::getTypeName(1)],
         ['id' => 1, 'table' => $t, 'field' => 'titulo', 'name' => 'Pedido', 'datatype' => 'string', 'massiveaction' => false],
         ['id' => 2, 'table' => $t, 'field' => 'id', 'name' => 'ID', 'datatype' => 'number', 'massiveaction' => false],
         ['id' => 3, 'table' => $t, 'field' => 'date_envio', 'name' => 'Enviado em', 'datatype' => 'datetime', 'massiveaction' => false],
         ['id' => 4, 'table' => $t, 'field' => 'date_resposta', 'name' => 'Respondido em', 'datatype' => 'datetime', 'massiveaction' => false],
         ['id' => 5, 'table' => $t, 'field' => 'status', 'name' => 'Situacao', 'datatype' => 'specific', 'searchtype' => ['equals', 'notequals'], 'massiveaction' => false],
         ['id' => 6, 'table' => $t, 'field' => 'telefone', 'name' => 'Numero', 'datatype' => 'string', 'massiveaction' => false],
         self::opcaoUsuario(7, 'users_id', 'Aprovador'),
         ['id' => 8, 'table' => $t, 'field' => 'comentario', 'name' => 'Comentario', 'datatype' => 'text', 'massiveaction' => false],
         ['id' => 9, 'table' => $t, 'field' => 'itemtype', 'name' => 'Origem', 'datatype' => 'specific', 'searchtype' => ['equals', 'notequals'], 'massiveaction' => false],
         ['id' => 10, 'table' => $t, 'field' => 'objetos_id', 'name' => 'Numero do chamado ou mudanca', 'datatype' => 'number', 'massiveaction' => false]
      ];
   }

   /**
    * Aprovadores do pedido.
    * O GLPI 11 usa itemtype_target/items_id_target, as versoes antigas usam users_id_validate.
    */
   static function aprovadoresDe(array $campos): array {
      global $DB;

      $usuarios = [];

      $antigo = (int)($campos['users_id_validate'] ?? 0);
      if ($antigo > 0) {
         $usuarios[] = $antigo;
      }

      $alvo     = (string)($campos['itemtype_target'] ?? '');
      $alvos_id = (int)($campos['items_id_target'] ?? 0);

      if ($alvo === 'User' && $alvos_id > 0) {
         $usuarios[] = $alvos_id;
      }

      // Validacao dirigida a um grupo: todo mundo do grupo recebe
      if ($alvo === 'Group' && $alvos_id > 0 && $DB->tableExists('glpi_groups_users')) {
         foreach ($DB->request([
            'SELECT' => ['users_id'],
            'FROM'   => 'glpi_groups_users',
            'WHERE'  => ['groups_id' => $alvos_id],
            'LIMIT'  => 50
         ]) as $linha) {
            $usuarios[] = (int)$linha['users_id'];
         }
      }

      return array_values(array_unique(array_filter($usuarios)));
   }

   /**
    * Aprovador gravado no registro de uma validacao ja existente
    */
   static function aprovadoresDoRegistro(string $itemtype, int $items_id): array {
      if (!class_exists($itemtype)) {
         return [];
      }

      $validacao = new $itemtype();
      if (!$validacao->getFromDB($items_id)) {
         return [];
      }

      return self::aprovadoresDe($validacao->fields);
   }

   /**
    * Envia o pedido de validacao para o celular do aprovador
    */
   static function enviarPedido($item): void {
      global $DB;

      if (!PluginWhatsappempresaConfig::ativo('fluxo_aprovacao')) {
         return;
      }

      $itemtype = $item->getType();
      $campos   = $item->fields;

      $aprovadores = self::aprovadoresDe($campos);

      if (empty($aprovadores)) {
         PluginWhatsappempresaLog::registrar(
            'Validacao sem aprovador identificado',
            'Nenhum usuario encontrado no pedido ' . $itemtype . ' #' . (int)($campos['id'] ?? 0) . '.',
            'aviso',
            'aprovacao'
         );
         return;
      }

      $ehMudanca  = ($itemtype === 'ChangeValidation');
      $objetos_id = (int)($ehMudanca ? ($campos['changes_id'] ?? 0) : ($campos['tickets_id'] ?? 0));
      $objeto     = $ehMudanca ? new Change() : new Ticket();

      if (!$objeto->getFromDB($objetos_id)) {
         return;
      }

      $titulo    = (string)$objeto->fields['name'];
      $descricao = trim(strip_tags((string)$objeto->fields['content']));
      $descricao = mb_substr(html_entity_decode($descricao, ENT_QUOTES, 'UTF-8'), 0, 900);
      $solicitante = PluginWhatsappempresaConfig::nomeUsuario((int)($objeto->fields['users_id_recipient'] ?? 0));
      $rotulo    = $ehMudanca ? 'Mudanca' : 'Chamado';

      $texto = "*Pedido de aprovacao*\n"
             . "{$rotulo} #{$objetos_id}\n"
             . "*Titulo:* {$titulo}\n"
             . "*Solicitante:* {$solicitante}\n"
             . "*Aberto em:* " . Html::convDateTime($objeto->fields['date']) . "\n\n"
             . "*Descricao:*\n{$descricao}";

      // Botoes reais no WhatsApp em vez de pedir para digitar um numero
      $mensagem = PluginWhatsappempresaMensagem::comOpcoes(
         $texto,
         [
            ['id' => '1', 'rotulo' => 'Aprovar'],
            ['id' => '2', 'rotulo' => 'Recusar']
         ],
         'Toque em uma das opcoes para responder',
         'Aprovacao'
      );

      foreach ($aprovadores as $aprovador) {
         $telefone = PluginWhatsappempresaConfig::telefoneDoUsuario($aprovador);

         if ($telefone === '') {
            PluginWhatsappempresaLog::registrar(
               'Validacao sem telefone',
               'Aprovador ' . PluginWhatsappempresaConfig::nomeUsuario($aprovador) . ' nao possui celular cadastrado.',
               'aviso',
               'aprovacao',
               $aprovador
            );
            continue;
         }

         $DB->insert('glpi_plugin_whatsappempresa_validacoes', [
            'itemtype'   => $itemtype,
            'items_id'   => (int)$campos['id'],
            'objetos_id' => $objetos_id,
            'users_id'   => $aprovador,
            'telefone'   => PluginWhatsappempresaConfig::limparTelefone($telefone),
            'titulo'     => mb_substr($rotulo . ' #' . $objetos_id . ' - ' . $titulo, 0, 250),
            'status'     => 'aguardando'
         ]);

         // A validacao interrompe qualquer fluxo em andamento nesse numero
         PluginWhatsappempresaFluxo::exigirValidacao($telefone, [
            'itemtype'   => $itemtype,
            'items_id'   => (int)$campos['id'],
            'objetos_id' => $objetos_id,
            'users_id'   => $aprovador,
            'titulo'     => $rotulo . ' #' . $objetos_id . ' - ' . $titulo
         ]);

         PluginWhatsappempresaMensagem::enviar($telefone, $mensagem, [
            'fluxo'       => 'aprovacao',
            'tickets_id'  => $ehMudanca ? 0 : $objetos_id,
            'users_id'    => $aprovador,
            'origem_tipo' => 'automacao'
         ]);

         PluginWhatsappempresaLog::registrar(
            'Pedido de validacao enviado',
            $rotulo . ' #' . $objetos_id . ' para ' . PluginWhatsappempresaConfig::nomeUsuario($aprovador)
            . ' no numero ' . $telefone,
            'info',
            'aprovacao',
            $aprovador
         );
      }
   }

   /**
    * Pedidos aguardando resposta de um numero
    */
   static function pendentesPorTelefone(string $telefone): array {
      global $DB;

      $chave = PluginWhatsappempresaConfig::chaveTelefone($telefone);
      if (strlen($chave) < 8) {
         return [];
      }

      $itens = [];
      $iterator = $DB->request([
         'FROM'  => 'glpi_plugin_whatsappempresa_validacoes',
         'WHERE' => [
            'telefone' => ['LIKE', '%' . $chave],
            'status'   => 'aguardando'
         ],
         'ORDER' => 'id DESC',
         'LIMIT' => 20
      ]);

      foreach ($iterator as $linha) {
         if (self::aindaPendente($linha)) {
            $itens[] = $linha;
         } else {
            $DB->update('glpi_plugin_whatsappempresa_validacoes', ['status' => 'encerrada'], ['id' => (int)$linha['id']]);
         }
      }

      return $itens;
   }

   /**
    * Pendencias direto das tabelas nativas do usuario
    */
   static function pendentesDoUsuario(int $users_id): array {
      global $DB;

      $itens  = [];
      $grupos = [];

      foreach ($DB->request([
         'SELECT' => ['groups_id'],
         'FROM'   => 'glpi_groups_users',
         'WHERE'  => ['users_id' => $users_id],
         'LIMIT'  => 50
      ]) as $linha) {
         $grupos[] = (int)$linha['groups_id'];
      }

      foreach (['TicketValidation' => 'glpi_ticketvalidations', 'ChangeValidation' => 'glpi_changevalidations'] as $itemtype => $tabela) {
         if (!$DB->tableExists($tabela)) {
            continue;
         }

         $campoPai = ($itemtype === 'ChangeValidation') ? 'changes_id' : 'tickets_id';

         $alvos = [];
         $resultado = $DB->doQuery("SHOW COLUMNS FROM `$tabela` LIKE 'itemtype_target'");
         $temAlvo = $resultado && $DB->numrows($resultado) > 0;

         if ($temAlvo) {
            $alvos[] = ['itemtype_target' => 'User', 'items_id_target' => $users_id];
            if (!empty($grupos)) {
               $alvos[] = ['itemtype_target' => 'Group', 'items_id_target' => $grupos];
            }
         }

         $resultado = $DB->doQuery("SHOW COLUMNS FROM `$tabela` LIKE 'users_id_validate'");
         if ($resultado && $DB->numrows($resultado) > 0) {
            $alvos[] = ['users_id_validate' => $users_id];
         }

         if (empty($alvos)) {
            continue;
         }

         $iterator = $DB->request([
            'SELECT' => ['id', $campoPai],
            'FROM'   => $tabela,
            'WHERE'  => [
               'status' => CommonITILValidation::WAITING,
               'OR'     => $alvos
            ],
            'ORDER' => 'id DESC',
            'LIMIT' => 20
         ]);

         foreach ($iterator as $linha) {
            $objetos_id = (int)$linha[$campoPai];
            $objeto = ($itemtype === 'ChangeValidation') ? new Change() : new Ticket();
            if (!$objeto->getFromDB($objetos_id)) {
               continue;
            }
            $itens[] = [
               'itemtype'   => $itemtype,
               'items_id'   => (int)$linha['id'],
               'objetos_id' => $objetos_id,
               'titulo'     => (string)$objeto->fields['name'],
               'conteudo'   => mb_substr(trim(strip_tags((string)$objeto->fields['content'])), 0, 700)
            ];
         }
      }

      return $itens;
   }

   static function aindaPendente(array $registro): bool {
      $classe = $registro['itemtype'];
      if (!class_exists($classe)) {
         return false;
      }

      $validacao = new $classe();
      if (!$validacao->getFromDB((int)$registro['items_id'])) {
         return false;
      }

      return (int)$validacao->fields['status'] === CommonITILValidation::WAITING;
   }

   /**
    * Grava a resposta do aprovador no GLPI
    */
   static function responder(string $itemtype, int $items_id, bool $aprovado, string $comentario, int $users_id): array {
      global $DB;

      if (!class_exists($itemtype)) {
         return ['ok' => false, 'erro' => 'Tipo de validacao invalido'];
      }

      $validacao = new $itemtype();
      if (!$validacao->getFromDB($items_id)) {
         return ['ok' => false, 'erro' => 'Validacao nao encontrada'];
      }

      $aprovadores = self::aprovadoresDe($validacao->fields);
      if (!empty($aprovadores) && !in_array($users_id, $aprovadores, true)) {
         return ['ok' => false, 'erro' => 'Voce nao e o aprovador deste pedido'];
      }

      if ((int)$validacao->fields['status'] !== CommonITILValidation::WAITING) {
         return ['ok' => false, 'erro' => 'Este pedido ja foi respondido'];
      }

      $status = $aprovado ? CommonITILValidation::ACCEPTED : CommonITILValidation::REFUSED;
      $texto  = trim($comentario);
      if ($texto === '') {
         $texto = $aprovado ? 'Aprovado via WhatsApp.' : 'Recusado via WhatsApp.';
      }

      PluginWhatsappempresaFluxo::assumirUsuario($users_id);

      $ok = $validacao->update([
         'id'                 => $items_id,
         'status'             => $status,
         'comment_validation' => $texto,
         'validation_date'    => date('Y-m-d H:i:s')
      ]);

      if (!$ok) {
         $DB->update(($itemtype === 'ChangeValidation') ? 'glpi_changevalidations' : 'glpi_ticketvalidations', [
            'status'             => $status,
            'comment_validation' => $texto,
            'validation_date'    => date('Y-m-d H:i:s')
         ], ['id' => $items_id]);
      }

      $DB->update('glpi_plugin_whatsappempresa_validacoes', [
         'status'        => $aprovado ? 'aprovado' : 'recusado',
         'comentario'    => $texto,
         'date_resposta' => date('Y-m-d H:i:s')
      ], ['itemtype' => $itemtype, 'items_id' => $items_id, 'status' => 'aguardando']);

      $ehMudanca  = ($itemtype === 'ChangeValidation');
      $objetos_id = (int)($ehMudanca ? $validacao->fields['changes_id'] : $validacao->fields['tickets_id']);

      PluginWhatsappempresaLog::registrar(
         $aprovado ? 'Validacao aprovada via WhatsApp' : 'Validacao recusada via WhatsApp',
         ($ehMudanca ? 'Mudanca' : 'Chamado') . ' #' . $objetos_id . ' por ' . PluginWhatsappempresaConfig::nomeUsuario($users_id),
         'info',
         'aprovacao',
         $users_id
      );

      return ['ok' => true, 'objetos_id' => $objetos_id, 'mudanca' => $ehMudanca];
   }

   /**
    * Historico de validacoes para a tela de configuracao
    */
   static function historico(array $filtros = [], int $pagina = 1, int $porPagina = 25): array {
      global $DB;

      $where = [];
      if (!empty($filtros['status'])) {
         $where['status'] = $filtros['status'];
      }
      if (!empty($filtros['telefone'])) {
         $where['telefone'] = ['LIKE', '%' . PluginWhatsappempresaConfig::limparTelefone($filtros['telefone']) . '%'];
      }

      $total = 0;
      foreach ($DB->request([
         'COUNT' => 'total',
         'FROM'  => 'glpi_plugin_whatsappempresa_validacoes',
         'WHERE' => $where
      ]) as $linha) {
         $total = (int)$linha['total'];
      }

      $itens = [];
      $iterator = $DB->request([
         'FROM'  => 'glpi_plugin_whatsappempresa_validacoes',
         'WHERE' => $where,
         'ORDER' => 'id DESC',
         'START' => ($pagina - 1) * $porPagina,
         'LIMIT' => $porPagina
      ]);

      foreach ($iterator as $linha) {
         $linha['nome_usuario'] = PluginWhatsappempresaConfig::nomeUsuario((int)$linha['users_id']);
         $itens[] = $linha;
      }

      return ['total' => $total, 'itens' => $itens];
   }
}
