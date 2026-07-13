<script setup>
import { AlertTriangle } from 'lucide-vue-next'

const props = defineProps({
  speedKbps: {
    type: Number,
    required: true
  },
  thresholdKbps: {
    type: Number,
    required: true
  }
})

const emit = defineEmits(['dismiss'])
</script>

<template>
  <div class="slow-network-notice">
    <div class="slow-network-notice-inner">
      <AlertTriangle class="slow-network-icon" />
      <span class="slow-network-message">
        {{ $t('slow_network.notice', { speed: props.speedKbps, threshold: props.thresholdKbps }) }}
      </span>
      <button class="slow-network-dismiss" @click="emit('dismiss')">
        {{ $t('slow_network.dismiss') }}
      </button>
    </div>
  </div>
</template>

<style scoped lang="scss">
.slow-network-notice {
  position: relative;
  width: 100%;
  margin-bottom: 8px;
  animation: slideDown 0.2s ease;

  @keyframes slideDown {
    from {
      opacity: 0;
      transform: translateY(-6px);
    }
    to {
      opacity: 1;
      transform: translateY(0);
    }
  }
}

.slow-network-notice-inner {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 12px;
  background: var(--warning-background-color, rgba(255, 193, 7, 0.15));
  color: var(--warning-text-color, #856404);
  border: 1px solid var(--warning-border-color, rgba(255, 193, 7, 0.4));
  border-radius: 6px;
  font-size: 0.85rem;
  line-height: 1.4;
}

.slow-network-icon {
  width: 16px;
  height: 16px;
  flex-shrink: 0;
  color: var(--warning-icon-color, #f0ad4e);
}

.slow-network-message {
  flex: 1;
}

.slow-network-dismiss {
  flex-shrink: 0;
  background: none;
  border: 1px solid currentColor;
  color: inherit;
  padding: 2px 8px;
  border-radius: 4px;
  font-size: 0.8rem;
  cursor: pointer;
  opacity: 0.8;
  transition: opacity 0.15s;

  &:hover {
    opacity: 1;
  }
}
</style>
