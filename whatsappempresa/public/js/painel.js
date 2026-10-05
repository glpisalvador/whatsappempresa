/**
 * whatsappempresa - painel de configuracao (abas nativas do GLPI)
 *
 * Utilitarios compartilhados (window.WAE) e a aba Servidor.
 * As requisicoes usam jQuery: no GLPI 11 o token CSRF vai automaticamente no cabecalho
 * dos POST; no GLPI 12 a protecao e por cabecalho do navegador e nao usa token.
 */
(function () {
   'use strict';

   var raiz = (typeof CFG_GLPI !== 'undefined' && CFG_GLPI.root_doc) ? CFG_GLPI.root_doc : '';
   var URL_AJAX = raiz + '/plugins/whatsappempresa/front/ajax.php';

   function pedir(acao, dados, metodo) {
      dados = Object.assign({}, dados || {}, { acao: acao });
      return new Promise(function (resolver) {
         $.ajax({
            url: URL_AJAX,
            type: metodo || 'GET',
            data: dados,
            dataType: 'json'
         }).done(function (resposta) {
            resolver(resposta || { sucesso: false, mensagem: 'Resposta vazia do servidor.' });
         }).fail(function () {
            resolver({ sucesso: false, mensagem: 'Falha de comunicacao com o GLPI.' });
         });
      });
   }

   function escapar(texto) {
      var div = document.createElement('div');
      div.textContent = texto === null || texto === undefined ? '' : String(texto);
      return div.innerHTML;
   }

   /** Notificacao nativa do GLPI */
   function avisar(texto, erro) {
      if (!texto) { return; }
      if (erro && typeof glpi_toast_error === 'function') {
         glpi_toast_error(escapar(texto));
      } else if (!erro && typeof glpi_toast_info === 'function') {
         glpi_toast_info(escapar(texto));
      } else {
         window.alert(texto);
      }
   }

   /** Confirmacao nativa do GLPI, como Promise<boolean> */
   function confirmar(texto) {
      return new Promise(function (resolver) {
         if (typeof glpi_confirm !== 'function') {
            resolver(window.confirm(texto));
            return;
         }
         var respondido = false;
         glpi_confirm({
            title: 'Confirmacao',
            message: escapar(texto),
            confirm_callback: function () { respondido = true; resolver(true); },
            cancel_callback: function () { respondido = true; resolver(false); },
            close_callback: function () { if (!respondido) { resolver(false); } }
         });
      });
   }

   window.WAE = {
      pedir: pedir,
      avisar: avisar,
      escapar: escapar,
      confirmar: confirmar,
      podeEditar: false
   };

   // ============================================
   // Aba Servidor: preparacao (uma vez) e numeros conectados (um servidor Node por numero)
   // ============================================

   var ESTADOS = {
      ok:        { classe: 'bg-green-lt',  icone: 'ti ti-circle-check',    texto: 'Pronto' },
      pendente:  { classe: 'bg-yellow-lt', icone: 'ti ti-alert-circle',    texto: 'Pendente' },
      erro:      { classe: 'bg-red-lt',    icone: 'ti ti-alert-triangle',  texto: 'Atenção' },
      andamento: { classe: 'bg-blue-lt',   icone: 'ti ti-loader-2',        texto: 'Em andamento' }
   };

   var ACOES = {
      node_instalar:         { rotulo: 'Instalar',      icone: 'ti ti-download',     classe: 'btn-primary' },
      node_atualizar:        { rotulo: 'Atualizar',     icone: 'ti ti-refresh',      classe: 'btn-outline-secondary' },
      dependencias_instalar: { rotulo: 'Instalar',      icone: 'ti ti-package',      classe: 'btn-primary' },
      vigia_ativar:          { rotulo: 'Ativar',        icone: 'ti ti-eye-check',    classe: 'btn-primary' },
      vigia_desativar:       { rotulo: 'Desativar',     icone: 'ti ti-eye-off',      classe: 'btn-outline-danger' },
      webhook_testar:        { rotulo: 'Testar',        icone: 'ti ti-arrows-exchange', classe: 'btn-outline-secondary' }
   };

   var CONFIRMACOES = {
      node_atualizar: 'Baixar e instalar a versão LTS mais recente do Node.js? Os números ligados serão reiniciados em seguida.',
      dependencias_instalar: 'Instalar ou atualizar as dependências do servidor? Isso pode levar alguns minutos.',
      vigia_desativar: 'Desativar o vigia? Se um número cair, ele não será religado automaticamente.',
      servico_parar: 'Parar este número? As mensagens dele deixam de ser recebidas e enviadas até que seja iniciado de novo.',
      aparelho_desvincular: 'Desvincular o aparelho deste número? Será preciso ler um novo QR Code para voltar a atender.'
   };

   var TAREFAS = { node_instalar: 'node', node_atualizar: 'node', dependencias_instalar: 'dependencias' };

   var painel = null;
   var ocupado = false;
   var etapasAtuais = [];
   var conexoesAtuais = [];
   var desenhoConexao = {};   // id -> assinatura do ultimo desenho (so redesenha o que mudou)
   var qrCache = {};          // id -> imagem do QR
   var timerConexoes = null;
   var timerEtapas = null;

   function el(seletor) {
      return painel ? painel.querySelector(seletor) : null;
   }

   function travar(sim) {
      ocupado = sim;
      if (!painel) { return; }
      painel.querySelectorAll('[data-wae-acao], #wae-preparar').forEach(function (b) {
         b.disabled = sim || !WAE.podeEditar;
      });
   }

   // ---------- Preparacao do servidor (etapas gerais) ----------

   function desenharEtapas(etapas) {
      etapasAtuais = etapas;
      var lista = el('#wae-etapas');
      if (!lista) { return; }

      lista.innerHTML = etapas.map(function (etapa, indice) {
         var estado = ESTADOS[etapa.estado] || ESTADOS.pendente;
         var botoes = (etapa.acoes || []).map(function (acao) {
            var def = ACOES[acao];
            if (!def) { return ''; }
            var reinstalar = acao === 'dependencias_instalar' && etapa.estado === 'ok';
            return '<button type="button" class="btn btn-sm ' + (reinstalar ? 'btn-outline-secondary' : def.classe) + '" data-wae-acao="' + acao + '"' +
               (WAE.podeEditar ? '' : ' disabled') + '><i class="' + def.icone + ' me-1"></i>' + (reinstalar ? 'Reinstalar' : def.rotulo) + '</button>';
         }).join('');

         return '<div class="list-group-item"><div class="row align-items-center g-2">' +
            '<div class="col-auto"><span class="avatar avatar-sm ' + estado.classe + '"><i class="' + estado.icone + '"></i></span></div>' +
            '<div class="col"><div class="fw-semibold">' + (indice + 1) + '. ' + escapar(etapa.rotulo) +
               ' <span class="badge ' + estado.classe + ' ms-1">' + estado.texto + '</span></div>' +
               '<div class="text-secondary small text-break">' + escapar(etapa.detalhe) + '</div></div>' +
            '<div class="col-auto d-flex flex-wrap gap-1">' + botoes + '</div>' +
         '</div></div>';
      }).join('');

      var pronto = etapas.every(function (e) { return e.estado === 'ok' || e.chave === 'webhook'; });
      var badge = el('#wae-badge-geral');
      if (badge) {
         badge.className = 'badge ' + (pronto ? 'bg-green-lt' : 'bg-yellow-lt');
         badge.textContent = pronto ? 'Tudo pronto' : 'Preparação pendente';
      }

      etapas.forEach(function (etapa) {
         if (etapa.estado === 'andamento' && etapa.tarefa) { acompanharTarefa(etapa.tarefa); }
      });
   }

   function atualizarEtapas(testarWebhook) {
      return pedir('etapas', { webhook: testarWebhook ? 1 : 0 }).then(function (r) {
         if (r.sucesso) { desenharEtapas(r.etapas || []); } else { avisar(r.mensagem, true); }
         return r;
      });
   }

   function mostrarTarefa(titulo, log, estado) {
      var caixa = el('#wae-tarefa');
      if (!caixa) { return; }
      caixa.hidden = false;
      el('#wae-tarefa-titulo').textContent = titulo;
      var saida = el('#wae-tarefa-log');
      saida.textContent = log || '...';
      saida.scrollTop = saida.scrollHeight;
      var badge = el('#wae-tarefa-estado');
      var def = ESTADOS[estado] || ESTADOS.andamento;
      badge.className = 'badge ' + def.classe;
      badge.textContent = estado === 'ok' ? 'Concluída' : (estado === 'erro' ? 'Falhou' : 'Em andamento');
   }

   var acompanhando = {};

   /** Acompanha a tarefa em segundo plano ate terminar. Resolve true se deu certo. */
   function acompanharTarefa(nome) {
      if (acompanhando[nome]) { return acompanhando[nome]; }
      var titulo = nome === 'node' ? 'Instalação do Node.js' : 'Instalação das dependências';

      acompanhando[nome] = new Promise(function (resolver) {
         function consultar() {
            pedir('tarefa', { tarefa: nome }).then(function (r) {
               if (!r.sucesso) { delete acompanhando[nome]; resolver(false); return; }
               var t = r.tarefa;
               mostrarTarefa(titulo, t.log, t.rodando ? 'andamento' : (t.sucesso ? 'ok' : 'erro'));
               if (t.rodando) { setTimeout(consultar, 2000); return; }
               delete acompanhando[nome];
               avisar(t.sucesso ? titulo + ' concluída.' : titulo + ' falhou. Veja o registro.', !t.sucesso);
               resolver(!!t.sucesso);
            });
         }
         consultar();
      });
      return acompanhando[nome];
   }

   /** Acao do servidor: gerais (Node, dependencias, vigia, webhook) ou de um numero (conexao informada) */
   function executarAcao(acao, conexao, semConfirmar) {
      var pergunta = (!semConfirmar && CONFIRMACOES[acao]) ? confirmar(CONFIRMACOES[acao]) : Promise.resolve(true);

      return pergunta.then(function (ok) {
         if (!ok) { return false; }
         travar(true);
         var dados = { tipo: acao };
         if (conexao) { dados.conexao = conexao; }
         return pedir('acao_servidor', dados, 'POST').then(function (r) {
            if (!r.sucesso) {
               avisar(r.mensagem, true);
               if (r.log) { mostrarTarefa('Log do servidor', r.log, 'erro'); }
               return false;
            }
            if (TAREFAS[acao]) { return acompanharTarefa(TAREFAS[acao]); }
            avisar(r.mensagem, false);
            return true;
         }).then(function (resultado) {
            travar(false);
            var depois = conexao ? atualizarConexoes() : atualizarEtapas(acao === 'webhook_testar');
            return depois.then(function () { return resultado; });
         });
      });
   }

   function etapa(chave) {
      for (var i = 0; i < etapasAtuais.length; i++) {
         if (etapasAtuais[i].chave === chave) { return etapasAtuais[i]; }
      }
      return null;
   }

   /** Executa em sequencia o que falta e liga o numero padrao */
   function prepararTudo() {
      var passos = [
         { chave: 'node',         acao: 'node_instalar' },
         { chave: 'dependencias', acao: 'dependencias_instalar' },
         { chave: 'vigia',        acao: 'vigia_ativar' }
      ];

      travar(true);
      var cadeia = atualizarEtapas(false);

      passos.forEach(function (passo) {
         cadeia = cadeia.then(function (continuar) {
            if (continuar === false) { return false; }
            var atual = etapa(passo.chave);
            if (atual && atual.estado === 'ok') { return true; }
            return executarAcao(passo.acao, 0, true);
         });
      });

      cadeia = cadeia.then(function (continuar) {
         if (continuar === false) { return false; }
         var padrao = conexoesAtuais.filter(function (c) { return c.padrao; })[0] || conexoesAtuais[0];
         if (!padrao || padrao.ligado) { return true; }
         return executarAcao('servico_iniciar', padrao.id, true);
      });

      return cadeia.then(function (resultado) {
         travar(false);
         return atualizarEtapas(true).then(function () {
            avisar(resultado === false
               ? 'A preparação parou em uma etapa. Veja o detalhe na lista.'
               : 'Servidor preparado. Leia o QR Code do número para parear o aparelho.', resultado === false);
         });
      });
   }

   // ---------- Numeros conectados ----------

   function situacao(c) {
      if (c.conectado) { return { classe: 'bg-green', texto: 'Conectado', icone: 'ti ti-device-mobile-check' }; }
      if (c.ligado && c.tem_qr) { return { classe: 'bg-yellow', texto: 'Aguardando QR Code', icone: 'ti ti-qrcode' }; }
      if (c.ligado) { return { classe: 'bg-blue', texto: 'Conectando...', icone: 'ti ti-loader-2' }; }
      if (c.deve_ligado) { return { classe: 'bg-red', texto: 'Não responde', icone: 'ti ti-alert-triangle' }; }
      return { classe: 'bg-secondary', texto: 'Parado', icone: 'ti ti-player-stop' };
   }

   function formatarNumero(numero) {
      var d = String(numero || '').replace(/\D/g, '');
      if (d.length === 13) { return '+' + d.slice(0, 2) + ' (' + d.slice(2, 4) + ') ' + d.slice(4, 9) + '-' + d.slice(9); }
      if (d.length === 12) { return '+' + d.slice(0, 2) + ' (' + d.slice(2, 4) + ') ' + d.slice(4, 8) + '-' + d.slice(8); }
      return d;
   }

   function htmlConexao(c) {
      var s = situacao(c);
      var pode = WAE.podeEditar;
      var dis = pode ? '' : ' disabled';

      var visual;
      if (c.conectado) {
         visual = '<div class="wae-con-visual wae-con-ok"><i class="ti ti-device-mobile-check"></i><span>Aparelho conectado</span></div>';
      } else if (c.ligado && c.tem_qr) {
         visual = '<div class="wae-con-visual" data-qr="' + c.id + '">' +
            (qrCache[c.id] ? '<img src="' + qrCache[c.id] + '" alt="QR Code">' : '<i class="ti ti-loader-2"></i><span>Gerando QR Code...</span>') + '</div>';
      } else if (c.ligado) {
         visual = '<div class="wae-con-visual"><i class="ti ti-loader-2"></i><span>Conectando ao WhatsApp...</span></div>';
      } else {
         visual = '<div class="wae-con-visual wae-con-parado"><i class="ti ti-qrcode-off"></i><span>' + (c.pareado ? 'Pareado, mas parado' : 'Inicie para ver o QR Code') + '</span></div>';
      }

      var botoes = c.ligado
         ? '<button type="button" class="btn btn-sm btn-outline-secondary" data-con-acao="servico_reiniciar"' + dis + '><i class="ti ti-refresh me-1"></i>Reiniciar</button>' +
           '<button type="button" class="btn btn-sm btn-outline-danger" data-con-acao="servico_parar"' + dis + '><i class="ti ti-player-stop me-1"></i>Parar</button>'
         : '<button type="button" class="btn btn-sm btn-success" data-con-acao="servico_iniciar"' + dis + '><i class="ti ti-player-play me-1"></i>Iniciar</button>';

      var menu = pode
         ? '<div class="dropdown"><button type="button" class="btn btn-sm btn-ghost-secondary" data-bs-toggle="dropdown" title="Mais opções"><i class="ti ti-dots-vertical"></i></button>' +
              '<div class="dropdown-menu dropdown-menu-end">' +
                 '<a href="#" class="dropdown-item" data-con-editar="renomear"><i class="ti ti-pencil me-2"></i>Renomear</a>' +
                 (c.padrao ? '' : '<a href="#" class="dropdown-item" data-con-editar="padrao"><i class="ti ti-star me-2"></i>Definir como padrão</a>') +
                 (c.pareado ? '<a href="#" class="dropdown-item text-danger" data-con-acao="aparelho_desvincular"><i class="ti ti-unlink me-2"></i>Desvincular aparelho</a>' : '') +
                 (c.padrao ? '' : '<div class="dropdown-divider"></div><a href="#" class="dropdown-item text-danger" data-con-editar="remover"><i class="ti ti-trash me-2"></i>Remover número</a>') +
              '</div></div>'
         : '';

      return '<div class="card wae-conexao' + (c.padrao ? ' wae-conexao-padrao' : '') + '">' +
         '<div class="card-header">' +
            '<span class="wae-con-ponto ' + s.classe + '" title="' + s.texto + '"></span>' +
            '<div class="wae-con-titulo">' +
               '<div class="d-flex align-items-center gap-2"><span class="fw-bold" data-con-nome>' + escapar(c.nome) + '</span>' +
                  (c.padrao ? '<span class="badge bg-yellow-lt" title="Usado quando o número não é definido por uma conversa: avisos de status e pedidos de aprovação">Padrão</span>' : '') +
               '</div>' +
               '<div class="small text-secondary">' + (c.numero ? '<i class="ti ti-brand-whatsapp me-1"></i>' + escapar(formatarNumero(c.numero)) + (c.nome_aparelho ? ' · ' + escapar(c.nome_aparelho) : '') : 'Sem aparelho pareado') + '</div>' +
            '</div>' +
            '<span class="badge ' + s.classe + '-lt ms-auto"><i class="' + s.icone + ' me-1"></i>' + s.texto + '</span>' +
            menu +
         '</div>' +
         '<div class="card-body">' +
            '<div class="wae-con-corpo">' + visual +
               '<div class="datagrid wae-con-dados">' +
                  '<div class="datagrid-item"><div class="datagrid-title">Fluxos</div><div class="datagrid-content">' +
                     c.fluxos_ativos + ' ativo(s) de ' + c.fluxos +
                     ' · <a href="#" data-abrir-fluxos="' + c.id + '">abrir</a></div></div>' +
                  '<div class="datagrid-item"><div class="datagrid-title">Hoje</div><div class="datagrid-content">' +
                     '<span title="Recebidas"><i class="ti ti-arrow-down-left text-green"></i> ' + c.recebidas + '</span> · ' +
                     '<span title="Enviadas"><i class="ti ti-arrow-up-right text-blue"></i> ' + c.enviadas + '</span>' +
                     (c.falhas ? ' · <span class="text-danger" title="Falhas de envio"><i class="ti ti-alert-triangle"></i> ' + c.falhas + '</span>' : '') + '</div></div>' +
                  '<div class="datagrid-item"><div class="datagrid-title">Conectado desde</div><div class="datagrid-content">' + escapar(c.desde || '-') + '</div></div>' +
                  '<div class="datagrid-item"><div class="datagrid-title">Porta local</div><div class="datagrid-content">' + c.porta + (c.pid ? ' · pid ' + c.pid : '') + '</div></div>' +
               '</div>' +
            '</div>' +
         '</div>' +
         '<div class="card-footer d-flex flex-wrap align-items-center gap-2">' + botoes +
            '<button type="button" class="btn btn-sm btn-ghost-secondary ms-auto" data-con-log><i class="ti ti-file-text me-1"></i>Log</button>' +
         '</div>' +
      '</div>';
   }

   function desenharConexoes(lista) {
      conexoesAtuais = lista;
      var caixa = el('#wae-conexoes');
      if (!caixa) { return; }
      el('#wae-total-conexoes').textContent = lista.length;

      if (!lista.length) {
         caixa.innerHTML = '<div class="col-12 text-secondary">Nenhum número cadastrado. Clique em "Novo número".</div>';
         return;
      }

      var ids = lista.map(function (c) { return String(c.id); });

      // Remove cartoes de conexoes que nao existem mais
      caixa.querySelectorAll('[data-conexao]').forEach(function (col) {
         if (ids.indexOf(col.getAttribute('data-conexao')) < 0) { col.remove(); delete desenhoConexao[col.getAttribute('data-conexao')]; }
      });
      if (!caixa.querySelector('[data-conexao]')) { caixa.innerHTML = ''; }

      lista.forEach(function (c) {
         var col = caixa.querySelector('[data-conexao="' + c.id + '"]');
         if (!col) {
            col = document.createElement('div');
            col.className = 'col-xl-6 col-xxl-4';
            col.setAttribute('data-conexao', c.id);
            col.innerHTML = '<div data-con-cartao></div><pre class="wae-con-log bg-dark text-light small" hidden></pre>';
            caixa.appendChild(col);
         }
         // So redesenha o cartao que mudou (log aberto e edicao de nome continuam)
         var assinatura = JSON.stringify(c) + (qrCache[c.id] ? qrCache[c.id].length : 0);
         if (desenhoConexao[c.id] !== assinatura && !col.querySelector('[data-renomeando]')) {
            col.querySelector('[data-con-cartao]').innerHTML = htmlConexao(c);
            desenhoConexao[c.id] = assinatura;
         }
         if (c.ligado && c.tem_qr) { buscarQr(c.id); } else { delete qrCache[c.id]; }
      });

      preencherSeletores(lista);
   }

   function buscarQr(id) {
      pedir('qrcode', { conexao: id }).then(function (r) {
         if (!(r.sucesso && r.qr) || qrCache[id] === r.qr) { return; }
         qrCache[id] = r.qr;
         var alvo = el('[data-qr="' + id + '"]');
         if (alvo) { alvo.innerHTML = '<img src="' + r.qr + '" alt="QR Code">'; }
      });
   }

   function atualizarConexoes() {
      return pedir('conexoes').then(function (r) {
         if (r.sucesso) { desenharConexoes(r.conexoes || []); }
         return r;
      });
   }

   /** Seletores "qual numero" dos cartoes de teste */
   function preencherSeletores(lista) {
      painel.querySelectorAll('.wae-seletor-conexao').forEach(function (sel) {
         var atual = sel.value;
         sel.innerHTML = lista.map(function (c) {
            return '<option value="' + c.id + '">' + escapar(c.nome + (c.numero ? ' (' + formatarNumero(c.numero) + ')' : '')) + '</option>';
         }).join('');
         if (atual && lista.some(function (c) { return String(c.id) === atual; })) {
            sel.value = atual;
         } else {
            var padrao = lista.filter(function (c) { return c.padrao; })[0];
            if (padrao) { sel.value = padrao.id; }
         }
      });
   }

   function alternarLog(col, id) {
      var pre = col.querySelector('.wae-con-log');
      if (!pre.hidden) { pre.hidden = true; return; }
      pre.hidden = false;
      pre.textContent = 'Carregando...';
      pedir('log_servidor', { conexao: id }).then(function (r) {
         pre.textContent = (r.sucesso && r.conteudo) ? r.conteudo : 'Sem registros ainda.';
         pre.scrollTop = pre.scrollHeight;
      });
   }

   function renomear(col, id) {
      var nome = col.querySelector('[data-con-nome]');
      if (!nome || col.querySelector('[data-renomeando]')) { return; }
      var atual = nome.textContent;
      nome.outerHTML = '<span class="input-group input-group-sm wae-con-renomear" data-renomeando>' +
         '<input type="text" class="form-control" value="' + escapar(atual) + '" maxlength="80">' +
         '<button type="button" class="btn btn-primary" data-renomear-ok><i class="ti ti-check"></i></button>' +
         '<button type="button" class="btn btn-outline-secondary" data-renomear-cancelar><i class="ti ti-x"></i></button></span>';
      var campo = col.querySelector('[data-renomeando] input');
      campo.focus();
      campo.select();
   }

   function abrirFluxosDa(id) {
      try { localStorage.setItem('wae-construtor-conexao', String(id)); } catch (x) { /* sem armazenamento */ }
      var aba = document.querySelector('a[data-glpi-ajax-content*="PluginWhatsappempresaPainel$5"], a[data-glpi-ajax-content*="PluginWhatsappempresaPainel%245"]');
      if (aba) { aba.click(); } else { avisar('Abra a aba Fluxos: o número já está selecionado lá.'); }
   }

   function ligarConexoes() {
      var abrirNova = el('#wae-nova-conexao-abrir');
      if (abrirNova) {
         var caixa = el('#wae-nova-conexao');
         var nome = el('#wae-nova-conexao-nome');
         var alternar = function (mostrar) {
            caixa.classList.toggle('wae-oculto', !mostrar);
            abrirNova.classList.toggle('wae-oculto', mostrar);
            if (mostrar) { nome.value = ''; nome.focus(); }
         };
         abrirNova.addEventListener('click', function () { alternar(true); });
         el('#wae-nova-conexao-cancelar').addEventListener('click', function () { alternar(false); });
         var criar = function () {
            var botao = el('#wae-nova-conexao-criar');
            botao.disabled = true;
            pedir('conexao_criar', { nome: nome.value }, 'POST').then(function (r) {
               botao.disabled = false;
               avisar(r.mensagem, !r.sucesso);
               if (r.sucesso) { alternar(false); atualizarConexoes(); }
            });
         };
         el('#wae-nova-conexao-criar').addEventListener('click', criar);
         nome.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { ev.preventDefault(); criar(); } });
      }

      el('#wae-conexoes').addEventListener('click', function (ev) {
         var col = ev.target.closest('[data-conexao]');
         if (!col) { return; }
         var id = parseInt(col.getAttribute('data-conexao'), 10);

         var acao = ev.target.closest('[data-con-acao]');
         if (acao) {
            ev.preventDefault();
            if (!ocupado) { executarAcao(acao.getAttribute('data-con-acao'), id, false); }
            return;
         }

         if (ev.target.closest('[data-con-log]')) { alternarLog(col, id); return; }

         var abrir = ev.target.closest('[data-abrir-fluxos]');
         if (abrir) { ev.preventDefault(); abrirFluxosDa(id); return; }

         var editar = ev.target.closest('[data-con-editar]');
         if (editar) {
            ev.preventDefault();
            var tipo = editar.getAttribute('data-con-editar');
            if (tipo === 'renomear') { renomear(col, id); return; }
            var conexao = conexoesAtuais.filter(function (c) { return c.id === id; })[0] || {};
            var pergunta = tipo === 'remover'
               ? confirmar('Remover o número "' + conexao.nome + '"? O servidor dele será parado, o pareamento apagado e os fluxos dele removidos. Conversas e mensagens ficam no histórico.')
               : Promise.resolve(true);
            pergunta.then(function (ok) {
               if (!ok) { return; }
               pedir('conexao_editar', { conexao: id, tipo: tipo }, 'POST').then(function (r) {
                  avisar(r.mensagem, !r.sucesso);
                  atualizarConexoes();
               });
            });
            return;
         }

         if (ev.target.closest('[data-renomear-cancelar]')) {
            delete desenhoConexao[id];
            col.querySelector('[data-renomeando]').remove();
            atualizarConexoes();
            return;
         }
         if (ev.target.closest('[data-renomear-ok]')) {
            var novo = col.querySelector('[data-renomeando] input').value;
            pedir('conexao_editar', { conexao: id, tipo: 'renomear', nome: novo }, 'POST').then(function (r) {
               avisar(r.mensagem, !r.sucesso);
               if (!r.sucesso) { return; }
               delete desenhoConexao[id];
               col.querySelector('[data-renomeando]').remove();
               atualizarConexoes();
            });
         }
      });

      el('#wae-conexoes').addEventListener('keydown', function (ev) {
         if (ev.key === 'Enter' && ev.target.closest('[data-renomeando]')) {
            ev.preventDefault();
            ev.target.closest('[data-renomeando]').querySelector('[data-renomear-ok]').click();
         }
      });
   }

   // ---------- Rede, testes e situacao de um telefone ----------

   function desenharDados(seletor, dados) {
      var caixa = el(seletor);
      if (!caixa) { return; }
      caixa.innerHTML = Object.keys(dados || {}).map(function (chave) {
         return '<div class="datagrid-item"><div class="datagrid-title">' + escapar(chave) + '</div>' +
            '<div class="datagrid-content text-break">' + escapar(dados[chave]) + '</div></div>';
      }).join('');
   }

   function atualizarInfo() {
      pedir('painel_info').then(function (r) {
         if (r.sucesso) { desenharDados('#wae-info-rede', r.rede); }
      });
   }

   function ligarTestes() {
      var enviar = el('#wae-enviar-teste');
      if (enviar) {
         enviar.addEventListener('click', function () {
            enviar.disabled = true;
            pedir('enviar_teste', {
               conexao: el('#wae-teste-conexao').value,
               telefone: el('#wae-teste-telefone').value,
               texto: el('#wae-teste-texto').value
            }, 'POST').then(function (r) {
               enviar.disabled = false;
               avisar(r.mensagem, !r.sucesso);
            });
         });
      }

      var consultar = el('#wae-consultar-fluxo');
      if (consultar) {
         consultar.addEventListener('click', function () {
            pedir('fluxo_situacao', { conexao: el('#wae-liberar-conexao').value, telefone: el('#wae-liberar-telefone').value }).then(function (r) {
               var saida = el('#wae-resultado-fluxo');
               if (!r.sucesso) { avisar(r.mensagem, true); return; }
               saida.hidden = false;
               saida.innerHTML = '<div class="datagrid">' +
                  '<div class="datagrid-item"><div class="datagrid-title">Fluxo atual</div><div class="datagrid-content">' + escapar(r.fluxo) + '</div></div>' +
                  '<div class="datagrid-item"><div class="datagrid-title">Etapa</div><div class="datagrid-content">' + escapar(r.etapa) + '</div></div>' +
                  '<div class="datagrid-item"><div class="datagrid-title">Conversa aberta</div><div class="datagrid-content">' + (r.aberta ? '#' + r.aberta : 'nenhuma') + '</div></div>' +
                  '<div class="datagrid-item"><div class="datagrid-title">Chamado</div><div class="datagrid-content">' + (r.tickets_id ? '#' + r.tickets_id : '-') + '</div></div>' +
                  '</div>';
            });
         });
      }

      var liberar = el('#wae-liberar-numero');
      if (liberar) {
         liberar.addEventListener('click', function () {
            confirmar('Liberar este telefone? A conversa aberta nele será encerrada e ele volta ao atendimento automático.').then(function (ok) {
               if (!ok) { return; }
               pedir('fluxo_liberar', { conexao: el('#wae-liberar-conexao').value, telefone: el('#wae-liberar-telefone').value }, 'POST').then(function (r) {
                  avisar(r.mensagem, !r.sucesso);
               });
            });
         });
      }
   }

   /** Chamado pelo conteudo da aba Servidor quando ele chega por ajax */
   function iniciarServidor(id) {
      painel = document.getElementById(id);
      if (!painel) { return; }
      WAE.podeEditar = painel.getAttribute('data-edita') === '1';
      desenhoConexao = {};

      painel.addEventListener('click', function (evento) {
         var botao = evento.target.closest('[data-wae-acao]');
         if (botao && !ocupado) { executarAcao(botao.getAttribute('data-wae-acao'), 0, false); }
      });

      var preparar = el('#wae-preparar');
      if (preparar) {
         preparar.disabled = !WAE.podeEditar;
         preparar.addEventListener('click', function () { if (!ocupado) { prepararTudo(); } });
      }

      ligarConexoes();
      ligarTestes();
      atualizarEtapas(false);
      atualizarConexoes();
      atualizarInfo();

      // Enquanto a aba estiver na tela: numeros e QR a cada 5 s, etapas a cada 30 s
      clearInterval(timerConexoes);
      clearInterval(timerEtapas);
      timerConexoes = setInterval(function () {
         if (!document.body.contains(painel)) { clearInterval(timerConexoes); return; }
         if (!ocupado && !document.hidden) { atualizarConexoes(); }
      }, 5000);
      timerEtapas = setInterval(function () {
         if (!document.body.contains(painel)) { clearInterval(timerEtapas); return; }
         if (!ocupado && !document.hidden) { atualizarEtapas(false); }
      }, 30000);
   }

   /** Abas com formulario: marca se o usuario pode editar (usado pelos fluxos) */
   function definirEdicao(pode) {
      WAE.podeEditar = !!pode;
   }

   // ============================================
   // Aba Mensagens: todas as mensagens em tempo real
   // ============================================

   var CORES_TIPO = {
      fluxo: 'bg-purple-lt', servidor: 'bg-secondary-lt', usuario: 'bg-blue-lt',
      contato_glpi: 'bg-cyan-lt', contato_cliente: 'bg-green-lt', desconhecido: 'bg-yellow-lt'
   };

   function formatarNumero(numero) {
      var d = String(numero || '').replace(/\D/g, '');
      if (d.length === 13 && d.indexOf('55') === 0) { return '+55 (' + d.slice(2, 4) + ') ' + d.slice(4, 9) + '-' + d.slice(9); }
      if (d.length === 12 && d.indexOf('55') === 0) { return '+55 (' + d.slice(2, 4) + ') ' + d.slice(4, 8) + '-' + d.slice(8); }
      if (d.length === 11) { return '(' + d.slice(0, 2) + ') ' + d.slice(2, 7) + '-' + d.slice(7); }
      return d || '-';
   }

   var historicoVariasConexoes = false;

   function linhaHistorico(m, nova) {
      var e = escapar;
      var entrada = m.direcao === 'entrada';
      var midia = m.midia === 'imagem' ? '<i class="ti ti-photo me-1"></i>' : (m.midia === 'audio' ? '<i class="ti ti-microphone me-1"></i>' : '');
      var envio = entrada
         ? '<i class="ti ti-arrow-bar-to-down text-secondary" title="Recebida"></i>'
         : (m.status === 'erro'
            ? '<i class="ti ti-alert-triangle text-danger" title="' + e(m.erro || 'Falha no envio') + '"></i>'
            : (m.status === 'pendente' ? '<i class="ti ti-clock text-warning" title="Aguardando confirmação"></i>' : '<i class="ti ti-checks text-success" title="Enviada"></i>'));

      return '<tr class="' + (nova ? 'wae-hist-nova' : '') + '" data-id="' + m.id + '">' +
         '<td class="text-nowrap small">' + e(m.data) + '</td>' +
         (historicoVariasConexoes ? '<td><span class="badge bg-secondary-lt text-nowrap"><i class="ti ti-device-mobile me-1"></i>' + e(m.conexao || '') + '</span></td>' : '') +
         '<td>' + (entrada
            ? '<span class="badge bg-green-lt" title="Recebida"><i class="ti ti-arrow-down-left"></i></span>'
            : '<span class="badge bg-blue-lt" title="Enviada"><i class="ti ti-arrow-up-right"></i></span>') + '</td>' +
         '<td><div class="font-monospace small text-nowrap">' + e(formatarNumero(m.de)) + '</div><div class="small text-secondary">' + e(m.de_nome || '') + '</div></td>' +
         '<td><div class="font-monospace small text-nowrap">' + e(formatarNumero(m.para)) + '</div><div class="small text-secondary">' + e(m.para_nome || '') + '</div></td>' +
         '<td><span class="badge ' + (CORES_TIPO[m.tipo] || 'bg-secondary-lt') + '">' + e(m.tipo_nome) + '</span>' +
            (m.detalhe ? '<div class="small text-secondary">' + e(m.detalhe) + '</div>' : '') + '</td>' +
         '<td>' + (m.tickets_id ? '<a href="' + e(m.ticket_url) + '" target="_blank">#' + m.tickets_id + '</a>' : '<span class="text-secondary">-</span>') + '</td>' +
         '<td class="wae-hist-texto">' +
            (m.citada ? '<div class="wae-hist-citada" title="Resposta a esta mensagem"><i class="ti ti-corner-up-left me-1"></i>' + e(m.citada) + '</div>' : '') +
            midia + e(m.texto) + (m.reacoes ? ' <span class="wae-hist-reacoes">' + e(m.reacoes) + '</span>' : '') +
         '</td>' +
         '<td class="text-center">' + envio + '</td>' +
      '</tr>';
   }

   function iniciarMensagens(id) {
      var caixa = document.getElementById(id);
      if (!caixa || caixa.getAttribute('data-iniciado')) { return; }
      caixa.setAttribute('data-iniciado', '1');
      historicoVariasConexoes = caixa.getAttribute('data-varias-conexoes') === '1';

      var corpo = document.getElementById('wae-hist-linhas');
      var maiorId = 0;
      var menorId = 0;
      var total = 0;
      var pausado = false;
      var buscando = false;
      var timer = null;

      function filtros(extra) {
         return Object.assign({
            busca: document.getElementById('wae-hist-busca').value.trim(),
            direcao: document.getElementById('wae-hist-direcao').value,
            tipo: document.getElementById('wae-hist-tipo').value,
            com_chamado: document.getElementById('wae-hist-chamado').checked ? 1 : 0,
            filtro_conexao: document.getElementById('wae-hist-conexao') ? document.getElementById('wae-hist-conexao').value : 0
         }, extra || {});
      }

      function contar() {
         document.getElementById('wae-hist-contagem').textContent = total + ' mensagem(ns) na tela';
      }

      function recarregar() {
         buscando = true;
         pedir('mensagens_ao_vivo', filtros({ limite: 100 })).then(function (r) {
            buscando = false;
            var itens = (r && r.itens) || [];
            maiorId = r.maior_lido || (itens.length ? itens[0].id : 0);
            menorId = r.ultimo_lido || (itens.length ? itens[itens.length - 1].id : 0);
            total = itens.length;
            corpo.innerHTML = itens.length
               ? itens.map(function (m) { return linhaHistorico(m, false); }).join('')
               : '<tr><td colspan="' + (historicoVariasConexoes ? 9 : 8) + '" class="text-center text-secondary py-4">Nenhuma mensagem encontrada.</td></tr>';
            document.getElementById('wae-hist-mais').disabled = itens.length < 100;
            contar();
         });
      }

      /** Busca so o que chegou depois da ultima mensagem mostrada */
      function novidades() {
         if (pausado || buscando || document.hidden || !document.body.contains(caixa)) { return; }
         if (!maiorId) { recarregar(); return; }
         buscando = true;
         pedir('mensagens_ao_vivo', filtros({ depois: maiorId, limite: 200 })).then(function (r) {
            buscando = false;
            var itens = (r && r.itens) || [];
            if (r && r.maior_lido) { maiorId = Math.max(maiorId, r.maior_lido); }
            if (!itens.length) { return; }
            if (!corpo.querySelector('tr[data-id]')) { corpo.innerHTML = ''; }
            maiorId = Math.max(maiorId, itens[0].id);
            corpo.insertAdjacentHTML('afterbegin', itens.map(function (m) { return linhaHistorico(m, true); }).join(''));
            total += itens.length;
            contar();
            setTimeout(function () {
               corpo.querySelectorAll('.wae-hist-nova').forEach(function (tr) { tr.classList.remove('wae-hist-nova'); });
            }, 2500);
         });
      }

      function maisAntigas() {
         if (!menorId) { return; }
         var botao = document.getElementById('wae-hist-mais');
         botao.disabled = true;
         pedir('mensagens_ao_vivo', filtros({ antes: menorId, limite: 100 })).then(function (r) {
            var itens = (r && r.itens) || [];
            menorId = r.ultimo_lido || menorId;
            corpo.insertAdjacentHTML('beforeend', itens.map(function (m) { return linhaHistorico(m, false); }).join(''));
            total += itens.length;
            botao.disabled = itens.length === 0;
            contar();
         });
      }

      function definirPausa(valor) {
         pausado = valor;
         var status = document.getElementById('wae-hist-status');
         status.classList.toggle('wae-pausado', pausado);
         status.lastChild.textContent = pausado ? 'Pausado' : 'Ao vivo';
         document.querySelector('#wae-hist-pausar i').className = pausado ? 'ti ti-player-play' : 'ti ti-player-pause';
         document.getElementById('wae-hist-pausar').title = pausado ? 'Retomar a atualização automática' : 'Pausar a atualização automática';
         if (!pausado) { novidades(); }
      }

      var espera = null;
      document.getElementById('wae-hist-busca').addEventListener('input', function () {
         clearTimeout(espera);
         espera = setTimeout(recarregar, 350);
      });
      ['wae-hist-conexao', 'wae-hist-direcao', 'wae-hist-tipo', 'wae-hist-chamado'].forEach(function (campo) {
         var alvo = document.getElementById(campo);
         if (alvo) { alvo.addEventListener('change', recarregar); }
      });
      document.getElementById('wae-hist-pausar').addEventListener('click', function () { definirPausa(!pausado); });
      document.getElementById('wae-hist-mais').addEventListener('click', maisAntigas);

      recarregar();
      timer = setInterval(function () {
         if (!document.body.contains(caixa)) { clearInterval(timer); return; }
         novidades();
      }, 3000);
   }

   window.WAEPainel = {
      iniciarServidor: iniciarServidor,
      definirEdicao: definirEdicao,
      iniciarMensagens: iniciarMensagens
   };
})();
