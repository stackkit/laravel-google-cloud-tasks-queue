<template>
  <div
    class="w-[300px] fixed transition-transform right-0 top-0 p-6 px-6 shadow-2xl h-screen bg-white"
    :class="{
      'translate-x-[300px]': visible === false,
    }"
  >
    <label for="queue" class="block mb-2 font-medium">Queue</label>
    <input
      type="text"
      name="queue"
      id="queue"
      ref="queue"
      class="bg-white py-2 px-3 w-full rounded border"
      @keyup.enter="filter"
      @keyup="(e) => filterOnEmpty(e.target.value)"
    />

    <label for="status" class="block mb-2 mt-6 font-medium">Status</label>
    <select
      name="status"
      id="status"
      v-model="status"
      class="bg-white py-2 px-3 w-full rounded border"
    >
      <option value="">List default</option>
      <option value="queued">Queued</option>
      <option value="running">Running</option>
      <option value="successful">Successful</option>
      <option value="error">Error</option>
      <option value="failed">Failed</option>
    </select>

    <button
      class="bg-indigo-500 w-full mt-4 text-indigo-100 rounded py-2"
      @click="filter"
    >
      Apply Filter (or Press Enter)
    </button>
  </div>
</template>

<script setup>
import { ref, onMounted, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
const router = useRouter()
const route = useRoute()

const props = defineProps({
  focus: String,
})

const visible = ref(false)
const queue = ref(null)
const status = ref(null)

function filter() {
  router.push({
    name: route.name,
    query: {
      ...(queue.value.value ? { queue: queue.value.value } : {}),
      ...(status.value ? { status: status.value } : {}),
    },
  })
}

function filterOnEmpty(value) {
  if (value === '') {
    filter()
  }
}

onMounted(() => {
  setTimeout(() => (visible.value = true))

  if (props.focus === 'queue') {
    queue.value.focus()
  }
})
</script>
