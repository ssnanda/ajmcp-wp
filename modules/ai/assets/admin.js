(() => {
	'use strict';
	const $ = (id) => document.getElementById(id);
	const ui = {
		status: $('ajwpai-code-status'), endpoint: $('ajwpai-code-endpoint'), token: $('ajwpai-code-token'),
		connect: $('ajwpai-code-connect'), root: $('ajwpai-code-root'), mode: $('ajwpai-code-mode'),
		newChat: $('ajwpai-code-new'), messages: $('ajwpai-code-messages'), approvals: $('ajwpai-code-approvals'),
		form: $('ajwpai-code-form'), prompt: $('ajwpai-code-prompt'), send: document.querySelector('#ajwpai-code-form button')
	};
	if (!ui.status) return;
	let endpoint = AJWPAICode.endpoint;
	let token = '';
	let threadId = null;
	let connected = false;
	let streaming = null;
	const patches = new Map();
	if (ui.root) ui.root.textContent = AJWPAICode.root;
	if (ui.endpoint) ui.endpoint.value = localStorage.getItem('ajwpai_endpoint') || AJWPAICode.endpoint;
	if (ui.token) ui.token.value = sessionStorage.getItem('ajwpai_token') || '';

	function message(text, type = 'assistant', key = '', rich = false) {
		if (!ui.messages) return null;
		let el = key ? document.querySelector(`[data-message="${CSS.escape(key)}"]`) : null;
		if (!el) {
			el = document.createElement('div');
			el.className = `ajwpai-message is-${type}`;
			if (key) el.dataset.message = key;
			ui.messages.appendChild(el);
		}
		if (rich) renderMarkdown(el, text); else el.textContent = text;
		ui.messages.scrollTop = ui.messages.scrollHeight;
		return el;
	}

	function renderMarkdown(container, text) {
		container.textContent = '';
		const lines = String(text).split('\n');
		for (let index = 0; index < lines.length;) {
			const line = lines[index].trim();
			if (!line) { index++; continue; }
			if (line.startsWith('|') && index + 1 < lines.length && /^\s*\|?\s*:?-{3,}/.test(lines[index + 1])) {
				const table = document.createElement('table'); table.className = 'ajwpai-result-table';
				const cells = (value) => value.trim().replace(/^\||\|$/g, '').split('|').map((cell) => cell.trim());
				const head = document.createElement('thead'); const headRow = document.createElement('tr');
				cells(lines[index]).forEach((value) => { const th = document.createElement('th'); th.textContent = value; headRow.appendChild(th); });
				head.appendChild(headRow); table.appendChild(head); index += 2;
				const body = document.createElement('tbody');
				while (index < lines.length && lines[index].trim().startsWith('|')) {
					const row = document.createElement('tr');
					cells(lines[index]).forEach((value) => { const td = document.createElement('td'); td.textContent = value; row.appendChild(td); });
					body.appendChild(row); index++;
				}
				table.appendChild(body); container.appendChild(table); continue;
			}
			if (/^[-*]\s+/.test(line)) {
				const list = document.createElement('ul');
				while (index < lines.length && /^\s*[-*]\s+/.test(lines[index])) {
					const item = document.createElement('li'); item.textContent = lines[index].replace(/^\s*[-*]\s+/, ''); list.appendChild(item); index++;
				}
				container.appendChild(list); continue;
			}
			if (/^\d+\.\s+/.test(line)) {
				const list = document.createElement('ol');
				while (index < lines.length && /^\s*\d+\.\s+/.test(lines[index])) {
					const item = document.createElement('li'); item.textContent = lines[index].replace(/^\s*\d+\.\s+/, ''); list.appendChild(item); index++;
				}
				container.appendChild(list); continue;
			}
			if (line.startsWith('```')) {
				const code = []; index++;
				while (index < lines.length && !lines[index].trim().startsWith('```')) code.push(lines[index++]);
				if (index < lines.length) index++;
				const pre = document.createElement('pre'); pre.textContent = code.join('\n'); container.appendChild(pre); continue;
			}
			const paragraph = document.createElement('p'); paragraph.textContent = line; container.appendChild(paragraph); index++;
		}
	}

	async function request(path, body) {
		const response = await fetch(endpoint + path, {
			method: body === undefined ? 'GET' : 'POST',
			headers: {'Content-Type': 'application/json', 'X-AJ-WP-AI-Token': token},
			body: body === undefined ? undefined : JSON.stringify(body)
		});
		const data = await response.json().catch(() => ({}));
		if (!response.ok) throw new Error(data.error || `HTTP ${response.status}`);
		return data;
	}

	async function rpc(method, params = {}) {
		const response = await request('/rpc', {method, params});
		if (response.error) throw new Error(response.error.message || 'Codex request failed.');
		return response.result;
	}

	async function notify(method, params = {}) {
		return request('/notify', {method, params});
	}

	async function eventStream() {
		const response = await fetch(endpoint + '/events', {headers: {'X-AJ-WP-AI-Token': token}});
		if (!response.ok || !response.body) throw new Error('Event stream unavailable.');
		const reader = response.body.getReader();
		const decoder = new TextDecoder();
		let buffer = '';
		while (connected) {
			const {value, done} = await reader.read();
			if (done) break;
			buffer += decoder.decode(value, {stream: true});
			let split;
			while ((split = buffer.indexOf('\n\n')) !== -1) {
				const block = buffer.slice(0, split); buffer = buffer.slice(split + 2);
				const line = block.split('\n').find((part) => part.startsWith('data:'));
				if (line) handleEvent(JSON.parse(line.slice(5).trim()));
			}
		}
	}

	function handleEvent(event) {
		if (event.method === 'item/agentMessage/delta') {
			const id = event.params.itemId;
			const el = message('', 'assistant', id);
			el.textContent += event.params.delta;
			streaming = id;
		} else if (event.method === 'item/completed' && event.params?.item?.type === 'agentMessage') {
			message(event.params.item.text || '', 'assistant', event.params.item.id, true);
		} else if (event.method === 'item/tool/call') {
			wordpressTool(event);
		} else if (event.method === 'item/fileChange/patchUpdated') {
			patches.set(event.params.itemId, event.params.changes || []);
		} else if (event.method === 'item/fileChange/requestApproval') {
			approval(event.id, 'Files', event.params.reason || 'Apply proposed file changes?', patches.get(event.params.itemId) || []);
		} else if (event.method === 'item/commandExecution/requestApproval') {
			approval(event.id, 'Command', event.params.reason || 'Run this command?', event.params.command || '');
		} else if (event.method === 'turn/completed') {
			streaming = null;
			setBusy(false);
		} else if (event.method === 'error') {
			message(event.params?.error?.message || 'Codex error.', 'error');
			setBusy(false);
		} else if (event.type === 'bridge-error') {
			message(event.message, 'error');
		}
	}

	async function wordpressTool(event) {
		const tool = event.params?.tool;
		const routes = {
			list_posts: 'posts', get_design_catalog: 'design-catalog', create_draft: 'draft', create_designed_draft: 'designed-draft',
			search_leads: 'ajcore/leads', get_lead: 'ajcore/lead', add_lead_note: 'ajcore/lead-note', tag_lead: 'ajcore/lead-tags',
			update_lead_status: 'ajcore/lead-status', send_lead_email: 'ajcore/lead-email', send_lead_campaign: 'ajcore/lead-campaign',
			list_live_chats: 'ajcore/chats', get_live_chat: 'ajcore/chat', reply_live_chat: 'ajcore/chat-reply', escalate_live_chat: 'ajcore/chat-escalate'
		};
		const approvals = {
			create_draft: ['Create draft', 'Create this WordPress draft?', 'Create draft'],
			create_designed_draft: ['Create designed draft', 'Create this block-native WordPress draft?', 'Create draft'],
			add_lead_note: ['Add lead note', 'Add this internal note to the lead?', 'Add note'],
			tag_lead: ['Update lead tags', 'Replace this lead’s internal tags?', 'Update tags'],
			update_lead_status: ['Update lead status', 'Change this lead’s pipeline status?', 'Update status'],
			send_lead_email: ['Send lead email', 'Send this email through AJ Core Mail?', 'Send email'],
			send_lead_campaign: ['Send lead campaign', 'Send this message to every selected lead?', 'Send campaign'],
			reply_live_chat: ['Send live-chat reply', 'Send this reply to the visitor now?', 'Send reply'],
			escalate_live_chat: ['Escalate live chat', 'Claim this chat and pause automation?', 'Escalate']
		};
		if (approvals[tool]) {
			wordpressApproval(event, routes, approvals[tool]);
			return;
		}
		await runWordPressTool(event, routes);
	}

	function wordpressApproval(event, routes, labels) {
		const card = document.createElement('div');
		card.className = 'ajwpai-approval';
		const heading = document.createElement('h3'); heading.textContent = labels[0];
		const text = document.createElement('div'); text.textContent = labels[1];
		const details = {...(event.params.arguments || {})};
		if (details.content) details.content = `${details.content.length} characters of Gutenberg block content`;
		const pre = document.createElement('pre'); pre.textContent = JSON.stringify(details, null, 2);
		const actions = document.createElement('div'); actions.className = 'ajwpai-approval-actions';
		const approve = document.createElement('button'); approve.type = 'button'; approve.className = 'button button-primary'; approve.textContent = labels[2];
		const reject = document.createElement('button'); reject.type = 'button'; reject.className = 'button'; reject.textContent = 'Reject';
		approve.addEventListener('click', async () => {
			approve.disabled = true; reject.disabled = true;
			await runWordPressTool(event, routes);
			card.remove();
		});
		reject.addEventListener('click', async () => {
			approve.disabled = true; reject.disabled = true;
			await request('/reply', {id: event.id, result: {success: false, contentItems: [{type: 'inputText', text: 'The administrator rejected this action.'}]}});
			card.remove();
		});
		actions.append(approve, reject); card.append(heading, text, pre, actions); ui.approvals.appendChild(card);
	}

	async function runWordPressTool(event, routes) {
		const tool = event.params?.tool;
		let result;
		try {
			if (!routes[tool] || event.params?.namespace !== 'wordpress') throw new Error('WordPress tool is not allowed.');
			const response = await fetch(AJWPAICode.restUrl + routes[tool], {
				method: 'POST',
				headers: {'Content-Type': 'application/json', 'X-WP-Nonce': AJWPAICode.restNonce},
				body: JSON.stringify(event.params.arguments || {})
			});
			const data = await response.json().catch(() => ({}));
			if (!response.ok) throw new Error(data.message || `WordPress HTTP ${response.status}`);
			result = {success: true, contentItems: [{type: 'inputText', text: JSON.stringify(data)}]};
		} catch (error) {
			result = {success: false, contentItems: [{type: 'inputText', text: error.message || 'WordPress tool failed.'}]};
		}
		try { await request('/reply', {id: event.id, result}); }
		catch (error) { message(error.message, 'error'); setBusy(false); }
	}

	function approval(id, title, reason, detail) {
		const card = document.createElement('div');
		card.className = 'ajwpai-approval';
		const heading = document.createElement('h3'); heading.textContent = title;
		const text = document.createElement('div'); text.textContent = reason;
		const pre = document.createElement('pre');
		pre.textContent = Array.isArray(detail) ? detail.map((item) => `${item.path}\n${item.diff}`).join('\n\n') : detail;
		const actions = document.createElement('div'); actions.className = 'ajwpai-approval-actions';
		[['Approve', 'accept', 'button button-primary'], ['Reject', 'decline', 'button']].forEach(([label, decision, classes]) => {
			const button = document.createElement('button'); button.type = 'button'; button.className = classes; button.textContent = label;
			button.addEventListener('click', async () => {
				button.disabled = true;
				try { await request('/reply', {id, result: {decision}}); card.remove(); }
				catch (error) { message(error.message, 'error'); button.disabled = false; }
			});
			actions.appendChild(button);
		});
		card.append(heading, text, pre, actions); ui.approvals.appendChild(card);
	}

	function setBusy(busy) {
		if (ui.prompt) ui.prompt.disabled = busy || !connected;
		if (ui.send) ui.send.disabled = busy || !connected;
	}

	async function newThread() {
		const mode = ui.mode.value;
		const site = AJWPAICode.site || {};
		const context = `The active WordPress admin is ${site.name || 'this site'} at ${site.url || ''}. Its local DDEV project is ${site.path || ''}. Treat references to "this site" or "my posts" as that WordPress site. Use only read-only WP-CLI commands for content inspection. Never edit its vendored plugin or theme files; code changes belong in the configured canonical workspace.`;
		const approvalInstructions = 'When an available tool has its own approval UI, call it immediately after the user requests the action. Never ask for confirmation in chat first; the tool approval card is the confirmation. For rich blog requests, call get_design_catalog, then create_designed_draft. Compose native Gutenberg block markup using the active theme catalog. Never use Custom HTML, inline CSS, or invented block names. AJCore read tools may run immediately. Lead changes, email sends, campaigns, live-chat replies, and escalations have host approval cards; call them without a separate chat confirmation. Never expose or repeat private lead data unless needed for the requested task.';
		const instructions = context + '\n\n' + approvalInstructions + '\n\n' + (mode === 'plan'
			? 'Plan mode. Inspect and explain only. Do not modify files or run commands that change state.'
			: 'Edit mode. Work only inside the configured workspace. Keep reasoning concise. Use the provided approval UI for file changes and state-changing tools without a separate chat confirmation. Never commit, push, deploy, install, delete, or run builds/tests.');
		const result = await rpc('thread/start', {
			cwd: AJWPAICode.root,
			runtimeWorkspaceRoots: [AJWPAICode.root],
			sandbox: 'read-only',
			approvalPolicy: 'on-request',
			approvalsReviewer: 'user',
			developerInstructions: instructions,
			ephemeral: false,
			dynamicTools: [{
				type: 'namespace',
				name: 'wordpress',
				description: 'Authenticated tools for the WordPress site open in wp-admin.',
				tools: [
					{type: 'function', name: 'list_posts', description: 'List posts or pages from the active WordPress site.', deferLoading: false, inputSchema: {type: 'object', properties: {post_type: {type: 'string', enum: ['post', 'page']}, search: {type: 'string'}, limit: {type: 'integer', minimum: 1, maximum: 50}}, additionalProperties: false}},
					{type: 'function', name: 'get_design_catalog', description: 'Get the active WordPress theme patterns, blog block styles, and layout rules. Call before creating a designed blog.', deferLoading: false, inputSchema: {type: 'object', properties: {}, additionalProperties: false}},
					{type: 'function', name: 'create_draft', description: 'Create a WordPress post or page as a draft. It can never publish. Call immediately; the host supplies approval.', deferLoading: false, inputSchema: {type: 'object', properties: {post_type: {type: 'string', enum: ['post', 'page']}, title: {type: 'string'}, content: {type: 'string'}, excerpt: {type: 'string'}}, required: ['title'], additionalProperties: false}},
					{type: 'function', name: 'create_designed_draft', description: 'Create a polished WordPress draft made only from native Gutenberg core/AJNanda blocks. Use headings, lists, callouts, cards, FAQs, media, and a CTA. Never use Custom HTML, inline CSS, or invented blocks. Call get_design_catalog first. Host approval is automatic.', deferLoading: false, inputSchema: {type: 'object', properties: {post_type: {type: 'string', enum: ['post', 'page']}, title: {type: 'string'}, excerpt: {type: 'string'}, content: {type: 'string', description: 'Serialized Gutenberg block markup.'}}, required: ['title', 'content'], additionalProperties: false}},
					{type: 'function', name: 'search_leads', description: 'Search AJCore leads. Results minimize contact data.', deferLoading: false, inputSchema: {type: 'object', properties: {search: {type: 'string'}, limit: {type: 'integer', minimum: 1, maximum: 50}}, additionalProperties: false}},
					{type: 'function', name: 'get_lead', description: 'Get one AJCore lead and its notes. Use only when details are needed.', deferLoading: false, inputSchema: {type: 'object', properties: {lead_id: {type: 'integer'}}, required: ['lead_id'], additionalProperties: false}},
					{type: 'function', name: 'add_lead_note', description: 'Add an internal AJCore lead note. Host approval is required.', deferLoading: false, inputSchema: {type: 'object', properties: {lead_id: {type: 'integer'}, note: {type: 'string'}}, required: ['lead_id', 'note'], additionalProperties: false}},
					{type: 'function', name: 'tag_lead', description: 'Replace a lead’s internal tags. Host approval is required.', deferLoading: false, inputSchema: {type: 'object', properties: {lead_id: {type: 'integer'}, tags: {type: 'array', items: {type: 'string'}, maxItems: 20}}, required: ['lead_id', 'tags'], additionalProperties: false}},
					{type: 'function', name: 'update_lead_status', description: 'Update an AJCore lead pipeline status. Host approval is required.', deferLoading: false, inputSchema: {type: 'object', properties: {lead_id: {type: 'integer'}, status: {type: 'string', enum: ['new', 'auto_reached', 'reached', 'engaged', 'tour', 'customer', 'future_follow_up', 'lost']}, note: {type: 'string'}, follow_up_at: {type: 'string'}, customer_name: {type: 'string'}}, required: ['lead_id', 'status'], additionalProperties: false}},
					{type: 'function', name: 'send_lead_email', description: 'Send one personalized email through AJ Core Mail. Supports {name}, {email}, and {site_name}. Host approval is required; do not ask in chat.', deferLoading: false, inputSchema: {type: 'object', properties: {lead_id: {type: 'integer'}, subject: {type: 'string'}, body: {type: 'string'}}, required: ['lead_id', 'subject', 'body'], additionalProperties: false}},
					{type: 'function', name: 'send_lead_campaign', description: 'Send one personalized message to up to 50 explicitly selected lead IDs. Supports {name}, {email}, and {site_name}. Campaign-level host approval is required.', deferLoading: false, inputSchema: {type: 'object', properties: {lead_ids: {type: 'array', items: {type: 'integer'}, minItems: 1, maxItems: 50}, subject: {type: 'string'}, body: {type: 'string'}}, required: ['lead_ids', 'subject', 'body'], additionalProperties: false}},
					{type: 'function', name: 'list_live_chats', description: 'List AJCore live-chat sessions.', deferLoading: false, inputSchema: {type: 'object', properties: {status: {type: 'string', enum: ['open', 'closed']}, search: {type: 'string'}, limit: {type: 'integer', minimum: 1, maximum: 50}}, additionalProperties: false}},
					{type: 'function', name: 'get_live_chat', description: 'Read one AJCore live-chat conversation.', deferLoading: false, inputSchema: {type: 'object', properties: {chat_id: {type: 'integer'}}, required: ['chat_id'], additionalProperties: false}},
					{type: 'function', name: 'reply_live_chat', description: 'Send a staff reply to an AJCore live chat and pause automation. Host approval is required.', deferLoading: false, inputSchema: {type: 'object', properties: {chat_id: {type: 'integer'}, body: {type: 'string'}}, required: ['chat_id', 'body'], additionalProperties: false}},
					{type: 'function', name: 'escalate_live_chat', description: 'Claim a live chat for the current administrator and pause automation. Host approval is required.', deferLoading: false, inputSchema: {type: 'object', properties: {chat_id: {type: 'integer'}}, required: ['chat_id'], additionalProperties: false}}
				]
			}]
		});
		threadId = result.thread.id;
		ui.messages.textContent = '';
		ui.approvals.textContent = '';
		message(`${mode === 'plan' ? 'Plan' : 'Edit'} chat ready.`, 'system');
	}

	async function connect() {
		endpoint = (ui.endpoint?.value || localStorage.getItem('ajwpai_endpoint') || AJWPAICode.endpoint).replace(/\/$/, '');
		token = (ui.token?.value || sessionStorage.getItem('ajwpai_token') || '').trim();
		if (!token) { message('Add the companion token under AJ WP AI Settings.', 'error'); return; }
		if (ui.connect) ui.connect.disabled = true;
		try {
			await request('/health');
			connected = true;
			let initialized = false;
			try {
				await rpc('initialize', {clientInfo: {name: 'aj_wp_ai', title: 'AJ WP AI', version: '0.4.5'}, capabilities: {experimentalApi: true}});
				initialized = true;
			} catch (error) {
				if (!/already initialized/i.test(error.message || '')) throw error;
			}
			if (initialized) await notify('initialized');
			const account = await rpc('account/read', {refreshToken: false});
			if (!account.account) throw new Error('Codex is not signed in. Run codex login first.');
			localStorage.setItem('ajwpai_endpoint', endpoint); sessionStorage.setItem('ajwpai_token', token);
			ui.status.textContent = 'Connected'; ui.status.classList.replace('is-offline', 'is-online');
			if (ui.newChat) {
				ui.newChat.disabled = false; setBusy(false);
				eventStream().catch((error) => { if (connected) message(error.message, 'error'); });
				await newThread();
			} else {
				message('Connection saved.', 'system');
			}
		} catch (error) {
			connected = false; message(error.message, 'error');
		} finally { if (ui.connect) ui.connect.disabled = false; }
	}

	if (ui.connect) ui.connect.addEventListener('click', connect);
	if (ui.newChat) ui.newChat.addEventListener('click', () => newThread().catch((error) => message(error.message, 'error')));
	if (ui.form) ui.form.addEventListener('submit', async (event) => {
		event.preventDefault(); const text = ui.prompt.value.trim(); if (!text || !threadId) return;
		message(text, 'user'); ui.prompt.value = ''; setBusy(true);
		try { await rpc('turn/start', {threadId, input: [{type: 'text', text}]}); }
		catch (error) { message(error.message, 'error'); setBusy(false); }
	});
	if (!ui.connect && sessionStorage.getItem('ajwpai_token')) connect();
})();
