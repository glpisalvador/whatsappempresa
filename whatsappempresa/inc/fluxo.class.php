<?php

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access this file directly");
}

class PluginWhatsappempresaFluxo {

   const FLUXO_MENU       = 'menu';
   const FLUXO_CONVERSA   = 'conversa';
   const FLUXO_APROVACAO  = 'aprovacao';
   const FLUXO_CONSTRUTOR = 'construtor';
   const FLUXO_SILENCIADO = 'silenciado';

   /**
    * Deixa o texto comparavel: minusculo, sem acento e sem pontuacao
    */
   static function normalizar(string $texto): string {
      $texto = mb_strtolower(trim($texto));

      $de   = ['á','à','ã','â','ä','é','è','ê','ë','í','ì','î','ï','ó','ò','õ','ô','ö','ú','ù','û','ü','ç'];
      $para = ['a','a','a','a','a','e','e','e','e','i','i','i','i','o','o','o','o','o','u','u','u','u','c'];
      $texto = str_replace($de, $para, $texto);

      return trim(preg_replace('/[^a-z0-9 ]/', '', $texto));
   }

   static function listaPalavras(string $chave): array {
      $bruto = (string)PluginWhatsappempresaConfig::get($chave, '');
      $itens = [];

      foreach (explode(',', $bruto) as $palavra) {
         $palavra = self::normalizar($palavra);
         if ($palavra !== '') {
            $itens[] = $palavra;
         }
      }

      return $itens;
   }

   /**
    * Palavras que sempre encerram o fluxo atual, em qualquer etapa
    */
   static function palavrasSaida(): array {
      $fixas = ['sair', 'encerrar', 'stop', 'parar', 'finalizar'];
      return array_values(array_unique(array_merge($fixas, self::listaPalavras('palavras_saida'))));
   }

   static function ehSaida(string $normalizado): bool {
      if ($normalizado === '') {
         return false;
      }

      $palavras = self::palavrasSaida();

      if (in_array($normalizado, $palavras, true)) {
         return true;
      }

      // Frases curtas tipo "quero sair" tambem encerram
      $termos = preg_split('/\s+/', $normalizado) ?: [];
      if (count($termos) > 3) {
         return false;
      }

      foreach ($palavras as $palavra) {
         if (in_array($palavra, $termos, true)) {
            return true;
         }
      }

      return false;
   }

   /**
    * Etapas de texto livre: só encerram com a palavra exata
    */
   static function etapaTextoLivre(array $sessao): bool {
      return in_array((string)($sessao['etapa'] ?? ''), [
         'abrir_titulo',
         'abrir_descricao',
         'acompanhamento',
         'validacao_comentario',
         'aprovacao_comentario',
         'informar_telefone'
      ], true);
   }

   /**
    * Saudacao com texto garantido, mesmo com a config vazia
    */
   static function textoSaudacao(): string {
      $texto = trim((string)PluginWhatsappempresaConfig::get('msg_saudacao', ''));

      if ($texto === '') {
         $texto = PluginWhatsappempresaConfig::ativo('exige_codigo')
            ? 'Ola! Voce esta no atendimento automatico do suporte. Informe o codigo de acesso da sua empresa para continuar.'
            : 'Ola! Voce esta no atendimento automatico do suporte.';
      }

      return $texto;
   }

   /**
    * Palavras que encerram a conversa com o tecnico
    */
   static function palavrasFimConversa(): array {
      $itens = self::listaPalavras('palavras_fim_conversa');
      return !empty($itens) ? $itens : ['0', 'sair', 'menu', 'encerrar'];
   }

   // ============================================
   // Controle de fluxos
   // ============================================

   /**
    * Fluxo em que o numero esta agora
    */
   static function fluxoAtual(array $sessao): string {
      $fluxo = (string)($sessao['fluxo'] ?? '');

      $validos = [
         self::FLUXO_MENU,
         self::FLUXO_CONVERSA,
         self::FLUXO_APROVACAO,
         self::FLUXO_CONSTRUTOR,
         self::FLUXO_SILENCIADO
      ];

      if (in_array($fluxo, $validos, true)) {
         return $fluxo;
      }

      // Sessoes gravadas antes da coluna de fluxo existir
      $etapa = (string)($sessao['etapa'] ?? 'inicio');

      if ($etapa === 'conversa') {
         return self::FLUXO_CONVERSA;
      }
      if ($etapa === 'silenciado') {
         return self::FLUXO_SILENCIADO;
      }
      if ($etapa === 'aprovacao_comentario') {
         return self::FLUXO_APROVACAO;
      }
      if ($etapa === 'construtor') {
         return self::FLUXO_CONSTRUTOR;
      }

      return self::FLUXO_MENU;
   }

   static function nomeAmigavel(string $fluxo): string {
      $nomes = [
         self::FLUXO_MENU       => 'atendimento automatico',
         self::FLUXO_CONVERSA   => 'conversa com o atendente',
         self::FLUXO_APROVACAO  => 'pedido de aprovacao',
         self::FLUXO_CONSTRUTOR => 'atendimento guiado',
         self::FLUXO_SILENCIADO => 'fora do atendimento automatico'
      ];

      return $nomes[$fluxo] ?? $fluxo;
   }

   /**
    * Coloca o numero em um fluxo
    */
   static function entrarFluxo(string $telefone, string $fluxo, array $contexto = [], array $extra = [], string $motivo = ''): bool {
      $telefone = PluginWhatsappempresaConfig::limparTelefone($telefone);
      if ($telefone === '') {
         return false;
      }

      $sessao   = self::obterSessao($telefone);
      $anterior = self::fluxoAtual($sessao);

      $etapas = [
         self::FLUXO_MENU       => 'inicio',
         self::FLUXO_CONVERSA   => 'conversa',
         self::FLUXO_APROVACAO  => 'aprovacao_comentario',
         self::FLUXO_CONSTRUTOR => 'construtor',
         self::FLUXO_SILENCIADO => 'silenciado'
      ];

      $dados = array_merge([
         'fluxo'        => $fluxo,
         'etapa'        => $etapas[$fluxo] ?? 'inicio',
         'contexto'     => json_encode($contexto, JSON_UNESCAPED_UNICODE),
         'conversas_id' => (int)($contexto['conversas_id'] ?? 0),
         'tickets_id'   => (int)($contexto['tickets_id'] ?? 0)
      ], $extra);

      self::gravarSessao($telefone, $dados);

      if ($anterior !== $fluxo) {
         self::registrarTransicao($telefone, $anterior, $fluxo, $motivo);
      }

      return true;
   }

   /**
    * Tira o numero do fluxo atual e devolve ao ponto neutro
    */
   static function sairFluxo(string $telefone, string $motivo = '', bool $manterAutenticado = false): bool {
      $telefone = PluginWhatsappempresaConfig::limparTelefone($telefone);
      if ($telefone === '') {
         return false;
      }

      $sessao   = self::obterSessao($telefone);
      $anterior = self::fluxoAtual($sessao);

      $dados = [
         'fluxo'        => self::FLUXO_MENU,
         'etapa'        => $manterAutenticado ? 'menu' : 'inicio',
         'contexto'     => '{}',
         'conversas_id' => 0,
         'tickets_id'   => 0,
         'fluxos_id'    => 0,
         'passo'        => null
      ];

      if (!$manterAutenticado) {
         $dados['autenticado']     = 0;
         $dados['autenticado_ate'] = null;
      }

      self::gravarSessao($telefone, $dados);

      if ($anterior !== self::FLUXO_MENU) {
         self::registrarTransicao($telefone, $anterior, self::FLUXO_MENU, $motivo);
      }

      return true;
   }

   /**
    * Move o numero de um fluxo para outro fechando corretamente o anterior
    */
   static function trocarFluxo(string $telefone, string $novo, array $contexto = [], string $motivo = ''): bool {
      $telefone = PluginWhatsappempresaConfig::limparTelefone($telefone);
      if ($telefone === '') {
         return false;
      }

      $sessao   = self::obterSessao($telefone);
      $anterior = self::fluxoAtual($sessao);
      $nova     = (int)($contexto['conversas_id'] ?? 0);

      if ($anterior === $novo && (int)$sessao['conversas_id'] === $nova) {
         return true;
      }

      // Sair da conversa antiga fecha o registro para nao ficar orfao na aba
      if ($anterior === self::FLUXO_CONVERSA) {
         $antiga = (int)$sessao['conversas_id'];
         if ($antiga <= 0) {
            $antiga = (int)($sessao['dados']['conversas_id'] ?? 0);
         }

         if ($antiga > 0 && $antiga !== $nova) {
            PluginWhatsappempresaConversa::encerrar($antiga, [
               'motivo'    => $motivo !== '' ? $motivo : 'Substituida por outra conversa',
               'origem'    => 'sistema',
               'despedida' => false,
               'liberar'   => false
            ]);
         }
      }

      return self::entrarFluxo($telefone, $novo, $contexto, [], $motivo);
   }

   /**
    * A validacao tem prioridade sobre tudo: encerra conversa aberta,
    * tira o numero do autoatendimento e prende ele na resposta do pedido
    */
   static function exigirValidacao(string $telefone, array $validacao): bool {
      $telefone = PluginWhatsappempresaConfig::limparTelefone($telefone);
      if ($telefone === '') {
         return false;
      }

      // Conversa em andamento e encerrada e gravada no chamado dela
      $aberta = PluginWhatsappempresaConversa::abertaDoNumero($telefone);
      if ($aberta !== null) {
         PluginWhatsappempresaConversa::encerrar((int)$aberta['id'], [
            'motivo'    => 'Encerrada para atender um pedido de validacao',
            'origem'    => 'sistema',
            'despedida' => false,
            'liberar'   => false,
            'users_id'  => (int)$validacao['users_id']
         ]);
      }

      self::gravarSessao($telefone, [
         'fluxo'         => self::FLUXO_APROVACAO,
         'etapa'         => 'aprovacao_resposta',
         'contexto'      => json_encode(['validacao' => $validacao], JSON_UNESCAPED_UNICODE),
         'conversas_id'  => 0,
         'tickets_id'    => 0,
         'fluxos_id'     => 0,
         'passo'         => null,
         'users_id'      => (int)$validacao['users_id'],
         'autenticado'   => 1,
         'tentativas'    => 0,
         'bloqueado_ate' => null
      ]);

      self::registrarTransicao($telefone, 'anterior', self::FLUXO_APROVACAO, 'Pedido de validacao recebido');

      return true;
   }

   static function registrarTransicao(string $telefone, string $de, string $para, string $motivo = ''): void {
      if (!PluginWhatsappempresaConfig::ativo('log_fluxos')) {
         return;
      }

      PluginWhatsappempresaLog::registrar(
         'Troca de fluxo do numero',
         'Numero ' . $telefone . ': ' . $de . ' para ' . $para . ($motivo !== '' ? ' (' . $motivo . ')' : ''),
         'info',
         'fluxo'
      );
   }

   /**
    * Alinha a sessao do numero com a conversa realmente aberta no GLPI.
    * E o que impede o numero de cair no autoatendimento ou ficar sem destino.
    */
   static function reconciliar(string $telefone, array $sessao): array {
      $fluxo = self::fluxoAtual($sessao);

      // Validacao e fluxo guiado nao podem ser interrompidos por conversa nenhuma
      if ($fluxo === self::FLUXO_APROVACAO || $fluxo === self::FLUXO_CONSTRUTOR) {
         return $sessao;
      }

      $ligada = PluginWhatsappempresaConversa::abertaDoNumero($telefone);

      if ($fluxo === self::FLUXO_CONVERSA) {
         $conversas_id = (int)$sessao['conversas_id'];
         if ($conversas_id <= 0) {
            $conversas_id = (int)($sessao['dados']['conversas_id'] ?? 0);
         }

         // A conversa gravada na sessao sumiu ou ja foi encerrada
         if ($conversas_id <= 0 || !PluginWhatsappempresaConversa::estaAberta($conversas_id)) {
            if ($ligada !== null) {
               self::entrarFluxo($telefone, self::FLUXO_CONVERSA, [
                  'conversas_id' => (int)$ligada['id'],
                  'tickets_id'   => (int)$ligada['tickets_id'],
                  'origem'       => (string)$ligada['origem']
               ], [], 'Conversa aberta reencontrada');

               return self::obterSessao($telefone);
            }

            self::sairFluxo($telefone, 'Conversa encerrada ou inexistente');
            return self::obterSessao($telefone);
         }

         // Contexto incompleto vindo de versoes anteriores
         if ((int)($sessao['dados']['conversas_id'] ?? 0) !== $conversas_id) {
            self::entrarFluxo($telefone, self::FLUXO_CONVERSA, [
               'conversas_id' => $conversas_id,
               'tickets_id'   => PluginWhatsappempresaConversa::ticketDaConversa($conversas_id),
               'origem'       => 'tecnico'
            ]);

            return self::obterSessao($telefone);
         }

         return $sessao;
      }

      // Existe conversa aberta mas o numero escapou dela: traz de volta
      if ($ligada !== null && PluginWhatsappempresaConfig::ativo('conversa_tecnico_prende')) {
         self::entrarFluxo($telefone, self::FLUXO_CONVERSA, [
            'conversas_id' => (int)$ligada['id'],
            'tickets_id'   => (int)$ligada['tickets_id'],
            'origem'       => (string)$ligada['origem']
         ], [], 'Numero devolvido a conversa aberta');

         return self::obterSessao($telefone);
      }

      return $sessao;
   }

   // ============================================
   // Entrada das mensagens recebidas
   // ============================================

   static function processar(string $telefone, string $texto, string $jid = ''): array {
      $telefone = PluginWhatsappempresaConfig::limparTelefone($telefone);
      $texto    = trim($texto);

      $vazio = ['respostas' => [], 'conversas_id' => 0, 'tickets_id' => 0, 'fluxo' => self::FLUXO_MENU];

      if ($telefone === '' || $texto === '') {
         return $vazio;
      }

      $sessao = self::obterSessao($telefone, $jid);
      $sessao = self::reconciliar($telefone, $sessao);
      $fluxo  = self::fluxoAtual($sessao);
      $limpo  = self::normalizar($texto);

      // Palavra de saida encerra o que estiver acontecendo, em qualquer fluxo
      $pediuSaida = self::etapaTextoLivre($sessao)
         ? in_array($limpo, self::palavrasSaida(), true)
         : self::ehSaida($limpo);

      if ($pediuSaida) {
         return self::tratarSaida($telefone, $texto, $sessao, $fluxo);
      }

      // Validacao pendente vem antes de tudo, inclusive da conversa
      if ($fluxo === self::FLUXO_APROVACAO) {
         PluginWhatsappempresaMensagem::registrar([
            'telefone' => $telefone,
            'direcao'  => 'entrada',
            'fluxo'    => 'aprovacao',
            'conteudo' => $texto,
            'users_id' => (int)$sessao['users_id']
         ]);

         $resposta = self::tratarAprovacao($telefone, $texto, $sessao);

         return [
            'respostas'    => $resposta ?? [self::respostaAprovacao($sessao)],
            'conversas_id' => 0,
            'tickets_id'   => 0,
            'fluxo'        => self::fluxoAtual(self::obterSessao($telefone))
         ];
      }

      // Fluxo montado no painel em andamento
      if ($fluxo === self::FLUXO_CONSTRUTOR) {
         PluginWhatsappempresaMensagem::registrar([
            'telefone'  => $telefone,
            'direcao'   => 'entrada',
            'fluxo'     => 'construtor',
            'fluxos_id' => (int)$sessao['fluxos_id'],
            'conteudo'  => $texto,
            'users_id'  => (int)$sessao['users_id']
         ]);

         $resultado = PluginWhatsappempresaConstrutor::processar($telefone, $texto, $sessao);
         $atual     = self::obterSessao($telefone);

         return [
            'respostas'    => $resultado['respostas'],
            'conversas_id' => (int)$atual['conversas_id'],
            'tickets_id'   => (int)$atual['tickets_id'],
            'fluxo'        => self::fluxoAtual($atual),
            'fluxos_id'    => (int)$sessao['fluxos_id']
         ];
      }

      // A conversa com o tecnico tem prioridade sobre qualquer palavra de controle
      if ($fluxo === self::FLUXO_CONVERSA) {
         return self::tratarConversa($telefone, $texto, $sessao);
      }

      // Numero silenciado: so volta com a palavra de retomada
      if ($fluxo === self::FLUXO_SILENCIADO) {
         if (in_array($limpo, self::listaPalavras('palavras_retomar'), true)) {
            self::entrarFluxo($telefone, self::FLUXO_MENU, [], [
               'autenticado'     => 0,
               'autenticado_ate' => null,
               'tentativas'      => 0,
               'bloqueado_ate'   => null
            ], 'Retomada pedida pelo cliente');

            $retomada = trim((string)PluginWhatsappempresaConfig::get('msg_retomada'));
            $respostas = [$retomada !== '' ? $retomada : 'Atendimento automatico reativado.'];

            if (PluginWhatsappempresaConfig::ativo('exige_codigo')) {
               self::definirEtapa($telefone, 'codigo', [], ['autenticado' => 0, 'tentativas' => 0]);
               $respostas[] = self::textoSaudacao();
            }

            return [
               'respostas'    => $respostas,
               'conversas_id' => 0,
               'tickets_id'   => 0,
               'fluxo'        => self::FLUXO_MENU
            ];
         }

         PluginWhatsappempresaMensagem::registrar([
            'telefone' => $telefone,
            'direcao'  => 'entrada',
            'fluxo'    => 'silenciado',
            'conteudo' => $texto,
            'users_id' => (int)$sessao['users_id']
         ]);

         return $vazio;
      }

      PluginWhatsappempresaMensagem::registrar([
         'telefone' => $telefone,
         'direcao'  => 'entrada',
         'fluxo'    => 'menu',
         'conteudo' => $texto,
         'users_id' => (int)$sessao['users_id']
      ]);

      // Bloqueio por excesso de tentativas: avisa uma vez so
      if (!empty($sessao['bloqueado_ate']) && strtotime($sessao['bloqueado_ate']) > time()) {
         $dados = $sessao['dados'];

         if (!empty($dados['avisado'])) {
            return $vazio;
         }

         $dados['avisado'] = 1;
         self::gravarSessao($telefone, ['contexto' => json_encode($dados, JSON_UNESCAPED_UNICODE)]);

         return [
            'respostas'    => [PluginWhatsappempresaConfig::get('msg_bloqueado')],
            'conversas_id' => 0,
            'tickets_id'   => 0,
            'fluxo'        => $fluxo
         ];
      }

      // Sessao liberada pelo codigo tem tempo de vida proprio
      if (self::sessaoExpirou($sessao)) {
         self::encerrarSessaoAutenticada($telefone);

         return [
            'respostas'    => [
               PluginWhatsappempresaConfig::get('msg_sessao_expirada'),
               PluginWhatsappempresaConfig::get('msg_saudacao')
            ],
            'conversas_id' => 0,
            'tickets_id'   => 0,
            'fluxo'        => self::FLUXO_MENU
         ];
      }

      // Resposta a um pedido de aprovacao pendente
      if (PluginWhatsappempresaConfig::ativo('fluxo_aprovacao')
          && in_array($sessao['etapa'], ['inicio', 'menu', 'aprovacao_comentario'], true)) {

         $resposta = null;

         try {
            $resposta = self::tratarAprovacao($telefone, $texto, $sessao);
         } catch (Throwable $e) {
            PluginWhatsappempresaLog::registrar(
               'Falha ao verificar pedidos de aprovacao',
               $e->getMessage(),
               'erro',
               'aprovacao'
            );
         }

         if ($resposta !== null) {
            return ['respostas' => $resposta, 'conversas_id' => 0, 'tickets_id' => 0, 'fluxo' => self::FLUXO_APROVACAO];
         }
      }

      // Palavra que dispara um fluxo montado no painel
      if ((int)$sessao['autenticado'] === 1) {
         $montado = PluginWhatsappempresaConstrutor::porPalavra($limpo);
         if ($montado !== null) {
            return self::abrirConstrutor($telefone, (int)$montado['id'], (int)$sessao['users_id']);
         }
      }

      if (!PluginWhatsappempresaConfig::ativo('fluxo_autoatendimento')) {
         return $vazio;
      }

      $respostas = self::tratarMenu($telefone, $texto, $sessao);

      // O primeiro contato nunca pode ficar sem resposta
      if (empty($respostas)) {
         self::definirEtapa(
            $telefone,
            PluginWhatsappempresaConfig::ativo('exige_codigo') ? 'codigo' : 'inicio',
            [],
            ['autenticado' => 0, 'tentativas' => 0]
         );
         $respostas = [self::textoSaudacao()];
      }

      return [
         'respostas'    => $respostas,
         'conversas_id' => 0,
         'tickets_id'   => 0,
         'fluxo'        => self::fluxoAtual(self::obterSessao($telefone))
      ];
   }

   /**
    * Encerra o fluxo atual e avisa o cliente do que aconteceu
    */
   static function tratarSaida(string $telefone, string $texto, array $sessao, string $fluxo): array {
      $users_id = (int)$sessao['users_id'];

      PluginWhatsappempresaMensagem::registrar([
         'telefone'  => $telefone,
         'direcao'   => 'entrada',
         'fluxo'     => $fluxo,
         'fluxos_id' => (int)($sessao['fluxos_id'] ?? 0),
         'conteudo'  => $texto,
         'users_id'  => $users_id
      ]);

      $respostas = [];

      if ($fluxo === self::FLUXO_CONVERSA) {
         $conversas_id = (int)$sessao['conversas_id'];
         if ($conversas_id <= 0) {
            $conversas_id = (int)($sessao['dados']['conversas_id'] ?? 0);
         }

         if ($conversas_id > 0) {
            PluginWhatsappempresaConversa::encerrar($conversas_id, [
               'motivo'    => 'Cliente pediu para sair da conversa',
               'origem'    => 'cliente',
               'despedida' => false
            ]);
         }

         $respostas[] = PluginWhatsappempresaConfig::get('msg_saida_conversa');
      } elseif ($fluxo === self::FLUXO_APROVACAO) {
         self::encerrarValidacao($telefone, $users_id);
         $respostas[] = PluginWhatsappempresaConfig::get('msg_saida_validacao');
      } elseif ($fluxo === self::FLUXO_CONSTRUTOR) {
         $nome = (string)($sessao['dados']['fluxo_nome'] ?? 'atendimento guiado');
         $respostas[] = str_replace('{fluxo}', $nome, (string)PluginWhatsappempresaConfig::get('msg_saida_fluxo'));
      } else {
         $respostas[] = PluginWhatsappempresaConfig::get('msg_saida');
      }

      // Palavra de saida nunca pode terminar em silencio
      foreach ($respostas as $indice => $item) {
         if (trim(PluginWhatsappempresaMensagem::textoDaResposta($item)) === '') {
            $respostas[$indice] = 'Atendimento encerrado. Envie qualquer mensagem quando quiser voltar.';
         }
      }

      $silenciar = PluginWhatsappempresaConfig::ativo('saida_silencia');

      if ($silenciar) {
         self::silenciar($telefone);
      } else {
         self::sairFluxo($telefone, 'Palavra de saida enviada pelo cliente');
      }

      PluginWhatsappempresaLog::registrar(
         'Cliente saiu do fluxo pela palavra de saida',
         'Numero ' . $telefone . ' saiu de ' . self::nomeAmigavel($fluxo) . '.',
         'info',
         'fluxo',
         $users_id
      );

      return [
         'respostas'    => $respostas,
         'conversas_id' => 0,
         'tickets_id'   => 0,
         'fluxo'        => $silenciar ? self::FLUXO_SILENCIADO : self::FLUXO_MENU
      ];
   }

   /**
    * Tira o numero do atendimento automatico ate ele pedir para voltar
    */
   static function silenciar(string $telefone): void {
      $telefone = PluginWhatsappempresaConfig::limparTelefone($telefone);

      self::entrarFluxo($telefone, self::FLUXO_SILENCIADO, [], [
         'autenticado'     => 0,
         'autenticado_ate' => null,
         'tentativas'      => 0,
         'bloqueado_ate'   => null,
         'fluxos_id'       => 0,
         'passo'           => null
      ], 'Palavra de saida enviada pelo cliente');

      PluginWhatsappempresaLog::registrar(
         'Numero saiu do atendimento automatico',
         'Telefone ' . $telefone,
         'info',
         'autoatendimento'
      );
   }

   // ============================================
   // Sessao do cliente
   // ============================================

   static function minutosDaSessao(): int {
      $minutos = (int)PluginWhatsappempresaConfig::get('sessao_codigo_minutos', '10');
      return $minutos > 0 ? $minutos : 0;
   }

   static function sessaoExpirou(array $sessao): bool {
      if ((int)($sessao['autenticado'] ?? 0) !== 1) {
         return false;
      }

      $limite = (string)($sessao['autenticado_ate'] ?? '');
      if ($limite === '' || $limite === null) {
         return false;
      }

      return strtotime($limite) < time();
   }

   static function encerrarSessaoAutenticada(string $telefone): void {
      self::gravarSessao($telefone, [
         'fluxo'           => self::FLUXO_MENU,
         'etapa'           => 'codigo',
         'contexto'        => '{}',
         'autenticado'     => 0,
         'autenticado_ate' => null,
         'conversas_id'    => 0,
         'tickets_id'      => 0,
         'fluxos_id'       => 0,
         'passo'           => null
      ]);

      PluginWhatsappempresaLog::registrar(
         'Sessao de autoatendimento expirada',
         'Numero ' . $telefone . ' passou de ' . self::minutosDaSessao() . ' minuto(s) e precisa informar o codigo de novo.',
         'info',
         'autoatendimento'
      );
   }

   /**
    * Usada pela tarefa automatica para limpar sessoes vencidas
    */
   static function expirarSessoesAutenticadas(): int {
      global $DB;

      if (self::minutosDaSessao() <= 0) {
         return 0;
      }

      $agora = date('Y-m-d H:i:s');
      $total = 0;

      foreach ($DB->request([
         'SELECT' => ['id', 'telefone'],
         'FROM'   => 'glpi_plugin_whatsappempresa_sessoes',
         'WHERE'  => [
            'autenticado'     => 1,
            'fluxo'           => [self::FLUXO_MENU, self::FLUXO_CONSTRUTOR],
            'autenticado_ate' => ['<', $agora],
            'NOT' => ['autenticado_ate' => null]
         ],
         'LIMIT' => 200
      ]) as $linha) {
         $DB->update('glpi_plugin_whatsappempresa_sessoes', [
            'fluxo'           => self::FLUXO_MENU,
            'etapa'           => 'codigo',
            'contexto'        => '{}',
            'autenticado'     => 0,
            'autenticado_ate' => null,
            'conversas_id'    => 0,
            'tickets_id'      => 0,
            'fluxos_id'       => 0,
            'passo'           => null
         ], ['id' => (int)$linha['id']]);

         $total++;
      }

      return $total;
   }

   static function obterSessao(string $telefone, string $jid = ''): array {
      global $DB;

      $telefone = PluginWhatsappempresaConfig::limparTelefone($telefone);
      $chave    = PluginWhatsappempresaConfig::chaveTelefone($telefone);
      $minutos  = (int)PluginWhatsappempresaConfig::get('sessao_minutos', '15');

      $encontrada = null;

      // 1. Pelo endereco real da conversa no WhatsApp
      if ($jid !== '') {
         foreach ($DB->request([
            'FROM'  => 'glpi_plugin_whatsappempresa_sessoes',
            'WHERE' => ['jid' => $jid],
            'LIMIT' => 1
         ]) as $linha) {
            $encontrada = $linha;
         }
      }

      // 2. Pelo numero exato
      if ($encontrada === null) {
         foreach ($DB->request([
            'FROM'  => 'glpi_plugin_whatsappempresa_sessoes',
            'WHERE' => ['telefone' => $telefone],
            'LIMIT' => 1
         ]) as $linha) {
            $encontrada = $linha;
         }
      }

      // 3. Pela chave de comparacao, cobrindo nono digito e DDI
      if ($encontrada === null && strlen($chave) >= 8) {
         foreach ($DB->request([
            'FROM'  => 'glpi_plugin_whatsappempresa_sessoes',
            'WHERE' => ['chave' => $chave],
            'ORDER' => 'date_mod DESC',
            'LIMIT' => 1
         ]) as $linha) {
            $encontrada = $linha;
         }
      }

      // 4. Sessoes antigas ainda sem a coluna de chave preenchida
      if ($encontrada === null && strlen($chave) >= 8) {
         foreach ($DB->request([
            'FROM'  => 'glpi_plugin_whatsappempresa_sessoes',
            'WHERE' => ['telefone' => ['LIKE', '%' . $chave]],
            'ORDER' => 'date_mod DESC',
            'LIMIT' => 1
         ]) as $linha) {
            $encontrada = $linha;
         }
      }

      if ($encontrada !== null) {
         $linha = $encontrada;

         $ajuste = [];
         if ((string)$linha['telefone'] !== $telefone) {
            $ajuste['telefone'] = $telefone;
         }
         if ((string)($linha['chave'] ?? '') !== $chave) {
            $ajuste['chave'] = $chave;
         }
         if ($jid !== '' && (string)($linha['jid'] ?? '') !== $jid) {
            $ajuste['jid'] = $jid;
         }
         if (!empty($ajuste)) {
            $DB->update('glpi_plugin_whatsappempresa_sessoes', $ajuste, ['id' => (int)$linha['id']]);
            $linha = array_merge($linha, $ajuste);
         }

         $fluxo = self::fluxoAtual($linha);

         // Conversa e silencio nao expiram por tempo: quem fecha e o encerramento
         $expira = !in_array($fluxo, [self::FLUXO_CONVERSA, self::FLUXO_SILENCIADO], true);

         if ($expira && (strtotime((string)$linha['date_mod']) + ($minutos * 60)) < time()) {
            $DB->update('glpi_plugin_whatsappempresa_sessoes', [
               'fluxo'           => self::FLUXO_MENU,
               'etapa'           => 'inicio',
               'contexto'        => '{}',
               'autenticado'     => 0,
               'autenticado_ate' => null,
               'conversas_id'    => 0,
               'tickets_id'      => 0,
               'fluxos_id'       => 0,
               'passo'           => null
            ], ['id' => (int)$linha['id']]);

            $linha['fluxo']           = self::FLUXO_MENU;
            $linha['etapa']           = 'inicio';
            $linha['contexto']        = '{}';
            $linha['autenticado']     = 0;
            $linha['autenticado_ate'] = null;
            $linha['conversas_id']    = 0;
            $linha['tickets_id']      = 0;
            $linha['fluxos_id']       = 0;
            $linha['passo']           = null;
         }

         $linha['dados']        = json_decode((string)$linha['contexto'], true) ?: [];
         $linha['conversas_id'] = (int)($linha['conversas_id'] ?? 0);
         $linha['tickets_id']   = (int)($linha['tickets_id'] ?? 0);
         $linha['fluxos_id']    = (int)($linha['fluxos_id'] ?? 0);

         return $linha;
      }

      $DB->insert('glpi_plugin_whatsappempresa_sessoes', [
         'telefone' => $telefone,
         'chave'    => $chave,
         'jid'      => $jid !== '' ? $jid : null,
         'fluxo'    => self::FLUXO_MENU,
         'etapa'    => 'inicio',
         'contexto' => '{}'
      ]);

      return [
         'id'              => (int)$DB->insertId(),
         'telefone'        => $telefone,
         'chave'           => $chave,
         'jid'             => $jid,
         'fluxo'           => self::FLUXO_MENU,
         'etapa'           => 'inicio',
         'autenticado'     => 0,
         'autenticado_ate' => null,
         'tentativas'      => 0,
         'bloqueado_ate'   => null,
         'users_id'        => 0,
         'conversas_id'    => 0,
         'tickets_id'      => 0,
         'fluxos_id'       => 0,
         'passo'           => null,
         'contexto'        => '{}',
         'dados'           => []
      ];
   }

   /**
    * Grava sempre na linha certa, mesmo quando o numero chega em outro formato
    */
   static function gravarSessao(string $telefone, array $dados): void {
      global $DB;

      $telefone = PluginWhatsappempresaConfig::limparTelefone($telefone);
      $chave    = PluginWhatsappempresaConfig::chaveTelefone($telefone);

      $alvo = 0;
      foreach ($DB->request([
         'SELECT' => ['id'],
         'FROM'   => 'glpi_plugin_whatsappempresa_sessoes',
         'WHERE'  => ['telefone' => $telefone],
         'LIMIT'  => 1
      ]) as $linha) {
         $alvo = (int)$linha['id'];
      }

      if ($alvo <= 0 && strlen($chave) >= 8) {
         foreach ($DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_plugin_whatsappempresa_sessoes',
            'WHERE'  => ['chave' => $chave],
            'ORDER'  => 'date_mod DESC',
            'LIMIT'  => 1
         ]) as $linha) {
            $alvo = (int)$linha['id'];
         }
      }

      if ($alvo <= 0) {
         $DB->insert('glpi_plugin_whatsappempresa_sessoes', array_merge([
            'telefone' => $telefone,
            'chave'    => $chave,
            'fluxo'    => self::FLUXO_MENU,
            'etapa'    => 'inicio',
            'contexto' => '{}'
         ], $dados));
         return;
      }

      $DB->update('glpi_plugin_whatsappempresa_sessoes', $dados, ['id' => $alvo]);
   }

   static function definirEtapa(string $telefone, string $etapa, array $contexto = [], array $extra = []): void {
      $dados = array_merge([
         'etapa'    => $etapa,
         'contexto' => json_encode($contexto, JSON_UNESCAPED_UNICODE)
      ], $extra);

      if (!isset($dados['fluxo'])) {
         $dados['fluxo'] = ($etapa === 'aprovacao_comentario') ? self::FLUXO_APROVACAO : self::FLUXO_MENU;
      }

      self::gravarSessao($telefone, $dados);
   }

   /**
    * Marca o numero como identificado pelo tempo configurado
    */
   static function liberarAcesso(string $telefone, int $users_id, array $contexto = []): void {
      $minutos = self::minutosDaSessao();

      self::definirEtapa($telefone, 'menu', $contexto, [
         'autenticado'     => 1,
         'autenticado_ate' => $minutos > 0 ? date('Y-m-d H:i:s', time() + ($minutos * 60)) : null,
         'users_id'        => $users_id,
         'tentativas'      => 0
      ]);
   }

   // ============================================
   // Fluxo de aprovacao
   // ============================================

   static function respostaAprovacao(array $sessao): array {
      return PluginWhatsappempresaMensagem::comOpcoes(
         'Voce tem um pedido de aprovacao pendente. Qual a sua decisao?',
         [['id' => '1', 'rotulo' => 'Aprovar'], ['id' => '2', 'rotulo' => 'Recusar']],
         'Toque em uma das opcoes'
      );
   }

   static function tratarAprovacao(string $telefone, string $texto, array $sessao): ?array {
      $dados = $sessao['dados'];
      $etapa = (string)$sessao['etapa'];

      // Segunda etapa: comentario da validacao
      if ($etapa === 'aprovacao_comentario' && !empty($dados['validacao'])) {
         $registro = $dados['validacao'];
         $users_id = (int)$registro['users_id'];

         $resultado = PluginWhatsappempresaValidacao::responder(
            $registro['itemtype'],
            (int)$registro['items_id'],
            (bool)$registro['aprovado'],
            $texto,
            $users_id
         );

         if (!$resultado['ok']) {
            self::encerrarValidacao($telefone, $users_id);
            return array_merge(
               ['Nao foi possivel registrar a validacao: ' . $resultado['erro']],
               self::proximoPasso($telefone, $users_id)
            );
         }

         $rotulo   = $resultado['mudanca'] ? 'Mudanca' : 'Chamado';
         $situacao = $registro['aprovado'] ? 'APROVADA' : 'RECUSADA';

         self::encerrarValidacao($telefone, $users_id);

         return array_merge(
            ["Validacao {$situacao} com sucesso.\n{$rotulo} #{$resultado['objetos_id']}\nComentario registrado: " . $texto],
            self::proximoPasso($telefone, $users_id)
         );
      }

      // Primeira etapa: escolha entre aprovar e recusar
      $pendentes = PluginWhatsappempresaValidacao::pendentesPorTelefone($telefone);

      // O pedido guardado na sessao vale mesmo que a lista esteja vazia
      $registro = null;
      if (!empty($dados['validacao']) && empty($dados['validacao']['aprovado'])) {
         $registro = $dados['validacao'];
      } elseif (!empty($pendentes)) {
         $registro = $pendentes[0];
      }

      if ($registro === null) {
         if ($etapa === 'aprovacao_resposta') {
            self::encerrarValidacao($telefone, (int)$sessao['users_id']);
            return ['Nao ha mais pedidos de aprovacao pendentes para voce.'];
         }
         return null;
      }

      $normalizado = self::normalizar($texto);
      $aprovado = null;

      if ($normalizado === '1' || str_contains($normalizado, 'aprovad') || str_contains($normalizado, 'aprovo') || $normalizado === 'sim') {
         $aprovado = true;
      } elseif ($normalizado === '2' || str_contains($normalizado, 'recusad') || str_contains($normalizado, 'recuso')
                || str_contains($normalizado, 'reprovad') || $normalizado === 'nao') {
         $aprovado = false;
      }

      if ($aprovado === null) {
         // Fora do fluxo de validacao a mensagem segue o caminho normal
         if ($etapa !== 'aprovacao_resposta') {
            return null;
         }

         return [PluginWhatsappempresaMensagem::comOpcoes(
            "Nao entendi. Escolha uma das opcoes:\n" . (string)($registro['titulo'] ?? ''),
            [['id' => '1', 'rotulo' => 'Aprovar'], ['id' => '2', 'rotulo' => 'Recusar']]
         )];
      }

      $contexto = [
         'validacao' => [
            'itemtype'   => $registro['itemtype'],
            'items_id'   => (int)$registro['items_id'],
            'objetos_id' => (int)($registro['objetos_id'] ?? 0),
            'users_id'   => (int)$registro['users_id'],
            'titulo'     => (string)($registro['titulo'] ?? ''),
            'aprovado'   => $aprovado
         ]
      ];

      self::entrarFluxo($telefone, self::FLUXO_APROVACAO, $contexto, [
         'etapa'       => 'aprovacao_comentario',
         'users_id'    => (int)$registro['users_id'],
         'autenticado' => 1
      ], 'Resposta a uma validacao pendente');

      $situacao = $aprovado ? 'APROVAR' : 'RECUSAR';
      return ["Voce escolheu {$situacao}: " . (string)($registro['titulo'] ?? '') . "\n\nEnvie agora o comentario da validacao."];
   }

   /**
    * Tira o numero do fluxo de validacao
    */
   static function encerrarValidacao(string $telefone, int $users_id = 0): void {
      self::gravarSessao($telefone, [
         'fluxo'        => self::FLUXO_MENU,
         'etapa'        => 'menu',
         'contexto'     => '{}',
         'conversas_id' => 0,
         'tickets_id'   => 0
      ]);

      self::registrarTransicao($telefone, self::FLUXO_APROVACAO, self::FLUXO_MENU, 'Validacao respondida');
   }

   /**
    * Encadeia a proxima validacao pendente ou devolve o menu
    */
   static function proximoPasso(string $telefone, int $users_id): array {
      $pendentes = PluginWhatsappempresaValidacao::pendentesPorTelefone($telefone);

      if (!empty($pendentes)) {
         $proximo = $pendentes[0];

         self::exigirValidacao($telefone, [
            'itemtype'   => $proximo['itemtype'],
            'items_id'   => (int)$proximo['items_id'],
            'objetos_id' => (int)$proximo['objetos_id'],
            'users_id'   => (int)$proximo['users_id'],
            'titulo'     => (string)$proximo['titulo']
         ]);

         return [PluginWhatsappempresaMensagem::comOpcoes(
            "Voce ainda tem um pedido pendente:\n" . (string)$proximo['titulo'],
            [['id' => '1', 'rotulo' => 'Aprovar'], ['id' => '2', 'rotulo' => 'Recusar']]
         )];
      }

      if (PluginWhatsappempresaConfig::ativo('fluxo_autoatendimento') && $users_id > 0) {
         return [self::montarMenu($telefone, $users_id)];
      }

      return [];
   }

   // ============================================
   // Fluxo de conversa com o tecnico
   // ============================================

   static function tratarConversa(string $telefone, string $texto, array $sessao): array {
      $conversas_id = (int)$sessao['conversas_id'];
      if ($conversas_id <= 0) {
         $conversas_id = (int)($sessao['dados']['conversas_id'] ?? 0);
      }

      $tickets_id = (int)$sessao['tickets_id'];
      if ($tickets_id <= 0) {
         $tickets_id = (int)($sessao['dados']['tickets_id'] ?? 0);
      }

      $peloTecnico = ((string)($sessao['dados']['origem'] ?? '') === 'tecnico');
      $users_id    = (int)$sessao['users_id'];
      $limpo       = self::normalizar($texto);

      $saida = [
         'respostas'    => [],
         'conversas_id' => $conversas_id,
         'tickets_id'   => $tickets_id,
         'fluxo'        => self::FLUXO_CONVERSA
      ];

      // Fluxo desligado no painel enquanto a conversa estava aberta
      if (!PluginWhatsappempresaConfig::ativo('fluxo_conversa')) {
         PluginWhatsappempresaConversa::encerrar($conversas_id, [
            'motivo'    => 'Fluxo de conversa desativado no painel',
            'origem'    => 'sistema',
            'despedida' => false
         ]);

         return $saida;
      }

      // Pedido de encerramento feito pelo proprio cliente
      if (in_array($limpo, self::palavrasFimConversa(), true)) {
         PluginWhatsappempresaMensagem::registrar([
            'conversas_id' => $conversas_id,
            'telefone'     => $telefone,
            'direcao'      => 'entrada',
            'fluxo'        => 'conversa',
            'conteudo'     => $texto,
            'tickets_id'   => $tickets_id,
            'users_id'     => $users_id
         ]);

         PluginWhatsappempresaConversa::encerrar($conversas_id, [
            'motivo'    => 'Encerrada pelo cliente no WhatsApp',
            'origem'    => 'cliente',
            'despedida' => false
         ]);

         $respostas = ['Conversa encerrada. Obrigado pelo contato.'];

         // So devolve o menu para quem estava autenticado no autoatendimento
         if (!$peloTecnico && (int)$sessao['autenticado'] === 1 && PluginWhatsappempresaConfig::ativo('fluxo_autoatendimento')) {
            self::sairFluxo($telefone, 'Conversa encerrada pelo cliente', true);
            $respostas[] = self::montarMenu($telefone, $users_id);
         } else {
            self::sairFluxo($telefone, 'Conversa encerrada pelo cliente');
            $respostas[] = 'Envie qualquer mensagem para falar com o atendimento.';
         }

         return [
            'respostas'    => $respostas,
            'conversas_id' => $conversas_id,
            'tickets_id'   => $tickets_id,
            'fluxo'        => self::FLUXO_MENU
         ];
      }

      PluginWhatsappempresaConversa::receberDoCliente($telefone, $texto, $tickets_id, $users_id, $conversas_id);

      // Renova o tempo de vida da conversa a cada mensagem
      self::gravarSessao($telefone, ['fluxo' => self::FLUXO_CONVERSA, 'etapa' => 'conversa']);

      return $saida;
   }

   /**
    * Direciona as respostas do numero para a conversa aberta pelo tecnico
    */
   static function prenderEmConversa(string $telefone, int $conversas_id, int $tickets_id, int $users_id = 0, string $jid = ''): void {
      $telefone = PluginWhatsappempresaConfig::limparTelefone($telefone);
      $sessao   = self::obterSessao($telefone, $jid);

      if ($users_id <= 0) {
         $users_id = (int)$sessao['users_id'];
      }

      self::trocarFluxo($telefone, self::FLUXO_CONVERSA, [
         'conversas_id' => $conversas_id,
         'tickets_id'   => $tickets_id,
         'origem'       => 'tecnico'
      ], 'Conversa iniciada pelo atendente');

      $extra = [
         'users_id'      => $users_id,
         'tentativas'    => 0,
         'bloqueado_ate' => null
      ];

      if ($jid !== '') {
         $extra['jid'] = $jid;
      }

      self::gravarSessao($telefone, $extra);
   }

   /**
    * Devolve o numero ao atendimento automatico
    */
   static function liberarConversa(string $telefone, int $conversas_id = 0): void {
      $telefone = PluginWhatsappempresaConfig::limparTelefone($telefone);
      if ($telefone === '') {
         return;
      }

      $sessao = self::obterSessao($telefone);
      $atual  = (int)$sessao['conversas_id'];

      if ($atual <= 0) {
         $atual = (int)($sessao['dados']['conversas_id'] ?? 0);
      }

      // Nao derruba uma conversa diferente da que esta sendo encerrada
      if ($conversas_id > 0 && $atual > 0 && $atual !== $conversas_id) {
         return;
      }

      self::sairFluxo($telefone, 'Conversa encerrada');
   }

   // ============================================
   // Menu do cliente
   // ============================================

   static function tratarMenu(string $telefone, string $texto, array $sessao): array {
      $etapa = $sessao['etapa'];

      if ($etapa === 'inicio') {
         return self::iniciarAtendimento($telefone, $texto, $sessao);
      }

      if ($etapa === 'codigo') {
         return self::validarCodigo($telefone, $texto, $sessao);
      }

      if ($etapa === 'informar_telefone') {
         return self::receberTelefone($telefone, $texto, $sessao);
      }

      if ((int)$sessao['autenticado'] !== 1) {
         return self::iniciarAtendimento($telefone, $texto, $sessao);
      }

      $users_id = (int)$sessao['users_id'];
      $dados    = $sessao['dados'];

      if ($texto === '0' && $etapa !== 'menu') {
         self::definirEtapa($telefone, 'menu', []);
         return [self::montarMenu($telefone, $users_id)];
      }

      switch ($etapa) {
         case 'menu':
            return self::escolherMenu($telefone, $texto, $users_id, $dados);

         case 'chamados':
            return self::escolherChamado($telefone, $texto, $users_id, $dados);

         case 'chamado_acao':
            return self::acaoChamado($telefone, $texto, $users_id, $dados);

         case 'acompanhamento':
            return self::gravarAcompanhamento($telefone, $texto, $users_id, $dados);

         case 'validacoes':
            return self::escolherValidacao($telefone, $texto, $users_id, $dados);

         case 'validacao_acao':
            return self::acaoValidacao($telefone, $texto, $users_id, $dados);

         case 'validacao_comentario':
            return self::gravarValidacao($telefone, $texto, $users_id, $dados);

         case 'abrir_titulo':
            return self::abrirTitulo($telefone, $texto, $users_id, $dados);

         case 'abrir_descricao':
            return self::abrirDescricao($telefone, $texto, $users_id, $dados);
      }

      self::definirEtapa($telefone, 'menu', []);
      return [self::montarMenu($telefone, $users_id)];
   }

   static function iniciarAtendimento(string $telefone, string $texto, array $sessao): array {
      if (PluginWhatsappempresaConfig::ativo('exige_codigo')) {
         self::definirEtapa($telefone, 'codigo', [], [
            'autenticado'     => 0,
            'autenticado_ate' => null,
            'tentativas'      => 0
         ]);

         // Cliente que ja manda o codigo na primeira mensagem entra direto
         if (!empty(PluginWhatsappempresaConfig::validarCodigo($texto))) {
            return self::validarCodigo($telefone, $texto, array_merge($sessao, [
               'etapa'      => 'codigo',
               'tentativas' => 0
            ]));
         }

         return [self::textoSaudacao()];
      }

      $users_id = PluginWhatsappempresaConfig::usuarioPorTelefone($telefone);
      if ($users_id <= 0) {
         return self::pedirTelefone($telefone, []);
      }

      self::liberarAcesso($telefone, $users_id);
      return [self::montarMenu($telefone, $users_id)];
   }

   /**
    * Usado quando o WhatsApp entrega a mensagem sem o numero real (LID)
    * ou quando o numero nao consta no cadastro
    */
   static function pedirTelefone(string $telefone, array $contexto): array {
      self::definirEtapa($telefone, 'informar_telefone', $contexto, ['autenticado' => 0]);

      return [
         "Nao consegui identificar seu cadastro por este numero.\n"
         . "Envie o telefone que esta registrado no sistema, com DDD.\n"
         . "Exemplo: 71999998888"
      ];
   }

   static function receberTelefone(string $telefone, string $texto, array $sessao): array {
      $informado = PluginWhatsappempresaConfig::limparTelefone($texto);

      if (strlen($informado) < 10) {
         return ['Numero invalido. Envie apenas os digitos com DDD, por exemplo 71999998888.'];
      }

      $users_id = PluginWhatsappempresaConfig::usuarioPorTelefone($informado);

      if ($users_id <= 0) {
         $tentativas = (int)$sessao['tentativas'] + 1;
         $maximo     = (int)PluginWhatsappempresaConfig::get('tentativas_max', '3');

         if ($tentativas >= $maximo) {
            self::gravarSessao($telefone, ['tentativas' => 0, 'etapa' => 'inicio', 'autenticado' => 0]);
            return [PluginWhatsappempresaConfig::get('msg_sem_cadastro')];
         }

         self::gravarSessao($telefone, ['tentativas' => $tentativas]);
         return ["Nao encontrei nenhum usuario com este telefone. Tentativa {$tentativas} de {$maximo}."];
      }

      $contexto = $sessao['dados'];
      $contexto['telefone_informado'] = $informado;

      self::liberarAcesso($telefone, $users_id, $contexto);

      PluginWhatsappempresaLog::registrar(
         'Cadastro confirmado pelo telefone informado',
         'Conversa ' . $telefone . ' vinculada a ' . PluginWhatsappempresaConfig::nomeUsuario($users_id),
         'info',
         'autoatendimento',
         $users_id
      );

      return [
         'Cadastro localizado, ' . PluginWhatsappempresaConfig::nomeUsuario($users_id) . '.',
         self::montarMenu($telefone, $users_id)
      ];
   }

   static function validarCodigo(string $telefone, string $texto, array $sessao): array {
      $codigo = PluginWhatsappempresaConfig::validarCodigo($texto);

      if (empty($codigo)) {
         $tentativas = (int)$sessao['tentativas'] + 1;
         $maximo     = (int)PluginWhatsappempresaConfig::get('tentativas_max', '3');

         if ($tentativas >= $maximo) {
            $minutos = (int)PluginWhatsappempresaConfig::get('bloqueio_minutos', '10');
            self::gravarSessao($telefone, [
               'tentativas'    => 0,
               'etapa'         => 'inicio',
               'autenticado'   => 0,
               'bloqueado_ate' => date('Y-m-d H:i:s', time() + ($minutos * 60))
            ]);

            PluginWhatsappempresaLog::registrar(
               'Numero bloqueado',
               'Telefone ' . $telefone . ' excedeu ' . $maximo . ' tentativas de codigo.',
               'aviso',
               'autoatendimento'
            );

            return [PluginWhatsappempresaConfig::get('msg_bloqueado')];
         }

         self::gravarSessao($telefone, ['tentativas' => $tentativas]);
         return [PluginWhatsappempresaConfig::get('msg_codigo_invalido') . "\nTentativa {$tentativas} de {$maximo}."];
      }

      $users_id = PluginWhatsappempresaConfig::usuarioPorTelefone($telefone);
      if ($users_id <= 0) {
         self::gravarSessao($telefone, ['tentativas' => 0]);
         return self::pedirTelefone($telefone, ['entities_id' => (int)$codigo['entities_id']]);
      }

      self::liberarAcesso($telefone, $users_id, ['entities_id' => (int)$codigo['entities_id']]);

      $minutos = self::minutosDaSessao();

      PluginWhatsappempresaLog::registrar(
         'Acesso liberado no autoatendimento',
         'Telefone ' . $telefone . ' - ' . PluginWhatsappempresaConfig::nomeUsuario($users_id)
         . ($minutos > 0 ? ' - sessao valida por ' . $minutos . ' minuto(s)' : ''),
         'info',
         'autoatendimento',
         $users_id
      );

      $abertura = 'Acesso liberado, ' . PluginWhatsappempresaConfig::nomeUsuario($users_id) . '.';
      if ($minutos > 0) {
         $abertura .= "\nSua sessao fica ativa por " . $minutos . ' minuto(s).';
      }

      // Um fluxo montado pode assumir o atendimento logo depois do codigo
      $montado = PluginWhatsappempresaConstrutor::porCodigo();
      if ($montado !== null) {
         $resultado = PluginWhatsappempresaConstrutor::iniciar($telefone, (int)$montado['id'], $users_id);
         return array_merge([$abertura], $resultado['respostas']);
      }

      return [$abertura, self::montarMenu($telefone, $users_id)];
   }

   /**
    * Monta o menu apenas com as opcoes habilitadas, com botoes clicaveis
    */
   static function montarMenu(string $telefone, int $users_id) {
      $opcoes  = [];
      $nomesJa = [];

      // Fluxos do painel vem primeiro: sao eles que o cliente edita
      foreach (PluginWhatsappempresaConstrutor::opcoesDeMenu() as $montado) {
         $opcoes[] = ['chave' => 'construtor:' . $montado['id'], 'rotulo' => $montado['nome']];
         $nomesJa[] = self::normalizar((string)$montado['nome']);
      }

      $nativas = [];
      if (PluginWhatsappempresaConfig::ativo('menu_chamados')) {
         $nativas[] = ['chave' => 'chamados', 'rotulo' => 'Meus chamados'];
      }
      if (PluginWhatsappempresaConfig::ativo('menu_validacoes')) {
         $nativas[] = ['chave' => 'validacoes', 'rotulo' => 'Validacoes'];
      }
      if (PluginWhatsappempresaConfig::ativo('menu_abrir')) {
         $nativas[] = ['chave' => 'abrir', 'rotulo' => 'Abrir chamado'];
      }
      if (PluginWhatsappempresaConfig::ativo('menu_falar_tecnico') && PluginWhatsappempresaConfig::ativo('fluxo_conversa')) {
         $nativas[] = ['chave' => 'falar', 'rotulo' => 'Falar com tecnico'];
      }

      // Opcao fixa some quando existe um fluxo do painel com o mesmo nome
      foreach ($nativas as $nativa) {
         if (!in_array(self::normalizar((string)$nativa['rotulo']), $nomesJa, true)) {
            $opcoes[] = $nativa;
         }
      }

      if (empty($opcoes)) {
         return 'Nenhuma opcao de atendimento esta disponivel no momento.';
      }

      $mapa  = [];
      $lista = [];

      foreach (array_slice($opcoes, 0, 8) as $indice => $opcao) {
         $numero = (string)($indice + 1);
         $mapa[$numero] = $opcao['chave'];
         $lista[] = ['id' => $numero, 'rotulo' => (string)$opcao['rotulo']];
      }

      $mapa['9'] = 'encerrar';
      $lista[] = ['id' => '9', 'rotulo' => 'Encerrar'];

      self::definirEtapa($telefone, 'menu', ['mapa' => $mapa], ['users_id' => $users_id]);

      return PluginWhatsappempresaMensagem::comOpcoes(
         '*Menu de atendimento*',
         $lista,
         (string)PluginWhatsappempresaConfig::get('msg_rodape'),
         'Atendimento'
      );
   }

   static function escolherMenu(string $telefone, string $texto, int $users_id, array $dados): array {
      $mapa    = $dados['mapa'] ?? [];
      $escolha = trim(preg_replace('/\D/', '', $texto));

      if (!isset($mapa[$escolha])) {
         return ['Opcao invalida.', self::montarMenu($telefone, $users_id)];
      }

      $destino = (string)$mapa[$escolha];

      if (str_starts_with($destino, 'construtor:')) {
         $fluxos_id = (int)substr($destino, 11);
         $resultado = PluginWhatsappempresaConstrutor::iniciar($telefone, $fluxos_id, $users_id);
         return $resultado['respostas'];
      }

      switch ($destino) {
         case 'encerrar':
            self::definirEtapa($telefone, 'inicio', [], ['autenticado' => 0, 'autenticado_ate' => null]);
            return [PluginWhatsappempresaConfig::get('msg_despedida')];
         case 'chamados':
            return self::listarChamados($telefone, $users_id);
         case 'validacoes':
            return self::listarValidacoes($telefone, $users_id);
         case 'abrir':
            self::definirEtapa($telefone, 'abrir_titulo', []);
            return ["*Abertura de chamado*\nEnvie o titulo do chamado."];
         case 'falar':
            return self::listarChamados($telefone, $users_id, true);
      }

      return [self::montarMenu($telefone, $users_id)];
   }

   /**
    * Coloca o numero num fluxo montado no painel
    */
   static function abrirConstrutor(string $telefone, int $fluxos_id, int $users_id): array {
      $resultado = PluginWhatsappempresaConstrutor::iniciar($telefone, $fluxos_id, $users_id);
      $atual     = self::obterSessao($telefone);

      return [
         'respostas'    => $resultado['respostas'],
         'conversas_id' => (int)$atual['conversas_id'],
         'tickets_id'   => (int)$atual['tickets_id'],
         'fluxo'        => self::fluxoAtual($atual),
         'fluxos_id'    => $fluxos_id
      ];
   }

   // ============================================
   // Meus chamados
   // ============================================

   static function chamadosDoUsuario(int $users_id): array {
      global $DB;

      $itens = [];
      $iterator = $DB->request([
         'SELECT'    => ['glpi_tickets.id', 'glpi_tickets.name', 'glpi_tickets.status', 'glpi_tickets.date', 'glpi_tickets.content'],
         'FROM'      => 'glpi_tickets',
         'LEFT JOIN' => [
            'glpi_tickets_users' => [
               'ON' => [
                  'glpi_tickets_users' => 'tickets_id',
                  'glpi_tickets'       => 'id',
                  ['AND' => ['glpi_tickets_users.type' => CommonITILActor::REQUESTER]]
               ]
            ]
         ],
         'WHERE' => [
            'glpi_tickets_users.users_id' => $users_id,
            'glpi_tickets.is_deleted'     => 0,
            'NOT' => ['glpi_tickets.status' => [Ticket::CLOSED]]
         ],
         'ORDER' => 'glpi_tickets.id DESC',
         'LIMIT' => 10
      ]);

      foreach ($iterator as $linha) {
         $itens[] = $linha;
      }

      return $itens;
   }

   static function listarChamados(string $telefone, int $users_id, bool $paraConversa = false): array {
      $chamados = self::chamadosDoUsuario($users_id);

      if (empty($chamados)) {
         return ['Voce nao possui chamados abertos.', self::montarMenu($telefone, $users_id)];
      }

      $mapa  = [];
      $lista = [];

      foreach ($chamados as $indice => $chamado) {
         $numero = (string)($indice + 1);
         $mapa[$numero] = (int)$chamado['id'];
         $lista[] = [
            'id'        => $numero,
            'rotulo'    => '#' . $chamado['id'] . ' ' . mb_substr((string)$chamado['name'], 0, 18),
            'descricao' => Ticket::getStatus((int)$chamado['status']) . ' | ' . Html::convDateTime($chamado['date'])
         ];
      }

      self::definirEtapa($telefone, 'chamados', ['mapa' => $mapa, 'conversa' => $paraConversa]);

      return [PluginWhatsappempresaMensagem::comOpcoes(
         '*Seus chamados abertos*',
         $lista,
         (string)PluginWhatsappempresaConfig::get('msg_rodape'),
         'Meus chamados'
      )];
   }

   static function escolherChamado(string $telefone, string $texto, int $users_id, array $dados): array {
      $mapa    = $dados['mapa'] ?? [];
      $escolha = trim(preg_replace('/\D/', '', $texto));

      if (!isset($mapa[$escolha])) {
         return ['Opcao invalida. Escolha um dos chamados da lista.'];
      }

      $tickets_id = (int)$mapa[$escolha];

      if (!empty($dados['conversa'])) {
         return self::iniciarConversa($telefone, $users_id, $tickets_id);
      }

      $ticket = new Ticket();
      if (!$ticket->getFromDB($tickets_id)) {
         return ['Chamado nao encontrado.', self::montarMenu($telefone, $users_id)];
      }

      $conteudo = mb_substr(trim(strip_tags((string)$ticket->fields['content'])), 0, 700);
      $tecnico  = PluginWhatsappempresaConversa::tecnicoDoTicket($tickets_id);

      $detalhe = "*Chamado #{$tickets_id}*\n"
               . '*Titulo:* ' . $ticket->fields['name'] . "\n"
               . '*Status:* ' . Ticket::getStatus((int)$ticket->fields['status']) . "\n"
               . '*Prioridade:* ' . CommonITILObject::getPriorityName((int)$ticket->fields['priority']) . "\n"
               . '*Aberto em:* ' . Html::convDateTime($ticket->fields['date']) . "\n"
               . '*Tecnico:* ' . ($tecnico > 0 ? PluginWhatsappempresaConfig::nomeUsuario($tecnico) : 'Nao atribuido') . "\n\n"
               . "*Descricao:*\n" . html_entity_decode($conteudo, ENT_QUOTES, 'UTF-8');

      $opcoes = [['id' => '1', 'rotulo' => 'Acompanhar']];

      if (PluginWhatsappempresaConfig::ativo('fluxo_conversa') && PluginWhatsappempresaConfig::ativo('menu_falar_tecnico')) {
         $opcoes[] = ['id' => '2', 'rotulo' => 'Falar com tecnico'];
      }

      $opcoes[] = ['id' => '3', 'rotulo' => 'Ultimos registros'];
      $opcoes[] = ['id' => '0', 'rotulo' => 'Voltar ao menu'];

      self::definirEtapa($telefone, 'chamado_acao', ['tickets_id' => $tickets_id]);

      return [PluginWhatsappempresaMensagem::comOpcoes($detalhe, $opcoes, '', 'Chamado #' . $tickets_id)];
   }

   static function acaoChamado(string $telefone, string $texto, int $users_id, array $dados): array {
      $tickets_id = (int)($dados['tickets_id'] ?? 0);
      $escolha    = trim(preg_replace('/\D/', '', $texto));

      if ($escolha === '1') {
         self::definirEtapa($telefone, 'acompanhamento', ['tickets_id' => $tickets_id]);
         return ["Envie o texto do acompanhamento para o chamado #{$tickets_id}."];
      }

      if ($escolha === '2' && PluginWhatsappempresaConfig::ativo('fluxo_conversa')) {
         return self::iniciarConversa($telefone, $users_id, $tickets_id);
      }

      if ($escolha === '3') {
         return [self::ultimosAcompanhamentos($tickets_id)];
      }

      return ['Opcao invalida. Toque em uma das opcoes disponiveis.'];
   }

   static function ultimosAcompanhamentos(int $tickets_id): string {
      global $DB;

      $linhas = ["*Acompanhamentos do chamado #{$tickets_id}*"];
      $total  = 0;

      $iterator = $DB->request([
         'FROM'  => 'glpi_itilfollowups',
         'WHERE' => ['itemtype' => 'Ticket', 'items_id' => $tickets_id, 'is_private' => 0],
         'ORDER' => 'id DESC',
         'LIMIT' => 5
      ]);

      foreach ($iterator as $linha) {
         $total++;
         $conteudo = mb_substr(trim(strip_tags((string)$linha['content'])), 0, 300);
         $linhas[] = "\n" . Html::convDateTime($linha['date_creation']) . ' - '
                   . PluginWhatsappempresaConfig::nomeUsuario((int)$linha['users_id']) . "\n"
                   . html_entity_decode($conteudo, ENT_QUOTES, 'UTF-8');
      }

      if ($total === 0) {
         $linhas[] = 'Nenhum acompanhamento publico registrado.';
      }

      return implode("\n", $linhas);
   }

   static function gravarAcompanhamento(string $telefone, string $texto, int $users_id, array $dados): array {
      $tickets_id = (int)($dados['tickets_id'] ?? 0);

      if (mb_strlen($texto) < 3) {
         return ['Texto muito curto. Escreva o acompanhamento com mais detalhes.'];
      }

      if (!self::ehRequerente($tickets_id, $users_id)) {
         self::definirEtapa($telefone, 'menu', []);
         return ['Voce nao e requerente deste chamado.', self::montarMenu($telefone, $users_id)];
      }

      $ok = PluginWhatsappempresaConversa::gravarFollowup($tickets_id, nl2br($texto), $users_id, false);

      self::definirEtapa($telefone, 'menu', []);

      if (!$ok) {
         return ['Nao foi possivel registrar o acompanhamento.', self::montarMenu($telefone, $users_id)];
      }

      PluginWhatsappempresaLog::registrar(
         'Acompanhamento via WhatsApp',
         'Chamado #' . $tickets_id . ' por ' . PluginWhatsappempresaConfig::nomeUsuario($users_id),
         'info',
         'autoatendimento',
         $users_id
      );

      return ["Acompanhamento registrado no chamado #{$tickets_id}.", self::montarMenu($telefone, $users_id)];
   }

   static function ehRequerente(int $tickets_id, int $users_id): bool {
      global $DB;

      $iterator = $DB->request([
         'COUNT' => 'total',
         'FROM'  => 'glpi_tickets_users',
         'WHERE' => ['tickets_id' => $tickets_id, 'users_id' => $users_id, 'type' => CommonITILActor::REQUESTER]
      ]);

      foreach ($iterator as $linha) {
         return (int)$linha['total'] > 0;
      }

      return false;
   }

   // ============================================
   // Validacoes pendentes
   // ============================================

   static function listarValidacoes(string $telefone, int $users_id): array {
      $pendentes = PluginWhatsappempresaValidacao::pendentesDoUsuario($users_id);

      if (empty($pendentes)) {
         return ['Voce nao possui validacoes pendentes.', self::montarMenu($telefone, $users_id)];
      }

      $mapa  = [];
      $lista = [];

      foreach ($pendentes as $indice => $item) {
         $numero = (string)($indice + 1);
         $mapa[$numero] = [
            'itemtype'   => $item['itemtype'],
            'items_id'   => $item['items_id'],
            'objetos_id' => $item['objetos_id'],
            'titulo'     => $item['titulo']
         ];
         $rotulo = ($item['itemtype'] === 'ChangeValidation') ? 'Mudanca' : 'Chamado';
         $lista[] = [
            'id'        => $numero,
            'rotulo'    => $rotulo . ' #' . $item['objetos_id'],
            'descricao' => mb_substr((string)$item['titulo'], 0, 70)
         ];
      }

      self::definirEtapa($telefone, 'validacoes', ['mapa' => $mapa]);

      return [PluginWhatsappempresaMensagem::comOpcoes(
         '*Validacoes pendentes*',
         $lista,
         (string)PluginWhatsappempresaConfig::get('msg_rodape'),
         'Aprovacoes'
      )];
   }

   static function escolherValidacao(string $telefone, string $texto, int $users_id, array $dados): array {
      $mapa    = $dados['mapa'] ?? [];
      $escolha = trim(preg_replace('/\D/', '', $texto));

      if (!isset($mapa[$escolha])) {
         return ['Opcao invalida. Escolha um dos pedidos da lista.'];
      }

      $item   = $mapa[$escolha];
      $classe = ($item['itemtype'] === 'ChangeValidation') ? 'Change' : 'Ticket';
      $objeto = new $classe();

      $descricao = '';
      if ($objeto->getFromDB((int)$item['objetos_id'])) {
         $descricao = mb_substr(trim(strip_tags((string)$objeto->fields['content'])), 0, 700);
         $descricao = html_entity_decode($descricao, ENT_QUOTES, 'UTF-8');
      }

      self::definirEtapa($telefone, 'validacao_acao', ['validacao' => $item]);

      return [PluginWhatsappempresaMensagem::comOpcoes(
         "*{$item['titulo']}*\n\n{$descricao}",
         [
            ['id' => '1', 'rotulo' => 'Aprovar'],
            ['id' => '2', 'rotulo' => 'Recusar'],
            ['id' => '0', 'rotulo' => 'Voltar']
         ],
         'Escolha uma das opcoes'
      )];
   }

   static function acaoValidacao(string $telefone, string $texto, int $users_id, array $dados): array {
      $escolha = trim(preg_replace('/\D/', '', $texto));

      if (!in_array($escolha, ['1', '2'], true)) {
         return ['Opcao invalida. Toque em Aprovar ou Recusar.'];
      }

      $item = $dados['validacao'] ?? [];
      $item['aprovado'] = ($escolha === '1');

      self::definirEtapa($telefone, 'validacao_comentario', ['validacao' => $item]);

      return ['Envie o comentario da validacao.'];
   }

   static function gravarValidacao(string $telefone, string $texto, int $users_id, array $dados): array {
      $item = $dados['validacao'] ?? [];

      if (empty($item)) {
         self::definirEtapa($telefone, 'menu', []);
         return [self::montarMenu($telefone, $users_id)];
      }

      self::assumirUsuario($users_id);

      $resultado = PluginWhatsappempresaValidacao::responder(
         $item['itemtype'],
         (int)$item['items_id'],
         (bool)$item['aprovado'],
         $texto,
         $users_id
      );

      self::definirEtapa($telefone, 'menu', []);

      if (!$resultado['ok']) {
         return ['Nao foi possivel registrar: ' . $resultado['erro'], self::montarMenu($telefone, $users_id)];
      }

      $situacao = $item['aprovado'] ? 'APROVADA' : 'RECUSADA';
      $rotulo   = $resultado['mudanca'] ? 'Mudanca' : 'Chamado';

      return [
         "Validacao {$situacao}.\n{$rotulo} #{$resultado['objetos_id']}\nComentario: {$texto}",
         self::montarMenu($telefone, $users_id)
      ];
   }

   // ============================================
   // Abertura de chamado
   // ============================================

   static function abrirTitulo(string $telefone, string $texto, int $users_id, array $dados): array {
      if (mb_strlen($texto) < 4) {
         return ['Titulo muito curto. Envie um titulo com pelo menos 4 caracteres.'];
      }

      self::definirEtapa($telefone, 'abrir_descricao', ['titulo' => mb_substr($texto, 0, 200)]);

      return ['Agora envie a descricao detalhada do problema.'];
   }

   static function abrirDescricao(string $telefone, string $texto, int $users_id, array $dados): array {
      global $DB;

      if (mb_strlen($texto) < 5) {
         return ['Descricao muito curta. Detalhe um pouco mais o problema.'];
      }

      $titulo = (string)($dados['titulo'] ?? 'Solicitacao via WhatsApp');

      $entities_id = 0;
      foreach ($DB->request([
         'SELECT' => ['entities_id'],
         'FROM'   => 'glpi_users',
         'WHERE'  => ['id' => $users_id],
         'LIMIT'  => 1
      ]) as $linha) {
         $entities_id = (int)$linha['entities_id'];
      }

      self::assumirUsuario($users_id);

      $conteudo = $texto . "\n\nAberto pelo WhatsApp - telefone " . $telefone;

      $ticket = new Ticket();
      $tickets_id = $ticket->add([
         'name'                => $titulo,
         'content'             => nl2br($conteudo),
         'entities_id'         => $entities_id,
         'users_id_recipient'  => $users_id,
         '_users_id_requester' => $users_id,
         'type'                => (int)PluginWhatsappempresaConfig::get('abertura_tipo', '1'),
         'urgency'             => (int)PluginWhatsappempresaConfig::get('abertura_urgencia', '3'),
         'itilcategories_id'   => (int)PluginWhatsappempresaConfig::get('abertura_categoria', '0'),
         'status'              => Ticket::INCOMING
      ]);

      self::definirEtapa($telefone, 'menu', []);

      if (!$tickets_id) {
         return ['Nao foi possivel abrir o chamado. Procure o suporte.', self::montarMenu($telefone, $users_id)];
      }

      PluginWhatsappempresaLog::registrar(
         'Chamado aberto via WhatsApp',
         'Chamado #' . $tickets_id . ' por ' . PluginWhatsappempresaConfig::nomeUsuario($users_id),
         'info',
         'autoatendimento',
         $users_id
      );

      return [
         "Chamado *#{$tickets_id}* aberto com sucesso.\n*Titulo:* {$titulo}\nVoce recebera as atualizacoes por aqui.",
         self::montarMenu($telefone, $users_id)
      ];
   }

   // ============================================
   // Conversa pedida pelo cliente no menu
   // ============================================

   static function iniciarConversa(string $telefone, int $users_id, int $tickets_id): array {
      $tecnicos_id = PluginWhatsappempresaConversa::tecnicoDoTicket($tickets_id);

      if ($tecnicos_id <= 0) {
         self::definirEtapa($telefone, 'menu', []);
         return ["O chamado #{$tickets_id} ainda nao tem tecnico atribuido.", self::montarMenu($telefone, $users_id)];
      }

      $conversas_id = PluginWhatsappempresaConversa::obterOuCriar($telefone, [
         'tickets_id'   => $tickets_id,
         'users_id'     => $users_id,
         'tecnicos_id'  => $tecnicos_id,
         'nome_contato' => PluginWhatsappempresaConfig::nomeUsuario($users_id),
         'origem'       => 'cliente'
      ]);

      PluginWhatsappempresaConversa::criarAlerta(
         $tecnicos_id,
         $conversas_id,
         $tickets_id,
         $telefone,
         PluginWhatsappempresaConfig::nomeUsuario($users_id) . ' iniciou uma conversa pelo WhatsApp.'
      );

      self::trocarFluxo($telefone, self::FLUXO_CONVERSA, [
         'tickets_id'   => $tickets_id,
         'conversas_id' => $conversas_id,
         'origem'       => 'cliente'
      ], 'Conversa pedida pelo cliente no menu');

      return [
         'Voce esta falando com ' . PluginWhatsappempresaConfig::nomeUsuario($tecnicos_id)
         . ", tecnico do chamado #{$tickets_id}.\nEnvie sua mensagem. Escreva *SAIR* para encerrar a conversa."
      ];
   }

   /**
    * Preenche a sessao PHP com o usuario para que o GLPI registre o autor correto
    */
   static function assumirUsuario(int $users_id): void {
      global $DB;

      if ($users_id <= 0) {
         return;
      }

      // Com alguem logado (aba do chamado, painel, cron interno) a sessao real e mantida:
      // sobrescreve-la deixa o perfil incompleto e quebra as telas seguintes do usuario
      if ((int)Session::getLoginUserID() > 0 && empty($_SESSION['wae_sessao_assumida'])) {
         return;
      }

      $usuario = new User();
      if (!$usuario->getFromDB($users_id)) {
         return;
      }

      $_SESSION['wae_sessao_assumida'] = true;
      $_SESSION['glpiID']            = $users_id;
      $_SESSION['glpiname']          = $usuario->fields['name'];
      $_SESSION['glpirealname']      = $usuario->fields['realname'] ?? '';
      $_SESSION['glpifirstname']     = $usuario->fields['firstname'] ?? '';
      $_SESSION['glpi_use_mode']     = Session::NORMAL_MODE;
      $_SESSION['glpiactive_entity'] = (int)$usuario->fields['entities_id'];

      $entidades   = [(int)$usuario->fields['entities_id']];
      $profiles_id = 0;

      foreach ($DB->request([
         'SELECT' => ['entities_id', 'profiles_id', 'is_default_profile'],
         'FROM'   => 'glpi_profiles_users',
         'WHERE'  => ['users_id' => $users_id],
         'ORDER'  => 'is_default_profile DESC'
      ]) as $linha) {
         $entidades[] = (int)$linha['entities_id'];
         if ($profiles_id <= 0) {
            $profiles_id = (int)$linha['profiles_id'];
         }
      }

      $_SESSION['glpiactiveentities']        = array_values(array_unique($entidades));
      $_SESSION['glpiactiveentities_string'] = implode(',', $_SESSION['glpiactiveentities']);
      // Sem os direitos do perfil o GLPI recusa gravar acompanhamento e chamado
      if ($profiles_id > 0) {
         $perfil = new Profile();
         if ($perfil->getFromDB($profiles_id)) {
            $_SESSION['glpiactiveprofile']       = $perfil->fields;
            $_SESSION['glpiactiveprofile']['id'] = $profiles_id;

            foreach ($DB->request([
               'SELECT' => ['name', 'rights'],
               'FROM'   => 'glpi_profilerights',
               'WHERE'  => ['profiles_id' => $profiles_id]
            ]) as $direito) {
               $_SESSION['glpiactiveprofile'][$direito['name']] = (int)$direito['rights'];
            }
         }
      }
   }
}
