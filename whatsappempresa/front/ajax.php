<?php
/**
 * Endpoint AJAX do plugin whatsappempresa
 * O bootstrap do GLPI (sessao, autoload, CSRF) e feito pelo kernel antes deste arquivo.
 */

while (ob_get_level() > 0) { ob_end_clean(); }
header('Content-Type: application/json; charset=utf-8');

register_shutdown_function(function () {
   $erro = error_get_last();
   if ($erro !== null && in_array($erro['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
      while (ob_get_level() > 0) { ob_end_clean(); }
      echo json_encode(['sucesso' => false, 'mensagem' => 'Erro interno no plugin.']);
   }
});

global $DB;

Session::checkLoginUser();

$acao = (string)($_REQUEST['acao'] ?? '');

// Consultas (GET) so leem: liberar a trava da sessao evita que o polling
// enfileire as outras requisicoes do mesmo navegador e atrase as mensagens
if ($_SERVER['REQUEST_METHOD'] === 'GET' && session_status() === PHP_SESSION_ACTIVE) {
   session_write_close();
}

function wae_responder(array $dados): void {
   // Token novo so no GLPI 11 e so em POST (no GET a sessao ja foi liberada); no 12 nao existe
   $token = $_SERVER['REQUEST_METHOD'] === 'POST' ? PluginWhatsappempresaConfig::tokenCsrf() : '';
   if ($token !== '') {
      $dados['token'] = $token;
   }
   echo json_encode($dados, JSON_UNESCAPED_UNICODE);
   exit;
}

/**
 * Marca do estado do chamado: muda a cada mensagem ou mudanca de conversa
 */
function wae_marca_ticket(int $tickets_id): string {
   global $DB;

   $conversas = [0];
   $carimbo   = '';

   foreach ($DB->request([
      'SELECT' => ['id', 'status', 'nao_lidas', 'date_ultima'],
      'FROM'   => 'glpi_plugin_whatsappempresa_conversas',
      'WHERE'  => ['tickets_id' => $tickets_id, 'is_deleted' => 0],
      'ORDER'  => 'id ASC'
   ]) as $linha) {
      $conversas[] = (int)$linha['id'];
      $carimbo .= $linha['id'] . '-' . $linha['status'] . '-' . $linha['nao_lidas'] . '-' . $linha['date_ultima'] . '|';
   }

   $ultimo = 0;
   foreach ($DB->request([
      'SELECT' => ['id'],
      'FROM'   => 'glpi_plugin_whatsappempresa_mensagens',
      'WHERE'  => ['conversas_id' => $conversas],
      'ORDER'  => 'id DESC',
      'LIMIT'  => 1
   ]) as $linha) {
      $ultimo = (int)$linha['id'];
   }

   $total = countElementsInTable('glpi_plugin_whatsappempresa_mensagens', ['conversas_id' => $conversas]);

   return $ultimo . ':' . $total . ':' . md5($carimbo);
}

function wae_exigir_admin(): void {
   if (!Session::haveRight('config', READ)) {
      wae_responder(['sucesso' => false, 'mensagem' => 'Sem permissao de acesso.']);
   }
}

function wae_exigir_admin_escrita(): void {
   if (!Session::haveRight('config', UPDATE) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
      wae_responder(['sucesso' => false, 'mensagem' => 'Sem permissao para alterar.']);
   }
}

function wae_usuario(): string {
   return PluginWhatsappempresaConfig::nomeUsuario((int)Session::getLoginUserID());
}

try {

switch ($acao) {

   // ============================================
   // Servidor: preparacao e controle
   // ============================================

   case 'etapas':
      wae_exigir_admin();
      wae_responder([
         'sucesso' => true,
         'etapas'  => PluginWhatsappempresaServidor::etapas((string)($_REQUEST['webhook'] ?? '0') === '1')
      ]);

   case 'acao_servidor':
      wae_exigir_admin_escrita();
      @set_time_limit(120);
      $tipo = (string)($_POST['tipo'] ?? '');
      $usuario = (int)Session::getLoginUserID();

      switch ($tipo) {
         case 'node_instalar':
         case 'node_atualizar':
            $erro = PluginWhatsappempresaServidor::instalarNode();
            if ($erro === null) {
               PluginWhatsappempresaConfig::set('reiniciar_apos_node', PluginWhatsappempresaServidor::deveEstarLigado() ? '1' : '0');
               PluginWhatsappempresaLog::registrar('Instalacao do Node.js iniciada', wae_usuario(), 'info', 'painel', $usuario);
            }
            wae_responder(['sucesso' => $erro === null, 'mensagem' => $erro ?? 'Download do Node.js iniciado.']);

         case 'dependencias_instalar':
            $erro = PluginWhatsappempresaServidor::instalarDependencias();
            if ($erro === null) {
               PluginWhatsappempresaLog::registrar('Instalacao das dependencias iniciada', wae_usuario(), 'info', 'painel', $usuario);
            }
            wae_responder(['sucesso' => $erro === null, 'mensagem' => $erro ?? 'Instalacao das dependencias iniciada.']);

         case 'vigia_ativar':
         case 'vigia_desativar':
            $ativar = ($tipo === 'vigia_ativar');
            $erro = PluginWhatsappempresaServidor::ajustarCron($ativar);
            if ($erro === null) {
               PluginWhatsappempresaLog::registrar($ativar ? 'Vigia do servidor ativado' : 'Vigia do servidor desativado', wae_usuario(), 'info', 'painel', $usuario);
            }
            wae_responder([
               'sucesso'  => $erro === null,
               'mensagem' => $erro ?? ($ativar ? 'Vigia ativado: o servidor volta sozinho se cair.' : 'Vigia desativado.')
            ]);

         case 'webhook_testar':
            $r = PluginWhatsappempresaServidor::testarWebhook(12);
            wae_responder([
               'sucesso'  => $r['ok'],
               'mensagem' => $r['ok'] ? 'O GLPI respondeu corretamente em ' . $r['url'] : $r['erro']
            ]);

         case 'servico_iniciar':
         case 'servico_reiniciar':
            if ($tipo === 'servico_reiniciar') {
               PluginWhatsappempresaServidor::requisitar('POST', '/servico/parar', [], 5);
               PluginWhatsappempresaServidor::parar();
            } elseif (PluginWhatsappempresaServidor::ativo()) {
               wae_responder(['sucesso' => true, 'mensagem' => 'O servidor ja estava em execucao.']);
            }
            $erro = PluginWhatsappempresaServidor::iniciar();
            if ($erro !== null) {
               wae_responder(['sucesso' => false, 'mensagem' => $erro]);
            }
            $ligou = PluginWhatsappempresaServidor::aguardar(20, true);
            PluginWhatsappempresaLog::registrar(
               $tipo === 'servico_iniciar' ? 'Servidor iniciado pelo painel' : 'Servidor reiniciado pelo painel',
               wae_usuario(), $ligou ? 'info' : 'erro', 'painel', $usuario
            );
            wae_responder([
               'sucesso'  => $ligou,
               'mensagem' => $ligou ? 'Servidor em execucao.' : 'O servidor nao respondeu. Veja o log do servidor.',
               'log'      => $ligou ? '' : PluginWhatsappempresaServidor::logServidor(25)
            ]);

         case 'servico_parar':
            PluginWhatsappempresaServidor::requisitar('POST', '/servico/parar', [], 5);
            PluginWhatsappempresaServidor::parar();
            PluginWhatsappempresaLog::registrar('Servidor parado pelo painel', wae_usuario(), 'info', 'painel', $usuario);
            wae_responder(['sucesso' => true, 'mensagem' => 'Servidor parado.']);

         case 'aparelho_desvincular':
            $r = PluginWhatsappempresaServidor::requisitar('POST', '/servico/desvincular', [], 15);
            if (!$r['ok']) {
               PluginWhatsappempresaServidor::parar();
               PluginWhatsappempresaServidor::limparAutenticacao();
               PluginWhatsappempresaServidor::iniciar();
               PluginWhatsappempresaServidor::aguardar(15, true);
            } else {
               PluginWhatsappempresaConfig::set('numero_host', '');
            }
            PluginWhatsappempresaLog::registrar('Aparelho do WhatsApp desvinculado', wae_usuario(), 'aviso', 'painel', $usuario);
            wae_responder(['sucesso' => true, 'mensagem' => 'Aparelho desvinculado. Leia o novo QR Code.']);
      }

      wae_responder(['sucesso' => false, 'mensagem' => 'Acao do servidor desconhecida.']);

   case 'tarefa':
      wae_exigir_admin();
      $nome = (string)($_REQUEST['tarefa'] ?? '');
      if (!PluginWhatsappempresaServidor::nomeTarefaValido($nome)) {
         wae_responder(['sucesso' => false, 'mensagem' => 'Tarefa invalida.']);
      }
      $situacao = PluginWhatsappempresaServidor::situacaoTarefa($nome);

      // Conclusoes que dependem do fim da tarefa
      if ($situacao['sucesso'] && $nome === 'dependencias') {
         PluginWhatsappempresaServidor::confirmarDependencias();
      }
      if ($situacao['sucesso'] && $nome === 'node' && PluginWhatsappempresaConfig::ativo('reiniciar_apos_node')) {
         PluginWhatsappempresaConfig::set('reiniciar_apos_node', '0');
         PluginWhatsappempresaServidor::parar();
         PluginWhatsappempresaServidor::iniciar();
      }

      wae_responder(['sucesso' => true, 'tarefa' => $situacao]);

   case 'qrcode':
      wae_exigir_admin();
      wae_responder(['sucesso' => true, 'qr' => PluginWhatsappempresaServidor::qrcode()]);

   case 'painel_info':
      wae_exigir_admin();
      $status = PluginWhatsappempresaServidor::status();
      wae_responder([
         'sucesso' => true,
         'rede'    => PluginWhatsappempresaServidor::infoRede(),
         'tempos'  => PluginWhatsappempresaServidor::infoTempos($status)
      ]);

   case 'log_servidor':
      wae_exigir_admin();
      wae_responder(['sucesso' => true, 'conteudo' => PluginWhatsappempresaServidor::logServidor(120)]);

   case 'enviar_teste':
      wae_exigir_admin_escrita();
      $texto = trim((string)($_POST['texto'] ?? ''));
      $resultado = PluginWhatsappempresaMensagem::enviar(
         (string)($_POST['telefone'] ?? ''),
         $texto !== '' ? $texto : 'Mensagem de teste do WhatsApp Empresa.',
         ['fluxo' => 'teste', 'users_id' => (int)Session::getLoginUserID()]
      );
      wae_responder([
         'sucesso'  => $resultado['ok'],
         'mensagem' => $resultado['ok'] ? 'Mensagem enviada.' : (string)$resultado['erro']
      ]);

   // ============================================
   // Conversas
   // ============================================

   case 'conversas_ticket':
      $tickets_id = (int)($_REQUEST['tickets_id'] ?? 0);
      $ticket = new Ticket();
      if (!$ticket->getFromDB($tickets_id) || !$ticket->canViewItem()) {
         wae_responder(['sucesso' => false, 'mensagem' => 'Sem permissao para este chamado.']);
      }

      $incluirEncerradas = ((string)($_REQUEST['encerradas'] ?? '0') === '1');
      $conversas = PluginWhatsappempresaConversa::conversasDoTicket($tickets_id, $incluirEncerradas);
      $mensagens = [];
      $resumo    = [];

      foreach ($conversas as $conversa) {
         PluginWhatsappempresaConversa::marcarLida((int)$conversa['id']);

         $sessao = PluginWhatsappempresaFluxo::obterSessao((string)$conversa['telefone']);
         $presa  = (PluginWhatsappempresaFluxo::fluxoAtual($sessao) === PluginWhatsappempresaFluxo::FLUXO_CONVERSA)
                   && ((int)$sessao['conversas_id'] === (int)$conversa['id']);

         $resumo[] = [
            'id'       => (int)$conversa['id'],
            'contato'  => $conversa['nome_contato'] ?: $conversa['telefone'],
            'telefone' => $conversa['telefone'],
            'status'   => $conversa['status'],
            'origem'   => $conversa['origem'],
            'presa'    => $presa,
            'motivo'   => (string)($conversa['motivo_encerramento'] ?? '')
         ];

         foreach (PluginWhatsappempresaConversa::listarMensagens((int)$conversa['id']) as $mensagem) {
            $mensagem['contato'] = $conversa['nome_contato'] ?: $conversa['telefone'];
            $mensagens[] = $mensagem;
         }
      }

      PluginWhatsappempresaConversa::marcarAlertasLidos((int)Session::getLoginUserID());

      wae_responder([
         'sucesso'    => true,
         'marca'      => wae_marca_ticket($tickets_id),
         'requerente' => PluginWhatsappempresaConversa::requerenteDoTicket($tickets_id),
         'conversas'  => $resumo,
         'mensagens'  => $mensagens
      ]);

   case 'conversa_encerrar':
      $conversas_id = (int)($_POST['conversas_id'] ?? 0);
      $users_id     = (int)Session::getLoginUserID();

      $permitido = false;
      foreach ($DB->request([
         'FROM'  => 'glpi_plugin_whatsappempresa_conversas',
         'WHERE' => ['id' => $conversas_id],
         'LIMIT' => 1
      ]) as $conversa) {
         $ticket = new Ticket();
         $permitido = ((int)$conversa['tecnicos_id'] === $users_id)
            || ((int)$conversa['tickets_id'] > 0 && $ticket->getFromDB((int)$conversa['tickets_id']) && $ticket->canViewItem());
      }

      if (!$permitido) {
         wae_responder(['sucesso' => false, 'mensagem' => 'Sem permissao para encerrar esta conversa.']);
      }

      $resultado = PluginWhatsappempresaConversa::encerrar($conversas_id, [
         'motivo'   => 'Encerrada pelo atendente no GLPI',
         'origem'   => 'tecnico',
         'users_id' => $users_id
      ]);

      if (!$resultado['ok']) {
         wae_responder(['sucesso' => false, 'mensagem' => (string)$resultado['erro']]);
      }

      $aviso = 'Conversa encerrada e numero liberado para o atendimento automatico.';
      if (!empty($resultado['followup'])) {
         $aviso .= ' Acompanhamento gravado no chamado #' . (int)$resultado['tickets_id'] . '.';
      }

      wae_responder(['sucesso' => true, 'mensagem' => $aviso, 'followup' => !empty($resultado['followup'])]);

   case 'conversa_enviar':
      $tickets_id = (int)($_POST['tickets_id'] ?? 0);
      $telefone   = (string)($_POST['telefone'] ?? '');
      $texto      = trim((string)($_POST['texto'] ?? ''));
      $nome       = (string)($_POST['nome'] ?? '');

      if ($texto === '' || $telefone === '') {
         wae_responder(['sucesso' => false, 'mensagem' => 'Informe o numero e a mensagem.']);
      }

      if ($tickets_id > 0) {
         $ticket = new Ticket();
         if (!$ticket->getFromDB($tickets_id) || !$ticket->canViewItem()) {
            wae_responder(['sucesso' => false, 'mensagem' => 'Sem permissao para este chamado.']);
         }
      }

      $resultado = PluginWhatsappempresaConversa::enviarDoTecnico($telefone, $texto, $tickets_id, $nome);

      wae_responder([
         'sucesso'  => $resultado['ok'],
         'mensagem' => $resultado['ok'] ? 'Mensagem enviada.' : (string)$resultado['erro']
      ]);

   case 'buscar_destinos':
      $termo = trim((string)($_REQUEST['termo'] ?? ''));
      if (mb_strlen($termo) < 2) {
         wae_responder(['sucesso' => true, 'itens' => []]);
      }

      $itens = [];

      foreach ($DB->request([
         'SELECT' => ['id', 'name', 'firstname', 'realname', 'mobile', 'phone'],
         'FROM'   => 'glpi_users',
         'WHERE'  => [
            'is_active'  => 1,
            'is_deleted' => 0,
            'OR' => [
               ['name'      => ['LIKE', '%' . $termo . '%']],
               ['realname'  => ['LIKE', '%' . $termo . '%']],
               ['firstname' => ['LIKE', '%' . $termo . '%']]
            ]
         ],
         'LIMIT' => 15
      ]) as $linha) {
         $telefone = PluginWhatsappempresaConfig::telefoneDoUsuario((int)$linha['id']);
         if ($telefone === '') {
            continue;
         }
         $itens[] = [
            'tipo'     => 'Usuario',
            'nome'     => trim(($linha['firstname'] ?? '') . ' ' . ($linha['realname'] ?? '')) ?: $linha['name'],
            'telefone' => $telefone
         ];
      }

      foreach ($DB->request([
         'SELECT' => ['id', 'name', 'firstname', 'mobile', 'phone'],
         'FROM'   => 'glpi_contacts',
         'WHERE'  => [
            'is_deleted' => 0,
            'OR' => [
               ['name'      => ['LIKE', '%' . $termo . '%']],
               ['firstname' => ['LIKE', '%' . $termo . '%']]
            ]
         ],
         'LIMIT' => 15
      ]) as $linha) {
         $telefone = PluginWhatsappempresaConfig::limparTelefone((string)($linha['mobile'] ?: $linha['phone']));
         if (strlen($telefone) < 10) {
            continue;
         }
         $itens[] = [
            'tipo'     => 'Contato',
            'nome'     => trim(($linha['firstname'] ?? '') . ' ' . $linha['name']),
            'telefone' => $telefone
         ];
      }

      wae_responder(['sucesso' => true, 'itens' => $itens]);

   // ============================================
   // Alertas do botao flutuante
   // ============================================

   case 'alertas':
      $users_id = (int)Session::getLoginUserID();
      wae_responder([
         'sucesso'   => true,
         'alertas'   => PluginWhatsappempresaConversa::alertasDoUsuario($users_id),
         'conversas' => PluginWhatsappempresaConversa::conversasDoTecnico($users_id)
      ]);

   case 'alertas_lidos':
      $users_id = (int)Session::getLoginUserID();
      PluginWhatsappempresaConversa::marcarAlertasLidos($users_id, (int)($_POST['conversas_id'] ?? 0));
      wae_responder(['sucesso' => true]);

   case 'conversa_mensagens':
      $conversas_id = (int)($_REQUEST['conversas_id'] ?? 0);
      $users_id     = (int)Session::getLoginUserID();

      $permitido = false;
      foreach ($DB->request([
         'FROM'  => 'glpi_plugin_whatsappempresa_conversas',
         'WHERE' => ['id' => $conversas_id],
         'LIMIT' => 1
      ]) as $conversa) {
         $ticket = new Ticket();
         $permitido = ((int)$conversa['tecnicos_id'] === $users_id)
            || ((int)$conversa['tickets_id'] > 0 && $ticket->getFromDB((int)$conversa['tickets_id']) && $ticket->canViewItem());
      }

      if (!$permitido) {
         wae_responder(['sucesso' => false, 'mensagem' => 'Sem permissao para esta conversa.']);
      }

      PluginWhatsappempresaConversa::marcarLida($conversas_id);
      PluginWhatsappempresaConversa::marcarAlertasLidos($users_id, $conversas_id);

      wae_responder([
         'sucesso'   => true,
         'mensagens' => PluginWhatsappempresaConversa::listarMensagens($conversas_id)
      ]);

   // ============================================
   // Pulso: consulta barata usada pelo tempo real
   // ============================================

   case 'conversa_pulso':
      wae_responder([
         'sucesso' => true,
         'marca'   => wae_marca_ticket((int)($_REQUEST['tickets_id'] ?? 0))
      ]);

   case 'alertas_pulso':
      $users_id = (int)Session::getLoginUserID();

      $ultimo = 0;
      foreach ($DB->request([
         'SELECT' => ['id'],
         'FROM'   => 'glpi_plugin_whatsappempresa_alertas',
         'WHERE'  => ['users_id' => $users_id, 'lido' => 0],
         'ORDER'  => 'id DESC',
         'LIMIT'  => 1
      ]) as $linha) {
         $ultimo = (int)$linha['id'];
      }

      $total = 0;
      foreach ($DB->request([
         'COUNT' => 'total',
         'FROM'  => 'glpi_plugin_whatsappempresa_alertas',
         'WHERE' => ['users_id' => $users_id, 'lido' => 0]
      ]) as $linha) {
         $total = (int)$linha['total'];
      }

      $abertas = 0;
      foreach ($DB->request([
         'COUNT' => 'total',
         'FROM'  => 'glpi_plugin_whatsappempresa_conversas',
         'WHERE' => ['tecnicos_id' => $users_id, 'status' => 'aberta', 'is_deleted' => 0]
      ]) as $linha) {
         $abertas = (int)$linha['total'];
      }

      wae_responder(['sucesso' => true, 'marca' => $ultimo . ':' . $total . ':' . $abertas, 'nao_lidos' => $total]);

   case 'conversa_pulso_id':
      $conversas_id = (int)($_REQUEST['conversas_id'] ?? 0);

      $ultimo = 0;
      foreach ($DB->request([
         'SELECT' => ['id'],
         'FROM'   => 'glpi_plugin_whatsappempresa_mensagens',
         'WHERE'  => ['conversas_id' => $conversas_id],
         'ORDER'  => 'id DESC',
         'LIMIT'  => 1
      ]) as $linha) {
         $ultimo = (int)$linha['id'];
      }

      $total = 0;
      foreach ($DB->request([
         'COUNT' => 'total',
         'FROM'  => 'glpi_plugin_whatsappempresa_mensagens',
         'WHERE' => ['conversas_id' => $conversas_id]
      ]) as $linha) {
         $total = (int)$linha['total'];
      }

      wae_responder(['sucesso' => true, 'marca' => $ultimo . ':' . $total]);

   // ============================================
   // Fluxo do numero
   // ============================================

   case 'fluxo_situacao':
      wae_exigir_admin();
      $telefone = (string)($_REQUEST['telefone'] ?? '');
      if (PluginWhatsappempresaConfig::limparTelefone($telefone) === '') {
         wae_responder(['sucesso' => false, 'mensagem' => 'Informe o numero.']);
      }

      $sessao  = PluginWhatsappempresaFluxo::obterSessao($telefone);
      $aberta  = PluginWhatsappempresaConversa::abertaDoNumero($telefone);

      wae_responder([
         'sucesso'      => true,
         'fluxo'        => PluginWhatsappempresaFluxo::fluxoAtual($sessao),
         'etapa'        => (string)$sessao['etapa'],
         'conversas_id' => (int)$sessao['conversas_id'],
         'tickets_id'   => (int)$sessao['tickets_id'],
         'aberta'       => $aberta !== null ? (int)$aberta['id'] : 0
      ]);

   case 'fluxo_liberar':
      wae_exigir_admin_escrita();
      $telefone = (string)($_POST['telefone'] ?? '');
      if (PluginWhatsappempresaConfig::limparTelefone($telefone) === '') {
         wae_responder(['sucesso' => false, 'mensagem' => 'Informe o numero.']);
      }

      $aberta = PluginWhatsappempresaConversa::abertaDoNumero($telefone);
      if ($aberta !== null) {
         PluginWhatsappempresaConversa::encerrar((int)$aberta['id'], [
            'motivo'   => 'Numero liberado pelo painel',
            'origem'   => 'sistema',
            'users_id' => (int)Session::getLoginUserID()
         ]);
      } else {
         PluginWhatsappempresaFluxo::sairFluxo($telefone, 'Numero liberado pelo painel');
      }

      wae_responder(['sucesso' => true, 'mensagem' => 'Numero devolvido ao atendimento automatico.']);

   // ============================================
   // Fluxos montados no painel
   // ============================================

   case 'fluxos_listar':
      wae_exigir_admin();
      $itens = [];
      foreach (PluginWhatsappempresaConstrutor::listar() as $fluxo) {
         $itens[] = [
            'id'        => (int)$fluxo['id'],
            'nome'      => (string)$fluxo['nome'],
            'descricao' => (string)$fluxo['descricao'],
            'gatilho'   => (string)$fluxo['gatilho'],
            'palavras'  => (string)$fluxo['palavras'],
            'is_ativo'  => (int)$fluxo['is_ativo'],
            'is_padrao' => (int)$fluxo['is_padrao'],
            'ordem'     => (int)$fluxo['ordem'],
            'passos'    => count($fluxo['passos']),
            'avisos'    => count(PluginWhatsappempresaConstrutor::validar($fluxo)),
            'date_mod'  => Html::convDateTime($fluxo['date_mod'])
         ];
      }
      wae_responder(['sucesso' => true, 'itens' => $itens]);

   case 'fluxo_obter':
      wae_exigir_admin();
      $fluxo = PluginWhatsappempresaConstrutor::porId((int)($_REQUEST['id'] ?? 0));
      if ($fluxo === null) {
         wae_responder(['sucesso' => false, 'mensagem' => 'Fluxo nao encontrado.']);
      }
      wae_responder([
         'sucesso' => true,
         'fluxo'   => [
            'id'        => (int)$fluxo['id'],
            'nome'      => (string)$fluxo['nome'],
            'descricao' => (string)$fluxo['descricao'],
            'gatilho'   => (string)$fluxo['gatilho'],
            'palavras'  => (string)$fluxo['palavras'],
            'is_ativo'  => (int)$fluxo['is_ativo'],
            'ordem'     => (int)$fluxo['ordem'],
            'passos'    => $fluxo['passos']
         ],
         'avisos' => PluginWhatsappempresaConstrutor::validar($fluxo)
      ]);

   case 'fluxo_salvar':
      wae_exigir_admin_escrita();
      $bruto  = (string)($_POST['dados'] ?? '');
      $entrada = json_decode($bruto, true);

      if (!is_array($entrada)) {
         wae_responder(['sucesso' => false, 'mensagem' => 'Nao foi possivel ler o fluxo enviado.']);
      }
      if (trim((string)($entrada['nome'] ?? '')) === '') {
         wae_responder(['sucesso' => false, 'mensagem' => 'Informe o nome do fluxo.']);
      }

      $id = PluginWhatsappempresaConstrutor::salvar($entrada);

      if ($id <= 0) {
         wae_responder(['sucesso' => false, 'mensagem' => 'Nao foi possivel gravar o fluxo.']);
      }

      PluginWhatsappempresaLog::registrar(
         'Fluxo do WhatsApp salvo',
         'Fluxo #' . $id . ' por ' . PluginWhatsappempresaConfig::nomeUsuario((int)Session::getLoginUserID()),
         'info',
         'construtor',
         (int)Session::getLoginUserID()
      );

      $gravado = PluginWhatsappempresaConstrutor::porId($id);

      wae_responder([
         'sucesso'  => true,
         'mensagem' => 'Fluxo salvo.',
         'id'       => $id,
         'avisos'   => $gravado !== null ? PluginWhatsappempresaConstrutor::validar($gravado) : []
      ]);

   case 'fluxo_remover':
      wae_exigir_admin_escrita();
      PluginWhatsappempresaConstrutor::remover((int)($_POST['id'] ?? 0));
      wae_responder(['sucesso' => true, 'mensagem' => 'Fluxo removido.']);

   case 'fluxo_duplicar':
      wae_exigir_admin_escrita();
      $novo = PluginWhatsappempresaConstrutor::duplicar((int)($_POST['id'] ?? 0));
      if ($novo <= 0) {
         wae_responder(['sucesso' => false, 'mensagem' => 'Nao foi possivel duplicar o fluxo.']);
      }
      wae_responder(['sucesso' => true, 'mensagem' => 'Copia criada como inativa.', 'id' => $novo]);

   case 'fluxos_reordenar':
      wae_exigir_admin_escrita();
      $ordem = json_decode((string)($_POST['ordem'] ?? '[]'), true);
      if (!is_array($ordem)) {
         wae_responder(['sucesso' => false, 'mensagem' => 'Ordem invalida.']);
      }
      PluginWhatsappempresaConstrutor::reordenar($ordem);
      wae_responder(['sucesso' => true, 'mensagem' => 'Ordem atualizada.']);

   case 'fluxo_catalogo':
      wae_exigir_admin();
      wae_responder(['sucesso' => true, 'catalogo' => PluginWhatsappempresaConstrutor::catalogo()]);

   case 'fluxo_testar':
      wae_exigir_admin();
      $entradas = json_decode((string)($_POST['entradas'] ?? '[]'), true);
      if (!is_array($entradas)) {
         $entradas = [];
      }
      $resultado = PluginWhatsappempresaConstrutor::simular(
         (int)($_POST['id'] ?? 0),
         array_slice($entradas, 0, 30),
         (int)Session::getLoginUserID()
      );
      wae_responder(['sucesso' => !empty($resultado['ok'])] + $resultado);

   default:
      wae_responder(['sucesso' => false, 'mensagem' => 'Acao desconhecida.']);
}

} catch (Throwable $e) {
   PluginWhatsappempresaLog::registrar('Erro no endpoint AJAX', $e->getMessage() . ' em ' . basename($e->getFile()) . ':' . $e->getLine(), 'erro', 'painel', (int)Session::getLoginUserID());
   wae_responder(['sucesso' => false, 'mensagem' => 'Erro interno ao processar a acao.']);
}
