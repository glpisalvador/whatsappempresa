<?php

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access this file directly");
}

/**
 * Conexoes de WhatsApp: cada numero pareado por QR Code e um servidor Node independente
 * (porta, pareamento, log e pid proprios) com os seus fluxos.
 *
 * "Conexao atual": definida no inicio de cada requisicao (webhook = numero que recebeu a
 * mensagem; tela = conexao escolhida; chamado = conexao da conversa). Sessoes, fluxos,
 * conversas, mensagens e o envio trabalham sempre com ela. Sem definicao, vale a padrao.
 */
class PluginWhatsappempresaConexao {

   const TABELA        = 'glpi_plugin_whatsappempresa_conexoes';
   const PORTA_INICIAL = 3456;

   private static int $atual = 0;
   private static array $cache = [];
   private static ?int $padraoCache = null;

   // ============================================
   // Conexao atual
   // ============================================

   /** Conexao em uso nesta requisicao (a padrao quando nenhuma foi definida) */
   static function atual(): int {
      return self::$atual > 0 ? self::$atual : self::padrao();
   }

   /** Houve escolha explicita de conexao nesta requisicao? */
   static function definida(): bool {
      return self::$atual > 0;
   }

   /**
    * Passa a trabalhar com a conexao informada. Devolve a anterior (para restaurar depois).
    */
   static function usar(int $id): int {
      $anterior = self::$atual;
      self::$atual = ($id > 0 && self::porId($id) !== null) ? $id : 0;
      return $anterior;
   }

   static function restaurar(int $anterior): void {
      self::$atual = $anterior;
   }

   // ============================================
   // Cadastro
   // ============================================

   static function tabelaExiste(): bool {
      global $DB;
      static $existe = null;
      return $existe ??= $DB->tableExists(self::TABELA);
   }

   static function porId(int $id): ?array {
      global $DB;

      if ($id <= 0 || !self::tabelaExiste()) {
         return null;
      }
      if (array_key_exists($id, self::$cache)) {
         return self::$cache[$id];
      }

      $linha = null;
      foreach ($DB->request(['FROM' => self::TABELA, 'WHERE' => ['id' => $id, 'is_deleted' => 0], 'LIMIT' => 1]) as $registro) {
         $linha = $registro;
      }
      return self::$cache[$id] = $linha;
   }

   static function limparCache(): void {
      self::$cache = [];
      self::$padraoCache = null;
   }

   static function listar(): array {
      global $DB;

      if (!self::tabelaExiste()) {
         return [];
      }

      $itens = [];
      foreach ($DB->request(['FROM' => self::TABELA, 'WHERE' => ['is_deleted' => 0], 'ORDER' => ['is_padrao DESC', 'id ASC']]) as $linha) {
         $itens[] = $linha;
      }
      return $itens;
   }

   static function padrao(): int {
      global $DB;

      if (self::$padraoCache !== null) {
         return self::$padraoCache;
      }
      if (!self::tabelaExiste()) {
         return 1;
      }

      foreach ($DB->request([
         'SELECT' => ['id'],
         'FROM'   => self::TABELA,
         'WHERE'  => ['is_deleted' => 0],
         'ORDER'  => ['is_padrao DESC', 'id ASC'],
         'LIMIT'  => 1
      ]) as $linha) {
         return self::$padraoCache = (int)$linha['id'];
      }
      return 1;
   }

   static function nome(int $id): string {
      $c = self::porId($id);
      return $c !== null ? (string)$c['nome'] : 'Conexão #' . $id;
   }

   /** Nome com o numero, para listas e seletores */
   static function rotulo(int $id): string {
      $c = self::porId($id);
      if ($c === null) {
         return 'Conexão #' . $id;
      }
      $numero = trim((string)($c['numero'] ?? ''));
      return (string)$c['nome'] . ($numero !== '' ? ' (' . $numero . ')' : '');
   }

   static function porta(int $id): int {
      $c = self::porId($id);
      return $c !== null ? (int)$c['porta'] : self::PORTA_INICIAL;
   }

   /** Proxima porta livre a partir de 3456 (nao usada por outra conexao) */
   static function proximaPorta(): int {
      global $DB;

      $usadas = [];
      foreach ($DB->request(['SELECT' => ['porta'], 'FROM' => self::TABELA]) as $linha) {
         $usadas[] = (int)$linha['porta'];
      }
      $porta = self::PORTA_INICIAL;
      while (in_array($porta, $usadas, true)) {
         $porta++;
      }
      return $porta;
   }

   /**
    * Cria uma conexao nova (sem aparelho). Devolve o id ou a mensagem de erro.
    */
   static function criar(string $nome): int|string {
      global $DB;

      $nome = mb_substr(trim($nome), 0, 80);
      if ($nome === '') {
         return 'Informe um nome para a conexão.';
      }
      if (countElementsInTable(self::TABELA, ['nome' => $nome, 'is_deleted' => 0]) > 0) {
         return 'Já existe uma conexão com este nome.';
      }

      $DB->insert(self::TABELA, [
         'nome'      => $nome,
         'porta'     => self::proximaPorta(),
         'is_padrao' => countElementsInTable(self::TABELA, ['is_deleted' => 0]) === 0 ? 1 : 0
      ]);
      $id = (int)$DB->insertId();
      self::limparCache();

      return $id > 0 ? $id : 'Não foi possível criar a conexão.';
   }

   static function renomear(int $id, string $nome): ?string {
      global $DB;

      $nome = mb_substr(trim($nome), 0, 80);
      if ($nome === '') {
         return 'Informe o nome.';
      }
      if (countElementsInTable(self::TABELA, ['nome' => $nome, 'is_deleted' => 0, 'NOT' => ['id' => $id]]) > 0) {
         return 'Já existe outra conexão com este nome.';
      }
      $DB->update(self::TABELA, ['nome' => $nome], ['id' => $id]);
      self::limparCache();
      return null;
   }

   static function definirPadrao(int $id): void {
      global $DB;
      if (self::porId($id) === null) {
         return;
      }
      $DB->update(self::TABELA, ['is_padrao' => 0], ['NOT' => ['id' => $id]]);
      $DB->update(self::TABELA, ['is_padrao' => 1], ['id' => $id]);
      self::limparCache();
   }

   static function gravarNumero(int $id, string $numero, string $nomeAparelho = ''): void {
      global $DB;
      $c = self::porId($id);
      if ($c === null) {
         return;
      }
      $dados = [];
      if ((string)$c['numero'] !== $numero) {
         $dados['numero'] = $numero !== '' ? $numero : null;
      }
      if ($nomeAparelho !== '' && (string)($c['nome_aparelho'] ?? '') !== $nomeAparelho) {
         $dados['nome_aparelho'] = mb_substr($nomeAparelho, 0, 100);
      }
      if ($dados) {
         $DB->update(self::TABELA, $dados, ['id' => $id]);
         self::limparCache();
      }
   }

   /**
    * Remove a conexao: para o servidor, apaga o pareamento e desativa os fluxos dela.
    * Conversas e mensagens ficam no historico.
    */
   static function remover(int $id): ?string {
      global $DB;

      $c = self::porId($id);
      if ($c === null) {
         return 'Conexão não encontrada.';
      }
      if ((int)$c['is_padrao'] === 1) {
         return 'Esta é a conexão padrão. Defina outra como padrão antes de removê-la.';
      }

      $anterior = self::usar($id);
      PluginWhatsappempresaServidor::parar();
      PluginWhatsappempresaServidor::apagarPastaConexao($id);
      self::restaurar($anterior);

      $DB->update(self::TABELA, ['is_deleted' => 1], ['id' => $id]);
      $DB->update('glpi_plugin_whatsappempresa_fluxos', ['is_ativo' => 0, 'is_deleted' => 1], ['conexoes_id' => $id]);
      self::limparCache();
      PluginWhatsappempresaServidor::gravarVigia();
      return null;
   }

   static function totalFluxos(int $id): int {
      return countElementsInTable('glpi_plugin_whatsappempresa_fluxos', ['conexoes_id' => $id, 'is_deleted' => 0]);
   }

   /**
    * Conexao para falar com um telefone quando nada mais define: a da conversa aberta,
    * senao a da sessao mais recente do numero, senao a padrao
    */
   static function paraTelefone(string $telefone): int {
      global $DB;

      $chave = PluginWhatsappempresaConfig::chaveTelefone($telefone);
      if (strlen($chave) >= 8 && self::tabelaExiste()) {
         foreach ([
            ['glpi_plugin_whatsappempresa_conversas', ['chave' => $chave, 'status' => 'aberta', 'is_deleted' => 0], 'id DESC'],
            ['glpi_plugin_whatsappempresa_sessoes', ['chave' => $chave], ['date_mod DESC', 'id DESC']]
         ] as [$tabela, $where, $ordem]) {
            foreach ($DB->request(['SELECT' => ['conexoes_id'], 'FROM' => $tabela, 'WHERE' => $where, 'ORDER' => $ordem, 'LIMIT' => 1]) as $linha) {
               if (self::porId((int)$linha['conexoes_id']) !== null) {
                  return (int)$linha['conexoes_id'];
               }
            }
         }
      }
      return self::padrao();
   }

   /**
    * Lista para os seletores das telas: id, nome, numero, padrao
    */
   static function opcoes(): array {
      return array_map(fn($c) => [
         'id'     => (int)$c['id'],
         'nome'   => (string)$c['nome'],
         'numero' => (string)($c['numero'] ?? ''),
         'padrao' => (int)$c['is_padrao'] === 1,
         'rotulo' => self::rotulo((int)$c['id'])
      ], self::listar());
   }
}
