<?php

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access this file directly");
}

/**
 * Numeros em atendimento (sessao de cada numero no autoatendimento).
 * Lista nativa com acoes em massa para desbloquear e reiniciar.
 */
class PluginWhatsappempresaSessao extends CommonDBTM {

   use PluginWhatsappempresaListaTrait;

   const ROTULOS = [
      'fluxo' => [
         'menu' => 'Autoatendimento', 'conversa' => 'Conversa com tecnico', 'aprovacao' => 'Aprovacao',
         'construtor' => 'Fluxo montado', 'silenciado' => 'Silenciado'
      ]
   ];

   /** A tabela usa o plural em portugues */
   static function getTable($classname = null): string {
      return 'glpi_plugin_whatsappempresa_sessoes';
   }

   static function getTypeName($nb = 0): string {
      return $nb > 1 ? 'Numeros em atendimento' : 'Numero em atendimento';
   }

   static function getIcon() {
      return 'ti ti-device-mobile-message';
   }

   /** Necessario para as acoes em massa (nao ha formulario de edicao) */
   static function canUpdate(): bool {
      return Session::haveRight('config', UPDATE);
   }

   function rawSearchOptions() {
      $t = self::getTable();
      return [
         ['id' => 'common', 'name' => self::getTypeName(1)],
         ['id' => 1, 'table' => $t, 'field' => 'telefone', 'name' => 'Numero', 'datatype' => 'string', 'massiveaction' => false],
         ['id' => 2, 'table' => $t, 'field' => 'id', 'name' => 'ID', 'datatype' => 'number', 'massiveaction' => false],
         ['id' => 3, 'table' => $t, 'field' => 'fluxo', 'name' => 'Fluxo atual', 'datatype' => 'specific', 'searchtype' => ['equals', 'notequals'], 'massiveaction' => false],
         ['id' => 4, 'table' => $t, 'field' => 'etapa', 'name' => 'Etapa', 'datatype' => 'string', 'massiveaction' => false],
         ['id' => 5, 'table' => $t, 'field' => 'autenticado', 'name' => 'Codigo informado', 'datatype' => 'bool', 'massiveaction' => false],
         ['id' => 6, 'table' => $t, 'field' => 'tentativas', 'name' => 'Tentativas erradas', 'datatype' => 'number', 'massiveaction' => false],
         ['id' => 7, 'table' => $t, 'field' => 'bloqueado_ate', 'name' => 'Bloqueado ate', 'datatype' => 'datetime', 'massiveaction' => false],
         self::opcaoUsuario(8, 'users_id', 'Usuario'),
         self::opcaoChamado(9),
         ['id' => 19, 'table' => $t, 'field' => 'date_mod', 'name' => 'Ultima atividade', 'datatype' => 'datetime', 'massiveaction' => false]
      ];
   }

   function getSpecificMassiveActions($checkitem = null) {
      $acoes = parent::getSpecificMassiveActions($checkitem);
      if (self::canUpdate()) {
         $prefixo = self::class . MassiveAction::CLASS_ACTION_SEPARATOR;
         $acoes[$prefixo . 'desbloquear'] = "<i class='ti ti-lock-open'></i>" . 'Desbloquear';
         $acoes[$prefixo . 'reiniciar']   = "<i class='ti ti-rotate'></i>" . 'Reiniciar atendimento';
      }
      return $acoes;
   }

   static function showMassiveActionsSubForm(MassiveAction $ma) {
      switch ($ma->getAction()) {
         case 'desbloquear':
            echo '<p class="text-secondary">Zera as tentativas erradas e libera os numeros bloqueados.</p>';
            echo Html::submit(_x('button', 'Post'), ['name' => 'massiveaction']);
            return true;
         case 'reiniciar':
            echo '<p class="text-secondary">O proximo contato de cada numero comeca do inicio do atendimento.</p>';
            echo Html::submit(_x('button', 'Post'), ['name' => 'massiveaction']);
            return true;
      }
      return parent::showMassiveActionsSubForm($ma);
   }

   static function processMassiveActionsForOneItemtype(MassiveAction $ma, CommonDBTM $item, array $ids) {
      global $DB;

      $valores = match ($ma->getAction()) {
         'desbloquear' => ['bloqueado_ate' => null, 'tentativas' => 0],
         'reiniciar'   => [
            'bloqueado_ate' => null, 'tentativas' => 0, 'fluxo' => 'menu', 'etapa' => 'inicio',
            'autenticado' => 0, 'contexto' => '{}', 'conversas_id' => 0, 'tickets_id' => 0
         ],
         default       => null
      };

      if ($valores === null) {
         parent::processMassiveActionsForOneItemtype($ma, $item, $ids);
         return;
      }

      foreach ($ids as $id) {
         if (!$item->can($id, UPDATE)) {
            $ma->itemDone($item->getType(), $id, MassiveAction::ACTION_NORIGHT);
            continue;
         }
         $ok = $DB->update(self::getTable(), $valores, ['id' => (int)$id]);
         $ma->itemDone($item->getType(), $id, $ok ? MassiveAction::ACTION_OK : MassiveAction::ACTION_KO);
      }

      PluginWhatsappempresaLog::registrar(
         'Sessoes alteradas pela lista de numeros',
         count($ids) . ' numero(s) - ' . $ma->getAction() . ' por ' . PluginWhatsappempresaConfig::nomeUsuario((int)Session::getLoginUserID()),
         'info',
         'painel',
         (int)Session::getLoginUserID()
      );
   }
}
