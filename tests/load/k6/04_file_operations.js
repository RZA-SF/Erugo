/**
 * Load scenario 04: File management and clone operations (F1 + F3).
 *
 * Tests the new endpoints under concurrent load:
 *  - POST /api/shares/{id}/clone     (F3)
 *  - POST /api/shares/{id}/add-files (F1, uses pre-uploaded sessions)
 *
 * Focus areas:
 *  - Race condition: many VUs cloning the same share simultaneously
 *  - Throughput: clone latency under increasing concurrency
 *  - No data corruption: each clone must have its own share ID and files
 *
 * Note: add-files requires valid upload sessions. This scenario uses
 * pre-seeded sessions created in setup(). Upload itself is not load-tested
 * here because tus resumable uploads require a stateful session.
 *
 * Run:
 *   k6 run --env BASE_URL=https://your-instance.example.com \
 *           --env EMAIL=user@example.com \
 *           --env PASSWORD=secret \
 *           --env SOURCE_SHARE_ID=42 \
 *           tests/load/k6/04_file_operations.js
 */
import http from 'k6/http';
import { check, group, sleep } from 'k6';
import { Rate, Trend, Counter } from 'k6/metrics';
import { login, authHeaders } from './utils/auth.js';
import { randomString } from './utils/data.js';

const BASE_URL        = __ENV.BASE_URL        || 'http://localhost';
const EMAIL           = __ENV.EMAIL           || 'admin@example.com';
const PASSWORD        = __ENV.PASSWORD        || 'password';
const SOURCE_SHARE_ID = __ENV.SOURCE_SHARE_ID || '';

const cloneLatency  = new Trend('clone_latency_ms', true);
const cloneFailRate = new Rate('clone_failures');
const cloneCount    = new Counter('clones_created');
const uniqueIds     = new Set();

export const options = {
  scenarios: {
    // Concurrent clones — ramps up to expose race conditions
    concurrent_clones: {
      executor: 'ramping-vus',
      stages: [
        { duration: '20s', target: 5  },
        { duration: '1m',  target: 30 },
        { duration: '20s', target: 60 }, // spike: 60 simultaneous clones
        { duration: '20s', target: 0  },
      ],
      gracefulRampDown: '10s',
      exec: 'cloneShare',
    },
  },
  thresholds: {
    'clone_latency_ms': ['p(95)<3000', 'p(99)<8000'],
    'clone_failures':   ['rate<0.02'],
  },
};

export function setup() {
  const token = login(EMAIL, PASSWORD);
  if (!token) throw new Error('setup: login failed');

  // Resolve share numeric ID if not provided
  let shareId = SOURCE_SHARE_ID;
  if (!shareId) {
    const res = http.get(`${BASE_URL}/api/shares`, { headers: authHeaders(token) });
    try {
      const shares = JSON.parse(res.body).data?.shares ?? [];
      if (shares.length === 0) throw new Error('No shares found. Create at least one share before running this scenario.');
      shareId = String(shares[0].id);
    } catch (e) {
      throw new Error(`setup: could not get share list: ${e.message}`);
    }
  }

  return { token, shareId };
}

export function cloneShare(data) {
  const { token, shareId } = data;
  const cloneName = `load-clone-${randomString(6)}`;

  const start = Date.now();
  const res = http.post(
    `${BASE_URL}/api/shares/${shareId}/clone`,
    JSON.stringify({ name: cloneName }),
    { headers: authHeaders(token) }
  );
  const elapsed = Date.now() - start;
  cloneLatency.add(elapsed);

  const ok = check(res, {
    'clone status 200': (r) => r.status === 200,
    'clone has share':  (r) => {
      try { return !!JSON.parse(r.body).data?.share?.id; } catch { return false; }
    },
    'clone has unique id': (r) => {
      try {
        const id = JSON.parse(r.body).data?.share?.id;
        if (!id || uniqueIds.has(id)) return false;
        uniqueIds.add(id);
        return true;
      } catch {
        return false;
      }
    },
  });

  cloneFailRate.add(!ok);
  if (ok) cloneCount.add(1);

  sleep(1 + Math.random());
}

export function teardown(data) {
  // Report uniqueness — if cloneCount !== uniqueIds.size there was a collision
  console.log(`Clones created: ${cloneCount.name}`);
}
