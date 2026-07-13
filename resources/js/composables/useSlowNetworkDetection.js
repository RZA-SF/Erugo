import { ref, computed, watch } from 'vue'
import { useSetting } from './useSetting'

const RESHOW_MS = 5 * 60 * 1000 // 5 minutes

/**
 * Composable for slow network detection during uploads.
 *
 * @param {import('vue').ComputedRef} uploadSpeedRef - bytes/sec ref
 * @returns {{ showNotice: ComputedRef<boolean>, dismissNotice: Function, measuredSpeedKbps: Ref<number>, thresholdKbpsComputed: ComputedRef<number> }}
 */
export function useSlowNetworkDetection(uploadSpeedRef) {
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

  watch(uploadSpeedRef, (speed) => {
    if (speed == null) return
    const threshold = Number(thresholdKbps.value) * 1024 // convert KB/s to bytes/s
    if (threshold === 0) return // disabled

    if (speed > 0) {
      measuredSpeedKbps.value = Math.round(speed / 1024)
      if (speed < threshold) {
        isSlowDetected.value = true
      }
    }
  })

  const thresholdKbpsComputed = computed(() => Number(thresholdKbps.value))

  return { showNotice, dismissNotice, measuredSpeedKbps, thresholdKbpsComputed }
}
