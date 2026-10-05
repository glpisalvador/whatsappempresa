<?php
/**
 * WhatsApp > lista nativa de PluginWhatsappempresaValidacao
 */

Session::checkRight('config', READ);

Html::header(PluginWhatsappempresaValidacao::getTypeName(2), '', 'whatsapp', 'pluginwhatsappempresavalidacao');

Search::show('PluginWhatsappempresaValidacao');

Html::footer();
