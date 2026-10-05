<?php
/**
 * Endpoint sem sessao usado pelo servidor Node para entregar mensagens recebidas
 * Autenticacao pelo token interno, resposta unica no final sem exit.
 * O kernel do GLPI inicializa tudo antes; o caminho e declarado publico e sem sessao em setup.php.
 */

if (!headers_sent()) {
   header('Content-Type: application/json; charset=utf-8');
}

$resposta_json = ['ok' => true, 'respostas' => []];

$origem  = $_SERVER['REMOTE_ADDR'] ?? 'desconhecida';
$corpo   = json_decode((string)file_get_contents('php://input'), true) ?: [];
$token   = $_SERVER['HTTP_X_TOKEN_INTERNO'] ?? ($corpo['token'] ?? '');
$oficial = (string)PluginWhatsappempresaConfig::get('token_interno');

if ($oficial === '' || !hash_equals($oficial, (string)$token)) {
   PluginWhatsappempresaLog::registrar(
      'Chamada recusada no webhook',
      'Token invalido. Origem ' . $origem . '.',
      'erro',
      'webhook'
   );
   http_response_code(403);
   $resposta_json = ['ok' => false, 'erro' => 'Token invalido', 'respostas' => []];

} elseif (!empty($corpo['confirmar']) && is_array($corpo['confirmar'])) {
   global $DB;
   $ajustados = 0;

   foreach ($corpo['confirmar'] as $item) {
      $id = (int)($item['id'] ?? 0);
      if ($id <= 0) {
         continue;
      }

      $DB->update('glpi_plugin_whatsappempresa_mensagens', [
         'status_envio' => !empty($item['ok']) ? 'ok' : 'erro',
         'erro'         => !empty($item['ok']) ? null : mb_substr((string)($item['erro'] ?? 'nao entregue'), 0, 250)
      ], ['id' => $id]);

      $ajustados++;

      if (empty($item['ok'])) {
         PluginWhatsappempresaLog::registrar(
            'Resposta nao entregue no WhatsApp',
            'Mensagem #' . $id . ' - ' . mb_substr((string)($item['erro'] ?? ''), 0, 200),
            'erro',
            'envio'
         );
      }
   }

   $resposta_json = ['ok' => true, 'confirmados' => $ajustados, 'respostas' => []];

} elseif (!empty($corpo['teste'])) {
   $resposta_json = ['ok' => true, 'teste' => true, 'origem' => $origem, 'respostas' => []];

} else {
   $telefone = (string)($corpo['telefone'] ?? '');
   $texto    = (string)($corpo['texto'] ?? '');
   $jid      = (string)($corpo['jid'] ?? '');

   if ($telefone !== '' && $texto !== '') {
      $detalhado = PluginWhatsappempresaConfig::ativo('log_detalhado');

      if ($detalhado) {
         PluginWhatsappempresaLog::registrar(
            'Mensagem recebida no webhook',
            'Numero ' . $telefone . ' - ' . mb_substr($texto, 0, 120),
            'info',
            'webhook'
         );
      }

      try {
         $resultado = PluginWhatsappempresaFluxo::processar($telefone, $texto, $jid);

         $respostas    = $resultado['respostas'] ?? [];
         $conversas_id = (int)($resultado['conversas_id'] ?? 0);
         $tickets_id   = (int)($resultado['tickets_id'] ?? 0);
         $fluxo        = (string)($resultado['fluxo'] ?? 'menu');
         $fluxos_id    = (int)($resultado['fluxos_id'] ?? 0);

         $saida     = [];
         $entregues = 0;

         foreach ($respostas as $resposta) {
            $conteudo = is_array($resposta)
               ? $resposta
               : ['texto' => (string)$resposta];

            if (trim((string)($conteudo['texto'] ?? '')) === '') {
               continue;
            }

            // Entrega direta pelo servidor Node, sem depender da resposta deste webhook
            $envio = PluginWhatsappempresaServidor::enviarTexto($telefone, $conteudo);

            try {
               $mensagens_id = PluginWhatsappempresaMensagem::registrar([
                  'conversas_id' => $conversas_id,
                  'telefone'     => $telefone,
                  'direcao'      => 'saida',
                  'fluxo'        => $conversas_id > 0 ? 'conversa' : $fluxo,
                  'fluxos_id'    => $fluxos_id,
                  'origem_tipo'  => 'automacao',
                  'conteudo'     => $conteudo,
                  'tickets_id'   => $tickets_id,
                  'status_envio' => $envio['ok'] ? 'ok' : 'pendente',
                  'erro'         => $envio['ok'] ? null : mb_substr((string)($envio['erro'] ?? ''), 0, 250)
               ]);
            } catch (Throwable $e) {
               $mensagens_id = 0;
            }

            if ($envio['ok']) {
               $entregues++;
               usleep(350000);
               continue;
            }

            PluginWhatsappempresaLog::registrar(
               'Falha na entrega direta da resposta',
               'Numero ' . $telefone . ' - ' . (string)($envio['erro'] ?? 'sem detalhe'),
               'erro',
               'envio'
            );

            // Caminho antigo como reserva: o Node tenta enviar pela propria resposta
            if ($mensagens_id > 0) {
               $conteudo['id'] = $mensagens_id;
            }

            $saida[] = $conteudo;
         }

         if ($detalhado) {
            PluginWhatsappempresaLog::registrar(
               'Resposta gerada pelo atendimento',
               'Numero ' . $telefone . ' - ' . $entregues . ' entregue(s), ' . count($saida) . ' devolvida(s) ao servidor.',
               'info',
               'webhook'
            );
         }

         $resposta_json = ['ok' => true, 'respostas' => array_values($saida)];
      } catch (Throwable $e) {
         PluginWhatsappempresaLog::registrar(
            'Falha ao processar mensagem recebida',
            $e->getMessage() . ' em ' . basename($e->getFile()) . ':' . $e->getLine(),
            'erro',
            'webhook'
         );

         $resposta_json = [
            'ok'        => false,
            'erro'      => 'Falha interna',
            'respostas' => [['texto' => 'Nao consegui te atender agora. Envie a mensagem novamente em instantes.']]
         ];
      }
   }
}

echo json_encode($resposta_json, JSON_UNESCAPED_UNICODE);
