<?php
/**
 * Endereco antigo da configuracao: mantido para links salvos
 */

Session::checkRight('config', READ);

Html::redirect(PluginWhatsappempresaPainel::getSearchURL());
