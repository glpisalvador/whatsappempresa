<?php

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access this file directly");
}

class PluginWhatsappempresaMonitor extends CommonDBTM {

   // $rightname não é redeclarada: é tipada (string) no GLPI 12 e sem tipo no GLPI 11,
   // e o PHP exige o mesmo tipo da classe pai. As permissões são sobrescritas abaixo.
   private const RIGHTNAME = 'config';

   public static function canCreate(): bool
   {
       return Session::haveRight(self::RIGHTNAME, CREATE);
   }

   public static function canView(): bool
   {
       return Session::haveRight(self::RIGHTNAME, READ);
   }

   public static function canUpdate(): bool
   {
       return Session::haveRight(self::RIGHTNAME, UPDATE);
   }

   public static function canDelete(): bool
   {
       return Session::haveRight(self::RIGHTNAME, DELETE);
   }

   public static function canPurge(): bool
   {
       return Session::haveRight(self::RIGHTNAME, PURGE);
   }

   static function getTypeName($nb = 0): string {
      return 'Monitor do WhatsApp';
   }

   static function cronInfo($nome): array {
      return ['description' => 'Monitora o servidor WhatsApp, expira sessoes e limpa registros antigos'];
   }

   static function cronMonitorar($tarefa = null): int {
      // Segunda camada alem do vigia do crontab: religa se deveria estar ligado
      if (PluginWhatsappempresaServidor::garantirLigado()) {
         PluginWhatsappempresaLog::registrar(
            'Servidor WhatsApp religado pela tarefa automatica',
            'O processo nao estava em execucao e foi iniciado novamente.',
            'aviso',
            'monitor'
         );
         PluginWhatsappempresaServidor::aguardar(10, true);
      }

      $ativo = PluginWhatsappempresaServidor::ativo();

      if (!$ativo) {
         // So e erro quando o servidor deveria estar ligado
         if (PluginWhatsappempresaServidor::deveEstarLigado()) {
            PluginWhatsappempresaLog::registrar(
               'Servidor WhatsApp inacessivel',
               'A tarefa automatica nao conseguiu falar com o servidor Node.',
               'erro',
               'monitor'
            );
         }
      } else {
         $status = PluginWhatsappempresaServidor::status();
         if (empty($status['servico']['conectado'])) {
            PluginWhatsappempresaLog::registrar(
               'WhatsApp desconectado',
               'O servidor esta ligado mas o aparelho nao esta conectado.',
               'aviso',
               'monitor'
            );
         }
      }

      // Conversa parada segura o numero fora do autoatendimento
      $encerradas = PluginWhatsappempresaConversa::encerrarInativas();
      if ($encerradas > 0) {
         PluginWhatsappempresaLog::registrar(
            'Conversas encerradas por inatividade',
            $encerradas . ' conversa(s) fechada(s) e numero(s) liberado(s).',
            'info',
            'monitor'
         );
      }

      // Sessoes de autoatendimento que passaram do tempo liberado pelo codigo
      $expiradas = PluginWhatsappempresaFluxo::expirarSessoesAutenticadas();
      if ($expiradas > 0) {
         PluginWhatsappempresaLog::registrar(
            'Sessoes de autoatendimento expiradas',
            $expiradas . ' numero(s) precisarao informar o codigo novamente.',
            'info',
            'monitor'
         );
      }

      $dias = (int)PluginWhatsappempresaConfig::get('retencao_dias', '180');
      $removidos = PluginWhatsappempresaMensagem::limpar($dias) + PluginWhatsappempresaLog::limpar($dias);

      if ($tarefa !== null) {
         $tarefa->setVolume($removidos);
      }

      return 1;
   }
}
