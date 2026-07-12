/**
 * Test data helpers.
 * setup() functions in each scenario use these to seed the load test.
 */
import http from 'k6/http';
import { check } from 'k6';
import { authHeaders } from './auth.js';

const BASE_URL = __ENV.BASE_URL || 'http://localhost';

/**
 * Fetch the authenticated user's share list and return all long_ids.
 */
export function getShareLongIds(token) {
  const res = http.get(`${BASE_URL}/api/shares`, { headers: authHeaders(token) });
  check(res, { 'list shares 200': (r) => r.status === 200 });
  try {
    return JSON.parse(res.body).data.shares.map((s) => s.long_id);
  } catch {
    return [];
  }
}

/**
 * Return a random element from an array.
 */
export function randomItem(arr) {
  return arr[Math.floor(Math.random() * arr.length)];
}

/**
 * Generate a random string of given length.
 */
export function randomString(len = 8) {
  const chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
  let out = '';
  for (let i = 0; i < len; i++) out += chars[Math.floor(Math.random() * chars.length)];
  return out;
}
