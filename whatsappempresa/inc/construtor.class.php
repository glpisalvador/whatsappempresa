<?php

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access this file directly");
}

/**
 * Motor dos fluxos montados no painel.
 *
 * Cada fluxo e um grafo: blocos (nos) ligados por conexoes (arestas). A conexao sai de uma
 * saida nomeada do bloco ("proximo", "sim", "op2", "fora"...) e entra em outro bloco.
 * O editor visual grava o grafo a cada alteracao e o motor le o fluxo do banco a cada
 * mensagem recebida, entao qualquer mudanca vale na hora.
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

   // Blocos por fluxo e blocos executados seguidos numa mesma mensagem (protege contra lacos)
   const LIMITE_NOS      = 200;
   const LIMITE_EXECUCAO = 60;
   const LIMITE_PASSOS   = self::LIMITE_EXECUCAO;

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
   // Catalogo de blocos do editor
   // ============================================

   static function grupos(): array {
      return [
         'interacao'  => 'Interação',
         'logica'     => 'Lógica',
         'glpi'       => 'GLPI',
         'integracao' => 'Integração',
         'fim'        => 'Finalização'
      ];
   }

   static function rotulosSaida(): array {
      return [
         'proximo'    => 'Próximo',
         'sim'        => 'Sim',
         'nao'        => 'Não',
         'verdadeiro' => 'Verdadeiro',
         'falso'      => 'Falso',
         'vazio'      => 'Nada encontrado',
         'erro'       => 'Erro',
         'sucesso'    => 'Sucesso',
         'dentro'     => 'No expediente',
         'fora'       => 'Fora do expediente',
         'invalida'   => 'Resposta inválida',
         'falha'      => 'Não transferido'
      ];
   }

   /**
    * Tipos de bloco. "campos" descreve o painel de propriedades que o editor monta sozinho;
    * "saidas" sao as saidas fixas e "dinamico" indica saidas que dependem da configuracao.
    */
   static function tiposDePasso(): array {
      $texto    = ['nome' => 'texto', 'tipo' => 'area', 'rotulo' => 'Mensagem', 'ajuda' => 'Use {variavel} para inserir valores coletados. *negrito*, _italico_ e ~riscado~ funcionam no WhatsApp.'];
      $variavel = ['nome' => 'variavel', 'tipo' => 'variavel', 'rotulo' => 'Variável'];
      $objeto   = ['nome' => 'objeto', 'tipo' => 'selecao', 'rotulo' => 'Tipo de registro', 'fonte' => 'objetos'];

      return [
         'inicio' => [
            'rotulo' => 'Início',
            'grupo'  => 'inicio',
            'icone'  => 'ti ti-player-play',
            'ajuda'  => 'Ponto de partida do fluxo. Clique nele para configurar nome, gatilho e situação.',
            'saidas' => ['proximo'],
            'campos' => []
         ],

         // Interacao
         'mensagem' => [
            'rotulo' => 'Conteúdo',
            'grupo'  => 'interacao',
            'icone'  => 'ti ti-message',
            'ajuda'  => 'Envia um texto, uma imagem ou um áudio e segue para o próximo bloco.',
            'saidas' => ['proximo'],
            'campos' => [
               $texto,
               ['nome' => 'midia', 'tipo' => 'midia', 'rotulo' => 'Imagem ou áudio (opcional)', 'ajuda' => 'Com imagem, o texto vai como legenda. Áudio OGG vai como nota de voz.']
            ]
         ],
         'pergunta' => [
            'rotulo' => 'Pergunta',
            'grupo'  => 'interacao',
            'icone'  => 'ti ti-help-circle',
            'ajuda'  => 'Pergunta, espera a resposta do cliente e guarda numa variável.',
            'saidas' => ['proximo'],
            'campos' => [
               array_merge($texto, ['rotulo' => 'Pergunta']),
               array_merge($variavel, ['rotulo' => 'Guardar a resposta em']),
               ['nome' => 'validacao', 'tipo' => 'selecao', 'rotulo' => 'Aceitar somente', 'opcoes' => [
                  'texto' => 'Texto livre', 'numero' => 'Número', 'email' => 'E-mail', 'telefone' => 'Telefone com DDD',
                  'cpf_cnpj' => 'CPF ou CNPJ', 'data' => 'Data (dd/mm/aaaa)'
               ]],
               ['nome' => 'minimo', 'tipo' => 'numero', 'rotulo' => 'Mínimo de caracteres'],
               ['nome' => 'erro', 'tipo' => 'texto', 'rotulo' => 'Mensagem se a resposta não for aceita']
            ]
         ],
         'sim_nao' => [
            'rotulo' => 'Sim ou não',
            'grupo'  => 'interacao',
            'icone'  => 'ti ti-arrows-split',
            'ajuda'  => 'Pergunta com as opções Sim e Não; cada resposta tem sua saída.',
            'saidas' => ['sim', 'nao'],
            'campos' => [array_merge($texto, ['rotulo' => 'Pergunta']), array_merge($variavel, ['rotulo' => 'Guardar sim/nao em'])]
         ],
         'variavel' => [
            'rotulo' => 'Definir variável',
            'grupo'  => 'interacao',
            'icone'  => 'ti ti-variable',
            'ajuda'  => 'Guarda um valor fixo ou montado com outras variáveis.',
            'saidas' => ['proximo'],
            'campos' => [$variavel, ['nome' => 'valor', 'tipo' => 'texto', 'rotulo' => 'Valor', 'ajuda' => 'Aceita {variavel}.']]
         ],
         'intervalo' => [
            'rotulo' => 'Intervalo',
            'grupo'  => 'interacao',
            'icone'  => 'ti ti-clock-pause',
            'ajuda'  => 'Espera alguns segundos antes do próximo bloco, como se estivesse digitando.',
            'saidas' => ['proximo'],
            'campos' => [['nome' => 'segundos', 'tipo' => 'numero', 'rotulo' => 'Aguardar (segundos, até 60)']]
         ],

         // Logica
         'menu' => [
            'rotulo'   => 'Menu',
            'grupo'    => 'logica',
            'icone'    => 'ti ti-list-numbers',
            'ajuda'    => 'Mostra opções numeradas; cada opção é uma saída. A saída "Resposta inválida" é opcional.',
            'dinamico' => 'menu',
            'campos'   => [
               array_merge($texto, ['rotulo' => 'Texto do menu']),
               ['nome' => 'opcoes', 'tipo' => 'opcoes', 'rotulo' => 'Opções', 'ajuda' => 'Até 10 opções. Cada uma ganha uma saída no bloco.'],
               array_merge($variavel, ['rotulo' => 'Guardar a opção escolhida em (opcional)'])
            ]
         ],
         'condicao' => [
            'rotulo' => 'Condição',
            'grupo'  => 'logica',
            'icone'  => 'ti ti-git-branch',
            'ajuda'  => 'Compara uma variável e segue pela saída Verdadeiro ou Falso.',
            'saidas' => ['verdadeiro', 'falso'],
            'campos' => [
               $variavel,
               ['nome' => 'operador', 'tipo' => 'selecao', 'rotulo' => 'Regra', 'opcoes' => [
                  'igual' => 'é igual a', 'diferente' => 'é diferente de', 'contem' => 'contém', 'comeca' => 'começa com',
                  'maior' => 'é maior que', 'menor' => 'é menor que', 'vazio' => 'está vazia', 'preenchido' => 'está preenchida'
               ]],
               ['nome' => 'valor', 'tipo' => 'texto', 'rotulo' => 'Valor', 'ajuda' => 'Aceita {variavel}.']
            ]
         ],
         'randomizador' => [
            'rotulo'   => 'Randomizador',
            'grupo'    => 'logica',
            'icone'    => 'ti ti-arrows-shuffle',
            'ajuda'    => 'Divide os clientes entre caminhos pela porcentagem (teste A/B).',
            'dinamico' => 'randomizador',
            'campos'   => [['nome' => 'caminhos', 'tipo' => 'caminhos', 'rotulo' => 'Caminhos']]
         ],
         'horario' => [
            'rotulo' => 'Horário de expediente',
            'grupo'  => 'logica',
            'icone'  => 'ti ti-calendar-time',
            'ajuda'  => 'Segue por "No expediente" ou "Fora do expediente". Usa o calendário do GLPI (com feriados) ou os dias e horas abaixo.',
            'saidas' => ['dentro', 'fora'],
            'campos' => [
               ['nome' => 'calendario', 'tipo' => 'selecao', 'rotulo' => 'Calendário do GLPI', 'fonte' => 'calendarios', 'vazio' => 'Usar dias e horas abaixo'],
               ['nome' => 'dias', 'tipo' => 'dias', 'rotulo' => 'Dias de atendimento'],
               ['nome' => 'hora_inicio', 'tipo' => 'hora', 'rotulo' => 'Das'],
               ['nome' => 'hora_fim', 'tipo' => 'hora', 'rotulo' => 'Até']
            ]
         ],

         // GLPI
         'listar_objetos' => [
            'rotulo' => 'Listar registros',
            'grupo'  => 'glpi',
            'icone'  => 'ti ti-clipboard-list',
            'ajuda'  => 'Lista chamados, problemas ou mudanças do cliente e guarda o escolhido.',
            'saidas' => ['proximo', 'vazio'],
            'campos' => [
               $texto, $objeto, array_merge($variavel, ['rotulo' => 'Guardar o escolhido em']),
               ['nome' => 'somente_abertos', 'tipo' => 'caixa', 'rotulo' => 'Somente os não fechados'],
               ['nome' => 'texto_vazio', 'tipo' => 'texto', 'rotulo' => 'Mensagem quando não houver registros']
            ]
         ],
         'detalhe_objeto' => [
            'rotulo' => 'Detalhe do registro',
            'grupo'  => 'glpi',
            'icone'  => 'ti ti-file-text',
            'ajuda'  => 'Mostra título, status, prioridade, prazo e técnico do registro escolhido.',
            'saidas' => ['proximo'],
            'campos' => [$objeto, array_merge($variavel, ['rotulo' => 'Registro guardado em'])]
         ],
         'abrir_objeto' => [
            'rotulo' => 'Abrir registro',
            'grupo'  => 'glpi',
            'icone'  => 'ti ti-ticket',
            'ajuda'  => 'Cria o chamado, problema ou mudança com as variáveis coletadas. O número fica em {numero_criado}.',
            'saidas' => ['proximo', 'erro'],
            'campos' => [
               $objeto,
               ['nome' => 'variavel_titulo', 'tipo' => 'variavel', 'rotulo' => 'Título vem da variável'],
               ['nome' => 'variavel_descricao', 'tipo' => 'variavel', 'rotulo' => 'Descrição vem da variável'],
               ['nome' => 'modelo_descricao', 'tipo' => 'area', 'rotulo' => 'Modelo da descrição (opcional)', 'ajuda' => 'Se preenchido, substitui a descrição. Aceita {variavel}.'],
               ['nome' => 'tipo', 'tipo' => 'selecao', 'rotulo' => 'Tipo (chamado)', 'fonte' => 'tipos'],
               ['nome' => 'urgencia', 'tipo' => 'selecao', 'rotulo' => 'Urgência', 'fonte' => 'urgencias'],
               ['nome' => 'categoria', 'tipo' => 'selecao', 'rotulo' => 'Categoria', 'fonte' => 'categorias', 'vazio' => '-----'],
               ['nome' => 'sla', 'tipo' => 'selecao', 'rotulo' => 'SLA de solução', 'fonte' => 'slas', 'vazio' => '-----'],
               ['nome' => 'grupo', 'tipo' => 'selecao', 'rotulo' => 'Grupo atribuído', 'fonte' => 'grupos', 'vazio' => '-----'],
               array_merge($texto, ['rotulo' => 'Mensagem de confirmação'])
            ]
         ],
         'acompanhamento' => [
            'rotulo' => 'Gravar acompanhamento',
            'grupo'  => 'glpi',
            'icone'  => 'ti ti-file-certificate',
            'ajuda'  => 'Pede um texto ao cliente e grava como acompanhamento no registro escolhido.',
            'saidas' => ['proximo'],
            'campos' => [
               $objeto, array_merge($variavel, ['rotulo' => 'Registro guardado em']),
               ['nome' => 'variavel_texto', 'tipo' => 'variavel', 'rotulo' => 'Texto já coletado na variável (opcional)'],
               array_merge($texto, ['rotulo' => 'Pedido do texto']),
               ['nome' => 'privado', 'tipo' => 'caixa', 'rotulo' => 'Acompanhamento privado'],
               ['nome' => 'texto_ok', 'tipo' => 'texto', 'rotulo' => 'Mensagem de confirmação']
            ]
         ],
         'listar_validacoes' => [
            'rotulo' => 'Listar validações',
            'grupo'  => 'glpi',
            'icone'  => 'ti ti-user-check',
            'ajuda'  => 'Lista as aprovações pendentes do cliente e guarda a escolhida.',
            'saidas' => ['proximo', 'vazio'],
            'campos' => [$texto, array_merge($variavel, ['rotulo' => 'Guardar a escolhida em']), ['nome' => 'texto_vazio', 'tipo' => 'texto', 'rotulo' => 'Mensagem quando não houver pendências']]
         ],
         'responder_validacao' => [
            'rotulo' => 'Aprovar ou recusar',
            'grupo'  => 'glpi',
            'icone'  => 'ti ti-circle-check',
            'ajuda'  => 'Pergunta a decisão, pede o comentário e grava no GLPI.',
            'saidas' => ['proximo'],
            'campos' => [array_merge($variavel, ['rotulo' => 'Validação guardada em']), array_merge($texto, ['rotulo' => 'Pergunta da decisão'])]
         ],

         // Integracao
         'http' => [
            'rotulo' => 'Requisição HTTP',
            'grupo'  => 'integracao',
            'icone'  => 'ti ti-world-www',
            'ajuda'  => 'Chama uma API externa. A resposta fica na variável e o código HTTP em {variavel_status}.',
            'saidas' => ['sucesso', 'erro'],
            'campos' => [
               ['nome' => 'metodo', 'tipo' => 'selecao', 'rotulo' => 'Método', 'opcoes' => ['GET' => 'GET', 'POST' => 'POST', 'PUT' => 'PUT', 'PATCH' => 'PATCH', 'DELETE' => 'DELETE']],
               ['nome' => 'url', 'tipo' => 'texto', 'rotulo' => 'URL (http ou https)', 'ajuda' => 'Aceita {variavel}.'],
               ['nome' => 'cabecalhos', 'tipo' => 'area', 'rotulo' => 'Cabeçalhos', 'ajuda' => 'Um por linha, no formato Nome: valor.'],
               ['nome' => 'corpo', 'tipo' => 'area', 'rotulo' => 'Corpo da requisição', 'ajuda' => 'JSON ou texto. Aceita {variavel}.'],
               array_merge($variavel, ['rotulo' => 'Guardar a resposta em']),
               ['nome' => 'mapeamentos', 'tipo' => 'area', 'rotulo' => 'Extrair campos do JSON', 'ajuda' => 'Um por linha: variavel = caminho.do.campo (ex.: nome = dados.cliente.nome).'],
               ['nome' => 'tempo_limite', 'tipo' => 'numero', 'rotulo' => 'Tempo limite (segundos)']
            ]
         ],

         // Finalizacao
         'conversa_tecnico' => [
            'rotulo' => 'Falar com o técnico',
            'grupo'  => 'fim',
            'icone'  => 'ti ti-headset',
            'ajuda'  => 'Passa o cliente para a conversa com o técnico do chamado escolhido.',
            'saidas' => ['falha'],
            'campos' => [array_merge($variavel, ['rotulo' => 'Chamado guardado em'])]
         ],
         'trocar_fluxo' => [
            'rotulo' => 'Trocar de fluxo',
            'grupo'  => 'fim',
            'icone'  => 'ti ti-arrow-guide',
            'ajuda'  => 'Continua o atendimento em outro fluxo, mantendo as variáveis.',
            'saidas' => [],
            'campos' => [['nome' => 'fluxo', 'tipo' => 'selecao', 'rotulo' => 'Fluxo de destino', 'fonte' => 'fluxos']]
         ],
         'encerrar' => [
            'rotulo' => 'Encerrar',
            'grupo'  => 'fim',
            'icone'  => 'ti ti-flag',
            'ajuda'  => 'Envia a mensagem final e devolve o número ao atendimento.',
            'saidas' => [],
            'campos' => [
               array_merge($texto, ['rotulo' => 'Mensagem final']),
               ['nome' => 'silenciar', 'tipo' => 'caixa', 'rotulo' => 'Silenciar o número depois (bot para de responder)']
            ]
         ]
      ];
   }

   static function gatilhos(): array {
      return [
         'menu'    => 'Aparece como opção no menu de atendimento',
         'palavra' => 'Começa quando o cliente digita uma palavra',
         'codigo'  => 'Começa logo depois do código de acesso ser aceito',
         'manual'  => 'Somente por outro fluxo (bloco Trocar de fluxo)'
      ];
   }

   static function objetos(): array {
      return ['Ticket' => 'Chamado', 'Problem' => 'Problema', 'Change' => 'Mudanca'];
   }

   /**
    * Saidas do bloco na ordem em que aparecem no editor
    */
   static function saidasDoNo(array $no): array {
      $tipos = self::tiposDePasso();
      $tipo  = $tipos[(string)($no['tipo'] ?? '')] ?? null;
      if ($tipo === null) {
         return [];
      }

      $config = (array)($no['config'] ?? []);

      switch ($tipo['dinamico'] ?? '') {
         case 'menu':
            $saidas = [];
            foreach (array_values((array)($config['opcoes'] ?? [])) as $i => $opcao) {
               $saidas[] = 'op' . ($i + 1);
            }
            $saidas[] = 'invalida';
            return $saidas;

         case 'randomizador':
            $saidas = [];
            foreach (array_values((array)($config['caminhos'] ?? [])) as $i => $caminho) {
               $saidas[] = 'r' . ($i + 1);
            }
            return $saidas;
      }

      return (array)($tipo['saidas'] ?? []);
   }

   static function rotuloSaida(array $no, string $saida): string {
      $config = (array)($no['config'] ?? []);

      if (preg_match('/^op(\d+)$/', $saida, $m)) {
         $opcao = array_values((array)($config['opcoes'] ?? []))[(int)$m[1] - 1] ?? null;
         return $opcao !== null ? (string)$opcao['rotulo'] : $saida;
      }
      if (preg_match('/^r(\d+)$/', $saida, $m)) {
         $caminho = array_values((array)($config['caminhos'] ?? []))[(int)$m[1] - 1] ?? null;
         return $caminho !== null ? (int)$caminho['percentual'] . '%' : $saida;
      }

      return self::rotulosSaida()[$saida] ?? $saida;
   }

   // ============================================
   // Persistencia dos fluxos
   // ============================================

   /**
    * Fluxos de uma conexao (numero). Sem conexao informada: os da conexao em uso.
    * conexao = -1 lista os de todas.
    */
   static function listar(bool $somenteAtivos = false, ?int $conexao = null): array {
      global $DB;

      $where = ['is_deleted' => 0];
      if ($somenteAtivos) {
         $where['is_ativo'] = 1;
      }
      $conexao = $conexao ?? PluginWhatsappempresaConexao::atual();
      if ($conexao > 0) {
         $where['conexoes_id'] = $conexao;
      }

      $itens = [];
      foreach ($DB->request([
         'FROM'  => self::TABELA,
         'WHERE' => $where,
         'ORDER' => ['ordem ASC', 'id ASC']
      ]) as $linha) {
         $itens[] = self::carregar($linha);
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
         return self::carregar($linha);
      }

      return null;
   }

   /**
    * Le a definicao gravada e monta os indices usados pelo motor.
    * Fluxos gravados como lista de passos (versoes anteriores) sao convertidos em grafo.
    */
   static function carregar(array $linha): array {
      $definicao = json_decode((string)($linha['definicao'] ?? ''), true);
      $definicao = is_array($definicao) ? $definicao : [];

      if ((int)($definicao['versao'] ?? 1) >= 2) {
         $grafo = ['nos' => (array)($definicao['nos'] ?? []), 'arestas' => (array)($definicao['arestas'] ?? [])];
      } else {
         $grafo = self::migrarLegado(self::normalizarPassos($definicao['passos'] ?? []));
      }

      $mapa   = [];
      $inicio = '';
      foreach ($grafo['nos'] as $no) {
         $mapa[(string)$no['id']] = $no;
         if ($no['tipo'] === 'inicio' && $inicio === '') {
            $inicio = (string)$no['id'];
         }
      }

      $ligacoes = [];
      foreach ($grafo['arestas'] as $aresta) {
         $ligacoes[(string)$aresta['de']][(string)$aresta['saida']] = (string)$aresta['para'];
      }

      $linha['nos']      = array_values($grafo['nos']);
      $linha['arestas']  = array_values($grafo['arestas']);
      $linha['mapa']     = $mapa;
      $linha['ligacoes'] = $ligacoes;
      $linha['inicio']   = $inicio;

      // Blocos executaveis (sem o Inicio), para quem so precisa saber se o fluxo tem conteudo
      $linha['passos'] = array_values(array_filter($grafo['nos'], fn($no) => $no['tipo'] !== 'inicio'));

      return $linha;
   }

   static function passosDe(array $fluxo): array {
      return $fluxo['passos'] ?? [];
   }

   /**
    * Grava o fluxo. Aceita o grafo do editor (nos + arestas) ou uma lista de passos (modelos antigos).
    */
   static function salvar(array $dados): int {
      global $DB;

      $id = (int)($dados['id'] ?? 0);

      if (isset($dados['nos'])) {
         $grafo = self::normalizarGrafo((array)$dados['nos'], (array)($dados['arestas'] ?? []));
      } elseif (isset($dados['passos'])) {
         $grafo = self::migrarLegado(self::normalizarPassos($dados['passos']));
      } elseif ($id > 0 && ($atual = self::porId($id)) !== null) {
         $grafo = ['nos' => $atual['nos'], 'arestas' => $atual['arestas']];
      } else {
         $grafo = self::normalizarGrafo([], []);
      }

      $campos = [
         'nome'        => mb_substr(trim((string)($dados['nome'] ?? '')) ?: 'Fluxo sem nome', 0, 120),
         'descricao'   => mb_substr(trim((string)($dados['descricao'] ?? '')), 0, 250),
         'gatilho'     => array_key_exists((string)($dados['gatilho'] ?? ''), self::gatilhos()) ? (string)$dados['gatilho'] : 'menu',
         'palavras'    => mb_substr(trim((string)($dados['palavras'] ?? '')), 0, 250),
         'definicao'   => json_encode(['versao' => 2, 'nos' => $grafo['nos'], 'arestas' => $grafo['arestas']], JSON_UNESCAPED_UNICODE),
         'entities_id' => (int)($dados['entities_id'] ?? 0),
         'is_ativo'    => !empty($dados['is_ativo']) ? 1 : 0
      ];

      if (isset($dados['ordem'])) {
         $campos['ordem'] = (int)$dados['ordem'];
      }

      if ($id > 0) {
         $DB->update(self::TABELA, $campos, ['id' => $id]);
         return $id;
      }

      // Fluxo novo nasce na conexao informada (ou na conexao em uso)
      $conexao = (int)($dados['conexoes_id'] ?? 0);
      $campos['conexoes_id'] = PluginWhatsappempresaConexao::porId($conexao) !== null ? $conexao : PluginWhatsappempresaConexao::atual();
      $campos['users_id'] = (int)Session::getLoginUserID();
      if (!isset($campos['ordem'])) {
         $campos['ordem'] = countElementsInTable(self::TABELA, ['is_deleted' => 0, 'conexoes_id' => $campos['conexoes_id']]);
      }
      $DB->insert(self::TABELA, $campos);

      return (int)$DB->insertId();
   }

   static function remover(int $id): void {
      global $DB;

      if ($id > 0) {
         $DB->update(self::TABELA, ['is_ativo' => 0, 'is_deleted' => 1], ['id' => $id]);
      }
   }

   /**
    * Copia o fluxo (inativo), na mesma conexao ou em outra
    */
   static function duplicar(int $id, int $conexaoDestino = 0): int {
      $fluxo = self::porId($id);
      if ($fluxo === null) {
         return 0;
      }
      $destino = PluginWhatsappempresaConexao::porId($conexaoDestino) !== null ? $conexaoDestino : (int)$fluxo['conexoes_id'];
      $nome = $destino === (int)$fluxo['conexoes_id'] ? 'Copia de ' . $fluxo['nome'] : (string)$fluxo['nome'];

      return self::salvar([
         'conexoes_id' => $destino,
         'nome'        => mb_substr($nome, 0, 120),
         'descricao'   => (string)$fluxo['descricao'],
         'gatilho'     => 'manual',
         'palavras'    => '',
         'nos'         => $fluxo['nos'],
         'arestas'     => $fluxo['arestas'],
         'entities_id' => (int)$fluxo['entities_id'],
         'is_ativo'    => 0
      ]);
   }

   /**
    * Passa o fluxo para outro aparelho (conexao); vai para o fim da lista de la
    */
   static function mover(int $id, int $conexaoDestino): bool {
      global $DB;

      $fluxo = self::porId($id);
      if ($fluxo === null || PluginWhatsappempresaConexao::porId($conexaoDestino) === null) {
         return false;
      }
      if ((int)$fluxo['conexoes_id'] === $conexaoDestino) {
         return true;
      }
      $DB->update(self::TABELA, [
         'conexoes_id' => $conexaoDestino,
         'ordem'       => countElementsInTable(self::TABELA, ['is_deleted' => 0, 'conexoes_id' => $conexaoDestino])
      ], ['id' => $id]);
      return true;
   }

   static function reordenar(array $ordem): void {
      global $DB;

      foreach (array_values($ordem) as $posicao => $id) {
         $DB->update(self::TABELA, ['ordem' => $posicao], ['id' => (int)$id]);
      }
   }

   static function idLimpo($valor): string {
      return mb_substr(preg_replace('/[^a-zA-Z0-9_]/', '', (string)$valor), 0, 40);
   }

   /**
    * Limpa a configuracao de um bloco: so textos, e listas somente onde o tipo usa lista
    */
   static function normalizarConfig(string $tipo, $config): array {
      $limpa = [];

      foreach ((array)$config as $chave => $valor) {
         $chave = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$chave);
         if ($chave === '') {
            continue;
         }

         if ($chave === 'opcoes') {
            $opcoes = [];
            foreach ((array)$valor as $opcao) {
               $rotulo = mb_substr(trim((string)(is_array($opcao) ? ($opcao['rotulo'] ?? '') : $opcao)), 0, 24);
               if ($rotulo !== '') {
                  $opcoes[] = ['rotulo' => $rotulo];
               }
            }
            $limpa['opcoes'] = array_slice($opcoes, 0, 10);
            continue;
         }

         if ($chave === 'caminhos') {
            $caminhos = [];
            foreach ((array)$valor as $caminho) {
               $caminhos[] = ['percentual' => max(0, min(100, (int)(is_array($caminho) ? ($caminho['percentual'] ?? 0) : $caminho)))];
            }
            $limpa['caminhos'] = array_slice($caminhos, 0, 6);
            continue;
         }

         if (is_array($valor)) {
            continue;
         }

         $limpa[$chave] = mb_substr((string)$valor, 0, 4000);
      }

      if ($tipo === 'randomizador' && count($limpa['caminhos'] ?? []) < 2) {
         $limpa['caminhos'] = [['percentual' => 50], ['percentual' => 50]];
      }

      if ($tipo === 'pergunta' && trim((string)($limpa['variavel'] ?? '')) === '') {
         $limpa['variavel'] = 'resposta';
      }

      return $limpa;
   }

   /**
    * Garante blocos validos, ids unicos, um unico Inicio e conexoes que existem
    */
   static function normalizarGrafo(array $nos, array $arestas): array {
      $tipos  = self::tiposDePasso();
      $limpos = [];
      $usados = [];
      $temInicio = false;

      foreach ($nos as $indice => $no) {
         if (!is_array($no)) {
            continue;
         }

         $tipo = (string)($no['tipo'] ?? '');
         if (!isset($tipos[$tipo])) {
            continue;
         }

         if ($tipo === 'inicio') {
            if ($temInicio) {
               continue;
            }
            $temInicio = true;
         }

         $id = self::idLimpo($no['id'] ?? '');
         if ($id === '' || isset($usados[$id])) {
            $id = 'n' . substr(md5($tipo . $indice . microtime(true) . mt_rand()), 0, 8);
         }
         $usados[$id] = true;

         $limpos[] = [
            'id'     => $id,
            'tipo'   => $tipo,
            'rotulo' => mb_substr(trim((string)($no['rotulo'] ?? '')) ?: $tipos[$tipo]['rotulo'], 0, 80),
            'x'      => max(-20000, min(20000, (int)round((float)($no['x'] ?? 0)))),
            'y'      => max(-20000, min(20000, (int)round((float)($no['y'] ?? 0)))),
            'config' => self::normalizarConfig($tipo, $no['config'] ?? [])
         ];

         if (count($limpos) >= self::LIMITE_NOS) {
            break;
         }
      }

      if (!$temInicio) {
         array_unshift($limpos, [
            'id' => 'inicio', 'tipo' => 'inicio', 'rotulo' => 'Início', 'x' => 60, 'y' => 200, 'config' => []
         ]);
      }

      $mapa = [];
      foreach ($limpos as $no) {
         $mapa[$no['id']] = $no;
      }

      $ligadas = [];
      foreach ($arestas as $aresta) {
         if (!is_array($aresta)) {
            continue;
         }
         $de    = self::idLimpo($aresta['de'] ?? '');
         $para  = self::idLimpo($aresta['para'] ?? '');
         $saida = preg_replace('/[^a-z0-9_]/', '', (string)($aresta['saida'] ?? ''));

         if (!isset($mapa[$de], $mapa[$para]) || $mapa[$para]['tipo'] === 'inicio') {
            continue;
         }
         if (!in_array($saida, self::saidasDoNo($mapa[$de]), true)) {
            continue;
         }

         // Uma saida leva a um unico bloco
         $ligadas[$de . '|' . $saida] = ['de' => $de, 'saida' => $saida, 'para' => $para];
      }

      return ['nos' => $limpos, 'arestas' => array_values($ligadas)];
   }

   /**
    * Limpeza dos passos no formato antigo (lista), usada so para converter
    */
   static function normalizarPassos($passos): array {
      $tipos  = self::tiposDePasso() + ['ir_para' => ['rotulo' => 'Ir para']];
      $limpos = [];
      $usados = [];

      foreach ((array)$passos as $indice => $passo) {
         if (!is_array($passo)) {
            continue;
         }

         $tipo = (string)($passo['tipo'] ?? '');
         if (!isset($tipos[$tipo]) || $tipo === 'inicio') {
            continue;
         }

         $id = self::idLimpo($passo['id'] ?? '');
         if ($id === '' || $id === 'inicio' || in_array($id, $usados, true)) {
            $id = 'p' . ($indice + 1) . substr(md5($tipo . $indice), 0, 4);
         }
         $usados[] = $id;

         $config = [];
         foreach ((array)($passo['config'] ?? []) as $chave => $valor) {
            $chave = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$chave);
            if ($chave === 'opcoes') {
               $config['opcoes'] = [];
               foreach ((array)$valor as $opcao) {
                  $rotulo = mb_substr(trim((string)($opcao['rotulo'] ?? '')), 0, 24);
                  if ($rotulo !== '') {
                     $config['opcoes'][] = ['rotulo' => $rotulo, 'ir' => self::idLimpo($opcao['ir'] ?? '')];
                  }
               }
               continue;
            }
            if ($chave !== '' && !is_array($valor)) {
               $config[$chave] = mb_substr((string)$valor, 0, 4000);
            }
         }

         $limpos[] = [
            'id'     => $id,
            'tipo'   => $tipo,
            'rotulo' => mb_substr(trim((string)($passo['rotulo'] ?? $tipos[$tipo]['rotulo'])), 0, 80),
            'config' => $config
         ];
      }

      return $limpos;
   }

   /**
    * Converte a lista de passos (versao antiga) em grafo, mantendo a mesma execucao:
    * cada passo segue para o seguinte, os desvios viram conexoes e "Ir para" some
    * (quem apontava para ele passa a apontar direto para o destino).
    */
   static function migrarLegado(array $passos): array {
      $indices = [];
      foreach ($passos as $i => $passo) {
         $indices[$passo['id']] = $i;
      }

      $resolver = function (string $id) use ($passos, $indices): string {
         $vistos = [];
         while ($id !== '' && isset($indices[$id]) && $passos[$indices[$id]]['tipo'] === 'ir_para' && !isset($vistos[$id])) {
            $vistos[$id] = true;
            $id = (string)($passos[$indices[$id]]['config']['ir_verdadeiro'] ?? '');
         }
         return isset($indices[$id]) ? $id : '';
      };

      $seguinte = function (int $i) use ($passos, $resolver): string {
         return isset($passos[$i + 1]) ? $resolver((string)$passos[$i + 1]['id']) : '';
      };

      $nos     = [['id' => 'inicio', 'tipo' => 'inicio', 'rotulo' => 'Início', 'x' => 40, 'y' => 60, 'config' => []]];
      $arestas = [];
      $ligar   = function (string $de, string $saida, string $para) use (&$arestas) {
         if ($para !== '') {
            $arestas[] = ['de' => $de, 'saida' => $saida, 'para' => $para];
         }
      };

      if (!empty($passos)) {
         $ligar('inicio', 'proximo', $resolver((string)$passos[0]['id']));
      }

      $posicao = 0;
      foreach ($passos as $i => $passo) {
         $tipo = $passo['tipo'];
         if ($tipo === 'ir_para') {
            continue;
         }

         $config = $passo['config'];
         $id     = $passo['id'];
         $proximo = $seguinte($i);
         $alvo = fn(string $campo) => ($destino = $resolver((string)($config[$campo] ?? ''))) !== '' ? $destino : $proximo;

         switch ($tipo) {
            case 'menu':
               foreach (array_values((array)($config['opcoes'] ?? [])) as $k => $opcao) {
                  $destino = $resolver((string)($opcao['ir'] ?? ''));
                  $ligar($id, 'op' . ($k + 1), $destino !== '' ? $destino : $proximo);
               }
               break;
            case 'sim_nao':
               $ligar($id, 'sim', $alvo('ir_sim'));
               $ligar($id, 'nao', $alvo('ir_nao'));
               break;
            case 'condicao':
               $ligar($id, 'verdadeiro', $alvo('ir_verdadeiro'));
               $ligar($id, 'falso', $alvo('ir_falso'));
               break;
            case 'encerrar':
               break;
            case 'conversa_tecnico':
               $ligar($id, 'falha', $proximo);
               break;
            case 'abrir_objeto':
               $ligar($id, 'proximo', $proximo);
               $ligar($id, 'erro', $proximo);
               break;
            default:
               $ligar($id, 'proximo', $proximo);
         }

         foreach (['ir_sim', 'ir_nao', 'ir_verdadeiro', 'ir_falso'] as $campo) {
            unset($config[$campo]);
         }
         if (isset($config['opcoes'])) {
            $config['opcoes'] = array_map(fn($opcao) => ['rotulo' => $opcao['rotulo']], $config['opcoes']);
         }

         // Grade de 4 colunas, na ordem em que os passos rodavam
         $nos[] = [
            'id'     => $id,
            'tipo'   => $tipo,
            'rotulo' => $passo['rotulo'],
            'x'      => 340 + ($posicao % 4) * 320,
            'y'      => 60 + intdiv($posicao, 4) * 300,
            'config' => self::normalizarConfig($tipo, $config)
         ];
         $posicao++;
      }

      return self::normalizarGrafo($nos, $arestas);
   }

   /**
    * Comparacao unica usada pela condicao e pelo "visivel se" dos fluxos convertidos
    */
   static function comparar(string $valor, string $operador, string $alvo): bool {
      $a = mb_strtolower(trim($valor));
      $b = mb_strtolower(trim($alvo));

      switch ($operador) {
         case 'diferente':
            return $a !== $b;
         case 'contem':
            return ($b !== '' && mb_strpos($a, $b) !== false);
         case 'comeca':
            return ($b !== '' && str_starts_with($a, $b));
         case 'vazio':
            return $a === '';
         case 'preenchido':
            return $a !== '';
         case 'maior':
            return (float)str_replace(',', '.', $valor) > (float)str_replace(',', '.', $alvo);
         case 'menor':
            return (float)str_replace(',', '.', $valor) < (float)str_replace(',', '.', $alvo);
      }

      return $a === $b;
   }

   /**
    * Bloco convertido de versao antiga com "visivel se": fora da condicao e pulado
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
    * Problemas que impedem o fluxo de rodar como esperado.
    * Cada aviso aponta o bloco (no) para o editor destacar.
    */
   static function validar(array $fluxo): array {
      $avisos = [];
      $mapa   = $fluxo['mapa'] ?? [];
      $ligacoes = $fluxo['ligacoes'] ?? [];
      $inicio = (string)($fluxo['inicio'] ?? '');
      $tipos  = self::tiposDePasso();

      $aviso = function (string $texto, string $no = '') use (&$avisos) {
         $avisos[] = ['texto' => $texto, 'no' => $no];
      };

      if ($inicio === '' || empty($ligacoes[$inicio]['proximo'])) {
         $aviso('O bloco Início não está ligado a nenhum bloco: o fluxo não faz nada.', $inicio);
      }

      if ((string)($fluxo['gatilho'] ?? '') === 'palavra' && trim((string)($fluxo['palavras'] ?? '')) === '') {
         $aviso('O gatilho por palavra exige pelo menos uma palavra (clique no bloco Início).', $inicio);
      }

      // Blocos que nunca serao alcancados a partir do Inicio
      $alcancados = [];
      $fila = $inicio !== '' ? [$inicio] : [];
      while ($fila) {
         $atual = array_shift($fila);
         if (isset($alcancados[$atual])) {
            continue;
         }
         $alcancados[$atual] = true;
         foreach ((array)($ligacoes[$atual] ?? []) as $para) {
            $fila[] = $para;
         }
      }

      $variaveis = ['fluxo_nome', 'numero_criado', 'objeto_criado', 'ultima_opcao', 'contato_nome', 'contato_telefone', 'cliente'];
      foreach ($mapa as $no) {
         foreach (['variavel', 'variavel_texto'] as $campo) {
            $bruta = trim((string)($no['config'][$campo] ?? ''));
            if ($bruta !== '') {
               $variaveis[] = self::nomeVariavel($bruta);
            }
         }
      }

      foreach ($mapa as $id => $no) {
         $tipo   = (string)$no['tipo'];
         $config = (array)$no['config'];
         $nome   = '"' . ($no['rotulo'] !== '' ? $no['rotulo'] : ($tipos[$tipo]['rotulo'] ?? $tipo)) . '"';

         if ($tipo === 'inicio') {
            continue;
         }

         if (!isset($alcancados[$id])) {
            $aviso('O bloco ' . $nome . ' não está ligado ao fluxo e nunca será executado.', $id);
         }

         $semTexto = trim((string)($config['texto'] ?? '')) === '';

         if ($tipo === 'mensagem' && $semTexto && trim((string)($config['midia_arquivo'] ?? '')) === '') {
            $aviso('O bloco ' . $nome . ' está sem texto e sem mídia.', $id);
         }
         if (in_array($tipo, ['pergunta', 'menu', 'sim_nao'], true) && $semTexto) {
            $aviso('O bloco ' . $nome . ' está sem o texto da pergunta.', $id);
         }
         if (in_array($tipo, ['sim_nao', 'variavel', 'condicao'], true) && trim((string)($config['variavel'] ?? '')) === '') {
            $aviso('O bloco ' . $nome . ' precisa do nome da variável.', $id);
         }
         if ($tipo === 'menu') {
            if (empty($config['opcoes'])) {
               $aviso('O menu ' . $nome . ' não tem opções.', $id);
            }
            foreach (array_values((array)($config['opcoes'] ?? [])) as $k => $opcao) {
               if (empty($ligacoes[$id]['op' . ($k + 1)])) {
                  $aviso('A opção "' . $opcao['rotulo'] . '" do menu ' . $nome . ' não leva a nenhum bloco (o fluxo termina nela).', $id);
               }
            }
         }
         if ($tipo === 'randomizador') {
            $soma = array_sum(array_map(fn($c) => (int)$c['percentual'], (array)($config['caminhos'] ?? [])));
            if ($soma !== 100) {
               $aviso('As porcentagens do randomizador ' . $nome . ' somam ' . $soma . '% (o ideal é 100%).', $id);
            }
         }
         if ($tipo === 'abrir_objeto' && trim((string)($config['variavel_titulo'] ?? '')) === '') {
            $aviso('O bloco ' . $nome . ' precisa da variável com o título.', $id);
         }
         if (in_array($tipo, ['detalhe_objeto', 'acompanhamento', 'conversa_tecnico', 'responder_validacao'], true)
             && trim((string)($config['variavel'] ?? '')) === '') {
            $aviso('O bloco ' . $nome . ' precisa da variável com o registro escolhido.', $id);
         }
         if ($tipo === 'http' && !preg_match('#^https?://#i', trim((string)($config['url'] ?? '')))) {
            $aviso('O bloco ' . $nome . ' precisa de uma URL começando com http:// ou https://.', $id);
         }
         if ($tipo === 'trocar_fluxo') {
            $destino = (int)($config['fluxo'] ?? 0);
            if ($destino <= 0) {
               $aviso('O bloco ' . $nome . ' não tem o fluxo de destino.', $id);
            } elseif ($destino === (int)($fluxo['id'] ?? 0)) {
               $aviso('O bloco ' . $nome . ' troca para o próprio fluxo (volta ao início).', $id);
            } elseif (($alvo = self::porId($destino)) === null) {
               $aviso('O bloco ' . $nome . ' aponta para um fluxo que foi removido.', $id);
            } elseif ((int)$alvo['conexoes_id'] !== (int)($fluxo['conexoes_id'] ?? 0)) {
               $aviso('O bloco ' . $nome . ' aponta para um fluxo de outro aparelho ("' . $alvo['nome'] . '"): a troca não acontece.', $id);
            }
         }
         if ($tipo === 'condicao' || $tipo === 'variavel') {
            $usada = self::nomeVariavel((string)($config['variavel'] ?? ''));
            if (trim((string)($config['variavel'] ?? '')) !== '' && $tipo === 'condicao' && !in_array($usada, $variaveis, true)) {
               $aviso('A condição ' . $nome . ' usa a variável {' . $usada . '}, que nenhum bloco preenche.', $id);
            }
         }
      }

      return $avisos;
   }

   // ============================================
   // Gatilhos
   // ============================================

   static function ativoNoSistema(): bool {
      return PluginWhatsappempresaConfig::ativo('fluxo_construtor');
   }

   static function temConteudo(array $fluxo): bool {
      return !empty($fluxo['passos']) && !empty($fluxo['ligacoes'][$fluxo['inicio']]['proximo']);
   }

   static function opcoesDeMenu(): array {
      if (!self::ativoNoSistema()) {
         return [];
      }

      $itens = [];
      foreach (self::listar(true) as $fluxo) {
         if ((string)$fluxo['gatilho'] === 'menu' && self::temConteudo($fluxo)) {
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
         if ((string)$fluxo['gatilho'] !== 'palavra' || !self::temConteudo($fluxo)) {
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
         if ((string)$fluxo['gatilho'] === 'codigo' && self::temConteudo($fluxo)) {
            return $fluxo;
         }
      }

      return null;
   }

   // ============================================
   // Execucao
   // ============================================

   static function seguir(array $fluxo, string $de, string $saida): string {
      return (string)($fluxo['ligacoes'][$de][$saida] ?? '');
   }

   static function primeiroBloco(array $fluxo): string {
      return self::seguir($fluxo, (string)$fluxo['inicio'], 'proximo');
   }

   static function iniciar(string $telefone, int $fluxos_id, int $users_id, array $contexto = []): array {
      $fluxo = self::porId($fluxos_id);

      if ($fluxo === null || (int)$fluxo['is_ativo'] !== 1 || self::primeiroBloco($fluxo) === '') {
         return ['respostas' => ['Este fluxo nao esta disponivel agora.'], 'encerrado' => true];
      }

      $contexto = array_merge(self::contextoDoCliente($telefone), $contexto);
      $contexto['fluxo_nome'] = (string)$fluxo['nome'];

      $primeiro = self::primeiroBloco($fluxo);
      self::gravarEstado($telefone, $fluxos_id, $primeiro, $contexto, $users_id);

      return self::executar($telefone, $fluxo, $primeiro, $contexto, $users_id, false, '');
   }

   /**
    * Variaveis do cliente identificado (entidade e contato), disponiveis em todos os fluxos
    */
   static function contextoDoCliente(string $telefone): array {
      if (self::$simulando || !class_exists('PluginWhatsappempresaCliente')) {
         return [];
      }

      $identidade = PluginWhatsappempresaCliente::identidadeDaSessao($telefone);
      $contexto = [];

      if ($identidade['entities_id'] > 0) {
         $contexto['cliente'] = (string)$identidade['entidade'];
      }
      if (!empty($identidade['contato'])) {
         $contexto['contato_nome']     = (string)$identidade['contato']['nome'];
         $contexto['contato_telefone'] = (string)$identidade['contato']['telefone'];
      }

      return $contexto;
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

      if ($fluxo === null || self::primeiroBloco($fluxo) === '') {
         PluginWhatsappempresaFluxo::sairFluxo($telefone, 'Fluxo montado removido');
         return ['respostas' => ['O fluxo em andamento nao existe mais.'], 'encerrado' => true];
      }

      // Bloco removido no editor enquanto o cliente respondia: recomeca o fluxo sem perder a mensagem
      if ($passo === '' || !isset($fluxo['mapa'][$passo]) || $fluxo['mapa'][$passo]['tipo'] === 'inicio') {
         return self::executar($telefone, $fluxo, self::primeiroBloco($fluxo), $contexto, $users_id, false, '');
      }

      return self::executar($telefone, $fluxo, $passo, $contexto, $users_id, true, $texto);
   }

   /**
    * Percorre os blocos pelas conexoes ate precisar de uma resposta ou terminar
    */
   static function executar(string $telefone, array $fluxo, string $atual, array $contexto, int $users_id, bool $temEntrada, string $entrada): array {
      $respostas = [];
      $voltas    = 0;

      while ($voltas < self::LIMITE_EXECUCAO) {
         $voltas++;

         if ($atual === '' || !isset($fluxo['mapa'][$atual])) {
            return self::finalizar($telefone, $respostas, $users_id, '', false);
         }

         $passo = $fluxo['mapa'][$atual];

         if ($passo['tipo'] === 'inicio') {
            $atual = self::seguir($fluxo, $atual, 'proximo');
            continue;
         }

         // Condicao nao atendida (fluxos convertidos): segue sem consumir a resposta do cliente
         if (!self::condicaoAtendida($passo, $contexto)) {
            $atual = self::seguir($fluxo, $atual, 'proximo');
            continue;
         }

         $resultado = self::executarPasso($telefone, $fluxo, $passo, $contexto, $users_id, $temEntrada, $entrada);

         $contexto   = $resultado['contexto'];
         $respostas  = array_merge($respostas, $resultado['respostas']);
         $temEntrada = false;
         $entrada    = '';

         if (!empty($resultado['aguardar'])) {
            // Bloco que espera resposta nunca pode ficar sem texto
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

         // Continua em outro fluxo, com as mesmas variaveis
         if (!empty($resultado['trocar'])) {
            $novo = self::porId((int)$resultado['trocar']);
            // Troca so dentro do mesmo numero: cada conexao tem os seus fluxos
            if ($novo !== null && (int)$novo['conexoes_id'] !== (int)$fluxo['conexoes_id']) {
               $novo = null;
            }
            if ($novo === null || (int)$novo['is_ativo'] !== 1 || self::primeiroBloco($novo) === '') {
               return self::finalizar($telefone, $respostas, $users_id, 'O atendimento seguinte nao esta disponivel agora.', false);
            }
            $fluxo = $novo;
            $contexto['fluxo_nome'] = (string)$novo['nome'];
            $atual = self::primeiroBloco($novo);
            self::gravarEstado($telefone, (int)$novo['id'], $atual, $contexto, $users_id);
            continue;
         }

         $atual = self::seguir($fluxo, (string)$passo['id'], (string)($resultado['saida'] ?? 'proximo'));
      }

      return self::finalizar($telefone, $respostas, $users_id, 'O fluxo foi interrompido por seguranca (muitos blocos seguidos sem resposta do cliente).', false);
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
   // Blocos
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

   /**
    * Bloco tem a saida ligada a outro bloco?
    */
   static function ligada(array $fluxo, array $passo, string $saida): bool {
      return self::seguir($fluxo, (string)$passo['id'], $saida) !== '';
   }

   static function passoMensagem(string $telefone, array $fluxo, array $passo, array $config, array $contexto, int $users_id, bool $temEntrada, string $entrada): array {
      $texto   = self::render((string)($config['texto'] ?? ''), $contexto);
      $arquivo = (string)($config['midia_arquivo'] ?? '');

      // Imagem ou audio anexado no editor (o texto vira legenda)
      if ($arquivo !== '' && PluginWhatsappempresaServidor::caminhoMidia($arquivo) !== null) {
         return [
            'respostas' => [[
               'texto' => $texto,
               'midia' => [
                  'tipo'    => (string)($config['midia_tipo'] ?? 'imagem') === 'audio' ? 'audio' : 'imagem',
                  'arquivo' => $arquivo,
                  'mime'    => (string)($config['midia_mime'] ?? '')
               ]
            ]],
            'contexto' => $contexto
         ];
      }

      return [
         'respostas' => trim($texto) !== '' ? [$texto] : [],
         'contexto'  => $contexto
      ];
   }

   /**
    * Confere a resposta conforme o tipo pedido. Devolve o valor limpo ou null.
    */
   static function validarResposta(string $valor, string $regra): ?string {
      $valor = trim($valor);

      switch ($regra) {
         case 'numero':
            $numero = str_replace(',', '.', preg_replace('/[^\d,.\-]/', '', $valor));
            return is_numeric($numero) ? $numero : null;
         case 'email':
            return filter_var($valor, FILTER_VALIDATE_EMAIL) ? mb_strtolower($valor) : null;
         case 'telefone':
            $digitos = preg_replace('/\D/', '', $valor);
            return strlen($digitos) >= 10 && strlen($digitos) <= 13 ? $digitos : null;
         case 'cpf_cnpj':
            $digitos = preg_replace('/\D/', '', $valor);
            return in_array(strlen($digitos), [11, 14], true) && !preg_match('/^(\d)\1+$/', $digitos) ? $digitos : null;
         case 'data':
            if (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{2,4})$#', $valor, $m)) {
               $ano = strlen($m[3]) === 2 ? 2000 + (int)$m[3] : (int)$m[3];
               if (checkdate((int)$m[2], (int)$m[1], $ano)) {
                  return sprintf('%02d/%02d/%04d', $m[1], $m[2], $ano);
               }
            }
            return null;
      }

      return $valor;
   }

   static function passoPergunta(string $telefone, array $fluxo, array $passo, array $config, array $contexto, int $users_id, bool $temEntrada, string $entrada): array {
      $variavel = self::nomeVariavel((string)($config['variavel'] ?? 'resposta'));
      $minimo   = max(1, (int)($config['minimo'] ?? 1));
      $regra    = (string)($config['validacao'] ?? 'texto');

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

      $valor = self::validarResposta($entrada, $regra);
      $erro  = trim((string)($config['erro'] ?? ''));

      if ($valor === null) {
         $explicacao = [
            'numero' => 'Envie apenas numeros.', 'email' => 'Envie um e-mail valido.',
            'telefone' => 'Envie o telefone com DDD, somente numeros.', 'cpf_cnpj' => 'Envie um CPF ou CNPJ valido.',
            'data' => 'Envie a data no formato dd/mm/aaaa.'
         ][$regra] ?? 'Resposta invalida.';

         return [
            'respostas' => [$erro !== '' ? self::render($erro, $contexto) : $explicacao],
            'contexto'  => $contexto,
            'aguardar'  => true
         ];
      }

      if (mb_strlen($valor) < $minimo) {
         return [
            'respostas' => [$erro !== '' ? self::render($erro, $contexto) : 'Texto muito curto. Envie ao menos ' . $minimo . ' caractere(s).'],
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

      return ['respostas' => [], 'contexto' => $contexto, 'saida' => $sim ? 'sim' : 'nao'];
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

      // Aceita o numero da opcao ou o proprio texto dela
      $escolha = (int)preg_replace('/\D/', '', $entrada);
      if ($escolha < 1 || !isset($opcoes[$escolha - 1])) {
         $escolha = 0;
         $digitado = PluginWhatsappempresaFluxo::normalizar($entrada);
         foreach ($opcoes as $posicao => $opcao) {
            if ($digitado !== '' && PluginWhatsappempresaFluxo::normalizar((string)$opcao['rotulo']) === $digitado) {
               $escolha = $posicao + 1;
               break;
            }
         }
      }

      if ($escolha < 1) {
         $contexto['resposta_invalida'] = mb_substr(trim($entrada), 0, 200);

         // Saida "Resposta invalida" ligada: o fluxo decide o que fazer
         if (self::ligada($fluxo, $passo, 'invalida')) {
            return ['respostas' => [], 'contexto' => $contexto, 'saida' => 'invalida'];
         }

         return [
            'respostas' => ['Opcao invalida. Toque em um dos botoes ou envie o numero da opcao.'],
            'contexto'  => $contexto,
            'aguardar'  => true
         ];
      }

      $opcao = $opcoes[$escolha - 1];
      $contexto['ultima_opcao'] = (string)$opcao['rotulo'];

      $variavel = trim((string)($config['variavel'] ?? ''));
      if ($variavel !== '') {
         $contexto[self::nomeVariavel($variavel)] = (string)$opcao['rotulo'];
      }

      return ['respostas' => [], 'contexto' => $contexto, 'saida' => 'op' . $escolha];
   }

   static function passoListarObjetos(string $telefone, array $fluxo, array $passo, array $config, array $contexto, int $users_id, bool $temEntrada, string $entrada): array {
      $objeto   = self::classeObjeto((string)($config['objeto'] ?? 'Ticket'));
      $variavel = self::nomeVariavel((string)($config['variavel'] ?? 'item'));
      $chaveMapa = $variavel . '_mapa';

      if (!$temEntrada) {
         $itens = self::itensDoUsuario($objeto, $users_id, !empty($config['somente_abertos']));

         if (empty($itens)) {
            $aviso = trim((string)($config['texto_vazio'] ?? ''));
            $aviso = $aviso !== '' ? self::render($aviso, $contexto) : 'Nao encontrei nenhum registro seu de ' . strtolower(self::objetos()[$objeto]) . '.';

            if (self::ligada($fluxo, $passo, 'vazio')) {
               return ['respostas' => [$aviso], 'contexto' => $contexto, 'saida' => 'vazio'];
            }
            return ['respostas' => [], 'contexto' => $contexto, 'fim' => true, 'texto_fim' => $aviso];
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
         return ['respostas' => ['Nao consegui montar o registro: o titulo ficou vazio.'], 'contexto' => $contexto, 'saida' => 'erro'];
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
         return ['respostas' => ['Nao foi possivel registrar. Procure o suporte.'], 'contexto' => $contexto, 'saida' => 'erro'];
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
            $aviso = trim((string)($config['texto_vazio'] ?? ''));
            $aviso = $aviso !== '' ? self::render($aviso, $contexto) : 'Voce nao possui validacoes pendentes.';

            if (self::ligada($fluxo, $passo, 'vazio')) {
               return ['respostas' => [$aviso], 'contexto' => $contexto, 'saida' => 'vazio'];
            }
            return ['respostas' => [], 'contexto' => $contexto, 'fim' => true, 'texto_fim' => $aviso];
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
         return ['respostas' => ['Nenhum chamado selecionado para a conversa.'], 'contexto' => $contexto, 'saida' => 'falha'];
      }

      if (self::$simulando) {
         return [
            'respostas' => ['[simulacao] a conversa com o tecnico do chamado #' . $tickets_id . ' seria aberta aqui.'],
            'contexto'  => $contexto,
            'fim'       => true
         ];
      }

      $respostas = PluginWhatsappempresaFluxo::iniciarConversa($telefone, $users_id, $tickets_id);
      $sessao    = PluginWhatsappempresaFluxo::obterSessao($telefone);

      if (PluginWhatsappempresaFluxo::fluxoAtual($sessao) !== PluginWhatsappempresaFluxo::FLUXO_CONVERSA) {
         // Sem tecnico no chamado: a mensagem de iniciarConversa ja traz o menu; o fluxo decide o resto
         return ['respostas' => array_slice($respostas, 0, 1), 'contexto' => $contexto, 'saida' => 'falha'];
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

      $verdade = self::comparar($valor, $operador, self::render($alvo, $contexto));

      return ['respostas' => [], 'contexto' => $contexto, 'saida' => $verdade ? 'verdadeiro' : 'falso'];
   }

   static function passoVariavel(string $telefone, array $fluxo, array $passo, array $config, array $contexto, int $users_id, bool $temEntrada, string $entrada): array {
      $nome = trim((string)($config['variavel'] ?? ''));
      if ($nome !== '') {
         $contexto[self::nomeVariavel($nome)] = mb_substr(self::render((string)($config['valor'] ?? ''), $contexto), 0, 2000);
      }
      return ['respostas' => [], 'contexto' => $contexto];
   }

   /**
    * Pausa antes do proximo bloco: o servidor espera antes de enviar as mensagens seguintes
    */
   static function passoIntervalo(string $telefone, array $fluxo, array $passo, array $config, array $contexto, int $users_id, bool $temEntrada, string $entrada): array {
      $segundos = max(1, min(60, (int)($config['segundos'] ?? 3)));
      return ['respostas' => [['atraso' => $segundos]], 'contexto' => $contexto];
   }

   static function passoRandomizador(string $telefone, array $fluxo, array $passo, array $config, array $contexto, int $users_id, bool $temEntrada, string $entrada): array {
      $caminhos = array_values((array)($config['caminhos'] ?? []));
      $total    = array_sum(array_map(fn($c) => max(0, (int)$c['percentual']), $caminhos));

      if ($total <= 0) {
         return ['respostas' => [], 'contexto' => $contexto, 'saida' => 'r1'];
      }

      $sorteio = random_int(1, $total);
      $acumulado = 0;
      foreach ($caminhos as $i => $caminho) {
         $acumulado += max(0, (int)$caminho['percentual']);
         if ($sorteio <= $acumulado) {
            $contexto['caminho_sorteado'] = $i + 1;
            return ['respostas' => [], 'contexto' => $contexto, 'saida' => 'r' . ($i + 1)];
         }
      }

      return ['respostas' => [], 'contexto' => $contexto, 'saida' => 'r' . count($caminhos)];
   }

   /**
    * Expediente pelo calendario do GLPI (considera feriados) ou pelos dias e horas do bloco
    */
   static function dentroDoExpediente(array $config, ?int $momento = null): bool {
      $momento = $momento ?? time();
      $calendario = (int)($config['calendario'] ?? 0);

      if ($calendario > 0) {
         $cal = new Calendar();
         if ($cal->getFromDB($calendario)) {
            return (bool)$cal->isAWorkingHour($momento);
         }
      }

      $dias = array_filter(array_map('intval', explode(',', (string)($config['dias'] ?? '1,2,3,4,5'))));
      if (!in_array((int)date('N', $momento), $dias, true)) {
         return false;
      }

      $inicio = (string)($config['hora_inicio'] ?? '08:00') ?: '08:00';
      $fim    = (string)($config['hora_fim'] ?? '18:00') ?: '18:00';
      $agora  = date('H:i', $momento);

      return $fim > $inicio
         ? ($agora >= $inicio && $agora < $fim)
         : ($agora >= $inicio || $agora < $fim); // expediente que vira a meia-noite
   }

   static function passoHorario(string $telefone, array $fluxo, array $passo, array $config, array $contexto, int $users_id, bool $temEntrada, string $entrada): array {
      return ['respostas' => [], 'contexto' => $contexto, 'saida' => self::dentroDoExpediente($config) ? 'dentro' : 'fora'];
   }

   /**
    * Le um valor do JSON pelo caminho com pontos (dados.itens.0.nome)
    */
   static function valorDoCaminho($dados, string $caminho) {
      foreach (explode('.', trim($caminho)) as $parte) {
         if ($parte === '') {
            continue;
         }
         if (is_array($dados) && array_key_exists($parte, $dados)) {
            $dados = $dados[$parte];
         } else {
            return null;
         }
      }
      return $dados;
   }

   static function passoHttp(string $telefone, array $fluxo, array $passo, array $config, array $contexto, int $users_id, bool $temEntrada, string $entrada): array {
      $metodo   = strtoupper((string)($config['metodo'] ?? 'GET'));
      $metodo   = in_array($metodo, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true) ? $metodo : 'GET';
      $url      = trim(self::render((string)($config['url'] ?? ''), $contexto));
      $variavel = self::nomeVariavel((string)($config['variavel'] ?? 'http') ?: 'http');
      $limite   = max(1, min(30, (int)($config['tempo_limite'] ?? 10)));

      if (!preg_match('#^https?://#i', $url)) {
         $contexto[$variavel . '_status'] = 0;
         return ['respostas' => [], 'contexto' => $contexto, 'saida' => 'erro'];
      }

      // No teste do editor so GET e executado de verdade (POST/PUT/DELETE poderiam alterar dados)
      if (self::$simulando && $metodo !== 'GET') {
         $contexto[$variavel] = '';
         $contexto[$variavel . '_status'] = 200;
         return ['respostas' => ['[simulacao] ' . $metodo . ' ' . $url . ' nao foi enviado no teste.'], 'contexto' => $contexto, 'saida' => 'sucesso'];
      }

      $cabecalhos = [];
      foreach (preg_split('/\r?\n/', (string)($config['cabecalhos'] ?? '')) as $linha) {
         $linha = trim(self::render($linha, $contexto));
         if ($linha !== '' && str_contains($linha, ':')) {
            $cabecalhos[] = $linha;
         }
      }

      $corpo = self::render((string)($config['corpo'] ?? ''), $contexto);
      if ($corpo !== '' && !preg_grep('/^content-type:/i', $cabecalhos)) {
         $cabecalhos[] = 'Content-Type: ' . (json_decode($corpo) !== null ? 'application/json' : 'text/plain');
      }

      $curl = curl_init($url);
      curl_setopt_array($curl, [
         CURLOPT_CUSTOMREQUEST  => $metodo,
         CURLOPT_RETURNTRANSFER => true,
         CURLOPT_HTTPHEADER     => $cabecalhos,
         CURLOPT_TIMEOUT        => $limite,
         CURLOPT_CONNECTTIMEOUT => min(10, $limite),
         CURLOPT_FOLLOWLOCATION => true,
         CURLOPT_MAXREDIRS      => 3,
         CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
         CURLOPT_USERAGENT      => 'GLPI-whatsappempresa'
      ]);
      if ($corpo !== '' && $metodo !== 'GET') {
         curl_setopt($curl, CURLOPT_POSTFIELDS, $corpo);
      }

      $resposta = curl_exec($curl);
      $status   = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
      $falha    = curl_error($curl);
      curl_close($curl);

      $texto = is_string($resposta) ? mb_substr($resposta, 0, 2000) : '';
      $contexto[$variavel] = $texto;
      $contexto[$variavel . '_status'] = $status;

      // Campos do JSON viram variaveis: "nome = dados.cliente.nome"
      $json = is_string($resposta) ? json_decode($resposta, true) : null;
      if (is_array($json)) {
         foreach (preg_split('/\r?\n/', (string)($config['mapeamentos'] ?? '')) as $linha) {
            if (!str_contains($linha, '=')) {
               continue;
            }
            [$nome, $caminho] = array_map('trim', explode('=', $linha, 2));
            $valor = self::valorDoCaminho($json, $caminho);
            if ($nome !== '' && $valor !== null) {
               $contexto[self::nomeVariavel($nome)] = is_scalar($valor) ? mb_substr((string)$valor, 0, 2000) : json_encode($valor, JSON_UNESCAPED_UNICODE);
            }
         }
      }

      $ok = ($falha === '' && $status >= 200 && $status < 300);

      if (!$ok && !self::$simulando && PluginWhatsappempresaConfig::ativo('log_detalhado')) {
         PluginWhatsappempresaLog::registrar(
            'Requisicao HTTP do fluxo falhou',
            'Fluxo ' . $fluxo['nome'] . ' - ' . $metodo . ' ' . $url . ' - HTTP ' . $status . ($falha !== '' ? ' - ' . $falha : ''),
            'aviso',
            'construtor'
         );
      }

      return ['respostas' => [], 'contexto' => $contexto, 'saida' => $ok ? 'sucesso' : 'erro'];
   }

   static function passoTrocarFluxo(string $telefone, array $fluxo, array $passo, array $config, array $contexto, int $users_id, bool $temEntrada, string $entrada): array {
      $destino = (int)($config['fluxo'] ?? 0);
      if ($destino <= 0) {
         return ['respostas' => [], 'contexto' => $contexto, 'fim' => true];
      }
      return ['respostas' => [], 'contexto' => $contexto, 'trocar' => $destino];
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

      // Cliente identificado pelo codigo/contato: o registro nasce na entidade dele
      $identidade  = PluginWhatsappempresaCliente::identidadeDaSessao($telefone);
      $entities_id = (int)$identidade['entities_id'];

      if ($entities_id <= 0) {
         foreach ($DB->request([
            'SELECT' => ['entities_id'],
            'FROM'   => 'glpi_users',
            'WHERE'  => ['id' => $users_id],
            'LIMIT'  => 1
         ]) as $linha) {
            $entities_id = (int)$linha['entities_id'];
         }
      }

      PluginWhatsappempresaFluxo::assumirUsuario($users_id);
      PluginWhatsappempresaFluxo::incluirEntidadeNaSessao($entities_id);

      $dados = [
         'name'                => mb_substr($titulo, 0, 250),
         'content'             => nl2br($conteudo . "\n\n" . PluginWhatsappempresaCliente::assinatura($telefone, $identidade)),
         'entities_id'         => $entities_id,
         'users_id_recipient'  => $users_id,
         '_users_id_requester' => $users_id,
         'urgency'             => (int)($config['urgencia'] ?? PluginWhatsappempresaConfig::get('abertura_urgencia', '3')),
         'status'              => CommonITILObject::INCOMING
      ];

      // Categoria escolhida no bloco; sem ela, a do codigo/cliente do autoatendimento
      $parametros = PluginWhatsappempresaCliente::parametrosAbertura($telefone);
      $categoria = (int)($config['categoria'] ?? 0) ?: (int)$parametros['categoria'];
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

      // Unidade e Setor do cliente/codigo nos campos adicionais do plugin Botoes
      if ($novo) {
         PluginWhatsappempresaCliente::gravarCamposBotoes($classe, (int)$novo, $parametros, $telefone, $identidade);
      }

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

      $calendarios = [];
      foreach ($DB->request([
         'SELECT' => ['id', 'name'],
         'FROM'   => 'glpi_calendars',
         'ORDER'  => 'name ASC',
         'LIMIT'  => 100
      ]) as $linha) {
         $calendarios[] = ['id' => (int)$linha['id'], 'nome' => (string)$linha['name']];
      }

      // Todos os fluxos com a conexao de cada um: o editor mostra so os da conexao aberta
      $fluxos = [];
      foreach ($DB->request([
         'SELECT' => ['id', 'nome', 'is_ativo', 'conexoes_id'],
         'FROM'   => self::TABELA,
         'WHERE'  => ['is_deleted' => 0],
         'ORDER'  => ['ordem ASC', 'id ASC']
      ]) as $linha) {
         $fluxos[] = [
            'id'          => (int)$linha['id'],
            'nome'        => (string)$linha['nome'] . ((int)$linha['is_ativo'] === 1 ? '' : ' (inativo)'),
            'conexoes_id' => (int)$linha['conexoes_id']
         ];
      }

      $objetos = [];
      foreach (self::objetos() as $id => $nome) {
         $objetos[] = ['id' => $id, 'nome' => $nome];
      }

      return [
         'tipos_passo'   => self::tiposDePasso(),
         'grupos_blocos' => self::grupos(),
         'rotulos_saida' => self::rotulosSaida(),
         'gatilhos'      => self::gatilhos(),
         'objetos'       => $objetos,
         'categorias'    => $categorias,
         'slas'          => $slas,
         'grupos'        => $grupos,
         'tipos'         => $tipos,
         'urgencias'     => $urgencias,
         'calendarios'   => $calendarios,
         'fluxos'        => $fluxos,
         'conexoes'      => PluginWhatsappempresaConexao::opcoes()
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

      if ($fluxo === null || self::primeiroBloco($fluxo) === '') {
         return ['ok' => false, 'mensagem' => 'Ligue o bloco Início a outro bloco para testar.', 'roteiro' => []];
      }

      self::$simulando      = true;
      self::$estadoSimulado = [];

      $telefone = '0000000000';
      $roteiro  = [];

      try {
         $contexto = ['fluxo_nome' => (string)$fluxo['nome']];
         $primeiro = self::primeiroBloco($fluxo);

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

            // Troca de fluxo durante o teste: continua no fluxo que ficou ativo
            if ((int)($estado['fluxos_id'] ?? $fluxo['id']) !== (int)$fluxo['id']) {
               $fluxo = self::porId((int)$estado['fluxos_id']) ?? $fluxo;
            }

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
         if (is_array($resposta) && isset($resposta['atraso'])) {
            $itens[] = ['quem' => 'aviso', 'texto' => 'Intervalo de ' . (int)$resposta['atraso'] . ' segundo(s)'];
            continue;
         }

         $texto = PluginWhatsappempresaMensagem::textoDaResposta($resposta);

         if (is_array($resposta) && !empty($resposta['midia'])) {
            $rotulo = ($resposta['midia']['tipo'] ?? '') === 'audio' ? '[audio]' : '[imagem]';
            $texto  = trim($rotulo . ' ' . $texto);
         }

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
