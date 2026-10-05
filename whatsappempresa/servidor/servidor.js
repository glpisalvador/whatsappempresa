// servidor.js - servidor WhatsApp do plugin whatsappempresa

import http from 'http';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import { createRequire } from 'module';
import {
   makeWASocket,
   useMultiFileAuthState,
   makeCacheableSignalKeyStore,
   DisconnectReason,
   fetchLatestBaileysVersion,
   generateWAMessageFromContent,
   proto
} from '@whiskeysockets/baileys';
import pino from 'pino';
import QRCode from 'qrcode';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const PASTA_AUTH = process.env.WAE_AUTH && process.env.WAE_AUTH.trim() !== ''
   ? process.env.WAE_AUTH
   : path.join(__dirname, 'auth');
const PORTA   = parseInt(process.env.WAE_PORTA || '3456', 10);
const TOKEN   = process.env.WAE_TOKEN || '';
const WEBHOOK = process.env.WAE_WEBHOOK || '';

if (WEBHOOK === '' || TOKEN === '') {
   process.stdout.write(`[inicio] Webhook ou token ausente - webhook="${WEBHOOK}" token=${TOKEN ? 'definido' : 'vazio'}\n`);
}

if (process.env.WAE_TLS_INSEGURO === '1') {
   process.env.NODE_TLS_REJECT_UNAUTHORIZED = '0';
}

let socket = null;
let conectado = false;
let paradaManual = false;
let ultimoQr = null;
let versaoWa = null;
let conexao = { numero: null, nome: null, desde: null };

// Numeros que nao aceitaram botoes: voltam a receber texto numerado
const semBotoes = new Set();
const fila = new Map();
const aguardandoAck = new Map();

/**
 * Espera o WhatsApp confirmar o recebimento da mensagem interativa
 */
function esperarAck(id, milissegundos) {
   return new Promise((resolve) => {
      if (!id) {
         resolve(false);
         return;
      }

      const prazo = setTimeout(() => {
         aguardandoAck.delete(id);
         resolve(false);
      }, milissegundos);

      aguardandoAck.set(id, () => {
         clearTimeout(prazo);
         aguardandoAck.delete(id);
         resolve(true);
      });
   });
}

let versaoBaileys = 'desconhecida';
try {
   const requerer = createRequire(import.meta.url);
   versaoBaileys = requerer('@whiskeysockets/baileys/package.json').version;
} catch (e) {
   versaoBaileys = 'desconhecida';
}

const registrador = pino({ level: 'silent' });

/**
 * Mensagens enviadas recentemente. Quando o aparelho nao consegue decifrar
 * ("Aguardando mensagem"), ele pede reenvio e o Baileys busca o conteudo aqui
 * para cifrar de novo com uma sessao nova. Sem isso a mensagem nunca aparece.
 */
const enviadas = new Map();
const LIMITE_ENVIADAS = 3000;

async function enviarGuardando(destino, conteudo) {
   const enviada = await socket.sendMessage(destino, conteudo);
   if (enviada && enviada.key) guardarEnviada(enviada.key.id, enviada.message);
   return enviada;
}

function guardarEnviada(id, conteudo) {
   if (!id || !conteudo) return;
   enviadas.set(id, conteudo);
   if (enviadas.size > LIMITE_ENVIADAS) {
      enviadas.delete(enviadas.keys().next().value);
   }
}

// Contador de pedidos de reenvio exigido pelo Baileys (interface get/set/del/flushAll)
const contadorReenvio = {
   dados: new Map(),
   get(chave) { return this.dados.get(chave); },
   set(chave, valor) {
      this.dados.set(chave, valor);
      if (this.dados.size > 5000) this.dados.delete(this.dados.keys().next().value);
      return true;
   },
   del(chave) { this.dados.delete(chave); return 1; },
   flushAll() { this.dados.clear(); }
};

function agora() {
   return new Date().toLocaleString('pt-BR', { timeZone: 'America/Sao_Paulo' });
}

function registrar(evento, detalhe = '') {
   process.stdout.write(`[${agora()}] ${evento}${detalhe ? ' - ' + detalhe : ''}\n`);
}

function autenticacaoExiste() {
   try {
      return fs.existsSync(path.join(PASTA_AUTH, 'creds.json'));
   } catch (e) {
      return false;
   }
}

function limparAutenticacao() {
   try {
      if (fs.existsSync(PASTA_AUTH)) {
         fs.rmSync(PASTA_AUTH, { recursive: true, force: true });
      }
      fs.mkdirSync(PASTA_AUTH, { recursive: true });
   } catch (e) {
      registrar('Falha ao limpar a pasta auth', e.message);
   }
}

function formatarNumero(numero) {
   let limpo = String(numero).replace(/\D/g, '');
   if (limpo.length === 10 || limpo.length === 11) {
      limpo = '55' + limpo;
   }
   return limpo + '@s.whatsapp.net';
}

function numeroDoJid(jid) {
   return String(jid || '').split('@')[0].split(':')[0];
}

// telefone -> jid real da conversa, e o caminho inverso para os LIDs
const mapaJid = new Map();
const mapaLid = new Map();
const ARQUIVO_MAPA = path.join(PASTA_AUTH, 'mapa-jid.json');

function carregarMapa() {
   try {
      if (fs.existsSync(ARQUIVO_MAPA)) {
         const dados = JSON.parse(fs.readFileSync(ARQUIVO_MAPA, 'utf8'));
         const numeros = dados.numeros || dados;
         Object.keys(numeros).forEach((chave) => {
            if (!String(numeros[chave]).endsWith('@lid')) {
               mapaJid.set(chave, numeros[chave]);
            }
         });
         Object.keys(dados.lids || {}).forEach((chave) => mapaLid.set(chave, dados.lids[chave]));
         registrar('Mapa de conversas carregado', `${mapaJid.size} numero(s), ${mapaLid.size} lid(s)`);
      }
   } catch (e) {
      registrar('Falha ao carregar o mapa de conversas', e.message);
   }
}

function gravarMapa() {
   try {
      fs.writeFileSync(ARQUIVO_MAPA, JSON.stringify({
         numeros: Object.fromEntries(mapaJid),
         lids: Object.fromEntries(mapaLid)
      }), 'utf8');
   } catch (e) {}
}

function associar(telefone, jid) {
   if (!telefone || !jid) return;

   let mudou = false;
   const ehLid = String(jid).endsWith('@lid');

   // LID fica no mapa proprio (lid -> telefone); o de numeros guarda so enderecos @s.whatsapp.net
   if (ehLid) {
      if (mapaLid.get(jid) !== telefone) {
         mapaLid.set(jid, telefone);
         mudou = true;
      }
   } else if (mapaJid.get(telefone) !== jid) {
      mapaJid.set(telefone, jid);
      mudou = true;
   }

   if (mudou) gravarMapa();
}

/**
 * Variantes do numero brasileiro com e sem o nono digito
 */
function variantesDoNumero(telefone) {
   const lista = [];
   let base = String(telefone || '').replace(/\D/g, '');

   if (base === '') {
      return lista;
   }

   if (base.length === 10 || base.length === 11) {
      base = '55' + base;
   }

   lista.push(base);

   if (base.startsWith('55') && base.length === 12) {
      lista.push(base.slice(0, 4) + '9' + base.slice(4));
   }

   if (base.startsWith('55') && base.length === 13 && base[4] === '9') {
      lista.push(base.slice(0, 4) + base.slice(5));
   }

   return lista;
}

/**
 * Enderecos a tentar, do mais provavel para o menos provavel
 */
function candidatosDeEnvio(jid, telefone) {
   const lista = [];

   function incluir(valor) {
      if (valor && lista.indexOf(valor) < 0) {
         lista.push(valor);
      }
   }

   const numero = String(telefone || '').replace(/\D/g, '');
   const guardado = numero !== '' ? mapaJid.get(numero) : null;
   const ehLid = String(jid || '').endsWith('@lid');

   // Aparelhos ja migrados para LID (Android atual) so decifram o que foi cifrado para o LID:
   // ele vai primeiro, o numero fica como reserva
   incluir(ehLid ? jid : lidDoTelefone(numero));

   if (!ehLid) {
      incluir(jid);
   }

   incluir(guardado);

   variantesDoNumero(numero).forEach(function (variante) {
      incluir(variante + '@s.whatsapp.net');
   });

   return lista;
}

/**
 * LID ja visto para o telefone (com ou sem o nono digito)
 */
function lidDoTelefone(numero) {
   if (!numero) return null;
   const variantes = variantesDoNumero(numero);
   for (const [lid, fone] of mapaLid) {
      if (variantes.indexOf(String(fone)) >= 0) {
         return lid;
      }
   }
   return null;
}

/**
 * Descobre o telefone real quando o WhatsApp entrega a mensagem com LID
 */
async function telefoneDaMensagem(mensagem) {
   const chave = mensagem.key || {};

   const candidatos = [
      chave.senderPn,
      chave.participantPn,
      chave.remoteJidAlt,
      chave.participantAlt,
      mensagem.senderPn,
      chave.remoteJid
   ];

   for (const candidato of candidatos) {
      if (candidato && String(candidato).includes('@s.whatsapp.net')) {
         return numeroDoJid(candidato);
      }
   }

   // Conversa aberta pelo atendente: o jid ja foi associado no envio
   if (mapaLid.has(chave.remoteJid)) {
      return mapaLid.get(chave.remoteJid);
   }

   // Tabela interna de correspondencia LID -> telefone do proprio Baileys (assincrona no 7.x)
   try {
      const mapeador = socket?.signalRepository?.lidMapping;
      if (mapeador && typeof mapeador.getPNForLID === 'function') {
         const encontrado = await mapeador.getPNForLID(chave.remoteJid);
         if (encontrado && String(encontrado).includes('@s.whatsapp.net')) {
            return numeroDoJid(encontrado);
         }
      }
   } catch (e) {}

   return '';
}

/**
 * Descobre o jid correto para um numero antes de enviar
 */
async function resolverJid(numero) {
   const limpo = String(numero).replace(/\D/g, '');

   if (mapaJid.has(limpo)) {
      return mapaJid.get(limpo);
   }

   try {
      const consulta = await socket.onWhatsApp(limpo);
      if (Array.isArray(consulta) && consulta[0] && consulta[0].exists) {
         const achado = consulta[0];

         // Guarda o LID so para reconhecer a resposta desse contato
         if (achado.lid) {
            mapaLid.set(achado.lid, limpo);
            gravarMapa();
         }

         if (achado.jid && !String(achado.jid).endsWith('@lid')) {
            associar(limpo, achado.jid);
            return achado.jid;
         }
      }
   } catch (e) {
      registrar('Falha ao consultar o numero no WhatsApp', `${limpo} - ${e.message}`);
   }

   return formatarNumero(limpo);
}

/**
 * Le o identificador escolhido quando o cliente toca num botao ou item de lista
 */
function idDaEscolha(conteudo) {
   const resposta = conteudo.buttonsResponseMessage;
   if (resposta && resposta.selectedButtonId) {
      return String(resposta.selectedButtonId);
   }

   const modelo = conteudo.templateButtonReplyMessage;
   if (modelo && modelo.selectedId) {
      return String(modelo.selectedId);
   }

   const lista = conteudo.listResponseMessage;
   if (lista && lista.singleSelectReply && lista.singleSelectReply.selectedRowId) {
      return String(lista.singleSelectReply.selectedRowId);
   }

   const interativa = conteudo.interactiveResponseMessage;
   const bruto = interativa?.nativeFlowResponseMessage?.paramsJson;
   if (bruto) {
      try {
         const dados = JSON.parse(bruto);
         const escolhido = dados.id || dados.selectedRowId || dados.selectedId;
         if (escolhido) {
            return String(escolhido);
         }
      } catch (e) {}
   }

   return '';
}

function extrairTexto(mensagem) {
   const conteudo = mensagem.message || {};

   // Botao tocado vale mais que o rotulo exibido
   const escolha = idDaEscolha(conteudo);
   if (escolha !== '') {
      return escolha;
   }

   return conteudo.conversation
      || conteudo.extendedTextMessage?.text
      || conteudo.imageMessage?.caption
      || conteudo.videoMessage?.caption
      || conteudo.buttonsResponseMessage?.selectedDisplayText
      || conteudo.listResponseMessage?.title
      || '';
}

/**
 * Entrega a mensagem recebida ao GLPI e devolve as respostas
 */
async function consultarGlpi(telefone, texto, jid, tentativa = 1) {
   if (!WEBHOOK) {
      registrar('Webhook nao configurado', 'a variavel WAE_WEBHOOK chegou vazia');
      return [];
   }

   try {
      const resposta = await fetch(WEBHOOK, {
         method: 'POST',
         headers: {
            'Content-Type': 'application/json',
            'X-Token-Interno': TOKEN
         },
         body: JSON.stringify({ telefone, texto, jid, token: TOKEN })
      });

      const bruto = await resposta.text();

      if (!resposta.ok) {
         registrar('Webhook recusou a mensagem', `HTTP ${resposta.status} em ${WEBHOOK} - ${bruto.substring(0, 200)}`);

         if (resposta.status >= 500 && tentativa < 2) {
            await new Promise((r) => setTimeout(r, 1500));
            return consultarGlpi(telefone, texto, jid, tentativa + 1);
         }

         return [];
      }

      let dados = null;
      try {
         dados = JSON.parse(bruto);
      } catch (e) {
         const inicio = bruto.indexOf('{');
         const fim = bruto.lastIndexOf('}');

         if (inicio >= 0 && fim > inicio) {
            try {
               dados = JSON.parse(bruto.substring(inicio, fim + 1));
            } catch (e2) {
               dados = null;
            }
         }
      }

      if (!dados) {
         registrar('Resposta do webhook nao e JSON', `${bruto.length} bytes - ${bruto.substring(0, 200)}`);
         return [];
      }

      const respostas = Array.isArray(dados.respostas) ? dados.respostas : [];
      const entregues = Number(dados.entregues || 0);
      registrar('Webhook respondeu', `${entregues} entregue(s) pelo GLPI, ${respostas.length} para enviar aqui - ${telefone}`);
      return respostas;
   } catch (e) {
      registrar('Falha ao consultar o GLPI', `${WEBHOOK} - ${e.message}`);

      if (tentativa < 2) {
         await new Promise((r) => setTimeout(r, 1500));
         return consultarGlpi(telefone, texto, jid, tentativa + 1);
      }

      return [];
   }
}

/**
 * Avisa o GLPI o que realmente saiu, para o historico nao mentir
 */
async function confirmarEnvio(itens) {
   if (!WEBHOOK || !itens.length) return;

   try {
      await fetch(WEBHOOK, {
         method: 'POST',
         headers: {
            'Content-Type': 'application/json',
            'X-Token-Interno': TOKEN
         },
         body: JSON.stringify({ confirmar: itens, token: TOKEN })
      });
   } catch (e) {
      registrar('Falha ao confirmar a entrega no GLPI', e.message);
   }
}

async function tratarRecebida(mensagem) {
   const jid = mensagem.key?.remoteJid || '';

   if (mensagem.key?.fromMe) return;
   if (jid.endsWith('@g.us')) return;
   if (jid.endsWith('@newsletter')) return;
   if (jid.endsWith('@broadcast')) return;
   if (jid === 'status@broadcast') return;

   const texto = extrairTexto(mensagem).trim();
   if (texto === '') return;

   const ehLid = jid.endsWith('@lid');
   let telefone = await telefoneDaMensagem(mensagem);

   if (telefone === '') {
      telefone = numeroDoJid(jid);
      if (ehLid) {
         registrar('Numero real nao identificado', `LID ${jid} sera usado como identificador`);
      }
   }

   // Guarda o jid verdadeiro para os envios que o GLPI fizer depois
   associar(telefone, jid);

   registrar('Mensagem recebida', `${telefone}${ehLid ? ' (lid ' + numeroDoJid(jid) + ')' : ''}: ${texto.substring(0, 60)}`);

   // Enfileira por contato: mensagens seguidas entram na ordem, nenhuma e descartada
   const anterior = fila.get(jid) || Promise.resolve();

   const atual = anterior.then(async () => {
      const respostas = await consultarGlpi(telefone, texto, jid);
      const confirmacoes = [];

      for (const resposta of respostas) {
         if (!resposta) continue;

         try {
            await enviarParaJid(jid, resposta, telefone);
            if (resposta.id) {
               confirmacoes.push({ id: resposta.id, ok: true });
            }
         } catch (e) {
            registrar('Resposta nao entregue', `${jid} - ${e.message}`);
            if (resposta.id) {
               confirmacoes.push({ id: resposta.id, ok: false, erro: e.message });
            }
         }

         await new Promise((r) => setTimeout(r, 400));
      }

      await confirmarEnvio(confirmacoes);
   }).catch((e) => {
      registrar('Falha ao responder o cliente', `${jid} - ${e.message}`);
   });

   fila.set(jid, atual);

   atual.finally(() => {
      if (fila.get(jid) === atual) fila.delete(jid);
   });

   await atual;
}

/**
 * Conecta ao WhatsApp. Se a preparacao falhar (sem internet, DNS, etc.),
 * tenta de novo com espera crescente em vez de ficar parado para sempre.
 */
let tentativasConexao = 0;

async function conectar() {
   paradaManual = false;
   try {
      await conectarSessao();
      tentativasConexao = 0;
   } catch (e) {
      tentativasConexao++;
      const espera = Math.min(60, 5 * tentativasConexao);
      registrar('Falha ao preparar a conexao', `${e && e.message ? e.message : e}. Nova tentativa em ${espera}s`);
      setTimeout(() => { if (!paradaManual) conectar(); }, espera * 1000);
   }
}

async function conectarSessao() {
   const { state, saveCreds } = await useMultiFileAuthState(PASTA_AUTH);

   // A versao mais recente do WhatsApp Web vem da internet; sem ela usa a padrao da biblioteca
   let version;
   try {
      version = (await fetchLatestBaileysVersion()).version;
   } catch (e) {
      registrar('Versao do WhatsApp Web indisponivel', 'usando a padrao da biblioteca');
   }
   versaoWa = version ? version.join('.') : 'padrao';

   socket = makeWASocket({
      version,
      auth: {
         creds: state.creds,
         // Cache das chaves: evita gravacoes concorrentes que corrompem as sessoes de criptografia
         keys: makeCacheableSignalKeyStore(state.keys, registrador)
      },
      logger: registrador,
      printQRInTerminal: false,
      browser: ['WhatsAppEmpresa', 'Chrome', '1.0.0'],
      msgRetryCounterCache: contadorReenvio,
      getMessage: async (chave) => {
         const conteudo = chave && chave.id ? enviadas.get(chave.id) : undefined;
         if (conteudo) {
            registrar('Reenvio pedido pelo aparelho', `${chave.remoteJid || ''} - mensagem cifrada de novo`);
         }
         return conteudo;
      }
   });

   socket.ev.on('connection.update', async (atualizacao) => {
      const { connection, lastDisconnect, qr } = atualizacao;

      if (qr) {
         try {
            ultimoQr = await QRCode.toDataURL(qr, { margin: 1, width: 300 });
            registrar('Novo QR Code gerado');
         } catch (e) {
            ultimoQr = null;
         }
      }

      if (connection === 'open') {
         conectado = true;
         ultimoQr = null;
         const usuario = socket.user || {};
         conexao = {
            numero: numeroDoJid(usuario.id),
            nome: usuario.name || usuario.verifiedName || null,
            desde: Date.now()
         };
         registrar('WhatsApp conectado', conexao.numero || '');
      }

      if (connection === 'close') {
         conectado = false;
         const codigo = lastDisconnect?.error?.output?.statusCode;

         if (paradaManual) {
            registrar('Servico parado manualmente');
            return;
         }

         const sessaoInvalida = codigo === DisconnectReason.loggedOut
            || codigo === DisconnectReason.badSession
            || codigo === DisconnectReason.forbidden
            || codigo === 401
            || codigo === 403;

         if (sessaoInvalida) {
            registrar('Sessao invalida', `Codigo ${codigo}. Gerando novo QR Code`);
            limparAutenticacao();
            conexao = { numero: null, nome: null, desde: null };
            setTimeout(conectar, 2000);
         } else {
            registrar('Conexao perdida', `Codigo ${codigo}. Reconectando em 5s`);
            setTimeout(conectar, 5000);
         }
      }
   });

   socket.ev.on('creds.update', saveCreds);

   // Confirma se o WhatsApp aceitou a mensagem que acabou de sair
   socket.ev.on('messages.update', (atualizacoes) => {
      for (const item of atualizacoes || []) {
         const id = item.key && item.key.id;
         const situacao = item.update && item.update.status;
         if (id && situacao && Number(situacao) >= 2 && aguardandoAck.has(id)) {
            aguardandoAck.get(id)();
         }
      }
   });

   socket.ev.on('messages.upsert', async (evento) => {
      if (evento.type !== 'notify') return;
      for (const mensagem of evento.messages || []) {
         try {
            await tratarRecebida(mensagem);
         } catch (e) {
            registrar('Falha ao tratar mensagem', e.message);
         }
      }
   });
}

// ============================================
// Montagem das mensagens com botoes
// ============================================

function normalizarResposta(resposta) {
   if (typeof resposta === 'string') {
      return { texto: resposta, rodape: '', botoes: [], lista: null };
   }

   const botoes = Array.isArray(resposta.botoes) ? resposta.botoes : [];
   const lista = resposta.lista && Array.isArray(resposta.lista.itens) ? resposta.lista : null;

   return {
      texto: String(resposta.texto || ''),
      rodape: String(resposta.rodape || ''),
      botoes: botoes.slice(0, 3),
      lista
   };
}

/**
 * Sempre existe um caminho de texto: se o botao falhar o cliente ainda entende
 */
function textoNumerado(dados) {
   const linhas = [dados.texto];
   const itens = dados.botoes.length ? dados.botoes : (dados.lista ? dados.lista.itens : []);

   itens.forEach((item) => {
      linhas.push(`*${item.id}* - ${item.rotulo}${item.descricao ? '\n    ' + item.descricao : ''}`);
   });

   if (dados.rodape) {
      linhas.push(dados.rodape);
   }

   return linhas.join('\n');
}

async function enviarBotoesNativos(jid, dados) {
   const conteudo = {
      body: proto.Message.InteractiveMessage.Body.fromObject({ text: dados.texto }),
      footer: proto.Message.InteractiveMessage.Footer.fromObject({ text: dados.rodape || ' ' }),
      nativeFlowMessage: proto.Message.InteractiveMessage.NativeFlowMessage.fromObject({
         buttons: dados.botoes.map((botao) => ({
            name: 'quick_reply',
            buttonParamsJson: JSON.stringify({ display_text: botao.rotulo, id: String(botao.id) })
         }))
      })
   };

   const mensagem = generateWAMessageFromContent(jid, {
      viewOnceMessage: {
         message: { interactiveMessage: proto.Message.InteractiveMessage.fromObject(conteudo) }
      }
   }, {});

   await socket.relayMessage(jid, mensagem.message, { messageId: mensagem.key.id });
   guardarEnviada(mensagem.key.id, mensagem.message);

   return mensagem.key.id;
}

async function enviarListaNativa(jid, dados) {
   const secao = {
      title: dados.lista.titulo || 'Opcoes',
      rows: dados.lista.itens.map((item) => ({
         header: '',
         title: item.rotulo,
         description: item.descricao || '',
         id: String(item.id)
      }))
   };

   const conteudo = {
      body: proto.Message.InteractiveMessage.Body.fromObject({ text: dados.texto }),
      footer: proto.Message.InteractiveMessage.Footer.fromObject({ text: dados.rodape || ' ' }),
      nativeFlowMessage: proto.Message.InteractiveMessage.NativeFlowMessage.fromObject({
         buttons: [{
            name: 'single_select',
            buttonParamsJson: JSON.stringify({
               title: dados.lista.titulo || 'Escolher',
               sections: [secao]
            })
         }]
      })
   };

   const mensagem = generateWAMessageFromContent(jid, {
      viewOnceMessage: {
         message: { interactiveMessage: proto.Message.InteractiveMessage.fromObject(conteudo) }
      }
   }, {});

   await socket.relayMessage(jid, mensagem.message, { messageId: mensagem.key.id });
   guardarEnviada(mensagem.key.id, mensagem.message);

   return mensagem.key.id;
}

async function enviarBotoesClassicos(jid, dados) {
   const enviada = await enviarGuardando(jid, {
      text: dados.texto,
      footer: dados.rodape || undefined,
      buttons: dados.botoes.map((botao) => ({
         buttonId: String(botao.id),
         buttonText: { displayText: botao.rotulo },
         type: 1
      })),
      headerType: 1
   });

   return enviada && enviada.key ? enviada.key.id : '';
}

async function enviarListaClassica(jid, dados) {
   const enviada = await enviarGuardando(jid, {
      text: dados.texto,
      footer: dados.rodape || undefined,
      title: dados.lista.titulo || 'Opcoes',
      buttonText: 'Ver opcoes',
      sections: [{
         title: dados.lista.titulo || 'Opcoes',
         rows: dados.lista.itens.map((item) => ({
            title: item.rotulo,
            description: item.descricao || '',
            rowId: String(item.id)
         }))
      }]
   });

   return enviada && enviada.key ? enviada.key.id : '';
}

/**
 * Envia o texto puro tentando cada endereco conhecido do contato
 */
async function enviarTextoPuro(jid, telefone, conteudo) {
   const enderecos = candidatosDeEnvio(jid, telefone);
   const falhas = [];

   for (const endereco of enderecos) {
      try {
         await enviarGuardando(endereco, { text: conteudo });

         if (telefone) {
            associar(String(telefone).replace(/\D/g, ''), endereco);
         }

         if (endereco !== jid) {
            registrar('Mensagem enviada por endereco alternativo', `${jid} atendido em ${endereco}`);
         }

         return endereco;
      } catch (e) {
         falhas.push(`${endereco}: ${e.message}`);
         registrar('Endereco recusado pelo WhatsApp', `${endereco} - ${e.message}`);
      }
   }

   throw new Error('Nenhum endereco aceitou a mensagem (' + falhas.join(' | ') + ')');
}

async function enviarParaJid(jid, resposta, telefone) {
   if (!conectado || !socket) {
      throw new Error('WhatsApp nao esta conectado');
   }

   const dados = normalizarResposta(resposta);

   if (dados.texto.trim() === '') {
      return true;
   }

   const temOpcoes = dados.botoes.length > 0 || (dados.lista && dados.lista.itens.length > 0);

   // Sem opcoes, ou contato que ja recusou botoes: texto puro
   if (!temOpcoes || semBotoes.has(jid)) {
      await enviarTextoPuro(jid, telefone, textoNumerado(dados));
      return true;
   }

   const destino = candidatosDeEnvio(jid, telefone)[0] || jid;

   const tentativas = dados.botoes.length > 0
      ? [['botoes classicos', enviarBotoesClassicos], ['botoes nativos', enviarBotoesNativos]]
      : [['lista classica', enviarListaClassica], ['lista nativa', enviarListaNativa]];

   for (const [nome, tentativa] of tentativas) {
      try {
         const id = await tentativa(destino, dados);

         // O WhatsApp aceita e descarta em silencio o que o aparelho nao entende
         const aceita = await esperarAck(id, 6000);

         if (aceita) {
            registrar('Opcoes enviadas', `${destino} via ${nome}`);
            return true;
         }

         registrar('Sem confirmacao do WhatsApp', `${destino} via ${nome}, tentando outro formato`);
      } catch (e) {
         registrar('Envio interativo recusado', `${destino} via ${nome} - ${e.message}`);
      }
   }

   // Nenhum formato interativo foi aceito: este contato passa a receber texto
   semBotoes.add(jid);
   registrar('Contato sem suporte a botoes', `${jid} passa a receber texto numerado`);

   await enviarTextoPuro(jid, telefone, textoNumerado(dados));

   return true;
}

async function enviarTexto(telefone, resposta) {
   if (!conectado || !socket) {
      throw new Error('WhatsApp nao esta conectado');
   }
   const jid = await resolverJid(telefone);
   await enviarParaJid(jid, resposta, telefone);
   return jid;
}

async function pararServico() {
   paradaManual = true;
   ultimoQr = null;
   if (socket) {
      try { socket.end(undefined); } catch (e) {}
      try { socket.ws?.close(); } catch (e) {}
   }
   conectado = false;
   socket = null;
}

async function desvincular() {
   try { if (socket) await socket.logout(); } catch (e) {}
   try { socket?.end(undefined); } catch (e) {}
   socket = null;
   conectado = false;
   ultimoQr = null;
   conexao = { numero: null, nome: null, desde: null };
   limparAutenticacao();
   paradaManual = false;
   setTimeout(conectar, 1500);
}

function corpoDaRequisicao(req) {
   return new Promise((resolve) => {
      let dados = '';
      req.on('data', (parte) => { dados += parte; });
      req.on('end', () => {
         try {
            resolve(JSON.parse(dados || '{}'));
         } catch (e) {
            resolve({});
         }
      });
   });
}

const servidor = http.createServer(async (req, res) => {
   res.setHeader('Content-Type', 'application/json; charset=utf-8');

   const origem = req.socket.remoteAddress || '';
   if (!origem.includes('127.0.0.1') && !origem.includes('::1')) {
      res.writeHead(403);
      res.end(JSON.stringify({ erro: 'Origem nao autorizada' }));
      return;
   }

   if (TOKEN !== '' && req.headers['x-token-interno'] !== TOKEN) {
      res.writeHead(403);
      res.end(JSON.stringify({ erro: 'Token invalido' }));
      return;
   }

   if (req.method === 'GET' && req.url === '/status') {
      res.writeHead(200);
      res.end(JSON.stringify({
         conectado,
         tem_auth: autenticacaoExiste(),
         tem_qr: !!ultimoQr,
         numero: conexao.numero,
         nome: conexao.nome,
         desde: conexao.desde,
         tempo_ms: conexao.desde ? (Date.now() - conexao.desde) : 0,
         versao_wa: versaoWa,
         versao_baileys: versaoBaileys
      }));
      return;
   }

   if (req.method === 'GET' && req.url === '/qr') {
      res.writeHead(200);
      res.end(JSON.stringify({ qr: ultimoQr }));
      return;
   }

   if (req.method === 'POST' && req.url === '/enviar') {
      const dados = await corpoDaRequisicao(req);
      if (!dados.telefone || !dados.texto) {
         res.writeHead(400);
         res.end(JSON.stringify({ ok: false, erro: 'telefone e texto sao obrigatorios' }));
         return;
      }
      try {
         const jidUsado = await enviarTexto(dados.telefone, {
            texto: dados.texto,
            rodape: dados.rodape || '',
            botoes: dados.botoes || [],
            lista: dados.lista || null
         });
         registrar('Mensagem enviada', `${dados.telefone} via ${jidUsado}`);
         res.writeHead(200);
         res.end(JSON.stringify({ ok: true, jid: jidUsado }));
      } catch (e) {
         registrar('Falha no envio', e.message);
         res.writeHead(500);
         res.end(JSON.stringify({ ok: false, erro: e.message }));
      }
      return;
   }

   if (req.method === 'POST' && req.url === '/servico/parar') {
      await pararServico();
      res.writeHead(200);
      res.end(JSON.stringify({ ok: true }));
      return;
   }

   if (req.method === 'POST' && req.url === '/servico/reiniciar') {
      await pararServico();
      setTimeout(conectar, 1000);
      res.writeHead(200);
      res.end(JSON.stringify({ ok: true }));
      return;
   }

   if (req.method === 'POST' && req.url === '/servico/desvincular') {
      await desvincular();
      res.writeHead(200);
      res.end(JSON.stringify({ ok: true }));
      return;
   }

   res.writeHead(404);
   res.end(JSON.stringify({ erro: 'Rota nao encontrada' }));
});

servidor.listen(PORTA, '127.0.0.1', () => {
   try {
      fs.mkdirSync(PASTA_AUTH, { recursive: true });
   } catch (e) {
      registrar('Falha ao preparar a pasta da sessao', e.message);
   }
   registrar('Servidor iniciado', `porta ${PORTA} - sessao em ${PASTA_AUTH}`);
   registrar('Webhook do GLPI', WEBHOOK || 'NAO CONFIGURADO');
   carregarMapa();
   conectar();
});

function encerrar(motivo) {
   registrar('Servidor parado', motivo);
   try { servidor.close(); } catch (e) {}
   process.exit(0);
}

process.on('SIGINT', () => encerrar('SIGINT'));
process.on('SIGTERM', () => encerrar('SIGTERM'));
process.on('SIGHUP', () => encerrar('SIGHUP'));

process.on('uncaughtException', (erro) => {
   registrar('Erro nao tratado', erro.message);
   process.exit(1);
});

process.on('unhandledRejection', (erro) => {
   registrar('Promise rejeitada', erro && erro.message ? erro.message : String(erro));
});
