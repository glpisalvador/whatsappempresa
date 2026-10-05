/* whatsappempresa - aba de conversas no chamado */

(function () {
   'use strict';

   var caixa = document.querySelector('.wae-aba');
   if (!caixa) { return; }

   var raiz = caixa.getAttribute('data-raiz') || (window.CFG_GLPI ? window.CFG_GLPI.root_doc : '');
   var URL_AJAX = (raiz || '') + '/plugins/whatsappempresa/front/ajax.php';
   var tickets_id = caixa.getAttribute('data-tickets-id');
   var telefoneRequerente = caixa.getAttribute('data-telefone') || '';
   var token = '';
   var timer = null;
   var marca = '';
   var ocupado = false;
   var ateRapido = 0;

   var RAPIDO = 1000;
   var NORMAL = 1500;
   var OCIOSO = 15000;
   var falhas = 0;

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
         .catch(function () { return { sucesso: false, mensagem: 'Falha de comunicacao.' }; });
   }

   function escapar(texto) {
      var div = document.createElement('div');
      div.textContent = texto === null || texto === undefined ? '' : String(texto);
      return div.innerHTML;
   }

   function desenharMensagens(mensagens) {
      var chat = document.getElementById('wae-aba-chat');
      if (!chat) { return; }

      if (!mensagens || !mensagens.length) {
         chat.innerHTML = '<div class="wae-vazio">Nenhuma mensagem trocada neste chamado.</div>';
         return;
      }

      chat.innerHTML = mensagens.map(function (item) {
         var classe = item.direcao === 'saida' ? 'wae-balao wae-balao-saida' : 'wae-balao';
         return '<div class="' + classe + '">' + escapar(item.conteudo) +
            '<span class="wae-balao-meta">' + escapar(item.data) + ' - ' + escapar(item.contato || '') + '</span></div>';
      }).join('');

      chat.scrollTop = chat.scrollHeight;
   }

   function avisar(texto, tipo) {
      var caixa = document.getElementById('wae-aba-aviso');
      if (!caixa) { return; }

      if (!texto) {
         caixa.className = 'wae-aviso-linha wae-oculto';
         caixa.textContent = '';
         return;
      }

      caixa.className = 'wae-aviso-linha wae-aviso-' + (tipo || 'info');
      caixa.textContent = texto;
   }

   function desenharAtivas(conversas) {
      var caixa = document.getElementById('wae-aba-ativas');
      if (!caixa) { return; }

      var abertas = (conversas || []).filter(function (item) {
         return item.status !== 'encerrada';
      });

      if (!abertas.length) {
         caixa.innerHTML = '<span class="text-secondary small"><i class="ti ti-mood-empty me-1"></i>Nenhuma conversa aberta</span>';
         return;
      }

      caixa.innerHTML = abertas.map(function (item) {
         var origem = item.origem === 'tecnico' ? 'Aberta pelo atendente' : 'Aberta pelo cliente';
         var numero = item.telefone && item.telefone !== item.contato ? ' (' + escapar(item.telefone) + ')' : '';
         var marca = item.presa
            ? '<span class="badge bg-green-lt" title="' + origem + ' - numero vinculado a esta conversa"><i class="ti ti-lock me-1"></i>'
            : '<span class="badge bg-secondary-lt" title="' + origem + ' - numero livre"><i class="ti ti-unlink me-1"></i>';

         return marca + escapar(item.contato) + numero + '</span>' +
            '<button type="button" class="btn btn-sm btn-outline-danger" data-encerrar="' + item.id + '">' +
            '<i class="ti ti-circle-check me-1"></i>Encerrar conversa</button>';
      }).join('');

      caixa.querySelectorAll('[data-encerrar]').forEach(function (botao) {
         botao.addEventListener('click', function () {
            botao.disabled = true;
            avisar('Encerrando a conversa...', 'info');

            pedir('conversa_encerrar', { conversas_id: botao.getAttribute('data-encerrar') }, 'POST').then(function (resposta) {
               botao.disabled = false;
               avisar(resposta.mensagem || '', resposta.sucesso ? 'sucesso' : 'erro');
               carregar();
            });
         });
      });
   }

   function carregar() {
      var mostrarEncerradas = document.getElementById('wae-aba-encerradas');

      pedir('conversas_ticket', {
         tickets_id: tickets_id,
         encerradas: (mostrarEncerradas && mostrarEncerradas.checked) ? 1 : 0
      }).then(function (resposta) {
         if (!resposta.sucesso) {
            var chat = document.getElementById('wae-aba-chat');
            if (chat) { chat.innerHTML = '<div class="wae-vazio">' + escapar(resposta.mensagem) + '</div>'; }
            return;
         }
         if (resposta.requerente && resposta.requerente.telefone && resposta.requerente.telefone !== telefoneRequerente) {
            telefoneRequerente = resposta.requerente.telefone;
            var destino = document.getElementById('wae-aba-destino');
            if (destino && destino.value === 'requerente') { montarDestinoExtra('requerente'); }
         }
         if (resposta.marca) { marca = resposta.marca; }
         desenharAtivas(resposta.conversas);
         desenharMensagens(resposta.mensagens);
      });
   }

   function montarDestinoExtra(tipo) {
      var extra = document.getElementById('wae-aba-destino-extra');
      if (!extra) { return; }

      if (tipo === 'requerente') {
         extra.innerHTML = telefoneRequerente
            ? '<span class="text-secondary small"><i class="ti ti-phone me-1"></i>' + escapar(telefoneRequerente) + '</span>'
            : '<span class="text-warning small"><i class="ti ti-alert-triangle me-1"></i>Requerente sem celular cadastrado</span>';
         return;
      }

      if (tipo === 'manual') {
         extra.innerHTML = '<input type="text" class="form-control form-control-sm" id="wae-aba-numero" placeholder="5571999999999">';
         return;
      }

      extra.innerHTML = '<span class="wae-sugestoes">' +
         '<input type="text" class="form-control form-control-sm" id="wae-aba-busca" placeholder="Digite o nome" autocomplete="off">' +
         '<span class="wae-lista-sugestoes wae-oculto" id="wae-aba-sugestoes"></span>' +
         '<input type="hidden" id="wae-aba-numero"></span>';

      var busca = document.getElementById('wae-aba-busca');
      var lista = document.getElementById('wae-aba-sugestoes');
      var temporizador = null;

      busca.addEventListener('input', function () {
         clearTimeout(temporizador);
         temporizador = setTimeout(function () {
            if (busca.value.trim().length < 2) {
               lista.classList.add('wae-oculto');
               return;
            }
            pedir('buscar_destinos', { termo: busca.value.trim() }).then(function (resposta) {
               var itens = (resposta.itens || []).filter(function (item) {
                  return tipo === 'usuario' ? item.tipo === 'Usuario' : item.tipo === 'Contato';
               });

               if (!itens.length) {
                  lista.innerHTML = '<span class="wae-item-sugestao">Nenhum resultado</span>';
                  lista.classList.remove('wae-oculto');
                  return;
               }

               lista.innerHTML = itens.map(function (item, indice) {
                  return '<span class="wae-item-sugestao" data-indice="' + indice + '">' +
                     '<span>' + escapar(item.nome) + '</span>' +
                     '<span class="wae-tipo">' + escapar(item.telefone) + '</span></span>';
               }).join('');
               lista.classList.remove('wae-oculto');

               lista.querySelectorAll('.wae-item-sugestao').forEach(function (elemento) {
                  elemento.addEventListener('click', function () {
                     var item = itens[parseInt(elemento.getAttribute('data-indice'), 10)];
                     if (!item) { return; }
                     document.getElementById('wae-aba-numero').value = item.telefone;
                     busca.value = item.nome + ' - ' + item.telefone;
                     lista.classList.add('wae-oculto');
                  });
               });
            });
         }, 350);
      });
   }

   function numeroEscolhido() {
      var tipo = document.getElementById('wae-aba-destino').value;
      if (tipo === 'requerente') { return telefoneRequerente; }
      var campo = document.getElementById('wae-aba-numero');
      return campo ? campo.value.trim() : '';
   }

   function enviar() {
      var texto = document.getElementById('wae-aba-texto').value.trim();
      var numero = numeroEscolhido();

      if (numero === '' || texto === '') { return; }

      var botao = document.getElementById('wae-aba-enviar');
      botao.disabled = true;

      pedir('conversa_enviar', {
         tickets_id: tickets_id,
         telefone: numero,
         texto: texto
      }, 'POST').then(function (resposta) {
         botao.disabled = false;
         if (resposta.sucesso) {
            document.getElementById('wae-aba-texto').value = '';
            avisar('', '');
            ateRapido = Date.now() + 180000;
            reagendar();
         } else {
            avisar(resposta.mensagem || 'Nao foi possivel enviar.', 'erro');
         }
         carregar();
      });
   }

   var seletor = document.getElementById('wae-aba-destino');
   if (seletor) {
      seletor.addEventListener('change', function () { montarDestinoExtra(seletor.value); });
      montarDestinoExtra(seletor.value);
   }

   var botaoEnviar = document.getElementById('wae-aba-enviar');
   if (botaoEnviar) { botaoEnviar.addEventListener('click', enviar); }

   // Enter envia; Shift+Enter quebra a linha
   var campoTexto = document.getElementById('wae-aba-texto');
   if (campoTexto) {
      campoTexto.addEventListener('keydown', function (evento) {
         if (evento.key === 'Enter' && !evento.shiftKey && !evento.isComposing) {
            evento.preventDefault();
            if (!botaoEnviar || !botaoEnviar.disabled) { enviar(); }
         }
      });
   }

   var atualizar = document.getElementById('wae-aba-atualizar');
   if (atualizar) { atualizar.addEventListener('click', carregar); }

   var alternarEncerradas = document.getElementById('wae-aba-encerradas');
   if (alternarEncerradas) { alternarEncerradas.addEventListener('change', carregar); }

   /**
    * Consulta barata: so recarrega a tela quando algo mudou de verdade
    */
   function pulsar() {
      if (document.hidden || ocupado) { return; }

      ocupado = true;
      pedir('conversa_pulso', { tickets_id: tickets_id }).then(function (resposta) {
         ocupado = false;

         // Se o pulso falhar, recarrega direto para nao travar a tela
         if (!resposta || !resposta.sucesso || typeof resposta.marca === 'undefined') {
            falhas++;
            if (falhas >= 2) { carregar(); }
            return;
         }

         falhas = 0;

         if (resposta.marca !== marca) {
            marca = resposta.marca;
            ateRapido = Date.now() + 180000;
            carregar();
            reagendar();
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

   // Voltar para a aba mostra o estado atual na hora
   document.addEventListener('visibilitychange', function () {
      if (!document.hidden) {
         ateRapido = Date.now() + 180000;
         pulsar();
         reagendar();
      } else {
         reagendar();
      }
   });

   window.addEventListener('focus', pulsar);

   carregar();
   pulsar();
   ateRapido = Date.now() + 180000;
   reagendar();

   window.addEventListener('beforeunload', function () {
      if (timer) { clearInterval(timer); }
   });
})();
