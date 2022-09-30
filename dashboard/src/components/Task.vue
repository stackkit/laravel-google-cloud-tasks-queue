<script setup>
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { callApi } from '../api'
const route = useRoute()
const router = useRouter()

import Status from './Status.vue'
import Icon from './Icon.vue'

const task = ref({
  id: null,
  status: 'loading',
})

onMounted(async () => {
  task.value = await callApi({
    endpoint: `task/${route.params.uuid}`,
    router,
  })
})

const titles = {
  scheduled: 'Scheduled',
  queued: 'Added to the queue',
  running: 'Running',
  successful: 'Successful',
  error: 'An error occurred',
  failed: 'Failed permanently',
  released: 'Released',
}
</script>

<template>
  <h1 class="text-4xl mb-2">Task #{{ task.id }}</h1>
  <Status :status="task.status" :classes="['text-sm']" />

  <div class="flex">
    <div class="basis-[400px] shrink-0 pr-6 w-2/12">
      <div class="flex-initial sticky ml-4 mt-12">
        <ol class="relative border-l border-gray-200 dark:border-gray-700">
          <li
            class="ml-10 pt-1 mb-6"
            :class="[`event-${event.status}`]"
            v-for="(event, i) in task.events"
          >
            <Icon :status="event.status" />
            <h3 class="text-gray-900">
              {{ titles[event.status] || event.status }}
              <div>
                <span
                  v-if="event.queue"
                  class="bg-blue-100 text-blue-800 text-xs font-medium mr-2 inline-block mb-1 px-1.5 py-0.5 rounded dark:bg-blue-200 dark:text-blue-800"
                  >{{ task.queue }}</span
                >
              </div>
              <div v-if="event['scheduled_at']">
                <span
                  class="bg-gray-200 text-gray-800 text-xs font-medium mr-2 inline-block mb-1 px-1.5 py-0.5 rounded dark:bg-blue-200 dark:text-blue-800"
                >
                  Scheduled: {{ event['scheduled_at'] }} (UTC)
                </span>
              </div>
              <div v-if="event['delay']">
                <span
                  class="bg-gray-200 text-gray-800 text-xs font-medium mr-2 inline-block mb-1 px-1.5 py-0.5 rounded dark:bg-blue-200 dark:text-blue-800"
                >
                  Delay: {{ event['delay'] }} seconds
                </span>
              </div>
            </h3>
            <Popper
              :content="event.datetime"
              :hover="true"
              :arrow="true"
              placement="right"
            >
              <time
                class="block mb-2 mt-2 text-xs text-black/70 font-normal leading-none"
              >
                <span class="cursor-default">{{ event.diff }}</span>
              </time>
            </Popper>
          </li>
        </ol>
      </div>
    </div>
    <div class="basis-auto overflow-x-auto pr-12">
      <template v-if="task.exception" class="mt-12">
        <h2 class="text-2xl">Task Exception</h2>
        <pre
          class="text-xs p-8 border border-[#ccc/80] bg-white/90 mt-4 rounded overflow-auto no-scroll"
          >{{ task.exception }}</pre
        >
      </template>

      <div v-if="task.payload" class="mt-12">
        <h2 class="text-2xl">Task Payload</h2>
        <pre
          class="text-xs p-8 border border-[#ccc/80] bg-white/90 mt-4 rounded overflow-auto no-scroll"
          >{{ task.payload }}</pre
        >
      </div>
    </div>
    <div class="basis-[250px] shrink-0 px-6">
      <h2 class="text-3xl">Actions</h2>
      <button
        class="bg-gray-200 text-black/20 cursor-not-allowed mt-4 w-full rounded px-4 py-2"
      >
        Retry
      </button>
      <span class="text-xs text-gray-800 mt-2 inline-block"
        >Retrying tasks is not available yet.</span
      >
    </div>
  </div>
</template>

<style lang="css" scoped>
.no-scroll::-webkit-scrollbar {
  display: none;
}
.event-failed {
}
</style>
