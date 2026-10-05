<?php

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access this file directly");
}

/**
 * Conversas do WhatsApp: lista nativa em WhatsApp > Conversas e aba WhatsApp nos chamados.
 * A aba respeita a permissao do proprio chamado (nao depende de canView desta classe).
 */
class PluginWhatsappempresaConversa extends CommonDBTM {

   use PluginWhatsappempresaListaTrait;

   const ROTULOS = [
      'status' => ['aberta' => 'Aberta', 'encerrada' => 'Encerrada'],
      'origem' => ['cliente' => 'Cliente', 'tecnico' => 'Tecnico', 'sistema' => 'Sistema']
   ];

   /** Conversas fazem parte do historico dos chamados: nao sao excluidas pela lista */
   static function canPurge(): bool {
      return false;
   }

   static function getTypeName($nb = 0): string {
      return $nb > 1 ? 'Conversas do WhatsApp' : 'WhatsApp';
   }

   static function getIcon() {
      return 'ti ti-brand-whatsapp';
   }

   function rawSearchOptions() {
      $t = self::getTable();
      return [
         ['id' => 'common', 'name' => 'Conversa do WhatsApp'],
         ['id' => 1, 'table' => $t, 'field' => 'nome_contato', 'name' => 'Contato', 'datatype' => 'string', 'massiveaction' => false],
         ['id' => 2, 'table' => $t, 'field' => 'id', 'name' => 'ID', 'datatype' => 'number', 'massiveaction' => false],
         ['id' => 3, 'table' => $t, 'field' => 'telefone', 'name' => 'Numero', 'datatype' => 'string', 'massiveaction' => false],
         ['id' => 4, 'table' => $t, 'field' => 'status', 'name' => 'Situacao', 'datatype' => 'specific', 'searchtype' => ['equals', 'notequals'], 'massiveaction' => false],
         ['id' => 5, 'table' => $t, 'field' => 'origem', 'name' => 'Iniciada por', 'datatype' => 'specific', 'searchtype' => ['equals', 'notequals'], 'massiveaction' => false],
         self::opcaoChamado(6),
         self::opcaoUsuario(7, 'tecnicos_id', 'Tecnico'),
         self::opcaoUsuario(8, 'users_id', 'Usuario do cliente'),
         ['id' => 9, 'table' => $t, 'field' => 'nao_lidas', 'name' => 'Nao lidas', 'datatype' => 'number', 'massiveaction' => false],
         ['id' => 10, 'table' => $t, 'field' => 'ultima_mensagem', 'name' => 'Ultima mensagem', 'datatype' => 'text', 'massiveaction' => false],
         ['id' => 11, 'table' => $t, 'field' => 'date_ultima', 'name' => 'Ultima atividade', 'datatype' => 'datetime', 'massiveaction' => false],
         ['id' => 12, 'table' => $t, 'field' => 'date_creation', 'name' => 'Inicio', 'datatype' => 'datetime', 'massiveaction' => false],
         ['id' => 13, 'table' => $t, 'field' => 'date_encerramento', 'name' => 'Encerramento', 'datatype' => 'datetime', 'massiveaction' => false],
         ['id' => 14, 'table' => $t, 'field' => 'motivo_encerramento', 'name' => 'Motivo do encerramento', 'datatype' => 'string', 'massiveaction' => false]
      ];
   }

   function getTabNameForItem(CommonGLPI $item, $withtemplate = 0) {
      if ($item->getType() === 'Ticket' && PluginWhatsappempresaConfig::ativo('fluxo_conversa')) {
         return self::createTabEntry('WhatsApp', self::naoLidasDoTicket((int)$item->getID()));
      }
      return '';
   }

   static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0) {
      if ($item->getType() === 'Ticket') {
         self::mostrarAba((int)$item->getID());
      }
      return true;
   }

   /**
    * Conteudo da aba WhatsApp dentro do chamado
    */
   static function mostrarAba(int $tickets_id): void {
      global $CFG_GLPI;

      $ticket = new Ticket();
      if (!$ticket->getFromDB($tickets_id) || !$ticket->canViewItem()) {
         echo '<div class="alert alert-danger">Sem permissao para visualizar este chamado.</div>';
         return;
      }

      $requerente = self::requerenteDoTicket($tickets_id);
      $telefone   = $requerente['telefone'] ?? '';
      $raiz       = $CFG_GLPI['root_doc'];

      echo '<div class="wae-aba" data-raiz="' . htmlescape($raiz) . '" data-tickets-id="' . $tickets_id
         . '" data-telefone="' . htmlescape($telefone) . '">';

      echo '<div class="card">';
      echo '<div class="card-header">';
      echo '<h3 class="card-title"><i class="ti ti-brand-whatsapp me-2"></i>Conversas do chamado</h3>';
      echo '<div class="card-actions">';
      echo '<button type="button" class="btn btn-sm btn-outline-secondary" id="wae-aba-atualizar"><i class="ti ti-refresh me-1"></i>Atualizar</button>';
      echo '</div>';
      echo '</div>';

      echo '<div class="card-body">';

      echo '<div class="row g-2 align-items-end mb-3">';
      echo '<div class="col-md-4">';
      echo '<label class="form-label" for="wae-aba-destino">Destino</label>';
      echo '<select class="form-select" id="wae-aba-destino">';
      echo '<option value="requerente">Requerente do chamado</option>';
      echo '<option value="usuario">Usuario do GLPI</option>';
      echo '<option value="contato">Contato do GLPI</option>';
      echo '<option value="manual">Numero manual</option>';
      echo '</select>';
      echo '</div>';
      echo '<div class="col-md-8" id="wae-aba-destino-extra"></div>';
      echo '</div>';

      echo '<div class="wae-ativas mb-2" id="wae-aba-ativas"></div>';
      echo '<div class="alert alert-warning wae-oculto" id="wae-aba-aviso"></div>';

      echo '<label class="form-check form-switch mb-3">';
      echo '<input class="form-check-input" type="checkbox" id="wae-aba-encerradas">';
      echo '<span class="form-check-label">Mostrar conversas encerradas</span>';
      echo '</label>';

      echo '<div class="wae-chat" id="wae-aba-chat"><div class="text-secondary">Carregando conversas...</div></div>';

      echo '<div class="input-group mt-3">';
      echo '<textarea class="form-control" id="wae-aba-texto" rows="2" placeholder="Escreva a mensagem"></textarea>';
      echo '<button type="button" class="btn btn-primary" id="wae-aba-enviar"><i class="ti ti-send me-1"></i>Enviar</button>';
      echo '</div>';

      echo '</div></div></div>';

      echo Html::script('/plugins/whatsappempresa/public/js/conversa.js', [
         'version' => PLUGIN_WHATSAPPEMPRESA_VERSION
      ]);
   }

   /**
    * Requerente principal do chamado com telefone
    */
   static function requerenteDoTicket(int $tickets_id): array {
      global $DB;

      $iterator = $DB->request([
         'SELECT' => ['users_id'],
         'FROM'   => 'glpi_tickets_users',
         'WHERE'  => ['tickets_id' => $tickets_id, 'type' => CommonITILActor::REQUESTER],
         'LIMIT'  => 5
      ]);

      foreach ($iterator as $linha) {
         $users_id = (int)$linha['users_id'];
         $telefone = PluginWhatsappempresaConfig::telefoneDoUsuario($users_id);
         if ($telefone !== '') {
            return ['users_id' => $users_id, 'telefone' => $telefone, 'nome' => PluginWhatsappempresaConfig::nomeUsuario($users_id)];
         }
      }

      return [];
   }

   /**
    * Tecnico atribuido ao chamado
    */
   static function tecnicoDoTicket(int $tickets_id): int {
      global $DB;

      $iterator = $DB->request([
         'SELECT' => ['users_id'],
         'FROM'   => 'glpi_tickets_users',
         'WHERE'  => ['tickets_id' => $tickets_id, 'type' => CommonITILActor::ASSIGN],
         'ORDER'  => 'id ASC',
         'LIMIT'  => 1
      ]);

      foreach ($iterator as $linha) {
         return (int)$linha['users_id'];
      }

      return 0;
   }

   // ============================================
   // Leitura das conversas
   // ============================================

   static function porId(int $conversas_id): ?array {
      global $DB;

      if ($conversas_id <= 0) {
         return null;
      }

      foreach ($DB->request([
         'FROM'  => 'glpi_plugin_whatsappempresa_conversas',
         'WHERE' => ['id' => $conversas_id],
         'LIMIT' => 1
      ]) as $linha) {
         return $linha;
      }

      return null;
   }

   static function estaAberta(int $conversas_id): bool {
      $conversa = self::porId($conversas_id);
      return $conversa !== null && (string)$conversa['status'] === 'aberta' && (int)$conversa['is_deleted'] === 0;
   }

   static function ticketDaConversa(int $conversas_id): int {
      $conversa = self::porId($conversas_id);
      return $conversa !== null ? (int)$conversa['tickets_id'] : 0;
   }

   /**
    * Conversa aberta do numero, comparando pela chave para aceitar
    * qualquer formato de telefone que o WhatsApp entregar
    */
   static function abertaDoNumero(string $telefone): ?array {
      global $DB;

      $numero = PluginWhatsappempresaConfig::limparTelefone($telefone);
      $chave  = PluginWhatsappempresaConfig::chaveTelefone($numero);

      if (strlen($chave) < 8) {
         return null;
      }

      foreach ($DB->request([
         'FROM'  => 'glpi_plugin_whatsappempresa_conversas',
         'WHERE' => ['chave' => $chave, 'status' => 'aberta', 'is_deleted' => 0],
         'ORDER' => 'id DESC',
         'LIMIT' => 1
      ]) as $linha) {
         return $linha;
      }

      // Conversas gravadas antes da coluna de chave existir
      foreach ($DB->request([
         'FROM'  => 'glpi_plugin_whatsappempresa_conversas',
         'WHERE' => ['telefone' => ['LIKE', '%' . $chave], 'status' => 'aberta', 'is_deleted' => 0],
         'ORDER' => 'id DESC',
         'LIMIT' => 1
      ]) as $linha) {
         return $linha;
      }

      return null;
   }

   /**
    * Recupera ou cria a conversa do numero
    */
   static function obterOuCriar(string $telefone, array $dados = []): int {
      global $DB;

      $numero     = PluginWhatsappempresaConfig::limparTelefone($telefone);
      $chave      = PluginWhatsappempresaConfig::chaveTelefone($numero);
      $tickets_id = (int)($dados['tickets_id'] ?? 0);

      $where = ['chave' => $chave, 'is_deleted' => 0, 'status' => 'aberta'];
      if ($tickets_id > 0) {
         $where['tickets_id'] = $tickets_id;
      }

      foreach ($DB->request([
         'SELECT' => ['id'],
         'FROM'   => 'glpi_plugin_whatsappempresa_conversas',
         'WHERE'  => $where,
         'ORDER'  => 'id DESC',
         'LIMIT'  => 1
      ]) as $linha) {
         return (int)$linha['id'];
      }

      $DB->insert('glpi_plugin_whatsappempresa_conversas', [
         'telefone'     => $numero,
         'chave'        => $chave,
         'jid'          => $dados['jid'] ?? null,
         'nome_contato' => $dados['nome_contato'] ?? '',
         'users_id'     => (int)($dados['users_id'] ?? 0),
         'tickets_id'   => $tickets_id,
         'tecnicos_id'  => (int)($dados['tecnicos_id'] ?? 0),
         'entities_id'  => (int)($dados['entities_id'] ?? 0),
         'origem'       => $dados['origem'] ?? 'cliente',
         'status'       => 'aberta',
         'nao_lidas'    => 0,
         'is_deleted'   => 0,
         'date_ultima'  => date('Y-m-d H:i:s')
      ]);

      return (int)$DB->insertId();
   }

   static function atualizarResumo(int $conversas_id, string $texto, bool $incrementaNaoLida = false): void {
      global $DB;

      if ($conversas_id <= 0) {
         return;
      }

      $dados = [
         'ultima_mensagem' => mb_substr($texto, 0, 500),
         'date_ultima'     => date('Y-m-d H:i:s')
      ];

      if ($incrementaNaoLida) {
         $atual = 0;
         foreach ($DB->request([
            'SELECT' => ['nao_lidas'],
            'FROM'   => 'glpi_plugin_whatsappempresa_conversas',
            'WHERE'  => ['id' => $conversas_id],
            'LIMIT'  => 1
         ]) as $linha) {
            $atual = (int)$linha['nao_lidas'];
         }
         $dados['nao_lidas'] = $atual + 1;
      }

      $DB->update('glpi_plugin_whatsappempresa_conversas', $dados, ['id' => $conversas_id]);
   }

   static function marcarLida(int $conversas_id): void {
      global $DB;
      $DB->update('glpi_plugin_whatsappempresa_conversas', ['nao_lidas' => 0], ['id' => $conversas_id]);
   }

   static function naoLidasDoTicket(int $tickets_id): int {
      global $DB;

      $total = 0;
      foreach ($DB->request([
         'SELECT' => ['SUM' => 'nao_lidas AS total'],
         'FROM'   => 'glpi_plugin_whatsappempresa_conversas',
         'WHERE'  => ['tickets_id' => $tickets_id, 'is_deleted' => 0]
      ]) as $linha) {
         $total = (int)$linha['total'];
      }

      return $total;
   }

   /**
    * Mensagem recebida dentro do fluxo de conversa com o tecnico
    */
   static function receberDoCliente(string $telefone, string $texto, int $tickets_id, int $users_id, int $conversas_id = 0): void {
      $conversa = self::porId($conversas_id);

      // Sessao sem vinculo: aproveita a conversa aberta do numero antes de criar outra
      if ($conversa === null) {
         $conversa = self::abertaDoNumero($telefone);

         if ($conversa === null) {
            $conversas_id = self::obterOuCriar($telefone, [
               'tickets_id'   => $tickets_id,
               'users_id'     => $users_id,
               'tecnicos_id'  => $tickets_id > 0 ? self::tecnicoDoTicket($tickets_id) : 0,
               'nome_contato' => $users_id > 0 ? PluginWhatsappempresaConfig::nomeUsuario($users_id) : '',
               'origem'       => 'cliente'
            ]);
            $conversa = self::porId($conversas_id);
         } else {
            $conversas_id = (int)$conversa['id'];
         }
      }

      if ($conversa === null) {
         return;
      }

      // O chamado sempre vem da conversa: e nele que o historico fica vinculado
      $tickets_id  = (int)$conversa['tickets_id'];
      $tecnicos_id = (int)$conversa['tecnicos_id'];

      if ($tecnicos_id <= 0 && $tickets_id > 0) {
         $tecnicos_id = self::tecnicoDoTicket($tickets_id);
      }

      PluginWhatsappempresaMensagem::registrar([
         'conversas_id' => $conversas_id,
         'telefone'     => $telefone,
         'direcao'      => 'entrada',
         'fluxo'        => 'conversa',
         'conteudo'     => $texto,
         'tickets_id'   => $tickets_id,
         'users_id'     => $users_id,
         'origem_tipo'  => 'humano'
      ]);

      self::atualizarResumo($conversas_id, $texto, true);

      if ($tecnicos_id > 0 && PluginWhatsappempresaConfig::ativo('notificar_tecnico')) {
         self::criarAlerta($tecnicos_id, $conversas_id, $tickets_id, $telefone, $texto);
      }
   }

   static function criarAlerta(int $users_id, int $conversas_id, int $tickets_id, string $telefone, string $mensagem): void {
      global $DB;

      if ($users_id <= 0) {
         return;
      }

      $titulo = $tickets_id > 0
         ? 'Mensagem no chamado #' . $tickets_id
         : 'Mensagem no WhatsApp';

      $DB->insert('glpi_plugin_whatsappempresa_alertas', [
         'users_id'     => $users_id,
         'conversas_id' => $conversas_id,
         'tickets_id'   => $tickets_id,
         'telefone'     => PluginWhatsappempresaConfig::limparTelefone($telefone),
         'titulo'       => $titulo,
         'mensagem'     => mb_substr($mensagem, 0, 500),
         'lido'         => 0
      ]);
   }

   /**
    * Envio feito pelo tecnico (aba do chamado ou modal global)
    */
   static function enviarDoTecnico(string $telefone, string $texto, int $tickets_id = 0, string $nomeContato = ''): array {
      global $DB;

      $numero      = PluginWhatsappempresaConfig::limparTelefone($telefone);
      $users_id    = PluginWhatsappempresaConfig::usuarioPorTelefone($numero);
      $tecnicos_id = (int)Session::getLoginUserID();

      // Numero ja falando em outro chamado: fecha a anterior antes de assumir
      $aberta = self::abertaDoNumero($numero);
      if ($aberta !== null
          && $tickets_id > 0
          && (int)$aberta['tickets_id'] !== $tickets_id
          && PluginWhatsappempresaConfig::ativo('conversa_unica_por_numero')) {

         self::encerrar((int)$aberta['id'], [
            'motivo'    => 'Numero assumido pelo chamado #' . $tickets_id,
            'origem'    => 'sistema',
            'despedida' => false,
            'liberar'   => false
         ]);
      }

      $conversas_id = self::obterOuCriar($numero, [
         'tickets_id'   => $tickets_id,
         'users_id'     => $users_id,
         'tecnicos_id'  => $tecnicos_id,
         'nome_contato' => $nomeContato,
         'origem'       => 'tecnico'
      ]);

      self::reabrir($conversas_id, $tecnicos_id);

      // Apresentacao enviada apenas na primeira mensagem da conversa
      if (PluginWhatsappempresaConfig::ativo('enviar_apresentacao') && self::semMensagens($conversas_id)) {
         $apresentacao = trim((string)PluginWhatsappempresaConfig::get('msg_conversa_tecnico'));
         if ($apresentacao !== '') {
            $apresentacao = str_replace(
               ['{tecnico}', '{chamado}'],
               [PluginWhatsappempresaConfig::nomeUsuario($tecnicos_id), $tickets_id > 0 ? '#' . $tickets_id : ''],
               $apresentacao
            );

            PluginWhatsappempresaMensagem::enviar($numero, $apresentacao, [
               'conversas_id' => $conversas_id,
               'fluxo'        => 'conversa',
               'tickets_id'   => $tickets_id,
               'users_id'     => $tecnicos_id,
               'origem_tipo'  => 'automacao'
            ]);
         }
      }

      $resultado = PluginWhatsappempresaMensagem::enviar($numero, $texto, [
         'conversas_id' => $conversas_id,
         'fluxo'        => 'conversa',
         'tickets_id'   => $tickets_id,
         'users_id'     => $tecnicos_id,
         'origem_tipo'  => 'humano'
      ]);

      self::atualizarResumo($conversas_id, $texto);

      $jid = (string)($resultado['jid'] ?? '');
      if ($jid !== '') {
         $DB->update('glpi_plugin_whatsappempresa_conversas', ['jid' => $jid], ['id' => $conversas_id]);
      }

      // Direciona as respostas do numero para esta conversa em vez do menu
      if ($resultado['ok'] && PluginWhatsappempresaConfig::ativo('conversa_tecnico_prende')) {
         PluginWhatsappempresaFluxo::prenderEmConversa($numero, $conversas_id, $tickets_id, $users_id, $jid);
      }

      return $resultado + ['conversas_id' => $conversas_id];
   }

   static function semMensagens(int $conversas_id): bool {
      global $DB;

      foreach ($DB->request([
         'COUNT' => 'total',
         'FROM'  => 'glpi_plugin_whatsappempresa_mensagens',
         'WHERE' => ['conversas_id' => $conversas_id]
      ]) as $linha) {
         return (int)$linha['total'] === 0;
      }

      return true;
   }

   static function reabrir(int $conversas_id, int $tecnicos_id): void {
      global $DB;

      $dados = ['status' => 'aberta', 'date_encerramento' => null, 'motivo_encerramento' => null];
      if ($tecnicos_id > 0) {
         $dados['tecnicos_id'] = $tecnicos_id;
      }

      $DB->update('glpi_plugin_whatsappempresa_conversas', $dados, ['id' => $conversas_id]);
   }

   // ============================================
   // Encerramento
   // ============================================

   /**
    * Encerra a conversa, grava o acompanhamento no chamado
    * e devolve o numero ao atendimento automatico
    */
   static function encerrar(int $conversas_id, array $opcoes = []): array {
      global $DB;

      $conversa = self::porId($conversas_id);
      if ($conversa === null) {
         return ['ok' => false, 'erro' => 'Conversa nao encontrada', 'followup' => false];
      }

      $motivo    = (string)($opcoes['motivo'] ?? 'Encerrada pelo atendente');
      $origem    = (string)($opcoes['origem'] ?? 'tecnico');
      $despedida = !isset($opcoes['despedida']) || $opcoes['despedida'] !== false;
      $liberar   = !isset($opcoes['liberar']) || $opcoes['liberar'] !== false;
      $autor     = (int)($opcoes['users_id'] ?? Session::getLoginUserID());

      $jaEncerrada = ((string)$conversa['status'] === 'encerrada');
      $telefone    = (string)$conversa['telefone'];
      $tickets_id  = (int)$conversa['tickets_id'];

      if (!$jaEncerrada) {
         $DB->update('glpi_plugin_whatsappempresa_conversas', [
            'status'              => 'encerrada',
            'date_encerramento'   => date('Y-m-d H:i:s'),
            'encerrada_por'       => $autor,
            'motivo_encerramento' => mb_substr($motivo, 0, 250)
         ], ['id' => $conversas_id]);
      }

      // Toda conversa iniciada em chamado deixa o historico registrado nele
      $followup = false;
      if (!$jaEncerrada && $tickets_id > 0 && PluginWhatsappempresaConfig::ativo('followup_encerramento')) {
         $followup = self::gravarTranscricao($conversas_id, $conversa, $motivo, $autor);
      }

      if ($liberar) {
         PluginWhatsappempresaFluxo::liberarConversa($telefone, $conversas_id);
      }

      if ($despedida && !$jaEncerrada) {
         $texto = trim((string)PluginWhatsappempresaConfig::get('msg_fim_conversa'));
         if ($texto !== '') {
            PluginWhatsappempresaMensagem::enviar($telefone, $texto, [
               'conversas_id' => $conversas_id,
               'fluxo'        => 'conversa',
               'tickets_id'   => $tickets_id,
               'users_id'     => $autor,
               'origem_tipo'  => 'automacao'
            ]);
         }
      }

      // Cliente encerrou: o tecnico precisa saber
      if ($origem === 'cliente' && !$jaEncerrada) {
         $tecnicos_id = (int)$conversa['tecnicos_id'];
         if ($tecnicos_id <= 0 && $tickets_id > 0) {
            $tecnicos_id = self::tecnicoDoTicket($tickets_id);
         }

         self::criarAlerta(
            $tecnicos_id,
            $conversas_id,
            $tickets_id,
            $telefone,
            'O cliente encerrou a conversa pelo WhatsApp.'
         );
      }

      if (!$jaEncerrada) {
         PluginWhatsappempresaLog::registrar(
            'Conversa encerrada',
            'Numero ' . $telefone . ($tickets_id > 0 ? ' - chamado #' . $tickets_id : '')
            . ' - origem ' . $origem . ' - ' . $motivo,
            'info',
            'conversa',
            $autor
         );
      }

      return ['ok' => true, 'erro' => null, 'followup' => $followup, 'tickets_id' => $tickets_id];
   }

   /**
    * Monta a transcricao da conversa e grava como acompanhamento do chamado
    */
   static function gravarTranscricao(int $conversas_id, array $conversa, string $motivo, int $autor): bool {
      $mensagens = self::listarMensagens($conversas_id, 200);

      $contato = (string)($conversa['nome_contato'] ?: $conversa['telefone']);
      $linhas  = [];

      $linhas[] = '<p><strong>Conversa de WhatsApp encerrada</strong></p>';
      // htmlescape existe no GLPI 11 e 12 (Html::clean nao existe em nenhum dos dois)
      $linhas[] = '<p>Contato: ' . htmlescape($contato) . ' (' . htmlescape((string)$conversa['telefone']) . ')<br>';
      $linhas[] = 'Motivo: ' . htmlescape($motivo) . '<br>';
      $linhas[] = 'Mensagens trocadas: ' . count($mensagens) . '</p>';

      if (empty($mensagens)) {
         $linhas[] = '<p>Nenhuma mensagem foi trocada nesta conversa.</p>';
      } else {
         $linhas[] = '<p><strong>Transcricao</strong></p><ul>';

         foreach ($mensagens as $mensagem) {
            $quem = ($mensagem['direcao'] === 'saida') ? 'Atendimento' : $contato;
            $linhas[] = '<li>' . htmlescape((string)$mensagem['data']) . ' - <strong>'
                      . htmlescape($quem) . ':</strong> '
                      . htmlescape(strip_tags((string)$mensagem['conteudo'])) . '</li>';
         }

         $linhas[] = '</ul>';
      }

      $tecnicos_id = (int)$conversa['tecnicos_id'];
      if ($tecnicos_id <= 0) {
         $tecnicos_id = $autor > 0 ? $autor : (int)$conversa['users_id'];
      }

      return self::gravarFollowup(
         (int)$conversa['tickets_id'],
         implode('', $linhas),
         $tecnicos_id,
         PluginWhatsappempresaConfig::ativo('followup_privado')
      );
   }

   /**
    * Grava o acompanhamento no chamado usando a API do GLPI.
    * Sem sessao valida o insert direto garante que o registro nao se perca.
    */
   static function gravarFollowup(int $tickets_id, string $conteudo, int $users_id, bool $privado = false): bool {
      global $DB;

      if ($tickets_id <= 0 || trim(strip_tags($conteudo)) === '') {
         return false;
      }

      $ticket = new Ticket();
      if (!$ticket->getFromDB($tickets_id)) {
         return false;
      }

      if ($users_id > 0) {
         PluginWhatsappempresaFluxo::assumirUsuario($users_id);
      }

      $acompanhamento = new ITILFollowup();
      $ok = $acompanhamento->add([
         'itemtype'   => 'Ticket',
         'items_id'   => $tickets_id,
         'content'    => $conteudo,
         'users_id'   => $users_id,
         'is_private' => $privado ? 1 : 0
      ]);

      if ($ok) {
         return true;
      }

      $DB->insert('glpi_itilfollowups', [
         'itemtype'      => 'Ticket',
         'items_id'      => $tickets_id,
         'content'       => $conteudo,
         'users_id'      => $users_id,
         'is_private'    => $privado ? 1 : 0,
         'date'          => date('Y-m-d H:i:s'),
         'date_creation' => date('Y-m-d H:i:s'),
         'date_mod'      => date('Y-m-d H:i:s')
      ]);

      return $DB->insertId() > 0;
   }

   /**
    * Fecha conversas paradas ha muito tempo
    */
   static function encerrarInativas(): int {
      global $DB;

      $minutos = (int)PluginWhatsappempresaConfig::get('conversa_minutos', '120');
      if ($minutos <= 0) {
         return 0;
      }

      $limite = date('Y-m-d H:i:s', time() - ($minutos * 60));
      $total  = 0;

      foreach ($DB->request([
         'SELECT' => ['id'],
         'FROM'   => 'glpi_plugin_whatsappempresa_conversas',
         'WHERE'  => [
            'status'      => 'aberta',
            'is_deleted'  => 0,
            'date_ultima' => ['<', $limite]
         ],
         'LIMIT' => 200
      ]) as $linha) {
         $resultado = self::encerrar((int)$linha['id'], [
            'motivo' => 'Encerrada automaticamente por inatividade',
            'origem' => 'sistema'
         ]);

         if ($resultado['ok']) {
            $total++;
         }
      }

      return $total;
   }

   // ============================================
   // Listagens para a interface
   // ============================================

   static function listarMensagens(int $conversas_id, int $limite = 80): array {
      global $DB;

      $itens = [];
      $iterator = $DB->request([
         'FROM'  => 'glpi_plugin_whatsappempresa_mensagens',
         'WHERE' => ['conversas_id' => $conversas_id],
         'ORDER' => 'id DESC',
         'LIMIT' => $limite
      ]);

      foreach ($iterator as $linha) {
         $itens[] = [
            'id'          => (int)$linha['id'],
            'direcao'     => $linha['direcao'],
            'conteudo'    => (string)$linha['conteudo'],
            'fluxo'       => $linha['fluxo'],
            'origem_tipo' => (string)($linha['origem_tipo'] ?? 'automacao'),
            'remetente'   => (string)($linha['remetente'] ?? ''),
            'status'      => $linha['status_envio'],
            'data'        => Html::convDateTime($linha['date_creation'])
         ];
      }

      return array_reverse($itens);
   }

   static function conversasDoTicket(int $tickets_id, bool $incluirEncerradas = false): array {
      global $DB;

      $where = ['tickets_id' => $tickets_id, 'is_deleted' => 0];
      if (!$incluirEncerradas) {
         $where['status'] = 'aberta';
      }

      $itens = [];
      $iterator = $DB->request([
         'FROM'  => 'glpi_plugin_whatsappempresa_conversas',
         'WHERE' => $where,
         'ORDER' => 'date_ultima DESC'
      ]);

      foreach ($iterator as $linha) {
         $itens[] = $linha;
      }

      return $itens;
   }

   /**
    * Conversas relacionadas ao tecnico logado
    */
   static function conversasDoTecnico(int $users_id, int $limite = 30): array {
      global $DB;

      $chamados = [0];
      foreach ($DB->request([
         'SELECT' => ['tickets_id'],
         'FROM'   => 'glpi_tickets_users',
         'WHERE'  => ['users_id' => $users_id, 'type' => CommonITILActor::ASSIGN],
         'LIMIT'  => 300
      ]) as $linha) {
         $chamados[] = (int)$linha['tickets_id'];
      }

      $itens = [];
      $iterator = $DB->request([
         'FROM'  => 'glpi_plugin_whatsappempresa_conversas',
         'WHERE' => [
            'is_deleted' => 0,
            'status'     => 'aberta',
            'OR' => [
               ['tecnicos_id' => $users_id],
               ['tickets_id'  => $chamados]
            ]
         ],
         'ORDER' => 'date_ultima DESC',
         'LIMIT' => $limite
      ]);

      foreach ($iterator as $linha) {
         $linha['nome_contato'] = $linha['nome_contato'] !== '' ? $linha['nome_contato'] : $linha['telefone'];
         $itens[] = $linha;
      }

      return $itens;
   }

   static function alertasDoUsuario(int $users_id, int $limite = 10): array {
      global $DB;

      $itens = [];
      $iterator = $DB->request([
         'FROM'  => 'glpi_plugin_whatsappempresa_alertas',
         'WHERE' => ['users_id' => $users_id, 'lido' => 0],
         'ORDER' => 'id DESC',
         'LIMIT' => $limite
      ]);

      foreach ($iterator as $linha) {
         $linha['data'] = Html::convDateTime($linha['date_creation']);
         $itens[] = $linha;
      }

      return $itens;
   }

   static function marcarAlertasLidos(int $users_id, int $conversas_id = 0): void {
      global $DB;

      $where = ['users_id' => $users_id];
      if ($conversas_id > 0) {
         $where['conversas_id'] = $conversas_id;
      }

      $DB->update('glpi_plugin_whatsappempresa_alertas', ['lido' => 1], $where);
   }

   /**
    * Avisa o requerente quando o chamado muda de status
    */
   static function avisarMudancaStatus(int $tickets_id, int $status): void {
      if (!PluginWhatsappempresaConfig::ativo('aviso_status') || !PluginWhatsappempresaConfig::ativo('fluxo_conversa')) {
         return;
      }

      $requerente = self::requerenteDoTicket($tickets_id);
      if (empty($requerente['telefone'])) {
         return;
      }

      $ticket = new Ticket();
      if (!$ticket->getFromDB($tickets_id)) {
         return;
      }

      $texto = "*Chamado #{$tickets_id}*\n"
             . $ticket->fields['name'] . "\n\n"
             . 'Novo status: ' . Ticket::getStatus($status);

      // Se existe conversa aberta o aviso entra nela e aparece na aba
      $aberta = self::abertaDoNumero($requerente['telefone']);

      PluginWhatsappempresaMensagem::enviar($requerente['telefone'], $texto, [
         'conversas_id' => $aberta !== null ? (int)$aberta['id'] : 0,
         'fluxo'        => 'aviso',
         'tickets_id'   => $tickets_id,
         'users_id'     => (int)$requerente['users_id'],
         'origem_tipo'  => 'automacao'
      ]);
   }
}
