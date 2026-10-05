<?php
/**
 * WhatsApp > lista nativa de PluginWhatsappempresaLog
 */

Session::checkRight('config', READ);

Html::header(PluginWhatsappempresaLog::getTypeName(2), '', 'whatsapp', 'pluginwhatsappempresalog');

Search::show('PluginWhatsappempresaLog');

Html::footer();
