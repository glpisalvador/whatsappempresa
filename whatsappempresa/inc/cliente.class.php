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
            'itilcategories_id'    => (int)($linha['itilcategories_id'] ?? 0),
            'usar_botoes'          => (int)($linha['usar_botoes'] ?? 0),
            'unidade_id'           => (int)($linha['unidade_id'] ?? 0),
            'setor'                => (string)($linha['setor'] ?? ''),
            'botoes'               => self::listasBotoes($entities_id),
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
      if (array_key_exists('itilcategories_id', $campos)) {
         $dados['itilcategories_id'] = self::categoriaValida((int)$campos['itilcategories_id']);
      }
      if (array_key_exists('usar_botoes', $campos)) {
         $dados['usar_botoes'] = !empty($campos['usar_botoes']) ? 1 : 0;
      }
      if (array_key_exists('unidade_id', $campos)) {
         $dados['unidade_id'] = self::unidadeValida($entities_id, (int)$campos['unidade_id']);
      }
      if (array_key_exists('setor', $campos)) {
         $dados['setor'] = self::setorValido($entities_id, (string)$campos['setor']);
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
   // Abertura de chamados: categoria e Unidade/Setor do plugin Botoes
   // ============================================

   const TABELA_BOTOES_DADOS  = 'glpi_plugin_botoes_clientedados';
   const TABELA_BOTOES_CAMPOS = 'glpi_plugin_botoes_itemcampos';

   /**
    * Plugin Botoes ativo e com as tabelas de Unidade/Setor
    */
   static function botoesDisponivel(): bool {
      global $DB;
      return Plugin::isPluginActive('botoes')
         && $DB->tableExists(self::TABELA_BOTOES_DADOS)
         && $DB->tableExists(self::TABELA_BOTOES_CAMPOS);
   }

   /**
    * Unidades e setores cadastrados no plugin Botoes para a entidade (lidos da tabela dele)
    *
    * @return array{disponivel:bool, unidades:array, setores:array}
    */
   static function listasBotoes(int $entities_id): array {
      global $DB;

      $listas = ['disponivel' => self::botoesDisponivel(), 'unidades' => [], 'setores' => []];
      if (!$listas['disponivel']) {
         return $listas;
      }

      foreach ($DB->request([
         'SELECT' => ['id', 'tipo', 'nome'],
         'FROM'   => self::TABELA_BOTOES_DADOS,
         'WHERE'  => ['entities_id' => $entities_id, 'tipo' => ['unidade', 'setor']],
         'ORDER'  => 'nome ASC'
      ]) as $linha) {
         $chave = $linha['tipo'] === 'unidade' ? 'unidades' : 'setores';
         $listas[$chave][] = ['id' => (int)$linha['id'], 'nome' => (string)$linha['nome']];
      }

      return $listas;
   }

   static function categoriaValida(int $id): int {
      return $id > 0 && countElementsInTable('glpi_itilcategories', ['id' => $id]) > 0 ? $id : 0;
   }

   /** Unidade so vale se estiver cadastrada no Botoes para a propria entidade do cliente */
   static function unidadeValida(int $entities_id, int $id): int {
      if ($id <= 0 || !self::botoesDisponivel()) {
         return 0;
      }
      return countElementsInTable(self::TABELA_BOTOES_DADOS, ['id' => $id, 'tipo' => 'unidade', 'entities_id' => $entities_id]) > 0 ? $id : 0;
   }

   /** Setor da lista do Botoes; sem setores cadastrados, texto livre em maiusculas (como o Botoes faz) */
   static function setorValido(int $entities_id, string $setor): string {
      $setor = mb_substr(trim((string)preg_replace('/\s+/', ' ', strip_tags($setor))), 0, 255);
      if ($setor === '') {
         return '';
      }

      $setores = array_column(self::listasBotoes($entities_id)['setores'], 'nome');
      if (empty($setores)) {
         return mb_strtoupper($setor, 'UTF-8');
      }
      return in_array($setor, $setores, true) ? $setor : '';
   }

   static function nomeUnidade(int $id): string {
      global $DB;
      if ($id <= 0 || !$DB->tableExists(self::TABELA_BOTOES_DADOS)) {
         return '';
      }
      foreach ($DB->request(['SELECT' => ['nome'], 'FROM' => self::TABELA_BOTOES_DADOS, 'WHERE' => ['id' => $id], 'LIMIT' => 1]) as $linha) {
         return (string)$linha['nome'];
      }
      return '';
   }

   static function codigoPorId(int $id): ?array {
      global $DB;
      foreach ($DB->request(['FROM' => self::TABELA_CODIGOS, 'WHERE' => ['id' => $id], 'LIMIT' => 1]) as $linha) {
         return $linha;
      }
      return null;
   }

   /**
    * O que o chamado aberto pelo WhatsApp recebe: o codigo usado tem prioridade sobre o cliente
    *
    * @return array{categoria:int, usar_botoes:bool, unidade_id:int, unidade:string, setor:string}
    */
   static function parametrosAbertura(string $telefone): array {
      $parametros = ['categoria' => 0, 'usar_botoes' => false, 'unidade_id' => 0, 'unidade' => '', 'setor' => ''];

      $identidade = self::identidadeDaSessao($telefone);
      $entities_id = (int)$identidade['entities_id'];
      $cliente = $entities_id > 0 ? self::porEntidade($entities_id) : null;
      if ($cliente === null) {
         return $parametros;
      }

      $codigo = !empty($identidade['codigos_id']) ? self::codigoPorId((int)$identidade['codigos_id']) : null;
      if ($codigo !== null && (int)$codigo['entities_id'] !== $entities_id) {
         $codigo = null;
      }

      $parametros['categoria'] = (int)($codigo['itilcategories_id'] ?? 0) ?: (int)$cliente['itilcategories_id'];

      if ((int)$cliente['usar_botoes'] === 1 && self::botoesDisponivel()) {
         $parametros['usar_botoes'] = true;
         $parametros['unidade_id']  = self::unidadeValida($entities_id, (int)($codigo['unidade_id'] ?? 0) ?: (int)$cliente['unidade_id']);
         $parametros['unidade']     = self::nomeUnidade($parametros['unidade_id']);
         $parametros['setor']       = trim((string)($codigo['setor'] ?? '')) !== '' ? (string)$codigo['setor'] : (string)($cliente['setor'] ?? '');
      }

      return $parametros;
   }

   /**
    * Grava Unidade, Setor e Telefone do chamado na tabela do plugin Botoes, no mesmo formato
    * do formulario dele (so os campos ativos na configuracao do Botoes)
    */
   static function gravarCamposBotoes(string $itemtype, int $items_id, array $parametros, string $telefone, array $identidade): void {
      global $DB;

      if ($items_id <= 0 || empty($parametros['usar_botoes']) || !self::botoesDisponivel()) {
         return;
      }

      $ativos = ['unidade' => true, 'setor' => true, 'telefone' => true];
      if (class_exists('PluginBotoesCampos') && method_exists('PluginBotoesCampos', 'getConfig')) {
         foreach (PluginBotoesCampos::getConfig() as $campo => $cfg) {
            $ativos[$campo] = !empty($cfg['ativo']);
         }
      }

      // Telefone do contato ou o do WhatsApp, sem o 55, formatado como o Botoes exibe
      $numero = PluginWhatsappempresaConfig::limparTelefone((string)($identidade['contato']['telefone'] ?? $telefone));
      if (strlen($numero) > 11 && str_starts_with($numero, '55')) {
         $numero = substr($numero, 2);
      }
      $formatado = '';
      if (strlen($numero) === 11) {
         $formatado = '(' . substr($numero, 0, 2) . ') ' . substr($numero, 2, 5) . '-' . substr($numero, 7);
      } elseif (strlen($numero) === 10) {
         $formatado = '(' . substr($numero, 0, 2) . ') ' . substr($numero, 2, 4) . '-' . substr($numero, 6);
      }

      $linha = [
         'unidade_id' => $ativos['unidade'] ? (int)$parametros['unidade_id'] : 0,
         'unidade'    => $ativos['unidade'] ? (string)$parametros['unidade'] : '',
         'setor'      => $ativos['setor'] ? (string)$parametros['setor'] : '',
         'telefone'   => $ativos['telefone'] ? $formatado : ''
      ];

      if ($linha['unidade_id'] === 0 && $linha['setor'] === '' && $linha['telefone'] === '') {
         return;
      }

      if (countElementsInTable(self::TABELA_BOTOES_CAMPOS, ['itemtype' => $itemtype, 'items_id' => $items_id]) > 0) {
         $DB->update(self::TABELA_BOTOES_CAMPOS, $linha, ['itemtype' => $itemtype, 'items_id' => $items_id]);
      } else {
         $DB->insert(self::TABELA_BOTOES_CAMPOS, $linha + ['itemtype' => $itemtype, 'items_id' => $items_id]);
      }
   }

   /**
    * Categorias do GLPI para os seletores da aba Clientes
    */
   static function categorias(): array {
      global $DB;

      $lista = [];
      foreach ($DB->request([
         'SELECT' => ['id', 'completename'],
         'FROM'   => 'glpi_itilcategories',
         'ORDER'  => 'completename ASC',
         'LIMIT'  => 500
      ]) as $linha) {
         $lista[] = ['id' => (int)$linha['id'], 'nome' => (string)$linha['completename']];
      }
      return $lista;
   }

   // ============================================
   // Codigos de acesso
   // ============================================

   static function codigosDe(int $entities_id): array {
      global $DB;

      $itens = [];
      foreach ($DB->request([
         'FROM'   => self::TABELA_CODIGOS,
         'WHERE'  => ['entities_id' => $entities_id, 'is_deleted' => 0],
         'ORDER'  => 'id ASC'
      ]) as $linha) {
         $itens[] = [
            'id'                => (int)$linha['id'],
            'codigo'            => (string)$linha['codigo'],
            'descricao'         => (string)($linha['descricao'] ?? ''),
            'is_ativo'          => (int)$linha['is_ativo'],
            'itilcategories_id' => (int)($linha['itilcategories_id'] ?? 0),
            'unidade_id'        => (int)($linha['unidade_id'] ?? 0),
            'setor'             => (string)($linha['setor'] ?? '')
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

      // Categoria, Unidade e Setor proprios do codigo (vazio = usa os do cliente)
      if (array_key_exists('itilcategories_id', $dados)) {
         $campos['itilcategories_id'] = self::categoriaValida((int)$dados['itilcategories_id']);
      }
      if (array_key_exists('unidade_id', $dados)) {
         $campos['unidade_id'] = self::unidadeValida($entities_id, (int)$dados['unidade_id']);
      }
      if (array_key_exists('setor', $dados)) {
         $campos['setor'] = self::setorValido($entities_id, (string)$dados['setor']);
      }

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

      $vazio = ['entities_id' => 0, 'entidade' => '', 'contato' => null, 'codigos_id' => 0];
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
         'contato'     => $contatos_id > 0 ? self::contatoPorId($contatos_id) : null,
         'codigos_id'  => (int)($sessao['codigos_id'] ?? 0)
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
