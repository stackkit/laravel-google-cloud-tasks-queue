<script setup>
import { ref } from 'vue'
import { fetchTasks } from '../api'
import Overview from './Overview.vue'
import { useRouter } from 'vue-router'

defineProps({
  tasks: Array,
})

const tasks = ref(null)
const router = useRouter()

fetchTasks(
  tasks,
  {
    status: 'queued',
  },
  router
)
</script>

<template>
  <Overview
    title="Queued tasks"
    description="Tasks that have been added to the queue recently."
    :tasks="tasks"
  />
</template>
