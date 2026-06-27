#!/usr/bin/env node
/**
 * Minimal ERP webhook receiver for CI/local HTTP-level webhook QA.
 * Validates X-RFQ-Signature when RFQ_ERP_WEBHOOK_SECRET is set.
 *
 * Usage:
 *   RFQ_ERP_WEBHOOK_SECRET=ci-secret node scripts/ci-webhook-mock.mjs
 *   curl http://127.0.0.1:8765/requests
 */

import { createServer } from 'node:http';
import { createHmac, timingSafeEqual } from 'node:crypto';

const HOST = process.env.RFQ_WEBHOOK_MOCK_HOST ?? '0.0.0.0';
const PORT = Number(process.env.RFQ_WEBHOOK_MOCK_PORT ?? '8765');
const PATH = process.env.RFQ_WEBHOOK_MOCK_PATH ?? '/rfq/import';
const SECRET = process.env.RFQ_ERP_WEBHOOK_SECRET ?? 'ci-webhook-secret';

/** @type {Array<{received_at: string, url: string, headers: Record<string, string>, body: string, signature_valid: boolean|null}>} */
const requests = [];

function readBody(req) {
  return new Promise((resolve, reject) => {
    const chunks = [];
    req.on('data', (chunk) => chunks.push(chunk));
    req.on('end', () => resolve(Buffer.concat(chunks).toString('utf8')));
    req.on('error', reject);
  });
}

function verifySignature(body, signatureHeader) {
  if (!SECRET) {
    return null;
  }

  if (!signatureHeader) {
    return false;
  }

  const expected = createHmac('sha256', SECRET).update(body).digest('hex');

  try {
    return timingSafeEqual(Buffer.from(expected), Buffer.from(signatureHeader));
  } catch {
    return false;
  }
}

const server = createServer(async (req, res) => {
  const url = new URL(req.url ?? '/', `http://${HOST}:${PORT}`);

  if (req.method === 'GET' && url.pathname === '/health') {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ status: 'ok' }));
    return;
  }

  if (req.method === 'GET' && url.pathname === '/requests') {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ count: requests.length, requests }));
    return;
  }

  if (req.method === 'POST' && url.pathname === PATH) {
    const body = await readBody(req);
    const signature = req.headers['x-rfq-signature'];
    const signatureHeader = Array.isArray(signature) ? signature[0] : signature ?? '';
    const signatureValid = verifySignature(body, signatureHeader);

    if (SECRET && signatureValid === false) {
      res.writeHead(401, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ error: 'invalid signature' }));
      return;
    }

    requests.push({
      received_at: new Date().toISOString(),
      url: url.pathname,
      headers: {
        'content-type': String(req.headers['content-type'] ?? ''),
        'x-rfq-signature': signatureHeader,
      },
      body,
      signature_valid: signatureValid,
    });

    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ status: 'accepted' }));
    return;
  }

  res.writeHead(404, { 'Content-Type': 'application/json' });
  res.end(JSON.stringify({ error: 'not found' }));
});

server.listen(PORT, HOST, () => {
  process.stdout.write(`RFQ webhook mock listening on http://${HOST}:${PORT}${PATH}\n`);
});
