<?php
/**
 * WhatsApp > lista nativa de PluginWhatsappempresaCodigo
 */

Session::checkRight('config', READ);

Html::header(PluginWhatsappempresaCodigo::getTypeName(2), '', 'whatsapp', 'pluginwhatsappempresacodigo');

Search::show('PluginWhatsappempresaCodigo');

Html::footer();
