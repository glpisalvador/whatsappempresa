/* whatsappempresa - botao flutuante de conversas */

(function () {
   'use strict';

   var RAPIDO = 1500;
   var NORMAL = 4000;
   var OCIOSO = 30000;
   var CONVERSA_ABERTA = 1500;
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

   /**
    * Visualizador de imagens em modal, usado aqui e na aba WhatsApp do chamado.
    * Usa o modal do Bootstrap do GLPI; sem ele, uma camada propria com o mesmo visual.
    */
   if (!window.WAEVisualizador) {
      window.WAEVisualizador = (function () {
         var modal = null;
         var instancia = null;

         function criar() {
            modal = document.createElement('div');
            modal.className = 'modal fade wae-visualizador';
            modal.id = 'wae-visualizador';
            modal.tabIndex = -1;
            modal.setAttribute('aria-hidden', 'true');
            modal.innerHTML =
               '<div class="modal-dialog modal-dialog-centered modal-xl">' +
               '<div class="modal-content">' +
               '<div class="modal-header py-2">' +
               '<h5 class="modal-title"><i class="ti ti-photo me-2"></i>Imagem</h5>' +
               '<a class="btn btn-sm btn-ghost-secondary ms-auto me-2" id="wae-visualizador-abrir" target="_blank" rel="noopener"><i class="ti ti-external-link me-1"></i>Abrir original</a>' +
               '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>' +
               '</div>' +
               '<div class="modal-body text-center p-2">' +
               '<img id="wae-visualizador-img" class="wae-visualizador-img" alt="Imagem">' +
               '</div></div></div>';
            document.body.appendChild(modal);

            if (window.bootstrap && window.bootstrap.Modal) {
               instancia = window.bootstrap.Modal.getOrCreateInstance(modal);
            } else {
               // Sem Bootstrap: fecha no X, no fundo e no Esc
               modal.addEventListener('click', function (e) {
                  if (e.target === modal || (e.target.closest && e.target.closest('[data-bs-dismiss]'))) { fechar(); }
               });
               document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { fechar(); } });
            }
         }

         function fechar() {
            if (instancia) { instancia.hide(); return; }
            modal.classList.remove('show', 'wae-visualizador-aberto');
         }

         function abrir(url) {
            if (!modal) { criar(); }
            document.getElementById('wae-visualizador-img').src = url;
            document.getElementById('wae-visualizador-abrir').href = url;
            if (instancia) {
               instancia.show();
            } else {
               modal.classList.add('show', 'wae-visualizador-aberto');
            }
         }

         return { abrir: abrir, fechar: fechar };
      })();
   }

   function midiaDoBalao(mensagem) {
      if (mensagem.tipo_midia === 'imagem' && mensagem.midia_url) {
         return '<img class="waen-midia-img" src="' + escapar(mensagem.midia_url) + '" alt="Imagem" loading="lazy" data-ampliar="' + escapar(mensagem.midia_url) + '">';
      }
      if (mensagem.tipo_midia === 'audio' && mensagem.midia_url) {
         return '<audio class="waen-midia-audio" controls preload="metadata" src="' + escapar(mensagem.midia_url) + '"></audio>';
      }
      return '';
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

      document.getElementById('waen-mensagens').addEventListener('click', function (evento) {
         if (evento.target.matches && evento.target.matches('img[data-ampliar]')) {
            window.WAEVisualizador.abrir(evento.target.getAttribute('data-ampliar'));
         }
      });

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
      reagendar();
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
            return '<div class="' + classe + '">' + midiaDoBalao(mensagem) + escapar(mensagem.conteudo) +
               '<span class="waen-balao-meta">' + escapar(mensagem.data) + '</span></div>';
         }).join('');

         caixa.scrollTop = caixa.scrollHeight;
         caixa.querySelectorAll('img.waen-midia-img').forEach(function (img) {
            if (!img.complete) { img.addEventListener('load', function () { caixa.scrollTop = caixa.scrollHeight; }, { once: true }); }
         });
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
      if (conversaAtual) { return CONVERSA_ABERTA; }
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
