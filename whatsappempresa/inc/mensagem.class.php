<?php

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access this file directly");
}

class PluginWhatsappempresaMensagem extends CommonDBTM {

   use PluginWhatsappempresaListaTrait;

   const ROTULOS = [
      'direcao'      => ['entrada' => 'Recebida', 'saida' => 'Enviada'],
      'origem_tipo'  => ['automacao' => 'Automacao do fluxo', 'humano' => 'Pessoa', 'sistema' => 'Sistema e testes'],
      'status_envio' => ['ok' => 'Entregue', 'pendente' => 'Pendente', 'erro' => 'Falhou'],
      'fluxo'        => [
         'menu' => 'Autoatendimento', 'construtor' => 'Fluxo montado', 'aprovacao' => 'Aprovacao',
         'conversa' => 'Conversa', 'aviso' => 'Aviso automatico', 'teste' => 'Teste'
      ]
   ];

   /** A tabela usa o plural em portugues */
   static function getTable($classname = null): string {
      return 'glpi_plugin_whatsappempresa_mensagens';
   }

   static function getTypeName($nb = 0): string {
      return $nb > 1 ? 'Mensagens do WhatsApp' : 'Mensagem do WhatsApp';
   }

   static function getIcon() {
      return 'ti ti-messages';
   }

   function rawSearchOptions() {
      $t = self::getTable();
      return [
         ['id' => 'common', 'name' => self::getTypeName(1)],
         ['id' => 1, 'table' => $t, 'field' => 'conteudo', 'name' => 'Conteudo', 'datatype' => 'text', 'massiveaction' => false],
         ['id' => 2, 'table' => $t, 'field' => 'id', 'name' => 'ID', 'datatype' => 'number', 'massiveaction' => false],
         ['id' => 3, 'table' => $t, 'field' => 'date_creation', 'name' => 'Data', 'datatype' => 'datetime', 'massiveaction' => false],
         ['id' => 4, 'table' => $t, 'field' => 'direcao', 'name' => 'Direcao', 'datatype' => 'specific', 'searchtype' => ['equals', 'notequals'], 'massiveaction' => false],
         ['id' => 5, 'table' => $t, 'field' => 'numero_cliente', 'name' => 'Numero do cliente', 'datatype' => 'string', 'massiveaction' => false],
         ['id' => 6, 'table' => $t, 'field' => 'origem_tipo', 'name' => 'Tipo', 'datatype' => 'specific', 'searchtype' => ['equals', 'notequals'], 'massiveaction' => false],
         ['id' => 7, 'table' => $t, 'field' => 'fluxo', 'name' => 'Fluxo', 'datatype' => 'specific', 'searchtype' => ['equals', 'notequals'], 'massiveaction' => false],
         ['id' => 8, 'table' => $t, 'field' => 'status_envio', 'name' => 'Entrega', 'datatype' => 'specific', 'searchtype' => ['equals', 'notequals'], 'massiveaction' => false],
         ['id' => 9, 'table' => $t, 'field' => 'erro', 'name' => 'Erro de envio', 'datatype' => 'string', 'massiveaction' => false],
         self::opcaoChamado(10),
         self::opcaoUsuario(11, 'users_id', 'Usuario'),
         ['id' => 12, 'table' => $t, 'field' => 'remetente', 'name' => 'De', 'datatype' => 'string', 'massiveaction' => false],
         ['id' => 13, 'table' => $t, 'field' => 'destinatario', 'name' => 'Para', 'datatype' => 'string', 'massiveaction' => false],
         ['id' => 14, 'table' => $t, 'field' => 'numero_host', 'name' => 'Numero do aparelho', 'datatype' => 'string', 'massiveaction' => false]
      ];
   }

   /**
    * Monta uma resposta com botoes reais no WhatsApp.
    * Ate 3 opcoes viram botoes, acima disso vira lista selecionavel.
    */
   static function comOpcoes(string $texto, array $opcoes, string $rodape = '', string $titulo = 'Escolher opcao'): array {
      $resposta = ['texto' => $texto];

      if ($rodape !== '') {
         $resposta['rodape'] = $rodape;
      }

      $limpas = [];
      foreach ($opcoes as $opcao) {
         $id     = trim((string)($opcao['id'] ?? ''));
         $rotulo = trim((string)($opcao['rotulo'] ?? ''));
         if ($id === '' || $rotulo === '') {
            continue;
         }
         $limite = PluginWhatsappempresaConfig::ativo('botoes_whatsapp') ? 24 : 70;

         $limpas[] = [
            'id'        => $id,
            'rotulo'    => mb_substr($rotulo, 0, $limite),
            'descricao' => mb_substr((string)($opcao['descricao'] ?? ''), 0, 72)
         ];
      }

      if (empty($limpas)) {
         return $resposta;
      }

      if (!PluginWhatsappempresaConfig::ativo('botoes_whatsapp')) {
         $linhas = [$texto];

         foreach ($limpas as $opcao) {
            $linha = '*' . $opcao['id'] . '* - ' . $opcao['rotulo'];
            if ($opcao['descricao'] !== '') {
               $linha .= "\n    " . $opcao['descricao'];
            }
            $linhas[] = $linha;
         }

         if ($rodape !== '') {
            $linhas[] = $rodape;
         }

         return ['texto' => implode("\n", $linhas)];
      }

      if (count($limpas) <= 3) {
         $resposta['botoes'] = $limpas;
      } else {
         $resposta['lista'] = ['titulo' => mb_substr($titulo, 0, 24), 'itens' => array_slice($limpas, 0, 10)];
      }

      return $resposta;
   }

   /**
    * Texto puro de uma resposta que pode ser string ou estrutura com botoes
    */
   static function textoDaResposta($resposta): string {
      if (is_array($resposta)) {
         $texto = (string)($resposta['texto'] ?? '');

         $rotulos = [];
         foreach ((array)($resposta['botoes'] ?? []) as $botao) {
            $rotulos[] = $botao['id'] . ') ' . $botao['rotulo'];
         }
         foreach ((array)($resposta['lista']['itens'] ?? []) as $item) {
            $rotulos[] = $item['id'] . ') ' . $item['rotulo'];
         }

         if (!empty($rotulos)) {
            $texto .= "\n" . implode(' | ', $rotulos);
         }

         return trim($texto);
      }

      return trim((string)$resposta);
   }

   /**
    * Classifica a mensagem entre automacao do fluxo, pessoa real e sistema
    */
   static function tipoDeOrigem(array $dados): string {
      if (!empty($dados['origem_tipo'])) {
         return (string)$dados['origem_tipo'];
      }

      $direcao = (string)($dados['direcao'] ?? 'entrada');
      $fluxo   = (string)($dados['fluxo'] ?? 'menu');

      // Quem escreve para o servidor e sempre uma pessoa real
      if ($direcao === 'entrada') {
         return 'humano';
      }

      if ($fluxo === 'teste') {
         return 'sistema';
      }

      // Conversa com atendente identificado e mensagem real digitada por ele
      if ($fluxo === 'conversa' && (int)($dados['users_id'] ?? 0) > 0) {
         return 'humano';
      }

      return 'automacao';
   }

   // Texto usado no lugar da legenda quando a midia chega sem ela (os fluxos precisam de texto)
   const ROTULO_IMAGEM = '📷 Imagem';
   const ROTULO_AUDIO  = '🎤 Áudio';

   /**
    * Midia da mensagem recebida que esta sendo processada pelo webhook.
    * A primeira mensagem de entrada gravada durante o processamento fica com ela.
    */
   private static ?array $midiaRecebida = null;

   static function definirMidiaRecebida(?array $midia): void {
      self::$midiaRecebida = $midia;
   }

   /**
    * Id do WhatsApp e citacao da mensagem recebida em processamento (entram na primeira mensagem de entrada gravada)
    */
   private static ?array $extrasRecebida = null;

   static function definirExtrasRecebida(?array $extras): void {
      self::$extrasRecebida = $extras;
   }

   /**
    * Mensagem gravada com o id do WhatsApp informado (a mais recente)
    */
   static function porWaId(string $wa_id): ?array {
      global $DB;
      if ($wa_id === '') {
         return null;
      }
      foreach ($DB->request([
         'FROM'  => 'glpi_plugin_whatsappempresa_mensagens',
         'WHERE' => ['wa_id' => $wa_id],
         'ORDER' => 'id DESC',
         'LIMIT' => 1
      ]) as $linha) {
         return $linha;
      }
      return null;
   }

   /**
    * Texto curto de uma mensagem gravada, usado na citacao
    */
   static function resumoParaCitacao(array $linha): string {
      $texto = trim(strip_tags((string)($linha['conteudo'] ?? '')));
      if (!empty($linha['tipo_midia'])) {
         $legenda = self::legenda($linha);
         $texto = self::rotuloDaMidia((string)$linha['tipo_midia']) . ($legenda !== '' ? ' ' . $legenda : '');
      }
      return mb_substr($texto, 0, 500);
   }

   /**
    * Reacao recebida do WhatsApp (emoji vazio = reacao removida)
    */
   static function registrarReacao(string $wa_id, string $emoji, bool $doCliente = true): bool {
      global $DB;
      $alvo = self::porWaId($wa_id);
      if ($alvo === null) {
         return false;
      }
      $DB->update('glpi_plugin_whatsappempresa_mensagens', [
         $doCliente ? 'reacao_cliente' : 'reacao_atendente' => $emoji !== '' ? mb_substr($emoji, 0, 16) : null
      ], ['id' => (int)$alvo['id']]);
      return true;
   }

   static function rotuloDaMidia(string $tipo): string {
      return $tipo === 'audio' ? self::ROTULO_AUDIO : self::ROTULO_IMAGEM;
   }

   /**
    * Legenda real da midia (vazia quando o conteudo e so o rotulo automatico)
    */
   static function legenda(array $linha): string {
      $texto = (string)($linha['conteudo'] ?? '');
      if (!empty($linha['tipo_midia']) && in_array($texto, [self::ROTULO_IMAGEM, self::ROTULO_AUDIO], true)) {
         return '';
      }
      return $texto;
   }

   // ============================================
   // Aba Mensagens: todas as mensagens em tempo real
   // ============================================

   const TIPOS_HISTORICO = [
      'fluxo'           => 'Fluxo',
      'servidor'        => 'Servidor',
      'usuario'         => 'Usuário do GLPI',
      'contato_glpi'    => 'Contato do GLPI',
      'contato_cliente' => 'Contato de cliente',
      'desconhecido'    => 'Não identificado'
   ];

   const ROTULOS_FLUXO_INTERNO = [
      'menu' => 'menu de atendimento', 'conversa' => 'conversa com técnico', 'aprovacao' => 'aprovação',
      'silenciado' => 'número silenciado', 'construtor' => 'fluxo montado', 'teste' => 'teste', 'notificacao' => 'aviso de chamado'
   ];

   private static array $cacheQuem = [];

   /**
    * Quem e o dono do numero: usuario do GLPI, contato de cliente (aba Clientes) ou contato do GLPI
    *
    * @return array{tipo:string, nome:string}
    */
   static function quemEhONumero(string $telefone): array {
      global $DB;

      $chave = PluginWhatsappempresaConfig::chaveTelefone($telefone);
      if ($chave === '') {
         return ['tipo' => 'desconhecido', 'nome' => ''];
      }
      if (isset(self::$cacheQuem[$chave])) {
         return self::$cacheQuem[$chave];
      }

      $quem = ['tipo' => 'desconhecido', 'nome' => ''];

      $users_id = PluginWhatsappempresaConfig::usuarioPorTelefone($telefone);
      if ($users_id > 0) {
         $quem = ['tipo' => 'usuario', 'nome' => PluginWhatsappempresaConfig::nomeUsuario($users_id)];
      } elseif (($contato = PluginWhatsappempresaCliente::contatoPorTelefone($telefone)) !== null) {
         $quem = ['tipo' => 'contato_cliente', 'nome' => $contato['nome'] . ' (' . PluginWhatsappempresaCliente::nomeEntidade((int)$contato['entities_id']) . ')'];
      } else {
         foreach ($DB->request([
            'SELECT' => ['name', 'firstname', 'phone', 'phone2', 'mobile'],
            'FROM'   => 'glpi_contacts',
            'WHERE'  => [
               'is_deleted' => 0,
               'OR' => [
                  ['phone' => ['LIKE', '%' . $chave]], ['phone2' => ['LIKE', '%' . $chave]], ['mobile' => ['LIKE', '%' . $chave]]
               ]
            ],
            'LIMIT' => 5
         ]) as $linha) {
            foreach (['mobile', 'phone', 'phone2'] as $campo) {
               if (PluginWhatsappempresaConfig::chaveTelefone((string)$linha[$campo]) === $chave) {
                  $quem = ['tipo' => 'contato_glpi', 'nome' => trim(($linha['firstname'] ?? '') . ' ' . $linha['name'])];
                  break 2;
               }
            }
         }
      }

      return self::$cacheQuem[$chave] = $quem;
   }

   /**
    * Mensagens para a tabela ao vivo, ja classificadas.
    * depois: so mensagens novas (id maior); antes: paginas mais antigas (id menor)
    */
   static function historicoAoVivo(array $filtros, int $depois = 0, int $antes = 0, int $limite = 100): array {
      global $DB, $CFG_GLPI;

      $where = [];
      if ($depois > 0) {
         $where[] = ['id' => ['>', $depois]];
      }
      if ($antes > 0) {
         $where[] = ['id' => ['<', $antes]];
      }
      if (in_array((string)($filtros['direcao'] ?? ''), ['entrada', 'saida'], true)) {
         $where['direcao'] = (string)$filtros['direcao'];
      }
      if (!empty($filtros['com_chamado'])) {
         $where[] = ['tickets_id' => ['>', 0]];
      }
      $busca = trim((string)($filtros['busca'] ?? ''));
      if ($busca !== '') {
         $numero = PluginWhatsappempresaConfig::limparTelefone($busca);
         $ou = [['conteudo' => ['LIKE', '%' . $busca . '%']]];
         if (strlen($numero) >= 4) {
            $ou[] = ['telefone' => ['LIKE', '%' . $numero . '%']];
            $ou[] = ['remetente' => ['LIKE', '%' . $numero . '%']];
         }
         if (ctype_digit(ltrim($busca, '#'))) {
            $ou[] = ['tickets_id' => (int)ltrim($busca, '#')];
         }
         $where[] = ['OR' => $ou];
      }

      // Tipo e calculado (quem e o numero): filtra depois, buscando um pouco mais
      $tipoFiltro = array_key_exists((string)($filtros['tipo'] ?? ''), self::TIPOS_HISTORICO) ? (string)$filtros['tipo'] : '';

      $fluxos = [];
      foreach ($DB->request(['SELECT' => ['id', 'nome'], 'FROM' => 'glpi_plugin_whatsappempresa_fluxos']) as $f) {
         $fluxos[(int)$f['id']] = (string)$f['nome'];
      }

      $host = PluginWhatsappempresaServidor::numeroHost();
      $itens = [];
      $ultimoLido = 0;
      $maiorLido = 0;

      foreach ($DB->request([
         'FROM'  => 'glpi_plugin_whatsappempresa_mensagens',
         'WHERE' => $where,
         'ORDER' => 'id DESC',
         'LIMIT' => $tipoFiltro !== '' ? $limite * 4 : $limite
      ]) as $linha) {
         $ultimoLido = (int)$linha['id'];
         $maiorLido  = max($maiorLido, (int)$linha['id']);
         $entrada  = ($linha['direcao'] === 'entrada');
         $cliente  = (string)($linha['numero_cliente'] ?: $linha['telefone']);
         $servidor = (string)($linha['numero_host'] ?: $host);
         $pessoa   = self::quemEhONumero($cliente);
         $fluxoInterno = (string)$linha['fluxo'];
         $fluxos_id = (int)($linha['fluxos_id'] ?? 0);

         if ($entrada) {
            $tipo = $pessoa['tipo'];
            $autor = $pessoa['nome'];
            $detalhe = self::ROTULOS_FLUXO_INTERNO[$fluxoInterno] ?? $fluxoInterno;
            if ($fluxos_id > 0 && isset($fluxos[$fluxos_id])) {
               $detalhe = 'respondendo o fluxo ' . $fluxos[$fluxos_id];
            }
         } elseif ((string)$linha['origem_tipo'] === 'humano' && (int)$linha['users_id'] > 0) {
            $tipo = 'usuario';
            $autor = PluginWhatsappempresaConfig::nomeUsuario((int)$linha['users_id']);
            $detalhe = 'digitada no GLPI';
         } elseif ($fluxoInterno === 'construtor' || $fluxos_id > 0) {
            $tipo = 'fluxo';
            $autor = $fluxos[$fluxos_id] ?? 'fluxo removido';
            $detalhe = 'fluxo ' . $autor;
         } else {
            $tipo = 'servidor';
            $autor = 'Atendimento automático';
            $detalhe = self::ROTULOS_FLUXO_INTERNO[$fluxoInterno] ?? $fluxoInterno;
         }

         if ($tipoFiltro !== '' && $tipo !== $tipoFiltro) {
            continue;
         }

         $texto = !empty($linha['tipo_midia']) ? self::resumoParaCitacao($linha) : trim(strip_tags((string)$linha['conteudo']));

         $itens[] = [
            'id'        => (int)$linha['id'],
            'data'      => Html::convDateTime($linha['date_creation']),
            'direcao'   => $entrada ? 'entrada' : 'saida',
            'de'        => $entrada ? $cliente : $servidor,
            'de_nome'   => $entrada ? $pessoa['nome'] : $autor,
            'para'      => $entrada ? $servidor : $cliente,
            'para_nome' => $entrada ? 'WhatsApp da empresa' : $pessoa['nome'],
            'tipo'      => $tipo,
            'tipo_nome' => self::TIPOS_HISTORICO[$tipo],
            'detalhe'   => $detalhe,
            'tickets_id' => (int)$linha['tickets_id'],
            'ticket_url' => (int)$linha['tickets_id'] > 0 ? $CFG_GLPI['root_doc'] . '/front/ticket.form.php?id=' . (int)$linha['tickets_id'] : '',
            'texto'     => mb_substr($texto, 0, 400),
            'midia'     => (string)($linha['tipo_midia'] ?? ''),
            'citada'    => trim((string)($linha['citada_texto'] ?? '')) !== '' ? mb_substr((string)$linha['citada_texto'], 0, 120) : '',
            'reacoes'   => trim(($linha['reacao_cliente'] ?? '') . ' ' . ($linha['reacao_atendente'] ?? '')),
            'status'    => (string)$linha['status_envio'],
            'erro'      => (string)($linha['erro'] ?? '')
         ];

         if (count($itens) >= $limite) {
            break;
         }
      }

      return ['itens' => $itens, 'ultimo_lido' => $ultimoLido, 'maior_lido' => $maiorLido];
   }

   /**
    * Grava a mensagem na tabela de historico
    */
   static function registrar(array $dados): int {
      global $DB;

      $cliente = PluginWhatsappempresaConfig::limparTelefone((string)($dados['telefone'] ?? ''));
      $host    = PluginWhatsappempresaServidor::numeroHost();
      $direcao = (string)($dados['direcao'] ?? 'entrada');
      $entrada = ($direcao === 'entrada');

      $midia = $dados['midia'] ?? null;
      if ($midia === null && $entrada && self::$midiaRecebida !== null) {
         $midia = self::$midiaRecebida;
         self::$midiaRecebida = null;
      }

      // Id do WhatsApp e citacao: vindos do envio (saida) ou da mensagem recebida (entrada)
      $wa_id       = (string)($dados['wa_id'] ?? '');
      $citadaWa    = (string)($dados['citada_wa'] ?? '');
      $citadaTexto = (string)($dados['citada_texto'] ?? '');
      $citada_id   = (int)($dados['citada_id'] ?? 0);
      if ($entrada && self::$extrasRecebida !== null) {
         $wa_id       = $wa_id ?: (string)(self::$extrasRecebida['wa_id'] ?? '');
         $citadaWa    = $citadaWa ?: (string)(self::$extrasRecebida['citada']['wa_id'] ?? '');
         $citadaTexto = $citadaTexto ?: (string)(self::$extrasRecebida['citada']['texto'] ?? '');
         self::$extrasRecebida = null;
      }
      if ($citada_id <= 0 && $citadaWa !== '') {
         $citada = self::porWaId($citadaWa);
         if ($citada !== null) {
            $citada_id = (int)$citada['id'];
            if ($citadaTexto === '') {
               $citadaTexto = self::resumoParaCitacao($citada);
            }
         }
      }

      $DB->insert('glpi_plugin_whatsappempresa_mensagens', [
         'wa_id'          => $wa_id !== '' ? mb_substr($wa_id, 0, 80) : null,
         'citada_id'      => $citada_id,
         'citada_texto'   => $citadaTexto !== '' ? mb_substr($citadaTexto, 0, 500) : null,
         'tipo_midia'     => $midia !== null ? mb_substr((string)$midia['tipo'], 0, 10) : null,
         'midia_arquivo'  => $midia !== null ? mb_substr((string)$midia['arquivo'], 0, 255) : null,
         'midia_mime'     => $midia !== null ? mb_substr((string)$midia['mime'], 0, 100) : null,
         'conversas_id'   => (int)($dados['conversas_id'] ?? 0),
         'telefone'       => $cliente,
         'numero_host'    => $host !== '' ? $host : null,
         'numero_cliente' => $cliente,
         'remetente'      => $entrada ? $cliente : ($host !== '' ? $host : null),
         'destinatario'   => $entrada ? ($host !== '' ? $host : null) : $cliente,
         'direcao'        => $direcao,
         'origem_tipo'    => self::tipoDeOrigem($dados),
         'fluxo'          => mb_substr((string)($dados['fluxo'] ?? 'menu'), 0, 60),
         'fluxos_id'      => (int)($dados['fluxos_id'] ?? 0),
         'conteudo'       => self::textoDaResposta($dados['conteudo'] ?? ''),
         'tickets_id'     => (int)($dados['tickets_id'] ?? 0),
         'users_id'       => (int)($dados['users_id'] ?? 0),
         'status_envio'   => $dados['status_envio'] ?? 'ok',
         'erro'           => $dados['erro'] ?? null
      ]);

      return (int)$DB->insertId();
   }

   /**
    * Envia pelo WhatsApp e ja grava o historico
    */
   static function enviar(string $telefone, $conteudo, array $contexto = []): array {
      $midia  = $contexto['midia'] ?? null;

      // Resposta citando uma mensagem da conversa (linha gravada da mensagem citada)
      $citada = $contexto['citar'] ?? null;
      $citar  = null;
      if (is_array($citada) && !empty($citada['wa_id'])) {
         $citar = [
            'wa_id'  => (string)$citada['wa_id'],
            'de_mim' => (string)$citada['direcao'] === 'saida',
            'texto'  => self::resumoParaCitacao($citada)
         ];
      }

      $resultado = PluginWhatsappempresaServidor::enviarTexto($telefone, $conteudo, $midia, $citar);

      // Audio gravado no navegador (WebM) e convertido para OGG pelo servidor: guarda a versao convertida
      if ($midia !== null && !empty($resultado['convertido']['arquivo'])
          && PluginWhatsappempresaServidor::caminhoMidia((string)$resultado['convertido']['arquivo']) !== null) {
         $midia['arquivo'] = (string)$resultado['convertido']['arquivo'];
         $midia['mime']    = (string)($resultado['convertido']['mime'] ?? $midia['mime']);
      }

      if ($midia !== null && trim(is_array($conteudo) ? (string)($conteudo['texto'] ?? '') : (string)$conteudo) === '') {
         $conteudo = self::rotuloDaMidia((string)$midia['tipo']);
      }

      self::registrar([
         'midia'        => $midia,
         'wa_id'        => (string)($resultado['wa_id'] ?? ''),
         'citada_id'    => $citar !== null ? (int)$citada['id'] : 0,
         'citada_texto' => $citar !== null ? $citar['texto'] : '',
         'conversas_id' => $contexto['conversas_id'] ?? 0,
         'telefone'     => $telefone,
         'direcao'      => 'saida',
         'fluxo'        => $contexto['fluxo'] ?? 'menu',
         'fluxos_id'    => $contexto['fluxos_id'] ?? 0,
         'origem_tipo'  => $contexto['origem_tipo'] ?? '',
         'conteudo'     => $conteudo,
         'tickets_id'   => $contexto['tickets_id'] ?? 0,
         'users_id'     => $contexto['users_id'] ?? 0,
         'status_envio' => $resultado['ok'] ? 'ok' : 'erro',
         'erro'         => $resultado['erro']
      ]);

      if (!$resultado['ok']) {
         PluginWhatsappempresaLog::registrar(
            'Falha no envio de mensagem',
            'Destino ' . $telefone . ' - ' . (string)$resultado['erro'],
            'erro',
            'envio'
         );
      }

      return $resultado;
   }

   static function montarFiltros(array $filtros): array {
      $where = [];

      if (!empty($filtros['telefone'])) {
         $numero = PluginWhatsappempresaConfig::limparTelefone($filtros['telefone']);
         if ($numero !== '') {
            $where['telefone'] = ['LIKE', '%' . $numero . '%'];
         }
      }
      if (!empty($filtros['direcao'])) {
         $where['direcao'] = $filtros['direcao'];
      }
      if (!empty($filtros['origem_tipo'])) {
         $where['origem_tipo'] = $filtros['origem_tipo'];
      }
      if (!empty($filtros['fluxo'])) {
         $where['fluxo'] = $filtros['fluxo'];
      }
      if (!empty($filtros['tickets_id'])) {
         $where['tickets_id'] = (int)$filtros['tickets_id'];
      }

      return $where;
   }

   /**
    * Historico paginado para a tela de configuracao
    */
   static function historico(array $filtros = [], int $pagina = 1, int $porPagina = 25): array {
      global $DB;

      $where = self::montarFiltros($filtros);

      $total = 0;
      foreach ($DB->request([
         'COUNT' => 'total',
         'FROM'  => 'glpi_plugin_whatsappempresa_mensagens',
         'WHERE' => $where
      ]) as $linha) {
         $total = (int)$linha['total'];
      }

      $itens = [];
      $iterator = $DB->request([
         'FROM'  => 'glpi_plugin_whatsappempresa_mensagens',
         'WHERE' => $where,
         'ORDER' => 'id DESC',
         'START' => ($pagina - 1) * $porPagina,
         'LIMIT' => $porPagina
      ]);

      foreach ($iterator as $linha) {
         $itens[] = self::formatarLinha($linha);
      }

      return ['total' => $total, 'itens' => $itens];
   }

   /**
    * Duas listas independentes: o que o servidor recebeu e o que ele enviou
    */
   static function historicoSeparado(array $filtros = [], int $pagina = 1, int $porPagina = 25): array {
      $recebidas = self::historico(array_merge($filtros, ['direcao' => 'entrada']), $pagina, $porPagina);
      $enviadas  = self::historico(array_merge($filtros, ['direcao' => 'saida']), $pagina, $porPagina);

      return [
         'recebidas' => $recebidas['itens'],
         'enviadas'  => $enviadas['itens'],
         'total_recebidas' => $recebidas['total'],
         'total_enviadas'  => $enviadas['total'],
         'host' => PluginWhatsappempresaServidor::numeroHost()
      ];
   }

   static function formatarLinha(array $linha): array {
      $host    = (string)($linha['numero_host'] ?? '');
      $cliente = (string)($linha['numero_cliente'] ?? $linha['telefone']);
      $entrada = ((string)$linha['direcao'] === 'entrada');

      return [
         'id'           => (int)$linha['id'],
         'data'         => Html::convDateTime($linha['date_creation']),
         'direcao'      => (string)$linha['direcao'],
         'origem_tipo'  => (string)($linha['origem_tipo'] ?? 'automacao'),
         'fluxo'        => (string)$linha['fluxo'],
         'conteudo'     => strip_tags((string)$linha['conteudo']),
         'status'       => (string)$linha['status_envio'],
         'erro'         => (string)($linha['erro'] ?? ''),
         'cliente'      => $cliente,
         'host'         => $host,
         'remetente'    => (string)($linha['remetente'] ?? ($entrada ? $cliente : $host)),
         'destinatario' => (string)($linha['destinatario'] ?? ($entrada ? $host : $cliente)),
         'tickets_id'   => (int)$linha['tickets_id'],
         'nome_usuario' => (int)$linha['users_id'] > 0 ? PluginWhatsappempresaConfig::nomeUsuario((int)$linha['users_id']) : ''
      ];
   }

   static function limpar(int $dias): int {
      global $DB;

      if ($dias <= 0) {
         return 0;
      }

      $limite = date('Y-m-d H:i:s', strtotime('-' . $dias . ' days'));
      $DB->delete('glpi_plugin_whatsappempresa_mensagens', ['date_creation' => ['<', $limite]]);

      return (int)$DB->affectedRows();
   }
}
