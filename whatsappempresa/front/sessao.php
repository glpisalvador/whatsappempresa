<?php
/**
 * WhatsApp > lista nativa de PluginWhatsappempresaSessao
 */

Session::checkRight('config', READ);

Html::header(PluginWhatsappempresaSessao::getTypeName(2), '', 'whatsapp', 'pluginwhatsappempresasessao');

Search::show('PluginWhatsappempresaSessao');

Html::footer();
