# WhatsApp Empresa para GLPI

> Autor: **GLPI Salvador** · Licença: **GPLv2+** · Compatível com GLPI **11.0.0 a 12.x**

**Atendimento pelo WhatsApp** integrado ao GLPI. O cliente abre e acompanha chamados pelo WhatsApp, o técnico conversa com ele de dentro do chamado e os aprovadores respondem às validações pelo celular. O servidor de WhatsApp (Node.js + Baileys) é instalado e administrado pelo próprio GLPI.

## O que o plugin faz

### Vários números (conexões)
- Cada número pareado por **QR Code** é um servidor independente, com porta, pareamento, log e fluxos próprios.
- A aba **Servidor** prepara tudo pela tela:
  - baixa o Node.js oficial, com a soma SHA-256 conferida;
  - instala as dependências e mostra o pareamento;
  - liga e desliga o servidor e ativa o vigia que o religa se cair.
- Ferramentas de diagnóstico: **envio de teste**, **situação de um telefone** e verificação de rede e ambiente.

### Autoatendimento do cliente
- **Menu no WhatsApp:** abrir chamado, ver **meus chamados** (situação e acompanhamentos), **comentar**, ver **validações pendentes** e **falar com um técnico**. Cada opção pode ser ligada ou desligada.
- **Clientes:** cada entidade tem os seus **códigos de acesso**, um requerente padrão e uma lista de **contatos** (nome e telefone) que podem ser atendidos mesmo sem usuário no GLPI.
- **Código de acesso** opcional para liberar o atendimento, com **proteção contra tentativas** (limite e bloqueio temporário).
- **Sessões** por número, com tempo de expiração e palavras para sair e retomar. A lista de números em atendimento tem ações para desbloquear e reiniciar.
- Chamados abertos pelo WhatsApp usam o **tipo**, a **urgência** e a **categoria** configurados, e o técnico pode ser **notificado**.
- **Botões clicáveis** do WhatsApp (opcional), lembrando que contas comuns às vezes descartam mensagens com botões.

### Construtor visual de fluxos
Uma tela de **arrastar e soltar** para montar os próprios fluxos de atendimento. Cada alteração vale na hora. Os blocos disponíveis:
- **Mensagem**, com imagem ou áudio opcional;
- **Pergunta**, que guarda a resposta numa variável e valida o que é aceito;
- **Sim ou não**, **Menu** de opções, **Condição** e **Randomizador**;
- **Definir variável** e **Intervalo** (aguardar alguns segundos);
- **Horário de expediente**, pelo calendário do GLPI ou por dias e horários;
- **Listar registros**, **Detalhe do registro** e **Abrir registro**, por exemplo para criar um chamado com título vindo de uma variável.

### Conversa técnico e cliente
- Aba **WhatsApp** no chamado, onde o técnico conversa com o cliente em tempo real.
- **Botão flutuante** de conversas com aviso de mensagens novas.
- Lista de **conversas** e de **mensagens** com a busca nativa do GLPI.

### Aprovação de validações
- Quando uma **validação de chamado ou de mudança** é criada, o aprovador recebe o pedido no WhatsApp e **aprova ou recusa** por lá.
- A resposta é gravada na validação nativa do GLPI.
- Os pedidos ficam numa lista própria.

### Registros
O **log** do plugin fica numa lista nativa, com mais detalhes quando o log detalhado está ligado.

## Configuração

O menu **WhatsApp** do GLPI reúne:
- **Servidores**, com as abas Servidor, Mensagens, Parâmetros, Textos, Clientes e Fluxos;
- as listas de Conversas, Mensagens, Sessões, Códigos, Validações e Registros.

Em **Parâmetros** você liga ou desliga cada recurso (autoatendimento, fluxos, conversa e aprovação), escolhe as opções do menu do cliente e configura códigos, tentativas, sessões e os padrões dos chamados abertos. Em **Textos** ficam as mensagens enviadas ao cliente.

## Requisitos extras

- Servidor Linux x86_64 ou arm64, com o PHP podendo executar comandos e acesso à internet na preparação.
- Usa a biblioteca não oficial **Baileys**. Use um número dedicado ao atendimento e siga os termos de uso do WhatsApp.
- O construtor visual usa o **Drawflow** (licença MIT), incluído em `public/lib/drawflow`.

---

## Download e instalação

1. Baixe o arquivo `whatsappempresa-X.Y.Z.zip` da **[última versão](../../releases/latest)**. Use o arquivo anexado à release, não o "Source code".
2. Descompacte dentro da pasta `plugins/` do GLPI. O resultado deve ser `plugins/whatsappempresa/setup.php`.
3. Ajuste o dono dos arquivos para o usuário do servidor web, por exemplo:
   ```bash
   chown -R www-data:www-data /var/www/glpi/plugins/whatsappempresa
   ```
4. No GLPI, vá em **Configurar → Plugins** e clique em **Instalar** e depois em **Ativar**. Pela linha de comando:
   ```bash
   php bin/console plugin:install whatsappempresa -u <usuário administrador>
   php bin/console plugin:activate whatsappempresa
   ```

A instalação cria as tabelas, as configurações padrão e as ações automáticas do plugin, e funciona num GLPI sem nada configurado antes.

### Atualização

Substitua a pasta `plugins/whatsappempresa` pela versão nova e rode **Instalar** de novo, ou `php bin/console plugin:install whatsappempresa -f`. Depois, ative o plugin. As tabelas e colunas novas são criadas sem perder os dados.

### Desinstalação

A desinstalação **não apaga as tabelas do plugin**: reinstalar recupera os dados.

## Versões

O histórico, com o que mudou em cada versão e o arquivo para download, está em **[Releases](../../releases)**. Cada versão entrou por um **[pull request](../../pulls?q=is%3Apr)**.

## Licença

Distribuído sob a **GNU General Public License v2.0 ou posterior**. Veja o arquivo [LICENSE](LICENSE).