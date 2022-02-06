<script setup>
import { ref } from 'vue'
import { fetchTasks } from '../api'
import Overview from './Overview.vue'

defineProps({
  tasks: Array,
})

const tasks = ref(null)

fetchTasks(tasks, {
  filter: 'recent',
})
</script>

<template>
  <Overview
    title="Recent tasks"
    description="Tasks that have been added or processed in the queue recently."
    :tasks="tasks"
  />
</template>
