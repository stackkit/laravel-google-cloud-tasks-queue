<script setup>
import { ref } from 'vue'
import { fetchTasks } from '../api'
import Overview from './Overview.vue'

defineProps({
  tasks: Array,
})

const tasks = ref(null)

fetchTasks(tasks, {
  status: 'queued',
})
</script>

<template>
  <Overview
    title="Queued tasks"
    description="Tasks that have been added to the queue recently."
    :tasks="tasks"
  />
</template>
