<?php

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access this file directly");
}

use Glpi\Application\View\TemplateRenderer;

/**
 * Pagina de configuracao do plugin com as abas nativas do GLPI
 */
class PluginWhatsappempresaPainel extends CommonGLPI {

   const ABA_SERVIDOR   = 1;
   const ABA_PARAMETROS = 2;
   const ABA_REGRAS     = 3;
   const ABA_MENSAGENS  = 4;
   const ABA_FLUXOS     = 5;

   static function getTypeName($nb = 0): string {
      return 'WhatsApp Empresa';
   }

   static function getIcon() {
      return 'ti ti-brand-whatsapp';
   }

   static function canView(): bool {
      return Session::haveRight('config', READ);
   }

   static function canCreate(): bool {
      return Session::haveRight('config', UPDATE);
   }

   static function getFormURL($full = true) {
      global $CFG_GLPI;
      return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/whatsappempresa/front/painel.form.php';
   }

   static function getSearchURL($full = true) {
      global $CFG_GLPI;
      return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/whatsappempresa/front/painel.php';
   }

   static function getMenuContent() {
      if (!self::canView()) {
         return false;
      }
      return [
         'title' => 'Configuracao',
         'page'  => self::getSearchURL(false),
         'icon'  => self::getIcon()
      ];
   }

   function defineTabs($options = []) {
      $abas = [];
      $this->addStandardTab(self::class, $abas, $options);
      return $abas;
   }

   function getTabNameForItem(CommonGLPI $item, $withtemplate = 0) {
      if (!($item instanceof self)) {
         return '';
      }
      return [
         self::ABA_SERVIDOR   => self::createTabEntry('Servidor', 0, null, 'ti ti-server'),
         self::ABA_PARAMETROS => self::createTabEntry('Parametros', 0, null, 'ti ti-adjustments'),
         self::ABA_REGRAS     => self::createTabEntry('Regras', 0, null, 'ti ti-shield-check'),
         self::ABA_MENSAGENS  => self::createTabEntry('Mensagens', 0, null, 'ti ti-message-2'),
         self::ABA_FLUXOS     => self::createTabEntry('Fluxos', 0, null, 'ti ti-git-merge')
      ];
   }

   static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0) {
      if (!($item instanceof self)) {
         return false;
      }

      $variaveis = [
         'cfg'       => PluginWhatsappempresaConfig::todas(),
         'canedit'   => Session::haveRight('config', UPDATE),
         'form_path' => self::getFormURL()
      ];

      switch ((int)$tabnum) {
         case self::ABA_SERVIDOR:
            self::renderizar('painel_servidor', $variaveis);
            break;

         case self::ABA_PARAMETROS:
            $variaveis['webhook_padrao'] = rtrim((string)($GLOBALS['CFG_GLPI']['url_base'] ?? ''), '/') . '/plugins/whatsappempresa/front/webhook.php';
            self::renderizar('painel_parametros', $variaveis);
            break;

         case self::ABA_REGRAS:
            $variaveis['tipos'] = Ticket::getTypes();
            $variaveis['urgencias'] = [];
            foreach ([5, 4, 3, 2, 1] as $nivel) {
               $variaveis['urgencias'][$nivel] = Ticket::getUrgencyName($nivel);
            }
            self::renderizar('painel_regras', $variaveis);
            break;

         case self::ABA_MENSAGENS:
            $variaveis['grupos'] = self::gruposMensagens();
            $variaveis['palavras'] = self::palavras();
            self::renderizar('painel_mensagens', $variaveis);
            break;

         case self::ABA_FLUXOS:
            self::renderizar('painel_fluxos', $variaveis);
            break;
      }

      return true;
   }

   static function renderizar(string $modelo, array $variaveis): void {
      TemplateRenderer::getInstance()->display('@whatsappempresa/' . $modelo . '.html.twig', $variaveis);
   }

   static function gruposMensagens(): array {
      return [
         [
            'icone'  => 'ti ti-clipboard-list',
            'titulo' => 'Autoatendimento',
            'campos' => [
               'msg_saudacao'        => 'Saudacao e pedido do codigo',
               'msg_codigo_invalido' => 'Codigo invalido',
               'msg_sem_cadastro'    => 'Numero sem usuario no GLPI',
               'msg_rodape'          => 'Rodape das listas e menus',
               'msg_despedida'       => 'Encerramento do atendimento'
            ]
         ],
         [
            'icone'  => 'ti ti-clock',
            'titulo' => 'Sessao e bloqueio',
            'campos' => [
               'msg_sessao_expirada' => 'Sessao expirada por tempo',
               'msg_bloqueado'       => 'Numero bloqueado por tentativas',
               'msg_retomada'        => 'Confirmacao de retorno ao atendimento'
            ]
         ],
         [
            'icone'  => 'ti ti-flag',
            'titulo' => 'Saida do fluxo',
            'campos' => [
               'msg_saida'           => 'Saida do atendimento automatico',
               'msg_saida_conversa'  => 'Saida da conversa com o tecnico',
               'msg_saida_validacao' => 'Saida do pedido de aprovacao',
               'msg_saida_fluxo'     => 'Saida de um fluxo montado (use {fluxo})'
            ]
         ],
         [
            'icone'  => 'ti ti-messages',
            'titulo' => 'Conversa com o tecnico',
            'campos' => [
               'msg_conversa_tecnico' => 'Apresentacao do tecnico (use {tecnico} e {chamado})',
               'msg_fim_conversa'     => 'Aviso de conversa encerrada'
            ]
         ]
      ];
   }

   static function palavras(): array {
      return [
         'palavras_saida'        => ['Encerrar o fluxo atual', 'Separadas por virgula. sair, encerrar, stop, parar e finalizar valem sempre.'],
         'palavras_retomar'      => ['Voltar ao atendimento automatico', 'Reativa o menu para um numero silenciado.'],
         'palavras_fim_conversa' => ['Encerrar a conversa com o tecnico', 'Fecha a conversa sem silenciar o numero.']
      ];
   }

   /**
    * Campos aceitos por aba no formulario, com o tipo de cada um
    */
   static function camposDaAba(string $aba): array {
      $caixas = [];
      $numeros = [];
      $textos = [];

      switch ($aba) {
         case 'parametros':
            $caixas  = ['webhook_tls_inseguro', 'log_detalhado'];
            $numeros = ['node_porta', 'retencao_dias'];
            $textos  = ['webhook_url'];
            break;

         case 'regras':
            $caixas = [
               'fluxo_autoatendimento', 'fluxo_aprovacao', 'fluxo_conversa', 'fluxo_construtor', 'botoes_whatsapp',
               'menu_chamados', 'menu_validacoes', 'menu_abrir', 'menu_falar_tecnico',
               'exige_codigo', 'notificar_tecnico', 'aviso_status', 'saida_silencia',
               'conversa_tecnico_prende', 'conversa_unica_por_numero', 'enviar_apresentacao',
               'followup_encerramento', 'followup_privado', 'log_fluxos'
            ];
            $numeros = [
               'sessao_codigo_minutos', 'tentativas_max', 'bloqueio_minutos', 'sessao_minutos',
               'abertura_tipo', 'abertura_urgencia', 'abertura_categoria', 'conversa_minutos'
            ];
            break;

         case 'mensagens':
            foreach (self::gruposMensagens() as $grupo) {
               $textos = array_merge($textos, array_keys($grupo['campos']));
            }
            $textos = array_merge($textos, array_keys(self::palavras()));
            break;
      }

      return ['caixas' => $caixas, 'numeros' => $numeros, 'textos' => $textos];
   }
}
