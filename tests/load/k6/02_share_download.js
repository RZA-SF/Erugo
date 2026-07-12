/**
 * Load scenario 02: Share download throughput.
 *
 * Simulates many users fetching and downloading a public share simultaneously.
 * This is typically the highest-traffic path in a file sharing app.
 *
 * Two sub-scenarios:
 *  a) Fetch share metadata (lightweight JSON, CDN-cacheable)
 *  b) Trigger file download (heavy, streams file bytes)
 *
 * Run:
 *   k6 run --env BASE_URL=https://your-instance.example.com \
 *           --env SHARE_LONG_ID=share-abc123 \
 *           tests/load/k6/02_share_download.js
 *
 * To test multiple shares, set SHARE_LONG_IDS as a comma-separated list:
 *   --env SHARE_LONG_IDS=share-abc,share-def,share-ghi
 */
import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate, Trend } from 'k6/metrics';
import { randomItem } from './utils/data.js';

const BASE_URL      = __ENV.BASE_URL       || 'http://localhost';
const SHARE_LONG_ID = __ENV.SHARE_LONG_ID  || '';
const SHARE_IDS_ENV = __ENV.SHARE_LONG_IDS || '';

// Build the pool of share IDs to spread load across
const SHARE_POOL = SHARE_IDS_ENV
  ? SHARE_IDS_ENV.split(',').map((s) => s.trim()).filter(Boolean)
  : SHARE_LONG_ID
    ? [SHARE_LONG_ID]
    : [];

const metaFailRate     = new Rate('share_meta_failures');
const downloadFailRate = new Rate('share_download_failures');
const metaLatency      = new Trend('share_meta_latency_ms', true);
const downloadLatency  = new Trend('share_download_latency_ms', true);

export const options = {
  scenarios: {
    // Metadata reads — high volume, low cost
    share_metadata: {
      executor: 'ramping-vus',
      stages: [
        { duration: '30s', target: 50  },
        { duration: '2m',  target: 200 },
        { duration: '30s', target: 0   },
      ],
      gracefulRampDown: '10s',
      exec: 'fetchMetadata',
    },
    // File downloads — lower volume, heavier cost
    share_downloads: {
      executor: 'ramping-vus',
      stages: [
        { duration: '30s', target: 10 },
        { duration: '2m',  target: 40 },
        { duration: '30s', target: 0  },
      ],
      gracefulRampDown: '10s',
      exec: 'downloadFile',
    },
  },
  thresholds: {
    'share_meta_latency_ms':     ['p(95)<200', 'p(99)<500'],
    'share_download_latency_ms': ['p(95)<2000'],
    'share_meta_failures':       ['rate<0.01'],
    'share_download_failures':   ['rate<0.02'],
  },
};

function pickShareId() {
  if (SHARE_POOL.length === 0) {
    console.error('No share IDs configured. Set SHARE_LONG_ID or SHARE_LONG_IDS env var.');
    return 'UNCONFIGURED';
  }
  return randomItem(SHARE_POOL);
}

/** Fetch the share's public metadata JSON. */
export function fetchMetadata() {
  const shareId = pickShareId();
  const start = Date.now();
  const res = http.get(`${BASE_URL}/api/shares/${shareId}`, {
    headers: { 'Accept': 'application/json' },
  });
  metaLatency.add(Date.now() - start);

  const ok = check(res, {
    'meta status 200': (r) => r.status === 200,
    'meta has files':  (r) => {
      try { return Array.isArray(JSON.parse(r.body).data?.share?.files); } catch { return false; }
    },
  });
  metaFailRate.add(!ok);

  sleep(Math.random() * 2); // think time 0–2 s
}

/** Trigger the file download (HEAD only to avoid saturating bandwidth in tests). */
export function downloadFile() {
  const shareId = pickShareId();
  const start = Date.now();
  // Use HEAD to measure response-start latency without streaming the body
  const res = http.request('HEAD', `${BASE_URL}/api/shares/${shareId}/download`, null, {
    headers: { 'Accept': '*/*' },
    // Set a generous timeout for large files
    timeout: '30s',
  });
  downloadLatency.add(Date.now() - start);

  const ok = check(res, {
    'download responds': (r) => r.status === 200 || r.status === 302 || r.status === 206,
  });
  downloadFailRate.add(!ok);

  sleep(Math.random() * 3 + 1); // 1–4 s between downloads
}
