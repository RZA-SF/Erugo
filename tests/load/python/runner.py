#!/usr/bin/env python3
"""
Erugo load test runner (asyncio + aiohttp).

Runs without k6 — uses Python's asyncio for concurrency and aiohttp for
HTTP. Prints a percentile latency report and exits non-zero if any threshold
is breached.

Usage:
    python3 tests/load/python/runner.py \\
        --base-url https://your-instance.example.com \\
        --email admin@example.com \\
        --password secret \\
        --scenario all          # or: auth, download, management, clone

Requires: pip install aiohttp
"""

import asyncio
import argparse
import json
import statistics
import sys
import time
from collections import defaultdict
from typing import Optional

try:
    import aiohttp
except ImportError:
    sys.exit("aiohttp is required: pip install aiohttp")


# ─────────────────────────────────────────────────────────────────────────────
# Result collection
# ─────────────────────────────────────────────────────────────────────────────

class Results:
    def __init__(self):
        self._latencies: dict[str, list[float]] = defaultdict(list)
        self._errors: dict[str, int] = defaultdict(int)
        self._total: dict[str, int] = defaultdict(int)

    def record(self, label: str, latency_ms: float, success: bool):
        self._latencies[label].append(latency_ms)
        self._total[label] += 1
        if not success:
            self._errors[label] += 1

    def percentile(self, label: str, pct: float) -> float:
        data = sorted(self._latencies.get(label, [0]))
        if not data:
            return 0.0
        idx = max(0, int(len(data) * pct / 100) - 1)
        return data[idx]

    def error_rate(self, label: str) -> float:
        total = self._total.get(label, 0)
        if total == 0:
            return 0.0
        return self._errors.get(label, 0) / total

    def report(self, thresholds: dict) -> bool:
        """Print a summary and return True if all thresholds pass."""
        print("\n" + "─" * 70)
        print(f"{'Label':<30} {'Req':>6} {'Err%':>6} {'p50':>8} {'p95':>8} {'p99':>8}")
        print("─" * 70)

        all_pass = True
        for label in sorted(self._latencies):
            n       = self._total[label]
            err_pct = self.error_rate(label) * 100
            p50     = self.percentile(label, 50)
            p95     = self.percentile(label, 95)
            p99     = self.percentile(label, 99)
            print(f"{label:<30} {n:>6} {err_pct:>5.1f}% {p50:>7.0f}ms {p95:>7.0f}ms {p99:>7.0f}ms")

        print("─" * 70)
        print("\nThreshold results:")
        for label, checks in thresholds.items():
            for check_name, fn in checks.items():
                passed = fn(self)
                mark = "✓" if passed else "✗ FAIL"
                print(f"  [{mark}] {label} / {check_name}")
                if not passed:
                    all_pass = False

        return all_pass


# ─────────────────────────────────────────────────────────────────────────────
# HTTP helpers
# ─────────────────────────────────────────────────────────────────────────────

async def post_json(session: aiohttp.ClientSession, url: str, data: dict,
                    headers: dict = None) -> tuple[int, dict]:
    h = {"Content-Type": "application/json", "Accept": "application/json"}
    if headers:
        h.update(headers)
    try:
        async with session.post(url, json=data, headers=h) as resp:
            body = await resp.json(content_type=None)
            return resp.status, body
    except Exception as e:
        return 0, {"error": str(e)}


async def get_json(session: aiohttp.ClientSession, url: str,
                   headers: dict = None) -> tuple[int, dict]:
    h = {"Accept": "application/json"}
    if headers:
        h.update(headers)
    try:
        async with session.get(url, headers=h) as resp:
            body = await resp.json(content_type=None)
            return resp.status, body
    except Exception as e:
        return 0, {"error": str(e)}


async def head_req(session: aiohttp.ClientSession, url: str,
                   headers: dict = None) -> tuple[int, dict]:
    h = {}
    if headers:
        h.update(headers)
    try:
        async with session.head(url, headers=h, allow_redirects=True) as resp:
            return resp.status, {}
    except Exception as e:
        return 0, {"error": str(e)}


# ─────────────────────────────────────────────────────────────────────────────
# Scenarios
# ─────────────────────────────────────────────────────────────────────────────

async def login(session: aiohttp.ClientSession, base_url: str,
                email: str, password: str, retries: int = 3) -> Optional[str]:
    for attempt in range(retries):
        status, body = await post_json(
            session, f"{base_url}/api/auth/login",
            {"email": email, "password": password}
        )
        token = body.get("data", {}).get("access_token") or body.get("token")
        if status == 200 and isinstance(token, str):
            return token
        if attempt < retries - 1:
            await asyncio.sleep(0.5)
    print(f"  [!] Login failed after {retries} attempts — status={status} body={body}", file=sys.stderr)
    return None


def auth_header(token: str) -> dict:
    return {"Authorization": f"Bearer {token}"}


# ── Scenario A: Auth ─────────────────────────────────────────────────────────

async def scenario_auth_worker(session, base_url, email, password, results, sem):
    async with sem:
        t0 = time.monotonic()
        status, body = await post_json(
            session, f"{base_url}/api/auth/login",
            {"email": email, "password": password}
        )
        latency = (time.monotonic() - t0) * 1000
        _tok = body.get("data", {}).get("access_token") or body.get("token")
        ok = status == 200 and isinstance(_tok, str)
        results.record("login", latency, ok)


async def scenario_auth(session, base_url, email, password, results,
                        concurrency=20, total=200):
    print(f"\n[01] Auth — {total} logins, concurrency={concurrency}")
    sem = asyncio.Semaphore(concurrency)
    tasks = [
        scenario_auth_worker(session, base_url, email, password, results, sem)
        for _ in range(total)
    ]
    await asyncio.gather(*tasks)
    print(f"     done — error rate {results.error_rate('login'):.1%}")


# ── Scenario B: Share download ───────────────────────────────────────────────

async def scenario_download_worker(session, url, results, sem):
    async with sem:
        t0 = time.monotonic()
        status, _ = await head_req(session, url)
        latency = (time.monotonic() - t0) * 1000
        ok = status in (200, 206, 302, 303)
        results.record("share_download", latency, ok)


async def scenario_download(session, base_url, token, share_long_ids, results,
                             concurrency=40, total=400):
    if not share_long_ids:
        print("\n[02] Download — SKIPPED (no share IDs provided)")
        return

    print(f"\n[02] Download — {total} requests against {len(share_long_ids)} share(s), "
          f"concurrency={concurrency}")
    sem = asyncio.Semaphore(concurrency)

    urls = [
        f"{base_url}/api/shares/{share_long_ids[i % len(share_long_ids)]}/download"
        for i in range(total)
    ]
    tasks = [scenario_download_worker(session, url, results, sem) for url in urls]
    await asyncio.gather(*tasks)
    print(f"     done — error rate {results.error_rate('share_download'):.1%}")


# ── Scenario C: Share management ─────────────────────────────────────────────

async def scenario_mgmt_worker(session, base_url, token, share_id, results, sem):
    headers = auth_header(token)
    async with sem:
        # List shares
        t0 = time.monotonic()
        status, _ = await get_json(session, f"{base_url}/api/shares", headers)
        results.record("list_shares", (time.monotonic() - t0) * 1000, status == 200)

        if not share_id:
            return

        # Extend share
        t0 = time.monotonic()
        status, _ = await post_json(
            session, f"{base_url}/api/shares/{share_id}/extend", {}, headers
        )
        results.record("extend_share", (time.monotonic() - t0) * 1000, status == 200)


async def scenario_management(session, base_url, token, share_ids, results,
                               concurrency=20, total=200):
    print(f"\n[03] Management — {total} list+extend cycles, concurrency={concurrency}")
    sem = asyncio.Semaphore(concurrency)
    share_id = share_ids[0] if share_ids else None
    tasks = [
        scenario_mgmt_worker(session, base_url, token, share_id, results, sem)
        for _ in range(total)
    ]
    await asyncio.gather(*tasks)


# ── Scenario D: Concurrent clone (F3 race condition test) ────────────────────

async def scenario_clone_worker(session, base_url, token, share_id,
                                results, sem, seen_ids):
    headers = auth_header(token)
    async with sem:
        t0 = time.monotonic()
        status, body = await post_json(
            session, f"{base_url}/api/shares/{share_id}/clone",
            {"name": f"load-clone-{time.monotonic_ns()}"},
            headers
        )
        latency = (time.monotonic() - t0) * 1000

        clone_id = body.get("data", {}).get("share", {}).get("id")
        ok = status == 200 and clone_id is not None

        # Check for duplicate IDs — indicates a race condition bug
        if ok:
            if clone_id in seen_ids:
                print(f"  [!] DUPLICATE clone id detected: {clone_id}", file=sys.stderr)
                ok = False
            else:
                seen_ids.add(clone_id)

        results.record("clone_share", latency, ok)


async def scenario_clone(session, base_url, token, share_ids, results,
                         concurrency=30, total=60):
    if not share_ids:
        print("\n[04] Clone — SKIPPED (no shares available)")
        return

    # Intentionally hammer the *same* share to expose race conditions
    share_id = share_ids[0]
    print(f"\n[04] Clone — {total} concurrent clones of share {share_id}, "
          f"concurrency={concurrency}")
    sem = asyncio.Semaphore(concurrency)
    seen_ids: set = set()
    tasks = [
        scenario_clone_worker(session, base_url, token, share_id, results, sem, seen_ids)
        for _ in range(total)
    ]
    await asyncio.gather(*tasks)
    print(f"     unique clones created: {len(seen_ids)} / {total}")
    print(f"     error rate: {results.error_rate('clone_share'):.1%}")


# ─────────────────────────────────────────────────────────────────────────────
# Entry point
# ─────────────────────────────────────────────────────────────────────────────

async def main():
    parser = argparse.ArgumentParser(description="Erugo load test runner")
    parser.add_argument("--base-url",   default="http://localhost", help="Base URL of the Erugo instance")
    parser.add_argument("--email",      default="admin@example.com")
    parser.add_argument("--password",   default="password")
    parser.add_argument("--scenario",   default="all",
                        choices=["all", "auth", "download", "management", "clone"])
    parser.add_argument("--concurrency", type=int, default=None,
                        help="Override concurrency for all scenarios")
    parser.add_argument("--share-ids",  default="",
                        help="Comma-separated share long_ids for download scenario")
    args = parser.parse_args()

    results = Results()

    # Thresholds — (label, check_name) → predicate(results) → bool
    thresholds = {
        "login":           {"p95 < 300 ms":  lambda r: r.percentile("login", 95) < 300,
                            "error < 1%":    lambda r: r.error_rate("login") < 0.01},
        "share_download":  {"p95 < 2000 ms": lambda r: r.percentile("share_download", 95) < 2000,
                            "error < 2%":    lambda r: r.error_rate("share_download") < 0.02},
        "list_shares":     {"p95 < 300 ms":  lambda r: r.percentile("list_shares", 95) < 300,
                            "error < 1%":    lambda r: r.error_rate("list_shares") < 0.01},
        "extend_share":    {"p95 < 500 ms":  lambda r: r.percentile("extend_share", 95) < 500,
                            "error < 1%":    lambda r: r.error_rate("extend_share") < 0.01},
        "clone_share":     {"p95 < 3000 ms": lambda r: r.percentile("clone_share", 95) < 3000,
                            "error < 2%":    lambda r: r.error_rate("clone_share") < 0.02},
    }

    c = args.concurrency  # may be None (each scenario uses its own default)

    connector = aiohttp.TCPConnector(limit=200, ssl=False)
    timeout   = aiohttp.ClientTimeout(total=30)

    async with aiohttp.ClientSession(connector=connector, timeout=timeout) as session:
        print(f"Target: {args.base_url}")
        print(f"Scenario: {args.scenario}")

        # Warm-up: prime PHP-FPM workers before the timed scenarios
        print("Warming up...", end=" ", flush=True)
        for _ in range(3):
            await post_json(session, f"{args.base_url}/api/auth/login",
                            {"email": args.email, "password": args.password})
        print("done")

        # Authenticate
        token = await login(session, args.base_url, args.email, args.password)
        if not token and args.scenario != "auth":
            print("Cannot continue without a valid token.", file=sys.stderr)
            sys.exit(2)

        # Resolve share IDs
        share_long_ids = [s.strip() for s in args.share_ids.split(",") if s.strip()]
        numeric_share_ids: list[int] = []
        if token:
            status, body = await get_json(
                session, f"{args.base_url}/api/shares",
                auth_header(token)
            )
            if status == 200:
                shares = body.get("data", {}).get("shares", [])
                numeric_share_ids = [s["id"] for s in shares]
                if not share_long_ids:
                    share_long_ids = [s["long_id"] for s in shares]

        if args.scenario in ("all", "auth"):
            await scenario_auth(session, args.base_url, args.email, args.password,
                                results, concurrency=c or 20, total=200)

        if args.scenario in ("all", "download"):
            await scenario_download(session, args.base_url, token, share_long_ids,
                                    results, concurrency=c or 40, total=400)

        if args.scenario in ("all", "management"):
            share_ids_for_mgmt = [str(i) for i in numeric_share_ids]
            await scenario_management(session, args.base_url, token, share_ids_for_mgmt,
                                      results, concurrency=c or 20, total=200)

        if args.scenario in ("all", "clone"):
            share_ids_for_clone = [str(i) for i in numeric_share_ids]
            await scenario_clone(session, args.base_url, token, share_ids_for_clone,
                                 results, concurrency=c or 30, total=60)

    all_pass = results.report(thresholds)
    sys.exit(0 if all_pass else 1)


if __name__ == "__main__":
    asyncio.run(main())
