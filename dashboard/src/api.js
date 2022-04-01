import { onUnmounted, watch } from 'vue'
import { onBeforeRouteUpdate } from 'vue-router'

export async function callApi({
  endpoint,
  router,
  body = null,
  method = 'GET',
  login = false,
} = {}) {
  const response = await fetch(
    `${import.meta.env.VITE_API_URL || ''}/cloud-tasks-api/${endpoint}`,
    {
      method,
      ...(body ? { body } : {}),
      headers: {
        ...(!login
          ? {
              Authorization: `Bearer ${localStorage.getItem(
                'cloud-tasks-token'
              )}`,
            }
          : {}),
      },
    }
  )

  if (response.status === 403 && !login) {
    localStorage.removeItem('cloud-tasks-token')
    router.push({ name: 'login' })
  }

  return login ? await response.text() : await response.json()
}

export async function fetchTasks(into, query = {}, router) {
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
    into.value = await callApi({
      endpoint: `tasks?${queryParams.toString()}`,
      router,
    })
    paused = false
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
