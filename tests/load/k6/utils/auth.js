/**
 * Authentication helpers for Erugo load tests.
 * Handles login, token caching, and per-VU token management.
 */
import http from 'k6/http';
import { check } from 'k6';

const BASE_URL = __ENV.BASE_URL || 'http://localhost';

/**
 * Log in and return a bearer token.
 * Call this from setup() to get a shared admin token,
 * or from default() to get per-VU tokens.
 */
export function login(email, password) {
  const res = http.post(
    `${BASE_URL}/api/auth/login`,
    JSON.stringify({ email, password }),
    { headers: { 'Content-Type': 'application/json' } }
  );

  const ok = check(res, {
    'login status 200': (r) => r.status === 200,
    'login returns token': (r) => {
      try { return !!JSON.parse(r.body).token; } catch { return false; }
    },
  });

  if (!ok) return null;
  return JSON.parse(res.body).token;
}

/**
 * Build standard auth headers for an API request.
 */
export function authHeaders(token) {
  return {
    'Authorization': `Bearer ${token}`,
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  };
}
