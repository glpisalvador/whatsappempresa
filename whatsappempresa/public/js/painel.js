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
   // Aba Servidor
   // ============================================

   var ESTADOS = {
      ok:        { classe: 'bg-green-lt',  icone: 'ti ti-circle-check',    texto: 'Pronto' },
      pendente:  { classe: 'bg-yellow-lt', icone: 'ti ti-alert-circle',    texto: 'Pendente' },
      erro:      { classe: 'bg-red-lt',    icone: 'ti ti-alert-triangle',  texto: 'Atencao' },
      andamento: { classe: 'bg-blue-lt',   icone: 'ti ti-loader-2',        texto: 'Em andamento' }
   };

   var ACOES = {
      node_instalar:         { rotulo: 'Instalar',      icone: 'ti ti-download',     classe: 'btn-primary' },
      node_atualizar:        { rotulo: 'Atualizar',     icone: 'ti ti-refresh',      classe: 'btn-outline-secondary' },
      dependencias_instalar: { rotulo: 'Instalar',      icone: 'ti ti-package',      classe: 'btn-primary' },
      vigia_ativar:          { rotulo: 'Ativar',        icone: 'ti ti-eye-check',    classe: 'btn-primary' },
      vigia_desativar:       { rotulo: 'Desativar',     icone: 'ti ti-eye-off',      classe: 'btn-outline-danger' },
      webhook_testar:        { rotulo: 'Testar',        icone: 'ti ti-arrows-exchange', classe: 'btn-outline-secondary' },
      servico_iniciar:       { rotulo: 'Iniciar',       icone: 'ti ti-player-play',  classe: 'btn-success' },
      servico_reiniciar:     { rotulo: 'Reiniciar',     icone: 'ti ti-refresh',      classe: 'btn-outline-secondary' },
      servico_parar:         { rotulo: 'Parar',         icone: 'ti ti-player-stop',  classe: 'btn-outline-danger' },
      aparelho_desvincular:  { rotulo: 'Desvincular',   icone: 'ti ti-unlink',       classe: 'btn-outline-danger' }
   };

   var CONFIRMACOES = {
      node_atualizar: 'Baixar e instalar a versao LTS mais recente do Node.js? O servico sera reiniciado em seguida, se estiver ligado.',
      dependencias_instalar: 'Instalar ou atualizar as dependencias do servidor? Isso pode levar alguns minutos.',
      servico_parar: 'Parar o servidor WhatsApp? As mensagens deixam de ser recebidas e enviadas ate que ele seja iniciado de novo.',
      aparelho_desvincular: 'Desvincular o aparelho? Sera preciso ler um novo QR Code para voltar a atender.',
      vigia_desativar: 'Desativar o vigia? Se o servidor cair, ele nao sera religado automaticamente.'
   };

   var TAREFAS = { node_instalar: 'node', node_atualizar: 'node', dependencias_instalar: 'dependencias' };

   var painel = null;
   var ocupado = false;
   var etapasAtuais = [];
   var timerQr = null;
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

   function desenharEtapas(etapas) {
      etapasAtuais = etapas;
      var lista = el('#wae-etapas');
      if (!lista) { return; }

      var html = '';
      etapas.forEach(function (etapa, indice) {
         var estado = ESTADOS[etapa.estado] || ESTADOS.pendente;
         var botoes = '';
         (etapa.acoes || []).forEach(function (acao) {
            var def = ACOES[acao];
            if (!def) { return; }
            var rotulo = def.rotulo;
            if (acao === 'dependencias_instalar' && etapa.estado === 'ok') {
               rotulo = 'Reinstalar';
            }
            var classe = (acao === 'dependencias_instalar' && etapa.estado === 'ok') ? 'btn-outline-secondary' : def.classe;
            botoes += '<button type="button" class="btn btn-sm ' + classe + '" data-wae-acao="' + acao + '"'
               + (WAE.podeEditar ? '' : ' disabled') + '><i class="' + def.icone + ' me-1"></i>' + rotulo + '</button>';
         });

         html += '<div class="list-group-item">'
            + '<div class="row align-items-center g-2">'
            + '<div class="col-auto"><span class="avatar avatar-sm ' + estado.classe + '"><i class="' + estado.icone + '"></i></span></div>'
            + '<div class="col">'
            + '<div class="fw-semibold">' + (indice + 1) + '. ' + escapar(etapa.rotulo)
            + ' <span class="badge ' + estado.classe + ' ms-1">' + estado.texto + '</span></div>'
            + '<div class="text-secondary small text-break">' + escapar(etapa.detalhe) + '</div>'
            + '</div>'
            + '<div class="col-auto d-flex flex-wrap gap-1">' + botoes + '</div>'
            + '</div></div>';
      });
      lista.innerHTML = html;

      var pronto = etapas.every(function (e) { return e.estado === 'ok' || e.chave === 'webhook'; });
      var badge = el('#wae-badge-geral');
      if (badge) {
         badge.className = 'badge ' + (pronto ? 'bg-green-lt' : 'bg-yellow-lt');
         badge.textContent = pronto ? 'Tudo pronto' : 'Preparacao pendente';
      }

      etapas.forEach(function (etapa) {
         if (etapa.estado === 'andamento' && etapa.tarefa) {
            acompanharTarefa(etapa.tarefa);
         }
      });
   }

   function atualizarEtapas(testarWebhook) {
      return pedir('etapas', { webhook: testarWebhook ? 1 : 0 }).then(function (r) {
         if (r.sucesso) {
            desenharEtapas(r.etapas || []);
            atualizarQr();
         } else {
            avisar(r.mensagem, true);
         }
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
      badge.textContent = estado === 'ok' ? 'Concluida' : (estado === 'erro' ? 'Falhou' : 'Em andamento');
   }

   var acompanhando = {};

   /** Acompanha a tarefa em segundo plano ate terminar. Resolve true se deu certo. */
   function acompanharTarefa(nome) {
      if (acompanhando[nome]) { return acompanhando[nome]; }
      var titulo = nome === 'node' ? 'Instalacao do Node.js' : 'Instalacao das dependencias';

      acompanhando[nome] = new Promise(function (resolver) {
         function consultar() {
            pedir('tarefa', { tarefa: nome }).then(function (r) {
               if (!r.sucesso) {
                  delete acompanhando[nome];
                  resolver(false);
                  return;
               }
               var t = r.tarefa;
               mostrarTarefa(titulo, t.log, t.rodando ? 'andamento' : (t.sucesso ? 'ok' : 'erro'));
               if (t.rodando) {
                  setTimeout(consultar, 2000);
                  return;
               }
               delete acompanhando[nome];
               avisar(t.sucesso ? titulo + ' concluida.' : titulo + ' falhou. Veja o registro abaixo.', !t.sucesso);
               resolver(!!t.sucesso);
            });
         }
         consultar();
      });
      return acompanhando[nome];
   }

   function executarAcao(acao, semConfirmar) {
      var pergunta = (!semConfirmar && CONFIRMACOES[acao]) ? confirmar(CONFIRMACOES[acao]) : Promise.resolve(true);

      return pergunta.then(function (ok) {
         if (!ok) { return false; }
         travar(true);
         return pedir('acao_servidor', { tipo: acao }, 'POST').then(function (r) {
            if (!r.sucesso) {
               avisar(r.mensagem, true);
               if (r.log) { mostrarTarefa('Log do servidor', r.log, 'erro'); }
               return false;
            }
            if (TAREFAS[acao]) {
               return acompanharTarefa(TAREFAS[acao]);
            }
            avisar(r.mensagem, false);
            return true;
         }).then(function (resultado) {
            travar(false);
            return atualizarEtapas(acao === 'webhook_testar').then(function () { return resultado; });
         });
      });
   }

   function etapa(chave) {
      for (var i = 0; i < etapasAtuais.length; i++) {
         if (etapasAtuais[i].chave === chave) { return etapasAtuais[i]; }
      }
      return null;
   }

   /** Executa em sequencia tudo que falta para o servidor ficar pronto */
   function prepararTudo() {
      var passos = [
         { chave: 'node',         acao: 'node_instalar' },
         { chave: 'dependencias', acao: 'dependencias_instalar' },
         { chave: 'vigia',        acao: 'vigia_ativar' },
         { chave: 'servico',      acao: 'servico_iniciar' }
      ];

      travar(true);
      var cadeia = atualizarEtapas(false);

      passos.forEach(function (passo) {
         cadeia = cadeia.then(function (continuar) {
            if (continuar === false) { return false; }
            var atual = etapa(passo.chave);
            if (atual && atual.estado === 'ok') { return true; }
            return executarAcao(passo.acao, true);
         });
      });

      return cadeia.then(function (resultado) {
         travar(false);
         return atualizarEtapas(true).then(function () {
            avisar(resultado === false
               ? 'A preparacao parou em uma etapa. Veja o detalhe na lista.'
               : 'Servidor preparado. Leia o QR Code para parear o aparelho.', resultado === false);
         });
      });
   }

   function atualizarQr() {
      var caixa = el('#wae-qr');
      if (!caixa) { return; }
      var servico = etapa('servico');
      var aparelho = etapa('aparelho');

      if (aparelho && aparelho.estado === 'ok') {
         caixa.innerHTML = '<div class="empty"><div class="empty-icon"><i class="ti ti-device-mobile-check text-green"></i></div>'
            + '<p class="empty-title">Aparelho conectado</p><p class="empty-subtitle text-secondary">' + escapar(aparelho.detalhe) + '</p></div>';
         return;
      }
      if (!servico || servico.estado !== 'ok') {
         caixa.innerHTML = '<div class="empty"><div class="empty-icon"><i class="ti ti-qrcode-off"></i></div>'
            + '<p class="empty-title">Servico parado</p><p class="empty-subtitle text-secondary">O QR Code aparece quando o servico estiver em execucao.</p></div>';
         return;
      }
      pedir('qrcode').then(function (r) {
         if (r.sucesso && r.qr) {
            caixa.innerHTML = '<div class="text-center"><img src="' + r.qr + '" alt="QR Code" class="img-fluid" style="max-width:260px">'
               + '<p class="text-secondary small mt-2 mb-0">WhatsApp do aparelho: Configuracoes &gt; Aparelhos conectados &gt; Conectar um aparelho.</p></div>';
         } else {
            caixa.innerHTML = '<div class="empty"><div class="empty-icon"><i class="ti ti-loader-2"></i></div>'
               + '<p class="empty-title">Gerando QR Code</p><p class="empty-subtitle text-secondary">Aguarde alguns segundos.</p></div>';
         }
      });
   }

   function desenharDados(seletor, dados) {
      var caixa = el(seletor);
      if (!caixa) { return; }
      var html = '';
      Object.keys(dados || {}).forEach(function (chave) {
         html += '<div class="datagrid-item"><div class="datagrid-title">' + escapar(chave) + '</div>'
            + '<div class="datagrid-content text-break">' + escapar(dados[chave]) + '</div></div>';
      });
      caixa.innerHTML = html;
   }

   function atualizarInfo() {
      pedir('painel_info').then(function (r) {
         if (!r.sucesso) { return; }
         desenharDados('#wae-info-tempos', r.tempos);
         desenharDados('#wae-info-rede', r.rede);
      });
   }

   function atualizarLog() {
      pedir('log_servidor').then(function (r) {
         var log = el('#wae-log');
         if (log) {
            log.textContent = (r.sucesso && r.conteudo) ? r.conteudo : 'Sem registros ainda.';
            log.scrollTop = log.scrollHeight;
         }
      });
   }

   function ligarTestes() {
      var enviar = el('#wae-enviar-teste');
      if (enviar) {
         enviar.addEventListener('click', function () {
            enviar.disabled = true;
            pedir('enviar_teste', {
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
            pedir('fluxo_situacao', { telefone: el('#wae-liberar-telefone').value }).then(function (r) {
               var saida = el('#wae-resultado-fluxo');
               if (!r.sucesso) { avisar(r.mensagem, true); return; }
               saida.hidden = false;
               saida.innerHTML = '<div class="datagrid">'
                  + '<div class="datagrid-item"><div class="datagrid-title">Fluxo atual</div><div class="datagrid-content">' + escapar(r.fluxo) + '</div></div>'
                  + '<div class="datagrid-item"><div class="datagrid-title">Etapa</div><div class="datagrid-content">' + escapar(r.etapa) + '</div></div>'
                  + '<div class="datagrid-item"><div class="datagrid-title">Conversa aberta</div><div class="datagrid-content">' + (r.aberta ? '#' + r.aberta : 'nenhuma') + '</div></div>'
                  + '<div class="datagrid-item"><div class="datagrid-title">Chamado</div><div class="datagrid-content">' + (r.tickets_id ? '#' + r.tickets_id : '-') + '</div></div>'
                  + '</div>';
            });
         });
      }

      var liberar = el('#wae-liberar-numero');
      if (liberar) {
         liberar.addEventListener('click', function () {
            confirmar('Liberar este numero? A conversa aberta sera encerrada e ele volta ao atendimento automatico.').then(function (ok) {
               if (!ok) { return; }
               pedir('fluxo_liberar', { telefone: el('#wae-liberar-telefone').value }, 'POST').then(function (r) {
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

      painel.addEventListener('click', function (evento) {
         var botao = evento.target.closest('[data-wae-acao]');
         if (botao && !ocupado) {
            executarAcao(botao.getAttribute('data-wae-acao'), false);
         }
      });

      var preparar = el('#wae-preparar');
      if (preparar) {
         preparar.disabled = !WAE.podeEditar;
         preparar.addEventListener('click', function () {
            if (!ocupado) { prepararTudo(); }
         });
      }

      var atualizar = el('#wae-atualizar-log');
      if (atualizar) { atualizar.addEventListener('click', atualizarLog); }

      ligarTestes();
      atualizarEtapas(false);
      atualizarInfo();
      atualizarLog();

      // Enquanto a aba estiver na tela: QR e situacao se atualizam sozinhos
      clearInterval(timerQr);
      clearInterval(timerEtapas);
      timerQr = setInterval(function () {
         if (!document.body.contains(painel)) { clearInterval(timerQr); return; }
         if (!ocupado) { atualizarQr(); }
      }, 8000);
      timerEtapas = setInterval(function () {
         if (!document.body.contains(painel)) { clearInterval(timerEtapas); return; }
         if (!ocupado) { atualizarEtapas(false); atualizarInfo(); }
      }, 30000);
   }

   /** Abas com formulario: marca se o usuario pode editar (usado pelos fluxos) */
   function definirEdicao(pode) {
      WAE.podeEditar = !!pode;
   }

   window.WAEPainel = {
      iniciarServidor: iniciarServidor,
      definirEdicao: definirEdicao
   };
})();
