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
    filter: 'failed',
  },
  router
)
</script>

<template>
  <Overview
    title="Failed tasks"
    description="Tasks that permanently failed after they have reached their max number of attempts."
    :tasks="tasks"
  />
</template>
