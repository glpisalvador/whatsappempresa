<?php
/**
 * WhatsApp > Configuracao: abas nativas (Servidor, Parametros, Regras, Mensagens, Clientes, Fluxos)
 */

Session::checkRight('config', READ);

Html::header('WhatsApp Empresa', '', 'whatsapp', 'pluginwhatsappempresapainel');

$versao = ['version' => PLUGIN_WHATSAPPEMPRESA_VERSION];
// Drawflow (MIT, copia local): area de desenho do construtor de fluxos
echo Html::css('/plugins/whatsappempresa/public/lib/drawflow/drawflow.min.css', $versao);
echo Html::css('/plugins/whatsappempresa/public/css/estilo.css', $versao);
echo Html::script('/plugins/whatsappempresa/public/lib/drawflow/drawflow.min.js', $versao);
echo Html::script('/plugins/whatsappempresa/public/js/painel.js', $versao);
echo Html::script('/plugins/whatsappempresa/public/js/construtor.js', $versao);
echo Html::script('/plugins/whatsappempresa/public/js/clientes.js', $versao);

$painel = new PluginWhatsappempresaPainel();
$painel->display(['show_nav_header' => false]);

Html::footer();
