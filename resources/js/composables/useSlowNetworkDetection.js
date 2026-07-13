import { ref, computed, watch, onMounted } from 'vue'
import { useSetting } from './useSetting'

// Module-level probe cache: valid for 5 minutes
const CACHE_TTL_MS = 5 * 60 * 1000
let probeCache = null // { speedBytesPerSec, measuredAt }

// Abort the probe if it hasn't completed within this window.
// Keeps the probe non-blocking even if a WAF drops the connection silently.
const PROBE_TIMEOUT_MS = 5000

/**
 * Fetches /api/speed-probe and returns bytes per second.
 * Results are cached at module level for 5 minutes.
 *
 * Fails silently (returns null) on any error: network failure, non-200
 * status, WAF block, or timeout. The caller treats null as "no data".
 */
export const runSpeedProbe = async () => {
  // Return cached result if still valid
  if (probeCache && (Date.now() - probeCache.measuredAt) < CACHE_TTL_MS) {
    return probeCache.speedBytesPerSec
  }

  const controller = new AbortController()
  const timer = setTimeout(() => controller.abort(), PROBE_TIMEOUT_MS)

  try {
    const url = window.location.origin + '/api/speed-probe'
    const start = performance.now()
    const response = await fetch(url, { cache: 'no-store', signal: controller.signal })

    if (!response.ok) {
      // WAF block, unexpected redirect, etc. — skip silently.
      return null
    }

    await response.arrayBuffer() // consume full body
    const elapsed = (performance.now() - start) / 1000 // seconds
    const speedBytesPerSec = elapsed > 0 ? 65536 / elapsed : 0

    probeCache = { speedBytesPerSec, measuredAt: Date.now() }
    return speedBytesPerSec
  } finally {
    clearTimeout(timer)
  }
}

const RESHOW_MS = 5 * 60 * 1000 // 5 minutes

/**
 * Composable for slow network detection.
 *
 * @param {'upload'|'download'} mode
 * @param {import('vue').ComputedRef|null} uploadSpeedRef - bytes/sec ref (upload mode only)
 * @returns {{ showNotice: ComputedRef<boolean>, dismissNotice: Function, measuredSpeedKbps: Ref<number> }}
 */
export function useSlowNetworkDetection(mode, uploadSpeedRef) {
  const { value: thresholdKbps } = useSetting('slow_network_threshold_kbps', 'system.shares', 500)

  const isSlowDetected = ref(false)
  const dismissed = ref(false)
  const measuredSpeedKbps = ref(0)

  const showNotice = computed(() => isSlowDetected.value && !dismissed.value)

  const dismissNotice = () => {
    dismissed.value = true
    setTimeout(() => {
      dismissed.value = false
    }, RESHOW_MS)
  }

  if (mode === 'upload') {
    watch(
      uploadSpeedRef,
      (speed) => {
        if (speed == null) return
        const threshold = Number(thresholdKbps.value) * 1024 // convert KB/s to bytes/s
        if (threshold === 0) return // disabled

        if (speed > 0) {
          // Upload is active
          measuredSpeedKbps.value = Math.round(speed / 1024)
          if (speed < threshold) {
            isSlowDetected.value = true
          }
        }
      }
    )
  } else if (mode === 'download') {
    onMounted(async () => {
      try {
        const speedBytesPerSec = await runSpeedProbe()

        // null means probe was skipped (WAF block, timeout, non-200) — no notice shown
        if (speedBytesPerSec === null) return

        measuredSpeedKbps.value = Math.round(speedBytesPerSec / 1024)

        const threshold = Number(thresholdKbps.value) * 1024
        if (threshold === 0) return // feature disabled

        if (speedBytesPerSec < threshold) {
          isSlowDetected.value = true
        }
      } catch (e) {
        // AbortError (timeout) or any unexpected error — skip silently, never block
        // the UI or the download.
      }
    })
  }

  const thresholdKbpsComputed = computed(() => Number(thresholdKbps.value))

  return { showNotice, dismissNotice, measuredSpeedKbps, thresholdKbpsComputed }
}
