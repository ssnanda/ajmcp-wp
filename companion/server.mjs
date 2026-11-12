#!/usr/bin/env node

import { createServer } from 'node:http';
import { randomBytes, timingSafeEqual } from 'node:crypto';
import { spawn } from 'node:child_process';
import { realpathSync } from 'node:fs';
import { isAbsolute, relative, resolve } from 'node:path';

const args = process.argv.slice(2);
const value = (name, fallback) => {
	const index = args.indexOf(name);
	return index === -1 ? fallback : args[index + 1];
};
const port = Number(value('--port', '8765'));
const root = realpathSync(value('--root', process.cwd()));
const allowedOrigin = value('--origin', 'https://ncllc.ddev.site');
const token = process.env.AJWPAI_COMPANION_TOKEN || randomBytes(24).toString('hex');
const codex = value('--codex', 'codex');
const clients = new Set();
const pending = new Map();
const patchPaths = new Map();
const rpcMethods = new Set(['initialize', 'account/read', 'thread/start', 'turn/start', 'turn/interrupt']);
let nextId = 1;
let output = '';
let stopped = false;

if (!Number.isInteger(port) || port < 1024 || port > 65535) {
	throw new Error('Port must be between 1024 and 65535.');
}

const child = spawn(codex, ['app-server', '--listen', 'stdio://'], {
	cwd: root,
	stdio: ['pipe', 'pipe', 'pipe'],
	env: process.env
});

function emit(payload) {
	const data = `data: ${JSON.stringify(payload)}\n\n`;
	for (const response of clients) response.write(data);
}

function send(message) {
	if (!child.stdin.writable) throw new Error('Codex app-server is not running.');
	child.stdin.write(`${JSON.stringify(message)}\n`);
}

function insideRoot(path) {
	const target = isAbsolute(path) ? resolve(path) : resolve(root, path);
	const rel = relative(root, target);
	return rel === '' || (!rel.startsWith('..') && !isAbsolute(rel));
}

child.stdout.setEncoding('utf8');
child.stdout.on('data', (chunk) => {
	output += chunk;
	let newline;
	while ((newline = output.indexOf('\n')) !== -1) {
		const line = output.slice(0, newline).trim();
		output = output.slice(newline + 1);
		if (!line) continue;
		try {
			const message = JSON.parse(line);
			if (message.method === 'item/fileChange/patchUpdated') {
				patchPaths.set(message.params.itemId, (message.params.changes || []).map((change) => change.path));
			}
			if (message.method === 'item/fileChange/requestApproval') {
				const paths = patchPaths.get(message.params.itemId) || [];
				if (!paths.length || paths.some((path) => !insideRoot(path))) {
					send({ jsonrpc: '2.0', id: message.id, result: { decision: 'decline' } });
					emit({ type: 'bridge-error', message: 'Blocked a file change outside the configured workspace.' });
					continue;
				}
			}
			if (message.method === 'item/commandExecution/requestApproval' || message.method === 'execCommandApproval') {
				send({ jsonrpc: '2.0', id: message.id, result: { decision: 'decline' } });
				emit({ type: 'bridge-error', message: 'Command execution is disabled in this version.' });
				continue;
			}
			if (Object.hasOwn(message, 'id') && !message.method && pending.has(String(message.id))) {
				const entry = pending.get(String(message.id));
				pending.delete(String(message.id));
				clearTimeout(entry.timer);
				entry.resolve(message);
			} else {
				emit(message);
			}
		} catch {
			emit({ type: 'bridge-error', message: 'Codex returned invalid JSON.' });
		}
	}
});

child.stderr.setEncoding('utf8');
child.stderr.on('data', (chunk) => {
	const message = chunk.trim();
	if (message) emit({ type: 'bridge-log', message });
});

child.on('exit', (code, signal) => {
	stopped = true;
	for (const entry of pending.values()) {
		clearTimeout(entry.timer);
		entry.reject(new Error(`Codex stopped (${signal || code}).`));
	}
	pending.clear();
	emit({ type: 'bridge-error', message: 'Codex app-server stopped.' });
});

function authorized(request) {
	const supplied = request.headers['x-aj-wp-ai-token'];
	if (typeof supplied !== 'string') return false;
	const left = Buffer.from(supplied);
	const right = Buffer.from(token);
	return left.length === right.length && timingSafeEqual(left, right);
}

function headers(request, response) {
	const origin = request.headers.origin;
	if (origin === allowedOrigin) response.setHeader('Access-Control-Allow-Origin', origin);
	response.setHeader('Access-Control-Allow-Headers', 'Content-Type, X-AJ-WP-AI-Token');
	response.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
	response.setHeader('Access-Control-Allow-Private-Network', 'true');
	response.setHeader('Vary', 'Origin');
	response.setHeader('Cache-Control', 'no-store');
}

function json(response, status, data) {
	response.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8' });
	response.end(JSON.stringify(data));
}

async function body(request) {
	let raw = '';
	for await (const chunk of request) {
		raw += chunk;
		if (raw.length > 1024 * 1024) throw new Error('Request is too large.');
	}
	const parsed = JSON.parse(raw || '{}');
	if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) throw new Error('Invalid request.');
	return parsed;
}

function rpc(method, params) {
	const id = nextId++;
	return new Promise((resolve, reject) => {
		const timer = setTimeout(() => {
			pending.delete(String(id));
			reject(new Error('Codex request timed out.'));
		}, 120000);
		pending.set(String(id), { resolve, reject, timer });
		send({ jsonrpc: '2.0', id, method, params });
	});
}

const server = createServer(async (request, response) => {
	headers(request, response);
	if (request.method === 'OPTIONS') {
		response.writeHead(204); response.end(); return;
	}
	if (request.headers.origin && request.headers.origin !== allowedOrigin) {
		json(response, 403, { error: 'Origin not allowed.' }); return;
	}
	if (!authorized(request)) {
		json(response, 401, { error: 'Invalid companion token.' }); return;
	}
	try {
		if (request.method === 'GET' && request.url === '/health') {
			json(response, stopped ? 503 : 200, { ok: !stopped, root }); return;
		}
		if (request.method === 'GET' && request.url === '/events') {
			response.writeHead(200, {
				'Content-Type': 'text/event-stream; charset=utf-8',
				'Connection': 'keep-alive',
				'X-Accel-Buffering': 'no'
			});
			response.write(': connected\n\n');
			clients.add(response);
			request.on('close', () => clients.delete(response));
			return;
		}
		if (request.method === 'POST' && request.url === '/rpc') {
			const input = await body(request);
			if (typeof input.method !== 'string') throw new Error('Method is required.');
			if (!rpcMethods.has(input.method)) throw new Error('RPC method is not allowed.');
			const params = input.params && typeof input.params === 'object' ? { ...input.params } : {};
			if ('thread/start' === input.method) {
				params.cwd = root;
				params.runtimeWorkspaceRoots = [root];
				params.sandbox = 'read-only';
				params.approvalPolicy = 'on-request';
				params.approvalsReviewer = 'user';
			}
			if ('turn/start' === input.method) {
				params.cwd = root;
				params.runtimeWorkspaceRoots = [root];
				params.sandboxPolicy = { type: 'readOnly', networkAccess: false };
				params.approvalPolicy = 'on-request';
				params.approvalsReviewer = 'user';
			}
			json(response, 200, await rpc(input.method, params)); return;
		}
		if (request.method === 'POST' && request.url === '/notify') {
			const input = await body(request);
			if (typeof input.method !== 'string') throw new Error('Method is required.');
			send({ jsonrpc: '2.0', method: input.method, params: input.params || {} });
			json(response, 200, { ok: true }); return;
		}
		if (request.method === 'POST' && request.url === '/reply') {
			const input = await body(request);
			if ((!Number.isInteger(input.id) && typeof input.id !== 'string') || !input.result) throw new Error('Reply id and result are required.');
			send({ jsonrpc: '2.0', id: input.id, result: input.result });
			json(response, 200, { ok: true }); return;
		}
		json(response, 404, { error: 'Not found.' });
	} catch (error) {
		json(response, 400, { error: error.message || 'Request failed.' });
	}
});

server.listen(port, '127.0.0.1', () => {
	console.log(`AJ WP AI companion: http://127.0.0.1:${port}`);
	console.log(`Workspace: ${root}`);
	console.log(`Origin: ${allowedOrigin}`);
	console.log(`Token: ${token}`);
});

function shutdown() {
	server.close();
	child.kill('SIGTERM');
}

process.on('SIGINT', shutdown);
process.on('SIGTERM', shutdown);
