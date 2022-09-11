<script setup>
defineProps({
  status: String,
  classes: {
    type: Array,
    default: [],
  },
})

function ucfirst(input) {
  return input.charAt(0).toUpperCase() + input.slice(1)
}
</script>

<template>
  <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full" :class="[`task-${status}`, ...classes]">
    {{ ucfirst(status) }}
  </span>
</template>

<style lang="postcss">
.task-successful {
  @apply bg-green-100 text-green-800
}
.task-failed {
  @apply bg-red-100 text-red-800
}
.task-error {
  @apply bg-red-100/50 text-red-600/50
}
.task-queued, .task-scheduled {
  @apply bg-gray-100 text-gray-500
}
.task-running, .task-released {
  @apply bg-blue-100 text-blue-800
}
</style>
