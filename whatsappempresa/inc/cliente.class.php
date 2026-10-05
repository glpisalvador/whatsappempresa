<?php

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access this file directly");
}

/**
 * Clientes do autoatendimento: cada entidade do GLPI tem seus codigos de acesso,
 * um requerente padrao e uma lista de contatos (nome + telefone) que podem ser atendidos
 * mesmo sem usuario no GLPI.
 */
class PluginWhatsappempresaCliente extends CommonGLPI {

   const TABELA          = 'glpi_plugin_whatsappempresa_clientes';
   const TABELA_CONTATOS = 'glpi_plugin_whatsappempresa_contatos';
   const TABELA_CODIGOS  = 'glpi_plugin_whatsappempresa_codigos';

   static function getTypeName($nb = 0): string {
      return $nb > 1 ? 'Clientes' : 'Cliente';
   }

   static function canView(): bool {
      return Session::haveRight('config', READ);
   }

   static function canUpdate(): bool {
      return Session::haveRight('config', UPDATE);
   }

   // ============================================
   // Clientes
   // ============================================

   static function nomeEntidade(int $entities_id): string {
      return (string)Dropdown::getDropdownName('glpi_entities', $entities_id);
   }

   static function porEntidade(int $entities_id): ?array {
      global $DB;

      foreach ($DB->request([
         'FROM'  => self::TABELA,
         'WHERE' => ['entities_id' => $entities_id],
         'LIMIT' => 1
      ]) as $linha) {
         return $linha;
      }

      return null;
   }

   /**
    * Lista dos clientes com contagens, para a aba Clientes
    */
   static function listar(): array {
      global $DB;

      $itens = [];
      foreach ($DB->request([
         'SELECT'    => [self::TABELA . '.*', 'glpi_entities.completename AS entidade'],
         'FROM'      => self::TABELA,
         'LEFT JOIN' => ['glpi_entities' => ['ON' => [self::TABELA => 'entities_id', 'glpi_entities' => 'id']]],
         'ORDER'     => 'glpi_entities.completename ASC'
      ]) as $linha) {
         $entities_id = (int)$linha['entities_id'];
         $itens[] = [
            'entities_id'          => $entities_id,
            'entidade'             => (string)($linha['entidade'] ?? ('Entidade #' . $entities_id)),
            'users_id_requerente'  => (int)$linha['users_id_requerente'],
            'requerente'           => (int)$linha['users_id_requerente'] > 0 ? PluginWhatsappempresaConfig::nomeUsuario((int)$linha['users_id_requerente']) : '',
            'is_ativo'             => (int)$linha['is_ativo'],
            'observacao'           => (string)($linha['observacao'] ?? ''),
            'codigos'              => self::codigosDe($entities_id),
            'contatos'             => self::contatosDe($entities_id)
         ];
      }

      return $itens;
   }

   /**
    * Cria ou atualiza o cliente da entidade (somente os campos enviados)
    */
   static function salvar(int $entities_id, array $campos): bool {
      global $DB;

      $entidade = new Entity();
      if (!$entidade->getFromDB($entities_id)) {
         return false;
      }

      $dados = [];
      if (array_key_exists('users_id_requerente', $campos)) {
         $dados['users_id_requerente'] = max(0, (int)$campos['users_id_requerente']);
      }
      if (array_key_exists('is_ativo', $campos)) {
         $dados['is_ativo'] = !empty($campos['is_ativo']) ? 1 : 0;
      }
      if (array_key_exists('observacao', $campos)) {
         $dados['observacao'] = mb_substr(trim((string)$campos['observacao']), 0, 255);
      }

      if (self::porEntidade($entities_id) === null) {
         return (bool)$DB->insert(self::TABELA, $dados + ['entities_id' => $entities_id, 'is_ativo' => 1]);
      }

      return empty($dados) || (bool)$DB->update(self::TABELA, $dados, ['entities_id' => $entities_id]);
   }

   /**
    * Remove o cliente, os contatos e desativa os codigos da entidade
    */
   static function remover(int $entities_id): void {
      global $DB;

      $DB->delete(self::TABELA, ['entities_id' => $entities_id]);
      $DB->delete(self::TABELA_CONTATOS, ['entities_id' => $entities_id]);
      $DB->update(self::TABELA_CODIGOS, ['is_deleted' => 1, 'is_ativo' => 0], ['entities_id' => $entities_id]);
   }

   static function requerentePadrao(int $entities_id): int {
      $cliente = self::porEntidade($entities_id);
      return $cliente !== null ? (int)$cliente['users_id_requerente'] : 0;
   }

   static function clienteAtivo(int $entities_id): bool {
      $cliente = self::porEntidade($entities_id);
      // Entidade sem cadastro de cliente continua valendo (codigos criados antes desta aba)
      return $cliente === null || (int)$cliente['is_ativo'] === 1;
   }

   // ============================================
   // Codigos de acesso
   // ============================================

   static function codigosDe(int $entities_id): array {
      global $DB;

      $itens = [];
      foreach ($DB->request([
         'SELECT' => ['id', 'codigo', 'descricao', 'is_ativo'],
         'FROM'   => self::TABELA_CODIGOS,
         'WHERE'  => ['entities_id' => $entities_id, 'is_deleted' => 0],
         'ORDER'  => 'id ASC'
      ]) as $linha) {
         $itens[] = [
            'id'        => (int)$linha['id'],
            'codigo'    => (string)$linha['codigo'],
            'descricao' => (string)($linha['descricao'] ?? ''),
            'is_ativo'  => (int)$linha['is_ativo']
         ];
      }

      return $itens;
   }

   static function codigoEmUso(string $codigo, int $ignorar = 0): bool {
      global $DB;

      foreach ($DB->request([
         'SELECT' => ['id', 'codigo'],
         'FROM'   => self::TABELA_CODIGOS,
         'WHERE'  => ['is_deleted' => 0, 'NOT' => ['id' => $ignorar]]
      ]) as $linha) {
         if (strcasecmp(trim((string)$linha['codigo']), trim($codigo)) === 0) {
            return true;
         }
      }

      return false;
   }

   /**
    * Grava um codigo (novo quando id = 0). Devolve o id ou a mensagem de erro.
    */
   static function salvarCodigo(int $entities_id, array $dados): int|string {
      global $DB;

      $id     = (int)($dados['id'] ?? 0);
      $codigo = trim((string)($dados['codigo'] ?? ''));

      if (mb_strlen($codigo) < 3 || mb_strlen($codigo) > 60) {
         return 'O codigo deve ter entre 3 e 60 caracteres.';
      }
      if (preg_match('/\s/', $codigo)) {
         return 'O codigo nao pode ter espacos.';
      }
      if (self::codigoEmUso($codigo, $id)) {
         return 'O codigo "' . $codigo . '" ja esta em uso por outro cliente.';
      }

      $campos = [
         'codigo'      => $codigo,
         'descricao'   => mb_substr(trim((string)($dados['descricao'] ?? '')), 0, 255),
         'is_ativo'    => !array_key_exists('is_ativo', $dados) || !empty($dados['is_ativo']) ? 1 : 0,
         'entities_id' => $entities_id
      ];

      if ($id > 0) {
         $DB->update(self::TABELA_CODIGOS, $campos, ['id' => $id, 'entities_id' => $entities_id]);
         return $id;
      }

      self::salvar($entities_id, []);
      $DB->insert(self::TABELA_CODIGOS, $campos);
      return (int)$DB->insertId();
   }

   static function removerCodigo(int $entities_id, int $id): void {
      global $DB;
      $DB->update(self::TABELA_CODIGOS, ['is_deleted' => 1, 'is_ativo' => 0], ['id' => $id, 'entities_id' => $entities_id]);
   }

   /**
    * Sugestao de codigo facil de ditar: 6 caracteres sem letras ambiguas
    */
   static function sugerirCodigo(): string {
      $alfabeto = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
      do {
         $codigo = '';
         for ($i = 0; $i < 6; $i++) {
            $codigo .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
         }
      } while (self::codigoEmUso($codigo));

      return $codigo;
   }

   // ============================================
   // Contatos
   // ============================================

   static function contatosDe(int $entities_id): array {
      global $DB;

      $itens = [];
      foreach ($DB->request([
         'SELECT' => ['id', 'nome', 'telefone', 'is_ativo'],
         'FROM'   => self::TABELA_CONTATOS,
         'WHERE'  => ['entities_id' => $entities_id],
         'ORDER'  => 'nome ASC'
      ]) as $linha) {
         $itens[] = [
            'id'       => (int)$linha['id'],
            'nome'     => (string)$linha['nome'],
            'telefone' => (string)$linha['telefone'],
            'is_ativo' => (int)$linha['is_ativo']
         ];
      }

      return $itens;
   }

   /**
    * Grava um contato (novo quando id = 0). Devolve o id ou a mensagem de erro.
    */
   static function salvarContato(int $entities_id, array $dados): int|string {
      global $DB;

      $id       = (int)($dados['id'] ?? 0);
      $nome     = mb_substr(trim(preg_replace('/\s+/', ' ', (string)($dados['nome'] ?? ''))), 0, 100);
      $telefone = PluginWhatsappempresaConfig::limparTelefone((string)($dados['telefone'] ?? ''));

      if ($nome === '') {
         return 'Informe o nome do contato.';
      }
      if (strlen($telefone) < 10 || strlen($telefone) > 13) {
         return 'Telefone invalido: use DDD + numero (10 ou 11 digitos).';
      }

      // O mesmo numero nao pode apontar para dois contatos
      $chave = PluginWhatsappempresaConfig::chaveTelefone($telefone);
      foreach ($DB->request([
         'SELECT' => ['id', 'entities_id', 'nome'],
         'FROM'   => self::TABELA_CONTATOS,
         'WHERE'  => ['chave' => $chave, 'NOT' => ['id' => $id]],
         'LIMIT'  => 1
      ]) as $outro) {
         return 'Este telefone ja pertence a ' . $outro['nome'] . ' (' . self::nomeEntidade((int)$outro['entities_id']) . ').';
      }

      $campos = [
         'entities_id' => $entities_id,
         'nome'        => $nome,
         'telefone'    => $telefone,
         'chave'       => $chave,
         'is_ativo'    => !array_key_exists('is_ativo', $dados) || !empty($dados['is_ativo']) ? 1 : 0
      ];

      if ($id > 0) {
         $DB->update(self::TABELA_CONTATOS, $campos, ['id' => $id, 'entities_id' => $entities_id]);
         return $id;
      }

      self::salvar($entities_id, []);
      $DB->insert(self::TABELA_CONTATOS, $campos);
      return (int)$DB->insertId();
   }

   static function removerContato(int $entities_id, int $id): void {
      global $DB;
      $DB->delete(self::TABELA_CONTATOS, ['id' => $id, 'entities_id' => $entities_id]);
   }

   static function contatoPorId(int $id): ?array {
      global $DB;

      foreach ($DB->request(['FROM' => self::TABELA_CONTATOS, 'WHERE' => ['id' => $id], 'LIMIT' => 1]) as $linha) {
         return $linha;
      }

      return null;
   }

   /**
    * Contato ativo dono do telefone, de cliente ativo. Com entidade, procura so nela.
    */
   static function contatoPorTelefone(string $telefone, int $entities_id = 0): ?array {
      global $DB;

      $chave = PluginWhatsappempresaConfig::chaveTelefone($telefone);
      if (strlen($chave) < 8 || !$DB->tableExists(self::TABELA_CONTATOS)) {
         return null;
      }

      $where = ['chave' => $chave, 'is_ativo' => 1];
      if ($entities_id > 0) {
         $where['entities_id'] = $entities_id;
      }

      foreach ($DB->request(['FROM' => self::TABELA_CONTATOS, 'WHERE' => $where, 'LIMIT' => 1]) as $linha) {
         return self::clienteAtivo((int)$linha['entities_id']) ? $linha : null;
      }

      return null;
   }

   // ============================================
   // Identificacao no autoatendimento
   // ============================================

   /**
    * Quem esta falando: usuario do GLPI pelo telefone ou, se nao houver, contato de cliente
    * (atendido com o requerente padrao da entidade).
    *
    * @return array{users_id:int, entities_id:int, contatos_id:int, nome:string, motivo:string}
    */
   static function identificar(string $telefone, int $entidadeDoCodigo = 0): array {
      $resultado = ['users_id' => 0, 'entities_id' => $entidadeDoCodigo, 'contatos_id' => 0, 'nome' => '', 'motivo' => ''];

      $users_id = PluginWhatsappempresaConfig::usuarioPorTelefone($telefone);
      if ($users_id > 0) {
         $resultado['users_id'] = $users_id;
         $resultado['nome']     = PluginWhatsappempresaConfig::nomeUsuario($users_id);
         return $resultado;
      }

      $contato = self::contatoPorTelefone($telefone, $entidadeDoCodigo);
      if ($contato === null) {
         $resultado['motivo'] = 'sem_cadastro';
         return $resultado;
      }

      $requerente = self::requerentePadrao((int)$contato['entities_id']);
      if ($requerente <= 0) {
         $resultado['motivo'] = 'sem_requerente';
         $resultado['entities_id'] = (int)$contato['entities_id'];
         PluginWhatsappempresaLog::registrar(
            'Contato sem requerente padrao',
            'O cliente ' . self::nomeEntidade((int)$contato['entities_id']) . ' nao tem requerente padrao; o contato '
            . $contato['nome'] . ' nao pode ser atendido.',
            'aviso',
            'autoatendimento'
         );
         return $resultado;
      }

      return [
         'users_id'    => $requerente,
         'entities_id' => (int)$contato['entities_id'],
         'contatos_id' => (int)$contato['id'],
         'nome'        => (string)$contato['nome'],
         'motivo'      => ''
      ];
   }

   /**
    * Entidade e contato gravados na sessao do numero no momento da identificacao
    *
    * @return array{entities_id:int, entidade:string, contato:?array}
    */
   static function identidadeDaSessao(string $telefone): array {
      global $DB;

      $vazio = ['entities_id' => 0, 'entidade' => '', 'contato' => null];
      $numero = PluginWhatsappempresaConfig::limparTelefone($telefone);
      if ($numero === '' || !$DB->fieldExists('glpi_plugin_whatsappempresa_sessoes', 'contatos_id')) {
         return $vazio;
      }

      $sessao = PluginWhatsappempresaFluxo::obterSessao($numero);
      $entities_id = (int)($sessao['entities_id'] ?? 0);
      $contatos_id = (int)($sessao['contatos_id'] ?? 0);

      return [
         'entities_id' => $entities_id,
         'entidade'    => $entities_id > 0 ? self::nomeEntidade($entities_id) : '',
         'contato'     => $contatos_id > 0 ? self::contatoPorId($contatos_id) : null
      ];
   }

   /**
    * Linha final dos registros abertos pelo WhatsApp: quem pediu e por qual numero
    */
   static function assinatura(string $telefone, array $identidade): string {
      $linhas = ['Registrado pelo WhatsApp - numero ' . $telefone];

      if (!empty($identidade['contato'])) {
         $linhas[] = 'Contato: ' . $identidade['contato']['nome'] . ' - telefone ' . $identidade['contato']['telefone'];
      }
      if (!empty($identidade['entidade'])) {
         $linhas[] = 'Cliente: ' . $identidade['entidade'];
      }

      return implode("\n", $linhas);
   }
}
