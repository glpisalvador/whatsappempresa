<?php

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access this file directly");
}

/**
 * Comportamento comum das listas nativas do plugin (mensagens, registros, validacoes...):
 * registros gerados pelo proprio plugin, visiveis para quem ve a configuracao
 * e que so podem ser excluidos em definitivo (sem criacao nem edicao pela tela).
 *
 * $rightname nao e usada: e tipada (string) no GLPI 12 e sem tipo no GLPI 11.
 */
trait PluginWhatsappempresaListaTrait {

   static function canView(): bool {
      return Session::haveRight('config', READ);
   }

   static function canCreate(): bool {
      return false;
   }

   static function canUpdate(): bool {
      return false;
   }

   static function canDelete(): bool {
      return false;
   }

   static function canPurge(): bool {
      return Session::haveRight('config', UPDATE);
   }

   /** Sem pagina de formulario: as listas nao abrem o registro */
   static function getFormURL($full = true) {
      return '';
   }

   /**
    * Marca as colunas da propria tabela com a classe. O GLPI 12 descobre a classe pelo nome da tabela
    * (getItemTypeForTable), o que falha nos plurais em portugues (mensagens -> "Mensagen", sessoes -> "Sessoe",
    * validacoes -> "Validacoe") e quebra a lista com "getItemForItemtype(): null given".
    */
   static function comTipoProprio(array $opcoes): array {
      $tabela = static::getTable();
      foreach ($opcoes as $i => $opcao) {
         if (is_array($opcao) && ($opcao['table'] ?? '') === $tabela && !isset($opcao['itemtype'])) {
            $opcoes[$i]['itemtype'] = static::class;
         }
      }
      return $opcoes;
   }

   /**
    * Opcao de pesquisa de usuario do GLPI ligada a uma coluna da tabela
    */
   static function opcaoUsuario(int $id, string $coluna, string $nome): array {
      return [
         'id'            => $id,
         'table'         => 'glpi_users',
         'field'         => 'name',
         'linkfield'     => $coluna,
         'name'          => $nome,
         'datatype'      => 'dropdown',
         'right'         => 'all',
         'massiveaction' => false
      ];
   }

   /**
    * Opcao de pesquisa com o chamado vinculado (link nativo para o chamado)
    */
   static function opcaoChamado(int $id, string $coluna = 'tickets_id'): array {
      return [
         'id'            => $id,
         'table'         => 'glpi_tickets',
         'field'         => 'name',
         'linkfield'     => $coluna,
         'name'          => 'Chamado',
         'datatype'      => 'itemlink',
         'massiveaction' => false
      ];
   }

   /**
    * Valores fixos (situacao, direcao...) exibidos com rotulo e filtrados por lista
    */
   static function rotulosDoCampo(string $campo): array {
      return static::ROTULOS[$campo] ?? [];
   }

   static function getSpecificValueToDisplay($field, $values, array $options = []) {
      if (!is_array($values)) {
         $values = [$field => $values];
      }
      $rotulos = static::rotulosDoCampo((string)$field);
      if (!empty($rotulos)) {
         $valor = (string)($values[$field] ?? '');
         return htmlescape($rotulos[$valor] ?? $valor);
      }
      return parent::getSpecificValueToDisplay($field, $values, $options);
   }

   static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = []) {
      if (!is_array($values)) {
         $values = [$field => $values];
      }
      $rotulos = static::rotulosDoCampo((string)$field);
      if (!empty($rotulos)) {
         $options['display'] = false;
         $options['value']   = $values[$field] ?? '';
         return Dropdown::showFromArray($name, $rotulos, $options);
      }
      return parent::getSpecificValueToSelect($field, $name, $values, $options);
   }
}
