<?php
/**
 * whatsappempresa - Atendimento, aprovacao e conversas via WhatsApp no GLPI 11 e 12
 */

use Glpi\Plugin\Hooks;

define('PLUGIN_WHATSAPPEMPRESA_VERSION', '3.0.1');
define('PLUGIN_WHATSAPPEMPRESA_MIN_GLPI', '11.0.0');
define('PLUGIN_WHATSAPPEMPRESA_MAX_GLPI', '12.0.99');

/** Paginas com lista nativa no menu WhatsApp */
function plugin_whatsappempresa_tipos_menu(): array {
   return [
      'PluginWhatsappempresaPainel',
      'PluginWhatsappempresaConversa',
      'PluginWhatsappempresaMensagem',
      'PluginWhatsappempresaValidacao',
      'PluginWhatsappempresaSessao',
      'PluginWhatsappempresaCodigo',
      'PluginWhatsappempresaLog'
   ];
}

function plugin_init_whatsappempresa(): void {
   global $PLUGIN_HOOKS;

   $PLUGIN_HOOKS['csrf_compliant']['whatsappempresa'] = true;
   $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['whatsappempresa'] = 'front/painel.php';

   // Endpoint chamado pelo servidor Node (sem sessao GLPI, autenticado pelo token interno)
   $publico = '#^/front/webhook\.php#';
   if (class_exists('\Glpi\Http\Firewall')) {
      \Glpi\Http\Firewall::addPluginStrategyForLegacyScripts('whatsappempresa', $publico, \Glpi\Http\Firewall::STRATEGY_NO_CHECK);
   }
   if (class_exists('\Glpi\Http\SessionManager')) {
      \Glpi\Http\SessionManager::registerPluginStatelessPath('whatsappempresa', $publico);
   }

   $plugin = new Plugin();
   if (!$plugin->isActivated('whatsappempresa')) {
      return;
   }

   Plugin::registerClass('PluginWhatsappempresaConversa', ['addtabon' => ['Ticket']]);

   // Secao "WhatsApp" no menu lateral, com as listas nativas
   $PLUGIN_HOOKS[Hooks::REDEFINE_MENUS]['whatsappempresa'] = 'plugin_whatsappempresa_redefinir_menu';

   if (isset($_SESSION['glpiID'], $_SESSION['glpiactiveprofile']) && Session::haveRight('ticket', READ)) {
      // Botao flutuante de conversas e estilo do chat na aba WhatsApp dos chamados
      $PLUGIN_HOOKS[Hooks::ADD_CSS]['whatsappempresa']        = ['public/css/notificacoes.css', 'public/css/estilo.css'];
      $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['whatsappempresa'] = ['public/js/notificacoes.js'];
   }

   $PLUGIN_HOOKS[Hooks::ITEM_ADD]['whatsappempresa']['TicketValidation'] = 'plugin_whatsappempresa_item_add';
   $PLUGIN_HOOKS[Hooks::ITEM_ADD]['whatsappempresa']['ChangeValidation'] = 'plugin_whatsappempresa_item_add';
   $PLUGIN_HOOKS[Hooks::ITEM_UPDATE]['whatsappempresa']['Ticket']        = 'plugin_whatsappempresa_item_update';
}

/**
 * Acrescenta a secao WhatsApp ao menu, antes de Administracao
 */
function plugin_whatsappempresa_redefinir_menu($menu) {
   if (!Session::haveRight('config', READ)) {
      return $menu;
   }

   $conteudo = [];
   foreach (plugin_whatsappempresa_tipos_menu() as $classe) {
      $dados = $classe::getMenuContent();
      if (is_array($dados) && !empty($dados)) {
         $conteudo[strtolower($classe)] = $dados;
      }
   }
   if (empty($conteudo)) {
      return $menu;
   }

   $secao = [
      'whatsapp' => [
         'title'   => 'WhatsApp',
         'icon'    => 'ti ti-brand-whatsapp',
         'types'   => plugin_whatsappempresa_tipos_menu(),
         'content' => $conteudo,
         'default' => PluginWhatsappempresaPainel::getSearchURL(false)
      ]
   ];

   $novo = [];
   foreach ($menu as $chave => $valor) {
      if ($chave === 'admin') {
         $novo += $secao;
      }
      $novo[$chave] = $valor;
   }
   return $novo + $secao;
}

function plugin_version_whatsappempresa(): array {
   return [
      'name'         => 'WhatsApp Empresa',
      'version'      => PLUGIN_WHATSAPPEMPRESA_VERSION,
      'author'       => 'GLPI Salvador',
      'license'      => 'GPLv2+',
      'homepage'     => '',
      'requirements' => [
         'glpi' => [
            'min' => PLUGIN_WHATSAPPEMPRESA_MIN_GLPI,
            'max' => PLUGIN_WHATSAPPEMPRESA_MAX_GLPI
         ],
         'php'  => ['exts' => ['curl' => ['required' => true]]]
      ]
   ];
}

function plugin_whatsappempresa_check_prerequisites(): bool {
   return true;
}

function plugin_whatsappempresa_check_config($verbose = false): bool {
   return true;
}
