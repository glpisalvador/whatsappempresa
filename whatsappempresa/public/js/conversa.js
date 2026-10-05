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
         Object.keys(dados).forEach(function (chave) {
            var valor = dados[chave];
            if (typeof File !== 'undefined' && valor instanceof File) {
               corpo.append(chave, valor, valor.name);
            } else {
               corpo.append(chave, valor);
            }
         });
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

   // Chaves das mensagens ja desenhadas: novas mensagens entram no fim sem redesenhar a conversa
   // (redesenhar recarregaria as imagens e interromperia o audio que estiver tocando)
   var desenhadas = [];

   function htmlMensagem(item) {
      var classe = item.direcao === 'saida' ? 'wae-balao wae-balao-saida' : 'wae-balao';
      var midia = '';

      if (item.tipo_midia === 'imagem' && item.midia_url) {
         midia = '<img class="wae-midia-img" src="' + escapar(item.midia_url) + '" alt="Imagem" loading="lazy" data-ampliar="' + escapar(item.midia_url) + '">';
      } else if (item.tipo_midia === 'audio' && item.midia_url) {
         midia = '<audio class="wae-midia-audio" controls preload="metadata" src="' + escapar(item.midia_url) + '"></audio>';
      }

      var texto = item.conteudo ? '<span class="wae-balao-texto">' + escapar(item.conteudo) + '</span>' : '';

      return '<div class="' + classe + '">' + midia + texto +
         '<span class="wae-balao-meta">' + escapar(item.data) + ' - ' + escapar(item.contato || '') + '</span></div>';
   }

   function rolarParaFim(chat) {
      chat.scrollTop = chat.scrollHeight;
   }

   function desenharMensagens(mensagens) {
      var chat = document.getElementById('wae-aba-chat');
      if (!chat) { return; }

      if (!mensagens || !mensagens.length) {
         desenhadas = [];
         chat.innerHTML = '<div class="wae-vazio">Nenhuma mensagem trocada neste chamado.</div>';
         return;
      }

      var chaves = mensagens.map(function (item) { return item.id + ':' + (item.status || ''); });
      var pertoDoFim = chat.scrollHeight - chat.scrollTop - chat.clientHeight < 90;
      var continua = desenhadas.length > 0 && desenhadas.length <= chaves.length &&
         desenhadas.every(function (chave, i) { return chave === chaves[i]; });

      if (continua && desenhadas.length === chaves.length) { return; }

      if (continua) {
         chat.insertAdjacentHTML('beforeend', mensagens.slice(desenhadas.length).map(htmlMensagem).join(''));
      } else {
         chat.innerHTML = mensagens.map(htmlMensagem).join('');
         pertoDoFim = true;
      }

      desenhadas = chaves;

      if (pertoDoFim) {
         rolarParaFim(chat);
         // Imagens terminam de carregar depois: mantem a conversa no fim
         chat.querySelectorAll('img.wae-midia-img').forEach(function (img) {
            if (!img.complete) { img.addEventListener('load', function () { rolarParaFim(chat); }, { once: true }); }
         });
      }
   }

   // ============================================
   // Visualizador de imagem (modal)
   // ============================================

   function abrirImagem(url) {
      if (window.WAEVisualizador) {
         window.WAEVisualizador.abrir(url);
      } else {
         window.open(url, '_blank');
      }
   }

   var chatArea = document.getElementById('wae-aba-chat');
   if (chatArea) {
      chatArea.addEventListener('click', function (evento) {
         var alvo = evento.target;
         if (alvo && alvo.matches && alvo.matches('img[data-ampliar]')) {
            abrirImagem(alvo.getAttribute('data-ampliar'));
         }
      });
   }

   // ============================================
   // Imagens coladas (Ctrl+V) no campo de texto ou na conversa
   // ============================================

   var pendentes = [];
   var LADO_MAXIMO = 1600; // padrao do WhatsApp

   /**
    * Reduz para no maximo 1600px e converte para JPEG: fica leve para o envio
    * (o servidor aceita arquivos pequenos) e igual ao que o WhatsApp faria.
    */
   function prepararImagem(arquivo) {
      return new Promise(function (resolver) {
         if (arquivo.type === 'image/gif') { resolver(arquivo); return; }

         var endereco = URL.createObjectURL(arquivo);
         var img = new Image();
         img.onload = function () {
            var escala = Math.min(1, LADO_MAXIMO / Math.max(img.width, img.height));
            var tela = document.createElement('canvas');
            tela.width = Math.max(1, Math.round(img.width * escala));
            tela.height = Math.max(1, Math.round(img.height * escala));
            var ctx = tela.getContext('2d');
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, tela.width, tela.height);
            ctx.drawImage(img, 0, 0, tela.width, tela.height);
            URL.revokeObjectURL(endereco);
            tela.toBlob(function (blob) {
               resolver(blob ? new File([blob], 'imagem.jpg', { type: 'image/jpeg' }) : arquivo);
            }, 'image/jpeg', 0.85);
         };
         img.onerror = function () { URL.revokeObjectURL(endereco); resolver(arquivo); };
         img.src = endereco;
      });
   }

   function desenharPendentes() {
      var faixa = document.getElementById('wae-aba-anexos');
      if (!faixa) { return; }

      if (!pendentes.length) {
         faixa.innerHTML = '';
         faixa.classList.add('wae-oculto');
         return;
      }

      faixa.innerHTML = pendentes.map(function (item, indice) {
         return '<span class="wae-anexo">' +
            '<img src="' + item.previa + '" alt="Imagem colada" data-ampliar="' + item.previa + '">' +
            '<button type="button" class="wae-anexo-remover" data-remover="' + indice + '" title="Remover"><i class="ti ti-x"></i></button>' +
            '</span>';
      }).join('') +
         '<span class="wae-anexos-dica"><i class="ti ti-photo me-1"></i>' + pendentes.length +
         (pendentes.length === 1 ? ' imagem pronta' : ' imagens prontas') +
         ' para enviar. O texto digitado vai como legenda da primeira.</span>';
      faixa.classList.remove('wae-oculto');
   }

   function removerPendente(indice) {
      var item = pendentes[indice];
      if (item) { URL.revokeObjectURL(item.previa); }
      pendentes.splice(indice, 1);
      desenharPendentes();
   }

   var faixaAnexos = document.getElementById('wae-aba-anexos');
   if (faixaAnexos) {
      faixaAnexos.addEventListener('click', function (evento) {
         var remover = evento.target.closest ? evento.target.closest('[data-remover]') : null;
         if (remover) {
            removerPendente(parseInt(remover.getAttribute('data-remover'), 10));
            return;
         }
         if (evento.target.matches && evento.target.matches('img[data-ampliar]')) {
            abrirImagem(evento.target.getAttribute('data-ampliar'));
         }
      });
   }

   caixa.addEventListener('paste', function (evento) {
      var dados = evento.clipboardData;
      if (!dados || !dados.items) { return; }

      var arquivos = [];
      for (var i = 0; i < dados.items.length; i++) {
         var item = dados.items[i];
         if (item.kind === 'file' && item.type.indexOf('image/') === 0) {
            var arquivo = item.getAsFile();
            if (arquivo) { arquivos.push(arquivo); }
         }
      }

      if (!arquivos.length) { return; }
      evento.preventDefault();

      Promise.all(arquivos.map(prepararImagem)).then(function (prontos) {
         prontos.forEach(function (arquivo) {
            pendentes.push({ arquivo: arquivo, previa: URL.createObjectURL(arquivo) });
         });
         desenharPendentes();
         var texto = document.getElementById('wae-aba-texto');
         if (texto) { texto.focus(); }
      });
   });

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

   function travarEnvio(travar) {
      ['wae-aba-enviar', 'wae-aba-gravar'].forEach(function (id) {
         var botao = document.getElementById(id);
         if (botao) { botao.disabled = travar; }
      });
   }

   function depoisDeEnviar() {
      ateRapido = Date.now() + 180000;
      reagendar();
      carregar();
   }

   function enviarArquivo(tipo, arquivo, legenda, numero) {
      return pedir('conversa_enviar_midia', {
         tickets_id: tickets_id,
         telefone: numero,
         tipo: tipo,
         texto: legenda || '',
         arquivo: arquivo
      }, 'POST');
   }

   /**
    * Envia as imagens coladas uma a uma, na ordem; o texto vai como legenda da primeira
    */
   function enviarImagens(numero, texto) {
      var total = pendentes.length;
      var enviadas = 0;
      var campo = document.getElementById('wae-aba-texto');

      travarEnvio(true);
      avisar('Enviando ' + total + (total === 1 ? ' imagem...' : ' imagens...'), 'info');

      function proxima() {
         if (!pendentes.length) {
            travarEnvio(false);
            avisar('', '');
            depoisDeEnviar();
            return;
         }

         var legenda = enviadas === 0 ? texto : '';
         enviarArquivo('imagem', pendentes[0].arquivo, legenda, numero).then(function (resposta) {
            if (!resposta.sucesso) {
               travarEnvio(false);
               avisar((resposta.mensagem || 'Nao foi possivel enviar a imagem.') +
                  (enviadas > 0 ? ' (' + enviadas + ' de ' + total + ' enviadas)' : ''), 'erro');
               depoisDeEnviar();
               return;
            }
            if (enviadas === 0 && campo) { campo.value = ''; }
            enviadas++;
            removerPendente(0);
            proxima();
         });
      }

      proxima();
   }

   function enviar() {
      var campo = document.getElementById('wae-aba-texto');
      var texto = campo.value.trim();
      var numero = numeroEscolhido();

      if (numero === '') {
         avisar('Escolha o destino da mensagem.', 'erro');
         return;
      }

      if (pendentes.length) {
         enviarImagens(numero, texto);
         return;
      }

      if (texto === '') { return; }

      travarEnvio(true);

      pedir('conversa_enviar', {
         tickets_id: tickets_id,
         telefone: numero,
         texto: texto
      }, 'POST').then(function (resposta) {
         travarEnvio(false);
         if (resposta.sucesso) {
            campo.value = '';
            avisar('', '');
         } else {
            avisar(resposta.mensagem || 'Nao foi possivel enviar.', 'erro');
         }
         depoisDeEnviar();
      });
   }

   // ============================================
   // Gravacao de audio (nota de voz)
   // ============================================

   var gravador = null;
   var microfone = null;
   var pedacos = [];
   var inicioGravacao = 0;
   var relogio = null;
   var descartar = false;
   var LIMITE_GRAVACAO = 5 * 60 * 1000;

   function mostrarGravando(ativo) {
      var campo = document.getElementById('wae-aba-texto');
      var indicador = document.getElementById('wae-aba-gravando');
      var cancelar = document.getElementById('wae-aba-cancelar-gravacao');
      var botao = document.getElementById('wae-aba-gravar');
      var enviarBtn = document.getElementById('wae-aba-enviar');

      if (campo) { campo.classList.toggle('wae-oculto', ativo); }
      if (indicador) { indicador.classList.toggle('wae-oculto', !ativo); }
      if (cancelar) { cancelar.classList.toggle('wae-oculto', !ativo); }
      if (enviarBtn) { enviarBtn.classList.toggle('wae-oculto', ativo); }
      if (botao) {
         botao.className = ativo ? 'btn btn-danger' : 'btn btn-outline-secondary';
         botao.title = ativo ? 'Parar e enviar o audio' : 'Gravar audio';
         botao.innerHTML = ativo ? '<i class="ti ti-send me-1"></i>Enviar audio' : '<i class="ti ti-microphone"></i>';
      }
   }

   function atualizarRelogio() {
      var decorrido = Date.now() - inicioGravacao;
      var segundos = Math.floor(decorrido / 1000);
      var alvo = document.getElementById('wae-aba-relogio');
      if (alvo) { alvo.textContent = Math.floor(segundos / 60) + ':' + String(segundos % 60).padStart(2, '0'); }
      if (decorrido >= LIMITE_GRAVACAO) { pararGravacao(false); }
   }

   function iniciarGravacao() {
      if (!window.isSecureContext) {
         avisar('A gravacao de audio exige que o GLPI seja acessado por HTTPS: o navegador bloqueia o microfone em enderecos http://.', 'erro');
         return;
      }
      if (!window.MediaRecorder || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
         avisar('Este navegador nao permite gravar audio.', 'erro');
         return;
      }
      if (numeroEscolhido() === '') {
         avisar('Escolha o destino antes de gravar.', 'erro');
         return;
      }

      navigator.mediaDevices.getUserMedia({ audio: true }).then(function (fluxo) {
         microfone = fluxo;
         // OGG/Opus quando o navegador suporta (Firefox); senao WebM/Opus, convertido no servidor
         var tipos = ['audio/ogg;codecs=opus', 'audio/webm;codecs=opus', 'audio/webm', 'audio/mp4'];
         var tipo = '';
         for (var i = 0; i < tipos.length; i++) {
            if (MediaRecorder.isTypeSupported(tipos[i])) { tipo = tipos[i]; break; }
         }

         gravador = new MediaRecorder(fluxo, tipo ? { mimeType: tipo, audioBitsPerSecond: 32000 } : undefined);
         pedacos = [];
         descartar = false;
         gravador.ondataavailable = function (e) { if (e.data && e.data.size) { pedacos.push(e.data); } };
         gravador.onstop = finalizarGravacao;
         gravador.start();

         inicioGravacao = Date.now();
         avisar('', '');
         mostrarGravando(true);
         atualizarRelogio();
         relogio = setInterval(atualizarRelogio, 250);
      }).catch(function (erro) {
         avisar('Microfone indisponivel: ' + (erro && erro.name === 'NotAllowedError'
            ? 'a permissao foi negada no navegador.'
            : ((erro && erro.message) || 'erro desconhecido.')), 'erro');
      });
   }

   function pararGravacao(cancelar) {
      descartar = !!cancelar;
      if (gravador && gravador.state !== 'inactive') { gravador.stop(); }
   }

   function finalizarGravacao() {
      var tipo = (gravador && gravador.mimeType) || 'audio/webm';
      var duracao = Date.now() - inicioGravacao;
      var blob = new Blob(pedacos, { type: tipo });

      if (microfone) { microfone.getTracks().forEach(function (t) { t.stop(); }); }
      microfone = null;
      gravador = null;
      clearInterval(relogio);
      mostrarGravando(false);

      if (descartar) { return; }
      if (duracao < 700 || blob.size < 500) {
         avisar('Gravacao muito curta: segure por pelo menos 1 segundo.', 'erro');
         return;
      }

      var extensao = tipo.indexOf('ogg') >= 0 ? 'ogg' : (tipo.indexOf('mp4') >= 0 ? 'm4a' : 'webm');
      var arquivo = new File([blob], 'audio.' + extensao, { type: tipo.split(';')[0] });

      travarEnvio(true);
      avisar('Enviando audio...', 'info');
      enviarArquivo('audio', arquivo, '', numeroEscolhido()).then(function (resposta) {
         travarEnvio(false);
         avisar(resposta.sucesso ? '' : (resposta.mensagem || 'Nao foi possivel enviar o audio.'), resposta.sucesso ? '' : 'erro');
         depoisDeEnviar();
      });
   }

   var botaoGravar = document.getElementById('wae-aba-gravar');
   if (botaoGravar) {
      botaoGravar.addEventListener('click', function () {
         if (gravador) { pararGravacao(false); } else { iniciarGravacao(); }
      });
   }

   var botaoCancelar = document.getElementById('wae-aba-cancelar-gravacao');
   if (botaoCancelar) {
      botaoCancelar.addEventListener('click', function () { pararGravacao(true); });
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
