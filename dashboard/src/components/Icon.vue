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
  <span
    class="absolute flex items-center justify-center w-2 h-2 bg-gray-200 rounded-full -left-1 ring-1 mt-3 ring-white"
  >
  </span>
</template>

<style lang="postcss" scoped>
.task-successful {
  @apply bg-green-100 text-green-800;
}
.task-failed {
  @apply bg-red-900;
}
.task-error {
  @apply bg-red-700;
}
.task-queued {
  @apply bg-gray-300;
}
.task-running {
  @apply bg-blue-500;
}
</style>
