/**
 * whatsappempresa - construtor visual de fluxos (arrastar e soltar).
 *
 * A tela usa o Drawflow (MIT) para a area de desenho. Cada bloco guarda seus dados
 * { id, tipo, rotulo, config } e cada saida do bloco tem um nome ("proximo", "sim", "op2"...).
 * Toda alteracao e gravada sozinha em menos de um segundo; o servidor le o fluxo do banco
 * a cada mensagem recebida, entao a mudanca vale na hora.
 */
(function () {
   'use strict';

   var raiz, editor, catalogo, tipos;
   var podeEditar = false;
   var fluxos = [];
   var atual = null;           // dados do fluxo aberto (sem os blocos)
   var selecionado = null;     // id do bloco no Drawflow
   var carregando = false;     // evita salvar enquanto o fluxo e montado na tela
   var temporizador = null;
   var salvando = false;
   var pendente = false;
   var avisos = [];
   var teste = [];
   var raizUrl = (typeof CFG_GLPI !== 'undefined' && CFG_GLPI.root_doc) ? CFG_GLPI.root_doc : '';
   var URL_AJAX = raizUrl + '/plugins/whatsappempresa/front/ajax.php';
   var CHAVE_ULTIMO = 'wae-construtor-ultimo';
   var CHAVE_CONEXAO = 'wae-construtor-conexao';
   var conexao = 0;            // numero (conexao de WhatsApp) cujos fluxos estao na tela

   var CORES = {
      inicio: 'wae-g-inicio', interacao: 'wae-g-interacao', logica: 'wae-g-logica',
      glpi: 'wae-g-glpi', integracao: 'wae-g-integracao', fim: 'wae-g-fim'
   };

   function e(texto) { return WAE.escapar(texto); }
   function $id(id) { return document.getElementById(id); }

   function novoId() {
      return 'n' + Math.random().toString(36).slice(2, 10);
   }

   function resumir(texto, limite) {
      texto = String(texto || '').replace(/\s+/g, ' ').trim();
      return texto.length > limite ? texto.slice(0, limite - 1) + '…' : texto;
   }

   function nomeNaLista(fonte, id) {
      var lista = catalogo[fonte] || [];
      for (var i = 0; i < lista.length; i++) {
         if (String(lista[i].id) === String(id)) { return lista[i].nome; }
      }
      return '';
   }

   // ============================================
   // Saidas dos blocos (espelha o servidor)
   // ============================================

   function saidasDe(dados) {
      var tipo = tipos[dados.tipo] || {};
      var config = dados.config || {};
      var lista = [];

      if (tipo.dinamico === 'menu') {
         (config.opcoes || []).forEach(function (op, i) { lista.push('op' + (i + 1)); });
         lista.push('invalida');
         return lista;
      }
      if (tipo.dinamico === 'randomizador') {
         (config.caminhos || []).forEach(function (c, i) { lista.push('r' + (i + 1)); });
         return lista;
      }
      return (tipo.saidas || []).slice();
   }

   function rotuloSaida(dados, saida) {
      var config = dados.config || {};
      var m = /^op(\d+)$/.exec(saida);
      if (m) { return (config.opcoes || [])[m[1] - 1] ? config.opcoes[m[1] - 1].rotulo : saida; }
      m = /^r(\d+)$/.exec(saida);
      if (m) { return (config.caminhos || [])[m[1] - 1] ? (parseInt(config.caminhos[m[1] - 1].percentual, 10) || 0) + '%' : saida; }
      return (catalogo.rotulos_saida || {})[saida] || saida;
   }

   // ============================================
   // Aparencia do bloco na tela
   // ============================================

   function resumoDe(dados) {
      var c = dados.config || {};
      var v = function (nome) { return nome ? '{' + nome + '}' : '(sem variável)'; };

      switch (dados.tipo) {
         case 'inicio':
            var gatilho = (catalogo.gatilhos || {})[atual ? atual.gatilho : 'menu'] || '';
            return resumir(gatilho + (atual && atual.gatilho === 'palavra' && atual.palavras ? ': ' + atual.palavras : ''), 90);
         case 'mensagem':
            var anexo = c.midia_arquivo ? (c.midia_tipo === 'audio' ? '🎤 áudio ' : '📷 imagem ') : '';
            return anexo + resumir(c.texto, 90 - anexo.length) || 'Clique para escrever a mensagem';
         case 'pergunta':
            return resumir(c.texto, 70) + ' → ' + v(c.variavel);
         case 'sim_nao':
         case 'menu':
         case 'listar_objetos':
         case 'listar_validacoes':
            return resumir(c.texto, 90) || 'Clique para configurar';
         case 'variavel':
            return v(c.variavel) + ' = ' + resumir(c.valor, 60);
         case 'intervalo':
            return 'Aguarda ' + (parseInt(c.segundos, 10) || 3) + ' segundo(s)';
         case 'condicao':
            var op = ((tipos.condicao.campos || []).filter(function (f) { return f.nome === 'operador'; })[0] || {}).opcoes || {};
            return v(c.variavel) + ' ' + (op[c.operador || 'igual'] || '') + (['vazio', 'preenchido'].indexOf(c.operador) >= 0 ? '' : ' "' + resumir(c.valor, 30) + '"');
         case 'horario':
            if (parseInt(c.calendario, 10) > 0) { return 'Calendário: ' + nomeNaLista('calendarios', c.calendario); }
            return (c.dias || '1,2,3,4,5').split(',').map(function (d) { return ['', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb', 'dom'][d] || ''; }).join(' ') +
               ' · ' + (c.hora_inicio || '08:00') + '–' + (c.hora_fim || '18:00');
         case 'detalhe_objeto':
            return 'Registro em ' + v(c.variavel);
         case 'abrir_objeto':
            return 'Abre ' + (nomeNaLista('objetos', c.objeto || 'Ticket') || 'Chamado').toLowerCase() + ' com título ' + v(c.variavel_titulo);
         case 'acompanhamento':
            return 'Acompanhamento em ' + v(c.variavel);
         case 'responder_validacao':
            return 'Validação em ' + v(c.variavel);
         case 'http':
            return (c.metodo || 'GET') + ' ' + resumir(c.url || '(sem URL)', 60);
         case 'conversa_tecnico':
            return 'Chamado em ' + v(c.variavel);
         case 'trocar_fluxo':
            return '→ ' + (nomeNaLista('fluxos', c.fluxo) || 'escolha o fluxo');
         case 'encerrar':
            return resumir(c.texto, 90) || 'Encerra o atendimento';
      }
      return '';
   }

   function htmlBloco(dados) {
      var tipo = tipos[dados.tipo] || { rotulo: dados.tipo, icone: 'ti ti-square', grupo: 'interacao' };
      var saidas = saidasDe(dados);

      return '<div class="wae-no ' + (CORES[tipo.grupo] || '') + '">' +
         '<div class="wae-no-topo"><i class="' + e(tipo.icone) + '"></i>' +
            '<span class="wae-no-titulo">' + e(dados.rotulo || tipo.rotulo) + '</span>' +
            '<i class="ti ti-alert-triangle wae-no-alerta"></i></div>' +
         '<div class="wae-no-resumo">' + e(resumoDe(dados)) + '</div>' +
         (saidas.length ? '<div class="wae-no-saidas">' + saidas.map(function (s) {
            return '<div class="wae-no-saida wae-s-' + e(s.replace(/\d+$/, '')) + '">' + e(rotuloSaida(dados, s)) + '</div>';
         }).join('') + '</div>' : '') +
      '</div>';
   }

   function atualizarBloco(dfId) {
      var no = editor.getNodeFromId(dfId);
      if (!no) { return; }
      var html = htmlBloco(no.data);
      var conteudo = document.querySelector('#node-' + dfId + ' .drawflow_content_node');
      if (conteudo) { conteudo.innerHTML = html; }
      editor.drawflow.drawflow[editor.module].data[dfId].html = html;
      editor.updateConnectionNodes('node-' + dfId);
      marcarAvisos();
   }

   // ============================================
   // Conversao entre a tela e o modelo gravado
   // ============================================

   function dadosDoEditor() {
      return editor.export().drawflow[editor.module].data;
   }

   function paraModelo() {
      var dados = dadosDoEditor();
      var nos = [];
      var arestas = [];

      Object.keys(dados).forEach(function (dfId) {
         var no = dados[dfId];
         var info = no.data;
         nos.push({ id: info.id, tipo: info.tipo, rotulo: info.rotulo || '', config: info.config || {}, x: Math.round(no.pos_x), y: Math.round(no.pos_y) });

         var saidas = saidasDe(info);
         Object.keys(no.outputs || {}).forEach(function (chave) {
            var indice = parseInt(chave.replace('output_', ''), 10) - 1;
            var saida = saidas[indice];
            if (!saida) { return; }
            (no.outputs[chave].connections || []).forEach(function (con) {
               var destino = dados[con.node];
               if (destino) { arestas.push({ de: info.id, saida: saida, para: destino.data.id }); }
            });
         });
      });

      return { nos: nos, arestas: arestas };
   }

   function montarNaTela(fluxo) {
      carregando = true;
      editor.clear();
      selecionado = null;

      var mapa = {};
      (fluxo.nos || []).forEach(function (no) {
         var dados = { id: no.id, tipo: no.tipo, rotulo: no.rotulo, config: no.config || {} };
         var entradas = no.tipo === 'inicio' ? 0 : 1;
         mapa[no.id] = editor.addNode(no.tipo, entradas, saidasDe(dados).length, no.x || 0, no.y || 0, 'wae-df-no', dados, htmlBloco(dados));
      });

      (fluxo.arestas || []).forEach(function (a) {
         var de = mapa[a.de];
         var para = mapa[a.para];
         if (!de || !para) { return; }
         var saidas = saidasDe(editor.getNodeFromId(de).data);
         var indice = saidas.indexOf(a.saida);
         if (indice >= 0) {
            editor.addConnection(de, para, 'output_' + (indice + 1), 'input_1');
         }
      });

      carregando = false;
      mostrarPropriedades();
      setTimeout(enquadrar, 30);
   }

   /**
    * Troca as saidas de um bloco (menu e randomizador) mantendo as ligacoes.
    * mapa: saida antiga -> saida nova (ausente = ligacao descartada)
    */
   function reestruturarSaidas(dfId, dadosAntigos, dadosNovos, mapa) {
      var no = editor.getNodeFromId(dfId);
      var antigas = saidasDe(dadosAntigos);
      var novas = saidasDe(dadosNovos);
      var ligacoes = {};

      Object.keys(no.outputs || {}).forEach(function (chave) {
         var saida = antigas[parseInt(chave.replace('output_', ''), 10) - 1];
         var destino = mapa.hasOwnProperty(saida) ? mapa[saida] : saida;
         if (destino) {
            ligacoes[destino] = (no.outputs[chave].connections || []).map(function (c) { return c.node; });
         }
      });

      carregando = true;
      for (var k = Object.keys(no.outputs || {}).length; k >= 1; k--) {
         editor.removeNodeOutput(dfId, 'output_' + k);
      }
      editor.updateNodeDataFromId(dfId, dadosNovos);
      novas.forEach(function () { editor.addNodeOutput(dfId); });
      novas.forEach(function (saida, i) {
         (ligacoes[saida] || []).forEach(function (para) {
            editor.addConnection(dfId, para, 'output_' + (i + 1), 'input_1');
         });
      });
      carregando = false;

      atualizarBloco(dfId);
      agendarSalvar();
   }

   // ============================================
   // Salvamento automatico
   // ============================================

   function status(texto, classe) {
      var alvo = $id('wae-cons-status');
      if (!alvo) { return; }
      alvo.className = 'wae-cons-status small ' + (classe || 'text-secondary');
      alvo.innerHTML = texto;
   }

   function agendarSalvar() {
      if (carregando || !atual || !podeEditar) { return; }
      status('<i class="ti ti-pencil me-1"></i>Alterações pendentes...', 'text-secondary');
      clearTimeout(temporizador);
      temporizador = setTimeout(salvarAgora, 700);
   }

   function salvarAgora() {
      clearTimeout(temporizador);
      if (!atual || !podeEditar) { return Promise.resolve(); }
      if (salvando) { pendente = true; return Promise.resolve(); }

      salvando = true;
      status('<i class="ti ti-loader-2 me-1"></i>Salvando...', 'text-secondary');

      var modelo = paraModelo();
      var dados = {
         id: atual.id, nome: atual.nome, descricao: atual.descricao, gatilho: atual.gatilho,
         palavras: atual.palavras, is_ativo: atual.is_ativo, nos: modelo.nos, arestas: modelo.arestas
      };

      return WAE.pedir('fluxo_salvar', { dados: JSON.stringify(dados) }, 'POST').then(function (r) {
         salvando = false;
         if (!r.sucesso) {
            status('<i class="ti ti-alert-circle me-1"></i>' + e(r.mensagem || 'Falha ao salvar'), 'text-danger');
            WAE.avisar(r.mensagem || 'Nao foi possivel salvar o fluxo.', true);
            return;
         }
         avisos = r.avisos || [];
         mostrarAvisos();
         status('<i class="ti ti-circle-check me-1"></i>Salvo às ' + e(r.hora) +
            (atual.is_ativo ? ' · já em uso no WhatsApp' : ' · fluxo inativo'), atual.is_ativo ? 'text-success' : 'text-secondary');
         atualizarItemDaLista();
         if (pendente) { pendente = false; return salvarAgora(); }
      });
   }

   function mostrarAvisos() {
      var botao = $id('wae-cons-avisos');
      var lista = $id('wae-cons-lista-avisos');
      if (!botao || !lista) { return; }

      $id('wae-cons-avisos-total').textContent = avisos.length;
      botao.classList.toggle('wae-oculto', avisos.length === 0);
      lista.innerHTML = avisos.map(function (a, i) {
         return '<a href="#" class="dropdown-item wae-cons-aviso" data-aviso="' + i + '"><i class="ti ti-alert-triangle text-warning me-2"></i>' + e(a.texto) + '</a>';
      }).join('');
      marcarAvisos();
   }

   function marcarAvisos() {
      if (!editor) { return; }
      var porNo = {};
      avisos.forEach(function (a) { if (a.no) { (porNo[a.no] = porNo[a.no] || []).push(a.texto); } });

      var dados = dadosDoEditor();
      Object.keys(dados).forEach(function (dfId) {
         var el = $id('node-' + dfId);
         if (!el) { return; }
         var lista = porNo[dados[dfId].data.id];
         el.classList.toggle('wae-no-com-aviso', !!lista);
         el.title = lista ? lista.join('\n') : '';
      });
   }

   function atualizarItemDaLista() {
      fluxos.forEach(function (f) {
         if (f.id === atual.id) {
            f.nome = atual.nome;
            f.is_ativo = atual.is_ativo;
            f.avisos = avisos.length;
         }
      });
      desenharSeletor();
   }

   // ============================================
   // Lista de fluxos
   // ============================================

   function desenharSeletor() {
      var seletor = $id('wae-cons-fluxo');
      if (!seletor) { return; }
      seletor.innerHTML = fluxos.map(function (f) {
         return '<option value="' + f.id + '"' + (atual && atual.id === f.id ? ' selected' : '') + '>' +
            e(f.nome) + (f.is_ativo ? '' : ' (inativo)') + (f.avisos ? ' ⚠' + f.avisos : '') + '</option>';
      }).join('');
      seletor.disabled = fluxos.length === 0;

      $id('wae-cons-vazio').classList.toggle('wae-oculto', fluxos.length > 0);
      ['wae-cons-duplicar', 'wae-cons-excluir', 'wae-cons-testar'].forEach(function (id) {
         if ($id(id)) { $id(id).disabled = fluxos.length === 0; }
      });
      $id('wae-cons-ativo').disabled = !podeEditar || fluxos.length === 0;
   }

   function carregarLista(abrir) {
      return WAE.pedir('fluxos_listar', { conexao: conexao }).then(function (r) {
         fluxos = r.itens || [];
         desenharSeletor();

         var alvo = abrir;
         if (!alvo) {
            try { alvo = parseInt(localStorage.getItem(CHAVE_ULTIMO + '-' + conexao) || '0', 10); } catch (x) { alvo = 0; }
         }
         if (!fluxos.some(function (f) { return f.id === alvo; })) {
            alvo = fluxos.length ? fluxos[0].id : 0;
         }
         if (alvo) {
            return abrirFluxo(alvo);
         }
         atual = null;
         editor.clear();
         mostrarPropriedades();
      });
   }

   function abrirFluxo(id) {
      return salvarAgora().then(function () {
         return WAE.pedir('fluxo_obter', { id: id });
      }).then(function (r) {
         if (!r.sucesso) { WAE.avisar(r.mensagem || 'Nao foi possivel abrir o fluxo.', true); return; }
         var f = r.fluxo;
         atual = { id: f.id, nome: f.nome, descricao: f.descricao, gatilho: f.gatilho, palavras: f.palavras, is_ativo: f.is_ativo };
         try { localStorage.setItem(CHAVE_ULTIMO + '-' + conexao, String(f.id)); } catch (x) { /* navegador sem armazenamento */ }

         $id('wae-cons-ativo').checked = !!f.is_ativo;
         desenharSeletor();
         montarNaTela(f);
         avisos = r.avisos || [];
         mostrarAvisos();
         status('Última alteração: ' + e(f.date_mod || '-'));
         reiniciarTeste();
      });
   }

   // ============================================
   // Aparelhos conectados: cada um tem os seus fluxos
   // Visao geral (aparelhos e fluxos) e editor (blocos de um fluxo)
   // ============================================

   var aparelhos = [];
   var gatilhosNomes = {};
   var arrastandoFluxo = 0;

   function conexoes() { return (catalogo && catalogo.conexoes) || []; }

   function formatarNumero(numero) {
      var d = String(numero || '').replace(/\D/g, '');
      if (d.length === 13) { return '+' + d.slice(0, 2) + ' (' + d.slice(2, 4) + ') ' + d.slice(4, 9) + '-' + d.slice(9); }
      if (d.length === 12) { return '+' + d.slice(0, 2) + ' (' + d.slice(2, 4) + ') ' + d.slice(4, 8) + '-' + d.slice(8); }
      return d;
   }

   function nomeConexao(c) {
      return c.nome + (c.numero ? ' · ' + formatarNumero(c.numero) : '');
   }

   function conexaoPorId(id) {
      return conexoes().filter(function (c) { return c.id === id; })[0] || null;
   }

   /** Itens "mover para" e "copiar para" dos outros aparelhos */
   function itensOutrosAparelhos(origem) {
      var outros = conexoes().filter(function (c) { return c.id !== origem; });
      if (!outros.length) { return ''; }
      var item = function (acao, icone, c) {
         return '<a href="#" class="dropdown-item" data-' + acao + '="' + c.id + '"><i class="ti ' + icone + ' me-2"></i>' + e(nomeConexao(c)) + '</a>';
      };
      return '<div class="dropdown-divider"></div><h6 class="dropdown-header">Mover para o aparelho</h6>' +
         outros.map(function (c) { return item('mover', 'ti-arrow-move-right', c); }).join('') +
         '<div class="dropdown-divider"></div><h6 class="dropdown-header">Copiar para o aparelho</h6>' +
         outros.map(function (c) { return item('destino', 'ti-copy', c); }).join('');
   }

   /** Aparelho do editor e o menu de copiar/mover da barra */
   function desenharConexoes() {
      var c = conexaoPorId(conexao);
      var chip = $id('wae-cons-aparelho');
      if (chip) {
         chip.innerHTML = '<i class="ti ti-device-mobile me-1"></i>' + e(c ? nomeConexao(c) : 'Aparelho');
      }
      var menu = $id('wae-cons-copiar-menu');
      if (menu) {
         menu.innerHTML = '<a href="#" class="dropdown-item" data-destino="0"><i class="ti ti-copy me-2"></i>Duplicar neste aparelho</a>' +
            itensOutrosAparelhos(conexao);
      }
   }

   function escolherConexao(id) {
      var lista = conexoes();
      if (!lista.some(function (c) { return c.id === id; })) {
         var padrao = lista.filter(function (c) { return c.padrao; })[0] || lista[0];
         id = padrao ? padrao.id : 0;
      }
      conexao = id;
      try { localStorage.setItem(CHAVE_CONEXAO, String(id)); } catch (x) { /* sem armazenamento */ }
      desenharConexoes();
   }

   /** Nomes dos fluxos (bloco Trocar de fluxo) e aparelhos atualizados */
   function recarregarCatalogo() {
      return WAE.pedir('fluxo_catalogo').then(function (r) {
         if (!r.sucesso) { return; }
         catalogo.fluxos = r.catalogo.fluxos;
         catalogo.conexoes = r.catalogo.conexoes;
         desenharConexoes();
      });
   }

   // ---------- Visao geral ----------

   function htmlFluxoNaVisao(f, aparelho) {
      var gatilho = gatilhosNomes[f.gatilho] || f.gatilho;
      if (f.gatilho === 'palavra' && f.palavras) { gatilho += ': ' + resumir(f.palavras, 40); }

      var menu = podeEditar
         ? '<div class="dropdown">' +
              '<button type="button" class="btn btn-sm btn-ghost-secondary" data-bs-toggle="dropdown" title="Mais opções"><i class="ti ti-dots-vertical"></i></button>' +
              '<div class="dropdown-menu dropdown-menu-end">' +
                 '<a href="#" class="dropdown-item" data-abrir="1"><i class="ti ti-pencil me-2"></i>Abrir os blocos</a>' +
                 '<a href="#" class="dropdown-item" data-destino="0"><i class="ti ti-copy me-2"></i>Duplicar neste aparelho</a>' +
                 itensOutrosAparelhos(aparelho.id) +
                 '<div class="dropdown-divider"></div>' +
                 '<a href="#" class="dropdown-item text-danger" data-excluir="1"><i class="ti ti-trash me-2"></i>Excluir</a>' +
              '</div></div>'
         : '';

      return '<div class="wae-ap-fluxo' + (f.is_ativo ? '' : ' wae-ap-inativo') + '" data-fluxo="' + f.id + '" data-conexao="' + aparelho.id + '"' +
            (podeEditar ? ' draggable="true" title="Arraste para outro aparelho para mudar quem atende este fluxo"' : '') + '>' +
         (podeEditar ? '<i class="ti ti-grip-vertical wae-ap-alca"></i>' : '') +
         '<div class="wae-ap-fluxo-info" data-abrir="1">' +
            '<div class="wae-ap-fluxo-nome"><i class="ti ti-git-merge me-1"></i>' + e(f.nome) +
               (f.avisos ? ' <span class="text-warning small" title="Pontos de atenção"><i class="ti ti-alert-triangle"></i>' + f.avisos + '</span>' : '') + '</div>' +
            '<div class="wae-ap-fluxo-meta">' + e(gatilho) + ' · ' + f.blocos + ' bloco(s) · ' + e(f.date_mod || '') + '</div>' +
         '</div>' +
         '<label class="form-check form-switch m-0" title="' + (f.is_ativo ? 'Ativo: responde neste aparelho' : 'Inativo') + '">' +
            '<input class="form-check-input" type="checkbox" data-ativar="1"' + (f.is_ativo ? ' checked' : '') + (podeEditar ? '' : ' disabled') + '>' +
         '</label>' +
         menu +
      '</div>';
   }

   function desenharVisao() {
      var caixa = $id('wae-cons-aparelhos');
      if (!caixa) { return; }
      if (!aparelhos.length) {
         caixa.innerHTML = '<div class="col-12 text-secondary">Nenhum aparelho cadastrado. Conecte um número na aba Servidor.</div>';
         return;
      }

      var destaque = 0;
      try { destaque = parseInt(localStorage.getItem(CHAVE_CONEXAO) || '0', 10); } catch (x) { destaque = 0; }

      caixa.innerHTML = aparelhos.map(function (a) {
         var ativos = a.fluxos.filter(function (f) { return f.is_ativo; }).length;
         var situacao = a.conectado
            ? '<span class="badge bg-green-lt"><i class="ti ti-circle-check me-1"></i>Conectado</span>'
            : (a.ligado ? '<span class="badge bg-yellow-lt"><i class="ti ti-qrcode me-1"></i>Aguardando pareamento</span>'
                        : '<span class="badge bg-secondary-lt"><i class="ti ti-plug-off me-1"></i>Desligado</span>');

         return '<div class="col-lg-6 col-xxl-4">' +
            '<div class="card wae-ap-cartao' + (a.id === destaque && aparelhos.length > 1 ? ' wae-ap-destaque' : '') + '" data-aparelho="' + a.id + '">' +
               '<div class="card-header">' +
                  '<span class="wae-ap-icone ' + (a.conectado ? 'wae-ap-on' : '') + '"><i class="ti ti-device-mobile"></i></span>' +
                  '<div class="wae-ap-titulo">' +
                     '<div class="fw-bold">' + e(a.nome) + (a.padrao ? ' <span class="badge bg-yellow-lt ms-1" title="Aparelho padrão">Padrão</span>' : '') + '</div>' +
                     '<div class="small text-secondary">' + (a.numero ? e(formatarNumero(a.numero)) : 'Sem aparelho pareado') + '</div>' +
                  '</div>' +
                  '<div class="ms-auto text-end">' + situacao +
                     '<div class="small text-secondary mt-1">' + ativos + ' ativo(s) de ' + a.fluxos.length + '</div></div>' +
               '</div>' +
               '<div class="wae-ap-lista" data-soltar="' + a.id + '">' +
                  (a.fluxos.length
                     ? a.fluxos.map(function (f) { return htmlFluxoNaVisao(f, a); }).join('')
                     : '<div class="wae-ap-vazio"><i class="ti ti-git-merge"></i><div>Nenhum fluxo neste aparelho.</div>' +
                        (podeEditar ? '<div class="small">Crie um novo ou arraste um fluxo de outro aparelho para cá.</div>' : '') + '</div>') +
               '</div>' +
               (podeEditar ? '<div class="card-footer"><button type="button" class="btn btn-sm btn-outline-primary" data-novo-fluxo="' + a.id + '"><i class="ti ti-plus me-1"></i>Novo fluxo neste aparelho</button></div>' : '') +
            '</div>' +
         '</div>';
      }).join('');
   }

   function carregarVisao() {
      return WAE.pedir('fluxos_painel').then(function (r) {
         if (!r.sucesso) { WAE.avisar(r.mensagem || 'Nao foi possivel carregar os aparelhos.', true); return; }
         aparelhos = r.aparelhos || [];
         gatilhosNomes = r.gatilhos || {};
         desenharVisao();
      });
   }

   function mostrarVisao() {
      return salvarAgora().then(function () {
         raiz.classList.add('wae-cons-modo-visao');
         raiz.classList.remove('wae-cons-cheio');
         var botaoCheio = $id('wae-cons-tela-cheia');
         if (botaoCheio) { botaoCheio.querySelector('i').className = 'ti ti-maximize'; }
         $id('wae-cons-teste').classList.add('wae-oculto');
         $id('wae-cons-props').classList.remove('wae-oculto');
         atual = null;
         return carregarVisao();
      });
   }

   /** Entra no editor com os blocos do fluxo escolhido (a tela precisa estar visivel antes de montar) */
   function entrarNoFluxo(idConexao, idFluxo) {
      escolherConexao(idConexao);
      raiz.classList.remove('wae-cons-modo-visao');
      return carregarLista(idFluxo);
   }

   function moverFluxo(id, destino) {
      return WAE.pedir('fluxo_mover', { id: id, destino: destino }, 'POST').then(function (r) {
         WAE.avisar(r.mensagem || '', !r.sucesso);
         recarregarCatalogo();
         return r;
      });
   }

   function ligarVisao() {
      var caixa = $id('wae-cons-aparelhos');

      caixa.addEventListener('click', function (ev) {
         var linha = ev.target.closest('[data-fluxo]');
         var novo = ev.target.closest('[data-novo-fluxo]');

         if (novo) {
            var aparelho = parseInt(novo.getAttribute('data-novo-fluxo'), 10);
            novo.disabled = true;
            WAE.pedir('fluxo_criar', { nome: 'Novo fluxo', conexao: aparelho }, 'POST').then(function (r) {
               novo.disabled = false;
               WAE.avisar(r.mensagem || '', !r.sucesso);
               if (r.sucesso) { recarregarCatalogo().then(function () { entrarNoFluxo(aparelho, r.id); }); }
            });
            return;
         }
         if (!linha) { return; }

         var id = parseInt(linha.getAttribute('data-fluxo'), 10);
         var origem = parseInt(linha.getAttribute('data-conexao'), 10);
         var alvo;

         if (ev.target.closest('[data-ativar]')) { return; }

         if ((alvo = ev.target.closest('[data-mover]'))) {
            ev.preventDefault();
            moverFluxo(id, parseInt(alvo.getAttribute('data-mover'), 10)).then(carregarVisao);
            return;
         }
         if ((alvo = ev.target.closest('[data-destino]'))) {
            ev.preventDefault();
            WAE.pedir('fluxo_duplicar', { id: id, destino: parseInt(alvo.getAttribute('data-destino'), 10) || 0 }, 'POST').then(function (r) {
               WAE.avisar(r.mensagem || '', !r.sucesso);
               recarregarCatalogo();
               carregarVisao();
            });
            return;
         }
         if (ev.target.closest('[data-excluir]')) {
            ev.preventDefault();
            var nome = linha.querySelector('.wae-ap-fluxo-nome').textContent;
            WAE.confirmar('Excluir o fluxo "' + nome.trim() + '"? Quem estiver no meio dele volta para o menu.').then(function (ok) {
               if (!ok) { return; }
               WAE.pedir('fluxo_remover', { id: id }, 'POST').then(function (r) {
                  WAE.avisar(r.mensagem || '', !r.sucesso);
                  recarregarCatalogo();
                  carregarVisao();
               });
            });
            return;
         }
         if (ev.target.closest('[data-abrir]')) {
            ev.preventDefault();
            entrarNoFluxo(origem, id);
         }
      });

      caixa.addEventListener('change', function (ev) {
         var chave = ev.target.closest('[data-ativar]');
         if (!chave) { return; }
         var linha = chave.closest('[data-fluxo]');
         WAE.pedir('fluxo_ativar', { id: linha.getAttribute('data-fluxo'), ativo: chave.checked ? 1 : 0 }, 'POST').then(function (r) {
            WAE.avisar(r.mensagem || '', !r.sucesso);
            carregarVisao();
         });
      });

      // Arrastar um fluxo para outro aparelho muda quem o atende
      caixa.addEventListener('dragstart', function (ev) {
         var linha = ev.target.closest && ev.target.closest('[data-fluxo]');
         if (!linha) { return; }
         arrastandoFluxo = parseInt(linha.getAttribute('data-fluxo'), 10);
         linha.classList.add('wae-ap-arrastando');
         ev.dataTransfer.effectAllowed = 'move';
         try { ev.dataTransfer.setData('text/plain', String(arrastandoFluxo)); } catch (x) { /* navegador antigo */ }
      });
      caixa.addEventListener('dragend', function () {
         arrastandoFluxo = 0;
         caixa.querySelectorAll('.wae-ap-arrastando, .wae-ap-alvo').forEach(function (el) {
            el.classList.remove('wae-ap-arrastando', 'wae-ap-alvo');
         });
      });
      caixa.addEventListener('dragover', function (ev) {
         var zona = ev.target.closest('.wae-ap-cartao');
         if (!zona || !arrastandoFluxo) { return; }
         ev.preventDefault();
         ev.dataTransfer.dropEffect = 'move';
         caixa.querySelectorAll('.wae-ap-alvo').forEach(function (el) { if (el !== zona) { el.classList.remove('wae-ap-alvo'); } });
         zona.classList.add('wae-ap-alvo');
      });
      caixa.addEventListener('drop', function (ev) {
         var zona = ev.target.closest('.wae-ap-cartao');
         if (!zona || !arrastandoFluxo) { return; }
         ev.preventDefault();
         var destino = parseInt(zona.getAttribute('data-aparelho'), 10);
         var id = arrastandoFluxo;
         var linha = caixa.querySelector('[data-fluxo="' + id + '"]');
         arrastandoFluxo = 0;
         zona.classList.remove('wae-ap-alvo');
         if (!linha || parseInt(linha.getAttribute('data-conexao'), 10) === destino) { return; }
         moverFluxo(id, destino).then(carregarVisao);
      });

      $id('wae-cons-voltar').addEventListener('click', function () { mostrarVisao(); });
   }

   function ligarConexoes() {
      var salvo = 0;
      try { salvo = parseInt(localStorage.getItem(CHAVE_CONEXAO) || '0', 10); } catch (x) { salvo = 0; }
      escolherConexao(salvo);
      ligarVisao();
   }

   // ============================================
   // Paleta de blocos
   // ============================================

   function desenharPaleta() {
      var paleta = $id('wae-cons-paleta');
      var grupos = catalogo.grupos_blocos || {};
      var html = paleta.innerHTML;

      Object.keys(grupos).forEach(function (grupo) {
         var itens = Object.keys(tipos).filter(function (t) { return tipos[t].grupo === grupo; });
         if (!itens.length) { return; }
         html += '<div class="wae-cons-grupo">' + e(grupos[grupo]) + '</div>';
         itens.forEach(function (t) {
            html += '<div class="wae-cons-item ' + (CORES[grupo] || '') + '" draggable="' + (podeEditar ? 'true' : 'false') + '" data-tipo="' + e(t) + '" title="' + e(tipos[t].ajuda) + '">' +
               '<i class="' + e(tipos[t].icone) + '"></i><span>' + e(tipos[t].rotulo) + '</span></div>';
         });
      });

      paleta.innerHTML = html;

      paleta.addEventListener('dragstart', function (ev) {
         var item = ev.target.closest('[data-tipo]');
         if (!item || !podeEditar) { ev.preventDefault(); return; }
         ev.dataTransfer.setData('text/plain', item.getAttribute('data-tipo'));
         ev.dataTransfer.effectAllowed = 'copy';
      });

      // Clique tambem adiciona (no centro da area visivel)
      paleta.addEventListener('click', function (ev) {
         var item = ev.target.closest('[data-tipo]');
         if (!item || !podeEditar || !atual) { return; }
         var tela = $id('wae-cons-drawflow').getBoundingClientRect();
         adicionarBloco(item.getAttribute('data-tipo'), tela.left + tela.width / 2 - 110, tela.top + tela.height / 2 - 40);
      });
   }

   function configPadrao(tipo) {
      switch (tipo) {
         case 'menu': return { texto: 'Escolha uma opção:', opcoes: [{ rotulo: 'Opção 1' }, { rotulo: 'Opção 2' }] };
         case 'randomizador': return { caminhos: [{ percentual: 50 }, { percentual: 50 }] };
         case 'pergunta': return { texto: '', variavel: 'resposta', validacao: 'texto', minimo: '1' };
         case 'sim_nao': return { texto: '', variavel: 'confirmacao' };
         case 'condicao': return { operador: 'igual' };
         case 'intervalo': return { segundos: '3' };
         case 'horario': return { dias: '1,2,3,4,5', hora_inicio: '08:00', hora_fim: '18:00' };
         case 'listar_objetos': return { texto: '*Seus chamados*', objeto: 'Ticket', variavel: 'chamado', somente_abertos: '1' };
         case 'detalhe_objeto': return { objeto: 'Ticket', variavel: 'chamado' };
         case 'abrir_objeto': return { objeto: 'Ticket', variavel_titulo: 'titulo', variavel_descricao: 'descricao', tipo: '1', urgencia: '3', texto: 'Chamado *#{numero_criado}* registrado com sucesso.' };
         case 'acompanhamento': return { objeto: 'Ticket', variavel: 'chamado', texto: 'Escreva o texto do acompanhamento.', texto_ok: 'Acompanhamento registrado.' };
         case 'listar_validacoes': return { texto: '*Validações pendentes*', variavel: 'validacao' };
         case 'responder_validacao': return { variavel: 'validacao' };
         case 'conversa_tecnico': return { variavel: 'chamado' };
         case 'http': return { metodo: 'GET', variavel: 'http', tempo_limite: '10' };
         case 'encerrar': return { texto: 'Atendimento finalizado. Obrigado pelo contato.' };
      }
      return {};
   }

   /** Converte a posicao da tela (clientX/Y) para a posicao na area de desenho */
   function posicaoNoDesenho(x, y) {
      var r = editor.precanvas.getBoundingClientRect();
      return { x: (x - r.left) / editor.zoom, y: (y - r.top) / editor.zoom };
   }

   function adicionarBloco(tipo, clienteX, clienteY) {
      if (!tipos[tipo] || tipo === 'inicio') { return; }
      var pos = posicaoNoDesenho(clienteX, clienteY);
      var dados = { id: novoId(), tipo: tipo, rotulo: tipos[tipo].rotulo, config: configPadrao(tipo) };
      var dfId = editor.addNode(tipo, 1, saidasDe(dados).length, pos.x, pos.y, 'wae-df-no', dados, htmlBloco(dados));
      selecionar(dfId);
   }

   function selecionar(dfId) {
      var el = $id('node-' + dfId);
      if (!el) { return; }
      document.querySelectorAll('#wae-cons-drawflow .drawflow-node.selected').forEach(function (n) { n.classList.remove('selected'); });
      el.classList.add('selected');
      editor.node_selected = el;
      selecionado = String(dfId);
      mostrarPropriedades();
   }

   // ============================================
   // Painel de propriedades
   // ============================================

   function variaveisConhecidas() {
      var lista = ['fluxo_nome', 'numero_criado', 'ultima_opcao', 'resposta_invalida', 'cliente', 'contato_nome', 'contato_telefone'];
      if (!editor) { return lista; }
      var dados = dadosDoEditor();
      Object.keys(dados).forEach(function (k) {
         var c = dados[k].data.config || {};
         ['variavel', 'variavel_texto'].forEach(function (campo) {
            var nome = String(c[campo] || '').toLowerCase().replace(/[^a-z0-9_]/g, '');
            if (nome) {
               lista.push(nome);
               if (dados[k].data.tipo === 'http') { lista.push(nome + '_status'); }
            }
         });
         String(c.mapeamentos || '').split(/\r?\n/).forEach(function (linha) {
            var nome = linha.split('=')[0].trim().toLowerCase().replace(/[^a-z0-9_]/g, '');
            if (linha.indexOf('=') > 0 && nome) { lista.push(nome); }
         });
      });
      return lista.filter(function (v, i) { return lista.indexOf(v) === i; });
   }

   function opcoesDoCampo(campo) {
      if (campo.opcoes) {
         return Object.keys(campo.opcoes).map(function (k) { return { id: k, nome: campo.opcoes[k] }; });
      }
      var lista = catalogo[campo.fonte] || [];
      // Trocar de fluxo so leva a fluxos do mesmo numero
      if (campo.fonte === 'fluxos') {
         lista = lista.filter(function (i) { return !i.conexoes_id || i.conexoes_id === conexao; });
      }
      return lista.map(function (i) { return { id: i.id, nome: i.nome }; });
   }

   function campoHtml(campo, valor, dis) {
      var nome = e(campo.nome);
      var ajuda = campo.ajuda ? '<div class="form-hint">' + e(campo.ajuda) + '</div>' : '';
      var rotulo = '<label class="form-label">' + e(campo.rotulo) + '</label>';
      valor = valor === undefined || valor === null ? '' : valor;

      switch (campo.tipo) {
         case 'area':
            return '<div class="mb-3">' + rotulo +
               '<textarea class="form-control" rows="4" data-campo="' + nome + '"' + dis + '>' + e(valor) + '</textarea>' +
               '<div class="wae-cons-vars" data-inserir="' + nome + '"></div>' + ajuda + '</div>';

         case 'numero':
            return '<div class="mb-3">' + rotulo + '<input type="number" min="0" class="form-control" data-campo="' + nome + '" value="' + e(valor) + '"' + dis + '>' + ajuda + '</div>';

         case 'hora':
            return '<div class="mb-3">' + rotulo + '<input type="time" class="form-control" data-campo="' + nome + '" value="' + e(valor) + '"' + dis + '>' + ajuda + '</div>';

         case 'caixa':
            return '<div class="mb-3"><label class="form-check form-switch">' +
               '<input class="form-check-input" type="checkbox" data-campo="' + nome + '"' + (valor && valor !== '0' ? ' checked' : '') + dis + '>' +
               '<span class="form-check-label">' + e(campo.rotulo) + '</span></label>' + ajuda + '</div>';

         case 'selecao':
            var opcoes = opcoesDoCampo(campo);
            return '<div class="mb-3">' + rotulo + '<select class="form-select" data-campo="' + nome + '"' + dis + '>' +
               (campo.vazio ? '<option value="0">' + e(campo.vazio) + '</option>' : '') +
               opcoes.map(function (o) {
                  return '<option value="' + e(o.id) + '"' + (String(o.id) === String(valor) ? ' selected' : '') + '>' + e(o.nome) + '</option>';
               }).join('') + '</select>' + ajuda + '</div>';

         case 'variavel':
            return '<div class="mb-3">' + rotulo +
               '<div class="input-group"><span class="input-group-text">{ }</span>' +
               '<input type="text" class="form-control font-monospace" list="wae-cons-lista-vars" data-campo="' + nome + '" value="' + e(valor) + '" placeholder="nome_da_variavel"' + dis + '></div>' + ajuda + '</div>';

         case 'dias':
            var marcados = String(valor || '1,2,3,4,5').split(',');
            var nomes = ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'];
            return '<div class="mb-3">' + rotulo + '<div class="d-flex flex-wrap gap-2" data-dias="' + nome + '">' +
               nomes.map(function (n, i) {
                  return '<label class="form-check form-check-inline m-0"><input class="form-check-input" type="checkbox" value="' + (i + 1) + '"' +
                     (marcados.indexOf(String(i + 1)) >= 0 ? ' checked' : '') + dis + '><span class="form-check-label">' + n + '</span></label>';
               }).join('') + '</div>' + ajuda + '</div>';

         case 'opcoes':
            return '<div class="mb-3">' + rotulo + '<div data-lista="opcoes"></div>' + ajuda + '</div>';

         case 'caminhos':
            return '<div class="mb-3">' + rotulo + '<div data-lista="caminhos"></div>' + ajuda + '</div>';

         case 'midia':
            return '<div class="mb-3">' + rotulo + '<div data-midia></div>' + ajuda + '</div>';
      }

      return '<div class="mb-3">' + rotulo + '<input type="text" class="form-control" data-campo="' + nome + '" value="' + e(valor) + '"' + dis + '>' + ajuda + '</div>';
   }

   function mostrarPropriedades() {
      var painel = $id('wae-cons-props');
      if (!painel) { return; }
      var dis = podeEditar ? '' : ' disabled';

      if (!atual) {
         painel.innerHTML = '<div class="wae-cons-props-vazio"><i class="ti ti-git-merge"></i><div>Crie ou escolha um fluxo.</div></div>';
         return;
      }

      var no = selecionado ? editor.getNodeFromId(selecionado) : null;

      // Sem bloco selecionado ou com o Inicio: configuracoes do fluxo
      if (!no || no.data.tipo === 'inicio') {
         var gatilhos = catalogo.gatilhos || {};
         painel.innerHTML =
            '<div class="wae-cons-props-topo wae-g-inicio"><i class="ti ti-settings"></i><span>Configurações do fluxo</span></div>' +
            '<div class="wae-cons-props-corpo">' +
               '<div class="mb-3"><label class="form-label">Nome</label><input type="text" class="form-control" data-fluxo="nome" maxlength="120" value="' + e(atual.nome) + '"' + dis + '>' +
                  '<div class="form-hint">É o texto da opção no menu de atendimento.</div></div>' +
               '<div class="mb-3"><label class="form-label">Descrição interna</label><input type="text" class="form-control" data-fluxo="descricao" maxlength="250" value="' + e(atual.descricao) + '"' + dis + '></div>' +
               '<div class="mb-3"><label class="form-label">Como o fluxo começa</label><select class="form-select" data-fluxo="gatilho"' + dis + '>' +
                  Object.keys(gatilhos).map(function (g) { return '<option value="' + g + '"' + (atual.gatilho === g ? ' selected' : '') + '>' + e(gatilhos[g]) + '</option>'; }).join('') +
               '</select></div>' +
               '<div class="mb-3' + (atual.gatilho === 'palavra' ? '' : ' wae-oculto') + '" id="wae-cons-palavras"><label class="form-label">Palavras que iniciam o fluxo</label>' +
                  '<input type="text" class="form-control" data-fluxo="palavras" maxlength="250" value="' + e(atual.palavras) + '" placeholder="boleto, segunda via"' + dis + '>' +
                  '<div class="form-hint">Separadas por vírgula.</div></div>' +
               '<div class="alert alert-info small mb-0"><i class="ti ti-info-circle me-1"></i>' +
                  'Arraste blocos da esquerda, ligue a saída do <strong>Início</strong> ao primeiro bloco e clique num bloco para configurá-lo. ' +
                  'Tudo é salvo sozinho e vale na próxima mensagem recebida.</div>' +
            '</div>';
         return;
      }

      var dados = no.data;
      var tipo = tipos[dados.tipo] || { campos: [] };

      painel.innerHTML =
         '<div class="wae-cons-props-topo ' + (CORES[tipo.grupo] || '') + '"><i class="' + e(tipo.icone) + '"></i><span>' + e(tipo.rotulo) + '</span>' +
            (podeEditar ? '<div class="ms-auto d-flex gap-1">' +
               '<button type="button" class="btn btn-sm btn-ghost-secondary" data-acao="duplicar-bloco" title="Duplicar bloco"><i class="ti ti-copy"></i></button>' +
               '<button type="button" class="btn btn-sm btn-ghost-danger" data-acao="excluir-bloco" title="Excluir bloco"><i class="ti ti-trash"></i></button></div>' : '') +
         '</div>' +
         '<div class="wae-cons-props-corpo">' +
            '<p class="text-secondary small">' + e(tipo.ajuda) + '</p>' +
            '<div class="mb-3"><label class="form-label">Nome do bloco</label><input type="text" class="form-control" data-rotulo maxlength="80" value="' + e(dados.rotulo) + '"' + dis + '></div>' +
            (tipo.campos || []).map(function (campo) { return campoHtml(campo, (dados.config || {})[campo.nome], dis); }).join('') +
            '<datalist id="wae-cons-lista-vars">' + variaveisConhecidas().map(function (v) { return '<option value="' + e(v) + '">'; }).join('') + '</datalist>' +
         '</div>';

      desenharListas(dados);
      desenharMidia(dados);
      desenharVariaveis();
   }

   function desenharVariaveis() {
      var vars = variaveisConhecidas();
      document.querySelectorAll('#wae-cons-props [data-inserir]').forEach(function (alvo) {
         if (!podeEditar) { return; }
         alvo.innerHTML = '<span class="text-secondary small me-1">Inserir:</span>' + vars.map(function (v) {
            return '<button type="button" class="badge bg-secondary-lt wae-cons-var" data-var="' + e(v) + '">{' + e(v) + '}</button>';
         }).join(' ');
      });
   }

   function desenharListas(dados) {
      var config = dados.config || {};
      var dis = podeEditar ? '' : ' disabled';

      var opcoes = document.querySelector('#wae-cons-props [data-lista="opcoes"]');
      if (opcoes) {
         opcoes.innerHTML = (config.opcoes || []).map(function (op, i) {
            return '<div class="input-group input-group-sm mb-1">' +
               '<span class="input-group-text">' + (i + 1) + '</span>' +
               '<input type="text" class="form-control" maxlength="24" data-opcao="' + i + '" value="' + e(op.rotulo) + '"' + dis + '>' +
               (podeEditar ? '<button type="button" class="btn btn-outline-secondary" data-mover-opcao="' + i + '" data-dir="-1" title="Subir"' + (i === 0 ? ' disabled' : '') + '><i class="ti ti-arrow-up"></i></button>' +
                  '<button type="button" class="btn btn-outline-danger" data-remover-opcao="' + i + '" title="Remover"><i class="ti ti-x"></i></button>' : '') +
            '</div>';
         }).join('') + (podeEditar && (config.opcoes || []).length < 10 ?
            '<button type="button" class="btn btn-sm btn-outline-primary mt-1" data-acao="add-opcao"><i class="ti ti-plus me-1"></i>Adicionar opção</button>' : '');
      }

      var caminhos = document.querySelector('#wae-cons-props [data-lista="caminhos"]');
      if (caminhos) {
         var soma = (config.caminhos || []).reduce(function (t, c) { return t + (parseInt(c.percentual, 10) || 0); }, 0);
         caminhos.innerHTML = (config.caminhos || []).map(function (c, i) {
            return '<div class="input-group input-group-sm mb-1">' +
               '<span class="input-group-text">Caminho ' + (i + 1) + '</span>' +
               '<input type="number" min="0" max="100" class="form-control" data-caminho="' + i + '" value="' + (parseInt(c.percentual, 10) || 0) + '"' + dis + '>' +
               '<span class="input-group-text">%</span>' +
               (podeEditar && config.caminhos.length > 2 ? '<button type="button" class="btn btn-outline-danger" data-remover-caminho="' + i + '"><i class="ti ti-x"></i></button>' : '') +
            '</div>';
         }).join('') +
         '<div class="small ' + (soma === 100 ? 'text-success' : 'text-warning') + '">Total: ' + soma + '%</div>' +
         (podeEditar && (config.caminhos || []).length < 6 ? '<button type="button" class="btn btn-sm btn-outline-primary mt-1" data-acao="add-caminho"><i class="ti ti-plus me-1"></i>Adicionar caminho</button>' : '');
      }
   }

   function desenharMidia(dados) {
      var alvo = document.querySelector('#wae-cons-props [data-midia]');
      if (!alvo) { return; }
      var c = dados.config || {};

      if (c.midia_arquivo) {
         var url = URL_AJAX + '?acao=fluxo_midia_ver&arquivo=' + encodeURIComponent(c.midia_arquivo);
         alvo.innerHTML = '<div class="wae-cons-midia">' +
            (c.midia_tipo === 'audio'
               ? '<audio controls preload="metadata" src="' + e(url) + '"></audio>'
               : '<img src="' + e(url) + '" alt="Imagem do bloco">') +
            '<div class="d-flex align-items-center gap-2 mt-1"><span class="small text-secondary text-truncate">' + e(c.midia_nome || c.midia_arquivo) + '</span>' +
            (podeEditar ? '<button type="button" class="btn btn-sm btn-ghost-danger ms-auto" data-acao="remover-midia"><i class="ti ti-trash me-1"></i>Remover</button>' : '') +
            '</div></div>';
         return;
      }

      alvo.innerHTML = podeEditar
         ? '<label class="btn btn-sm btn-outline-secondary"><i class="ti ti-paperclip me-1"></i>Escolher imagem ou áudio' +
            '<input type="file" class="d-none" data-enviar-midia accept="image/jpeg,image/png,image/webp,image/gif,audio/ogg,audio/mpeg,audio/mp4,audio/webm"></label>'
         : '<span class="text-secondary small">Sem mídia.</span>';
   }

   function dadosSelecionados() {
      var no = selecionado ? editor.getNodeFromId(selecionado) : null;
      return no ? JSON.parse(JSON.stringify(no.data)) : null;
   }

   function gravarDados(dados, mudaSaidas, mapa) {
      var antigos = dadosSelecionados();
      if (mudaSaidas) {
         reestruturarSaidas(selecionado, antigos, dados, mapa || {});
      } else {
         editor.updateNodeDataFromId(selecionado, dados);
         atualizarBloco(selecionado);
         agendarSalvar();
      }
   }

   function ligarPropriedades() {
      var painel = $id('wae-cons-props');

      // Delete/Backspace digitados no painel nao podem apagar o bloco selecionado
      painel.addEventListener('keydown', function (ev) {
         if (ev.key === 'Delete' || ev.key === 'Backspace') { ev.stopPropagation(); }
      });

      painel.addEventListener('input', function (ev) {
         var alvo = ev.target;

         if (alvo.hasAttribute('data-fluxo')) {
            atual[alvo.getAttribute('data-fluxo')] = alvo.value;
            if (alvo.getAttribute('data-fluxo') === 'nome') { atualizarItemDaLista(); }
            atualizarInicio();
            agendarSalvar();
            return;
         }

         var dados = dadosSelecionados();
         if (!dados) { return; }

         if (alvo.hasAttribute('data-rotulo')) {
            dados.rotulo = alvo.value;
            gravarDados(dados);
            return;
         }
         if (alvo.hasAttribute('data-opcao')) {
            dados.config.opcoes[parseInt(alvo.getAttribute('data-opcao'), 10)].rotulo = alvo.value;
            gravarDados(dados);
            return;
         }
         if (alvo.hasAttribute('data-caminho')) {
            dados.config.caminhos[parseInt(alvo.getAttribute('data-caminho'), 10)].percentual = parseInt(alvo.value, 10) || 0;
            gravarDados(dados);
            var soma = dados.config.caminhos.reduce(function (t, c) { return t + (parseInt(c.percentual, 10) || 0); }, 0);
            var total = alvo.closest('[data-lista]').querySelector('.small');
            if (total) { total.className = 'small ' + (soma === 100 ? 'text-success' : 'text-warning'); total.textContent = 'Total: ' + soma + '%'; }
            return;
         }
         if (alvo.hasAttribute('data-campo') && alvo.type !== 'checkbox' && alvo.tagName !== 'SELECT') {
            dados.config[alvo.getAttribute('data-campo')] = alvo.value;
            gravarDados(dados);
         }
      });

      painel.addEventListener('change', function (ev) {
         var alvo = ev.target;

         if (alvo.hasAttribute('data-fluxo')) {
            atual[alvo.getAttribute('data-fluxo')] = alvo.value;
            if (alvo.getAttribute('data-fluxo') === 'gatilho') {
               $id('wae-cons-palavras').classList.toggle('wae-oculto', alvo.value !== 'palavra');
            }
            atualizarInicio();
            agendarSalvar();
            return;
         }

         var dados = dadosSelecionados();
         if (!dados) { return; }

         if (alvo.hasAttribute('data-enviar-midia')) {
            enviarMidia(alvo.files[0]);
            return;
         }

         var dias = alvo.closest('[data-dias]');
         if (dias) {
            dados.config[dias.getAttribute('data-dias')] = Array.prototype.slice.call(dias.querySelectorAll('input:checked')).map(function (i) { return i.value; }).join(',');
            gravarDados(dados);
            return;
         }

         if (alvo.hasAttribute('data-campo') && (alvo.type === 'checkbox' || alvo.tagName === 'SELECT')) {
            dados.config[alvo.getAttribute('data-campo')] = alvo.type === 'checkbox' ? (alvo.checked ? '1' : '') : alvo.value;
            gravarDados(dados);
         }
         if (alvo.hasAttribute('data-campo') || alvo.hasAttribute('data-rotulo')) {
            // Variavel nova passa a aparecer nas sugestoes dos outros blocos
            desenharVariaveis();
         }
      });

      painel.addEventListener('click', function (ev) {
         var alvo = ev.target;
         var dados = dadosSelecionados();

         var variavel = alvo.closest('[data-var]');
         if (variavel && dados) {
            var campo = variavel.closest('.mb-3').querySelector('textarea');
            var inicio = campo.selectionStart || campo.value.length;
            campo.value = campo.value.slice(0, inicio) + '{' + variavel.getAttribute('data-var') + '}' + campo.value.slice(campo.selectionEnd || inicio);
            campo.dispatchEvent(new Event('input', { bubbles: true }));
            campo.focus();
            return;
         }

         var botao = alvo.closest('[data-acao], [data-remover-opcao], [data-mover-opcao], [data-remover-caminho]');
         if (!botao || !dados) { return; }

         var acao = botao.getAttribute('data-acao');
         var mapa = {};

         if (acao === 'excluir-bloco') {
            editor.removeNodeId('node-' + selecionado);
            return;
         }
         if (acao === 'duplicar-bloco') {
            var original = editor.getNodeFromId(selecionado);
            var copia = JSON.parse(JSON.stringify(original.data));
            copia.id = novoId();
            copia.rotulo = copia.rotulo + ' (cópia)';
            var novo = editor.addNode(copia.tipo, 1, saidasDe(copia).length, original.pos_x + 40, original.pos_y + 40, 'wae-df-no', copia, htmlBloco(copia));
            selecionar(novo);
            return;
         }
         if (acao === 'remover-midia') {
            ['midia_tipo', 'midia_arquivo', 'midia_mime', 'midia_nome'].forEach(function (k) { delete dados.config[k]; });
            gravarDados(dados);
            desenharMidia(dados);
            return;
         }
         if (acao === 'add-opcao') {
            dados.config.opcoes = dados.config.opcoes || [];
            var n = dados.config.opcoes.length;
            dados.config.opcoes.push({ rotulo: 'Opção ' + (n + 1) });
            gravarDados(dados, true, {});
            desenharListas(dados);
            return;
         }
         if (botao.hasAttribute('data-remover-opcao')) {
            var i = parseInt(botao.getAttribute('data-remover-opcao'), 10);
            dados.config.opcoes.forEach(function (op, k) {
               if (k === i) { mapa['op' + (k + 1)] = null; } else if (k > i) { mapa['op' + (k + 1)] = 'op' + k; }
            });
            dados.config.opcoes.splice(i, 1);
            gravarDados(dados, true, mapa);
            desenharListas(dados);
            return;
         }
         if (botao.hasAttribute('data-mover-opcao')) {
            var j = parseInt(botao.getAttribute('data-mover-opcao'), 10);
            if (j <= 0) { return; }
            var tmp = dados.config.opcoes[j - 1];
            dados.config.opcoes[j - 1] = dados.config.opcoes[j];
            dados.config.opcoes[j] = tmp;
            mapa['op' + j] = 'op' + (j + 1);
            mapa['op' + (j + 1)] = 'op' + j;
            gravarDados(dados, true, mapa);
            desenharListas(dados);
            return;
         }
         if (acao === 'add-caminho') {
            dados.config.caminhos.push({ percentual: 0 });
            gravarDados(dados, true, {});
            desenharListas(dados);
            return;
         }
         if (botao.hasAttribute('data-remover-caminho')) {
            var r = parseInt(botao.getAttribute('data-remover-caminho'), 10);
            dados.config.caminhos.forEach(function (c, k) {
               if (k === r) { mapa['r' + (k + 1)] = null; } else if (k > r) { mapa['r' + (k + 1)] = 'r' + k; }
            });
            dados.config.caminhos.splice(r, 1);
            gravarDados(dados, true, mapa);
            desenharListas(dados);
         }
      });
   }

   function enviarMidia(arquivo) {
      if (!arquivo) { return; }
      var tipo = arquivo.type.indexOf('audio/') === 0 ? 'audio' : 'imagem';
      var corpo = new FormData();
      corpo.append('acao', 'fluxo_midia');
      corpo.append('tipo', tipo);
      corpo.append('arquivo', arquivo, arquivo.name);

      status('<i class="ti ti-loader-2 me-1"></i>Enviando arquivo...');
      var alvoId = selecionado;

      $.ajax({ url: URL_AJAX, type: 'POST', data: corpo, processData: false, contentType: false, dataType: 'json' })
         .done(function (r) {
            if (!r || !r.sucesso) { WAE.avisar((r && r.mensagem) || 'Nao foi possivel enviar o arquivo.', true); status(''); return; }
            var no = editor.getNodeFromId(alvoId);
            if (!no) { return; }
            var dados = JSON.parse(JSON.stringify(no.data));
            dados.config.midia_tipo = r.midia.tipo;
            dados.config.midia_arquivo = r.midia.arquivo;
            dados.config.midia_mime = r.midia.mime;
            dados.config.midia_nome = r.midia.nome;
            editor.updateNodeDataFromId(alvoId, dados);
            atualizarBloco(alvoId);
            if (selecionado === alvoId) { desenharMidia(dados); }
            agendarSalvar();
         })
         .fail(function () { WAE.avisar('Falha ao enviar o arquivo (limite do servidor: tamanho do upload).', true); status(''); });
   }

   /** O resumo do Inicio mostra o gatilho do fluxo */
   function atualizarInicio() {
      var dados = dadosDoEditor();
      Object.keys(dados).forEach(function (dfId) {
         if (dados[dfId].data.tipo === 'inicio') { atualizarBloco(dfId); }
      });
   }

   // ============================================
   // Zoom e enquadramento
   // ============================================

   function mostrarZoom() {
      var alvo = $id('wae-cons-zoom');
      if (alvo) { alvo.textContent = Math.round(editor.zoom * 100) + '%'; }
   }

   function aplicarTransformacao() {
      editor.precanvas.style.transform = 'translate(' + editor.canvas_x + 'px, ' + editor.canvas_y + 'px) scale(' + editor.zoom + ')';
      mostrarZoom();
   }

   /** Ajusta zoom e posicao para todos os blocos caberem na tela */
   function enquadrar() {
      var dados = dadosDoEditor();
      var ids = Object.keys(dados);
      if (!ids.length) { return; }

      var minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
      ids.forEach(function (id) {
         var el = $id('node-' + id);
         var w = el ? el.offsetWidth : 220;
         var h = el ? el.offsetHeight : 100;
         minX = Math.min(minX, dados[id].pos_x);
         minY = Math.min(minY, dados[id].pos_y);
         maxX = Math.max(maxX, dados[id].pos_x + w);
         maxY = Math.max(maxY, dados[id].pos_y + h);
      });

      var area = $id('wae-cons-drawflow');
      var margem = 40;
      var zoom = Math.min(1.1, (area.clientWidth - margem * 2) / (maxX - minX), (area.clientHeight - margem * 2) / (maxY - minY));
      zoom = Math.max(editor.zoom_min, zoom);

      editor.zoom = zoom;
      editor.canvas_x = margem - minX * zoom + Math.max(0, (area.clientWidth - margem * 2 - (maxX - minX) * zoom) / 2);
      editor.canvas_y = margem - minY * zoom;
      aplicarTransformacao();
   }

   // ============================================
   // Teste (simulador)
   // ============================================

   function reiniciarTeste() {
      teste = [];
      var chat = $id('wae-cons-teste-chat');
      if (chat) { chat.innerHTML = ''; }
   }

   function rodarTeste() {
      if (!atual) { return; }
      salvarAgora().then(function () {
         return WAE.pedir('fluxo_testar', { id: atual.id, conexao: conexao, entradas: JSON.stringify(teste) }, 'POST');
      }).then(function (r) {
         var chat = $id('wae-cons-teste-chat');
         if (!r.sucesso) {
            chat.innerHTML = '<div class="wae-cons-balao wae-cons-balao-aviso">' + e(r.mensagem || 'Nao foi possivel testar.') + '</div>';
            return;
         }
         chat.innerHTML = (r.roteiro || []).map(function (item) {
            var classe = item.quem === 'cliente' ? 'wae-cons-balao-cliente' : (item.quem === 'servidor' ? 'wae-cons-balao-bot' : 'wae-cons-balao-aviso');
            var botoes = (item.botoes || []).length
               ? '<div class="wae-cons-balao-botoes">' + item.botoes.map(function (b) {
                  return '<button type="button" class="btn btn-sm btn-outline-success" data-responder="' + e(String(b).split(' - ')[0]) + '">' + e(b) + '</button>';
               }).join('') + '</div>'
               : '';
            return '<div class="wae-cons-balao ' + classe + '">' + e(item.texto) + botoes + '</div>';
         }).join('');
         chat.scrollTop = chat.scrollHeight;
      });
   }

   function responderTeste(texto) {
      texto = String(texto || '').trim();
      if (!texto) { return; }
      teste.push(texto);
      rodarTeste();
   }

   // ============================================
   // Barra de ferramentas
   // ============================================

   function ligarBarra() {
      $id('wae-cons-fluxo').addEventListener('change', function () {
         abrirFluxo(parseInt(this.value, 10));
      });

      $id('wae-cons-ativo').addEventListener('change', function () {
         if (!atual) { return; }
         atual.is_ativo = this.checked ? 1 : 0;
         agendarSalvar();
         WAE.avisar(this.checked ? 'Fluxo ativado: já responde no WhatsApp.' : 'Fluxo desativado.');
      });

      if ($id('wae-cons-novo')) {
         $id('wae-cons-novo').addEventListener('click', function () {
            salvarAgora().then(function () {
               return WAE.pedir('fluxo_criar', { nome: 'Novo fluxo', conexao: conexao }, 'POST');
            }).then(function (r) {
               WAE.avisar(r.mensagem || '', !r.sucesso);
               if (r.sucesso) { recarregarCatalogo(); carregarLista(r.id); }
            });
         });

         // Duplicar no mesmo numero ou copiar para outro numero (a copia nasce inativa)
         $id('wae-cons-copiar-menu').addEventListener('click', function (ev) {
            var mover = ev.target.closest('[data-mover]');
            if (mover && atual) {
               ev.preventDefault();
               var destinoMover = parseInt(mover.getAttribute('data-mover'), 10);
               var idMover = atual.id;
               salvarAgora().then(function () { return moverFluxo(idMover, destinoMover); }).then(function (r) {
                  if (r.sucesso) { entrarNoFluxo(destinoMover, idMover); }
               });
               return;
            }
            var item = ev.target.closest('[data-destino]');
            if (!item || !atual) { return; }
            ev.preventDefault();
            var destino = parseInt(item.getAttribute('data-destino'), 10) || 0;
            salvarAgora().then(function () {
               return WAE.pedir('fluxo_duplicar', { id: atual.id, destino: destino }, 'POST');
            }).then(function (r) {
               WAE.avisar(r.mensagem || '', !r.sucesso);
               if (!r.sucesso) { return; }
               recarregarCatalogo();
               if (destino && destino !== conexao) { carregarLista(); } else { carregarLista(r.id); }
            });
         });

         $id('wae-cons-excluir').addEventListener('click', function () {
            if (!atual) { return; }
            WAE.confirmar('Excluir o fluxo "' + atual.nome + '"? Quem estiver no meio dele volta para o menu.').then(function (ok) {
               if (!ok) { return; }
               clearTimeout(temporizador);
               WAE.pedir('fluxo_remover', { id: atual.id }, 'POST').then(function (r) {
                  WAE.avisar(r.mensagem || '', !r.sucesso);
                  atual = null;
                  recarregarCatalogo();
                  carregarLista();
               });
            });
         });
      }

      // Ocultar e mostrar os paineis laterais (lembrado neste navegador)
      function alternarPainel(classe, botaoId, iconeAberto, iconeFechado, rotulo, forcar) {
         var oculto = forcar !== undefined ? forcar : !raiz.classList.contains(classe);
         raiz.classList.toggle(classe, oculto);
         var botao = $id(botaoId);
         botao.querySelector('i').className = 'ti ' + (oculto ? iconeFechado : iconeAberto);
         botao.title = (oculto ? 'Mostrar ' : 'Ocultar ') + rotulo;
         botao.classList.toggle('active', oculto);
         try { localStorage.setItem('wae-construtor-' + classe, oculto ? '1' : '0'); } catch (x) { /* sem armazenamento */ }
      }
      var paineis = [
         ['wae-sem-paleta', 'wae-cons-alternar-paleta', 'ti-layout-sidebar-left-collapse', 'ti-layout-sidebar-left-expand', 'o menu de blocos'],
         ['wae-sem-props', 'wae-cons-alternar-props', 'ti-layout-sidebar-right-collapse', 'ti-layout-sidebar-right-expand', 'o painel de propriedades']
      ];
      paineis.forEach(function (p) {
         $id(p[1]).addEventListener('click', function () { alternarPainel(p[0], p[1], p[2], p[3], p[4]); });
         var salvo = null;
         try { salvo = localStorage.getItem('wae-construtor-' + p[0]); } catch (x) { salvo = null; }
         if (salvo === '1') { alternarPainel(p[0], p[1], p[2], p[3], p[4], true); }
      });

      $id('wae-cons-zoom-mais').addEventListener('click', function () { editor.zoom_in(); mostrarZoom(); });
      $id('wae-cons-zoom-menos').addEventListener('click', function () { editor.zoom_out(); mostrarZoom(); });
      $id('wae-cons-zoom-normal').addEventListener('click', function () { editor.zoom_reset(); mostrarZoom(); });
      $id('wae-cons-centralizar').addEventListener('click', enquadrar);
      $id('wae-cons-tela-cheia').addEventListener('click', function () {
         raiz.classList.toggle('wae-cons-cheio');
         this.querySelector('i').className = raiz.classList.contains('wae-cons-cheio') ? 'ti ti-minimize' : 'ti ti-maximize';
         setTimeout(enquadrar, 50);
      });

      $id('wae-cons-lista-avisos').addEventListener('click', function (ev) {
         var item = ev.target.closest('[data-aviso]');
         if (!item) { return; }
         ev.preventDefault();
         var aviso = avisos[parseInt(item.getAttribute('data-aviso'), 10)];
         if (!aviso || !aviso.no) { return; }
         var dados = dadosDoEditor();
         Object.keys(dados).forEach(function (dfId) {
            if (dados[dfId].data.id === aviso.no) { selecionar(dfId); }
         });
      });

      $id('wae-cons-testar').addEventListener('click', function () {
         var painel = $id('wae-cons-teste');
         var abrir = painel.classList.contains('wae-oculto');
         painel.classList.toggle('wae-oculto', !abrir);
         $id('wae-cons-props').classList.toggle('wae-oculto', abrir);
         if (abrir) { reiniciarTeste(); rodarTeste(); $id('wae-cons-teste-texto').focus(); }
      });
      $id('wae-cons-teste-fechar').addEventListener('click', function () {
         $id('wae-cons-teste').classList.add('wae-oculto');
         $id('wae-cons-props').classList.remove('wae-oculto');
      });
      $id('wae-cons-teste-reiniciar').addEventListener('click', function () { reiniciarTeste(); rodarTeste(); });
      $id('wae-cons-teste-enviar').addEventListener('click', function () {
         var campo = $id('wae-cons-teste-texto');
         responderTeste(campo.value);
         campo.value = '';
      });
      $id('wae-cons-teste-texto').addEventListener('keydown', function (ev) {
         ev.stopPropagation();
         if (ev.key === 'Enter') { ev.preventDefault(); $id('wae-cons-teste-enviar').click(); }
      });
      $id('wae-cons-teste-chat').addEventListener('click', function (ev) {
         var botao = ev.target.closest('[data-responder]');
         if (botao) { responderTeste(botao.getAttribute('data-responder')); }
      });

      // Sair da pagina com alteracao pendente: grava antes
      window.addEventListener('beforeunload', function () {
         if (temporizador) { salvarAgora(); }
      });
   }

   // ============================================
   // Area de desenho
   // ============================================

   /**
    * Navegacao pela area de desenho (substitui o arrastar do Drawflow, que falhava quando o
    * clique caia em linhas, na legenda ou fora da area desenhada, e travava se o botao fosse
    * solto fora da tela):
    * - botao esquerdo em qualquer ponto vazio, ou botao do meio em qualquer lugar: arrasta
    * - roda do mouse: rola; Ctrl + roda continua com o zoom do Drawflow
    */
   function ligarNavegacao(area) {
      var arrasto = null;

      function sobreElemento(alvo) {
         return alvo.closest('.drawflow-node, .output, .input, .connection, .drawflow-delete, .wae-cons-vazio');
      }

      function desmarcarTudo() {
         if (editor.node_selected) {
            editor.node_selected.classList.remove('selected');
            editor.node_selected = null;
            editor.dispatch('nodeUnselected', true);
         }
         if (editor.connection_selected) {
            editor.connection_selected.classList.remove('selected');
            editor.connection_selected = null;
            editor.dispatch('connectionUnselected', true);
         }
         var botaoApagar = area.querySelector('.drawflow-delete');
         if (botaoApagar) { botaoApagar.remove(); }
      }

      function comecar(x, y) {
         arrasto = { x: x, y: y, cx: editor.canvas_x, cy: editor.canvas_y, moveu: false };
         area.classList.add('wae-arrastando');
      }

      function mover(x, y) {
         if (!arrasto) { return; }
         var dx = x - arrasto.x;
         var dy = y - arrasto.y;
         if (Math.abs(dx) + Math.abs(dy) > 3) { arrasto.moveu = true; }
         editor.canvas_x = arrasto.cx + dx;
         editor.canvas_y = arrasto.cy + dy;
         aplicarTransformacao();
      }

      function terminar() {
         if (!arrasto) { return; }
         var moveu = arrasto.moveu;
         arrasto = null;
         area.classList.remove('wae-arrastando');
         // Clique simples no fundo: tira a selecao (como o Drawflow fazia)
         if (!moveu) { desmarcarTudo(); }
      }

      // Fase de captura: decide antes do Drawflow
      area.addEventListener('mousedown', function (ev) {
         var meio = ev.button === 1;
         if (!meio && (ev.button !== 0 || sobreElemento(ev.target))) { return; }
         ev.preventDefault();
         ev.stopImmediatePropagation();
         comecar(ev.clientX, ev.clientY);
      }, true);

      document.addEventListener('mousemove', function (ev) {
         if (arrasto) { ev.preventDefault(); mover(ev.clientX, ev.clientY); }
      });
      document.addEventListener('mouseup', terminar);
      window.addEventListener('blur', terminar);

      // Toque (tablet): um dedo no fundo arrasta
      area.addEventListener('touchstart', function (ev) {
         if (ev.touches.length !== 1 || sobreElemento(ev.target)) { return; }
         ev.stopImmediatePropagation();
         comecar(ev.touches[0].clientX, ev.touches[0].clientY);
      }, { capture: true, passive: true });
      area.addEventListener('touchmove', function (ev) {
         if (arrasto && ev.touches.length === 1) { mover(ev.touches[0].clientX, ev.touches[0].clientY); }
      }, { passive: true });
      area.addEventListener('touchend', terminar);

      // Roda do mouse (ou dois dedos no touchpad) rola a area
      area.addEventListener('wheel', function (ev) {
         if (ev.ctrlKey) { return; }
         ev.preventDefault();
         editor.canvas_x -= ev.deltaX;
         editor.canvas_y -= ev.deltaY;
         aplicarTransformacao();
      }, { passive: false });
   }

   function ligarEditor() {
      var area = $id('wae-cons-drawflow');
      editor = new Drawflow(area);
      editor.reroute = false;
      editor.force_first_input = false;
      editor.zoom_min = 0.3;
      editor.zoom_max = 1.6;
      editor.editor_mode = podeEditar ? 'edit' : 'view';
      editor.start();

      ligarNavegacao(area);

      area.addEventListener('dragover', function (ev) { if (podeEditar) { ev.preventDefault(); ev.dataTransfer.dropEffect = 'copy'; } });
      area.addEventListener('drop', function (ev) {
         ev.preventDefault();
         if (!podeEditar || !atual) { return; }
         adicionarBloco(ev.dataTransfer.getData('text/plain'), ev.clientX, ev.clientY);
      });

      editor.on('nodeCreated', function () { agendarSalvar(); });
      editor.on('nodeMoved', function () { agendarSalvar(); });

      editor.on('nodeRemoved', function (id) {
         if (String(selecionado) === String(id)) { selecionado = null; mostrarPropriedades(); }
         agendarSalvar();
      });

      editor.on('nodeSelected', function (id) {
         selecionado = String(id);
         mostrarPropriedades();
      });
      editor.on('nodeUnselected', function () {
         selecionado = null;
         mostrarPropriedades();
      });

      editor.on('connectionCreated', function (c) {
         if (carregando) { return; }
         // Bloco ligado nele mesmo criaria um laco sem fim
         if (String(c.output_id) === String(c.input_id)) {
            editor.removeSingleConnection(c.output_id, c.input_id, c.output_class, c.input_class);
            WAE.avisar('Um bloco não pode ser ligado nele mesmo.', true);
            return;
         }
         // Uma saida leva a um unico bloco: a ligacao nova substitui a anterior
         var no = editor.getNodeFromId(c.output_id);
         var existentes = (no.outputs[c.output_class] || {}).connections || [];
         existentes.forEach(function (con) {
            if (String(con.node) !== String(c.input_id)) {
               editor.removeSingleConnection(c.output_id, con.node, c.output_class, con.output);
            }
         });
         agendarSalvar();
      });

      // Delete/Backspace digitados em qualquer campo fora da area de desenho nao apagam o bloco
      document.addEventListener('keydown', function (ev) {
         var alvo = ev.target;
         if ((ev.key === 'Delete' || ev.key === 'Backspace') && editor.node_selected && alvo && alvo.matches
             && alvo.matches('input, textarea, select, [contenteditable]') && !alvo.closest('#wae-cons-drawflow')) {
            ev.stopImmediatePropagation();
         }
      }, true);
      editor.on('connectionRemoved', function () { agendarSalvar(); });
      editor.on('zoom', mostrarZoom);
   }

   window.WAEConstrutor = {
      iniciar: function (id) {
         raiz = $id(id);
         if (!raiz || raiz.getAttribute('data-iniciado')) { return; }
         if (typeof Drawflow === 'undefined') {
            raiz.innerHTML = '<div class="alert alert-danger m-3">A biblioteca do editor (Drawflow) não carregou. Recarregue a página.</div>';
            return;
         }
         raiz.setAttribute('data-iniciado', '1');
         podeEditar = raiz.getAttribute('data-edita') === '1';

         WAE.pedir('fluxo_catalogo').then(function (r) {
            if (!r.sucesso) { WAE.avisar('Nao foi possivel carregar o catalogo de blocos.', true); return; }
            catalogo = r.catalogo;
            tipos = catalogo.tipos_passo;

            ligarEditor();
            desenharPaleta();
            ligarPropriedades();
            ligarBarra();
            ligarConexoes();
            mostrarVisao();
         });
      }
   };
})();
