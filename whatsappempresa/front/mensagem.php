<?php
/**
 * WhatsApp > lista nativa de PluginWhatsappempresaMensagem
 */

Session::checkRight('config', READ);

Html::header(PluginWhatsappempresaMensagem::getTypeName(2), '', 'whatsapp', 'pluginwhatsappempresamensagem');

Search::show('PluginWhatsappempresaMensagem');

Html::footer();
