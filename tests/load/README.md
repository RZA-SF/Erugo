# Erugo Load Tests

Two runners covering the same scenarios — use whichever is available:

| Runner | File | Requires |
|--------|------|---------|
| k6 (recommended) | `k6/*.js` | [k6](https://k6.io/docs/get-started/installation/) |
| Python asyncio | `python/runner.py` | Python 3.10+, `pip install aiohttp` |

---

## Scenarios

| # | File | What it tests |
|---|------|---------------|
| 01 | `01_auth.js` | Login throughput — 50→100 concurrent sessions |
| 02 | `02_share_download.js` | Share metadata + file download under load |
| 03 | `03_share_management.js` | Authenticated list / extend operations |
| 04 | `04_file_operations.js` | Concurrent clone race-condition test (F3) |

---

## Thresholds

| Metric | p95 target | Error rate |
|--------|-----------|------------|
| Login | < 300 ms | < 1% |
| Share metadata | < 200 ms | < 1% |
| File download | < 2 000 ms | < 2% |
| List shares | < 300 ms | < 1% |
| Extend share | < 500 ms | < 1% |
| Clone share | < 3 000 ms | < 2% |

---

## Running with k6

Install k6: https://k6.io/docs/get-started/installation/

```bash
# Single scenario
k6 run \
  --env BASE_URL=https://your-instance.example.com \
  --env EMAIL=admin@example.com \
  --env PASSWORD=secret \
  tests/load/k6/01_auth.js

# Download scenario (requires at least one share)
k6 run \
  --env BASE_URL=https://your-instance.example.com \
  --env SHARE_LONG_IDS=share-abc123,share-def456 \
  tests/load/k6/02_share_download.js

# File operations / clone race condition
k6 run \
  --env BASE_URL=https://your-instance.example.com \
  --env EMAIL=admin@example.com \
  --env PASSWORD=secret \
  --env SOURCE_SHARE_ID=42 \
  tests/load/k6/04_file_operations.js

# JSON output for CI
k6 run --out json=results.json tests/load/k6/01_auth.js
```

---

## Running with Python

```bash
pip install aiohttp

# All scenarios
python3 tests/load/python/runner.py \
  --base-url https://your-instance.example.com \
  --email admin@example.com \
  --password secret \
  --scenario all

# Specific scenario
python3 tests/load/python/runner.py \
  --base-url http://localhost \
  --email admin@example.com \
  --password secret \
  --scenario clone

# Override concurrency
python3 tests/load/python/runner.py \
  --base-url http://localhost \
  --scenario management \
  --concurrency 50
```

Exit code is 0 if all thresholds pass, 1 if any fail.

---

## Sample output (Python runner)

```
Target: https://your-instance.example.com
Scenario: all

[01] Auth — 200 logins, concurrency=20
     done — error rate 0.0%

[02] Download — 400 requests against 3 share(s), concurrency=40
     done — error rate 0.0%

[03] Management — 200 list+extend cycles, concurrency=20

[04] Clone — 60 concurrent clones of share 42, concurrency=30
     unique clones created: 60 / 60
     error rate: 0.0%

──────────────────────────────────────────────────────────────────────
Label                           Req    Err%      p50      p95      p99
──────────────────────────────────────────────────────────────────────
clone_share                      60    0.0%    412ms    891ms   1203ms
extend_share                    200    0.0%     38ms     74ms    120ms
list_shares                     200    0.0%     42ms     88ms    133ms
login                           200    0.0%     61ms    112ms    178ms
share_download                  400    0.0%    128ms    340ms    612ms
──────────────────────────────────────────────────────────────────────

Threshold results:
  [✓] login / p95 < 300 ms
  [✓] login / error < 1%
  [✓] share_download / p95 < 2000 ms
  [✓] share_download / error < 2%
  [✓] list_shares / p95 < 300 ms
  ...
```

---

## CI integration

Add to your CI pipeline after deployment:

```yaml
# GitHub Actions example
- name: Load tests
  run: |
    pip install aiohttp
    python3 tests/load/python/runner.py \
      --base-url ${{ secrets.STAGING_URL }} \
      --email ${{ secrets.LOAD_TEST_EMAIL }} \
      --password ${{ secrets.LOAD_TEST_PASSWORD }} \
      --scenario all
```
