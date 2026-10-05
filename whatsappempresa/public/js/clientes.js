/**
 * whatsappempresa - aba Clientes: entidades do autoatendimento, codigos de acesso e contatos.
 *
 * As alteracoes ficam como rascunho na tela ate o botao Salvar (do cliente ou "Salvar tudo").
 * O servidor valida o cliente inteiro antes de gravar: com qualquer erro nada e gravado e a
 * linha com problema fica destacada.
 */
(function () {
   'use strict';

   var raiz = null;
   var podeEditar = false;
   var clientes = [];
   var categorias = [];
   var botoesDisponivel = false;
   var sujos = {};          // entities_id -> true quando ha alteracao nao salva
   var removidos = {};      // entities_id -> { codigos: [ids], contatos: [ids] }

   function e(texto) { return WAE.escapar(texto); }

   function ajuda(texto) {
      return ' <span class="wae-ajuda" tabindex="0" title="' + e(texto) + '"><i class="ti ti-info-circle"></i></span>';
   }

   /** <option>s de uma lista [{id, nome}] com a primeira opcao "vazia" */
   function opcoes(lista, atual, rotuloVazio, campoValor) {
      campoValor = campoValor || 'id';
      return '<option value="' + (campoValor === 'id' ? '0' : '') + '">' + e(rotuloVazio) + '</option>' +
         lista.map(function (item) {
            var valor = String(item[campoValor]);
            return '<option value="' + e(valor) + '"' + (valor === String(atual) ? ' selected' : '') + '>' + e(item.nome) + '</option>';
         }).join('');
   }

   /** Setor: lista do plugin Botoes quando houver; sem lista, texto livre (maiusculas, como no Botoes) */
   function campoSetor(cliente, atual, rotuloVazio, atributos, pequeno) {
      var setores = (cliente.botoes && cliente.botoes.setores) || [];
      var tamanho = pequeno ? ' form-select-sm' : '';
      if (setores.length) {
         return '<select class="form-select' + tamanho + '" ' + atributos + '>' + opcoes(setores, atual, rotuloVazio, 'nome') + '</select>';
      }
      return '<input type="text" class="form-control' + (pequeno ? ' form-control-sm' : '') + ' text-uppercase" ' + atributos +
         ' value="' + e(atual) + '" placeholder="' + e(rotuloVazio) + '" maxlength="255">';
   }

   function formatarTelefone(valor) {
      var d = String(valor || '').replace(/\D/g, '');
      if (d.length > 11 && d.indexOf('55') === 0) { d = d.slice(2); }
      d = d.slice(0, 11);
      if (d.length <= 2) { return d.length ? '(' + d : ''; }
      if (d.length <= 6) { return '(' + d.slice(0, 2) + ') ' + d.slice(2); }
      if (d.length <= 10) { return '(' + d.slice(0, 2) + ') ' + d.slice(2, 6) + '-' + d.slice(6); }
      return '(' + d.slice(0, 2) + ') ' + d.slice(2, 7) + '-' + d.slice(7);
   }

   function nomeNaLista(lista, id) {
      for (var i = 0; i < lista.length; i++) {
         if (String(lista[i].id) === String(id)) { return lista[i].nome; }
      }
      return '';
   }

   function acharCliente(entities_id) {
      for (var i = 0; i < clientes.length; i++) {
         if (String(clientes[i].entities_id) === String(entities_id)) { return clientes[i]; }
      }
      return null;
   }

   function itemDe(entities_id) {
      return raiz.querySelector('.wae-cli-item[data-cliente="' + entities_id + '"]');
   }

   function ativarDicas(alvo) {
      if (!window.bootstrap || !window.bootstrap.Tooltip) { return; }
      alvo.querySelectorAll('.wae-ajuda[title]').forEach(function (el) {
         window.bootstrap.Tooltip.getOrCreateInstance(el, { placement: 'top' });
      });
   }

   // ============================================
   // Desenho
   // ============================================

   function htmlResumo(c) {
      var ativos = c.codigos.filter(function (x) { return x.is_ativo; }).map(function (x) { return x.codigo; });
      var partes = [
         '<span><i class="ti ti-key"></i>' + (ativos.length ? ativos.map(e).join(', ') : '<span class="text-warning">sem código ativo</span>') + '</span>',
         '<span><i class="ti ti-address-book"></i>' + c.contatos.length + ' contato(s)</span>',
         '<span><i class="ti ti-user"></i>' + (c.requerente ? e(c.requerente) : '<span class="text-warning">sem requerente padrão</span>') + '</span>'
      ];
      if (c.itilcategories_id) {
         partes.push('<span><i class="ti ti-category"></i>' + e(nomeNaLista(categorias, c.itilcategories_id)) + '</span>');
      }
      if (botoesDisponivel && c.usar_botoes) {
         var unidade = nomeNaLista((c.botoes && c.botoes.unidades) || [], c.unidade_id);
         partes.push('<span><i class="ti ti-building-community"></i>' + e(unidade || 'sem unidade') + (c.setor ? ' · ' + e(c.setor) : '') + '</span>');
      }
      return partes.join('');
   }

   function htmlItem(c) {
      var dis = podeEditar ? '' : ' disabled';
      return '<div class="wae-cli-item' + (c.is_ativo ? '' : ' wae-cli-inativo') + (botoesDisponivel && c.usar_botoes ? ' wae-cli-com-botoes' : '') +
         '" data-cliente="' + c.entities_id + '">' +
         '<div class="wae-cli-topo" data-alternar>' +
            '<span class="wae-cli-avatar"><i class="ti ti-building"></i></span>' +
            '<div class="wae-cli-info">' +
               '<div class="wae-cli-nome">' + e(c.entidade) +
                  '<span class="badge bg-secondary-lt wae-cli-selo-inativo">Inativo</span>' +
                  '<span class="badge bg-yellow-lt wae-cli-selo-sujo"><i class="ti ti-pencil me-1"></i>Não salvo</span>' +
               '</div>' +
               '<div class="wae-cli-resumo">' + htmlResumo(c) + '</div>' +
            '</div>' +
            '<label class="form-check form-switch m-0" title="Cliente ativo no autoatendimento" data-parar>' +
               '<input class="form-check-input" type="checkbox" data-cli="is_ativo"' + (c.is_ativo ? ' checked' : '') + dis + '>' +
            '</label>' +
            (podeEditar ? '<button type="button" class="btn btn-sm btn-ghost-danger" data-cli-remover title="Remover cliente" data-parar><i class="ti ti-trash"></i></button>' : '') +
            '<i class="ti ti-chevron-down wae-cli-seta"></i>' +
         '</div>' +
         '<div class="wae-cli-corpo"></div>' +
      '</div>';
   }

   function linhaCodigo(c, k) {
      var dis = podeEditar ? '' : ' disabled';
      var unidades = (c.botoes && c.botoes.unidades) || [];
      return '<tr data-codigo-linha data-id="' + (k.id || 0) + '">' +
         '<td><input type="text" class="form-control form-control-sm font-monospace" data-f="codigo" value="' + e(k.codigo) + '" maxlength="60"' + dis + '></td>' +
         '<td><input type="text" class="form-control form-control-sm" data-f="descricao" value="' + e(k.descricao) + '" placeholder="Descrição"' + dis + '></td>' +
         '<td><select class="form-select form-select-sm" data-f="itilcategories_id"' + dis + '>' + opcoes(categorias, k.itilcategories_id, 'Do cliente') + '</select></td>' +
         '<td class="wae-so-botoes"><select class="form-select form-select-sm" data-f="unidade_id"' + dis + '>' + opcoes(unidades, k.unidade_id, 'Do cliente') + '</select></td>' +
         '<td class="wae-so-botoes">' + campoSetor(c, k.setor, 'Do cliente', 'data-f="setor"' + dis, true) + '</td>' +
         '<td class="text-center"><label class="form-check form-switch m-0 d-inline-block"><input class="form-check-input" type="checkbox" data-f="is_ativo"' + (k.is_ativo ? ' checked' : '') + dis + '></label></td>' +
         '<td class="text-end">' + (podeEditar ? '<button type="button" class="btn btn-sm btn-ghost-danger" data-remover-linha title="Remover"><i class="ti ti-x"></i></button>' : '') + '</td>' +
      '</tr>';
   }

   function linhaContato(k) {
      var dis = podeEditar ? '' : ' disabled';
      return '<tr data-contato-linha data-id="' + (k.id || 0) + '">' +
         '<td><input type="text" class="form-control form-control-sm" data-f="nome" value="' + e(k.nome) + '" placeholder="Nome" maxlength="100"' + dis + '></td>' +
         '<td><input type="text" class="form-control form-control-sm" data-f="telefone" data-mascara value="' + e(formatarTelefone(k.telefone)) + '" placeholder="(71) 99999-9999"' + dis + '></td>' +
         '<td class="text-center"><label class="form-check form-switch m-0 d-inline-block"><input class="form-check-input" type="checkbox" data-f="is_ativo"' + (k.is_ativo ? ' checked' : '') + dis + '></label></td>' +
         '<td class="text-end">' + (podeEditar ? '<button type="button" class="btn btn-sm btn-ghost-danger" data-remover-linha title="Remover"><i class="ti ti-x"></i></button>' : '') + '</td>' +
      '</tr>';
   }

   function htmlCorpo(c) {
      var dis = podeEditar ? '' : ' disabled';
      var unidades = (c.botoes && c.botoes.unidades) || [];
      var setores = (c.botoes && c.botoes.setores) || [];

      var botoes = !botoesDisponivel
         ? '<div class="text-secondary small"><i class="ti ti-plug-off me-1"></i>Plugin Botões inativo.</div>'
         : '<div class="row g-3 wae-so-botoes">' +
              '<div class="col-md-6"><label class="form-label">Unidade' + ajuda('Unidades cadastradas para esta entidade em "Dados do cliente" do plugin Botões.') + '</label>' +
                 (unidades.length
                    ? '<select class="form-select" data-cli="unidade_id"' + dis + '>' + opcoes(unidades, c.unidade_id, 'Sem unidade') + '</select>'
                    : '<div class="form-control-plaintext text-warning small"><i class="ti ti-alert-triangle me-1"></i>Nenhuma unidade cadastrada no Botões.</div>') +
              '</div>' +
              '<div class="col-md-6"><label class="form-label">Setor' + ajuda(setores.length
                 ? 'Setores cadastrados para esta entidade no plugin Botões.'
                 : 'A entidade não tem setores no Botões: digite o setor (fica em maiúsculas, como no Botões).') + '</label>' +
                 campoSetor(c, c.setor, setores.length ? 'Sem setor' : 'Digite o setor', 'data-cli="setor"' + dis, false) +
              '</div>' +
           '</div>';

      return '' +
         '<div class="wae-cli-secao">' +
            '<div class="wae-cli-secao-titulo"><i class="ti ti-user-cog"></i>Atendimento</div>' +
            '<div class="row g-3">' +
               '<div class="col-lg-4"><label class="form-label">Requerente padrão' + ajuda('Usuário do GLPI em nome de quem são abertos os chamados dos contatos sem usuário próprio.') + '</label>' +
                  '<div data-requerente><span class="text-secondary small">Carregando...</span></div></div>' +
               '<div class="col-lg-4"><label class="form-label">Categoria dos chamados' + ajuda('Categoria dos chamados abertos pelo WhatsApp. Vazio: usa a da aba Parâmetros. Um bloco "Abrir registro" com categoria própria tem prioridade.') + '</label>' +
                  '<select class="form-select" data-cli="itilcategories_id"' + dis + '>' + opcoes(categorias, c.itilcategories_id, 'Padrão da aba Parâmetros') + '</select></div>' +
               '<div class="col-lg-4"><label class="form-label">Observação interna' + ajuda('Anotação visível só nesta tela.') + '</label>' +
                  '<input type="text" class="form-control" data-cli="observacao" value="' + e(c.observacao) + '" maxlength="255"' + dis + '></div>' +
            '</div>' +
         '</div>' +

         '<div class="wae-cli-secao">' +
            '<div class="wae-cli-secao-titulo"><i class="ti ti-building-community"></i>Unidade e Setor' +
               ajuda('Quando ligado, os chamados abertos pelo WhatsApp recebem Unidade e Setor nos campos adicionais do plugin Botões. Cada código pode usar outros.') +
               (botoesDisponivel ? '<label class="form-check form-switch m-0 ms-auto"><input class="form-check-input" type="checkbox" data-cli="usar_botoes"' + (c.usar_botoes ? ' checked' : '') + dis + '>' +
                  '<span class="form-check-label">Preencher nos chamados</span></label>' : '') +
            '</div>' +
            botoes +
         '</div>' +

         '<div class="wae-cli-secao">' +
            '<div class="wae-cli-secao-titulo"><i class="ti ti-key"></i>Códigos de acesso' +
               ajuda('O cliente envia o código no WhatsApp para ser atendido. Categoria, unidade e setor vazios no código usam os do cliente; um código por unidade faz cada equipe abrir chamados já na unidade certa.') +
               (podeEditar ? '<button type="button" class="btn btn-sm btn-outline-primary ms-auto" data-add-codigo><i class="ti ti-plus me-1"></i>Novo código</button>' : '') +
            '</div>' +
            '<div class="table-responsive"><table class="table table-sm table-vcenter wae-cli-tabela mb-0"><thead><tr>' +
               '<th>Código</th><th>Descrição</th><th>Categoria</th><th class="wae-so-botoes">Unidade</th><th class="wae-so-botoes">Setor</th><th class="text-center">Ativo</th><th></th>' +
            '</tr></thead><tbody data-codigos>' + c.codigos.map(function (k) { return linhaCodigo(c, k); }).join('') + '</tbody></table></div>' +
            '<div class="wae-cli-vazio" data-vazio-codigos' + (c.codigos.length ? ' hidden' : '') + '>Nenhum código.</div>' +
         '</div>' +

         '<div class="wae-cli-secao">' +
            '<div class="wae-cli-secao-titulo"><i class="ti ti-address-book"></i>Contatos' +
               ajuda('Pessoas atendidas pelo telefone mesmo sem usuário no GLPI. Os chamados saem em nome do requerente padrão, com o nome e o telefone do contato.') +
               (podeEditar ? '<button type="button" class="btn btn-sm btn-outline-primary ms-auto" data-add-contato><i class="ti ti-plus me-1"></i>Novo contato</button>' : '') +
            '</div>' +
            '<div class="table-responsive"><table class="table table-sm table-vcenter wae-cli-tabela mb-0"><thead><tr>' +
               '<th>Nome</th><th>Telefone (WhatsApp)</th><th class="text-center">Ativo</th><th></th>' +
            '</tr></thead><tbody data-contatos>' + c.contatos.map(linhaContato).join('') + '</tbody></table></div>' +
            '<div class="wae-cli-vazio" data-vazio-contatos' + (c.contatos.length ? ' hidden' : '') + '>Nenhum contato.</div>' +
         '</div>' +

         (podeEditar ?
            '<div class="wae-cli-rodape">' +
               '<span class="wae-cli-estado text-secondary small"><i class="ti ti-circle-check me-1"></i>Sem alterações</span>' +
               '<button type="button" class="btn btn-sm btn-outline-secondary ms-auto" data-descartar disabled><i class="ti ti-arrow-back-up me-1"></i>Descartar</button>' +
               '<button type="button" class="btn btn-sm wae-btn-salvar" data-salvar disabled><i class="ti ti-device-floppy me-1"></i>Salvar alterações</button>' +
            '</div>' : '');
   }

   function desenhar() {
      var lista = document.getElementById('wae-cli-lista');
      document.getElementById('wae-cli-total').textContent = clientes.length;

      if (!clientes.length) {
         lista.innerHTML = '<div class="wae-cli-vazio py-4"><i class="ti ti-building fs-1 d-block mb-2"></i>Nenhum cliente cadastrado.</div>';
         return;
      }

      lista.innerHTML = clientes.map(htmlItem).join('');
      ativarDicas(lista);
      filtrar();
   }

   /** Abre o cliente; o corpo e montado so na primeira vez e depois preservado (rascunho) */
   function abrir(item, abrirOuFechar) {
      var abrirAgora = abrirOuFechar !== undefined ? abrirOuFechar : !item.classList.contains('wae-cli-aberto');
      item.classList.toggle('wae-cli-aberto', abrirAgora);

      var corpo = item.querySelector('.wae-cli-corpo');
      if (abrirAgora && !corpo.hasAttribute('data-montado')) {
         var c = acharCliente(item.getAttribute('data-cliente'));
         corpo.innerHTML = htmlCorpo(c);
         corpo.setAttribute('data-montado', '1');
         ativarDicas(corpo);
         carregarRequerente(item, c.entities_id);
      }
   }

   /** Campo nativo de usuario do GLPI (select2) vindo do servidor */
   function carregarRequerente(item, entities_id) {
      var alvo = item.querySelector('[data-requerente]');
      // POST: o campo registra um token de seguranca na sessao
      WAE.pedir('cliente_requerente', { entities_id: entities_id }, 'POST').then(function (r) {
         if (!r.sucesso) { alvo.innerHTML = '<span class="text-danger small">Não foi possível carregar.</span>'; return; }
         $(alvo).html(r.html);
         if (!podeEditar) { $(alvo).find('select').prop('disabled', true); }
         $(alvo).find('select').on('change', function () { marcarSujo(item); });
      });
   }

   function filtrar() {
      var termo = (document.getElementById('wae-cli-busca').value || '').trim().toLowerCase();
      clientes.forEach(function (c) {
         var item = itemDe(c.entities_id);
         if (!item) { return; }
         var alvo = [c.entidade, c.requerente, c.observacao]
            .concat(c.codigos.map(function (k) { return k.codigo + ' ' + k.descricao; }))
            .concat(c.contatos.map(function (k) { return k.nome + ' ' + k.telefone; }))
            .join(' ').toLowerCase();
         item.hidden = !!termo && alvo.indexOf(termo) < 0;
      });
   }

   // ============================================
   // Rascunho e gravacao
   // ============================================

   function atualizarPendentes() {
      var total = Object.keys(sujos).length;
      var botao = document.getElementById('wae-cli-salvar-tudo');
      var selo = document.getElementById('wae-cli-pendentes');
      if (botao) { botao.disabled = total === 0; }
      if (selo) { selo.textContent = total; selo.classList.toggle('wae-oculto', total === 0); }
   }

   function marcarSujo(item) {
      if (!podeEditar) { return; }
      var id = item.getAttribute('data-cliente');
      sujos[id] = true;
      item.classList.add('wae-cli-sujo');
      item.querySelectorAll('[data-salvar], [data-descartar]').forEach(function (b) { b.disabled = false; });
      var estado = item.querySelector('.wae-cli-estado');
      if (estado) { estado.className = 'wae-cli-estado text-warning small'; estado.innerHTML = '<i class="ti ti-pencil me-1"></i>Alterações não salvas'; }
      atualizarPendentes();
   }

   function limparSujo(id) {
      delete sujos[id];
      delete removidos[id];
      atualizarPendentes();
   }

   function coletar(item) {
      var id = item.getAttribute('data-cliente');
      var corpo = item.querySelector('.wae-cli-corpo');
      var c = acharCliente(id);
      var montado = corpo.hasAttribute('data-montado');

      var cliente = { is_ativo: item.querySelector('[data-cli="is_ativo"]').checked ? 1 : 0 };

      if (montado) {
         var requerente = $(corpo).find('[data-requerente] select');
         if (requerente.length) { cliente.users_id_requerente = requerente.val() || 0; }
         corpo.querySelectorAll('[data-cli]').forEach(function (el) {
            var campo = el.getAttribute('data-cli');
            cliente[campo] = el.type === 'checkbox' ? (el.checked ? 1 : 0) : el.value;
         });
      }

      var linhas = function (seletor, campos) {
         if (!montado) { return null; }
         return Array.prototype.map.call(corpo.querySelectorAll(seletor), function (tr) {
            var dados = { id: parseInt(tr.getAttribute('data-id'), 10) || 0 };
            campos.forEach(function (f) {
               var el = tr.querySelector('[data-f="' + f + '"]');
               if (el) { dados[f] = el.type === 'checkbox' ? (el.checked ? 1 : 0) : el.value; }
            });
            return dados;
         });
      };

      // Corpo nunca aberto: codigos e contatos seguem como estao gravados
      var codigos = linhas('tr[data-codigo-linha]', ['codigo', 'descricao', 'itilcategories_id', 'unidade_id', 'setor', 'is_ativo']) || c.codigos;
      var contatos = linhas('tr[data-contato-linha]', ['nome', 'telefone', 'is_ativo']) || c.contatos;

      return {
         cliente: cliente,
         codigos: codigos,
         contatos: contatos,
         codigos_removidos: (removidos[id] || {}).codigos || [],
         contatos_removidos: (removidos[id] || {}).contatos || []
      };
   }

   function mostrarErros(item, erros) {
      item.querySelectorAll('.is-invalid').forEach(function (el) { el.classList.remove('is-invalid'); el.removeAttribute('title'); });
      var codigos = item.querySelectorAll('tr[data-codigo-linha]');
      var contatos = item.querySelectorAll('tr[data-contato-linha]');

      erros.forEach(function (erro) {
         var linha = erro.tipo === 'codigo' ? codigos[erro.indice] : (erro.tipo === 'contato' ? contatos[erro.indice] : null);
         if (!linha) { return; }
         var campo = linha.querySelector(erro.tipo === 'codigo' ? '[data-f="codigo"]' : (/telefone/i.test(erro.mensagem) ? '[data-f="telefone"]' : '[data-f="nome"]'));
         if (campo) { campo.classList.add('is-invalid'); campo.title = erro.mensagem; }
      });

      WAE.avisar(erros.map(function (x) { return x.mensagem; }).join('\n'), true);
   }

   function salvar(item) {
      var id = item.getAttribute('data-cliente');
      var botao = item.querySelector('[data-salvar]');
      if (botao) { botao.disabled = true; botao.innerHTML = '<i class="ti ti-loader-2 me-1"></i>Salvando...'; }

      return WAE.pedir('cliente_salvar_tudo', { entities_id: id, dados: JSON.stringify(coletar(item)) }, 'POST').then(function (r) {
         if (botao) { botao.innerHTML = '<i class="ti ti-device-floppy me-1"></i>Salvar alterações'; }

         if (!r.sucesso) {
            if (botao) { botao.disabled = false; }
            if (!item.classList.contains('wae-cli-aberto')) { abrir(item, true); }
            mostrarErros(item, r.erros || []);
            if (!(r.erros || []).length) { WAE.avisar(r.mensagem || 'Não foi possível salvar.', true); }
            return false;
         }

         limparSujo(id);
         return recarregarCliente(id).then(function () {
            WAE.avisar('Cliente "' + (acharCliente(id) || {}).entidade + '" salvo.');
            return true;
         });
      });
   }

   /** Busca os dados gravados e redesenha so este cliente (os rascunhos dos outros ficam) */
   function recarregarCliente(id) {
      return WAE.pedir('clientes_listar').then(function (r) {
         if (!r.sucesso) { return; }
         categorias = r.categorias || categorias;
         var novo = null;
         (r.itens || []).forEach(function (c) { if (String(c.entities_id) === String(id)) { novo = c; } });
         clientes = clientes.map(function (c) { return String(c.entities_id) === String(id) && novo ? novo : c; });

         var antigo = itemDe(id);
         if (!antigo || !novo) { return; }
         var aberto = antigo.classList.contains('wae-cli-aberto');
         var temp = document.createElement('div');
         temp.innerHTML = htmlItem(novo);
         var item = temp.firstChild;
         antigo.replaceWith(item);
         ativarDicas(item);
         if (aberto) { abrir(item, true); }
      });
   }

   function descartar(item) {
      var id = item.getAttribute('data-cliente');
      WAE.confirmar('Descartar as alterações não salvas deste cliente?').then(function (ok) {
         if (!ok) { return; }
         limparSujo(id);
         recarregarCliente(id);
      });
   }

   function salvarTudo() {
      var ids = Object.keys(sujos);
      var falhas = 0;
      var sequencia = Promise.resolve();
      ids.forEach(function (id) {
         sequencia = sequencia.then(function () {
            var item = itemDe(id);
            return item ? salvar(item).then(function (ok) { if (!ok) { falhas++; } }) : null;
         });
      });
      return sequencia.then(function () {
         if (ids.length > 1 && !falhas) { WAE.avisar(ids.length + ' clientes salvos.'); }
      });
   }

   function carregar() {
      return WAE.pedir('clientes_listar').then(function (r) {
         if (!r.sucesso) {
            WAE.avisar(r.mensagem || 'Não foi possível carregar os clientes.', true);
            return;
         }
         clientes = r.itens || [];
         categorias = r.categorias || [];
         botoesDisponivel = !!r.botoes_disponivel;
         sujos = {};
         removidos = {};
         atualizarPendentes();
         desenhar();
      });
   }

   // ============================================
   // Eventos
   // ============================================

   function ligarEventos() {
      document.getElementById('wae-cli-busca').addEventListener('input', filtrar);

      var salvarTudoBotao = document.getElementById('wae-cli-salvar-tudo');
      if (salvarTudoBotao) { salvarTudoBotao.addEventListener('click', salvarTudo); }

      var adicionar = document.getElementById('wae-cli-adicionar');
      if (adicionar) {
         adicionar.addEventListener('click', function () {
            var novo = document.getElementById('wae-cli-novo');
            var entidade = $(novo).find('select[name="entities_id"]').val();
            var requerente = $(novo).find('select[name="users_id_requerente"]').val() || 0;

            if (entidade === null || entidade === undefined || entidade === '' || parseInt(entidade, 10) < 0) {
               WAE.avisar('Escolha a entidade do cliente.', true);
               return;
            }

            adicionar.disabled = true;
            WAE.pedir('cliente_adicionar', { entities_id: entidade, users_id_requerente: requerente }, 'POST').then(function (r) {
               adicionar.disabled = false;
               WAE.avisar(r.mensagem || (r.sucesso ? 'Cliente cadastrado.' : 'Não foi possível cadastrar.'), !r.sucesso);
               if (!r.sucesso) { return; }
               $(novo).find('select[name="entities_id"]').val('-1').trigger('change');
               // Os rascunhos dos outros clientes continuam: so entra o novo
               WAE.pedir('clientes_listar').then(function (lista) {
                  var cliente = null;
                  (lista.itens || []).forEach(function (c) { if (String(c.entities_id) === String(r.entities_id)) { cliente = c; } });
                  if (!cliente) { return; }
                  clientes.push(cliente);
                  var temp = document.createElement('div');
                  temp.innerHTML = htmlItem(cliente);
                  var item = temp.firstChild;
                  var listaEl = document.getElementById('wae-cli-lista');
                  if (!listaEl.querySelector('.wae-cli-item')) { listaEl.innerHTML = ''; }
                  listaEl.insertBefore(item, listaEl.firstChild);
                  document.getElementById('wae-cli-total').textContent = clientes.length;
                  ativarDicas(item);
                  abrir(item, true);
               });
            });
         });
      }

      raiz.addEventListener('click', function (ev) {
         var alvo = ev.target;
         var item = alvo.closest('.wae-cli-item');
         if (!item) { return; }
         var id = item.getAttribute('data-cliente');

         if (alvo.closest('[data-cli-remover]')) {
            var c = acharCliente(id);
            WAE.confirmar('Remover o cliente "' + (c ? c.entidade : '') + '"? Os contatos serão apagados e os códigos desativados.').then(function (ok) {
               if (!ok) { return; }
               WAE.pedir('cliente_remover', { entities_id: id }, 'POST').then(function (r) {
                  WAE.avisar(r.mensagem || '', !r.sucesso);
                  if (!r.sucesso) { return; }
                  limparSujo(id);
                  clientes = clientes.filter(function (x) { return String(x.entities_id) !== String(id); });
                  item.remove();
                  document.getElementById('wae-cli-total').textContent = clientes.length;
               });
            });
            return;
         }

         if (alvo.closest('[data-parar]')) { return; }

         if (alvo.closest('[data-alternar]')) { abrir(item); return; }

         if (alvo.closest('[data-salvar]')) { salvar(item); return; }
         if (alvo.closest('[data-descartar]')) { descartar(item); return; }

         var cliente = acharCliente(id);

         if (alvo.closest('[data-add-codigo]')) {
            WAE.pedir('codigo_sugerir').then(function (r) {
               var corpo = item.querySelector('[data-codigos]');
               corpo.insertAdjacentHTML('beforeend', linhaCodigo(cliente, { id: 0, codigo: r.codigo || '', descricao: '', is_ativo: 1, itilcategories_id: 0, unidade_id: 0, setor: '' }));
               item.querySelector('[data-vazio-codigos]').hidden = true;
               corpo.lastElementChild.querySelector('[data-f="descricao"]').focus();
               marcarSujo(item);
            });
            return;
         }

         if (alvo.closest('[data-add-contato]')) {
            var tabela = item.querySelector('[data-contatos]');
            tabela.insertAdjacentHTML('beforeend', linhaContato({ id: 0, nome: '', telefone: '', is_ativo: 1 }));
            item.querySelector('[data-vazio-contatos]').hidden = true;
            tabela.lastElementChild.querySelector('[data-f="nome"]').focus();
            marcarSujo(item);
            return;
         }

         var remover = alvo.closest('[data-remover-linha]');
         if (remover) {
            var linha = remover.closest('tr');
            var linhaId = parseInt(linha.getAttribute('data-id'), 10) || 0;
            var ehCodigo = linha.hasAttribute('data-codigo-linha');
            if (linhaId > 0) {
               removidos[id] = removidos[id] || { codigos: [], contatos: [] };
               removidos[id][ehCodigo ? 'codigos' : 'contatos'].push(linhaId);
            }
            var tbody = linha.parentNode;
            linha.remove();
            item.querySelector(ehCodigo ? '[data-vazio-codigos]' : '[data-vazio-contatos]').hidden = tbody.children.length > 0;
            marcarSujo(item);
         }
      });

      // Qualquer alteracao vira rascunho
      raiz.addEventListener('input', function (ev) {
         var item = ev.target.closest('.wae-cli-item');
         if (ev.target.hasAttribute('data-mascara')) { ev.target.value = formatarTelefone(ev.target.value); }
         if (item) { marcarSujo(item); }
      });

      raiz.addEventListener('change', function (ev) {
         var item = ev.target.closest('.wae-cli-item');
         if (!item) { return; }
         var campo = ev.target.getAttribute('data-cli');
         if (campo === 'usar_botoes') {
            item.classList.toggle('wae-cli-com-botoes', ev.target.checked);
         }
         if (campo === 'is_ativo') {
            item.classList.toggle('wae-cli-inativo', !ev.target.checked);
         }
         marcarSujo(item);
      });
   }

   window.WAEClientes = {
      iniciar: function (id) {
         raiz = document.getElementById(id);
         if (!raiz || raiz.getAttribute('data-iniciado')) { return; }
         raiz.setAttribute('data-iniciado', '1');
         podeEditar = raiz.getAttribute('data-edita') === '1';
         ativarDicas(raiz);
         ligarEventos();
         carregar();
      }
   };
})();
