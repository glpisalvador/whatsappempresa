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

      $DB->insert('glpi_plugin_whatsappempresa_mensagens', [
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
      $midia     = $contexto['midia'] ?? null;
      $resultado = PluginWhatsappempresaServidor::enviarTexto($telefone, $conteudo, $midia);

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
