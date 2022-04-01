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
    filter: 'recent',
  },
  router
)
</script>

<template>
  <Overview
    title="Recent tasks"
    description="Tasks that have been added or processed in the queue recently."
    :tasks="tasks"
  />
</template>
