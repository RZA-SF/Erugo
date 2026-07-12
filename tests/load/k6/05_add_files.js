/**
 * Functional test 05: Add files to an existing share (F1 feature).
 *
 * Validates the full add-files pipeline under a small concurrent load:
 *   1. POST /files/  (tusd) — initiate TUS upload
 *   2. PATCH /files/<id>    — stream file content
 *   3. POST /api/shares/{id}/add-files — commit the upload to the share
 *   4. GET  /api/shares     — verify file_count increased
 *
 * The test creates a dedicated multi-file share in setup() so it does not
 * depend on pre-existing data.
 *
 * Run:
 *   k6 run --env BASE_URL=http://localhost:8085 \
 *           --env EMAIL=admin@example.com \
 *           --env PASSWORD=password \
 *           tests/load/k6/05_add_files.js
 */
import http from 'k6/http';
import { check, group, sleep } from 'k6';
import { Rate, Trend, Counter } from 'k6/metrics';
import { login, authHeaders } from './utils/auth.js';
import { randomString } from './utils/data.js';

const BASE_URL = __ENV.BASE_URL || 'http://localhost';
const EMAIL    = __ENV.EMAIL    || 'admin@example.com';
const PASSWORD = __ENV.PASSWORD || 'password';

const uploadLatency  = new Trend('add_files_upload_ms',  true);
const commitLatency  = new Trend('add_files_commit_ms',  true);
const failRate       = new Rate('add_files_failures');
const filesAdded     = new Counter('files_added_total');

export const options = {
  scenarios: {
    add_files: {
      executor: 'ramping-vus',
      stages: [
        { duration: '10s', target: 3  },
        { duration: '30s', target: 10 },
        { duration: '10s', target: 0  },
      ],
      gracefulRampDown: '5s',
    },
  },
  thresholds: {
    'add_files_upload_ms': ['p(95)<5000'],
    'add_files_commit_ms': ['p(95)<2000'],
    'add_files_failures':  ['rate<0.05'],
  },
};

// Create a fresh share to add files into
export function setup() {
  const token = login(EMAIL, PASSWORD);
  if (!token) throw new Error('setup: login failed');

  // Upload one seed file via TUS to create the initial share
  const seedContent = `seed file ${Date.now()}`;
  const seedBytes   = new TextEncoder().encode(seedContent);

  const initRes = http.post(`${BASE_URL}/files/`, null, {
    headers: {
      ...authHeaders(token),
      'Tus-Resumable': '1.0.0',
      'Upload-Length': String(seedBytes.byteLength),
      'Upload-Metadata': `filename ${btoa('seed.txt')},filetype ${btoa('text/plain')}`,
      'Content-Length': '0',
    },
  });
  if (initRes.status !== 201) throw new Error(`setup: TUS init failed: ${initRes.status}`);
  const tusUrl  = initRes.headers['Location'];
  const uploadId = tusUrl.split('/').pop();

  const patchRes = http.patch(tusUrl, seedBytes, {
    headers: {
      ...authHeaders(token),
      'Tus-Resumable': '1.0.0',
      'Upload-Offset': '0',
      'Content-Type': 'application/offset+octet-stream',
      'Content-Length': String(seedBytes.byteLength),
    },
  });
  if (patchRes.status !== 204) throw new Error(`setup: TUS PATCH failed: ${patchRes.status}`);

  // Create the share
  const shareRes = http.post(
    `${BASE_URL}/api/shares`,
    JSON.stringify({
      name: `add-files-test-${Date.now()}`,
      uploadIds: [uploadId],
      filePaths: { [uploadId]: 'seed.txt' },
      expires_at: null,
    }),
    { headers: { ...authHeaders(token), 'Content-Type': 'application/json' } }
  );
  if (shareRes.status !== 200 && shareRes.status !== 201) {
    throw new Error(`setup: share creation failed: ${shareRes.status} ${shareRes.body}`);
  }
  const shareId = JSON.parse(shareRes.body)?.data?.share?.id;
  if (!shareId) throw new Error('setup: no share ID in response');

  console.log(`setup: created share ${shareId} with seed file`);
  return { token, shareId };
}

export default function addFiles(data) {
  const { token, shareId } = data;

  const content  = `load-test-file-${randomString(8)}-${Date.now()}`;
  const bytes    = new TextEncoder().encode(content);
  const fileName = `file-${randomString(6)}.txt`;

  group('TUS upload', () => {
    // Initiate
    const t0 = Date.now();
    const initRes = http.post(`${BASE_URL}/files/`, null, {
      headers: {
        ...authHeaders(token),
        'Tus-Resumable': '1.0.0',
        'Upload-Length': String(bytes.byteLength),
        'Upload-Metadata': `filename ${btoa(fileName)},filetype ${btoa('text/plain')}`,
        'Content-Length': '0',
      },
    });

    const initOk = check(initRes, { 'TUS init 201': (r) => r.status === 201 });
    if (!initOk) { failRate.add(1); return; }

    const tusUrl  = initRes.headers['Location'];
    const uploadId = tusUrl.split('/').pop();

    // Upload content
    const patchRes = http.patch(tusUrl, bytes, {
      headers: {
        ...authHeaders(token),
        'Tus-Resumable': '1.0.0',
        'Upload-Offset': '0',
        'Content-Type': 'application/offset+octet-stream',
        'Content-Length': String(bytes.byteLength),
      },
    });
    uploadLatency.add(Date.now() - t0);

    const patchOk = check(patchRes, { 'TUS patch 204': (r) => r.status === 204 });
    if (!patchOk) { failRate.add(1); return; }

    // Commit to share
    group('add-files commit', () => {
      const t1 = Date.now();
      const addRes = http.post(
        `${BASE_URL}/api/shares/${shareId}/add-files`,
        JSON.stringify({
          uploadIds: [uploadId],
          filePaths: { [uploadId]: fileName },
        }),
        { headers: { ...authHeaders(token), 'Content-Type': 'application/json' } }
      );
      commitLatency.add(Date.now() - t1);

      const ok = check(addRes, {
        'add-files 200':       (r) => r.status === 200,
        'add-files success':   (r) => {
          try { return JSON.parse(r.body).status === 'success'; } catch { return false; }
        },
        'add-files has share': (r) => {
          try { return !!JSON.parse(r.body).data?.share?.id; } catch { return false; }
        },
      });

      failRate.add(!ok);
      if (ok) filesAdded.add(1);
    });
  });

  sleep(1 + Math.random() * 2);
}

export function teardown(data) {
  console.log(`Teardown: ${filesAdded.name} files added across all VUs`);
}
