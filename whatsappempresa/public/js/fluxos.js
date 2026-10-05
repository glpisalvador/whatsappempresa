/* whatsappempresa - editor dos fluxos montados */

(function () {
   'use strict';

   // Carregado junto com painel.js; a aba Fluxos chama WAEFluxos.iniciar() quando o conteudo chega por ajax
   if (typeof WAE === 'undefined') {
      return;
   }

   var pedir = WAE.pedir;
   var avisar = WAE.avisar;
   var escapar = WAE.escapar;
   var podeEditar = !!WAE.podeEditar;

   var estado = {
      fluxos: [],
      catalogo: null,
      atual: null,
      entradas: []
   };

   var ROTULOS = {
      texto: 'Texto enviado ao cliente',
      variavel: 'Variavel usada',
      variavel_titulo: 'Variavel com o titulo',
      variavel_descricao: 'Variavel com a descricao',
      modelo_descricao: 'Modelo da descricao do registro',
      variavel_texto: 'Variavel do acompanhamento',
      minimo: 'Minimo de caracteres',
      objeto: 'Objeto do GLPI',
      opcoes: 'Opcoes e destinos',
      somente_abertos: 'Listar somente os abertos',
      privado: 'Acompanhamento privado',
      silenciar: 'Silenciar o numero ao finalizar',
      tipo: 'Tipo do chamado',
      urgencia: 'Urgencia',
      categoria: 'Categoria',
      sla: 'SLA de solucao',
      grupo: 'Grupo atribuido',
      operador: 'Comparacao',
      valor: 'Valor comparado',
      ir_verdadeiro: 'Destino quando verdadeiro',
      ir_falso: 'Destino quando falso',
      ir_sim: 'Destino quando responder sim',
      ir_nao: 'Destino quando responder nao',
      texto_ok: 'Mensagem de confirmacao'
   };

   var OPERADORES = {
      igual: 'e igual a',
      diferente: 'e diferente de',
      contem: 'contem',
      vazio: 'esta vazia',
      preenchido: 'esta preenchida',
      maior: 'e maior que'
   };

   var CAMPOS_CAIXA = ['somente_abertos', 'privado', 'silenciar'];
   var CAMPOS_DESTINO = ['ir_verdadeiro', 'ir_falso', 'ir_sim', 'ir_nao'];

   function elemento(tag, classe, texto) {
      var no = document.createElement(tag);
      if (classe) { no.className = classe; }
      if (texto !== undefined) { no.textContent = texto; }
      return no;
   }

   function novoId() {
      return 'p' + Math.random().toString(36).substring(2, 7);
   }

   // ============================================
   // Lista de fluxos
   // ============================================

   function carregarFluxos(selecionar) {
      pedir('fluxos_listar').then(function (resposta) {
         var caixa = document.getElementById('wae-lista-fluxos');
         if (!caixa) { return; }

         estado.fluxos = resposta.itens || [];

         var badge = document.getElementById('wae-badge-fluxos');
         if (badge) {
            badge.textContent = estado.fluxos.length + ' fluxo(s)';
         }

         caixa.innerHTML = '';

         if (!estado.fluxos.length) {
            caixa.appendChild(elemento('span', 'wae-vazio', 'Nenhum fluxo cadastrado.'));
            return;
         }

         estado.fluxos.forEach(function (fluxo, indice) {
            caixa.appendChild(montarItemFluxo(fluxo, indice));
         });

         if (selecionar) {
            abrirFluxo(selecionar);
         }
      });
   }

   function montarItemFluxo(fluxo, indice) {
      var item = elemento('div', 'wae-item-fluxo');
      item.setAttribute('data-id', fluxo.id);
      item.setAttribute('data-indice', indice);

      if (estado.atual && Number(estado.atual.id) === Number(fluxo.id)) {
         item.classList.add('wae-selecionado');
      }

      var alca = elemento('i', 'ti ti-list wae-arrasta');
      alca.setAttribute('title', 'Arraste para reordenar');
      item.appendChild(alca);

      var texto = elemento('div', 'wae-item-texto');
      texto.appendChild(elemento('span', 'wae-item-nome', fluxo.nome));

      var detalhe = fluxo.passos + ' passo(s)';
      if (fluxo.avisos > 0) { detalhe += ' | ' + fluxo.avisos + ' aviso(s)'; }
      texto.appendChild(elemento('span', 'wae-item-info', detalhe));
      item.appendChild(texto);

      var marca = elemento('span', 'wae-badge ' + (fluxo.is_ativo ? 'wae-badge-ok' : ''), fluxo.is_ativo ? 'ativo' : 'inativo');
      item.appendChild(marca);

      item.addEventListener('click', function () { abrirFluxo(fluxo.id); });

      if (podeEditar) {
         item.draggable = true;
         item.addEventListener('dragstart', function (evento) {
            evento.dataTransfer.setData('wae-fluxo', String(indice));
         });
         item.addEventListener('dragover', function (evento) { evento.preventDefault(); });
         item.addEventListener('drop', function (evento) {
            evento.preventDefault();
            var origem = parseInt(evento.dataTransfer.getData('wae-fluxo'), 10);
            if (isNaN(origem) || origem === indice) { return; }
            var movido = estado.fluxos.splice(origem, 1)[0];
            estado.fluxos.splice(indice, 0, movido);
            gravarOrdem();
         });
      }

      return item;
   }

   function gravarOrdem() {
      var ordem = estado.fluxos.map(function (fluxo) { return fluxo.id; });

      pedir('fluxos_reordenar', { ordem: JSON.stringify(ordem) }, 'POST').then(function (resposta) {
         avisar(resposta.mensagem, !resposta.sucesso);
         carregarFluxos(estado.atual ? estado.atual.id : null);
      });
   }

   // ============================================
   // Editor do fluxo
   // ============================================

   function abrirFluxo(id) {
      pedir('fluxo_obter', { id: id }).then(function (resposta) {
         if (!resposta.sucesso) {
            avisar(resposta.mensagem, true);
            return;
         }

         estado.atual = resposta.fluxo;
         estado.entradas = [];
         desenharEditor(resposta.avisos || []);
         desenharEntradas();
         limparRoteiro();

         document.querySelectorAll('.wae-item-fluxo').forEach(function (item) {
            item.classList.toggle('wae-selecionado', Number(item.getAttribute('data-id')) === Number(id));
         });
      });
   }

   function fluxoNovo() {
      estado.atual = {
         id: 0,
         nome: '',
         descricao: '',
         gatilho: 'menu',
         palavras: '',
         is_ativo: 0,
         ordem: estado.fluxos.length,
         passos: []
      };
      estado.entradas = [];
      desenharEditor([]);
      desenharEntradas();
      limparRoteiro();

      document.querySelectorAll('.wae-item-fluxo').forEach(function (item) {
         item.classList.remove('wae-selecionado');
      });

      var nome = document.getElementById('wae-fluxo-nome');
      if (nome) { nome.focus(); }
   }

   function desenharEditor(avisos) {
      var editor = document.getElementById('wae-editor');
      var vazio = document.getElementById('wae-editor-vazio');
      if (!editor) { return; }

      editor.classList.remove('wae-oculto');
      if (vazio) { vazio.classList.add('wae-oculto'); }

      document.getElementById('wae-fluxo-nome').value = estado.atual.nome || '';
      document.getElementById('wae-fluxo-descricao').value = estado.atual.descricao || '';
      document.getElementById('wae-fluxo-palavras').value = estado.atual.palavras || '';
      document.getElementById('wae-fluxo-ativo').checked = Number(estado.atual.is_ativo) === 1;

      var gatilho = document.getElementById('wae-fluxo-gatilho');
      gatilho.innerHTML = '';
      Object.keys(estado.catalogo.gatilhos).forEach(function (chave) {
         var opcao = elemento('option', null, estado.catalogo.gatilhos[chave]);
         opcao.value = chave;
         gatilho.appendChild(opcao);
      });
      gatilho.value = estado.atual.gatilho || 'menu';

      var badge = document.getElementById('wae-badge-editor');
      if (badge) {
         badge.textContent = estado.atual.id > 0 ? 'fluxo #' + estado.atual.id : 'novo fluxo';
      }

      mostrarAvisos(avisos);
      desenharPassos();
   }

   function mostrarAvisos(avisos) {
      var caixa = document.getElementById('wae-fluxo-avisos');
      if (!caixa) { return; }

      if (!avisos || !avisos.length) {
         caixa.classList.add('wae-oculto');
         caixa.innerHTML = '';
         return;
      }

      caixa.classList.remove('wae-oculto');
      caixa.innerHTML = avisos.map(function (aviso) {
         return '<div>' + escapar(aviso) + '</div>';
      }).join('');
   }

   function desenharPassos() {
      var lista = document.getElementById('wae-lista-passos');
      if (!lista) { return; }

      lista.innerHTML = '';

      if (!estado.atual.passos.length) {
         lista.appendChild(elemento('span', 'wae-vazio', 'Arraste um passo da paleta ao lado para comecar.'));
      } else {
         estado.atual.passos.forEach(function (passo, indice) {
            lista.appendChild(montarPasso(passo, indice));
         });
      }

      var contador = document.getElementById('wae-passos-contador');
      if (contador) {
         contador.textContent = estado.atual.passos.length + ' passo(s)';
      }
   }

   function montarPasso(passo, indice) {
      var tipos = estado.catalogo.tipos_passo;
      var meta = tipos[passo.tipo] || { rotulo: passo.tipo, icone: 'ti ti-file', campos: [], ajuda: '' };

      var caixa = elemento('div', 'wae-passo');
      caixa.setAttribute('data-indice', indice);

      var topo = elemento('div', 'wae-passo-topo');

      topo.appendChild(elemento('span', 'wae-passo-ordem', String(indice + 1)));

      var icone = elemento('i', meta.icone + ' wae-arrasta');
      icone.setAttribute('title', 'Arraste para reordenar');
      topo.appendChild(icone);

      var nome = elemento('div', 'wae-passo-nome');
      nome.appendChild(elemento('span', 'wae-passo-rotulo', passo.rotulo || meta.rotulo));
      nome.appendChild(elemento('span', 'wae-passo-tipo', meta.rotulo));
      topo.appendChild(nome);

      var acoes = elemento('div', 'wae-passo-acoes');

      var abrir = elemento('button', 'btn wae-btn wae-btn-neutro');
      abrir.type = 'button';
      abrir.title = 'Abrir ou fechar';
      abrir.innerHTML = '<i class="ti ti-chevron-down"></i>';
      abrir.addEventListener('click', function () { caixa.classList.toggle('wae-aberto'); });
      acoes.appendChild(abrir);

      if (podeEditar) {
         var subir = elemento('button', 'btn wae-btn wae-btn-neutro');
         subir.type = 'button';
         subir.title = 'Mover para cima';
         subir.innerHTML = '<i class="ti ti-arrow-up"></i>';
         subir.disabled = indice === 0;
         subir.addEventListener('click', function () { mover(indice, indice - 1); });
         acoes.appendChild(subir);

         var remover = elemento('button', 'btn wae-btn wae-btn-perigo');
         remover.type = 'button';
         remover.title = 'Remover passo';
         remover.innerHTML = '<i class="ti ti-trash"></i>';
         remover.addEventListener('click', function () {
            estado.atual.passos.splice(indice, 1);
            desenharPassos();
         });
         acoes.appendChild(remover);
      }

      topo.appendChild(acoes);
      caixa.appendChild(topo);

      var corpo = elemento('div', 'wae-passo-corpo');

      if (meta.ajuda) {
         corpo.appendChild(elemento('div', 'wae-alerta wae-alerta-secondary', meta.ajuda));
      }

      corpo.appendChild(campoTexto('Nome do passo no painel', passo.rotulo || '', function (valor) {
         passo.rotulo = valor;
         var alvo = caixa.querySelector('.wae-passo-rotulo');
         if (alvo) { alvo.textContent = valor || meta.rotulo; }
      }));

      (meta.campos || []).forEach(function (campo) {
         corpo.appendChild(montarCampo(passo, campo));
      });

      corpo.appendChild(montarCondicao(passo));

      caixa.appendChild(corpo);

      if (podeEditar) {
         caixa.draggable = true;
         caixa.addEventListener('dragstart', function (evento) {
            evento.dataTransfer.setData('wae-passo', String(indice));
            caixa.classList.add('wae-arrastando');
         });
         caixa.addEventListener('dragend', function () {
            caixa.classList.remove('wae-arrastando');
         });
      }

      return caixa;
   }

   function mover(de, para) {
      if (para < 0 || para >= estado.atual.passos.length) { return; }
      var movido = estado.atual.passos.splice(de, 1)[0];
      estado.atual.passos.splice(para, 0, movido);
      desenharPassos();
   }

   // ============================================
   // Campos de configuracao de cada passo
   // ============================================

      function variaveisAnteriores(passo) {
      var lista = [];
      var passos = (estado.atual && estado.atual.passos) || [];
      var vistas = [];

      for (var i = 0; i < passos.length; i++) {
         if (passos[i] === passo) { break; }

         var tipo = passos[i].tipo;
         if (tipo !== 'pergunta' && tipo !== 'sim_nao' && tipo !== 'listar_objetos') { continue; }

         var variavel = (passos[i].config && passos[i].config.variavel) || '';
         var nome = (i + 1) + '. ' + (passos[i].rotulo || tipo);

         if (!variavel) {
            lista.push({ id: '_semvariavel_' + i, nome: nome + '  (defina a variavel)', bloqueado: true });
            continue;
         }

         if (vistas.indexOf(variavel) >= 0) { continue; }

         vistas.push(variavel);
         lista.push({ id: variavel, nome: nome });
      }

      return lista;
   }

   function montarCondicao(passo) {
      var config = passo.config || (passo.config = {});
      var salva = config.se_variavel || '';
      var itens = variaveisAnteriores(passo);
      var conhecida = false;

      itens.forEach(function (item) {
         if (!item.bloqueado && item.id === salva) { conhecida = true; }
      });

      // Variavel gravada que saiu da lista continua selecionada, nunca e apagada
      if (salva !== '' && !conhecida) {
         itens.push({ id: salva, nome: 'Variavel gravada: ' + salva });
      }

      var caixa = elemento('div', 'wae-condicao');
      caixa.appendChild(elemento('span', 'wae-condicao-titulo', 'Visivel se'));

      if (!itens.length) {
         caixa.appendChild(elemento('span', 'wae-ajuda', 'Nenhuma pergunta anterior para condicionar este passo.'));
         return caixa;
      }

      var linha = elemento('div', 'wae-condicao-linha');

      var alvo = elemento('select', 'form-select form-select-sm');
      alvo.disabled = !podeEditar;

      var sempre = elemento('option', null, 'Sempre visivel');
      sempre.value = '';
      alvo.appendChild(sempre);

      itens.forEach(function (item) {
         var opcao = elemento('option', null, item.nome);
         opcao.value = String(item.id);
         if (item.bloqueado) { opcao.disabled = true; }
         alvo.appendChild(opcao);
      });

      alvo.value = salva;
      linha.appendChild(alvo);

      var operador = elemento('select', 'form-select form-select-sm');
      Object.keys(OPERADORES).forEach(function (chave) {
         var opcao = elemento('option', null, OPERADORES[chave]);
         opcao.value = chave;
         operador.appendChild(opcao);
      });
      operador.value = config.se_operador || 'igual';
      linha.appendChild(operador);

      var valor = elemento('input', 'form-control form-control-sm');
      valor.type = 'text';
      valor.placeholder = 'Resposta esperada';
      valor.value = config.se_valor || '';
      linha.appendChild(valor);

      function ajustar() {
         var semAlvo = alvo.value === '';
         var semValor = semAlvo || operador.value === 'vazio' || operador.value === 'preenchido';
         operador.disabled = !podeEditar || semAlvo;
         valor.disabled = !podeEditar || semValor;
      }

      alvo.addEventListener('change', function () {
         config.se_variavel = alvo.value;

         if (alvo.value === '') {
            config.se_operador = '';
            config.se_valor = '';
            valor.value = '';
         } else if (!config.se_operador) {
            config.se_operador = operador.value || 'igual';
         }

         ajustar();
      });

      operador.addEventListener('change', function () {
         config.se_operador = operador.value;
         ajustar();
      });

      valor.addEventListener('input', function () {
         config.se_valor = valor.value;
      });

      ajustar();

      caixa.appendChild(linha);
      caixa.appendChild(elemento('span', 'wae-ajuda', 'Fora da condicao, o passo e ignorado e o fluxo segue no proximo.'));

      return caixa;
   }

   function envelope(rotulo) {
      var caixa = elemento('div', 'wae-passo-campo');
      caixa.appendChild(elemento('label', 'wae-label', rotulo));
      return caixa;
   }

   function campoTexto(rotulo, valor, aoMudar) {
      var caixa = envelope(rotulo);
      var campo = elemento('input', 'form-control form-control-sm');
      campo.type = 'text';
      campo.value = valor;
      campo.disabled = !podeEditar;
      campo.addEventListener('input', function () { aoMudar(campo.value); });
      caixa.appendChild(campo);
      return caixa;
   }

   function montarCampo(passo, campo) {
      var config = passo.config || (passo.config = {});
      var rotulo = ROTULOS[campo] || campo;

      if (campo === 'texto' || campo === 'texto_ok' || campo === 'modelo_descricao') {
         var caixa = envelope(rotulo);
         var area = elemento('textarea', 'form-control wae-textarea');
         area.rows = 3;
         area.value = config[campo] || '';
         area.disabled = !podeEditar;
         area.addEventListener('input', function () { config[campo] = area.value; });
         caixa.appendChild(area);
         caixa.appendChild(elemento('span', 'wae-ajuda', 'Use {variavel} para inserir o valor coletado.'));
         return caixa;
      }

      if (campo === 'minimo') {
         var numero = envelope(rotulo);
         var entrada = elemento('input', 'form-control form-control-sm wae-input-curto');
         entrada.type = 'number';
         entrada.min = '1';
         entrada.value = config[campo] || '2';
         entrada.disabled = !podeEditar;
         entrada.addEventListener('input', function () { config[campo] = entrada.value; });
         numero.appendChild(entrada);
         return numero;
      }

      if (CAMPOS_CAIXA.indexOf(campo) >= 0) {
         var linha = elemento('div', 'wae-switch-linha');
         var grupo = elemento('div', 'form-check form-switch wae-switch');
         var marca = elemento('input', 'form-check-input');
         marca.type = 'checkbox';
         marca.setAttribute('role', 'switch');
         marca.id = 'wae-campo-' + passo.id + '-' + campo;
         marca.checked = String(config[campo] || '') === '1';
         marca.disabled = !podeEditar;
         marca.addEventListener('change', function () { config[campo] = marca.checked ? '1' : ''; });
         var texto = elemento('label', 'form-check-label', rotulo);
         texto.setAttribute('for', marca.id);
         grupo.appendChild(marca);
         grupo.appendChild(texto);
         linha.appendChild(grupo);
         return linha;
      }

      if (campo === 'objeto') {
         return campoSelecao(rotulo, config, campo, mapaParaLista(estado.catalogo.objetos), 'Ticket');
      }

      if (campo === 'operador') {
         return campoSelecao(rotulo, config, campo, mapaParaLista(OPERADORES), 'igual');
      }

      if (campo === 'tipo') {
         return campoSelecao(rotulo, config, campo, estado.catalogo.tipos, '1');
      }

      if (campo === 'urgencia') {
         return campoSelecao(rotulo, config, campo, estado.catalogo.urgencias, '3');
      }

      if (campo === 'categoria') {
         return campoSelecao(rotulo, config, campo, comVazio(estado.catalogo.categorias, 'Nenhuma'), '0');
      }

      if (campo === 'sla') {
         return campoSelecao(rotulo, config, campo, comVazio(estado.catalogo.slas, 'Nenhum'), '0');
      }

      if (campo === 'grupo') {
         return campoSelecao(rotulo, config, campo, comVazio(estado.catalogo.grupos, 'Nenhum'), '0');
      }

      if (CAMPOS_DESTINO.indexOf(campo) >= 0) {
         return campoSelecao(rotulo, config, campo, destinos(), '');
      }

      if (campo === 'opcoes') {
         return montarOpcoes(passo, config);
      }

      // variavel, variavel_titulo, variavel_descricao, variavel_texto, valor
      return campoTexto(rotulo, config[campo] || '', function (valor) { config[campo] = valor; });
   }

   function mapaParaLista(mapa) {
      return Object.keys(mapa).map(function (chave) {
         return { id: chave, nome: mapa[chave] };
      });
   }

   function comVazio(lista, rotulo) {
      return [{ id: '0', nome: rotulo }].concat(lista || []);
   }

   function destinos() {
      var lista = [{ id: '', nome: 'Passo seguinte da lista' }];

      estado.atual.passos.forEach(function (passo, indice) {
         lista.push({ id: passo.id, nome: (indice + 1) + '. ' + (passo.rotulo || passo.tipo) });
      });

      return lista;
   }

   function campoSelecao(rotulo, config, campo, itens, padrao) {
      var caixa = envelope(rotulo);
      var selecao = elemento('select', 'form-select form-select-sm');
      selecao.disabled = !podeEditar;

      itens.forEach(function (item) {
         var opcao = elemento('option', null, item.nome);
         opcao.value = String(item.id);
         selecao.appendChild(opcao);
      });

      var atual = config[campo];
      selecao.value = (atual === undefined || atual === null || atual === '') ? String(padrao) : String(atual);

      selecao.addEventListener('change', function () { config[campo] = selecao.value; });
      caixa.appendChild(selecao);
      return caixa;
   }

   function montarOpcoes(passo, config) {
      var caixa = envelope(ROTULOS.opcoes);

      if (!Array.isArray(config.opcoes)) {
         config.opcoes = [];
      }

      var lista = elemento('div');

      function redesenhar() {
         lista.innerHTML = '';

         config.opcoes.forEach(function (opcao, indice) {
            var linha = elemento('div', 'wae-opcao-linha');

            var rotulo = elemento('input', 'form-control form-control-sm');
            rotulo.type = 'text';
            rotulo.maxLength = 24;
            rotulo.placeholder = 'Texto do botao';
            rotulo.value = opcao.rotulo || '';
            rotulo.disabled = !podeEditar;
            rotulo.addEventListener('input', function () { opcao.rotulo = rotulo.value; });
            linha.appendChild(rotulo);

            var seta = elemento('i', 'ti ti-exchange');
            linha.appendChild(seta);

            var destino = elemento('select', 'form-select form-select-sm');
            destino.disabled = !podeEditar;
            destinos().forEach(function (item) {
               var opt = elemento('option', null, item.nome);
               opt.value = String(item.id);
               destino.appendChild(opt);
            });
            destino.value = opcao.ir || '';
            destino.addEventListener('change', function () { opcao.ir = destino.value; });
            linha.appendChild(destino);

            if (podeEditar) {
               var remover = elemento('button', 'btn wae-btn wae-btn-perigo');
               remover.type = 'button';
               remover.innerHTML = '<i class="ti ti-x"></i>';
               remover.title = 'Remover opcao';
               remover.addEventListener('click', function () {
                  config.opcoes.splice(indice, 1);
                  redesenhar();
               });
               linha.appendChild(remover);
            }

            lista.appendChild(linha);
         });

         if (!config.opcoes.length) {
            lista.appendChild(elemento('span', 'wae-ajuda', 'Nenhuma opcao cadastrada.'));
         }
      }

      redesenhar();
      caixa.appendChild(lista);

      if (podeEditar) {
         var adicionar = elemento('button', 'btn wae-btn wae-btn-neutro');
         adicionar.type = 'button';
         adicionar.innerHTML = '<i class="ti ti-plus"></i> <span>Adicionar opcao</span>';
         adicionar.addEventListener('click', function () {
            if (config.opcoes.length >= 10) {
               avisar('Um menu aceita no maximo 10 opcoes.', true);
               return;
            }
            config.opcoes.push({ rotulo: '', ir: '' });
            redesenhar();
         });

         var barra = elemento('div', 'wae-barra-botoes');
         barra.appendChild(adicionar);
         caixa.appendChild(barra);
      }

      return caixa;
   }

   // ============================================
   // Paleta de passos
   // ============================================

   function desenharPaleta() {
      var caixa = document.getElementById('wae-paleta');
      if (!caixa) { return; }

      caixa.innerHTML = '';

      Object.keys(estado.catalogo.tipos_passo).forEach(function (tipo) {
         var meta = estado.catalogo.tipos_passo[tipo];

         var item = elemento('div', 'wae-item-paleta');
         item.appendChild(elemento('i', meta.icone));

         var texto = elemento('div', 'wae-item-texto');
         texto.appendChild(elemento('span', 'wae-item-nome', meta.rotulo));
         item.appendChild(texto);

         if (podeEditar) {
            item.draggable = true;
            item.addEventListener('dragstart', function (evento) {
               evento.dataTransfer.setData('wae-novo', tipo);
            });
            item.addEventListener('click', function () { adicionarPasso(tipo, -1); });
         }

         caixa.appendChild(item);
      });
   }

   function adicionarPasso(tipo, posicao) {
      if (!estado.atual) {
         avisar('Selecione ou crie um fluxo antes de adicionar passos.', true);
         return;
      }

      var meta = estado.catalogo.tipos_passo[tipo];
      if (!meta) { return; }

      var passo = { id: novoId(), tipo: tipo, rotulo: meta.rotulo, config: {} };

      if (posicao < 0 || posicao >= estado.atual.passos.length) {
         estado.atual.passos.push(passo);
      } else {
         estado.atual.passos.splice(posicao, 0, passo);
      }

      desenharPassos();
   }

   function posicaoDoPonteiro(lista, y) {
      var passos = Array.prototype.slice.call(lista.querySelectorAll('.wae-passo'));

      for (var i = 0; i < passos.length; i++) {
         var area = passos[i].getBoundingClientRect();
         if (y < area.top + (area.height / 2)) {
            return i;
         }
      }

      return passos.length;
   }

   function ligarArrasteDaLista() {
      var lista = document.getElementById('wae-lista-passos');
      if (!lista || !podeEditar) { return; }

      lista.addEventListener('dragover', function (evento) {
         evento.preventDefault();
         lista.classList.add('wae-solta');
      });

      lista.addEventListener('dragleave', function () {
         lista.classList.remove('wae-solta');
      });

      lista.addEventListener('drop', function (evento) {
         evento.preventDefault();
         lista.classList.remove('wae-solta');

         var destino = posicaoDoPonteiro(lista, evento.clientY);

         var novo = evento.dataTransfer.getData('wae-novo');
         if (novo) {
            adicionarPasso(novo, destino);
            return;
         }

         var origem = parseInt(evento.dataTransfer.getData('wae-passo'), 10);
         if (isNaN(origem)) { return; }

         if (destino > origem) { destino--; }
         if (destino === origem) { return; }

         mover(origem, destino);
      });
   }

   // ============================================
   // Gravacao
   // ============================================

   function coletar() {
      return {
         id: estado.atual.id,
         nome: document.getElementById('wae-fluxo-nome').value.trim(),
         descricao: document.getElementById('wae-fluxo-descricao').value.trim(),
         gatilho: document.getElementById('wae-fluxo-gatilho').value,
         palavras: document.getElementById('wae-fluxo-palavras').value.trim(),
         is_ativo: document.getElementById('wae-fluxo-ativo').checked ? 1 : 0,
         ordem: estado.atual.ordem || 0,
         passos: estado.atual.passos
      };
   }

   function salvar() {
      if (!estado.atual) { return; }

      var dados = coletar();

      if (dados.nome === '') {
         avisar('Informe o nome do fluxo.', true);
         return;
      }

      pedir('fluxo_salvar', { dados: JSON.stringify(dados) }, 'POST').then(function (resposta) {
         avisar(resposta.mensagem, !resposta.sucesso);
         if (!resposta.sucesso) { return; }

         estado.atual.id = resposta.id;
         mostrarAvisos(resposta.avisos || []);
         carregarFluxos(resposta.id);
      });
   }

   function ligarBotoes() {
      var novo = document.getElementById('wae-fluxo-novo');
      if (novo) { novo.addEventListener('click', fluxoNovo); }

      var gravar = document.getElementById('wae-fluxo-salvar');
      if (gravar) { gravar.addEventListener('click', salvar); }

      var duplicar = document.getElementById('wae-fluxo-duplicar');
      if (duplicar) {
         duplicar.addEventListener('click', function () {
            if (!estado.atual || !estado.atual.id) {
               avisar('Salve o fluxo antes de duplicar.', true);
               return;
            }
            pedir('fluxo_duplicar', { id: estado.atual.id }, 'POST').then(function (resposta) {
               avisar(resposta.mensagem, !resposta.sucesso);
               if (resposta.sucesso) { carregarFluxos(resposta.id); }
            });
         });
      }

      var remover = document.getElementById('wae-fluxo-remover');
      if (remover) {
         remover.addEventListener('click', function () {
            if (!estado.atual || !estado.atual.id) {
               avisar('Nenhum fluxo gravado selecionado.', true);
               return;
            }
            WAE.confirmar('Remover este fluxo? Ele deixa de aparecer no WhatsApp.').then(function (ok) {
               if (!ok) { return; }
               return pedir('fluxo_remover', { id: estado.atual.id }, 'POST');
            }).then(function (resposta) {
               if (!resposta) { return; }
               avisar(resposta.mensagem, !resposta.sucesso);
               estado.atual = null;

               var editor = document.getElementById('wae-editor');
               var vazio = document.getElementById('wae-editor-vazio');
               if (editor) { editor.classList.add('wae-oculto'); }
               if (vazio) { vazio.classList.remove('wae-oculto'); }

               carregarFluxos(null);
            });
         });
      }
   }

   // ============================================
   // Teste do fluxo
   // ============================================

   function desenharEntradas() {
      var caixa = document.getElementById('wae-teste-entradas');
      if (!caixa) { return; }

      caixa.innerHTML = '';

      if (!estado.entradas.length) {
         caixa.appendChild(elemento('span', 'wae-ajuda', 'Sem respostas simuladas: o teste vai apenas ate a primeira pergunta.'));
         return;
      }

      estado.entradas.forEach(function (entrada, indice) {
         var etiqueta = elemento('span', 'wae-etiqueta');
         etiqueta.appendChild(elemento('span', null, (indice + 1) + '. ' + entrada));

         var remover = elemento('button', null, '\u00d7');
         remover.type = 'button';
         remover.title = 'Remover';
         remover.addEventListener('click', function () {
            estado.entradas.splice(indice, 1);
            desenharEntradas();
         });

         etiqueta.appendChild(remover);
         caixa.appendChild(etiqueta);
      });
   }

   function limparRoteiro() {
      var caixa = document.getElementById('wae-roteiro');
      if (caixa) {
         caixa.innerHTML = '';
         caixa.appendChild(elemento('span', 'wae-vazio', 'Rode o teste para ver a conversa simulada.'));
      }
   }

   function desenharRoteiro(roteiro) {
      var caixa = document.getElementById('wae-roteiro');
      if (!caixa) { return; }

      caixa.innerHTML = '';

      if (!roteiro || !roteiro.length) {
         caixa.appendChild(elemento('span', 'wae-vazio', 'O fluxo nao produziu nenhuma mensagem.'));
         return;
      }

      var nomes = { servidor: 'Servidor', cliente: 'Cliente', aviso: 'Aviso', erro: 'Erro' };

      roteiro.forEach(function (fala) {
         var bloco = elemento('div', 'wae-fala wae-fala-' + fala.quem);
         bloco.appendChild(elemento('span', 'wae-fala-quem', nomes[fala.quem] || fala.quem));
         bloco.appendChild(elemento('span', null, fala.texto));

         if (fala.botoes && fala.botoes.length) {
            var barra = elemento('div', 'wae-fala-botoes');
            fala.botoes.forEach(function (botao) {
               barra.appendChild(elemento('span', 'wae-fala-botao', botao));
            });
            bloco.appendChild(barra);
         }

         caixa.appendChild(bloco);
      });
   }

   function ligarTeste() {
      var campo = document.getElementById('wae-teste-entrada');

      var adicionar = document.getElementById('wae-teste-adicionar');
      if (adicionar) {
         adicionar.addEventListener('click', function () {
            var valor = campo ? campo.value.trim() : '';
            if (valor === '') { return; }
            estado.entradas.push(valor);
            campo.value = '';
            desenharEntradas();
         });
      }

      if (campo) {
         campo.addEventListener('keydown', function (evento) {
            if (evento.key !== 'Enter') { return; }
            evento.preventDefault();
            if (adicionar) { adicionar.click(); }
         });
      }

      var limpar = document.getElementById('wae-teste-limpar');
      if (limpar) {
         limpar.addEventListener('click', function () {
            estado.entradas = [];
            desenharEntradas();
            limparRoteiro();
         });
      }

      var rodar = document.getElementById('wae-teste-rodar');
      if (rodar) {
         rodar.addEventListener('click', function () {
            if (!estado.atual || !estado.atual.id) {
               avisar('Salve o fluxo antes de testar.', true);
               return;
            }

            rodar.disabled = true;

            pedir('fluxo_testar', {
               id: estado.atual.id,
               entradas: JSON.stringify(estado.entradas)
            }, 'POST').then(function (resposta) {
               rodar.disabled = false;

               if (!resposta.sucesso) {
                  avisar(resposta.mensagem || 'Nao foi possivel testar.', true);
                  return;
               }

               desenharRoteiro(resposta.roteiro);
               mostrarAvisos(resposta.avisos || []);
               avisar(resposta.mensagem);
            });
         });
      }
   }

   // ============================================
   // Inicio
   // ============================================

   function iniciar() {
      pedir('fluxo_catalogo').then(function (resposta) {
         if (!resposta.sucesso) {
            avisar('Nao foi possivel carregar o catalogo de passos.', true);
            return;
         }

         estado.catalogo = resposta.catalogo;

         desenharPaleta();
         ligarArrasteDaLista();
         ligarBotoes();
         ligarTeste();
         carregarFluxos(null);
      });
   }

   window.WAEFluxos = { iniciar: iniciar };
})();
