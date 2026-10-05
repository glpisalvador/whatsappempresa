<?php

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access this file directly");
}

/**
 * Servidor WhatsApp (Node.js) totalmente administrado pelo GLPI.
 *
 * Tudo roda dentro de files/_plugins/whatsappempresa, onde o GLPI ja tem escrita:
 *   node/  Node.js portatil baixado de nodejs.org (SHA-256 conferido)
 *   app/   servidor.js + package.json copiados do plugin e node_modules instalados pelo npm
 *   auth/  sessao do aparelho pareado da conexao 1 (numeros antigos continuam aqui)
 *   run/   pid, log e scripts da conexao 1, vigia geral e tarefas em segundo plano
 *   conexoes/<id>/auth e conexoes/<id>/run  demais numeros conectados (um processo Node cada)
 *
 * O vigia e uma linha no crontab do proprio usuario do servidor web: religa o processo
 * em ate 1 minuto se ele cair (inclusive quando o Apache e reiniciado).
 */
class PluginWhatsappempresaServidor {

   const PACOTES     = ['@whiskeysockets/baileys', 'pino', 'qrcode'];
   const NODE_MINIMO = 20;
   const MARCA_CRON  = '# whatsappempresa-vigia';

   static protected array $hostCache = [];

   // ============================================
   // Pastas
   // ============================================

   static function base(): string {
      $raiz = defined('GLPI_PLUGIN_DOC_DIR') ? GLPI_PLUGIN_DOC_DIR : (GLPI_ROOT . '/files/_plugins');
      return $raiz . '/whatsappempresa';
   }

   static function pastaNode(): string { return self::base() . '/node'; }
   static function pastaApp(): string  { return self::base() . '/app'; }
   /** Pasta geral: tarefas (Node/npm), vigia e crontab */
   static function pastaRun(): string  { return self::base() . '/run'; }

   /** Pasta propria de uma conexao (a 1 usa as pastas originais, sem mover o pareamento) */
   static function pastaConexao(int $id): string { return self::base() . '/conexoes/' . $id; }

   static function pastaAuth(?int $id = null): string {
      $id = $id ?? PluginWhatsappempresaConexao::atual();
      return $id === 1 ? self::base() . '/auth' : self::pastaConexao($id) . '/auth';
   }

   static function pastaRunConexao(?int $id = null): string {
      $id = $id ?? PluginWhatsappempresaConexao::atual();
      return $id === 1 ? self::pastaRun() : self::pastaConexao($id) . '/run';
   }
   static function pastaMidia(): string { return self::base() . '/midia'; }

   /**
    * Caminho absoluto de um arquivo de midia a partir do caminho relativo gravado no banco.
    * Recusa qualquer coisa fora da pasta de midia.
    */
   static function caminhoMidia(string $relativo): ?string {
      if ($relativo === '' || !preg_match('#^[0-9]{6}/[A-Za-z0-9_.-]+$#', $relativo)) {
         return null;
      }
      $caminho = self::pastaMidia() . '/' . $relativo;
      return is_file($caminho) ? $caminho : null;
   }

   static function arquivoLog(): string   { return self::pastaRunConexao() . '/servidor.log'; }
   static function arquivoPid(): string   { return self::pastaRunConexao() . '/servidor.pid'; }
   static function arquivoAtivo(): string { return self::pastaRunConexao() . '/ativo'; }

   /**
    * Cria a estrutura de pastas. Devolve a mensagem de erro ou null.
    */
   static function prepararPastas(): ?string {
      foreach ([self::base(), self::pastaApp(), self::pastaRun(), self::pastaMidia(), self::pastaAuth(), self::pastaRunConexao()] as $pasta) {
         if (!is_dir($pasta)) {
            @mkdir($pasta, 0770, true);
         }
         if (!is_dir($pasta) || !is_writable($pasta)) {
            return sprintf('A pasta %s nao pode ser criada ou nao aceita escrita pelo usuario %s.', $pasta, self::usuarioPhp());
         }
      }

      // Sessao pareada por versoes anteriores, que ficava dentro da pasta do plugin
      $antiga = PluginWhatsappempresaConfig::pastaServidor() . '/auth';
      if (is_file($antiga . '/creds.json') && !is_file(self::pastaAuth(1) . '/creds.json')) {
         foreach ((array)glob($antiga . '/*') as $arquivo) {
            if (is_file($arquivo)) {
               @copy($arquivo, self::pastaAuth(1) . '/' . basename($arquivo));
            }
         }
      }

      return null;
   }

   // ============================================
   // Execucao de comandos
   // ============================================

   static function usuarioPhp(): string {
      if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
         $dados = @posix_getpwuid(posix_geteuid());
         if (!empty($dados['name'])) {
            return (string)$dados['name'];
         }
      }
      return (string)get_current_user();
   }

   static function shellDisponivel(): bool {
      if (!function_exists('shell_exec')) {
         return false;
      }
      $desabilitadas = array_map('trim', explode(',', (string)ini_get('disable_functions')));
      return !in_array('shell_exec', $desabilitadas, true);
   }

   static function executar(string $comando): string {
      if (!self::shellDisponivel()) {
         return '';
      }
      return (string)@shell_exec($comando);
   }

   static function comandoExiste(string $nome): bool {
      return trim(self::executar('command -v ' . escapeshellarg($nome) . ' 2>/dev/null')) !== '';
   }

   // ============================================
   // Node.js portatil
   // ============================================

   static function arquitetura(): string {
      $maquina = strtolower(php_uname('m'));
      return match (true) {
         in_array($maquina, ['x86_64', 'amd64'], true)          => 'x64',
         in_array($maquina, ['aarch64', 'arm64'], true)         => 'arm64',
         default                                                => '',
      };
   }

   static function binNode(): string {
      $bin = self::pastaNode() . '/bin/node';
      return (is_file($bin) && is_executable($bin)) ? $bin : '';
   }

   static function versaoNode(): string {
      $bin = self::binNode();
      return $bin === '' ? '' : trim(self::executar(escapeshellarg($bin) . ' --version 2>/dev/null'));
   }

   static function nodeValido(): bool {
      $versao = ltrim(self::versaoNode(), 'v');
      return $versao !== '' && (int)explode('.', $versao)[0] >= self::NODE_MINIMO;
   }

   static function baixarTexto(string $url, int $timeout = 20): ?string {
      if (!function_exists('curl_init')) {
         return null;
      }
      $ch = curl_init($url);
      curl_setopt_array($ch, [
         CURLOPT_RETURNTRANSFER => true,
         CURLOPT_FOLLOWLOCATION => true,
         CURLOPT_CONNECTTIMEOUT => 8,
         CURLOPT_TIMEOUT        => $timeout,
         CURLOPT_USERAGENT      => 'GLPI-whatsappempresa'
      ]);
      $corpo = curl_exec($ch);
      $http  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
      curl_close($ch);
      return ($corpo !== false && $http === 200) ? (string)$corpo : null;
   }

   /**
    * Ultima versao LTS do Node.js para esta arquitetura, com a assinatura oficial
    */
   static function ultimaLts(): array {
      $arq = self::arquitetura();
      if ($arq === '') {
         return ['erro' => 'Arquitetura ' . php_uname('m') . ' nao suportada (apenas x86_64 e arm64).'];
      }

      $indice = json_decode((string)self::baixarTexto('https://nodejs.org/dist/index.json'), true);
      if (!is_array($indice)) {
         return ['erro' => 'Nao foi possivel consultar nodejs.org. Verifique o acesso a internet do servidor.'];
      }

      foreach ($indice as $release) {
         if (empty($release['lts']) || !in_array('linux-' . $arq, (array)($release['files'] ?? []), true)) {
            continue;
         }

         $versao  = (string)$release['version'];
         $formato = self::comandoExiste('xz') ? 'tar.xz' : 'tar.gz';
         $pasta   = 'node-' . $versao . '-linux-' . $arq;
         $arquivo = $pasta . '.' . $formato;

         $somas = (string)self::baixarTexto('https://nodejs.org/dist/' . $versao . '/SHASUMS256.txt');
         if (!preg_match('/^([a-f0-9]{64})\s+' . preg_quote($arquivo, '/') . '$/m', $somas, $m)) {
            return ['erro' => 'Assinatura SHA-256 do Node.js ' . $versao . ' nao encontrada.'];
         }

         return [
            'versao'  => $versao,
            'lts'     => (string)$release['lts'],
            'pasta'   => $pasta,
            'arquivo' => $arquivo,
            'url'     => 'https://nodejs.org/dist/' . $versao . '/' . $arquivo,
            'sha256'  => $m[1]
         ];
      }

      return ['erro' => 'Nenhuma versao LTS do Node.js encontrada para linux-' . $arq . '.'];
   }

   /**
    * Baixa e instala o Node.js portatil em segundo plano
    */
   static function instalarNode(): ?string {
      $erro = self::verificarBasico();
      if ($erro !== null) {
         return $erro;
      }

      $lts = self::ultimaLts();
      if (isset($lts['erro'])) {
         return $lts['erro'];
      }

      $base = self::base();
      $script = 'cd ' . escapeshellarg($base) . "\n"
         . "rm -rf node.baixando\nmkdir node.baixando\n"
         . 'echo "Baixando Node.js ' . $lts['versao'] . ' (' . $lts['lts'] . ')..."' . "\n"
         . 'curl -fsSL --retry 3 --connect-timeout 15 -o ' . escapeshellarg('node.baixando/' . $lts['arquivo']) . ' ' . escapeshellarg($lts['url']) . "\n"
         . 'echo "Conferindo a assinatura SHA-256 oficial..."' . "\n"
         . 'echo ' . escapeshellarg($lts['sha256'] . '  node.baixando/' . $lts['arquivo']) . ' | sha256sum -c -' . "\n"
         . 'echo "Extraindo..."' . "\n"
         . 'tar -xf ' . escapeshellarg('node.baixando/' . $lts['arquivo']) . " -C node.baixando\n"
         . "rm -rf node.antigo\n"
         . "if [ -d node ]; then mv node node.antigo; fi\n"
         . 'mv ' . escapeshellarg('node.baixando/' . $lts['pasta']) . " node\n"
         . "rm -rf node.baixando node.antigo\n"
         . 'echo "Instalado: $(./node/bin/node --version)"' . "\n";

      return self::iniciarTarefa('node', $script);
   }

   // ============================================
   // Aplicacao Node e dependencias
   // ============================================

   /** Arquivos do servidor que vem com o plugin */
   static function arquivosFonte(): array {
      $fonte = PluginWhatsappempresaConfig::pastaServidor();
      return [
         'servidor.js'  => $fonte . '/servidor.js',
         'package.json' => $fonte . '/package.json'
      ];
   }

   /** A copia em app/ e igual ao que veio com esta versao do plugin? */
   static function appSincronizado(): bool {
      foreach (self::arquivosFonte() as $nome => $origem) {
         $destino = self::pastaApp() . '/' . $nome;
         if (!is_file($destino) || @md5_file($destino) !== @md5_file($origem)) {
            return false;
         }
      }
      return true;
   }

   static function sincronizarApp(): ?string {
      foreach (self::arquivosFonte() as $nome => $origem) {
         if (!is_file($origem)) {
            return 'Arquivo ' . $nome . ' nao encontrado na pasta servidor do plugin.';
         }
         if (!@copy($origem, self::pastaApp() . '/' . $nome)) {
            return 'Nao foi possivel copiar ' . $nome . ' para ' . self::pastaApp() . '.';
         }
      }
      return null;
   }

   /**
    * Situacao de cada pacote em app/node_modules e se package.json mudou desde a instalacao
    */
   static function situacaoDependencias(): array {
      $modulos  = self::pastaApp() . '/node_modules';
      $pacotes  = [];
      $faltando = [];

      foreach (self::PACOTES as $pacote) {
         $manifesto = $modulos . '/' . $pacote . '/package.json';
         $presente  = @is_file($manifesto);
         $versao    = '';
         if ($presente) {
            $versao = (string)(json_decode((string)@file_get_contents($manifesto), true)['version'] ?? '');
         } else {
            $faltando[] = $pacote;
         }
         $pacotes[] = ['nome' => $pacote, 'presente' => $presente, 'versao' => $versao];
      }

      $fonte     = self::arquivosFonte()['package.json'];
      $instalado = (string)PluginWhatsappempresaConfig::get('dependencias_hash', '');
      $atual     = is_file($fonte) ? (string)md5_file($fonte) : '';

      return [
         'completo'    => empty($faltando),
         'atualizado'  => empty($faltando) && $instalado !== '' && $instalado === $atual,
         'pacotes'     => $pacotes,
         'faltando'    => $faltando
      ];
   }

   /**
    * Instala as dependencias com o npm do Node portatil, em segundo plano
    */
   static function instalarDependencias(): ?string {
      $erro = self::verificarBasico();
      if ($erro !== null) {
         return $erro;
      }
      if (!self::nodeValido()) {
         return 'Instale o Node.js antes das dependencias.';
      }
      $erro = self::sincronizarApp();
      if ($erro !== null) {
         return $erro;
      }

      $base = self::base();
      $app  = self::pastaApp();
      $script = 'export PATH=' . escapeshellarg($base . '/node/bin') . ':"$PATH"' . "\n"
         . 'export HOME=' . escapeshellarg($app) . "\n"
         . 'export npm_config_cache=' . escapeshellarg($app . '/.npm-cache') . "\n"
         . "export npm_config_update_notifier=false npm_config_fund=false npm_config_audit=false npm_config_loglevel=warn\n"
         . 'cd ' . escapeshellarg($app) . "\n"
         . 'echo "Node $(node --version) - npm $(npm --version)"' . "\n"
         . 'echo "Instalando pacotes (pode levar alguns minutos)..."' . "\n"
         . "npm install --omit=dev --no-audit --no-fund\n"
         . 'echo "Dependencias instaladas."' . "\n";

      return self::iniciarTarefa('dependencias', $script);
   }

   /**
    * Chamado quando a tarefa de dependencias termina bem: registra a versao instalada
    */
   static function confirmarDependencias(): void {
      $fonte = self::arquivosFonte()['package.json'];
      if (is_file($fonte) && self::situacaoDependencias()['completo']) {
         PluginWhatsappempresaConfig::set('dependencias_hash', (string)md5_file($fonte));
      }
   }

   // ============================================
   // Tarefas em segundo plano (download e npm)
   // ============================================

   static function nomeTarefaValido(string $nome): bool {
      return in_array($nome, ['node', 'dependencias'], true);
   }

   /**
    * Roda o script desacoplado do PHP, gravando log e codigo de saida
    */
   static function iniciarTarefa(string $nome, string $corpo): ?string {
      if (!self::nomeTarefaValido($nome)) {
         return 'Tarefa invalida.';
      }
      if (self::situacaoTarefa($nome)['rodando']) {
         return 'Esta etapa ja esta em andamento.';
      }

      $run    = self::pastaRun();
      $script = $run . '/tarefa-' . $nome . '.sh';
      $log    = $run . '/tarefa-' . $nome . '.log';
      $status = $run . '/tarefa-' . $nome . '.status';
      $pid    = $run . '/tarefa-' . $nome . '.pid';

      @unlink($status);
      @file_put_contents($log, '');

      // O proprio script grava o pid: independe de o setsid criar ou nao um processo filho
      $conteudo = "#!/bin/sh\n# Gerado pelo plugin whatsappempresa - tarefa $nome\n"
         . 'echo $$ > ' . escapeshellarg($pid) . "\n"
         . "(\nset -e\n" . $corpo . ")\n"
         . 'echo $? > ' . escapeshellarg($status) . "\n";

      if (@file_put_contents($script, $conteudo) === false) {
         return 'Nao foi possivel gravar o script da tarefa em ' . $run . '.';
      }
      @unlink($pid);

      self::executar(
         'cd ' . escapeshellarg($run) . ' && setsid sh ' . escapeshellarg($script)
         . ' > ' . escapeshellarg($log) . ' 2>&1 < /dev/null &'
      );

      // Aguarda o script registrar o pid para a tela ja mostrar "em andamento"
      for ($i = 0; $i < 20 && !is_file($pid); $i++) {
         usleep(100000);
      }

      return null;
   }

   static function situacaoTarefa(string $nome): array {
      $run    = self::pastaRun();
      $status = $run . '/tarefa-' . $nome . '.status';
      $log    = $run . '/tarefa-' . $nome . '.log';
      $pid    = (int)@file_get_contents($run . '/tarefa-' . $nome . '.pid');

      $terminou = is_file($status);
      $codigo   = $terminou ? (int)trim((string)@file_get_contents($status)) : null;
      $rodando  = !$terminou && $pid > 1 && self::processoVivo($pid);

      $saida = is_file($log) ? (string)@file_get_contents($log) : '';

      return [
         'existe'   => is_file($log),
         'rodando'  => $rodando,
         'terminou' => $terminou,
         'sucesso'  => $terminou && $codigo === 0,
         'codigo'   => $codigo,
         'log'      => mb_substr(trim($saida), -6000)
      ];
   }

   // ============================================
   // Processo e vigia
   // ============================================

   static function processoVivo(int $pid): bool {
      if ($pid <= 1) {
         return false;
      }
      if (function_exists('posix_kill')) {
         return @posix_kill($pid, 0);
      }
      return is_dir('/proc/' . $pid);
   }

   static function pid(): int {
      return (int)trim((string)@file_get_contents(self::arquivoPid()));
   }

   static function rodando(): bool {
      return self::processoVivo(self::pid());
   }

   static function aspasShell(string $valor): string {
      return "'" . str_replace("'", "'\\''", $valor) . "'";
   }

   /**
    * Grava o ambiente do Node e os scripts de inicio e de vigia
    */
   /**
    * Grava o ambiente e o script de inicio da conexao atual, e o vigia geral
    */
   static function gravarScripts(): ?string {
      $id   = PluginWhatsappempresaConexao::atual();
      $run  = self::pastaRunConexao($id);
      $base = self::base();

      $ambiente = [
         'WAE_CONEXAO'      => (string)$id,
         'WAE_PORTA'        => (string)PluginWhatsappempresaConexao::porta($id),
         'WAE_TOKEN'        => (string)PluginWhatsappempresaConfig::get('token_interno'),
         'WAE_WEBHOOK'      => self::urlWebhook(),
         'WAE_AUTH'         => self::pastaAuth($id),
         'WAE_MIDIA'        => self::pastaMidia(),
         'WAE_TLS_INSEGURO' => PluginWhatsappempresaConfig::ativo('webhook_tls_inseguro') ? '1' : '0',
         'HOME'             => $run
      ];
      $linhas = [];
      foreach ($ambiente as $chave => $valor) {
         $linhas[] = $chave . '=' . self::aspasShell($valor);
      }

      // "--conexao=N" identifica o processo de cada numero (parar um nunca derruba outro)
      $iniciar = "#!/bin/sh\n# Gerado pelo plugin whatsappempresa: inicia o servidor WhatsApp da conexao $id\n"
         . 'BASE=' . self::aspasShell($base) . "\n"
         . 'RUN=' . self::aspasShell($run) . "\nLOG=\"\$RUN/servidor.log\"\nPID_ARQ=\"\$RUN/servidor.pid\"\n"
         . "exec 9>\"\$RUN/iniciar.lock\"\n"
         . "if command -v flock >/dev/null 2>&1; then flock -n 9 || exit 0; fi\n"
         . "if [ -f \"\$PID_ARQ\" ] && kill -0 \"\$(cat \"\$PID_ARQ\")\" 2>/dev/null; then exit 0; fi\n"
         . "# log limitado a cerca de 5 MB\n"
         . "if [ -f \"\$LOG\" ] && [ \"\$(wc -c < \"\$LOG\")\" -gt 5242880 ]; then tail -c 1048576 \"\$LOG\" > \"\$LOG.tmp\" && mv \"\$LOG.tmp\" \"\$LOG\"; fi\n"
         . "cd \"\$BASE/app\" || exit 1\n"
         . "set -a\n. \"\$RUN/ambiente.env\"\nset +a\n"
         . "setsid \"\$BASE/node/bin/node\" servidor.js --conexao=$id >> \"\$LOG\" 2>&1 < /dev/null &\n"
         . "echo \$! > \"\$PID_ARQ\"\n";

      $ok = @file_put_contents($run . '/ambiente.env', implode("\n", $linhas) . "\n") !== false
         && @file_put_contents($run . '/iniciar.sh', $iniciar) !== false;

      @chmod($run . '/ambiente.env', 0600);

      if (!$ok) {
         return 'Nao foi possivel gravar os scripts em ' . $run . '.';
      }
      return self::gravarVigia();
   }

   /**
    * Vigia geral (uma linha no crontab): religa cada conexao marcada como ativa
    */
   static function gravarVigia(): ?string {
      $vigia = "#!/bin/sh\n# Gerado pelo plugin whatsappempresa: religa os servidores WhatsApp que cairem\n"
         . 'BASE=' . self::aspasShell(self::base()) . "\n"
         . "for RUN in \"\$BASE/run\" \"\$BASE\"/conexoes/*/run; do\n"
         . "   [ -f \"\$RUN/ativo\" ] && [ -f \"\$RUN/iniciar.sh\" ] && /bin/sh \"\$RUN/iniciar.sh\"\n"
         . "done\nexit 0\n";

      return @file_put_contents(self::pastaRun() . '/vigia.sh', $vigia) !== false
         ? null
         : 'Nao foi possivel gravar o vigia em ' . self::pastaRun() . '.';
   }

   /**
    * Apaga pareamento e arquivos de execucao de uma conexao removida
    */
   static function apagarPastaConexao(int $id): void {
      if ($id === 1) {
         foreach ((array)glob(self::pastaAuth(1) . '/*') as $arquivo) {
            if (is_file($arquivo)) {
               @unlink($arquivo);
            }
         }
         foreach (['ativo', 'servidor.pid', 'iniciar.sh', 'ambiente.env', 'iniciar.lock'] as $nome) {
            @unlink(self::pastaRun() . '/' . $nome);
         }
         return;
      }
      $pasta = self::pastaConexao($id);
      if (is_dir($pasta) && str_contains($pasta, '/whatsappempresa/conexoes/')) {
         self::executar('rm -rf ' . escapeshellarg($pasta));
      }
   }

   static function linhaCron(): string {
      return '* * * * * /bin/sh ' . escapeshellarg(self::pastaRun() . '/vigia.sh') . ' >/dev/null 2>&1 ' . self::MARCA_CRON;
   }

   static function vigiaInstalado(): bool {
      return str_contains(self::executar('crontab -l 2>/dev/null'), self::MARCA_CRON);
   }

   /**
    * Reescreve o crontab do usuario do servidor web, com ou sem a linha do vigia
    */
   static function ajustarCron(bool $instalar): ?string {
      if (!self::shellDisponivel()) {
         return 'A execucao de comandos esta desabilitada no PHP.';
      }
      if (!self::comandoExiste('crontab')) {
         return 'O comando crontab nao existe neste servidor.';
      }

      $linhas = [];
      foreach (explode("\n", self::executar('crontab -l 2>/dev/null')) as $linha) {
         if (trim($linha) !== '' && !str_contains($linha, self::MARCA_CRON)) {
            $linhas[] = $linha;
         }
      }
      if ($instalar) {
         $erro = self::prepararPastas() ?? self::gravarScripts();
         if ($erro !== null) {
            return $erro;
         }
         $linhas[] = self::linhaCron();
      }

      $temporario = self::pastaRun() . '/crontab.tmp';
      @file_put_contents($temporario, implode("\n", $linhas) . "\n");
      $saida = trim(self::executar('crontab ' . escapeshellarg($temporario) . ' 2>&1'));
      @unlink($temporario);

      if (self::vigiaInstalado() !== $instalar) {
         return 'O crontab nao aceitou a alteracao' . ($saida !== '' ? ': ' . $saida : '.');
      }
      return null;
   }

   static function verificarBasico(): ?string {
      if (!self::shellDisponivel()) {
         return 'A funcao shell_exec esta desabilitada no php.ini (disable_functions). O servidor WhatsApp precisa dela.';
      }
      return self::prepararPastas();
   }

   /**
    * Liga o servidor e marca que ele deve permanecer ligado (o vigia respeita essa marca)
    */
   static function iniciar(): ?string {
      $erro = self::verificarBasico();
      if ($erro !== null) {
         return $erro;
      }
      if (!self::nodeValido()) {
         return 'O Node.js ainda nao foi instalado. Use a etapa "Node.js" em Preparacao do servidor.';
      }
      if (!self::appSincronizado()) {
         $erro = self::sincronizarApp();
         if ($erro !== null) {
            return $erro;
         }
      }
      $situacao = self::situacaoDependencias();
      if (!$situacao['completo']) {
         return 'Faltam dependencias do Node (' . implode(', ', $situacao['faltando']) . '). Use a etapa "Dependencias".';
      }

      $erro = self::gravarScripts();
      if ($erro !== null) {
         return $erro;
      }

      @touch(self::arquivoAtivo());
      self::executar('/bin/sh ' . escapeshellarg(self::pastaRunConexao() . '/iniciar.sh') . ' > /dev/null 2>&1');

      return null;
   }

   /**
    * Desliga o servidor e tira a marca para o vigia nao religar
    */
   static function parar(): void {
      @unlink(self::arquivoAtivo());

      $pid = self::pid();
      if (self::processoVivo($pid)) {
         if (function_exists('posix_kill')) {
            @posix_kill($pid, 15);
         } else {
            self::executar('kill -TERM ' . $pid . ' 2>/dev/null');
         }
         for ($i = 0; $i < 20 && self::processoVivo($pid); $i++) {
            usleep(250000);
         }
         if (self::processoVivo($pid)) {
            self::executar('kill -KILL ' . $pid . ' 2>/dev/null');
         }
      }
      @unlink(self::arquivoPid());

      // Processo orfao desta mesma conexao (pid perdido); a 1 tambem cobre o formato antigo, sem marca
      $id = PluginWhatsappempresaConexao::atual();
      self::executar('pkill -f -- ' . escapeshellarg(self::pastaNode() . '/bin/node servidor.js --conexao=' . $id . '$') . ' 2>/dev/null');
      if ($id === 1) {
         self::executar('pkill -f -- ' . escapeshellarg(self::pastaNode() . '/bin/node servidor.js$') . ' 2>/dev/null');
      }
   }

   static function deveEstarLigado(): bool {
      return is_file(self::arquivoAtivo());
   }

   /**
    * Usado pela tarefa automatica: religa se deveria estar ligado e nao esta
    */
   static function garantirLigado(): bool {
      if (!self::deveEstarLigado() || self::rodando()) {
         return false;
      }
      return self::iniciar() === null;
   }

   /**
    * Tarefa automatica: confere todas as conexoes. Devolve os nomes das que foram religadas.
    */
   static function garantirTodos(): array {
      $religadas = [];
      foreach (PluginWhatsappempresaConexao::listar() as $conexao) {
         $anterior = PluginWhatsappempresaConexao::usar((int)$conexao['id']);
         if (self::garantirLigado()) {
            $religadas[] = (string)$conexao['nome'];
         }
         PluginWhatsappempresaConexao::restaurar($anterior);
      }
      return $religadas;
   }

   static function logServidor(int $linhas = 80): string {
      $arquivo = self::arquivoLog();
      if (!is_file($arquivo)) {
         return '';
      }
      $conteudo = @file($arquivo, FILE_IGNORE_NEW_LINES);
      return is_array($conteudo) ? implode("\n", array_slice($conteudo, -$linhas)) : '';
   }

   static function limparAutenticacao(): void {
      foreach ((array)glob(self::pastaAuth() . '/*') as $arquivo) {
         if (is_file($arquivo)) {
            @unlink($arquivo);
         }
      }
      $id = PluginWhatsappempresaConexao::atual();
      PluginWhatsappempresaConexao::gravarNumero($id, '');
      unset(self::$hostCache[$id]);
   }

   /**
    * Remove tudo que o plugin criou fora da propria pasta (usado na desinstalacao)
    */
   /**
    * Desliga o servico e tira o vigia do crontab, mantendo Node, dependencias e a sessao do aparelho
    */
   static function desligarTudo(): void {
      foreach (PluginWhatsappempresaConexao::listar() as $conexao) {
         $anterior = PluginWhatsappempresaConexao::usar((int)$conexao['id']);
         self::parar();
         PluginWhatsappempresaConexao::restaurar($anterior);
      }
      if (self::shellDisponivel() && self::comandoExiste('crontab')) {
         self::ajustarCron(false);
      }
   }

   // ============================================
   // Etapas mostradas na tela de configuracao
   // ============================================

   /**
    * Cada etapa: chave, rotulo, estado (ok | pendente | erro | andamento), detalhe e acoes disponiveis
    */
   static function etapas(bool $testarWebhook = false): array {
      $etapas = [];

      $shell = self::shellDisponivel();
      $etapas[] = [
         'chave'   => 'comandos',
         'rotulo'  => 'Execucao de comandos pelo PHP',
         'estado'  => $shell ? 'ok' : 'erro',
         'detalhe' => $shell
            ? 'Liberada para o usuario ' . self::usuarioPhp() . '.'
            : 'A funcao shell_exec esta em disable_functions no php.ini. Sem ela o servidor nao pode ser controlado pelo GLPI.',
         'acoes'   => []
      ];

      $erroPasta = $shell ? self::prepararPastas() : 'Depende da execucao de comandos.';
      $etapas[] = [
         'chave'   => 'pasta',
         'rotulo'  => 'Pasta de trabalho',
         'estado'  => $erroPasta === null ? 'ok' : 'erro',
         'detalhe' => $erroPasta ?? self::base(),
         'acoes'   => []
      ];

      $tarefaNode = self::situacaoTarefa('node');
      $versao     = self::versaoNode();
      $etapas[] = [
         'chave'   => 'node',
         'rotulo'  => 'Node.js',
         'estado'  => $tarefaNode['rodando'] ? 'andamento' : (self::nodeValido() ? 'ok' : 'pendente'),
         'detalhe' => $tarefaNode['rodando']
            ? 'Download em andamento...'
            : ($versao !== ''
               ? 'Versao ' . $versao . ' instalada em ' . self::pastaNode() . '.'
               : 'Nao instalado. Sera baixada a versao LTS oficial de nodejs.org (cerca de 30 MB).'),
         'acoes'   => self::nodeValido() ? ['node_atualizar'] : ['node_instalar'],
         'tarefa'  => 'node'
      ];

      $deps   = self::situacaoDependencias();
      $tarefa = self::situacaoTarefa('dependencias');
      $resumo = [];
      foreach ($deps['pacotes'] as $pacote) {
         $resumo[] = $pacote['nome'] . ' ' . ($pacote['presente'] ? ($pacote['versao'] ?: 'ok') : 'ausente');
      }
      $etapas[] = [
         'chave'   => 'dependencias',
         'rotulo'  => 'Dependencias do servidor',
         'estado'  => $tarefa['rodando'] ? 'andamento' : ($deps['atualizado'] ? 'ok' : 'pendente'),
         'detalhe' => $tarefa['rodando']
            ? 'Instalacao em andamento...'
            : implode(' | ', $resumo) . ($deps['completo'] && !$deps['atualizado'] ? ' (a versao do plugin pede uma atualizacao)' : ''),
         'acoes'   => ['dependencias_instalar'],
         'tarefa'  => 'dependencias'
      ];

      $vigia = $shell && self::vigiaInstalado();
      $etapas[] = [
         'chave'   => 'vigia',
         'rotulo'  => 'Vigia (religar automaticamente)',
         'estado'  => $vigia ? 'ok' : 'pendente',
         'detalhe' => $vigia
            ? 'Ativo no crontab de ' . self::usuarioPhp() . ': o servidor volta sozinho em ate 1 minuto se cair ou se o Apache reiniciar.'
            : 'Inativo: se o processo cair, ele so volta quando alguem o iniciar pela tela.',
         'acoes'   => [$vigia ? 'vigia_desativar' : 'vigia_ativar']
      ];

      if ($testarWebhook) {
         $webhook = self::testarWebhook(8);
         $etapas[] = [
            'chave'   => 'webhook',
            'rotulo'  => 'Retorno do servidor para o GLPI (webhook)',
            'estado'  => $webhook['ok'] ? 'ok' : 'erro',
            'detalhe' => $webhook['ok'] ? 'Respondendo em ' . $webhook['url'] : $webhook['erro'] . ' URL testada: ' . $webhook['url'],
            'acoes'   => ['webhook_testar']
         ];
      } else {
         $etapas[] = [
            'chave'   => 'webhook',
            'rotulo'  => 'Retorno do servidor para o GLPI (webhook)',
            'estado'  => 'pendente',
            'detalhe' => 'Nao testado nesta visita. URL: ' . self::urlWebhook(),
            'acoes'   => ['webhook_testar']
         ];
      }

      return $etapas;
   }

   /**
    * Situacao de cada numero conectado, para os cartoes da aba Servidor
    */
   static function resumoConexoes(): array {
      global $DB;

      $hoje  = date('Y-m-d 00:00:00');
      $itens = [];

      foreach (PluginWhatsappempresaConexao::listar() as $conexao) {
         $id = (int)$conexao['id'];
         $anterior = PluginWhatsappempresaConexao::usar($id);

         $status    = self::status();
         $ligado    = $status['ligado'];
         $servico   = $status['servico'] ?? [];
         $conectado = $ligado && !empty($servico['conectado']);
         $atualizada = PluginWhatsappempresaConexao::porId($id) ?? $conexao;

         $contar = function (array $where) use ($DB, $id): int {
            return countElementsInTable('glpi_plugin_whatsappempresa_mensagens', $where + ['conexoes_id' => $id]);
         };

         $itens[] = [
            'id'            => $id,
            'nome'          => (string)$atualizada['nome'],
            'numero'        => (string)($atualizada['numero'] ?? ''),
            'nome_aparelho' => (string)($atualizada['nome_aparelho'] ?? ''),
            'porta'         => (int)$atualizada['porta'],
            'padrao'        => (int)$atualizada['is_padrao'] === 1,
            'deve_ligado'   => self::deveEstarLigado(),
            'ligado'        => $ligado,
            'conectado'     => $conectado,
            'tem_qr'        => $ligado && !$conectado && !empty($servico['tem_qr']),
            'pareado'       => is_file(self::pastaAuth($id) . '/creds.json'),
            'pid'           => $ligado ? self::pid() : 0,
            'desde'         => !empty($servico['tempo_ms']) ? Html::convDateTime(date('Y-m-d H:i:s', time() - (int)((int)$servico['tempo_ms'] / 1000))) : '',
            'fluxos'        => PluginWhatsappempresaConexao::totalFluxos($id),
            'fluxos_ativos' => countElementsInTable('glpi_plugin_whatsappempresa_fluxos', ['conexoes_id' => $id, 'is_deleted' => 0, 'is_ativo' => 1]),
            'recebidas'     => $contar(['direcao' => 'entrada', 'date_creation' => ['>=', $hoje]]),
            'enviadas'      => $contar(['direcao' => 'saida', 'date_creation' => ['>=', $hoje]]),
            'falhas'        => $contar(['status_envio' => 'erro', 'date_creation' => ['>=', $hoje]]),
            'versao_wa'     => (string)($servico['versao_wa'] ?? ''),
            'versao_baileys' => (string)($servico['versao_baileys'] ?? '')
         ];

         PluginWhatsappempresaConexao::restaurar($anterior);
      }

      return $itens;
   }

   // ============================================
   // Comunicacao com o Node
   // ============================================

   static function requisitar(string $metodo, string $rota, array $dados = [], int $timeout = 8): array {
      if (!function_exists('curl_init')) {
         return ['ok' => false, 'http' => 0, 'dados' => null];
      }

      // Cada conexao tem o seu processo, na sua porta
      $ch = curl_init('http://127.0.0.1:' . PluginWhatsappempresaConexao::porta(PluginWhatsappempresaConexao::atual()) . $rota);

      $opcoes = [
         CURLOPT_RETURNTRANSFER => true,
         CURLOPT_CUSTOMREQUEST  => $metodo,
         CURLOPT_CONNECTTIMEOUT => 3,
         CURLOPT_TIMEOUT        => $timeout,
         CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-Token-Interno: ' . PluginWhatsappempresaConfig::get('token_interno')
         ]
      ];

      if (!empty($dados)) {
         $opcoes[CURLOPT_POSTFIELDS] = json_encode($dados, JSON_UNESCAPED_UNICODE);
      }

      curl_setopt_array($ch, $opcoes);
      $resposta = curl_exec($ch);
      $http     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
      $erro     = curl_errno($ch);
      curl_close($ch);

      return [
         'ok'    => ($erro === 0 && $http >= 200 && $http < 300),
         'http'  => $http,
         'dados' => json_decode((string)$resposta, true)
      ];
   }

   static function ativo(): bool {
      return self::requisitar('GET', '/status', [], 3)['ok'];
   }

   static function status(): array {
      $r = self::requisitar('GET', '/status', [], 5);

      // Aproveita a consulta para manter o numero do aparelho da conexao atualizado
      if ($r['ok'] && !empty($r['dados']['numero'])) {
         $numero = PluginWhatsappempresaConfig::limparTelefone((string)$r['dados']['numero']);
         if ($numero !== '') {
            $id = PluginWhatsappempresaConexao::atual();
            PluginWhatsappempresaConexao::gravarNumero($id, $numero, (string)($r['dados']['nome'] ?? ''));
            self::$hostCache[$id] = $numero;
         }
      }

      return [
         'ligado'  => $r['ok'],
         'servico' => $r['ok'] ? $r['dados'] : null
      ];
   }

   static function qrcode(): ?string {
      $r = self::requisitar('GET', '/qr', [], 5);
      return ($r['ok'] && !empty($r['dados']['qr'])) ? (string)$r['dados']['qr'] : null;
   }

   static function aguardar(int $segundos, bool $esperaLigado = true): bool {
      $limite = time() + $segundos;
      do {
         $ok = self::requisitar('GET', '/status', [], 3)['ok'];
         if ($ok === $esperaLigado) {
            return $ok;
         }
         usleep(700000);
      } while (time() < $limite);

      return self::requisitar('GET', '/status', [], 3)['ok'];
   }

   /**
    * Numero do aparelho conectado ao servidor: e quem aparece como remetente
    */
   static function numeroHost(): string {
      $id = PluginWhatsappempresaConexao::atual();
      if (isset(self::$hostCache[$id])) {
         return self::$hostCache[$id];
      }

      $gravado = PluginWhatsappempresaConfig::limparTelefone((string)(PluginWhatsappempresaConexao::porId($id)['numero'] ?? ''));
      if ($gravado !== '') {
         return self::$hostCache[$id] = $gravado;
      }

      $r = self::requisitar('GET', '/status', [], 3);
      $numero = PluginWhatsappempresaConfig::limparTelefone((string)($r['dados']['numero'] ?? ''));
      if ($numero !== '') {
         PluginWhatsappempresaConexao::gravarNumero($id, $numero);
      }
      return self::$hostCache[$id] = $numero;
   }

   /**
    * Dados de rede e ambiente exibidos na tela
    */
   static function infoRede(): array {
      global $CFG_GLPI;

      $webhook = self::urlWebhook();
      $manual  = trim((string)PluginWhatsappempresaConfig::get('webhook_url', ''));
      $host    = (string)parse_url($webhook, PHP_URL_HOST);

      return [
         'Conexões'                => count(PluginWhatsappempresaConexao::listar()) . ' número(s), cada um na própria porta (somente 127.0.0.1)',
         'Webhook'                 => $webhook . ' (' . ($manual !== '' ? 'informado nos parametros' : 'derivado da URL do GLPI') . ')',
         'Destino do webhook'      => $host !== '' ? $host . ' / ' . (@gethostbyname($host) ?: '-') : '-',
         'URL do GLPI'             => (string)($CFG_GLPI['url_base'] ?? '-'),
         'Servidor'                => (string)@gethostname() . ' - ' . php_uname('s') . ' ' . php_uname('m'),
         'Usuario do PHP'          => self::usuarioPhp() . ' - PHP ' . PHP_VERSION,
         'Pasta de trabalho'       => self::base()
      ];
   }

   /**
    * Contadores e datas mostrados na tela
    */
   static function infoTempos(?array $status = null): array {
      global $DB;

      $status  = $status ?? self::status();
      $servico = $status['servico'] ?? [];

      $contar = function (array $where) use ($DB): int {
         foreach ($DB->request(['COUNT' => 'total', 'FROM' => 'glpi_plugin_whatsappempresa_mensagens', 'WHERE' => $where]) as $linha) {
            return (int)$linha['total'];
         }
         return 0;
      };

      $abertas = countElementsInTable('glpi_plugin_whatsappempresa_conversas', ['status' => 'aberta', 'is_deleted' => 0]);
      $hoje    = date('Y-m-d 00:00:00');
      $auth    = self::pastaAuth() . '/creds.json';

      $ligadoDesde = !empty($servico['tempo_ms'])
         ? Html::convDateTime(date('Y-m-d H:i:s', time() - (int)((int)$servico['tempo_ms'] / 1000)))
         : '-';

      return [
         'Conectado desde'          => $ligadoDesde,
         'Pareado em'               => is_file($auth) ? Html::convDateTime(date('Y-m-d H:i:s', (int)@filemtime($auth))) : '-',
         'Conversas abertas'        => $abertas,
         'Recebidas hoje'           => $contar(['direcao' => 'entrada', 'date_creation' => ['>=', $hoje]]),
         'Enviadas hoje'            => $contar(['direcao' => 'saida', 'date_creation' => ['>=', $hoje]]),
         'Falhas de envio hoje'     => $contar(['status_envio' => 'erro', 'date_creation' => ['>=', $hoje]]),
         'Versao do WhatsApp Web'   => (string)($servico['versao_wa'] ?? '-'),
         'Versao do Baileys'        => (string)($servico['versao_baileys'] ?? '-')
      ];
   }

   static function urlWebhook(): string {
      global $CFG_GLPI;

      $manual = trim((string)PluginWhatsappempresaConfig::get('webhook_url', ''));
      if ($manual !== '') {
         return rtrim($manual, '/');
      }

      return rtrim((string)($CFG_GLPI['url_base'] ?? ''), '/') . '/plugins/whatsappempresa/front/webhook.php';
   }

   /**
    * Bate no proprio webhook para provar que o Node vai conseguir alcancar o GLPI
    */
   static function testarWebhook(int $timeout = 10): array {
      $url = self::urlWebhook();

      if ($url === '' || strpos($url, 'http') !== 0) {
         return ['ok' => false, 'http' => 0, 'url' => $url, 'erro' => 'URL do webhook nao definida. Preencha a URL do GLPI em Configurar > Geral ou o campo Webhook nos parametros.'];
      }

      $ch = curl_init($url);
      $opcoes = [
         CURLOPT_RETURNTRANSFER => true,
         CURLOPT_POST           => true,
         CURLOPT_CONNECTTIMEOUT => 5,
         CURLOPT_TIMEOUT        => $timeout,
         CURLOPT_FOLLOWLOCATION => true,
         CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-Token-Interno: ' . PluginWhatsappempresaConfig::get('token_interno')
         ],
         CURLOPT_POSTFIELDS => json_encode(['teste' => true])
      ];
      if (PluginWhatsappempresaConfig::ativo('webhook_tls_inseguro')) {
         $opcoes[CURLOPT_SSL_VERIFYPEER] = false;
         $opcoes[CURLOPT_SSL_VERIFYHOST] = 0;
      }

      curl_setopt_array($ch, $opcoes);
      $resposta = curl_exec($ch);
      $http     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
      $erroCurl = curl_error($ch);
      curl_close($ch);

      $dados = json_decode((string)$resposta, true);

      $erro = match (true) {
         $erroCurl !== ''  => 'Conexao falhou: ' . $erroCurl . '.',
         $http === 403     => 'O webhook respondeu 403 (token interno divergente).',
         $http !== 200     => 'O webhook respondeu HTTP ' . $http . '. Confira o caminho da URL.',
         empty($dados['ok']) => 'Resposta inesperada: o GLPI pode ter devolvido uma pagina de login ou de erro.',
         default           => ''
      };

      return ['ok' => $erro === '', 'http' => $http, 'url' => $url, 'erro' => $erro];
   }

   /**
    * Aceita texto simples ou estrutura com botoes e lista clicavel
    */
   static function enviarTexto(string $telefone, $conteudo, ?array $midia = null, ?array $citar = null): array {
      $numero = PluginWhatsappempresaConfig::limparTelefone($telefone);
      if (strlen($numero) < 10) {
         return ['ok' => false, 'erro' => 'Telefone invalido', 'jid' => ''];
      }

      $corpo = ['telefone' => $numero];

      // Resposta citando outra mensagem: { wa_id, de_mim, texto }
      if ($citar !== null) {
         $corpo['citar'] = $citar;
      }

      // Imagem ou audio: o arquivo ja esta na pasta de midia, que o Node le direto
      if ($midia !== null) {
         $corpo['midia'] = [
            'tipo'    => (string)$midia['tipo'],
            'arquivo' => (string)$midia['arquivo'],
            'mime'    => (string)$midia['mime']
         ];
         $corpo['texto'] = is_array($conteudo) ? (string)($conteudo['texto'] ?? '') : (string)$conteudo;

         $r = self::requisitar('POST', '/enviar', $corpo, 60);
         if (!$r['ok']) {
            return ['ok' => false, 'erro' => $r['dados']['erro'] ?? 'Servidor WhatsApp indisponivel', 'jid' => ''];
         }
         return [
            'ok'        => true,
            'erro'      => null,
            'jid'       => (string)($r['dados']['jid'] ?? ''),
            'convertido' => $r['dados']['convertido'] ?? null,
            'wa_id'      => (string)($r['dados']['wa_id'] ?? '')
         ];
      }

      if (is_array($conteudo)) {
         $corpo['texto'] = (string)($conteudo['texto'] ?? '');
         if (!empty($conteudo['rodape'])) {
            $corpo['rodape'] = (string)$conteudo['rodape'];
         }
         if (!empty($conteudo['botoes'])) {
            $corpo['botoes'] = array_values($conteudo['botoes']);
         }
         if (!empty($conteudo['lista'])) {
            $corpo['lista'] = $conteudo['lista'];
         }
      } else {
         $corpo['texto'] = (string)$conteudo;
      }

      if (trim((string)$corpo['texto']) === '') {
         return ['ok' => false, 'erro' => 'Mensagem vazia', 'jid' => ''];
      }

      $r = self::requisitar('POST', '/enviar', $corpo, 20);

      if (!$r['ok']) {
         return ['ok' => false, 'erro' => $r['dados']['erro'] ?? 'Servidor WhatsApp indisponivel', 'jid' => ''];
      }

      return ['ok' => true, 'erro' => null, 'jid' => (string)($r['dados']['jid'] ?? ''), 'wa_id' => (string)($r['dados']['wa_id'] ?? '')];
   }

   /**
    * Reacao (emoji) numa mensagem do WhatsApp; emoji vazio remove
    */
   static function reagir(string $telefone, string $wa_id, bool $deMim, string $emoji): array {
      $r = self::requisitar('POST', '/reagir', [
         'telefone' => PluginWhatsappempresaConfig::limparTelefone($telefone),
         'wa_id'    => $wa_id,
         'de_mim'   => $deMim,
         'emoji'    => $emoji
      ], 20);
      return ['ok' => (bool)$r['ok'], 'erro' => $r['ok'] ? null : ($r['dados']['erro'] ?? 'Servidor WhatsApp indisponivel')];
   }
}
