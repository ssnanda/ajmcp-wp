(function () {
	'use strict';
	const cfg = window.AJMCPAssistant;
	const $ = (id) => document.getElementById(id);
	const ui = {
		messages: $('ajwpai-code-messages'), approvals: $('ajwpai-code-approvals'), status: $('ajwpai-code-status'),
		history: $('ajwpai-history-list'), form: $('ajwpai-code-form'), prompt: $('ajwpai-code-prompt'), send: document.querySelector('#ajwpai-code-form button'), reset: $('ajwpai-code-new')
	};
	if (!cfg || !ui.messages || !ui.form) return;

	const MAX_STEPS = 8;
	const RESULT_LIMIT = 24000;
	let history = [];     // what the server sees: {role, content, name?}
	let tools = {};       // name -> {route, approve}
	let busy = false;
	let connected = false;
	let chatId = 0;       // the saved conversation this page is continuing (0 = a new one)
	let log = [];         // what is shown on screen, saved with the conversation

	function setStatus(ok, text) {
		ui.status.textContent = text;
		ui.status.className = 'ajwpai-status ' + (ok ? 'is-online' : 'is-offline');
	}

	function setBusy(value) {
		busy = value;
		ui.prompt.disabled = value || !connected;
		ui.send.disabled = value || !connected;
		ui.reset.disabled = value;
	}

	function addLine(text, kind) {
		const el = document.createElement('div');
		el.className = 'ajwpai-message' + (kind ? ' is-' + kind : '');
		el.style.whiteSpace = 'pre-wrap';
		el.textContent = text;
		ui.messages.appendChild(el);
		ui.messages.scrollTop = ui.messages.scrollHeight;
		return el;
	}

	// keep=false is for passing notices ("Thinking…") that should not be saved with the conversation.
	function line(text, kind, keep = true) {
		if (keep) log.push({ kind: kind || 'assistant', text });
		return addLine(text, kind);
	}

	// ── Saved conversations ──
	function renderHistory(chats) {
		ui.history.textContent = '';
		if (!chats.length) {
			const empty = document.createElement('li'); empty.className = 'empty'; empty.textContent = 'None yet.';
			ui.history.appendChild(empty);
			return;
		}
		chats.forEach((chat) => {
			const li = document.createElement('li');
			if (chat.id === chatId) li.className = 'is-current';
			const open = document.createElement('button'); open.type = 'button'; open.className = 'title'; open.textContent = chat.title; open.title = chat.title;
			open.addEventListener('click', () => openChat(chat.id));
			const del = document.createElement('button'); del.type = 'button'; del.className = 'del'; del.textContent = '×'; del.title = 'Delete';
			del.addEventListener('click', () => removeChat(chat));
			li.append(open, del);
			ui.history.appendChild(li);
		});
	}

	async function loadList() {
		try { renderHistory((await wp(cfg.chatsUrl)).chats || []); } catch (error) { /* the list is optional */ }
	}

	async function save() {
		if (!history.length) return;
		try {
			const saved = await wp(cfg.chatsUrl, { method: 'POST', body: JSON.stringify({ id: chatId, history, log }) });
			chatId = saved.id;
			await loadList();
		} catch (error) {
			addLine('This conversation could not be saved: ' + error.message, 'error');
		}
	}

	function startNew() {
		history = []; log = []; chatId = 0;
		ui.messages.textContent = '';
		ui.approvals.textContent = '';
		loadList();
	}

	async function openChat(id) {
		if (busy) return;
		try {
			const chat = await wp(cfg.chatsUrl + '/' + id);
			history = chat.history || []; log = chat.log || []; chatId = chat.id;
			ui.messages.textContent = ''; ui.approvals.textContent = '';
			log.forEach((entry) => addLine(entry.text, 'assistant' === entry.kind ? '' : entry.kind));
			loadList();
			ui.prompt.focus();
		} catch (error) {
			addLine(error.message, 'error');
		}
	}

	async function removeChat(chat) {
		if (busy || !window.confirm('Delete "' + chat.title + '"?')) return;
		try {
			await wp(cfg.chatsUrl + '/' + chat.id, { method: 'DELETE' });
			if (chat.id === chatId) startNew(); else loadList();
		} catch (error) {
			addLine(error.message, 'error');
		}
	}

	async function wp(url, options) {
		const response = await fetch(url, Object.assign({ headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce } }, options));
		const data = await response.json().catch(() => ({}));
		if (!response.ok) throw new Error(data.message || ('HTTP ' + response.status));
		return data;
	}

	function trimResult(value) {
		const text = typeof value === 'string' ? value : JSON.stringify(value);
		return text.length > RESULT_LIMIT ? text.slice(0, RESULT_LIMIT) + '… (truncated)' : text;
	}

	async function runTool(name, args) {
		try {
			const data = await wp(cfg.codeUrl + tools[name].route, { method: 'POST', body: JSON.stringify(args || {}) });
			return trimResult(data);
		} catch (error) {
			return 'Error: ' + (error.message || 'the tool failed');
		}
	}

	// Resolves with the tool result text once the administrator approves (runs it) or rejects.
	function approve(name, args, labels) {
		return new Promise((resolve) => {
			const card = document.createElement('div');
			card.className = 'ajwpai-approval';
			const heading = document.createElement('h3'); heading.textContent = labels[0];
			const text = document.createElement('div'); text.textContent = labels[1];
			const details = Object.assign({}, args);
			if (typeof details.content === 'string') details.content = details.content.length + ' characters of block content';
			const pre = document.createElement('pre'); pre.textContent = JSON.stringify(details, null, 2);
			const actions = document.createElement('div'); actions.className = 'ajwpai-approval-actions';
			const yes = document.createElement('button'); yes.type = 'button'; yes.className = 'button button-primary'; yes.textContent = labels[2];
			const no = document.createElement('button'); no.type = 'button'; no.className = 'button'; no.textContent = 'Reject';
			yes.addEventListener('click', async () => { yes.disabled = no.disabled = true; const result = await runTool(name, args); card.remove(); resolve({ result, ran: true }); });
			no.addEventListener('click', () => { card.remove(); resolve({ result: 'The administrator rejected this action.', ran: false }); });
			actions.append(yes, no); card.append(heading, text, pre, actions);
			ui.approvals.appendChild(card);
			card.scrollIntoView({ block: 'nearest' });
		});
	}

	async function converse() {
		for (let step = 0; step < MAX_STEPS; step++) {
			const waiting = line('Thinking…', 'system', false);
			let turn;
			try {
				turn = await wp(cfg.turnUrl, { method: 'POST', body: JSON.stringify({ messages: history }) });
			} catch (error) {
				waiting.remove();
				line(error.message, 'error');
				return;
			}
			waiting.remove();
			if (turn.type === 'text') {
				history.push({ role: 'assistant', content: turn.text });
				line(turn.text || '(no reply)');
				return;
			}
			const def = tools[turn.name];
			if (!def) { line('The assistant asked for an unknown tool.', 'error'); return; }
			history.push({ role: 'assistant', content: JSON.stringify({ tool: turn.name, arguments: turn.arguments }) });
			let result;
			if (def.approve) {
				line('Waiting for your approval: ' + def.approve[0], 'system');
				result = (await approve(turn.name, turn.arguments, def.approve)).result;
			} else {
				line('Using ' + turn.name + '…', 'system');
				result = await runTool(turn.name, turn.arguments);
			}
			history.push({ role: 'tool', name: turn.name, content: result });
		}
		line('Stopped after ' + MAX_STEPS + ' steps. Say "continue" to go on.', 'system');
	}

	ui.form.addEventListener('submit', async (event) => {
		event.preventDefault();
		const text = ui.prompt.value.trim();
		if (!text || busy) return;
		ui.prompt.value = '';
		line(text, 'user');
		history.push({ role: 'user', content: text });
		setBusy(true);
		try { await converse(); } finally { await save(); setBusy(false); ui.prompt.focus(); }
	});

	ui.prompt.addEventListener('keydown', (event) => {
		if ('Enter' === event.key && !event.shiftKey) { event.preventDefault(); ui.form.requestSubmit(); }
	});

	ui.reset.addEventListener('click', () => { startNew(); ui.prompt.focus(); });

	(async function start() {
		setBusy(true);
		try {
			tools = (await wp(cfg.configUrl)).tools || {};
			const status = await wp(cfg.statusUrl);
			loadList();
			if (status.ok) {
				connected = true;
				setStatus(true, 'Connected' + (status.version ? ' · server v' + status.version : ''));
				setBusy(false);
			} else {
				setStatus(false, 'Not connected');
				line('disabled' === status.reason ? 'The AJMCP connector is switched off.' : 'Cannot reach the AJMCP server. Check the URL and token.', 'error');
			}
		} catch (error) {
			setStatus(false, 'Not connected');
			line(error.message, 'error');
		}
	})();
})();
