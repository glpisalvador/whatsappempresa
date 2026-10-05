<?php

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access this file directly");
}

/**
 * Motor dos fluxos montados no painel: cada fluxo e uma lista ordenada de passos
 */
class PluginWhatsappempresaConstrutor extends CommonDBTM {

   // $rightname não é redeclarada: é tipada (string) no GLPI 12 e sem tipo no GLPI 11,
   // e o PHP exige o mesmo tipo da classe pai. As permissões são sobrescritas abaixo.
   private const RIGHTNAME = 'config';

   public static function canCreate(): bool
   {
       return Session::haveRight(self::RIGHTNAME, CREATE);
   }

   public static function canDelete(): bool
   {
       return Session::haveRight(self::RIGHTNAME, DELETE);
   }

   public static function canPurge(): bool
   {
       return Session::haveRight(self::RIGHTNAME, PURGE);
   }

   const TABELA = 'glpi_plugin_whatsappempresa_fluxos';
   const LIMITE_PASSOS = 30;

   static protected $simulando = false;
   static protected $estadoSimulado = [];
   static protected $registroSimulado = [];

   static function getTable($classname = null): string {
      return self::TABELA;
   }

   static function getTypeName($nb = 0): string {
      return 'Fluxo do WhatsApp';
   }

   static function canView(): bool {
      return Session::haveRight('config', READ);
   }

   static function canUpdate(): bool {
      return Session::haveRight('config', UPDATE);
   }

   // ============================================
   // Catalogo de passos disponiveis no editor
   // ============================================

   static function tiposDePasso(): array {
      return [
         'mensagem' => [
            'rotulo' => 'Enviar mensagem',
            'icone'  => 'ti ti-message',
            'ajuda'  => 'Manda um texto e segue para o passo seguinte.',
            'campos' => ['texto']
         ],
         'pergunta' => [
            'rotulo' => 'Perguntar e guardar',
            'icone'  => 'ti ti-notes',
            'ajuda'  => 'Pede um texto ao cliente e guarda numa variavel.',
            'campos' => ['texto', 'variavel', 'minimo']
         ],
         'sim_nao' => [
            'rotulo' => 'Pergunta de sim ou nao',
            'icone'  => 'ti ti-git-merge',
            'ajuda'  => 'Pergunta com duas opcoes, guarda sim ou nao e pode desviar o fluxo.',
            'campos' => ['texto', 'variavel', 'ir_sim', 'ir_nao']
         ],
         'menu' => [
            'rotulo' => 'Menu de opcoes',
            'icone'  => 'ti ti-list-check',
            'ajuda'  => 'Mostra botoes clicaveis e desvia para o passo de cada opcao.',
            'campos' => ['texto', 'opcoes']
         ],
         'listar_objetos' => [
            'rotulo' => 'Listar chamados/problemas/mudancas',
            'icone'  => 'ti ti-clipboard-list',
            'ajuda'  => 'Lista os itens do usuario e guarda o escolhido.',
            'campos' => ['texto', 'objeto', 'variavel', 'somente_abertos']
         ],
         'detalhe_objeto' => [
            'rotulo' => 'Mostrar detalhe do item',
            'icone'  => 'ti ti-file-text',
            'ajuda'  => 'Mostra titulo, status, prioridade, SLA e tecnico do item escolhido.',
            'campos' => ['objeto', 'variavel']
         ],
         'abrir_objeto' => [
            'rotulo' => 'Abrir chamado/problema/mudanca',
            'icone'  => 'ti ti-ticket',
            'ajuda'  => 'Cria o registro no GLPI com as variaveis coletadas.',
            'campos' => ['objeto', 'variavel_titulo', 'variavel_descricao', 'modelo_descricao', 'tipo', 'urgencia', 'categoria', 'sla', 'grupo', 'texto']
         ],
         'acompanhamento' => [
            'rotulo' => 'Gravar acompanhamento',
            'icone'  => 'ti ti-file-certificate',
            'ajuda'  => 'Grava um acompanhamento no item escolhido.',
            'campos' => ['objeto', 'variavel', 'variavel_texto', 'privado', 'texto', 'texto_ok']
         ],
         'listar_validacoes' => [
            'rotulo' => 'Listar validacoes pendentes',
            'icone'  => 'ti ti-user-check',
            'ajuda'  => 'Lista as aprovacoes pendentes do usuario e guarda a escolhida.',
            'campos' => ['texto', 'variavel']
         ],
         'responder_validacao' => [
            'rotulo' => 'Aprovar ou recusar validacao',
            'icone'  => 'ti ti-circle-check',
            'ajuda'  => 'Pergunta a decisao, pede o comentario e grava no GLPI.',
            'campos' => ['variavel', 'texto']
         ],
         'conversa_tecnico' => [
            'rotulo' => 'Abrir conversa com o tecnico',
            'icone'  => 'ti ti-headset',
            'ajuda'  => 'Passa o numero para a conversa com o tecnico do chamado.',
            'campos' => ['variavel']
         ],
         'condicao' => [
            'rotulo' => 'Condicao',
            'icone'  => 'ti ti-git-merge',
            'ajuda'  => 'Compara uma variavel e escolhe o caminho.',
            'campos' => ['variavel', 'operador', 'valor', 'ir_verdadeiro', 'ir_falso']
         ],
         'ir_para' => [
            'rotulo' => 'Ir para outro passo',
            'icone'  => 'ti ti-exchange',
            'ajuda'  => 'Desvia a execucao para o passo indicado.',
            'campos' => ['ir_verdadeiro']
         ],
         'encerrar' => [
            'rotulo' => 'Finalizar fluxo',
            'icone'  => 'ti ti-flag',
            'ajuda'  => 'Envia a mensagem final e devolve o numero ao atendimento.',
            'campos' => ['texto', 'silenciar']
         ]
      ];
   }

   static function gatilhos(): array {
      return [
         'menu'      => 'Aparece como opcao no menu de atendimento',
         'palavra'   => 'Comeca quando o cliente digita uma palavra',
         'codigo'    => 'Comeca logo depois do codigo de acesso ser aceito',
         'manual'    => 'Somente pelo painel ou por outro fluxo'
      ];
   }

   static function objetos(): array {
      return ['Ticket' => 'Chamado', 'Problem' => 'Problema', 'Change' => 'Mudanca'];
   }

   // ============================================
   // Persistencia dos fluxos
   // ============================================

   static function listar(bool $somenteAtivos = false): array {
      global $DB;

      $where = ['is_deleted' => 0];
      if ($somenteAtivos) {
         $where['is_ativo'] = 1;
      }

      $itens = [];
      foreach ($DB->request([
         'FROM'  => self::TABELA,
         'WHERE' => $where,
         'ORDER' => ['ordem ASC', 'id ASC']
      ]) as $linha) {
         $linha['passos'] = self::passosDe($linha);
         $itens[] = $linha;
      }

      return $itens;
   }

   static function porId(int $id): ?array {
      global $DB;

      if ($id <= 0) {
         return null;
      }

      foreach ($DB->request([
         'FROM'  => self::TABELA,
         'WHERE' => ['id' => $id, 'is_deleted' => 0],
         'LIMIT' => 1
      ]) as $linha) {
         $linha['passos'] = self::passosDe($linha);
         return $linha;
      }

      return null;
   }

   static function passosDe(array $fluxo): array {
      $dados = json_decode((string)($fluxo['definicao'] ?? ''), true);
      return is_array($dados['passos'] ?? null) ? $dados['passos'] : [];
   }

   static function salvar(array $dados): int {
      global $DB;

      $id     = (int)($dados['id'] ?? 0);
      $passos = self::normalizarPassos($dados['passos'] ?? []);

      $campos = [
         'nome'        => mb_substr(trim((string)($dados['nome'] ?? 'Fluxo sem nome')), 0, 120),
         'descricao'   => mb_substr(trim((string)($dados['descricao'] ?? '')), 0, 250),
         'gatilho'     => array_key_exists((string)($dados['gatilho'] ?? ''), self::gatilhos()) ? (string)$dados['gatilho'] : 'menu',
         'palavras'    => mb_substr(trim((string)($dados['palavras'] ?? '')), 0, 250),
         'definicao'   => json_encode(['passos' => $passos], JSON_UNESCAPED_UNICODE),
         'entities_id' => (int)($dados['entities_id'] ?? 0),
         'ordem'       => (int)($dados['ordem'] ?? 0),
         'is_ativo'    => !empty($dados['is_ativo']) ? 1 : 0
      ];

      if ($id > 0) {
         $DB->update(self::TABELA, $campos, ['id' => $id]);
         return $id;
      }

      $campos['users_id'] = (int)Session::getLoginUserID();
      $DB->insert(self::TABELA, $campos);

      return (int)$DB->insertId();
   }

   static function remover(int $id): void {
      global $DB;

      if ($id > 0) {
         $DB->update(self::TABELA, ['is_ativo' => 0, 'is_deleted' => 1], ['id' => $id]);
      }
   }

   static function duplicar(int $id): int {
      $fluxo = self::porId($id);
      if ($fluxo === null) {
         return 0;
      }

      return self::salvar([
         'nome'        => mb_substr('Copia de ' . $fluxo['nome'], 0, 120),
         'descricao'   => (string)$fluxo['descricao'],
         'gatilho'     => 'manual',
         'palavras'    => '',
         'passos'      => $fluxo['passos'],
         'entities_id' => (int)$fluxo['entities_id'],
         'ordem'       => (int)$fluxo['ordem'] + 1,
         'is_ativo'    => 0
      ]);
   }

   static function reordenar(array $ordem): void {
      global $DB;

      foreach (array_values($ordem) as $posicao => $id) {
         $DB->update(self::TABELA, ['ordem' => $posicao], ['id' => (int)$id]);
      }
   }

   /**
    * Garante ids unicos, tipos validos e limite de passos
    */
   static function normalizarPassos($passos): array {
      $tipos  = self::tiposDePasso();
      $limpos = [];
      $usados = [];

      foreach ((array)$passos as $indice => $passo) {
         if (!is_array($passo)) {
            continue;
         }

         $tipo = (string)($passo['tipo'] ?? '');
         if (!isset($tipos[$tipo])) {
            continue;
         }

         $id = preg_replace('/[^a-zA-Z0-9_]/', '', (string)($passo['id'] ?? ''));
         if ($id === '' || in_array($id, $usados, true)) {
            $id = 'p' . ($indice + 1) . substr(md5($tipo . $indice . microtime(true)), 0, 4);
         }
         $usados[] = $id;

         $config = [];
         foreach ((array)($passo['config'] ?? []) as $chave => $valor) {
            $chave = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$chave);
            if ($chave === '') {
               continue;
            }

            if ($chave === 'opcoes') {
               $opcoes = [];
               foreach ((array)$valor as $opcao) {
                  $rotulo = mb_substr(trim((string)($opcao['rotulo'] ?? '')), 0, 24);
                  if ($rotulo === '') {
                     continue;
                  }
                  $opcoes[] = [
                     'rotulo' => $rotulo,
                     'ir'     => preg_replace('/[^a-zA-Z0-9_]/', '', (string)($opcao['ir'] ?? ''))
                  ];
               }
               $config['opcoes'] = array_slice($opcoes, 0, 10);
               continue;
            }

            if (is_array($valor)) {
               continue;
            }

            $config[$chave] = mb_substr((string)$valor, 0, 4000);
         }

         $limpos[] = [
            'id'     => $id,
            'tipo'   => $tipo,
            'rotulo' => mb_substr(trim((string)($passo['rotulo'] ?? $tipos[$tipo]['rotulo'])), 0, 80),
            'config' => $config
         ];

         if (count($limpos) >= self::LIMITE_PASSOS) {
            break;
         }
      }

      // Pergunta sem texto ou sem variavel nao pergunta nada no WhatsApp
      foreach ($limpos as $indice => $passo) {
         if ($passo['tipo'] !== 'pergunta') {
            continue;
         }

         if (trim((string)($passo['config']['texto'] ?? '')) === '') {
            $limpos[$indice]['config']['texto'] = $passo['rotulo'] !== '' ? $passo['rotulo'] : 'Envie sua resposta.';
         }

         if (trim((string)($passo['config']['variavel'] ?? '')) === '') {
            $limpos[$indice]['config']['variavel'] = self::nomeVariavel($passo['rotulo']);
         }
      }

      return $limpos;
   }

   /**
    * Comparacao unica usada pela condicao e pelo passo de condicao
    */
   static function comparar(string $valor, string $operador, string $alvo): bool {
      switch ($operador) {
         case 'diferente':
            return mb_strtolower(trim($valor)) !== mb_strtolower(trim($alvo));
         case 'contem':
            return ($alvo !== '' && mb_stripos($valor, $alvo) !== false);
         case 'vazio':
            return trim($valor) === '';
         case 'preenchido':
            return trim($valor) !== '';
         case 'maior':
            return (float)$valor > (float)$alvo;
      }

      return mb_strtolower(trim($valor)) === mb_strtolower(trim($alvo));
   }

   /**
    * Passo com "visivel se" fora da condicao e ignorado
    */
   static function condicaoAtendida(array $passo, array $contexto): bool {
      $config = (array)($passo['config'] ?? []);
      $bruta  = trim((string)($config['se_variavel'] ?? ''));

      if ($bruta === '') {
         return true;
      }

      return self::comparar(
         (string)($contexto[self::nomeVariavel($bruta)] ?? ''),
         (string)($config['se_operador'] ?? 'igual'),
         (string)($config['se_valor'] ?? '')
      );
   }

   /**
    * Aponta erros que impedem o fluxo de rodar
    */
   static function validar(array $fluxo): array {
      $passos = $fluxo['passos'] ?? [];
      $avisos = [];
      $ids    = array_column($passos, 'id');

      if (empty($passos)) {
         $avisos[] = 'O fluxo nao possui nenhum passo.';
         return $avisos;
      }

      if ((string)$fluxo['gatilho'] === 'palavra' && trim((string)$fluxo['palavras']) === '') {
         $avisos[] = 'O gatilho por palavra exige pelo menos uma palavra cadastrada.';
      }

      $temFim = false;

      $variaveis = [];
      foreach ($passos as $passo) {
         $bruta = trim((string)($passo['config']['variavel'] ?? ''));
         if ($bruta !== '') {
            $variaveis[] = self::nomeVariavel($bruta);
         }
      }

      foreach ($passos as $passo) {
         $tipo   = (string)$passo['tipo'];
         $config = (array)$passo['config'];
         $rotulo = (string)$passo['rotulo'];

         if ($tipo === 'encerrar') {
            $temFim = true;
         }

         if (in_array($tipo, ['mensagem', 'pergunta', 'menu', 'sim_nao'], true) && trim((string)($config['texto'] ?? '')) === '') {
            $avisos[] = 'O passo "' . $rotulo . '" esta sem texto.';
         }

         if (in_array($tipo, ['pergunta', 'sim_nao'], true) && trim((string)($config['variavel'] ?? '')) === '') {
            $avisos[] = 'O passo "' . $rotulo . '" precisa do nome da variavel.';
         }

         $seVariavel = trim((string)($config['se_variavel'] ?? ''));
         if ($seVariavel !== '' && !in_array(self::nomeVariavel($seVariavel), $variaveis, true)) {
            $avisos[] = 'A condicao do passo "' . $rotulo . '" usa uma variavel que nenhum passo coleta.';
         }

         if ($seVariavel === '' && trim((string)($config['se_valor'] ?? '')) !== '') {
            $avisos[] = 'O passo "' . $rotulo . '" tem valor na condicao mas nenhuma pergunta selecionada em "Visivel se".';
         }

         if ($tipo === 'menu') {
            if (empty($config['opcoes'])) {
               $avisos[] = 'O menu "' . $rotulo . '" nao tem opcoes.';
            }
            foreach ((array)($config['opcoes'] ?? []) as $opcao) {
               if ($opcao['ir'] !== '' && !in_array($opcao['ir'], $ids, true)) {
                  $avisos[] = 'A opcao "' . $opcao['rotulo'] . '" aponta para um passo que nao existe.';
               }
            }
         }

         if ($tipo === 'abrir_objeto' && trim((string)($config['variavel_titulo'] ?? '')) === '') {
            $avisos[] = 'O passo "' . $rotulo . '" precisa da variavel com o titulo.';
         }

         if (in_array($tipo, ['detalhe_objeto', 'acompanhamento', 'conversa_tecnico', 'responder_validacao'], true)
             && trim((string)($config['variavel'] ?? '')) === '') {
            $avisos[] = 'O passo "' . $rotulo . '" precisa da variavel com o item escolhido.';
         }

         foreach (['ir_verdadeiro', 'ir_falso', 'ir_sim', 'ir_nao'] as $campo) {
            $destino = (string)($config[$campo] ?? '');
            if ($destino !== '' && !in_array($destino, $ids, true)) {
               $avisos[] = 'O passo "' . $rotulo . '" aponta para um destino inexistente.';
            }
         }
      }

      if (!$temFim) {
         $avisos[] = 'Nenhum passo de finalizacao encontrado: o fluxo vai terminar devolvendo o menu.';
      }

      return $avisos;
   }

   // ============================================
   // Gatilhos
   // ============================================

   static function ativoNoSistema(): bool {
      return PluginWhatsappempresaConfig::ativo('fluxo_construtor');
   }

   static function opcoesDeMenu(): array {
      if (!self::ativoNoSistema()) {
         return [];
      }

      $itens = [];
      foreach (self::listar(true) as $fluxo) {
         if ((string)$fluxo['gatilho'] === 'menu' && !empty($fluxo['passos'])) {
            $itens[] = ['id' => (int)$fluxo['id'], 'nome' => (string)$fluxo['nome']];
         }
      }

      return $itens;
   }

   static function porPalavra(string $normalizado): ?array {
      if (!self::ativoNoSistema() || $normalizado === '') {
         return null;
      }

      foreach (self::listar(true) as $fluxo) {
         if ((string)$fluxo['gatilho'] !== 'palavra') {
            continue;
         }

         foreach (explode(',', (string)$fluxo['palavras']) as $palavra) {
            if (PluginWhatsappempresaFluxo::normalizar($palavra) === $normalizado) {
               return $fluxo;
            }
         }
      }

      return null;
   }

   static function porCodigo(): ?array {
      if (!self::ativoNoSistema()) {
         return null;
      }

      foreach (self::listar(true) as $fluxo) {
         if ((string)$fluxo['gatilho'] === 'codigo' && !empty($fluxo['passos'])) {
            return $fluxo;
         }
      }

      return null;
   }

   // ============================================
   // Execucao
   // ============================================

   static function iniciar(string $telefone, int $fluxos_id, int $users_id, array $contexto = []): array {
      $fluxo = self::porId($fluxos_id);

      if ($fluxo === null || empty($fluxo['passos']) || (int)$fluxo['is_ativo'] !== 1) {
         return ['respostas' => ['Este fluxo nao esta disponivel agora.'], 'encerrado' => true];
      }

      $primeiro = (string)$fluxo['passos'][0]['id'];
      $contexto['fluxo_nome'] = (string)$fluxo['nome'];

      self::gravarEstado($telefone, $fluxos_id, $primeiro, $contexto, $users_id);

      return self::executar($telefone, $fluxo, $primeiro, $contexto, $users_id, false, '');
   }

   /**
    * Mensagem recebida com um fluxo montado em andamento
    */
   static function processar(string $telefone, string $texto, array $sessao): array {
      $fluxos_id = (int)($sessao['fluxos_id'] ?? 0);
      $fluxo     = self::porId($fluxos_id);
      $users_id  = (int)($sessao['users_id'] ?? 0);
      $contexto  = (array)($sessao['dados'] ?? []);
      $passo     = (string)($sessao['passo'] ?? '');

      if ($fluxo === null || empty($fluxo['passos'])) {
         PluginWhatsappempresaFluxo::sairFluxo($telefone, 'Fluxo montado removido');
         return ['respostas' => ['O fluxo em andamento nao existe mais.'], 'encerrado' => true];
      }

      if ($passo === '') {
         $passo = (string)$fluxo['passos'][0]['id'];
      }

      return self::executar($telefone, $fluxo, $passo, $contexto, $users_id, true, $texto);
   }

   /**
    * Percorre os passos ate precisar de uma resposta ou terminar
    */
   static function executar(string $telefone, array $fluxo, string $passoAtual, array $contexto, int $users_id, bool $temEntrada, string $entrada): array {
      $respostas = [];
      $voltas    = 0;
      $mapa      = [];

      foreach ($fluxo['passos'] as $indice => $passo) {
         $mapa[(string)$passo['id']] = $indice;
      }

      $indice = $mapa[$passoAtual] ?? 0;

      while ($voltas < self::LIMITE_PASSOS) {
         $voltas++;

         if (!isset($fluxo['passos'][$indice])) {
            return self::finalizar($telefone, $respostas, $users_id, '', false);
         }

         $passo = $fluxo['passos'][$indice];

         // Condicao nao atendida: segue adiante sem consumir a resposta do cliente
         if (!self::condicaoAtendida($passo, $contexto)) {
            $indice++;
            continue;
         }

         $resultado = self::executarPasso($telefone, $fluxo, $passo, $contexto, $users_id, $temEntrada, $entrada);

         $contexto  = $resultado['contexto'];
         $respostas = array_merge($respostas, $resultado['respostas']);
         $temEntrada = false;
         $entrada    = '';

         if (!empty($resultado['aguardar'])) {
            // Passo que espera resposta nunca pode ficar sem texto
            $temTexto = false;
            foreach ((array)$resultado['respostas'] as $item) {
               if (trim(PluginWhatsappempresaMensagem::textoDaResposta($item)) !== '') {
                  $temTexto = true;
                  break;
               }
            }

            if (!$temTexto) {
               $rotulo = trim((string)($passo['rotulo'] ?? ''));
               $respostas[] = $rotulo !== '' ? $rotulo : 'Envie sua resposta.';
            }

            self::gravarEstado($telefone, (int)$fluxo['id'], (string)$passo['id'], $contexto, $users_id);
            return ['respostas' => $respostas, 'encerrado' => false];
         }

         if (!empty($resultado['fim'])) {
            return self::finalizar($telefone, $respostas, $users_id, (string)($resultado['texto_fim'] ?? ''), !empty($resultado['silenciar']));
         }

         if (!empty($resultado['transferido'])) {
            return [
               'respostas'    => $respostas,
               'encerrado'    => true,
               'transferido'  => true,
               'conversas_id' => (int)($resultado['conversas_id'] ?? 0),
               'tickets_id'   => (int)($resultado['tickets_id'] ?? 0)
            ];
         }

         $proximo = (string)($resultado['ir'] ?? '');
         if ($proximo !== '' && isset($mapa[$proximo])) {
            $indice = $mapa[$proximo];
            continue;
         }

         $indice++;
      }

      return self::finalizar($telefone, $respostas, $users_id, 'O fluxo foi interrompido por seguranca (muitos passos seguidos).', false);
   }

   static function finalizar(string $telefone, array $respostas, int $users_id, string $texto, bool $silenciar): array {
      if ($texto !== '') {
         $respostas[] = $texto;
      }

      if (self::$simulando) {
         return ['respostas' => $respostas, 'encerrado' => true];
      }

      if ($silenciar) {
         PluginWhatsappempresaFluxo::silenciar($telefone);
         return ['respostas' => $respostas, 'encerrado' => true];
      }

      PluginWhatsappempresaFluxo::sairFluxo($telefone, 'Fluxo montado finalizado', $users_id > 0);

      if ($users_id > 0 && PluginWhatsappempresaConfig::ativo('fluxo_autoatendimento')) {
         $respostas[] = PluginWhatsappempresaFluxo::montarMenu($telefone, $users_id);
      }

      return ['respostas' => $respostas, 'encerrado' => true];
   }

   static function gravarEstado(string $telefone, int $fluxos_id, string $passo, array $contexto, int $users_id): void {
      if (self::$simulando) {
         self::$estadoSimulado = [
            'fluxos_id' => $fluxos_id,
            'passo'     => $passo,
            'contexto'  => $contexto,
            'users_id'  => $users_id
         ];
         return;
      }

      PluginWhatsappempresaFluxo::gravarSessao($telefone, [
         'fluxo'     => PluginWhatsappempresaFluxo::FLUXO_CONSTRUTOR,
         'etapa'     => 'construtor',
         'fluxos_id' => $fluxos_id,
         'passo'     => $passo,
         'contexto'  => json_encode($contexto, JSON_UNESCAPED_UNICODE),
         'users_id'  => $users_id
      ]);
   }

   // ============================================
   // Passos
   // ============================================

   static function executarPasso(string $telefone, array $fluxo, array $passo, array $contexto, int $users_id, bool $temEntrada, string $entrada): array {
      $tipo   = (string)$passo['tipo'];
      $config = (array)$passo['config'];
      $vazio  = ['respostas' => [], 'contexto' => $contexto];

      $metodo = 'passo' . str_replace(' ', '', ucwords(str_replace('_', ' ', $tipo)));

      if (!method_exists(self::class, $metodo)) {
         return $vazio;
      }

      return self::$metodo($telefone, $fluxo, $passo, $config, $contexto, $users_id, $temEntrada, $entrada);
   }

   static function passoMensagem(string $telefone, array $fluxo, array $passo, array $config, array $contexto, int $users_id, bool $temEntrada, string $entrada): array {
      return [
         'respostas' => [self::render((string)($config['texto'] ?? ''), $contexto)],
         'contexto'  => $contexto
      ];
   }

   static function passoPergunta(string $telefone, array $fluxo, array $passo, array $config, array $contexto, int $users_id, bool $temEntrada, string $entrada): array {
      $variavel = self::nomeVariavel((string)($config['variavel'] ?? 'resposta'));
      $minimo   = max(1, (int)($config['minimo'] ?? 2));

      if (!$temEntrada) {
         $pergunta = trim((string)($config['texto'] ?? ''));

         if ($pergunta === '') {
            $pergunta = trim((string)($passo['rotulo'] ?? ''));
         }
         if ($pergunta === '') {
            $pergunta = 'Envie sua resposta.';
         }

         return [
            'respostas' => [self::render($pergunta, $contexto)],
            'contexto'  => $contexto,
            'aguardar'  => true
         ];
      }

      $valor = trim($entrada);

      if (mb_strlen($valor) < $minimo) {
         return [
            'respostas' => ['Texto muito curto. Envie ao menos ' . $minimo . ' caractere(s).'],
            'contexto'  => $contexto,
            'aguardar'  => true
         ];
      }

      $contexto[$variavel] = mb_substr($valor, 0, 2000);

      return ['respostas' => [], 'contexto' => $contexto];
   }

   static function passoSimNao(string $telefone, array $fluxo, array $passo, array $config, array $contexto, int $users_id, bool $temEntrada, string $entrada): array {
      $variavel = self::nomeVariavel((string)($config['variavel'] ?? 'resposta'));

      $pergunta = trim((string)($config['texto'] ?? ''));
      if ($pergunta === '') {
         $pergunta = trim((string)($passo['rotulo'] ?? ''));
      }
      if ($pergunta === '') {
         $pergunta = 'Responda sim ou nao.';
      }

      $opcoes = [
         ['id' => '1', 'rotulo' => 'Sim'],
         ['id' => '2', 'rotulo' => 'Nao']
      ];

      if (!$temEntrada) {
         return [
            'respostas' => [PluginWhatsappempresaMensagem::comOpcoes(
               self::render($pergunta, $contexto),
               $opcoes,
               'Responda com 1 ou 2'
            )],
            'contexto' => $contexto,
            'aguardar' => true
         ];
      }

      $limpo = PluginWhatsappempresaFluxo::normalizar($entrada);
      $sim   = in_array($limpo, ['1', 'sim', 's', 'claro', 'positivo', 'isso', 'ok'], true);
      $nao   = in_array($limpo, ['2', 'nao', 'n', 'negativo'], true);

      if (!$sim && !$nao) {
         return [
            'respostas' => [PluginWhatsappempresaMensagem::comOpcoes(
               'Nao entendi. ' . self::render($pergunta, $contexto),
               $opcoes,
               'Responda com 1 ou 2'
            )],
            'contexto' => $contexto,
            'aguardar' => true
         ];
      }

      $contexto[$variavel] = $sim ? 'sim' : 'nao';

      return [
         'respostas' => [],
         'contexto'  => $contexto,
         'ir'        => (string)($sim ? ($config['ir_sim'] ?? '') : ($config['ir_nao'] ?? ''))
      ];
   }

   static function passoMenu(string $telefone, array $fluxo, array $passo, array $config, array $contexto, int $users_id, bool $temEntrada, string $entrada): array {
      $opcoes = array_values((array)($config['opcoes'] ?? []));

      // Menu sem opcoes nao pode desaparecer sem deixar rastro
      if (empty($opcoes)) {
         $texto = trim((string)($config['texto'] ?? ''));

         PluginWhatsappempresaLog::registrar(
            'Passo de menu sem opcoes ignorado',
            'Fluxo ' . (string)($fluxo['nome'] ?? '') . ' - passo "' . (string)($passo['rotulo'] ?? '') . '"',
            'aviso',
            'construtor'
         );

         return [
            'respostas' => $texto !== '' ? [self::render($texto, $contexto)] : [],
            'contexto'  => $contexto
         ];
      }

      if (!$temEntrada) {
         $lista = [];
         foreach ($opcoes as $posicao => $opcao) {
            $lista[] = ['id' => (string)($posicao + 1), 'rotulo' => (string)$opcao['rotulo']];
         }

         return [
            'respostas' => [PluginWhatsappempresaMensagem::comOpcoes(
               self::render((string)($config['texto'] ?? 'Escolha uma opcao:'), $contexto),
               $lista,
               '',
               (string)$fluxo['nome']
            )],
            'contexto' => $contexto,
            'aguardar' => true
         ];
      }

      $escolha = (int)preg_replace('/\D/', '', $entrada);

      if ($escolha < 1 || !isset($opcoes[$escolha - 1])) {
         return [
            'respostas' => ['Opcao invalida. Toque em um dos botoes ou envie o numero da opcao.'],
            'contexto'  => $contexto,
            'aguardar'  => true
         ];
      }

      $opcao = $opcoes[$escolha - 1];
      $contexto['ultima_opcao'] = (string)$opcao['rotulo'];

      return ['respostas' => [], 'contexto' => $contexto, 'ir' => (string)$opcao['ir']];
   }

   static function passoListarObjetos(string $telefone, array $fluxo, array $passo, array $config, array $contexto, int $users_id, bool $temEntrada, string $entrada): array {
      $objeto   = self::classeObjeto((string)($config['objeto'] ?? 'Ticket'));
      $variavel = self::nomeVariavel((string)($config['variavel'] ?? 'item'));
      $chaveMapa = $variavel . '_mapa';

      if (!$temEntrada) {
         $itens = self::itensDoUsuario($objeto, $users_id, !empty($config['somente_abertos']));

         if (empty($itens)) {
            return [
               'respostas' => [],
               'contexto'  => $contexto,
               'fim'       => true,
               'texto_fim' => 'Nao encontrei nenhum registro seu de ' . strtolower(self::objetos()[$objeto]) . '.'
            ];
         }

         $mapa  = [];
         $lista = [];

         foreach ($itens as $posicao => $item) {
            $id = (string)($posicao + 1);
            $mapa[$id] = (int)$item['id'];
            $lista[] = [
               'id'        => $id,
               'rotulo'    => '#' . $item['id'] . ' ' . mb_substr((string)$item['name'], 0, 18),
               'descricao' => $item['situacao']
            ];
         }

         $contexto[$chaveMapa] = $mapa;

         return [
            'respostas' => [PluginWhatsappempresaMensagem::comOpcoes(
               self::render((string)($config['texto'] ?? 'Escolha o registro:'), $contexto),
               $lista,
               '',
               'Meus registros'
            )],
            'contexto' => $contexto,
            'aguardar' => true
         ];
      }

      $mapa    = (array)($contexto[$chaveMapa] ?? []);
      $escolha = trim(preg_replace('/\D/', '', $entrada));

      if ($escolha === '' || !isset($mapa[$escolha])) {
         return [
            'respostas' => ['Opcao invalida. Escolha um dos registros da lista.'],
            'contexto'  => $contexto,
            'aguardar'  => true
         ];
      }

      $contexto[$variavel] = (int)$mapa[$escolha];
      $contexto[$variavel . '_tipo'] = $objeto;

      return ['respostas' => [], 'contexto' => $contexto];
   }

   static function passoDetalheObjeto(string $telefone, array $fluxo, array $passo, array $config, array $contexto, int $users_id, bool $temEntrada, string $entrada): array {
      $variavel = self::nomeVariavel((string)($config['variavel'] ?? 'item'));
      $objeto   = self::classeObjeto((string)($config['objeto'] ?? ($contexto[$variavel . '_tipo'] ?? 'Ticket')));
      $itens_id = (int)($contexto[$variavel] ?? 0);

      if ($itens_id <= 0) {
         return ['respostas' => ['Nenhum registro selecionado.'], 'contexto' => $contexto];
      }

      return ['respostas' => [self::detalhe($objeto, $itens_id)], 'contexto' => $contexto];
   }

   static function passoAbrirObjeto(string $telefone, array $fluxo, array $passo, array $config, array $contexto, int $users_id, bool $temEntrada, string $entrada): array {
      $objeto = self::classeObjeto((string)($config['objeto'] ?? 'Ticket'));
      $titulo = trim((string)($contexto[self::nomeVariavel((string)($config['variavel_titulo'] ?? 'titulo'))] ?? ''));
      $corpo  = trim((string)($contexto[self::nomeVariavel((string)($config['variavel_descricao'] ?? 'descricao'))] ?? ''));

      if ($titulo === '') {
         return ['respostas' => ['Nao consegui montar o registro: o titulo ficou vazio.'], 'contexto' => $contexto];
      }

      if ($corpo === '') {
         $corpo = $titulo;
      }

      // Respostas coletadas nos outros passos entram no corpo do registro
      $usadas = [
         self::nomeVariavel((string)($config['variavel_titulo'] ?? 'titulo')),
         self::nomeVariavel((string)($config['variavel_descricao'] ?? 'descricao'))
      ];

      $extras = [];

      foreach ((array)($fluxo['passos'] ?? []) as $anterior) {
         if ((string)($anterior['tipo'] ?? '') !== 'pergunta') {
            continue;
         }

         $bruta = trim((string)($anterior['config']['variavel'] ?? ''));
         if ($bruta === '') {
            continue;
         }

         $variavel = self::nomeVariavel($bruta);
         if (in_array($variavel, $usadas, true)) {
            continue;
         }

         $valor = trim((string)($contexto[$variavel] ?? ''));
         if ($valor === '') {
            continue;
         }

         $usadas[] = $variavel;
         $rotulo = trim((string)($anterior['rotulo'] ?? ''));
         $extras[] = ($rotulo !== '' ? $rotulo : $variavel) . ': ' . $valor;
      }

      $modelo = trim((string)($config['modelo_descricao'] ?? ''));

      if ($modelo !== '') {
         $montado = trim(self::render($modelo, $contexto));
         if ($montado !== '') {
            $corpo = $montado;
         }
      } elseif (!empty($extras)) {
         $corpo .= "\n\n" . implode("\n", $extras);
      }

      if (self::$simulando) {
         $contexto['objeto_criado'] = 0;
         return [
            'respostas' => ['[simulacao] ' . self::objetos()[$objeto] . ' seria criado com o titulo "' . $titulo . '".'],
            'contexto'  => $contexto
         ];
      }

      $novo = self::criarObjeto($objeto, $titulo, $corpo, $config, $users_id, $telefone);

      if ($novo <= 0) {
         return ['respostas' => ['Nao foi possivel registrar. Procure o suporte.'], 'contexto' => $contexto];
      }

      $contexto['objeto_criado'] = $novo;
      $contexto['numero_criado'] = $novo;

      $texto = trim((string)($config['texto'] ?? ''));
      if ($texto === '') {
         $texto = self::objetos()[$objeto] . ' *#{numero_criado}* registrado com sucesso.';
      }

      PluginWhatsappempresaLog::registrar(
         self::objetos()[$objeto] . ' aberto por fluxo do WhatsApp',
         '#' . $novo . ' - fluxo ' . $fluxo['nome'] . ' - ' . PluginWhatsappempresaConfig::nomeUsuario($users_id),
         'info',
         'construtor',
         $users_id
      );

      return ['respostas' => [self::render($texto, $contexto)], 'contexto' => $contexto];
   }

   static function passoAcompanhamento(string $telefone, array $fluxo, array $passo, array $config, array $contexto, int $users_id, bool $temEntrada, string $entrada): array {
      $variavel = self::nomeVariavel((string)($config['variavel'] ?? 'item'));
      $objeto   = self::classeObjeto((string)($config['objeto'] ?? ($contexto[$variavel . '_tipo'] ?? 'Ticket')));
      $itens_id = (int)($contexto[$variavel] ?? 0);
      $campo    = self::nomeVariavel((string)($config['variavel_texto'] ?? 'acompanhamento'));

      if ($itens_id <= 0) {
         return ['respostas' => ['Nenhum registro selecionado para o acompanhamento.'], 'contexto' => $contexto];
      }

      if (!$temEntrada && trim((string)($contexto[$campo] ?? '')) === '') {
         return [
            'respostas' => [self::render((string)($config['texto'] ?? 'Envie o texto do acompanhamento.'), $contexto)],
            'contexto'  => $contexto,
            'aguardar'  => true
         ];
      }

      $conteudo = $temEntrada ? trim($entrada) : trim((string)$contexto[$campo]);

      if (mb_strlen($conteudo) < 3) {
         return [
            'respostas' => ['Texto muito curto. Detalhe um pouco mais.'],
            'contexto'  => $contexto,
            'aguardar'  => true
         ];
      }

      $contexto[$campo] = $conteudo;

      if (self::$simulando) {
         return [
            'respostas' => ['[simulacao] o acompanhamento seria gravado em ' . strtolower(self::objetos()[$objeto]) . ' #' . $itens_id . '.'],
            'contexto'  => $contexto
         ];
      }

      $ok = self::gravarAcompanhamento($objeto, $itens_id, nl2br($conteudo), $users_id, !empty($config['privado']));

      return [
         'respostas' => [$ok
            ? self::render((string)($config['texto_ok'] ?? 'Acompanhamento registrado em #' . $itens_id . '.'), $contexto)
            : 'Nao foi possivel gravar o acompanhamento.'],
         'contexto' => $contexto
      ];
   }

   static function passoListarValidacoes(string $telefone, array $fluxo, array $passo, array $config, array $contexto, int $users_id, bool $temEntrada, string $entrada): array {
      $variavel  = self::nomeVariavel((string)($config['variavel'] ?? 'validacao'));
      $chaveMapa = $variavel . '_mapa';

      if (!$temEntrada) {
         $pendentes = PluginWhatsappempresaValidacao::pendentesDoUsuario($users_id);

         if (empty($pendentes)) {
            return [
               'respostas' => [],
               'contexto'  => $contexto,
               'fim'       => true,
               'texto_fim' => 'Voce nao possui validacoes pendentes.'
            ];
         }

         $mapa  = [];
         $lista = [];

         foreach ($pendentes as $posicao => $item) {
            $id = (string)($posicao + 1);
            $mapa[$id] = [
               'itemtype'   => $item['itemtype'],
               'items_id'   => (int)$item['items_id'],
               'objetos_id' => (int)$item['objetos_id'],
               'titulo'     => (string)$item['titulo']
            ];
            $lista[] = [
               'id'        => $id,
               'rotulo'    => '#' . $item['objetos_id'] . ' ' . mb_substr((string)$item['titulo'], 0, 16),
               'descricao' => mb_substr((string)$item['titulo'], 0, 70)
            ];
         }

         $contexto[$chaveMapa] = $mapa;

         return [
            'respostas' => [PluginWhatsappempresaMensagem::comOpcoes(
               self::render((string)($config['texto'] ?? 'Escolha o pedido de aprovacao:'), $contexto),
               $lista,
               '',
               'Aprovacoes'
            )],
            'contexto' => $contexto,
            'aguardar' => true
         ];
      }

      $mapa    = (array)($contexto[$chaveMapa] ?? []);
      $escolha = trim(preg_replace('/\D/', '', $entrada));

      if ($escolha === '' || !isset($mapa[$escolha])) {
         return [
            'respostas' => ['Opcao invalida. Escolha um dos pedidos da lista.'],
            'contexto'  => $contexto,
            'aguardar'  => true
         ];
      }

      $contexto[$variavel] = $mapa[$escolha];

      return ['respostas' => [], 'contexto' => $contexto];
   }

   static function passoResponderValidacao(string $telefone, array $fluxo, array $passo, array $config, array $contexto, int $users_id, bool $temEntrada, string $entrada): array {
      $variavel = self::nomeVariavel((string)($config['variavel'] ?? 'validacao'));
      $registro = (array)($contexto[$variavel] ?? []);
      $etapa    = (string)($contexto[$variavel . '_etapa'] ?? '');

      if (empty($registro['itemtype'])) {
         return ['respostas' => ['Nenhum pedido de aprovacao selecionado.'], 'contexto' => $contexto];
      }

      if ($etapa === '') {
         $contexto[$variavel . '_etapa'] = 'decisao';

         if (!$temEntrada) {
            return [
               'respostas' => [PluginWhatsappempresaMensagem::comOpcoes(
                  self::render((string)($config['texto'] ?? "*{$registro['titulo']}*\n\nQual a sua decisao?"), $contexto),
                  [
                     ['id' => '1', 'rotulo' => 'Aprovar'],
                     ['id' => '2', 'rotulo' => 'Recusar']
                  ],
                  'Toque em uma das opcoes'
               )],
               'contexto' => $contexto,
               'aguardar' => true
            ];
         }
      }

      if ($etapa === '' || $etapa === 'decisao') {
         $escolha = PluginWhatsappempresaFluxo::normalizar($entrada);
         $aprovado = null;

         if ($escolha === '1' || str_contains($escolha, 'aprov') || $escolha === 'sim') {
            $aprovado = true;
         } elseif ($escolha === '2' || str_contains($escolha, 'recus') || str_contains($escolha, 'reprov') || $escolha === 'nao') {
            $aprovado = false;
         }

         if ($aprovado === null) {
            return [
               'respostas' => ['Nao entendi. Toque em Aprovar ou Recusar.'],
               'contexto'  => $contexto,
               'aguardar'  => true
            ];
         }

         $contexto[$variavel . '_decisao'] = $aprovado ? '1' : '0';
         $contexto[$variavel . '_etapa']   = 'comentario';

         return [
            'respostas' => ['Envie agora o comentario da ' . ($aprovado ? 'aprovacao' : 'recusa') . '.'],
            'contexto'  => $contexto,
            'aguardar'  => true
         ];
      }

      $aprovado   = ((string)($contexto[$variavel . '_decisao'] ?? '0') === '1');
      $comentario = trim($entrada);

      $contexto[$variavel . '_etapa'] = 'concluido';

      if (self::$simulando) {
         return [
            'respostas' => ['[simulacao] o pedido seria ' . ($aprovado ? 'aprovado' : 'recusado') . ' com o comentario informado.'],
            'contexto'  => $contexto
         ];
      }

      $resultado = PluginWhatsappempresaValidacao::responder(
         (string)$registro['itemtype'],
         (int)$registro['items_id'],
         $aprovado,
         $comentario,
         $users_id
      );

      if (empty($resultado['ok'])) {
         return ['respostas' => ['Nao foi possivel registrar: ' . (string)$resultado['erro']], 'contexto' => $contexto];
      }

      $rotulo = !empty($resultado['mudanca']) ? 'Mudanca' : 'Chamado';

      return [
         'respostas' => ['Validacao ' . ($aprovado ? 'APROVADA' : 'RECUSADA') . ".\n{$rotulo} #{$resultado['objetos_id']}"],
         'contexto'  => $contexto
      ];
   }

   static function passoConversaTecnico(string $telefone, array $fluxo, array $passo, array $config, array $contexto, int $users_id, bool $temEntrada, string $entrada): array {
      $variavel   = self::nomeVariavel((string)($config['variavel'] ?? 'item'));
      $tickets_id = (int)($contexto[$variavel] ?? 0);

      if ($tickets_id <= 0) {
         return ['respostas' => ['Nenhum chamado selecionado para a conversa.'], 'contexto' => $contexto];
      }

      if (self::$simulando) {
         return [
            'respostas' => ['[simulacao] a conversa com o tecnico do chamado #' . $tickets_id . ' seria aberta aqui.'],
            'contexto'  => $contexto
         ];
      }

      $respostas = PluginWhatsappempresaFluxo::iniciarConversa($telefone, $users_id, $tickets_id);
      $sessao    = PluginWhatsappempresaFluxo::obterSessao($telefone);

      if (PluginWhatsappempresaFluxo::fluxoAtual($sessao) !== PluginWhatsappempresaFluxo::FLUXO_CONVERSA) {
         return ['respostas' => $respostas, 'contexto' => $contexto];
      }

      return [
         'respostas'    => $respostas,
         'contexto'     => $contexto,
         'transferido'  => true,
         'conversas_id' => (int)$sessao['conversas_id'],
         'tickets_id'   => $tickets_id
      ];
   }

   static function passoCondicao(string $telefone, array $fluxo, array $passo, array $config, array $contexto, int $users_id, bool $temEntrada, string $entrada): array {
      $variavel = self::nomeVariavel((string)($config['variavel'] ?? ''));
      $valor    = (string)($contexto[$variavel] ?? '');
      $alvo     = (string)($config['valor'] ?? '');
      $operador = (string)($config['operador'] ?? 'igual');

      $verdade = self::comparar($valor, $operador, $alvo);

      return [
         'respostas' => [],
         'contexto'  => $contexto,
         'ir'        => (string)($verdade ? ($config['ir_verdadeiro'] ?? '') : ($config['ir_falso'] ?? ''))
      ];
   }

   static function passoIrPara(string $telefone, array $fluxo, array $passo, array $config, array $contexto, int $users_id, bool $temEntrada, string $entrada): array {
      return ['respostas' => [], 'contexto' => $contexto, 'ir' => (string)($config['ir_verdadeiro'] ?? '')];
   }

   static function passoEncerrar(string $telefone, array $fluxo, array $passo, array $config, array $contexto, int $users_id, bool $temEntrada, string $entrada): array {
      return [
         'respostas'  => [],
         'contexto'   => $contexto,
         'fim'        => true,
         'texto_fim'  => self::render((string)($config['texto'] ?? 'Atendimento finalizado. Obrigado pelo contato.'), $contexto),
         'silenciar'  => !empty($config['silenciar'])
      ];
   }

   // ============================================
   // Apoio ao GLPI
   // ============================================

   static function classeObjeto(string $objeto): string {
      return array_key_exists($objeto, self::objetos()) ? $objeto : 'Ticket';
   }

   static function nomeVariavel(string $nome): string {
      $limpo = preg_replace('/[^a-z0-9_]/', '', mb_strtolower(trim($nome)));
      return $limpo !== '' ? $limpo : 'valor';
   }

   /**
    * Troca {variavel} pelo valor guardado no contexto
    */
   static function render(string $texto, array $contexto): string {
      return preg_replace_callback('/\{([a-z0-9_]+)\}/i', function ($achado) use ($contexto) {
         $chave = mb_strtolower($achado[1]);
         $valor = $contexto[$chave] ?? '';
         return is_scalar($valor) ? (string)$valor : '';
      }, $texto);
   }

   static function tabelaDe(string $objeto): string {
      $tabelas = ['Ticket' => 'glpi_tickets', 'Problem' => 'glpi_problems', 'Change' => 'glpi_changes'];
      return $tabelas[$objeto] ?? 'glpi_tickets';
   }

   static function tabelaAtoresDe(string $objeto): string {
      $tabelas = ['Ticket' => 'glpi_tickets_users', 'Problem' => 'glpi_problems_users', 'Change' => 'glpi_changes_users'];
      return $tabelas[$objeto] ?? 'glpi_tickets_users';
   }

   static function campoLigacaoDe(string $objeto): string {
      $campos = ['Ticket' => 'tickets_id', 'Problem' => 'problems_id', 'Change' => 'changes_id'];
      return $campos[$objeto] ?? 'tickets_id';
   }

   static function itensDoUsuario(string $objeto, int $users_id, bool $somenteAbertos = true): array {
      global $DB;

      if ($users_id <= 0) {
         return [];
      }

      $tabela  = self::tabelaDe($objeto);
      $atores  = self::tabelaAtoresDe($objeto);
      $ligacao = self::campoLigacaoDe($objeto);

      if (!$DB->tableExists($tabela) || !$DB->tableExists($atores)) {
         return [];
      }

      $where = [
         $atores . '.users_id' => $users_id,
         $atores . '.type'     => CommonITILActor::REQUESTER,
         $tabela . '.is_deleted' => 0
      ];

      if ($somenteAbertos) {
         $where['NOT'] = [$tabela . '.status' => [CommonITILObject::CLOSED]];
      }

      $itens = [];

      foreach ($DB->request([
         'SELECT'    => [$tabela . '.id', $tabela . '.name', $tabela . '.status', $tabela . '.date'],
         'FROM'      => $tabela,
         'LEFT JOIN' => [
            $atores => ['ON' => [$atores => $ligacao, $tabela => 'id']]
         ],
         'WHERE' => $where,
         'ORDER' => $tabela . '.id DESC',
         'LIMIT' => 10
      ]) as $linha) {
         $classe = $objeto;
         $itens[] = [
            'id'       => (int)$linha['id'],
            'name'     => (string)$linha['name'],
            'situacao' => $classe::getStatus((int)$linha['status']) . ' | ' . Html::convDateTime($linha['date'])
         ];
      }

      return $itens;
   }

   static function detalhe(string $objeto, int $itens_id): string {
      $classe = self::classeObjeto($objeto);
      $item   = new $classe();

      if (!$item->getFromDB($itens_id)) {
         return 'Registro nao encontrado.';
      }

      $conteudo = mb_substr(trim(strip_tags((string)$item->fields['content'])), 0, 700);
      $conteudo = html_entity_decode($conteudo, ENT_QUOTES, 'UTF-8');
      $rotulo   = self::objetos()[$classe];

      $linhas = [
         "*{$rotulo} #{$itens_id}*",
         '*Titulo:* ' . $item->fields['name'],
         '*Status:* ' . $classe::getStatus((int)$item->fields['status']),
         '*Prioridade:* ' . CommonITILObject::getPriorityName((int)$item->fields['priority']),
         '*Aberto em:* ' . Html::convDateTime($item->fields['date'])
      ];

      if ($classe === 'Ticket') {
         $tecnico = PluginWhatsappempresaConversa::tecnicoDoTicket($itens_id);
         $linhas[] = '*Tecnico:* ' . ($tecnico > 0 ? PluginWhatsappempresaConfig::nomeUsuario($tecnico) : 'Nao atribuido');

         if (!empty($item->fields['time_to_resolve'])) {
            $linhas[] = '*Prazo de solucao:* ' . Html::convDateTime($item->fields['time_to_resolve']);
         }
      }

      $linhas[] = '';
      $linhas[] = "*Descricao:*\n" . $conteudo;

      return implode("\n", $linhas);
   }

   static function criarObjeto(string $objeto, string $titulo, string $conteudo, array $config, int $users_id, string $telefone): int {
      global $DB;

      $classe = self::classeObjeto($objeto);

      $entities_id = 0;
      foreach ($DB->request([
         'SELECT' => ['entities_id'],
         'FROM'   => 'glpi_users',
         'WHERE'  => ['id' => $users_id],
         'LIMIT'  => 1
      ]) as $linha) {
         $entities_id = (int)$linha['entities_id'];
      }

      PluginWhatsappempresaFluxo::assumirUsuario($users_id);

      $dados = [
         'name'                => mb_substr($titulo, 0, 250),
         'content'             => nl2br($conteudo . "\n\nRegistrado pelo WhatsApp - numero " . $telefone),
         'entities_id'         => $entities_id,
         'users_id_recipient'  => $users_id,
         '_users_id_requester' => $users_id,
         'urgency'             => (int)($config['urgencia'] ?? PluginWhatsappempresaConfig::get('abertura_urgencia', '3')),
         'status'              => CommonITILObject::INCOMING
      ];

      $categoria = (int)($config['categoria'] ?? 0);
      if ($categoria > 0) {
         $dados['itilcategories_id'] = $categoria;
      }

      $grupo = (int)($config['grupo'] ?? 0);
      if ($grupo > 0) {
         $dados['_groups_id_assign'] = $grupo;
      }

      if ($classe === 'Ticket') {
         $dados['type'] = (int)($config['tipo'] ?? PluginWhatsappempresaConfig::get('abertura_tipo', '1'));

         $sla = (int)($config['sla'] ?? 0);
         if ($sla > 0) {
            $dados['slas_id_ttr'] = $sla;
         }
      }

      $item = new $classe();
      $novo = $item->add($dados);

      return $novo ? (int)$novo : 0;
   }

   static function gravarAcompanhamento(string $objeto, int $itens_id, string $conteudo, int $users_id, bool $privado): bool {
      $classe = self::classeObjeto($objeto);

      if ($classe === 'Ticket') {
         return PluginWhatsappempresaConversa::gravarFollowup($itens_id, $conteudo, $users_id, $privado);
      }

      if ($users_id > 0) {
         PluginWhatsappempresaFluxo::assumirUsuario($users_id);
      }

      $acompanhamento = new ITILFollowup();

      return (bool)$acompanhamento->add([
         'itemtype'   => $classe,
         'items_id'   => $itens_id,
         'content'    => $conteudo,
         'users_id'   => $users_id,
         'is_private' => $privado ? 1 : 0
      ]);
   }

   /**
    * Listas usadas pelos seletores do editor
    */
   static function catalogo(): array {
      global $DB;

      $categorias = [];
      foreach ($DB->request([
         'SELECT' => ['id', 'completename'],
         'FROM'   => 'glpi_itilcategories',
         'ORDER'  => 'completename ASC',
         'LIMIT'  => 300
      ]) as $linha) {
         $categorias[] = ['id' => (int)$linha['id'], 'nome' => (string)$linha['completename']];
      }

      $slas = [];
      if ($DB->tableExists('glpi_slas')) {
         foreach ($DB->request([
            'SELECT' => ['id', 'name'],
            'FROM'   => 'glpi_slas',
            'ORDER'  => 'name ASC',
            'LIMIT'  => 200
         ]) as $linha) {
            $slas[] = ['id' => (int)$linha['id'], 'nome' => (string)$linha['name']];
         }
      }

      $grupos = [];
      foreach ($DB->request([
         'SELECT' => ['id', 'completename'],
         'FROM'   => 'glpi_groups',
         'ORDER'  => 'completename ASC',
         'LIMIT'  => 300
      ]) as $linha) {
         $grupos[] = ['id' => (int)$linha['id'], 'nome' => (string)$linha['completename']];
      }

      $tipos = [
         ['id' => 1, 'nome' => 'Incidente'],
         ['id' => 2, 'nome' => 'Requisicao']
      ];

      $urgencias = [];
      for ($nivel = 5; $nivel >= 1; $nivel--) {
         $urgencias[] = ['id' => $nivel, 'nome' => CommonITILObject::getUrgencyName($nivel)];
      }

      return [
         'tipos_passo' => self::tiposDePasso(),
         'gatilhos'    => self::gatilhos(),
         'objetos'     => self::objetos(),
         'categorias'  => $categorias,
         'slas'        => $slas,
         'grupos'      => $grupos,
         'tipos'       => $tipos,
         'urgencias'   => $urgencias
      ];
   }

   // ============================================
   // Simulador
   // ============================================

   /**
    * Roda o fluxo sem WhatsApp e sem gravar nada no GLPI
    */
   static function simular(int $fluxos_id, array $entradas, int $users_id): array {
      $fluxo = self::porId($fluxos_id);

      if ($fluxo === null || empty($fluxo['passos'])) {
         return ['ok' => false, 'mensagem' => 'Fluxo sem passos para testar.', 'roteiro' => []];
      }

      self::$simulando      = true;
      self::$estadoSimulado = [];

      $telefone = '0000000000';
      $roteiro  = [];

      try {
         $contexto = ['fluxo_nome' => (string)$fluxo['nome']];
         $primeiro = (string)$fluxo['passos'][0]['id'];

         $resultado = self::executar($telefone, $fluxo, $primeiro, $contexto, $users_id, false, '');
         $roteiro   = array_merge($roteiro, self::roteirizar($resultado['respostas'], 'servidor'));

         foreach ($entradas as $entrada) {
            $entrada = trim((string)$entrada);
            if ($entrada === '') {
               continue;
            }

            if (!empty($resultado['encerrado'])) {
               $roteiro[] = ['quem' => 'aviso', 'texto' => 'O fluxo ja tinha terminado quando esta mensagem foi enviada.'];
               break;
            }

            $roteiro[] = ['quem' => 'cliente', 'texto' => $entrada];

            $estado   = self::$estadoSimulado;
            $contexto = (array)($estado['contexto'] ?? $contexto);
            $passo    = (string)($estado['passo'] ?? $primeiro);

            $resultado = self::executar($telefone, $fluxo, $passo, $contexto, $users_id, true, $entrada);
            $roteiro   = array_merge($roteiro, self::roteirizar($resultado['respostas'], 'servidor'));
         }

         if (!empty($resultado['encerrado'])) {
            $roteiro[] = ['quem' => 'aviso', 'texto' => 'Fim do fluxo.'];
         } else {
            $roteiro[] = ['quem' => 'aviso', 'texto' => 'O fluxo esta aguardando a proxima mensagem do cliente.'];
         }
      } catch (Throwable $e) {
         $roteiro[] = ['quem' => 'erro', 'texto' => 'Erro na execucao: ' . $e->getMessage()];
      } finally {
         self::$simulando      = false;
         self::$estadoSimulado = [];
      }

      return [
         'ok'       => true,
         'roteiro'  => $roteiro,
         'avisos'   => self::validar($fluxo),
         'mensagem' => 'Teste executado em modo seco: nada foi gravado no GLPI nem enviado pelo WhatsApp.'
      ];
   }

   static function roteirizar(array $respostas, string $quem): array {
      $itens = [];

      foreach ($respostas as $resposta) {
         $texto = PluginWhatsappempresaMensagem::textoDaResposta($resposta);
         if (trim($texto) === '') {
            continue;
         }

         $botoes = [];
         if (is_array($resposta)) {
            foreach ((array)($resposta['botoes'] ?? []) as $botao) {
               $botoes[] = $botao['id'] . ' - ' . $botao['rotulo'];
            }
            foreach ((array)($resposta['lista']['itens'] ?? []) as $item) {
               $botoes[] = $item['id'] . ' - ' . $item['rotulo'];
            }
         }

         $itens[] = ['quem' => $quem, 'texto' => $texto, 'botoes' => $botoes];
      }

      return $itens;
   }

   // ============================================
   // Fluxos de exemplo criados na instalacao
   // ============================================

   static function instalarPadroes(): void {
      global $DB;

      foreach ($DB->request(['COUNT' => 'total', 'FROM' => self::TABELA]) as $linha) {
         if ((int)$linha['total'] > 0) {
            return;
         }
      }

      $modelos = [
         [
            'nome'      => 'Abrir chamado',
            'descricao' => 'Coleta titulo e descricao e registra o chamado no GLPI.',
            'gatilho'   => 'menu',
            'palavras'  => 'abrir, chamado, novo chamado',
            'ordem'     => 0,
            'is_ativo'  => 1,
            'passos'    => [
               ['id' => 'titulo', 'tipo' => 'pergunta', 'rotulo' => 'Pedir o titulo', 'config' => [
                  'texto' => "*Abertura de chamado*\nEnvie um titulo curto para o seu chamado.",
                  'variavel' => 'titulo',
                  'minimo' => '4'
               ]],
               ['id' => 'descricao', 'tipo' => 'pergunta', 'rotulo' => 'Pedir a descricao', 'config' => [
                  'texto' => 'Agora descreva o problema com o maximo de detalhes.',
                  'variavel' => 'descricao',
                  'minimo' => '5'
               ]],
               ['id' => 'criar', 'tipo' => 'abrir_objeto', 'rotulo' => 'Criar o chamado', 'config' => [
                  'objeto' => 'Ticket',
                  'variavel_titulo' => 'titulo',
                  'variavel_descricao' => 'descricao',
                  'tipo' => '1',
                  'urgencia' => '3',
                  'categoria' => '0',
                  'sla' => '0',
                  'grupo' => '0',
                  'texto' => "Chamado *#{numero_criado}* aberto com sucesso.\n*Titulo:* {titulo}"
               ]],
               ['id' => 'fim', 'tipo' => 'encerrar', 'rotulo' => 'Finalizar', 'config' => [
                  'texto' => 'Voce recebera as atualizacoes deste chamado por aqui.',
                  'silenciar' => ''
               ]]
            ]
         ],
         [
            'nome'      => 'Meus chamados',
            'descricao' => 'Lista os chamados abertos, mostra o detalhe e permite acompanhar ou falar com o tecnico.',
            'gatilho'   => 'menu',
            'palavras'  => 'chamados, meus chamados',
            'ordem'     => 1,
            'is_ativo'  => 1,
            'passos'    => [
               ['id' => 'lista', 'tipo' => 'listar_objetos', 'rotulo' => 'Listar chamados abertos', 'config' => [
                  'texto' => '*Seus chamados abertos*',
                  'objeto' => 'Ticket',
                  'variavel' => 'chamado',
                  'somente_abertos' => '1'
               ]],
               ['id' => 'detalhe', 'tipo' => 'detalhe_objeto', 'rotulo' => 'Mostrar o detalhe', 'config' => [
                  'objeto' => 'Ticket',
                  'variavel' => 'chamado'
               ]],
               ['id' => 'acoes', 'tipo' => 'menu', 'rotulo' => 'O que fazer', 'config' => [
                  'texto' => 'O que voce deseja fazer com este chamado?',
                  'opcoes' => [
                     ['rotulo' => 'Acompanhar', 'ir' => 'seguir'],
                     ['rotulo' => 'Falar com tecnico', 'ir' => 'conversa'],
                     ['rotulo' => 'Encerrar', 'ir' => 'fim']
                  ]
               ]],
               ['id' => 'seguir', 'tipo' => 'acompanhamento', 'rotulo' => 'Gravar acompanhamento', 'config' => [
                  'objeto' => 'Ticket',
                  'variavel' => 'chamado',
                  'variavel_texto' => 'acompanhamento',
                  'privado' => '',
                  'texto' => 'Escreva o texto do acompanhamento.',
                  'texto_ok' => 'Acompanhamento registrado no chamado #{chamado}.'
               ]],
               ['id' => 'fim', 'tipo' => 'encerrar', 'rotulo' => 'Finalizar', 'config' => [
                  'texto' => 'Atendimento finalizado. Obrigado pelo contato.',
                  'silenciar' => ''
               ]],
               ['id' => 'conversa', 'tipo' => 'conversa_tecnico', 'rotulo' => 'Falar com o tecnico', 'config' => [
                  'variavel' => 'chamado'
               ]]
            ]
         ],
         [
            'nome'      => 'Aprovacoes pendentes',
            'descricao' => 'Lista as validacoes do usuario e grava a aprovacao ou recusa no GLPI.',
            'gatilho'   => 'menu',
            'palavras'  => 'aprovacao, validacao',
            'ordem'     => 2,
            'is_ativo'  => 1,
            'passos'    => [
               ['id' => 'lista', 'tipo' => 'listar_validacoes', 'rotulo' => 'Listar pendencias', 'config' => [
                  'texto' => '*Validacoes pendentes*',
                  'variavel' => 'validacao'
               ]],
               ['id' => 'responder', 'tipo' => 'responder_validacao', 'rotulo' => 'Aprovar ou recusar', 'config' => [
                  'variavel' => 'validacao',
                  'texto' => 'Qual a sua decisao para este pedido?'
               ]],
               ['id' => 'fim', 'tipo' => 'encerrar', 'rotulo' => 'Finalizar', 'config' => [
                  'texto' => 'Obrigado. A decisao foi registrada no GLPI.',
                  'silenciar' => ''
               ]]
            ]
         ],
         [
            'nome'      => 'Registrar mudanca',
            'descricao' => 'Exemplo de fluxo que cria uma mudanca em vez de um chamado.',
            'gatilho'   => 'manual',
            'palavras'  => 'mudanca',
            'ordem'     => 3,
            'is_ativo'  => 0,
            'passos'    => [
               ['id' => 'titulo', 'tipo' => 'pergunta', 'rotulo' => 'Pedir o titulo', 'config' => [
                  'texto' => 'Envie um titulo para a mudanca.',
                  'variavel' => 'titulo',
                  'minimo' => '4'
               ]],
               ['id' => 'motivo', 'tipo' => 'pergunta', 'rotulo' => 'Pedir a justificativa', 'config' => [
                  'texto' => 'Descreva a justificativa da mudanca.',
                  'variavel' => 'descricao',
                  'minimo' => '10'
               ]],
               ['id' => 'criar', 'tipo' => 'abrir_objeto', 'rotulo' => 'Criar a mudanca', 'config' => [
                  'objeto' => 'Change',
                  'variavel_titulo' => 'titulo',
                  'variavel_descricao' => 'descricao',
                  'urgencia' => '3',
                  'categoria' => '0',
                  'grupo' => '0',
                  'texto' => 'Mudanca *#{numero_criado}* registrada para analise.'
               ]],
               ['id' => 'fim', 'tipo' => 'encerrar', 'rotulo' => 'Finalizar', 'config' => [
                  'texto' => 'A equipe vai avaliar e retornar por aqui.',
                  'silenciar' => ''
               ]]
            ]
         ]
      ];

      foreach ($modelos as $modelo) {
         $id = self::salvar($modelo);
         if ($id > 0) {
            $DB->update(self::TABELA, ['is_padrao' => 1], ['id' => $id]);
         }
      }
   }
}
