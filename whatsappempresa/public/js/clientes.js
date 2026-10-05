/**
 * whatsappempresa - aba Clientes: entidades do autoatendimento, codigos de acesso e contatos.
 * Cada alteracao e gravada na hora (sem botao salvar) e vale na proxima mensagem recebida.
 */
(function () {
   'use strict';

   var raiz = null;
   var podeEditar = false;
   var clientes = [];
   var abertos = {};
   var categorias = [];
   var botoesDisponivel = false;

   function e(texto) { return WAE.escapar(texto); }

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
   function campoSetor(cliente, atual, rotuloVazio, atributos) {
      var setores = (cliente.botoes && cliente.botoes.setores) || [];
      if (setores.length) {
         return '<select class="form-select form-select-sm" ' + atributos + '>' + opcoes(setores, atual, rotuloVazio, 'nome') + '</select>';
      }
      return '<input type="text" class="form-control form-control-sm text-uppercase" ' + atributos + ' value="' + e(atual) + '" placeholder="' + e(rotuloVazio) + '" maxlength="255">';
   }

   function formatarTelefone(valor) {
      var d = String(valor || '').replace(/\D/g, '').slice(0, 11);
      if (d.length <= 2) { return d.length ? '(' + d : ''; }
      if (d.length <= 6) { return '(' + d.slice(0, 2) + ') ' + d.slice(2); }
      if (d.length <= 10) { return '(' + d.slice(0, 2) + ') ' + d.slice(2, 6) + '-' + d.slice(6); }
      return '(' + d.slice(0, 2) + ') ' + d.slice(2, 7) + '-' + d.slice(7);
   }

   /** Marca o campo como gravado (borda verde por um instante) ou com erro */
   function sinalizar(campo, ok) {
      if (!campo) { return; }
      campo.classList.remove('wae-salvo', 'is-invalid');
      void campo.offsetWidth;
      campo.classList.add(ok ? 'wae-salvo' : 'is-invalid');
      if (ok) { setTimeout(function () { campo.classList.remove('wae-salvo'); }, 1200); }
   }

   function carregar() {
      return WAE.pedir('clientes_listar').then(function (r) {
         if (!r.sucesso) {
            WAE.avisar(r.mensagem || 'Nao foi possivel carregar os clientes.', true);
            return;
         }
         clientes = r.itens || [];
         categorias = r.categorias || [];
         botoesDisponivel = !!r.botoes_disponivel;
         desenhar();
      });
   }

   function filtro() {
      var campo = document.getElementById('wae-cli-busca');
      return campo ? campo.value.trim().toLowerCase() : '';
   }

   function combina(cliente, termo) {
      if (!termo) { return true; }
      var alvo = [cliente.entidade, cliente.requerente, cliente.observacao]
         .concat(cliente.codigos.map(function (c) { return c.codigo + ' ' + c.descricao; }))
         .concat(cliente.contatos.map(function (c) { return c.nome + ' ' + c.telefone; }))
         .join(' ').toLowerCase();
      return alvo.indexOf(termo) >= 0;
   }

   function desenhar() {
      var lista = document.getElementById('wae-cli-lista');
      if (!lista) { return; }

      var termo = filtro();
      var visiveis = clientes.filter(function (c) { return combina(c, termo); });

      if (!clientes.length) {
         lista.innerHTML = '<div class="list-group-item text-center text-secondary py-4">' +
            '<i class="ti ti-building fs-1 d-block mb-2"></i>Nenhum cliente cadastrado ainda.' +
            (podeEditar ? '<br>Escolha a entidade acima e clique em Adicionar.' : '') + '</div>';
         return;
      }
      if (!visiveis.length) {
         lista.innerHTML = '<div class="list-group-item text-secondary">Nenhum cliente corresponde a busca.</div>';
         return;
      }

      lista.innerHTML = visiveis.map(htmlCliente).join('');

      visiveis.forEach(function (c) {
         if (abertos[c.entities_id]) { carregarRequerente(c.entities_id); }
      });
   }

   function htmlCliente(c) {
      var aberto = !!abertos[c.entities_id];
      var dis = podeEditar ? '' : ' disabled';
      var ativos = c.codigos.filter(function (x) { return x.is_ativo; }).map(function (x) { return x.codigo; });

      var cabecalho =
         '<div class="d-flex align-items-center gap-3 wae-cli-topo" data-alternar="' + c.entities_id + '">' +
            '<i class="ti ' + (aberto ? 'ti-chevron-down' : 'ti-chevron-right') + ' text-secondary"></i>' +
            '<div class="flex-fill">' +
               '<div class="fw-bold">' + e(c.entidade) + (c.is_ativo ? '' : ' <span class="badge bg-secondary-lt ms-1">Inativo</span>') + '</div>' +
               '<div class="small text-secondary">' +
                  '<i class="ti ti-key me-1"></i>' + (ativos.length ? ativos.map(e).join(', ') : 'sem código ativo') +
                  '<span class="mx-2">·</span><i class="ti ti-address-book me-1"></i>' + c.contatos.length + ' contato(s)' +
                  '<span class="mx-2">·</span><i class="ti ti-user me-1"></i>' + (c.requerente ? e(c.requerente) : '<span class="text-warning">sem requerente padrão</span>') +
               '</div>' +
            '</div>' +
            '<label class="form-check form-switch m-0" title="Cliente ativo no autoatendimento" data-parar>' +
               '<input class="form-check-input" type="checkbox" data-cli-ativo="' + c.entities_id + '"' + (c.is_ativo ? ' checked' : '') + dis + '>' +
            '</label>' +
            (podeEditar ? '<button type="button" class="btn btn-sm btn-ghost-danger" data-cli-remover="' + c.entities_id + '" title="Remover cliente" data-parar><i class="ti ti-trash"></i></button>' : '') +
         '</div>';

      if (!aberto) {
         return '<div class="list-group-item" data-cliente="' + c.entities_id + '">' + cabecalho + '</div>';
      }

      var comBotoes = botoesDisponivel && !!c.usar_botoes;
      var unidades = (c.botoes && c.botoes.unidades) || [];

      var codigos = c.codigos.map(function (k) {
         return '<tr data-codigo="' + k.id + '">' +
            '<td><input type="text" class="form-control form-control-sm font-monospace" data-campo="codigo" value="' + e(k.codigo) + '" maxlength="60"' + dis + '></td>' +
            '<td><input type="text" class="form-control form-control-sm" data-campo="descricao" value="' + e(k.descricao) + '" placeholder="Opcional"' + dis + '></td>' +
            '<td><select class="form-select form-select-sm" data-campo="itilcategories_id"' + dis + '>' + opcoes(categorias, k.itilcategories_id, 'Categoria do cliente') + '</select></td>' +
            (comBotoes
               ? '<td><select class="form-select form-select-sm" data-campo="unidade_id"' + dis + '>' + opcoes(unidades, k.unidade_id, 'Unidade do cliente') + '</select></td>' +
                 '<td>' + campoSetor(c, k.setor, 'Setor do cliente', 'data-campo="setor"' + dis) + '</td>'
               : '') +
            '<td class="text-center"><input class="form-check-input" type="checkbox" data-campo="is_ativo"' + (k.is_ativo ? ' checked' : '') + dis + '></td>' +
            '<td class="text-end">' + (podeEditar ? '<button type="button" class="btn btn-sm btn-ghost-danger" data-remover-codigo="' + k.id + '"><i class="ti ti-x"></i></button>' : '') + '</td>' +
         '</tr>';
      }).join('');
      var colunasCodigo = comBotoes ? 7 : 5;

      var contatos = c.contatos.map(function (k) {
         return '<tr data-contato="' + k.id + '">' +
            '<td><input type="text" class="form-control form-control-sm" data-campo="nome" value="' + e(k.nome) + '" maxlength="100"' + dis + '></td>' +
            '<td><input type="text" class="form-control form-control-sm" data-campo="telefone" data-mascara value="' + e(formatarTelefone(k.telefone)) + '"' + dis + '></td>' +
            '<td class="text-center"><input class="form-check-input" type="checkbox" data-campo="is_ativo"' + (k.is_ativo ? ' checked' : '') + dis + '></td>' +
            '<td class="text-end">' + (podeEditar ? '<button type="button" class="btn btn-sm btn-ghost-danger" data-remover-contato="' + k.id + '"><i class="ti ti-x"></i></button>' : '') + '</td>' +
         '</tr>';
      }).join('');

      var blocoBotoes;
      if (!botoesDisponivel) {
         blocoBotoes = '<div class="text-secondary small"><i class="ti ti-info-circle me-1"></i>O plugin Botões não está ativo: Unidade e Setor não estão disponíveis.</div>';
      } else {
         blocoBotoes =
            '<label class="form-check form-switch mb-2">' +
               '<input class="form-check-input" type="checkbox" data-cli-campo="usar_botoes"' + (c.usar_botoes ? ' checked' : '') + dis + '>' +
               '<span class="form-check-label">Preencher Unidade e Setor nos chamados</span>' +
            '</label>' +
            (c.usar_botoes
               ? '<label class="form-label">Unidade</label>' +
                 (unidades.length
                    ? '<select class="form-select mb-1" data-cli-campo="unidade_id"' + dis + '>' + opcoes(unidades, c.unidade_id, 'Sem unidade') + '</select>'
                    : '<div class="text-warning small mb-1"><i class="ti ti-alert-triangle me-1"></i>Nenhuma unidade cadastrada para esta entidade no plugin Botões.</div>') +
                 '<label class="form-label mt-2">Setor</label>' +
                 campoSetor(c, c.setor, (c.botoes.setores || []).length ? 'Sem setor' : 'Texto livre (entidade sem setores no Botões)', 'data-cli-campo="setor"' + dis) +
                 '<div class="form-hint mt-2">Lidos do cadastro "Dados do cliente" do plugin Botões. Cada código abaixo pode usar outra unidade e outro setor.</div>'
               : '<div class="form-hint">Desligado: os chamados abertos pelo WhatsApp não recebem Unidade e Setor.</div>');
      }

      var corpo =
         '<div class="row g-4 mt-1 wae-cli-corpo">' +
            '<div class="col-xl-4">' +
               '<h4 class="wae-cli-titulo"><i class="ti ti-user-cog me-1"></i>Atendimento</h4>' +
               '<label class="form-label">Requerente padrão dos chamados</label>' +
               '<div class="mb-1" data-requerente="' + c.entities_id + '"><span class="text-secondary small">Carregando...</span></div>' +
               '<div class="form-hint mb-3">Usado quando quem fala é um contato sem usuário no GLPI.</div>' +
               '<label class="form-label">Categoria dos chamados</label>' +
               '<select class="form-select mb-1" data-cli-campo="itilcategories_id"' + dis + '>' + opcoes(categorias, c.itilcategories_id, 'Padrão da aba Regras') + '</select>' +
               '<div class="form-hint mb-3">Um bloco "Abrir registro" com categoria própria no fluxo tem prioridade.</div>' +
               '<label class="form-label">Observação interna</label>' +
               '<input type="text" class="form-control" data-cli-obs="' + c.entities_id + '" value="' + e(c.observacao) + '" maxlength="255"' + dis + '>' +
            '</div>' +
            '<div class="col-xl-4">' +
               '<h4 class="wae-cli-titulo"><i class="ti ti-building-community me-1"></i>Unidade e Setor (plugin Botões)</h4>' +
               blocoBotoes +
            '</div>' +
            '<div class="col-xl-4">' +
               '<h4 class="wae-cli-titulo"><i class="ti ti-address-book me-1"></i>Contatos (WhatsApp)</h4>' +
               '<table class="table table-sm table-vcenter mb-2"><thead><tr><th>Nome</th><th>Telefone</th><th class="text-center">Ativo</th><th></th></tr></thead>' +
               '<tbody>' + (contatos || '<tr><td colspan="4" class="text-secondary small">Nenhum contato.</td></tr>') + '</tbody></table>' +
               (podeEditar ?
                  '<div class="input-group input-group-sm">' +
                     '<input type="text" class="form-control" data-novo-contato-nome placeholder="Nome" maxlength="100">' +
                     '<input type="text" class="form-control" data-novo-contato-tel data-mascara placeholder="(71) 99999-9999">' +
                     '<button type="button" class="btn btn-primary" data-add-contato><i class="ti ti-plus"></i></button>' +
                  '</div>' : '') +
            '</div>' +
            '<div class="col-12">' +
               '<h4 class="wae-cli-titulo"><i class="ti ti-key me-1"></i>Códigos de acesso</h4>' +
               '<div class="table-responsive"><table class="table table-sm table-vcenter mb-2"><thead><tr>' +
                  '<th>Código</th><th>Descrição</th><th>Categoria</th>' + (comBotoes ? '<th>Unidade</th><th>Setor</th>' : '') +
                  '<th class="text-center">Ativo</th><th></th></tr></thead>' +
               '<tbody>' + (codigos || '<tr><td colspan="' + colunasCodigo + '" class="text-secondary small">Nenhum código.</td></tr>') + '</tbody></table></div>' +
               (podeEditar ?
                  '<div class="input-group input-group-sm wae-cli-novo-codigo">' +
                     '<input type="text" class="form-control font-monospace" data-novo-codigo placeholder="Novo código" maxlength="60">' +
                     '<button type="button" class="btn btn-outline-secondary" data-gerar-codigo title="Gerar código"><i class="ti ti-wand"></i></button>' +
                     '<button type="button" class="btn btn-primary" data-add-codigo><i class="ti ti-plus me-1"></i>Adicionar código</button>' +
                  '</div>' : '') +
               '<div class="form-hint mt-1">Sem categoria, unidade ou setor no código, valem os do cliente. Um código por unidade faz cada equipe abrir chamados já na unidade certa.</div>' +
            '</div>' +
         '</div>';

      return '<div class="list-group-item wae-cli-aberto" data-cliente="' + c.entities_id + '">' + cabecalho + corpo + '</div>';
   }

   /** Campo nativo de usuario do GLPI (select2) vindo do servidor */
   function carregarRequerente(entities_id) {
      var alvo = raiz.querySelector('[data-requerente="' + entities_id + '"]');
      if (!alvo) { return; }

      // POST: o campo registra um token de seguranca na sessao
      WAE.pedir('cliente_requerente', { entities_id: entities_id }, 'POST').then(function (r) {
         if (!r.sucesso) { alvo.innerHTML = '<span class="text-danger small">Nao foi possivel carregar.</span>'; return; }
         $(alvo).html(r.html);
         if (!podeEditar) { $(alvo).find('select').prop('disabled', true); }
         $(alvo).find('select').on('change', function () {
            var campo = this;
            WAE.pedir('cliente_salvar', { entities_id: entities_id, users_id_requerente: $(campo).val() || 0 }, 'POST').then(function (resp) {
               if (!resp.sucesso) { WAE.avisar('Nao foi possivel gravar o requerente.', true); return; }
               var cliente = acharCliente(entities_id);
               if (cliente) {
                  cliente.users_id_requerente = parseInt($(campo).val() || '0', 10);
                  cliente.requerente = $(campo).find('option:selected').text() || '';
               }
               atualizarResumo(entities_id);
               WAE.avisar('Requerente padrão atualizado.');
            });
         });
      });
   }

   function acharCliente(entities_id) {
      for (var i = 0; i < clientes.length; i++) {
         if (String(clientes[i].entities_id) === String(entities_id)) { return clientes[i]; }
      }
      return null;
   }

   /** Atualiza so a linha de resumo, sem redesenhar os campos abertos */
   function atualizarResumo(entities_id) {
      var cliente = acharCliente(entities_id);
      var item = raiz.querySelector('[data-cliente="' + entities_id + '"]');
      if (!cliente || !item) { return; }
      var temp = document.createElement('div');
      temp.innerHTML = htmlCliente(cliente);
      var novoTopo = temp.querySelector('.wae-cli-topo');
      var topo = item.querySelector('.wae-cli-topo');
      if (novoTopo && topo) { topo.replaceWith(novoTopo); }
   }

   function salvarCodigo(entities_id, linha) {
      var dados = {
         entities_id: entities_id,
         id: linha.getAttribute('data-codigo'),
         codigo: linha.querySelector('[data-campo="codigo"]').value.trim(),
         descricao: linha.querySelector('[data-campo="descricao"]').value.trim(),
         is_ativo: linha.querySelector('[data-campo="is_ativo"]').checked ? 1 : 0
      };
      // Categoria, Unidade e Setor proprios do codigo (so os que estao na tela)
      ['itilcategories_id', 'unidade_id', 'setor'].forEach(function (campo) {
         var el = linha.querySelector('[data-campo="' + campo + '"]');
         if (el) { dados[campo] = el.value; }
      });
      return WAE.pedir('codigo_salvar', dados, 'POST').then(function (r) {
         var campo = linha.querySelector('[data-campo="codigo"]');
         if (!r.sucesso) {
            sinalizar(campo, false);
            WAE.avisar(r.mensagem || 'Nao foi possivel gravar o codigo.', true);
            return;
         }
         sinalizar(campo, true);
         var cliente = acharCliente(entities_id);
         if (cliente) {
            cliente.codigos.forEach(function (k) {
               if (String(k.id) === String(dados.id)) {
                  k.codigo = dados.codigo; k.descricao = dados.descricao; k.is_ativo = dados.is_ativo;
                  if (dados.itilcategories_id !== undefined) { k.itilcategories_id = parseInt(dados.itilcategories_id, 10) || 0; }
                  if (dados.unidade_id !== undefined) { k.unidade_id = parseInt(dados.unidade_id, 10) || 0; }
                  if (dados.setor !== undefined) { k.setor = dados.setor; }
               }
            });
            atualizarResumo(entities_id);
         }
      });
   }

   function salvarContato(entities_id, linha) {
      var dados = {
         entities_id: entities_id,
         id: linha.getAttribute('data-contato'),
         nome: linha.querySelector('[data-campo="nome"]').value.trim(),
         telefone: linha.querySelector('[data-campo="telefone"]').value,
         is_ativo: linha.querySelector('[data-campo="is_ativo"]').checked ? 1 : 0
      };
      return WAE.pedir('contato_salvar', dados, 'POST').then(function (r) {
         var campo = linha.querySelector('[data-campo="telefone"]');
         if (!r.sucesso) {
            sinalizar(campo, false);
            WAE.avisar(r.mensagem || 'Nao foi possivel gravar o contato.', true);
            return;
         }
         sinalizar(campo, true);
         sinalizar(linha.querySelector('[data-campo="nome"]'), true);
      });
   }

   function ligarEventos() {
      var busca = document.getElementById('wae-cli-busca');
      if (busca) {
         var temporizador = null;
         busca.addEventListener('input', function () {
            clearTimeout(temporizador);
            temporizador = setTimeout(desenhar, 250);
         });
      }

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
               WAE.avisar(r.mensagem || (r.sucesso ? 'Cliente cadastrado.' : 'Nao foi possivel cadastrar.'), !r.sucesso);
               if (r.sucesso) {
                  abertos[r.entities_id] = true;
                  $(novo).find('select[name="entities_id"]').val('-1').trigger('change');
                  carregar();
               }
            });
         });
      }

      // Cliques: abrir/fechar, remover, adicionar codigos e contatos
      raiz.addEventListener('click', function (ev) {
         var alvo = ev.target;
         var item = alvo.closest('[data-cliente]');
         var entities_id = item ? item.getAttribute('data-cliente') : null;

         if (alvo.closest('[data-parar]') && !alvo.closest('[data-cli-remover]')) { return; }

         var remover = alvo.closest('[data-cli-remover]');
         if (remover) {
            ev.stopPropagation();
            var cliente = acharCliente(entities_id);
            WAE.confirmar('Remover o cliente "' + (cliente ? cliente.entidade : '') + '"? Os contatos serao apagados e os codigos desativados.').then(function (ok) {
               if (!ok) { return; }
               WAE.pedir('cliente_remover', { entities_id: entities_id }, 'POST').then(function (r) {
                  WAE.avisar(r.mensagem || '', !r.sucesso);
                  delete abertos[entities_id];
                  carregar();
               });
            });
            return;
         }

         if (alvo.closest('[data-alternar]') && !alvo.closest('input, button, select, label')) {
            abertos[entities_id] = !abertos[entities_id];
            desenhar();
            return;
         }

         if (alvo.closest('[data-gerar-codigo]')) {
            WAE.pedir('codigo_sugerir').then(function (r) {
               if (r.sucesso) { item.querySelector('[data-novo-codigo]').value = r.codigo; }
            });
            return;
         }

         if (alvo.closest('[data-add-codigo]')) {
            var campoCodigo = item.querySelector('[data-novo-codigo]');
            WAE.pedir('codigo_salvar', { entities_id: entities_id, codigo: campoCodigo.value.trim() }, 'POST').then(function (r) {
               if (!r.sucesso) { sinalizar(campoCodigo, false); WAE.avisar(r.mensagem, true); return; }
               WAE.avisar('Codigo adicionado.');
               carregar();
            });
            return;
         }

         var removerCodigo = alvo.closest('[data-remover-codigo]');
         if (removerCodigo) {
            WAE.confirmar('Remover este codigo? Quem usa ele deixa de conseguir acesso.').then(function (ok) {
               if (!ok) { return; }
               WAE.pedir('codigo_remover', { entities_id: entities_id, id: removerCodigo.getAttribute('data-remover-codigo') }, 'POST').then(carregar);
            });
            return;
         }

         if (alvo.closest('[data-add-contato]')) {
            var nome = item.querySelector('[data-novo-contato-nome]');
            var tel = item.querySelector('[data-novo-contato-tel]');
            WAE.pedir('contato_salvar', { entities_id: entities_id, nome: nome.value.trim(), telefone: tel.value }, 'POST').then(function (r) {
               if (!r.sucesso) { sinalizar(tel, false); WAE.avisar(r.mensagem, true); return; }
               WAE.avisar('Contato adicionado.');
               carregar();
            });
            return;
         }

         var removerContato = alvo.closest('[data-remover-contato]');
         if (removerContato) {
            WAE.confirmar('Remover este contato? Ele deixa de ser atendido pelo telefone.').then(function (ok) {
               if (!ok) { return; }
               WAE.pedir('contato_remover', { entities_id: entities_id, id: removerContato.getAttribute('data-remover-contato') }, 'POST').then(carregar);
            });
         }
      });

      // Alteracoes: gravadas na hora
      raiz.addEventListener('change', function (ev) {
         var alvo = ev.target;
         var item = alvo.closest('[data-cliente]');
         if (!item || !podeEditar) { return; }
         var entities_id = item.getAttribute('data-cliente');

         if (alvo.hasAttribute('data-cli-ativo')) {
            WAE.pedir('cliente_salvar', { entities_id: entities_id, is_ativo: alvo.checked ? 1 : 0 }, 'POST').then(function (r) {
               if (!r.sucesso) { WAE.avisar('Nao foi possivel gravar.', true); return; }
               var c = acharCliente(entities_id);
               if (c) { c.is_ativo = alvo.checked ? 1 : 0; atualizarResumo(entities_id); }
               WAE.avisar(alvo.checked ? 'Cliente ativado.' : 'Cliente desativado: os codigos dele deixam de valer.');
            });
            return;
         }

         // Categoria, Unidade/Setor do Botoes e a chave que liga esse preenchimento
         if (alvo.hasAttribute('data-cli-campo')) {
            var campo = alvo.getAttribute('data-cli-campo');
            var valor = alvo.type === 'checkbox' ? (alvo.checked ? 1 : 0) : alvo.value;
            var envio = { entities_id: entities_id };
            envio[campo] = valor;
            WAE.pedir('cliente_salvar', envio, 'POST').then(function (r) {
               if (!r.sucesso) { sinalizar(alvo, false); WAE.avisar('Nao foi possivel gravar.', true); return; }
               var c = acharCliente(entities_id);
               if (campo === 'usar_botoes') {
                  // Mostra ou esconde Unidade/Setor (do cliente e dos codigos)
                  if (c) { c.usar_botoes = valor; }
                  desenhar();
                  WAE.avisar(valor ? 'Unidade e Setor serão preenchidos nos chamados deste cliente.' : 'Unidade e Setor desligados para este cliente.');
                  return;
               }
               if (c) { c[campo] = campo === 'setor' ? valor : (parseInt(valor, 10) || 0); }
               sinalizar(alvo, true);
            });
            return;
         }

         if (alvo.hasAttribute('data-cli-obs')) {
            WAE.pedir('cliente_salvar', { entities_id: entities_id, observacao: alvo.value }, 'POST').then(function (r) {
               sinalizar(alvo, !!r.sucesso);
               var c = acharCliente(entities_id);
               if (c) { c.observacao = alvo.value; }
            });
            return;
         }

         var linhaCodigo = alvo.closest('[data-codigo]');
         if (linhaCodigo) { salvarCodigo(entities_id, linhaCodigo); return; }

         var linhaContato = alvo.closest('[data-contato]');
         if (linhaContato) { salvarContato(entities_id, linhaContato); }
      });

      // Mascara de telefone brasileiro
      raiz.addEventListener('input', function (ev) {
         if (ev.target.hasAttribute('data-mascara')) {
            ev.target.value = formatarTelefone(ev.target.value);
         }
      });

      // Enter nos campos de inclusao aciona o botao ao lado
      raiz.addEventListener('keydown', function (ev) {
         if (ev.key !== 'Enter') { return; }
         var grupo = ev.target.closest('.input-group');
         var botao = grupo ? grupo.querySelector('[data-add-codigo], [data-add-contato]') : null;
         if (botao) { ev.preventDefault(); botao.click(); }
      });
   }

   window.WAEClientes = {
      iniciar: function (id) {
         raiz = document.getElementById(id);
         if (!raiz || raiz.getAttribute('data-iniciado')) { return; }
         raiz.setAttribute('data-iniciado', '1');
         podeEditar = raiz.getAttribute('data-edita') === '1';
         ligarEventos();
         carregar();
      }
   };
})();
