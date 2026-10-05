/* whatsappempresa - botao flutuante de conversas */

(function () {
   'use strict';

   var RAPIDO = 4000;
   var NORMAL = 10000;
   var OCIOSO = 30000;
   var raiz = (window.CFG_GLPI && window.CFG_GLPI.root_doc) ? window.CFG_GLPI.root_doc : '';
   var URL_AJAX = raiz + '/plugins/whatsappempresa/front/ajax.php';
   var token = '';
   var conversaAtual = 0;
   var telefoneAtual = '';
   var ticketAtual = 0;
   var timer = null;
   var marca = '';
   var marcaConversa = '';
   var ocupado = false;
   var ateRapido = 0;

   function pedir(acao, dados, metodo) {
      dados = dados || {};
      dados.acao = acao;

      var opcoes = { method: metodo || 'GET', credentials: 'same-origin' };
      var url = URL_AJAX;

      if ((metodo || 'GET') === 'POST') {
         var corpo = new FormData();
         // GLPI 11 exige token (vem da pagina ou da ultima resposta); o GLPI 12 nao usa token
         var tk = token || (typeof getAjaxCsrfToken === 'function' ? getAjaxCsrfToken() : '');
         if (tk) {
            corpo.append('_glpi_csrf_token', tk);
            opcoes.headers = { 'X-Glpi-Csrf-Token': tk };
         }
         Object.keys(dados).forEach(function (chave) { corpo.append(chave, dados[chave]); });
         opcoes.body = corpo;
      } else {
         url = URL_AJAX + '?' + new URLSearchParams(dados).toString();
      }

      return fetch(url, opcoes)
         .then(function (r) { return r.json(); })
         .then(function (resposta) {
            if (resposta && resposta.token) { token = resposta.token; }
            return resposta;
         })
         .catch(function () { return { sucesso: false }; });
   }

   function escapar(texto) {
      var div = document.createElement('div');
      div.textContent = texto === null || texto === undefined ? '' : String(texto);
      return div.innerHTML;
   }

   function montarInterface() {
      var botao = document.createElement('button');
      botao.type = 'button';
      botao.className = 'waen-botao';
      botao.id = 'waen-botao';
      botao.innerHTML = '<i class="fab fa-whatsapp"></i> <span>WhatsApp</span> <span class="waen-contador waen-oculto" id="waen-contador">0</span>';

      var painel = document.createElement('div');
      painel.className = 'waen-painel';
      painel.id = 'waen-painel';
      painel.innerHTML =
         '<div class="waen-painel-topo">' +
         '<span class="waen-painel-titulo"><i class="ti ti-message"></i> <span>Conversas</span></span>' +
         '<button type="button" class="waen-fechar" id="waen-fechar"><i class="ti ti-x"></i></button>' +
         '</div>' +
         '<div class="waen-lista" id="waen-lista"><div class="waen-vazio">Carregando...</div></div>' +
         '<div class="waen-conversa" id="waen-conversa">' +
         '<div class="waen-mensagens" id="waen-mensagens"></div>' +
         '<div class="waen-envio">' +
         '<input type="text" id="waen-texto" placeholder="Escreva a mensagem">' +
         '<button type="button" id="waen-enviar"><i class="ti ti-send"></i> <span>Enviar</span></button>' +
         '<button type="button" id="waen-encerrar" class="waen-btn-encerrar"><i class="ti ti-x"></i> <span>Encerrar</span></button>' +
         '</div></div>';

      document.body.appendChild(botao);
      document.body.appendChild(painel);

      botao.addEventListener('click', function () {
         painel.classList.toggle('waen-aberto');
         if (painel.classList.contains('waen-aberto')) { carregar(); }
      });

      document.getElementById('waen-fechar').addEventListener('click', function () {
         painel.classList.remove('waen-aberto');
         fecharConversa();
      });

      document.getElementById('waen-enviar').addEventListener('click', enviar);

      document.getElementById('waen-encerrar').addEventListener('click', function () {
         if (!conversaAtual) { return; }

         pedir('conversa_encerrar', { conversas_id: conversaAtual }, 'POST').then(function () {
            fecharConversa();
            carregar();
         });
      });
      document.getElementById('waen-texto').addEventListener('keydown', function (evento) {
         if (evento.key === 'Enter') { enviar(); }
      });
   }

   function fecharConversa() {
      conversaAtual = 0;
      telefoneAtual = '';
      ticketAtual = 0;
      document.getElementById('waen-conversa').classList.remove('waen-aberto');
   }

   function carregar() {
      pedir('alertas').then(function (resposta) {
         if (!resposta.sucesso) { return; }

         var contador = document.getElementById('waen-contador');
         var total = (resposta.alertas || []).length;

         if (contador) {
            contador.textContent = total;
            contador.classList.toggle('waen-oculto', total === 0);
            contador.style.display = total === 0 ? 'none' : 'inline-flex';
         }

         var lista = document.getElementById('waen-lista');
         if (!lista) { return; }

         var conversas = resposta.conversas || [];
         if (!conversas.length) {
            lista.innerHTML = '<div class="waen-vazio">Nenhuma conversa vinculada a voce.</div>';
            return;
         }

         lista.innerHTML = conversas.map(function (item, indice) {
            var naoLida = parseInt(item.nao_lidas, 10) > 0 ? ' waen-nao-lida' : '';
            var ticket = parseInt(item.tickets_id, 10) > 0 ? ' - chamado #' + item.tickets_id : '';
            return '<div class="waen-item' + naoLida + '" data-indice="' + indice + '">' +
               '<div class="waen-item-dados">' +
               '<span class="waen-item-nome">' + escapar(item.nome_contato) + escapar(ticket) + '</span>' +
               '<span class="waen-item-texto">' + escapar(item.ultima_mensagem || '') + '</span>' +
               '</div></div>';
         }).join('');

         lista.querySelectorAll('.waen-item').forEach(function (elemento) {
            elemento.addEventListener('click', function () {
               var item = conversas[parseInt(elemento.getAttribute('data-indice'), 10)];
               if (!item) { return; }
               abrirConversa(item);
            });
         });
      });
   }

   function abrirConversa(item) {
      conversaAtual = parseInt(item.id, 10);
      telefoneAtual = item.telefone;
      ticketAtual = parseInt(item.tickets_id, 10) || 0;

      document.getElementById('waen-conversa').classList.add('waen-aberto');
      marcaConversa = '';
      ateRapido = Date.now() + 120000;
      reagendar();
      carregarMensagens();
   }

   function carregarMensagens() {
      if (!conversaAtual) { return; }

      pedir('conversa_mensagens', { conversas_id: conversaAtual }).then(function (resposta) {
         var caixa = document.getElementById('waen-mensagens');
         if (!caixa) { return; }

         if (!resposta.sucesso || !resposta.mensagens.length) {
            caixa.innerHTML = '<div class="waen-vazio">Sem mensagens.</div>';
            return;
         }

         caixa.innerHTML = resposta.mensagens.map(function (mensagem) {
            var classe = mensagem.direcao === 'saida' ? 'waen-balao waen-balao-saida' : 'waen-balao';
            return '<div class="' + classe + '">' + escapar(mensagem.conteudo) +
               '<span class="waen-balao-meta">' + escapar(mensagem.data) + '</span></div>';
         }).join('');

         caixa.scrollTop = caixa.scrollHeight;
      });
   }

   function enviar() {
      var campo = document.getElementById('waen-texto');
      var texto = campo.value.trim();

      if (texto === '' || telefoneAtual === '') { return; }

      pedir('conversa_enviar', {
         tickets_id: ticketAtual,
         telefone: telefoneAtual,
         texto: texto
      }, 'POST').then(function (resposta) {
         if (resposta.sucesso) { campo.value = ''; }
         carregarMensagens();
         carregar();
      });
   }

   /**
    * Consulta barata: recarrega a lista somente quando algo mudou
    */
   function pulsar() {
      if (document.hidden || ocupado) { return; }

      ocupado = true;

      pedir('alertas_pulso').then(function (resposta) {
         ocupado = false;
         if (!resposta || !resposta.sucesso) { return; }

         if (resposta.marca !== marca) {
            marca = resposta.marca;
            ateRapido = Date.now() + 120000;
            carregar();
            reagendar();
         }

         var aberto = conversaAtual
            && document.getElementById('waen-painel')
            && document.getElementById('waen-painel').classList.contains('waen-aberto');

         if (aberto) {
            pedir('conversa_pulso_id', { conversas_id: conversaAtual }).then(function (r) {
               if (r && r.sucesso && r.marca !== marcaConversa) {
                  marcaConversa = r.marca;
                  carregarMensagens();
               }
            });
         }
      });
   }

   function intervaloAtual() {
      if (document.hidden) { return OCIOSO; }
      return Date.now() < ateRapido ? RAPIDO : NORMAL;
   }

   function reagendar() {
      if (timer) { clearInterval(timer); }
      timer = setInterval(pulsar, intervaloAtual());
   }

   function iniciar() {
      if (document.getElementById('waen-botao')) { return; }
      montarInterface();
      carregar();
      pulsar();
      ateRapido = Date.now() + 120000;
      reagendar();

      document.addEventListener('visibilitychange', function () {
         if (!document.hidden) {
            ateRapido = Date.now() + 120000;
            pulsar();
         }
         reagendar();
      });

      window.addEventListener('focus', pulsar);
   }

   if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', iniciar);
   } else {
      iniciar();
   }

   window.addEventListener('beforeunload', function () {
      if (timer) { clearInterval(timer); }
   });
})();
