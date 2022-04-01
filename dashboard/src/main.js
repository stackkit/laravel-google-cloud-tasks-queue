import { createApp } from 'vue/dist/vue.esm-bundler'
import App from './App.vue'
import './index.css'
import { createRouter, createWebHistory } from 'vue-router'
import Popper from 'vue3-popper'

// 1. Define route components.
// These can be imported from other files
import Login from './components/Login.vue'
import Dashboard from './components/Dashboard.vue'
import Recent from './components/Recent.vue'
import Queued from './components/Queued.vue'
import Failed from './components/Failed.vue'
import Task from './components/Task.vue'

// 2. Define some routes
// Each route should map to a component.
// We'll talk about nested routes later.
const routes = [
  {
    name: 'home',
    path: '/',
    component: Dashboard,
  },
  {
    name: 'login',
    path: '/login',
    component: Login,
  },
  {
    name: 'recent',
    path: '/recent',
    component: Recent,
    meta: {
      route: 'recent',
    },
  },
  {
    name: 'recent-task',
    path: '/recent/:uuid',
    component: Task,
    meta: {
      route: 'recent',
    },
  },
  {
    name: 'queued',
    path: '/queued',
    component: Queued,
    meta: {
      route: 'queued',
    },
  },
  {
    name: 'queued-task',
    path: '/queued/:uuid',
    component: Task,
    meta: {
      route: 'queued',
    },
  },
  {
    name: 'failed',
    path: '/failed',
    component: Failed,
    meta: {
      route: 'failed',
    },
  },
  {
    name: 'failed-task',
    path: '/failed/:uuid',
    component: Task,
    meta: {
      route: 'failed',
    },
  },
]

// 3. Create the router instance and pass the `routes` option
// You can pass in additional options here, but let's
// keep it simple for now.
let routerBasePath = null
if ('CloudTasks' in window) {
  routerBasePath = `/${window.CloudTasks.path}`
}

const router = createRouter({
  // 4. Provide the history implementation to use. We are using the hash history for simplicity here.
  history: createWebHistory(routerBasePath),
  routes, // short for `routes: routes`,
})

router.beforeEach((to, from, next) => {
  const authenticated = localStorage.hasOwnProperty('cloud-tasks-token')
  if (!authenticated && to.name !== 'login') {
    return next({ name: 'login' })
  }
  return next()
})

createApp(App).use(router).component('Popper', Popper).mount('#app')
