<?php
/**
 * WhatsApp > Configuracao: abas nativas (Servidor, Parametros, Regras, Mensagens, Fluxos)
 */

Session::checkRight('config', READ);

Html::header('WhatsApp Empresa', '', 'whatsapp', 'pluginwhatsappempresapainel');

$versao = ['version' => PLUGIN_WHATSAPPEMPRESA_VERSION];
echo Html::css('/plugins/whatsappempresa/public/css/estilo.css', $versao);
echo Html::script('/plugins/whatsappempresa/public/js/painel.js', $versao);
echo Html::script('/plugins/whatsappempresa/public/js/fluxos.js', $versao);

$painel = new PluginWhatsappempresaPainel();
$painel->display(['show_nav_header' => false]);

Html::footer();
