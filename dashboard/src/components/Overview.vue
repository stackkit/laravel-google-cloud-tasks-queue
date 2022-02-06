<script setup>
import { ref, watch } from 'vue'
import Status from './Status.vue'
import TaskRowSpinner from './TaskRowSpinner.vue'
import FilterCard from './FilterCard.vue'

const props = defineProps({
  title: String,
  description: String,
  tasks: Array,
})

const newTasks = ref([])
const map = ref([])
const filter = ref({
  visible: false,
  focus: null,
})

function ucfirst(input) {
  return input.charAt(0).toUpperCase() + input.slice(1)
}

function flashTask(task) {
  newTasks.value.push(task.id)
  setTimeout(() => {
    newTasks.value.splice(newTasks.value.indexOf(task.id), 1)
  }, 1000)
}

watch(
  () => props.tasks,
  (n, o) => {
    if (!o) return

    // Map the task to their index so we can easily update them.
    map.value = []
    o.map((task, index) => {
      map[task.id] = index
    })

    for (const task of n) {
      if (map[task.id] === undefined) {
        flashTask(task)
      } else {
        if (o[map[task.id]]?.status !== task.status) {
          flashTask(task)
        }
      }
    }
  }
)
</script>

<template>
  <h1 class="text-4xl mb-2">{{ title }}</h1>
  <p class="text-lg">{{ description }}</p>

  <div class="flex flex-row mt-6">
    <div class="flex-1">
      <div class="align-middle">
        <div
          class="shadow overflow-hidden border-b border-gray-200 sm:rounded-lg"
        >
          <table class="table-fixed divide-y divide-gray-200 w-full">
            <thead class="bg-gray-50">
              <tr>
                <th
                  scope="col"
                  class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-[50px]"
                >
                  #
                </th>
                <th
                  scope="col"
                  class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider max-w-xl w-[300px]"
                >
                  Name
                </th>
                <th
                  scope="col"
                  class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-[100px]"
                >
                  Status
                </th>
                <th
                  scope="col"
                  class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-[150px] text-center"
                >
                  Attempts
                </th>
                <th
                  scope="col"
                  class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-[200px]"
                >
                  Created
                </th>
                <th
                  scope="col"
                  class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"
                >
                  Queue

                  <div class="inline relative">
                    <svg
                      xmlns="http://www.w3.org/2000/svg"
                      class="h-4 w-4 inline transition-transform hover:scale-[1.1] cursor-pointer"
                      fill="none"
                      viewBox="0 0 24 24"
                      stroke="currentColor"
                      @click="
                        () => {
                          filter.visible = !filter.visible
                          filter.focus = filter.visible ? 'queue' : null
                        }
                      "
                    >
                      <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"
                      />
                    </svg>
                  </div>
                </th>
                <th scope="col" class="relative px-6 py-3">
                  <span class="sr-only">Edit</span>
                </th>
              </tr>
            </thead>
            <TaskRowSpinner v-if="tasks === null" />
            <tbody v-if="tasks && tasks.length === 0">
              <tr>
                <td colspan="7" class="px-6 py-4 bg-white">No results.</td>
              </tr>
            </tbody>
            <tbody class="bg-white divide-y divide-gray-200">
              <tr
                v-for="task in tasks"
                class="cursor-pointer hover:bg-indigo-100/10 transition-colors"
                :class="{ 'bg-blue-300/30': newTasks.includes(task.id) }"
                @click="
                  $router.push({
                    name: `${$route.name}-task`,
                    params: { uuid: task.uuid },
                  })
                "
              >
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                  {{ task.id }}
                </td>
                <td
                  class="px-6 py-4 whitespace-nowrap text-ellipsis text-sm text-gray-900"
                >
                  {{ task.name.substring(0, 30)
                  }}{{ task.name.length > 30 ? '...' : '' }}
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                  <Status :status="task.status" />
                </td>
                <td
                  class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-center"
                >
                  {{ task.attempts }}
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                  {{ task.created }}
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                  {{ task.queue }}
                </td>
                <td
                  class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium"
                >
                  <a href="#" class="text-indigo-600 hover:text-indigo-900"
                    >View</a
                  >
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
  <FilterCard
    v-if="filter.visible"
    :visible="filter.visible"
    :focus="filter.focus"
  />
</template>

<style lang="postcss">
.task-successful {
  @apply bg-green-100 text-green-800;
}
.task-failed {
  @apply bg-red-100 text-red-800;
}
.task-queued {
  @apply bg-gray-100 text-gray-500;
}
.task-running {
  @apply bg-blue-100 text-blue-800;
}
</style>
