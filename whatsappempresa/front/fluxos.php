<?php
/**
 * WhatsApp > Fluxos: construtor visual de fluxos (aparelhos e seus fluxos)
 */

Session::checkRight('config', READ);

Html::header('Fluxos', '', 'whatsapp', 'fluxos');

$versao = ['version' => PLUGIN_WHATSAPPEMPRESA_VERSION];
// Drawflow (MIT, copia local): area de desenho do construtor de fluxos
echo Html::css('/plugins/whatsappempresa/lib/drawflow/drawflow.min.css', $versao);
echo Html::css('/plugins/whatsappempresa/css/estilo.css', $versao);
echo Html::script('/plugins/whatsappempresa/lib/drawflow/drawflow.min.js', $versao);
echo Html::script('/plugins/whatsappempresa/js/painel.js', $versao);
echo Html::script('/plugins/whatsappempresa/js/construtor.js', $versao);

PluginWhatsappempresaPainel::displayTabContentForItem(new PluginWhatsappempresaPainel(), PluginWhatsappempresaPainel::ABA_FLUXOS);

Html::footer();
