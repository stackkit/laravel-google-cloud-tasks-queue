<script setup>
import { ref } from 'vue'
import { fetchTasks } from '../api'
import Overview from './Overview.vue'

defineProps({
  tasks: Array,
})

const tasks = ref(null)

fetchTasks(tasks, {
  filter: 'failed',
})
</script>

<template>
  <Overview
    title="Failed tasks"
    description="Tasks that permanently failed after they have reached their max number of attempts."
    :tasks="tasks"
  />
</template>
