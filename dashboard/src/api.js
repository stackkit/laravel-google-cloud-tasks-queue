import { onUnmounted, watch } from 'vue'
import { onBeforeRouteUpdate } from 'vue-router'

export async function fetchTasks(into, query = {}) {
  let paused = false

  const f = async function (into) {
    if (paused) {
      return
    }

    const url = new URL(window.location.href)
    const queryParams = new URLSearchParams(url.search)

    for (const [name, value] of Object.entries(query)) {
      queryParams.append(name, value)
    }

    paused = true
    fetch(
      `${import.meta.env.VITE_API_URL || ''}/cloud-tasks-api/tasks?${queryParams.toString()}`
    )
      .then((response) => response.json())
      .then((response) => {
        into.value = response
        paused = false
      })
  }

  f(into)
  let interval = setInterval(() => f(into), 3000)
  let visibilityChangeListener = null

  // immediately re-fetch results if results have been filtered.
  onBeforeRouteUpdate(function () {
    setTimeout(() => f(into))
  })

  const onVisibilityChange = function () {
    if (document.visibilityState === 'visible') {
      f(into)
      clearInterval(interval)
      interval = setInterval(() => f(into), 3000)
    } else if (document.visibilityState === 'hidden') {
      clearInterval(interval)
    }
  }
  document.addEventListener('visibilitychange', onVisibilityChange)

  onUnmounted(() => {
    clearInterval(interval)
    document.removeEventListener('visibilitychange', onVisibilityChange)
    paused = false
  })
}
