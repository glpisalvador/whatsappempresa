<?php

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access this file directly");
}

/**
 * Registros (log) do plugin, com lista nativa em WhatsApp > Registros
 */
class PluginWhatsappempresaLog extends CommonDBTM {

   use PluginWhatsappempresaListaTrait;

   const ROTULOS = [
      'nivel' => ['info' => 'Informativo', 'aviso' => 'Aviso', 'erro' => 'Erro']
   ];

   static function getTypeName($nb = 0): string {
      return $nb > 1 ? 'Registros do WhatsApp' : 'Registro do WhatsApp';
   }

   static function getIcon() {
      return 'ti ti-list-details';
   }

   function rawSearchOptions() {
      return self::comTipoProprio([
         ['id' => 'common', 'name' => self::getTypeName(1)],
         ['id' => 1, 'table' => self::getTable(), 'field' => 'evento', 'name' => 'Evento', 'datatype' => 'string', 'massiveaction' => false],
         ['id' => 2, 'table' => self::getTable(), 'field' => 'id', 'name' => 'ID', 'datatype' => 'number', 'massiveaction' => false],
         ['id' => 3, 'table' => self::getTable(), 'field' => 'date_creation', 'name' => 'Data', 'datatype' => 'datetime', 'massiveaction' => false],
         ['id' => 4, 'table' => self::getTable(), 'field' => 'nivel', 'name' => 'Nivel', 'datatype' => 'specific', 'searchtype' => ['equals', 'notequals'], 'massiveaction' => false],
         ['id' => 5, 'table' => self::getTable(), 'field' => 'origem', 'name' => 'Origem', 'datatype' => 'string', 'massiveaction' => false],
         ['id' => 6, 'table' => self::getTable(), 'field' => 'detalhe', 'name' => 'Detalhe', 'datatype' => 'text', 'massiveaction' => false],
         self::opcaoUsuario(7, 'users_id', 'Usuario')
      ]);
   }

   static function registrar(string $evento, string $detalhe = '', string $nivel = 'info', string $origem = 'plugin', int $users_id = 0): void {
      global $DB;

      if (!$DB->tableExists('glpi_plugin_whatsappempresa_logs')) {
         return;
      }

      $DB->insert('glpi_plugin_whatsappempresa_logs', [
         'nivel'    => $nivel,
         'origem'   => $origem,
         'evento'   => mb_substr($evento, 0, 250),
         'detalhe'  => $detalhe,
         'users_id' => $users_id
      ]);
   }

   static function limpar(int $dias): int {
      global $DB;

      if ($dias <= 0) {
         return 0;
      }

      $limite = date('Y-m-d H:i:s', strtotime('-' . $dias . ' days'));
      $DB->delete('glpi_plugin_whatsappempresa_logs', ['date_creation' => ['<', $limite]]);

      return (int)$DB->affectedRows();
   }
}
