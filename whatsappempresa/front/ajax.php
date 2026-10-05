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

// Numero (conexao) com que a tela esta trabalhando: servidor, QR, log, fluxos, testes
if (isset($_REQUEST['conexao']) && (int)$_REQUEST['conexao'] > 0) {
   PluginWhatsappempresaConexao::usar((int)$_REQUEST['conexao']);
}

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

   // Reacoes e confirmacoes de envio alteram a mensagem sem criar outra
   $alterada = '';
   foreach ($DB->request([
      'SELECT' => [new \Glpi\DBAL\QueryExpression('MAX(' . $DB->quoteName('date_mod') . ') AS alterada')],
      'FROM'   => 'glpi_plugin_whatsappempresa_mensagens',
      'WHERE'  => ['conversas_id' => $conversas]
   ]) as $linha) {
      $alterada = (string)($linha['alterada'] ?? '');
   }

   return $ultimo . ':' . $total . ':' . md5($carimbo . $alterada);
}

/**
 * Mensagem citada no envio (citar_id), somente de conversa que o usuario pode ver
 */
function wae_mensagem_citada(): ?array {
   global $DB;
   $id = (int)($_POST['citar_id'] ?? 0);
   if ($id <= 0) {
      return null;
   }
   foreach ($DB->request(['FROM' => 'glpi_plugin_whatsappempresa_mensagens', 'WHERE' => ['id' => $id], 'LIMIT' => 1]) as $linha) {
      if (!empty($linha['wa_id']) && PluginWhatsappempresaConversa::podeVerConversa((int)$linha['conversas_id'])) {
         return $linha;
      }
   }
   return null;
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

   case 'conexoes':
      // Cartoes da aba Servidor: situacao de cada numero conectado
      wae_exigir_admin();
      wae_responder(['sucesso' => true, 'conexoes' => PluginWhatsappempresaServidor::resumoConexoes()]);

   case 'conexao_criar':
      wae_exigir_admin_escrita();
      $id = PluginWhatsappempresaConexao::criar((string)($_POST['nome'] ?? ''));
      if (is_string($id)) {
         wae_responder(['sucesso' => false, 'mensagem' => $id]);
      }
      PluginWhatsappempresaConexao::usar($id);
      $mensagem = 'Conexao criada.';
      // Com o Node e as dependencias prontos, ja inicia para mostrar o QR Code
      if (PluginWhatsappempresaServidor::nodeValido() && PluginWhatsappempresaServidor::situacaoDependencias()['completo']) {
         $erro = PluginWhatsappempresaServidor::iniciar();
         $mensagem = $erro === null ? 'Conexao criada e iniciada: leia o QR Code com o novo aparelho.' : 'Conexao criada, mas nao iniciou: ' . $erro;
      } else {
         $mensagem .= ' Conclua a preparacao do servidor (Node.js e dependencias) e inicie-a.';
      }
      PluginWhatsappempresaLog::registrar('Conexao de WhatsApp criada', PluginWhatsappempresaConexao::nome($id) . ' por ' . wae_usuario(), 'info', 'painel', (int)Session::getLoginUserID());
      wae_responder(['sucesso' => true, 'mensagem' => $mensagem, 'id' => $id]);

   case 'conexao_editar':
      wae_exigir_admin_escrita();
      $id = (int)($_POST['conexao'] ?? 0);
      if (PluginWhatsappempresaConexao::porId($id) === null) {
         wae_responder(['sucesso' => false, 'mensagem' => 'Conexao nao encontrada.']);
      }
      switch ((string)($_POST['tipo'] ?? '')) {
         case 'renomear':
            $erro = PluginWhatsappempresaConexao::renomear($id, (string)($_POST['nome'] ?? ''));
            wae_responder(['sucesso' => $erro === null, 'mensagem' => $erro ?? 'Nome alterado.']);
         case 'padrao':
            PluginWhatsappempresaConexao::definirPadrao($id);
            wae_responder(['sucesso' => true, 'mensagem' => PluginWhatsappempresaConexao::nome($id) . ' agora e a conexao padrao.']);
         case 'remover':
            $nome = PluginWhatsappempresaConexao::nome($id);
            $erro = PluginWhatsappempresaConexao::remover($id);
            if ($erro === null) {
               PluginWhatsappempresaLog::registrar('Conexao de WhatsApp removida', $nome . ' por ' . wae_usuario(), 'aviso', 'painel', (int)Session::getLoginUserID());
            }
            wae_responder(['sucesso' => $erro === null, 'mensagem' => $erro ?? 'Conexao removida.']);
      }
      wae_responder(['sucesso' => false, 'mensagem' => 'Acao desconhecida.']);

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
               PluginWhatsappempresaConfig::set('reiniciar_apos_node', '1');
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
               PluginWhatsappempresaConexao::gravarNumero(PluginWhatsappempresaConexao::atual(), '');
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
         // Node novo: reinicia todos os numeros que estavam ligados
         foreach (PluginWhatsappempresaConexao::listar() as $conexao) {
            $anterior = PluginWhatsappempresaConexao::usar((int)$conexao['id']);
            if (PluginWhatsappempresaServidor::deveEstarLigado()) {
               PluginWhatsappempresaServidor::parar();
               PluginWhatsappempresaServidor::iniciar();
            }
            PluginWhatsappempresaConexao::restaurar($anterior);
         }
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
         ['fluxo' => 'teste', 'users_id' => (int)Session::getLoginUserID(), 'conexoes_id' => PluginWhatsappempresaConexao::atual()]
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

      $variasConexoes = count(PluginWhatsappempresaConexao::listar()) > 1;
      foreach ($conversas as $conversa) {
         PluginWhatsappempresaConversa::marcarLida((int)$conversa['id']);

         // A sessao do telefone e a do numero (conexao) desta conversa
         $conexaoAnterior = PluginWhatsappempresaConexao::usar((int)($conversa['conexoes_id'] ?? 1));
         $sessao = PluginWhatsappempresaFluxo::obterSessao((string)$conversa['telefone']);
         PluginWhatsappempresaConexao::restaurar($conexaoAnterior);
         $presa  = (PluginWhatsappempresaFluxo::fluxoAtual($sessao) === PluginWhatsappempresaFluxo::FLUXO_CONVERSA)
                   && ((int)$sessao['conversas_id'] === (int)$conversa['id']);

         $resumo[] = [
            'id'       => (int)$conversa['id'],
            'contato'  => $conversa['nome_contato'] ?: $conversa['telefone'],
            'telefone' => $conversa['telefone'],
            'status'   => $conversa['status'],
            'origem'   => $conversa['origem'],
            'presa'    => $presa,
            'motivo'   => (string)($conversa['motivo_encerramento'] ?? ''),
            'conexoes_id' => (int)($conversa['conexoes_id'] ?? 1),
            'conexao'  => $variasConexoes ? PluginWhatsappempresaConexao::rotulo((int)($conversa['conexoes_id'] ?? 1)) : ''
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
         'mensagens'  => $mensagens,
         // Numeros disponiveis para enviar (seletor "Enviar por" quando houver mais de um)
         'conexoes'   => PluginWhatsappempresaConexao::opcoes()
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

      $resultado = PluginWhatsappempresaConversa::enviarDoTecnico($telefone, $texto, $tickets_id, $nome, null, wae_mensagem_citada(), (int)($_POST['enviar_por'] ?? 0));

      wae_responder([
         'sucesso'  => $resultado['ok'],
         'mensagem' => $resultado['ok'] ? 'Mensagem enviada.' : (string)$resultado['erro']
      ]);

   case 'conversa_enviar_midia':
      $tickets_id = (int)($_POST['tickets_id'] ?? 0);
      $telefone   = (string)($_POST['telefone'] ?? '');
      $legenda    = trim((string)($_POST['texto'] ?? ''));
      $nome       = (string)($_POST['nome'] ?? '');
      $tipo       = (string)($_POST['tipo'] ?? '') === 'audio' ? 'audio' : 'imagem';

      if ($telefone === '' || empty($_FILES['arquivo'])) {
         wae_responder(['sucesso' => false, 'mensagem' => 'Informe o numero e o arquivo.']);
      }

      if ($tickets_id > 0) {
         $ticket = new Ticket();
         if (!$ticket->getFromDB($tickets_id) || !$ticket->canViewItem()) {
            wae_responder(['sucesso' => false, 'mensagem' => 'Sem permissao para este chamado.']);
         }
      }

      $midia = PluginWhatsappempresaConversa::salvarUpload($_FILES['arquivo'], $tipo);
      if (is_string($midia)) {
         wae_responder(['sucesso' => false, 'mensagem' => $midia]);
      }

      $resultado = PluginWhatsappempresaConversa::enviarDoTecnico($telefone, $legenda, $tickets_id, $nome, $midia, wae_mensagem_citada(), (int)($_POST['enviar_por'] ?? 0));

      wae_responder([
         'sucesso'  => $resultado['ok'],
         'mensagem' => $resultado['ok']
            ? ($tipo === 'audio' ? 'Audio enviado.' : 'Imagem enviada.')
            : (string)$resultado['erro']
      ]);

   case 'mensagens_ao_vivo':
      // Aba Mensagens da configuracao: todas as mensagens enviadas e recebidas
      wae_exigir_admin();
      $resultado = PluginWhatsappempresaMensagem::historicoAoVivo([
         'direcao'     => (string)($_GET['direcao'] ?? ''),
         'tipo'        => (string)($_GET['tipo'] ?? ''),
         'busca'       => (string)($_GET['busca'] ?? ''),
         'com_chamado' => !empty($_GET['com_chamado']),
         'conexao'     => (int)($_GET['filtro_conexao'] ?? 0)
      ], (int)($_GET['depois'] ?? 0), (int)($_GET['antes'] ?? 0), max(10, min(200, (int)($_GET['limite'] ?? 100))));
      wae_responder(['sucesso' => true] + $resultado + ['tipos' => PluginWhatsappempresaMensagem::TIPOS_HISTORICO]);

   case 'conversa_reagir':
      // Reacao (emoji) do atendente numa mensagem; emoji vazio remove
      $mensagens_id = (int)($_POST['mensagens_id'] ?? 0);
      $emoji = mb_substr(trim((string)($_POST['emoji'] ?? '')), 0, 16);
      $linha = null;
      foreach ($DB->request(['FROM' => 'glpi_plugin_whatsappempresa_mensagens', 'WHERE' => ['id' => $mensagens_id], 'LIMIT' => 1]) as $registro) {
         $linha = $registro;
      }
      if ($linha === null || empty($linha['wa_id']) || !PluginWhatsappempresaConversa::podeVerConversa((int)$linha['conversas_id'])) {
         wae_responder(['sucesso' => false, 'mensagem' => 'Esta mensagem nao pode receber reacao.']);
      }
      // A reacao sai pelo mesmo numero que trocou a mensagem
      PluginWhatsappempresaConexao::usar((int)($linha['conexoes_id'] ?? 1));
      $envio = PluginWhatsappempresaServidor::reagir((string)$linha['telefone'], (string)$linha['wa_id'], $linha['direcao'] === 'saida', $emoji);
      if (!$envio['ok']) {
         wae_responder(['sucesso' => false, 'mensagem' => (string)$envio['erro']]);
      }
      $DB->update('glpi_plugin_whatsappempresa_mensagens', ['reacao_atendente' => $emoji !== '' ? $emoji : null], ['id' => $mensagens_id]);
      wae_responder(['sucesso' => true]);

   case 'midia':
      // Arquivo de imagem ou audio de uma mensagem, conferindo quem pode ver a conversa
      $mensagens_id = (int)($_GET['id'] ?? 0);
      $linha = null;
      foreach ($DB->request([
         'FROM'  => 'glpi_plugin_whatsappempresa_mensagens',
         'WHERE' => ['id' => $mensagens_id],
         'LIMIT' => 1
      ]) as $registro) {
         $linha = $registro;
      }

      $caminho = $linha !== null ? PluginWhatsappempresaServidor::caminhoMidia((string)$linha['midia_arquivo']) : null;
      if ($caminho === null || !PluginWhatsappempresaConversa::podeVerConversa((int)$linha['conversas_id'])) {
         http_response_code(404);
         header('Content-Type: text/plain; charset=utf-8');
         echo 'Arquivo nao encontrado.';
         exit;
      }

      $mime = (string)($linha['midia_mime'] ?: 'application/octet-stream');
      $mime = explode(';', $mime)[0];
      header('Content-Type: ' . $mime);
      header('Content-Length: ' . filesize($caminho));
      header('Content-Disposition: inline; filename="' . basename($caminho) . '"');
      header('Cache-Control: private, max-age=86400');
      header('X-Content-Type-Options: nosniff');
      readfile($caminho);
      exit;

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
   // Fluxos montados no painel (editor visual)
   // ============================================

   case 'fluxos_listar':
      wae_exigir_admin();
      $itens = [];
      foreach (PluginWhatsappempresaConstrutor::listar() as $fluxo) {
         $itens[] = [
            'id'       => (int)$fluxo['id'],
            'nome'     => (string)$fluxo['nome'],
            'gatilho'  => (string)$fluxo['gatilho'],
            'is_ativo' => (int)$fluxo['is_ativo'],
            'blocos'   => count($fluxo['passos']),
            'avisos'   => count(PluginWhatsappempresaConstrutor::validar($fluxo))
         ];
      }
      wae_responder(['sucesso' => true, 'itens' => $itens]);

   case 'fluxos_painel':
      // Visao geral da aba Fluxos: cada aparelho conectado com os fluxos dele
      wae_exigir_admin();
      $aparelhos = [];
      foreach (PluginWhatsappempresaConexao::listar() as $conexao) {
         $cid = (int)$conexao['id'];
         $anterior = PluginWhatsappempresaConexao::usar($cid);
         $status = PluginWhatsappempresaServidor::status();
         PluginWhatsappempresaConexao::restaurar($anterior);

         $lista = [];
         foreach (PluginWhatsappempresaConstrutor::listar(false, $cid) as $fluxo) {
            $lista[] = [
               'id'       => (int)$fluxo['id'],
               'nome'     => (string)$fluxo['nome'],
               'gatilho'  => (string)$fluxo['gatilho'],
               'palavras' => (string)$fluxo['palavras'],
               'is_ativo' => (int)$fluxo['is_ativo'],
               'blocos'   => count($fluxo['nos'] ?? $fluxo['passos'] ?? []),
               'avisos'   => count(PluginWhatsappempresaConstrutor::validar($fluxo)),
               'date_mod' => Html::convDateTime($fluxo['date_mod'])
            ];
         }
         $aparelhos[] = [
            'id'        => $cid,
            'nome'      => (string)$conexao['nome'],
            'numero'    => (string)($conexao['numero'] ?? ''),
            'padrao'    => (int)$conexao['is_padrao'] === 1,
            'ligado'    => !empty($status['ligado']),
            'conectado' => !empty($status['ligado']) && !empty($status['servico']['conectado']),
            'fluxos'    => $lista
         ];
      }
      wae_responder(['sucesso' => true, 'aparelhos' => $aparelhos, 'gatilhos' => PluginWhatsappempresaConstrutor::gatilhos()]);

   case 'fluxo_ativar':
      // Liga ou desliga o fluxo pela visao dos aparelhos, sem mexer nos blocos
      wae_exigir_admin_escrita();
      $id = (int)($_POST['id'] ?? 0);
      if (PluginWhatsappempresaConstrutor::porId($id) === null) {
         wae_responder(['sucesso' => false, 'mensagem' => 'Fluxo nao encontrado.']);
      }
      $ativo = !empty($_POST['ativo']) ? 1 : 0;
      $DB->update('glpi_plugin_whatsappempresa_fluxos', ['is_ativo' => $ativo], ['id' => $id]);
      wae_responder(['sucesso' => true, 'mensagem' => $ativo ? 'Fluxo ativado: ja responde neste aparelho.' : 'Fluxo desativado.']);

   case 'fluxo_mover':
      wae_exigir_admin_escrita();
      $id = (int)($_POST['id'] ?? 0);
      $destino = (int)($_POST['destino'] ?? 0);
      if (!PluginWhatsappempresaConstrutor::mover($id, $destino)) {
         wae_responder(['sucesso' => false, 'mensagem' => 'Nao foi possivel mover o fluxo.']);
      }
      PluginWhatsappempresaLog::registrar('Fluxo do WhatsApp movido de aparelho', 'Fluxo #' . $id . ' para ' . PluginWhatsappempresaConexao::nome($destino) . ' por ' . wae_usuario(), 'info', 'construtor', (int)Session::getLoginUserID());
      wae_responder(['sucesso' => true, 'mensagem' => 'Fluxo agora atende pelo aparelho ' . PluginWhatsappempresaConexao::nome($destino) . '.']);

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
            'conexoes_id' => (int)$fluxo['conexoes_id'],
            'nos'       => $fluxo['nos'],
            'arestas'   => $fluxo['arestas'],
            'date_mod'  => Html::convDateTime($fluxo['date_mod'])
         ],
         'avisos' => PluginWhatsappempresaConstrutor::validar($fluxo)
      ]);

   case 'fluxo_criar':
      wae_exigir_admin_escrita();
      $nome = trim((string)($_POST['nome'] ?? '')) ?: 'Novo fluxo';
      $id = PluginWhatsappempresaConstrutor::salvar([
         'conexoes_id' => PluginWhatsappempresaConexao::atual(),
         'nome'     => $nome,
         'gatilho'  => 'menu',
         'is_ativo' => 0,
         'nos'      => [],
         'arestas'  => []
      ]);
      if ($id <= 0) {
         wae_responder(['sucesso' => false, 'mensagem' => 'Nao foi possivel criar o fluxo.']);
      }
      PluginWhatsappempresaLog::registrar('Fluxo do WhatsApp criado', 'Fluxo #' . $id . ' "' . $nome . '" por ' . wae_usuario(), 'info', 'construtor', (int)Session::getLoginUserID());
      wae_responder(['sucesso' => true, 'mensagem' => 'Fluxo criado (inativo ate voce ativar).', 'id' => $id]);

   case 'fluxo_salvar':
      // Salvamento automatico do editor: vale na proxima mensagem que o servidor receber
      wae_exigir_admin_escrita();
      $entrada = json_decode((string)($_POST['dados'] ?? ''), true);

      if (!is_array($entrada) || (int)($entrada['id'] ?? 0) <= 0) {
         wae_responder(['sucesso' => false, 'mensagem' => 'Nao foi possivel ler o fluxo enviado.']);
      }
      if (PluginWhatsappempresaConstrutor::porId((int)$entrada['id']) === null) {
         wae_responder(['sucesso' => false, 'mensagem' => 'Este fluxo foi removido.']);
      }
      if (trim((string)($entrada['nome'] ?? '')) === '') {
         wae_responder(['sucesso' => false, 'mensagem' => 'Informe o nome do fluxo.']);
      }

      $id = PluginWhatsappempresaConstrutor::salvar($entrada);
      $gravado = PluginWhatsappempresaConstrutor::porId($id);

      wae_responder([
         'sucesso' => $gravado !== null,
         'id'      => $id,
         'avisos'  => $gravado !== null ? PluginWhatsappempresaConstrutor::validar($gravado) : [],
         'hora'    => date('H:i:s')
      ]);

   case 'fluxo_remover':
      wae_exigir_admin_escrita();
      $id = (int)($_POST['id'] ?? 0);
      PluginWhatsappempresaConstrutor::remover($id);
      PluginWhatsappempresaLog::registrar('Fluxo do WhatsApp removido', 'Fluxo #' . $id . ' por ' . wae_usuario(), 'info', 'construtor', (int)Session::getLoginUserID());
      wae_responder(['sucesso' => true, 'mensagem' => 'Fluxo removido.']);

   case 'fluxo_duplicar':
      wae_exigir_admin_escrita();
      $novo = PluginWhatsappempresaConstrutor::duplicar((int)($_POST['id'] ?? 0), (int)($_POST['destino'] ?? 0));
      if ($novo <= 0) {
         wae_responder(['sucesso' => false, 'mensagem' => 'Nao foi possivel duplicar o fluxo.']);
      }
      $destino = (int)($_POST['destino'] ?? 0);
      wae_responder([
         'sucesso'  => true,
         'mensagem' => $destino > 0 ? 'Fluxo copiado para ' . PluginWhatsappempresaConexao::nome($destino) . ' (inativo).' : 'Copia criada como inativa.',
         'id'       => $novo
      ]);

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

   case 'fluxo_midia':
      // Imagem ou audio anexado a um bloco Conteudo
      wae_exigir_admin_escrita();
      if (empty($_FILES['arquivo'])) {
         wae_responder(['sucesso' => false, 'mensagem' => 'Nenhum arquivo recebido.']);
      }
      $tipo  = (string)($_POST['tipo'] ?? '') === 'audio' ? 'audio' : 'imagem';
      $midia = PluginWhatsappempresaConversa::salvarUpload($_FILES['arquivo'], $tipo);
      if (is_string($midia)) {
         wae_responder(['sucesso' => false, 'mensagem' => $midia]);
      }
      wae_responder([
         'sucesso' => true,
         'midia'   => $midia + ['nome' => mb_substr(basename((string)($_FILES['arquivo']['name'] ?? '')), 0, 80)]
      ]);

   case 'fluxo_midia_ver':
      // Previa da midia de um bloco no editor
      wae_exigir_admin();
      $caminho = PluginWhatsappempresaServidor::caminhoMidia((string)($_GET['arquivo'] ?? ''));
      if ($caminho === null) {
         http_response_code(404);
         header('Content-Type: text/plain; charset=utf-8');
         echo 'Arquivo nao encontrado.';
         exit;
      }
      header('Content-Type: ' . (new finfo(FILEINFO_MIME_TYPE))->file($caminho));
      header('Content-Length: ' . filesize($caminho));
      header('Cache-Control: private, max-age=86400');
      header('X-Content-Type-Options: nosniff');
      readfile($caminho);
      exit;

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

   // ============================================
   // Clientes, codigos e contatos do autoatendimento
   // ============================================

   case 'clientes_listar':
      wae_exigir_admin();
      wae_responder([
         'sucesso'           => true,
         'itens'             => PluginWhatsappempresaCliente::listar(),
         'categorias'        => PluginWhatsappempresaCliente::categorias(),
         'botoes_disponivel' => PluginWhatsappempresaCliente::botoesDisponivel()
      ]);

   case 'cliente_adicionar':
      wae_exigir_admin_escrita();
      $entities_id = (int)($_POST['entities_id'] ?? -1);
      $entidade = new Entity();
      if ($entities_id < 0 || !$entidade->getFromDB($entities_id)) {
         wae_responder(['sucesso' => false, 'mensagem' => 'Escolha a entidade do cliente.']);
      }
      if (PluginWhatsappempresaCliente::porEntidade($entities_id) !== null) {
         wae_responder(['sucesso' => false, 'mensagem' => 'Esta entidade ja esta cadastrada como cliente.']);
      }
      PluginWhatsappempresaCliente::salvar($entities_id, [
         'users_id_requerente' => (int)($_POST['users_id_requerente'] ?? 0),
         'is_ativo'            => 1
      ]);
      // Todo cliente novo ja nasce com um codigo pronto para usar
      PluginWhatsappempresaCliente::salvarCodigo($entities_id, ['codigo' => PluginWhatsappempresaCliente::sugerirCodigo()]);
      PluginWhatsappempresaLog::registrar('Cliente do autoatendimento cadastrado', PluginWhatsappempresaCliente::nomeEntidade($entities_id) . ' por ' . wae_usuario(), 'info', 'autoatendimento', (int)Session::getLoginUserID());
      wae_responder(['sucesso' => true, 'mensagem' => 'Cliente cadastrado com um codigo gerado automaticamente.', 'entities_id' => $entities_id]);

   case 'cliente_salvar':
      wae_exigir_admin_escrita();
      $entities_id = (int)($_POST['entities_id'] ?? -1);
      if (PluginWhatsappempresaCliente::porEntidade($entities_id) === null) {
         wae_responder(['sucesso' => false, 'mensagem' => 'Cliente nao encontrado.']);
      }
      $campos = [];
      foreach (['users_id_requerente', 'is_ativo', 'observacao', 'itilcategories_id', 'usar_botoes', 'unidade_id', 'setor'] as $campo) {
         if (isset($_POST[$campo])) {
            $campos[$campo] = $_POST[$campo];
         }
      }
      wae_responder(['sucesso' => PluginWhatsappempresaCliente::salvar($entities_id, $campos)]);

   case 'cliente_salvar_tudo':
      // Botao Salvar da aba Clientes: cliente, codigos e contatos numa gravacao so
      wae_exigir_admin_escrita();
      $entities_id = (int)($_POST['entities_id'] ?? -1);
      $dados = json_decode((string)($_POST['dados'] ?? ''), true);
      if (!is_array($dados)) {
         wae_responder(['sucesso' => false, 'mensagem' => 'Nao foi possivel ler os dados enviados.', 'erros' => []]);
      }
      $resultado = PluginWhatsappempresaCliente::salvarTudo($entities_id, $dados);
      if ($resultado['ok']) {
         PluginWhatsappempresaLog::registrar('Cliente do autoatendimento alterado', PluginWhatsappempresaCliente::nomeEntidade($entities_id) . ' por ' . wae_usuario(), 'info', 'autoatendimento', (int)Session::getLoginUserID());
      }
      wae_responder([
         'sucesso'  => $resultado['ok'],
         'mensagem' => $resultado['ok'] ? 'Alteracoes salvas.' : 'Corrija os campos destacados antes de salvar.',
         'erros'    => $resultado['erros']
      ]);

   case 'cliente_remover':
      wae_exigir_admin_escrita();
      $entities_id = (int)($_POST['entities_id'] ?? -1);
      PluginWhatsappempresaCliente::remover($entities_id);
      PluginWhatsappempresaLog::registrar('Cliente do autoatendimento removido', PluginWhatsappempresaCliente::nomeEntidade($entities_id) . ' por ' . wae_usuario(), 'info', 'autoatendimento', (int)Session::getLoginUserID());
      wae_responder(['sucesso' => true, 'mensagem' => 'Cliente removido e codigos desativados.']);

   case 'cliente_requerente':
      // Campo nativo de usuario do GLPI (select2) para o requerente padrao do cliente
      wae_exigir_admin();
      $entities_id = (int)($_GET['entities_id'] ?? 0);
      $atual = PluginWhatsappempresaCliente::requerentePadrao($entities_id);
      $html = User::dropdown([
         'name'    => 'users_id_requerente',
         'value'   => $atual,
         'right'   => 'all',
         'entity'  => $entities_id,
         'entity_sons' => true,
         'display' => false,
         'width'   => '100%',
         'rand'    => mt_rand()
      ]);
      wae_responder(['sucesso' => true, 'html' => $html]);

   case 'codigo_salvar':
      wae_exigir_admin_escrita();
      $entities_id = (int)($_POST['entities_id'] ?? -1);
      if (PluginWhatsappempresaCliente::porEntidade($entities_id) === null) {
         wae_responder(['sucesso' => false, 'mensagem' => 'Cliente nao encontrado.']);
      }
      $dadosCodigo = [
         'id'        => (int)($_POST['id'] ?? 0),
         'codigo'    => (string)($_POST['codigo'] ?? ''),
         'descricao' => (string)($_POST['descricao'] ?? ''),
         'is_ativo'  => (string)($_POST['is_ativo'] ?? '1')
      ];
      foreach (['itilcategories_id', 'unidade_id', 'setor'] as $campo) {
         if (isset($_POST[$campo])) {
            $dadosCodigo[$campo] = $_POST[$campo];
         }
      }
      $resultado = PluginWhatsappempresaCliente::salvarCodigo($entities_id, $dadosCodigo);
      if (is_string($resultado)) {
         wae_responder(['sucesso' => false, 'mensagem' => $resultado]);
      }
      wae_responder(['sucesso' => true, 'id' => $resultado]);

   case 'codigo_sugerir':
      wae_exigir_admin();
      wae_responder(['sucesso' => true, 'codigo' => PluginWhatsappempresaCliente::sugerirCodigo()]);

   case 'codigo_remover':
      wae_exigir_admin_escrita();
      PluginWhatsappempresaCliente::removerCodigo((int)($_POST['entities_id'] ?? -1), (int)($_POST['id'] ?? 0));
      wae_responder(['sucesso' => true]);

   case 'contato_salvar':
      wae_exigir_admin_escrita();
      $entities_id = (int)($_POST['entities_id'] ?? -1);
      if (PluginWhatsappempresaCliente::porEntidade($entities_id) === null) {
         wae_responder(['sucesso' => false, 'mensagem' => 'Cliente nao encontrado.']);
      }
      $resultado = PluginWhatsappempresaCliente::salvarContato($entities_id, [
         'id'       => (int)($_POST['id'] ?? 0),
         'nome'     => (string)($_POST['nome'] ?? ''),
         'telefone' => (string)($_POST['telefone'] ?? ''),
         'is_ativo' => (string)($_POST['is_ativo'] ?? '1')
      ]);
      if (is_string($resultado)) {
         wae_responder(['sucesso' => false, 'mensagem' => $resultado]);
      }
      wae_responder(['sucesso' => true, 'id' => $resultado]);

   case 'contato_remover':
      wae_exigir_admin_escrita();
      PluginWhatsappempresaCliente::removerContato((int)($_POST['entities_id'] ?? -1), (int)($_POST['id'] ?? 0));
      wae_responder(['sucesso' => true]);

   default:
      wae_responder(['sucesso' => false, 'mensagem' => 'Acao desconhecida.']);
}

} catch (Throwable $e) {
   PluginWhatsappempresaLog::registrar('Erro no endpoint AJAX', $e->getMessage() . ' em ' . basename($e->getFile()) . ':' . $e->getLine(), 'erro', 'painel', (int)Session::getLoginUserID());
   wae_responder(['sucesso' => false, 'mensagem' => 'Erro interno ao processar a acao.']);
}
