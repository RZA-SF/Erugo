/**
 * Load scenario 03: Authenticated share management operations.
 *
 * Simulates users browsing, expiring, and extending their shares
 * concurrently — the main settings panel workload.
 *
 * Run:
 *   k6 run --env BASE_URL=https://your-instance.example.com \
 *           --env EMAIL=user@example.com \
 *           --env PASSWORD=secret \
 *           tests/load/k6/03_share_management.js
 */
import http from 'k6/http';
import { check, group, sleep } from 'k6';
import { Rate, Trend, Counter } from 'k6/metrics';
import { login, authHeaders } from './utils/auth.js';
import { getShareLongIds, randomItem } from './utils/data.js';

const BASE_URL = __ENV.BASE_URL || 'http://localhost';
const EMAIL    = __ENV.EMAIL    || 'admin@example.com';
const PASSWORD = __ENV.PASSWORD || 'password';

const listLatency   = new Trend('list_shares_latency_ms', true);
const expireLatency = new Trend('expire_share_latency_ms', true);
const extendLatency = new Trend('extend_share_latency_ms', true);
const errorRate     = new Rate('mgmt_errors');
const ops           = new Counter('mgmt_operations_total');

export const options = {
  stages: [
    { duration: '30s', target: 20 },
    { duration: '2m',  target: 50 },
    { duration: '30s', target: 0  },
  ],
  thresholds: {
    'list_shares_latency_ms':   ['p(95)<300'],
    'expire_share_latency_ms':  ['p(95)<500'],
    'extend_share_latency_ms':  ['p(95)<500'],
    'mgmt_errors':              ['rate<0.02'],
  },
};

export function setup() {
  const token = login(EMAIL, PASSWORD);
  if (!token) throw new Error('setup: login failed');
  const shareIds = getShareLongIds(token);
  return { token, shareIds };
}

export default function (data) {
  const { token, shareIds } = data;
  const headers = authHeaders(token);

  group('list own shares', () => {
    const start = Date.now();
    const res = http.get(`${BASE_URL}/api/shares`, { headers });
    listLatency.add(Date.now() - start);
    ops.add(1);

    const ok = check(res, {
      'list 200':        (r) => r.status === 200,
      'list has shares': (r) => {
        try { return Array.isArray(JSON.parse(r.body).data?.shares); } catch { return false; }
      },
    });
    errorRate.add(!ok);
  });

  sleep(0.5);

  // Only run mutating ops if we have shares to act on
  if (shareIds.length === 0) {
    sleep(2);
    return;
  }

  // Pick a random share for extend (safe, non-destructive)
  const shareId = randomItem(shareIds);

  group('extend share', () => {
    const start = Date.now();
    // Original extend endpoint: no body required
    const res = http.post(`${BASE_URL}/api/shares/${shareId}/extend`, null, { headers });
    extendLatency.add(Date.now() - start);
    ops.add(1);

    const ok = check(res, { 'extend 200': (r) => r.status === 200 });
    errorRate.add(!ok);
  });

  sleep(1 + Math.random() * 2);
}

export function teardown(data) {
  // Nothing to clean up — extend is idempotent
}
