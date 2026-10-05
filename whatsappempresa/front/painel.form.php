<?php
/**
 * Gravacao das abas Parametros, Regras e Mensagens do painel
 */

Session::checkRight('config', UPDATE);

if (isset($_POST['update'])) {
   $aba    = (string)($_POST['aba'] ?? '');
   $campos = PluginWhatsappempresaPainel::camposDaAba($aba);

   if (empty($campos['caixas']) && empty($campos['numeros']) && empty($campos['textos'])) {
      Session::addMessageAfterRedirect(htmlescape('Formulario desconhecido.'), false, ERROR);
      Html::back();
   }

   $porta = isset($_POST['node_porta']) ? (int)$_POST['node_porta'] : null;
   if ($porta !== null && ($porta < 1024 || $porta > 65535)) {
      Session::addMessageAfterRedirect(htmlescape('A porta deve estar entre 1024 e 65535.'), false, ERROR);
      Html::back();
   }

   $antes = PluginWhatsappempresaConfig::todas();

   foreach ($campos['caixas'] as $chave) {
      if (array_key_exists($chave, $_POST)) {
         PluginWhatsappempresaConfig::set($chave, (int)$_POST[$chave] === 1 ? '1' : '0');
      }
   }
   foreach ($campos['numeros'] as $chave) {
      if (array_key_exists($chave, $_POST)) {
         PluginWhatsappempresaConfig::set($chave, (string)max(0, (int)$_POST[$chave]));
      }
   }
   foreach ($campos['textos'] as $chave) {
      if (array_key_exists($chave, $_POST)) {
         PluginWhatsappempresaConfig::set($chave, trim((string)$_POST[$chave]));
      }
   }

   PluginWhatsappempresaLog::registrar(
      'Configuracoes alteradas',
      'Aba ' . $aba . ' por ' . PluginWhatsappempresaConfig::nomeUsuario((int)Session::getLoginUserID()),
      'info',
      'painel',
      (int)Session::getLoginUserID()
   );

   $mensagem = 'Configuracoes salvas.';

   // Porta, webhook e TLS vao para o ambiente do Node: aplica reiniciando o servico, se estiver ligado
   $depois = PluginWhatsappempresaConfig::todas();
   $mudouServidor = false;
   foreach (['node_porta', 'webhook_url', 'webhook_tls_inseguro'] as $chave) {
      if ((string)($antes[$chave] ?? '') !== (string)($depois[$chave] ?? '')) {
         $mudouServidor = true;
      }
   }
   if ($mudouServidor && PluginWhatsappempresaServidor::deveEstarLigado()) {
      PluginWhatsappempresaServidor::parar();
      $erro = PluginWhatsappempresaServidor::iniciar();
      $mensagem .= $erro === null
         ? ' O servidor WhatsApp foi reiniciado para aplicar a conexao.'
         : ' Nao foi possivel reiniciar o servidor: ' . $erro;
   }

   Session::addMessageAfterRedirect(htmlescape($mensagem), false, INFO);
}

Html::back();
