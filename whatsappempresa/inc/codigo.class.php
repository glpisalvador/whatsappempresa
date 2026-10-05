<?php

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access this file directly");
}

use Glpi\Application\View\TemplateRenderer;

/**
 * Codigos de acesso que liberam o autoatendimento para o cliente (cadastro nativo do GLPI)
 */
class PluginWhatsappempresaCodigo extends CommonDBTM {

   // $rightname e $dohistory nao sao redeclaradas: tipadas no GLPI 12 e sem tipo no GLPI 11.
   public function __construct() {
      parent::__construct();
      $this->dohistory = true;
   }

   function defineTabs($options = []) {
      $abas = [];
      $this->addDefaultFormTab($abas);
      $this->addStandardTab('Log', $abas, $options);
      return $abas;
   }

   static function getTypeName($nb = 0): string {
      return $nb > 1 ? 'Codigos de acesso' : 'Codigo de acesso';
   }

   static function getIcon() {
      return 'ti ti-key';
   }

   static function canView(): bool {
      return Session::haveRight('config', READ);
   }

   static function canCreate(): bool {
      return Session::haveRight('config', UPDATE);
   }

   static function canUpdate(): bool {
      return Session::haveRight('config', UPDATE);
   }

   static function canDelete(): bool {
      return Session::haveRight('config', UPDATE);
   }

   static function canPurge(): bool {
      return Session::haveRight('config', UPDATE);
   }

   function rawSearchOptions() {
      $t = self::getTable();
      return [
         ['id' => 'common', 'name' => self::getTypeName(1)],
         ['id' => 1, 'table' => $t, 'field' => 'codigo', 'name' => 'Codigo', 'datatype' => 'itemlink', 'massiveaction' => false],
         ['id' => 2, 'table' => $t, 'field' => 'id', 'name' => 'ID', 'datatype' => 'number', 'massiveaction' => false],
         ['id' => 3, 'table' => $t, 'field' => 'descricao', 'name' => 'Descricao', 'datatype' => 'string'],
         ['id' => 4, 'table' => $t, 'field' => 'is_ativo', 'name' => 'Ativo', 'datatype' => 'bool'],
         ['id' => 19, 'table' => $t, 'field' => 'date_mod', 'name' => 'Ultima atualizacao', 'datatype' => 'datetime', 'massiveaction' => false],
         ['id' => 121, 'table' => $t, 'field' => 'date_creation', 'name' => 'Data de criacao', 'datatype' => 'datetime', 'massiveaction' => false],
         ['id' => 80, 'table' => 'glpi_entities', 'field' => 'completename', 'name' => 'Entidade', 'datatype' => 'dropdown', 'massiveaction' => true]
      ];
   }

   function showForm($ID, array $options = []) {
      $this->initForm($ID, $options);
      TemplateRenderer::getInstance()->display('@whatsappempresa/codigo.html.twig', [
         'item'   => $this,
         'params' => $options
      ]);
      return true;
   }

   /**
    * Codigo obrigatorio e sem repeticao entre os codigos que nao estao na lixeira
    */
   private function validar(array $input, int $id = 0) {
      if (array_key_exists('codigo', $input) || $id === 0) {
         $codigo = trim((string)($input['codigo'] ?? ''));
         if ($codigo === '') {
            Session::addMessageAfterRedirect(htmlescape('Informe o codigo de acesso.'), false, ERROR);
            return false;
         }
         $onde = ['codigo' => $codigo, 'is_deleted' => 0];
         if ($id > 0) {
            $onde['NOT'] = ['id' => $id];
         }
         if (countElementsInTable(self::getTable(), $onde) > 0) {
            Session::addMessageAfterRedirect(htmlescape('Ja existe um codigo de acesso igual a "' . $codigo . '".'), false, ERROR);
            return false;
         }
         $input['codigo'] = $codigo;
      }
      if (array_key_exists('descricao', $input)) {
         $input['descricao'] = trim((string)$input['descricao']);
      }
      return $input;
   }

   function prepareInputForAdd($input) {
      return $this->validar($input);
   }

   function prepareInputForUpdate($input) {
      return $this->validar($input, (int)($input['id'] ?? 0));
   }
}
