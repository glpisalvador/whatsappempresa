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

      // Barra unica: destino, contato da conversa aberta com o botao de encerrar e atualizar
      echo '<div class="card-header py-2">';
      echo '<div class="d-flex flex-wrap align-items-center gap-2 w-100">';
      echo '<i class="ti ti-brand-whatsapp fs-2 text-success" title="WhatsApp"></i>';
      echo '<select class="form-select form-select-sm w-auto" id="wae-aba-destino" title="Destino">';
      echo '<option value="requerente">Requerente do chamado</option>';
      echo '<option value="usuario">Usuario do GLPI</option>';
      echo '<option value="contato">Contato do GLPI</option>';
      echo '<option value="manual">Numero manual</option>';
      echo '</select>';
      echo '<div class="d-flex align-items-center" id="wae-aba-destino-extra"></div>';
      echo '<div class="d-flex flex-wrap align-items-center gap-2 ms-auto" id="wae-aba-ativas"></div>';
      echo '<button type="button" class="btn btn-sm btn-ghost-secondary" id="wae-aba-atualizar" title="Atualizar"><i class="ti ti-refresh"></i></button>';
      echo '</div>';
      echo '</div>';

      echo '<div class="card-body">';

      echo '<div class="alert alert-warning wae-oculto" id="wae-aba-aviso"></div>';

      // tabindex: clicando na conversa ela recebe o foco e aceita Ctrl+V de imagens
      echo '<div class="wae-chat" id="wae-aba-chat" tabindex="0"><div class="text-secondary">Carregando conversas...</div></div>';

      // Imagens coladas aguardando envio
      echo '<div class="wae-anexos wae-oculto" id="wae-aba-anexos"></div>';

      // Resposta citando uma mensagem (aparece ao clicar em "Responder" num balao)
      echo '<div class="wae-citando wae-oculto" id="wae-aba-citando">';
      echo '<i class="ti ti-corner-up-left"></i>';
      echo '<div class="wae-citando-conteudo"><strong id="wae-aba-citando-autor"></strong><span id="wae-aba-citando-texto"></span></div>';
      echo '<button type="button" class="btn btn-sm btn-ghost-secondary" id="wae-aba-citando-fechar" title="Cancelar resposta"><i class="ti ti-x"></i></button>';
      echo '</div>';

      // Uma linha: Enter envia, Shift+Enter quebra linha; Ctrl+V cola imagens
      echo '<div class="input-group align-items-start mt-2 position-relative">';
      echo '<button type="button" class="btn btn-outline-secondary" id="wae-aba-emoji" title="Emojis"><i class="ti ti-mood-smile"></i></button>';
      echo '<div class="wae-emojis wae-oculto" id="wae-aba-emojis"></div>';
      echo '<textarea class="form-control" id="wae-aba-texto" rows="1" style="resize:none" placeholder="Escreva a mensagem e tecle Enter (Ctrl+V cola imagens)"></textarea>';
      echo '<span class="form-control wae-gravando wae-oculto" id="wae-aba-gravando"><span class="wae-ponto-gravando"></span><span id="wae-aba-relogio">0:00</span> Gravando audio...</span>';
      echo '<button type="button" class="btn btn-outline-secondary wae-oculto" id="wae-aba-cancelar-gravacao" title="Descartar gravacao"><i class="ti ti-trash"></i></button>';
      echo '<button type="button" class="btn btn-outline-secondary" id="wae-aba-gravar" title="Gravar audio"><i class="ti ti-microphone"></i></button>';
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
   static function enviarDoTecnico(string $telefone, string $texto, int $tickets_id = 0, string $nomeContato = '', ?array $midia = null, ?array $citar = null): array {
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
         'origem_tipo'  => 'humano',
         'midia'        => $midia,
         'citar'        => $citar
      ]);

      $resumo = trim($texto);
      if ($midia !== null) {
         $resumo = PluginWhatsappempresaMensagem::rotuloDaMidia((string)$midia['tipo']) . ($resumo !== '' ? ' ' . $resumo : '');
      }
      self::atualizarResumo($conversas_id, $resumo);

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
      $tecnicos_id = (int)$conversa['tecnicos_id'];
      if ($tecnicos_id <= 0) {
         $tecnicos_id = $autor > 0 ? $autor : (int)$conversa['users_id'];
      }

      return self::gravarFollowup(
         (int)$conversa['tickets_id'],
         self::transcricaoHtml($conversas_id, $conversa, $motivo),
         $tecnicos_id,
         PluginWhatsappempresaConfig::ativo('followup_privado')
      );
   }

   /**
    * Transcricao no visual do WhatsApp Web (baloes, horario, separador de dia),
    * com cores suaves e transparentes. So estilo inline: o GLPI 11 e 12 mantem o
    * atributo style no acompanhamento e remove blocos <style>; tambem vale no e-mail.
    */
   static function transcricaoHtml(int $conversas_id, array $conversa, string $motivo): string {
      global $DB;

      $contato  = (string)($conversa['nome_contato'] ?: $conversa['telefone']);
      $telefone = (string)$conversa['telefone'];

      $mensagens = [];
      foreach ($DB->request([
         'FROM'  => 'glpi_plugin_whatsappempresa_mensagens',
         'WHERE' => ['conversas_id' => $conversas_id],
         'ORDER' => 'id ASC',
         'LIMIT' => 500
      ]) as $linha) {
         $mensagens[] = $linha;
      }

      $caixa   = 'background:rgba(239,234,226,0.55);border:1px solid rgba(11,20,26,0.06);border-radius:8px;padding:10px 12px;max-width:760px;';
      $topo    = 'background:rgba(0,128,105,0.10);border-radius:6px;padding:7px 10px;margin-bottom:8px;font-size:12px;color:#0b3d34;';
      $chip    = 'display:inline-block;background:rgba(255,255,255,0.75);color:rgba(84,101,111,0.95);font-size:11px;padding:3px 10px;border-radius:7px;box-shadow:0 1px 0.5px rgba(11,20,26,0.08);';
      $balao   = 'display:inline-block;text-align:left;max-width:78%;padding:6px 9px 4px;border-radius:7.5px;font-size:13px;line-height:1.4;color:#111b21;box-shadow:0 1px 0.5px rgba(11,20,26,0.10);word-wrap:break-word;overflow-wrap:anywhere;';
      $recebido = 'background:rgba(255,255,255,0.78);';
      $enviado  = 'background:rgba(217,253,211,0.78);';
      $hora    = 'display:block;text-align:right;font-size:10.5px;color:rgba(17,27,33,0.45);margin-top:1px;';

      $html  = '<div style="' . $caixa . '">';
      $html .= '<div style="' . $topo . '"><strong>Conversa de WhatsApp</strong> &middot; '
             . htmlescape($contato) . ($contato !== $telefone ? ' (' . htmlescape($telefone) . ')' : '')
             . ' &middot; ' . count($mensagens) . ' mensage' . (count($mensagens) === 1 ? 'm' : 'ns') . '</div>';

      if (empty($mensagens)) {
         $html .= '<div style="text-align:center;margin:8px 0;"><span style="' . $chip . '">Nenhuma mensagem foi trocada nesta conversa</span></div>';
      }

      $diaAnterior   = '';
      $autorAnterior = '';

      foreach ($mensagens as $mensagem) {
         $data = (string)($mensagem['date_creation'] ?? '');
         $dia  = $data !== '' ? substr($data, 0, 10) : '';

         if ($dia !== $diaAnterior) {
            $html .= '<div style="text-align:center;margin:8px 0 6px;"><span style="' . $chip . '">'
                   . htmlescape(self::rotuloDia($dia)) . '</span></div>';
            $diaAnterior   = $dia;
            $autorAnterior = '';
         }

         $saida = ($mensagem['direcao'] === 'saida');
         $autor = $saida ? self::autorDaSaida($mensagem) : $contato;
         $novo  = ($autor !== $autorAnterior);
         $autorAnterior = $autor;

         // A "pontinha" do balao so aparece na primeira mensagem de cada sequencia, como no WhatsApp
         $canto = $novo ? ($saida ? 'border-top-right-radius:0;' : 'border-top-left-radius:0;') : '';

         $html .= '<div style="text-align:' . ($saida ? 'right' : 'left') . ';margin:' . ($novo ? '6px' : '2px') . ' 0 0;">';
         $html .= '<div style="' . $balao . ($saida ? $enviado : $recebido) . $canto . '">';

         if ($novo) {
            $html .= '<span style="display:block;font-size:11.5px;font-weight:600;color:'
                   . ($saida ? 'rgba(0,128,105,0.95)' : 'rgba(2,126,181,0.95)') . ';margin-bottom:1px;">'
                   . htmlescape($autor) . '</span>';
         }

         // Mensagem citada (resposta), no topo do balao como no WhatsApp
         if (trim((string)($mensagem['citada_texto'] ?? '')) !== '') {
            $autorCitada = self::autorDaCitada((int)($mensagem['citada_id'] ?? 0));
            $html .= '<span style="display:block;margin:2px 0 4px;padding:4px 8px;border-left:3px solid '
                   . ($autorCitada === 'Você' ? 'rgba(0,128,105,0.8)' : 'rgba(2,126,181,0.8)')
                   . ';background:rgba(11,20,26,0.05);border-radius:4px;font-size:12px;color:rgba(17,27,33,0.7);">'
                   . ($autorCitada !== '' ? '<strong style="display:block;font-size:11px;">' . htmlescape($autorCitada) . '</strong>' : '')
                   . htmlescape(mb_substr((string)$mensagem['citada_texto'], 0, 200)) . '</span>';
         }

         $temMidia = !empty($mensagem['tipo_midia']) && !empty($mensagem['midia_arquivo']);
         if ($temMidia) {
            $html .= self::midiaNaTranscricao($mensagem, (int)$conversa['tickets_id'], $contato);
         }

         $html .= self::formatarTextoWhatsapp($temMidia ? PluginWhatsappempresaMensagem::legenda($mensagem) : (string)$mensagem['conteudo']);

         $html .= '<span style="' . $hora . '">' . ($data !== '' ? htmlescape(substr($data, 11, 5)) : '');
         if ($saida) {
            $html .= ((string)$mensagem['status_envio'] === 'erro')
               ? ' <span style="color:rgba(220,53,69,0.85);" title="Nao entregue">&#9888;</span>'
               : ' <span style="color:rgba(83,189,235,0.95);letter-spacing:-3px;">&#10003;&#10003;</span>';
         }
         $html .= '</span>';

         // Reacoes (emoji) de cada lado
         $reacoes = array_filter([(string)($mensagem['reacao_cliente'] ?? ''), (string)($mensagem['reacao_atendente'] ?? '')]);
         if ($reacoes) {
            $html .= '<span style="display:inline-block;margin-top:2px;padding:0 6px;border-radius:10px;background:rgba(255,255,255,0.9);'
                   . 'box-shadow:0 1px 1px rgba(11,20,26,0.15);font-size:13px;">' . htmlescape(implode(' ', $reacoes)) . '</span>';
         }

         $html .= '</div></div>';
      }

      $html .= '<div style="text-align:center;margin:10px 0 2px;"><span style="' . $chip . '">Conversa encerrada &middot; '
             . htmlescape($motivo) . '</span></div>';
      $html .= '</div>';

      return $html;
   }

   /**
    * Imagem ou audio dentro do balao da transcricao, apontando para o documento do chamado
    */
   private static function midiaNaTranscricao(array $mensagem, int $tickets_id, string $contato): string {
      global $CFG_GLPI;

      $tipo    = (string)$mensagem['tipo_midia'];
      $caminho = PluginWhatsappempresaServidor::caminhoMidia((string)$mensagem['midia_arquivo']);
      $quando  = str_replace([' ', ':'], ['_', '-'], substr((string)$mensagem['date_creation'], 0, 16));
      $ext     = pathinfo((string)$mensagem['midia_arquivo'], PATHINFO_EXTENSION);
      $nome    = 'WhatsApp ' . ($tipo === 'audio' ? 'audio' : 'imagem') . ' ' . $quando . ' - '
               . preg_replace('/[^\w .-]+/u', '', $contato) . '.' . $ext;

      $documents_id = ($caminho !== null && $tickets_id > 0) ? self::criarDocumento($caminho, $nome, $tickets_id) : 0;

      if ($documents_id <= 0) {
         return '<span style="display:block;font-size:12px;color:rgba(17,27,33,0.55);font-style:italic;">'
              . ($tipo === 'audio' ? '&#127908; Audio' : '&#128247; Imagem') . ' (arquivo indisponivel)</span>';
      }

      $url = $CFG_GLPI['root_doc'] . '/front/document.send.php?docid=' . $documents_id
           . '&itemtype=Ticket&items_id=' . $tickets_id;

      if ($tipo === 'audio') {
         return '<audio controls preload="metadata" src="' . htmlescape($url) . '" style="display:block;width:260px;max-width:100%;height:40px;margin:2px 0;"></audio>'
              . '<a href="' . htmlescape($url) . '" target="_blank" style="font-size:11px;color:rgba(0,128,105,0.9);">&#127908; baixar audio</a>';
      }

      return '<a href="' . htmlescape($url) . '" target="_blank" title="Abrir imagem em tamanho real">'
           . '<img src="' . htmlescape($url) . '" alt="Imagem do WhatsApp" style="display:block;max-width:280px;width:100%;height:auto;border-radius:6px;margin:2px 0 3px;"></a>';
   }

   /**
    * Nome mostrado no balao enviado: tecnico que escreveu ou o atendimento automatico
    */
   private static function autorDaSaida(array $mensagem): string {
      $users_id = (int)($mensagem['users_id'] ?? 0);
      if ($users_id > 0 && (string)($mensagem['origem_tipo'] ?? '') !== 'automacao') {
         return PluginWhatsappempresaConfig::nomeUsuario($users_id);
      }
      return 'Atendimento automatico';
   }

   /**
    * Separador de dia do WhatsApp. O acompanhamento fica gravado para sempre,
    * entao usa a data com o dia da semana (nunca "Hoje"/"Ontem", que envelheceriam errado)
    */
   private static function rotuloDia(string $dia): string {
      $momento = $dia !== '' ? strtotime($dia) : false;
      if ($momento === false) {
         return $dia;
      }
      $semana = ['domingo', 'segunda-feira', 'terca-feira', 'quarta-feira', 'quinta-feira', 'sexta-feira', 'sabado'];
      return $semana[(int)date('w', $momento)] . ', ' . date('d/m/Y', $momento);
   }

   /**
    * Escapa o texto e aplica a formatacao do WhatsApp: *negrito*, _italico_, ~riscado~ e quebras de linha
    */
   private static function formatarTextoWhatsapp(string $texto): string {
      $seguro = htmlescape(trim(strip_tags($texto)));
      $seguro = preg_replace('/(?<![\w*])\*(?!\s)([^*\n]+?)(?<!\s)\*(?![\w*])/u', '<strong>$1</strong>', $seguro);
      $seguro = preg_replace('/(?<![\w_])_(?!\s)([^_\n]+?)(?<!\s)_(?![\w_])/u', '<em>$1</em>', $seguro);
      $seguro = preg_replace('/(?<![\w~])~(?!\s)([^~\n]+?)(?<!\s)~(?![\w~])/u', '<s>$1</s>', $seguro);
      return nl2br($seguro, false);
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

   // ============================================
   // Midia (imagens e audios)
   // ============================================

   const MIMES_IMAGEM = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
   const MIMES_AUDIO  = [
      'audio/ogg' => 'ogg', 'audio/opus' => 'ogg', 'audio/webm' => 'webm', 'video/webm' => 'webm',
      'audio/mp4' => 'm4a', 'video/mp4' => 'm4a', 'audio/x-m4a' => 'm4a', 'audio/mpeg' => 'mp3', 'audio/aac' => 'aac'
   ];

   static function urlMidia(int $mensagens_id): string {
      global $CFG_GLPI;
      return $CFG_GLPI['root_doc'] . '/plugins/whatsappempresa/front/ajax.php?acao=midia&id=' . $mensagens_id;
   }

   /**
    * Quem pode ver a conversa: o tecnico dela ou quem enxerga o chamado
    */
   static function podeVerConversa(int $conversas_id): bool {
      $conversa = self::porId($conversas_id);
      if ($conversa === null) {
         return false;
      }
      if ((int)$conversa['tecnicos_id'] === (int)Session::getLoginUserID()) {
         return true;
      }
      $ticket = new Ticket();
      return (int)$conversa['tickets_id'] > 0
         && $ticket->getFromDB((int)$conversa['tickets_id'])
         && $ticket->canViewItem();
   }

   /**
    * Guarda o arquivo enviado pelo navegador na pasta de midia.
    * Confere o tipo real pelo conteudo (nao pelo nome). Devolve a midia ou a mensagem de erro.
    */
   static function salvarUpload(array $arquivo, string $tipo): array|string {
      if (($arquivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$arquivo['tmp_name'])) {
         return ($arquivo['error'] ?? 0) === UPLOAD_ERR_INI_SIZE
            ? 'Arquivo maior que o limite do servidor (' . ini_get('upload_max_filesize') . ').'
            : 'O arquivo nao chegou ao servidor.';
      }

      $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file((string)$arquivo['tmp_name']);
      $permitidos = $tipo === 'audio' ? self::MIMES_AUDIO : self::MIMES_IMAGEM;
      if (!isset($permitidos[$mime])) {
         return 'Tipo de arquivo nao aceito: ' . $mime . '.';
      }

      if (PluginWhatsappempresaServidor::prepararPastas() !== null) {
         return 'A pasta de midia do plugin nao aceita escrita.';
      }

      $mes   = date('Ym');
      $pasta = PluginWhatsappempresaServidor::pastaMidia() . '/' . $mes;
      if (!is_dir($pasta)) {
         @mkdir($pasta, 0770, true);
      }

      $nome = 'glpi-' . bin2hex(random_bytes(10)) . '.' . $permitidos[$mime];
      if (!@move_uploaded_file((string)$arquivo['tmp_name'], $pasta . '/' . $nome)) {
         return 'Nao foi possivel gravar o arquivo na pasta de midia.';
      }

      // Audio do navegador vem como video/webm no finfo; para o WhatsApp e audio
      if ($tipo === 'audio' && $mime === 'video/webm') {
         $mime = 'audio/webm';
      }

      return ['tipo' => $tipo, 'arquivo' => $mes . '/' . $nome, 'mime' => $mime];
   }

   /**
    * Cria um documento nativo do GLPI com o arquivo de midia, vinculado ao chamado fora da
    * linha do tempo (como as imagens coladas em acompanhamentos). Devolve o id ou 0.
    */
   static function criarDocumento(string $caminho, string $nomeExibido, int $tickets_id): int {
      $ticket = new Ticket();
      if (!is_file($caminho) || !$ticket->getFromDB($tickets_id)) {
         return 0;
      }

      $prefixo = uniqid('wae', true) . '_';
      $arquivo = $prefixo . basename($caminho);
      if (!@copy($caminho, GLPI_TMP_DIR . '/' . $arquivo)) {
         return 0;
      }

      $documento = new Document();
      $documents_id = (int)$documento->add([
         'name'              => $nomeExibido,
         'entities_id'       => (int)$ticket->fields['entities_id'],
         'is_recursive'      => 0,
         '_filename'         => [$arquivo],
         '_prefix_filename'  => [$prefixo],
         '_only_if_upload_succeed' => 1
      ]);

      @unlink(GLPI_TMP_DIR . '/' . $arquivo);

      if ($documents_id <= 0) {
         return 0;
      }

      $vinculo = new Document_Item();
      $vinculo->add([
         'documents_id'      => $documents_id,
         'itemtype'          => 'Ticket',
         'items_id'          => $tickets_id,
         'entities_id'       => (int)$ticket->fields['entities_id'],
         'timeline_position' => CommonITILObject::NO_TIMELINE
      ]);

      return $documents_id;
   }

   /**
    * Quem escreveu a mensagem citada: "Você" (enviada pelo GLPI) ou o contato
    */
   static function autorDaCitada(int $mensagens_id): string {
      global $DB;
      if ($mensagens_id <= 0) {
         return '';
      }
      foreach ($DB->request([
         'SELECT' => ['direcao', 'conversas_id'],
         'FROM'   => 'glpi_plugin_whatsappempresa_mensagens',
         'WHERE'  => ['id' => $mensagens_id],
         'LIMIT'  => 1
      ]) as $linha) {
         if ($linha['direcao'] === 'saida') {
            return 'Você';
         }
         $conversa = self::porId((int)$linha['conversas_id']);
         return $conversa !== null ? (string)($conversa['nome_contato'] ?: $conversa['telefone']) : 'Contato';
      }
      return '';
   }

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
         $temMidia = !empty($linha['tipo_midia']) && !empty($linha['midia_arquivo']);
         $itens[] = [
            'id'          => (int)$linha['id'],
            'direcao'     => $linha['direcao'],
            'conteudo'    => $temMidia ? PluginWhatsappempresaMensagem::legenda($linha) : (string)$linha['conteudo'],
            'tipo_midia'  => $temMidia ? (string)$linha['tipo_midia'] : '',
            // Citacao (resposta a outra mensagem) e reacoes
            'citada'      => trim((string)($linha['citada_texto'] ?? '')) !== '' ? [
               'id'    => (int)($linha['citada_id'] ?? 0),
               'texto' => (string)$linha['citada_texto'],
               'autor' => self::autorDaCitada((int)($linha['citada_id'] ?? 0))
            ] : null,
            'reacao_cliente'   => (string)($linha['reacao_cliente'] ?? ''),
            'reacao_atendente' => (string)($linha['reacao_atendente'] ?? ''),
            'citavel'     => !empty($linha['wa_id']),
            'midia_url'   => $temMidia ? self::urlMidia((int)$linha['id']) : '',
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
