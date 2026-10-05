<?php

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access this file directly");
}

class PluginWhatsappempresaConfig extends CommonDBTM {

   // $rightname nao e redeclarada: e tipada (string) no GLPI 12 e sem tipo no GLPI 11.
   static protected $cache = null;

   /** Chaves que existiram em versoes anteriores e nao sao mais usadas */
   const OBSOLETAS = ['api_url', 'api_app_token', 'api_user_token', 'node_bin'];

   static function canCreate(): bool {
      return Session::haveRight('config', UPDATE);
   }

   static function canDelete(): bool {
      return Session::haveRight('config', UPDATE);
   }

   static function canPurge(): bool {
      return Session::haveRight('config', UPDATE);
   }

   /**
    * GLPI 11 exige token CSRF em POST; no 12 a protecao e por cabecalho e o token foi removido
    */
   static function usaTokenCsrf(): bool {
      return version_compare(GLPI_VERSION, '12.0.0-dev', '<');
   }

   static function tokenCsrf(): string {
      return self::usaTokenCsrf() ? Session::getNewCSRFToken() : '';
   }

   static function campoCsrf(): string {
      return self::usaTokenCsrf()
         ? Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()])
         : '';
   }

   static function getTypeName($nb = 0): string {
      return 'WhatsApp Empresa';
   }

   static function canView(): bool {
      return Session::haveRight('config', READ);
   }

   static function canUpdate(): bool {
      return Session::haveRight('config', UPDATE);
   }

   /**
    * Valores padrao gravados na instalacao
    */
   static function padroes(): array {
      return [
         'node_porta'            => '3456',
         'token_interno'         => '',
         'webhook_url'           => '',
         'webhook_tls_inseguro'  => '0',
         'log_detalhado'         => '1',
         'fluxo_autoatendimento' => '1',
         'fluxo_aprovacao'       => '1',
         'fluxo_conversa'        => '1',
         'fluxo_construtor'      => '1',
         'menu_chamados'         => '1',
         'menu_validacoes'       => '1',
         'menu_abrir'            => '1',
         'menu_falar_tecnico'    => '1',
         'exige_codigo'          => '1',
         'botoes_whatsapp'       => '0',
         'palavras_saida'        => 'sair, encerrar, stop, parar, finalizar',
         'palavras_retomar'      => 'atendimento, voltar, oi',
         'saida_silencia'        => '0',
         'tentativas_max'        => '3',
         'bloqueio_minutos'      => '10',
         'sessao_minutos'        => '15',
         'sessao_codigo_minutos' => '10',
         'abertura_tipo'         => '1',
         'abertura_urgencia'     => '3',
         'abertura_categoria'    => '0',
         'notificar_tecnico'     => '1',
         'conversa_tecnico_prende' => '1',
         'conversa_unica_por_numero' => '1',
         'enviar_apresentacao'   => '1',
         'conversa_minutos'      => '120',
         'followup_encerramento' => '1',
         'followup_privado'      => '0',
         'log_fluxos'            => '1',
         'aviso_status'          => '1',
         'retencao_dias'         => '180',
         'palavras_fim_conversa' => '0, sair, menu, encerrar',
         'msg_saudacao'          => 'Ola! Voce esta no atendimento automatico do suporte. Informe o codigo de acesso da sua empresa para continuar.',
         'msg_codigo_invalido'   => 'Codigo invalido. Tente novamente.',
         'msg_bloqueado'         => 'Numero bloqueado temporariamente por excesso de tentativas. Tente novamente mais tarde.',
         'msg_sem_cadastro'      => 'Seu numero nao esta cadastrado como usuario do sistema. Procure o suporte.',
         'msg_despedida'         => 'Atendimento encerrado. Envie qualquer mensagem para comecar de novo.',
         'msg_rodape'            => 'Escolha uma das opcoes abaixo.',
         'msg_saida'             => 'Voce saiu do atendimento. Envie qualquer mensagem quando quiser voltar.',
         'msg_saida_conversa'    => 'Voce saiu da conversa com o atendente. O atendimento foi finalizado.',
         'msg_saida_validacao'   => 'Voce saiu do pedido de aprovacao. Ele continua pendente e pode ser respondido depois.',
         'msg_saida_fluxo'       => 'Voce saiu do fluxo *{fluxo}*. Nada foi salvo.',
         'msg_sessao_expirada'   => 'Sua sessao de atendimento expirou por tempo. Informe o codigo de acesso novamente para continuar.',
         'msg_retomada'          => 'Atendimento automatico reativado.',
         'msg_conversa_tecnico'  => 'Ola! Aqui e {tecnico}, do suporte, falando sobre o chamado {chamado}. Pode responder por aqui mesmo.',
         'msg_fim_conversa'      => 'Conversa encerrada pelo atendimento. Obrigado pelo contato.'
      ];
   }

   static function instalarPadroes(): void {
      global $DB;
      foreach (self::padroes() as $chave => $valor) {
         $existe = $DB->request([
            'FROM'  => 'glpi_plugin_whatsappempresa_configs',
            'WHERE' => ['chave' => $chave],
            'LIMIT' => 1
         ]);
         if (count($existe) === 0) {
            if ($chave === 'token_interno') {
               $valor = bin2hex(random_bytes(20));
            }
            $DB->insert('glpi_plugin_whatsappempresa_configs', ['chave' => $chave, 'valor' => $valor]);
         }
      }
   }

   static function todas(): array {
      global $DB;

      if (self::$cache !== null) {
         return self::$cache;
      }

      $dados = self::padroes();
      if (!$DB->tableExists('glpi_plugin_whatsappempresa_configs')) {
         return $dados;
      }

      foreach ($DB->request(['FROM' => 'glpi_plugin_whatsappempresa_configs']) as $linha) {
         $dados[$linha['chave']] = $linha['valor'];
      }

      self::$cache = $dados;
      return $dados;
   }

   static function get(string $chave, $padrao = '') {
      $dados = self::todas();
      return $dados[$chave] ?? $padrao;
   }

   static function ativo(string $chave): bool {
      return (string)self::get($chave, '0') === '1';
   }

   static function set(string $chave, $valor): void {
      global $DB;

      $existe = $DB->request([
         'SELECT' => ['id'],
         'FROM'   => 'glpi_plugin_whatsappempresa_configs',
         'WHERE'  => ['chave' => $chave],
         'LIMIT'  => 1
      ]);

      if (count($existe) > 0) {
         $DB->update('glpi_plugin_whatsappempresa_configs', ['valor' => $valor], ['chave' => $chave]);
      } else {
         $DB->insert('glpi_plugin_whatsappempresa_configs', ['chave' => $chave, 'valor' => $valor]);
      }

      self::$cache = null;
   }

   static function urlNode(): string {
      return 'http://127.0.0.1:' . (int)self::get('node_porta', '3456');
   }

   static function pastaServidor(): string {
      return realpath(__DIR__ . '/../servidor') ?: (__DIR__ . '/../servidor');
   }

   /**
    * Deixa o telefone apenas com digitos
    */
   static function limparTelefone(string $telefone): string {
      $numero = preg_replace('/\D/', '', $telefone);
      return $numero ?? '';
   }

   /**
    * Chave de comparacao: ultimos 8 digitos (contorna o nono digito)
    */
   static function chaveTelefone(string $telefone): string {
      $numero = self::limparTelefone($telefone);
      return strlen($numero) >= 8 ? substr($numero, -8) : '';
   }

   /**
    * Localiza o usuario ativo do GLPI dono do numero informado
    */
   static function usuarioPorTelefone(string $telefone): int {
      global $DB;

      $chave = self::chaveTelefone($telefone);
      if (strlen($chave) < 8) {
         return 0;
      }

      $iterator = $DB->request([
         'SELECT' => ['id', 'phone', 'phone2', 'mobile'],
         'FROM'   => 'glpi_users',
         'WHERE'  => [
            'is_active'  => 1,
            'is_deleted' => 0,
            'OR' => [
               ['phone'  => ['LIKE', '%' . $chave]],
               ['phone2' => ['LIKE', '%' . $chave]],
               ['mobile' => ['LIKE', '%' . $chave]]
            ]
         ],
         'LIMIT' => 5
      ]);

      foreach ($iterator as $linha) {
         foreach (['phone', 'phone2', 'mobile'] as $campo) {
            if (self::chaveTelefone((string)$linha[$campo]) === $chave) {
               return (int)$linha['id'];
            }
         }
      }

      return 0;
   }

   /**
    * Telefone cadastrado do usuario (mobile tem prioridade)
    */
   static function telefoneDoUsuario(int $users_id): string {
      global $DB;

      $usuario = $DB->request([
         'SELECT' => ['mobile', 'phone', 'phone2'],
         'FROM'   => 'glpi_users',
         'WHERE'  => ['id' => $users_id],
         'LIMIT'  => 1
      ]);

      foreach ($usuario as $linha) {
         foreach (['mobile', 'phone', 'phone2'] as $campo) {
            $numero = self::limparTelefone((string)$linha[$campo]);
            if (strlen($numero) >= 10) {
               return $numero;
            }
         }
      }

      return '';
   }

   /**
    * Nome e sobrenome do usuario
    */
   static function nomeUsuario(int $users_id): string {
      global $DB;

      if ($users_id <= 0) {
         return 'Sistema';
      }

      $usuario = $DB->request([
         'SELECT' => ['firstname', 'realname', 'name'],
         'FROM'   => 'glpi_users',
         'WHERE'  => ['id' => $users_id],
         'LIMIT'  => 1
      ]);

      foreach ($usuario as $linha) {
         $nome = trim(($linha['firstname'] ?? '') . ' ' . ($linha['realname'] ?? ''));
         return $nome !== '' ? $nome : (string)$linha['name'];
      }

      return 'Usuario ' . $users_id;
   }

   /**
    * Valida o codigo de acesso digitado pelo cliente
    */
   static function validarCodigo(string $codigo): array {
      global $DB;

      $codigo = trim($codigo);
      if ($codigo === '') {
         return [];
      }

      $iterator = $DB->request([
         'SELECT' => ['id', 'codigo', 'descricao', 'entities_id'],
         'FROM'   => 'glpi_plugin_whatsappempresa_codigos',
         'WHERE'  => ['is_ativo' => 1, 'is_deleted' => 0],
         'LIMIT'  => 500
      ]);

      foreach ($iterator as $linha) {
         if (strcasecmp(trim((string)$linha['codigo']), $codigo) === 0) {
            return $linha;
         }
      }

      return [];
   }
}
