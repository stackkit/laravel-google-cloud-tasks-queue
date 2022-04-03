<script setup>
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { callApi } from '../api'

const router = useRouter()
const dashboard = ref({
  recent: {
    this_minute: '...',
    this_hour: '...',
    today: '...',
  },
  failed: {
    this_minute: '...',
    this_hour: '...',
    today: '...',
  },
})

onMounted(async () => {
  dashboard.value = await callApi({
    endpoint: 'dashboard',
    router,
  })
})
</script>

<template>
  <h3 class="text-3xl mb-4">All tasks</h3>
  <div class="grid grid-cols-3 gap-4">
    <router-link
      :to="{
        name: 'recent',
        query: {
          time: `${new Date().getUTCHours()}:${new Date().getUTCMinutes()}`,
        },
      }"
      class="bg-white rounded-lg p-6"
    >
      <span
        class="block text-4xl"
        v-text="dashboard?.recent?.this_minute"
      ></span>
      <span class="text-gray-600">this minute</span>
    </router-link>
    <router-link
      :to="{
        name: 'recent',
        query: {
          hour: new Date().getUTCHours(),
        },
      }"
      class="bg-white rounded-lg p-6"
    >
      <span class="block text-4xl" v-text="dashboard?.recent?.this_hour"></span>
      <span class="text-gray-600">this hour</span>
    </router-link>
    <router-link
      :to="{
        name: 'recent',
      }"
      class="bg-white rounded-lg p-6"
    >
      <span class="block text-4xl" v-text="dashboard?.recent?.this_day"></span>
      <span class="text-gray-600">today</span>
    </router-link>
  </div>
  <h3 class="text-3xl mb-4 mt-8">Failed tasks</h3>
  <div class="grid grid-cols-3 gap-4">
    <router-link
      :to="{
        name: 'failed',
        query: {
          time: `${new Date().getUTCHours()}:${new Date().getUTCMinutes()}`,
        },
      }"
      class="bg-white rounded-lg p-6"
    >
      <span
        class="block text-4xl"
        v-text="dashboard?.failed?.this_minute"
      ></span>
      <span class="text-gray-600">this minute</span>
    </router-link>
    <router-link
      :to="{
        name: 'failed',
        query: {
          hour: new Date().getUTCHours(),
        },
      }"
      class="bg-white rounded-lg p-6"
    >
      <span class="block text-4xl" v-text="dashboard?.failed?.this_hour"></span>
      <span class="text-gray-600">this hour</span>
    </router-link>
    <router-link
      :to="{
        name: 'failed',
      }"
      class="bg-white rounded-lg p-6"
    >
      <span class="block text-4xl" v-text="dashboard?.failed?.this_day"></span>
      <span class="text-gray-600">today</span>
    </router-link>
  </div>
</template>
