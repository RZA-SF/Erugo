/**
 * Load scenario 01: Authentication throughput.
 *
 * Verifies the login endpoint holds up under concurrent requests.
 * This is the entry point for every other user flow, so it must be fast
 * even at peak load (e.g. many sessions starting simultaneously).
 *
 * Run:
 *   k6 run --env BASE_URL=https://your-instance.example.com \
 *           --env EMAIL=admin@example.com \
 *           --env PASSWORD=secret \
 *           tests/load/k6/01_auth.js
 */
import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate, Trend } from 'k6/metrics';

const BASE_URL = __ENV.BASE_URL || 'http://localhost';
const EMAIL    = __ENV.EMAIL    || 'admin@example.com';
const PASSWORD = __ENV.PASSWORD || 'password';

const loginFailRate    = new Rate('login_failures');
const loginLatency     = new Trend('login_latency_ms', true);

export const options = {
  stages: [
    { duration: '30s', target: 10  }, // ramp up
    { duration: '1m',  target: 50  }, // sustained load — 50 concurrent logins/s
    { duration: '30s', target: 100 }, // spike
    { duration: '30s', target: 0   }, // ramp down
  ],
  thresholds: {
    // 95th percentile login under 300 ms
    'login_latency_ms': ['p(95)<300'],
    // Less than 1% login failures
    'login_failures': ['rate<0.01'],
    // Overall HTTP error rate under 1%
    'http_req_failed': ['rate<0.01'],
  },
};

export default function () {
  const payload = JSON.stringify({ email: EMAIL, password: PASSWORD });
  const headers = { 'Content-Type': 'application/json' };

  const start = Date.now();
  const res = http.post(`${BASE_URL}/api/auth/login`, payload, { headers });
  loginLatency.add(Date.now() - start);

  const ok = check(res, {
    'status is 200': (r) => r.status === 200,
    'body has token': (r) => {
      try { return typeof JSON.parse(r.body).token === 'string'; } catch { return false; }
    },
  });

  loginFailRate.add(!ok);

  sleep(1);
}
