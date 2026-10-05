<?php
/**
 * WhatsApp > lista nativa de PluginWhatsappempresaConversa
 */

Session::checkRight('config', READ);

Html::header(PluginWhatsappempresaConversa::getTypeName(2), '', 'whatsapp', 'pluginwhatsappempresaconversa');

Search::show('PluginWhatsappempresaConversa');

Html::footer();
