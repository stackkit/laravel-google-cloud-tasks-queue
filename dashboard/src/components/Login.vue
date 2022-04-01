<template>
  <div class="block w-full h-full flex items-center justify-center">
    <div>
      <h3 class="text-4xl">This application is password protected.</h3>
      <input
        type="password"
        class="w-full p-2 px-6 text-2xl font-light mt-8 text-center outline-none shadow rounded-full"
        :class="{ shake: incorrect }"
        @keyup.enter="attemptLogin"
        v-model="pw"
        :disabled="loading"
        ref="inputRef"
      />
      <div class="text-center mt-6 text-xl">
        Press
        <span class="bg-blue-200 py-1 px-2 ml-2 mr-2 rounded text-blue-800"
          >Enter</span
        >
        to log in.
      </div>
    </div>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { callApi } from '../api'

const router = useRouter()
const inputRef = ref(null)
const pw = ref('')
const pwPrev = ref('')
const incorrect = ref(false)
const loading = ref(false)

onMounted(() => {
  inputRef.value.focus()
})

async function attemptLogin() {
  if (pw.value === '' || pw.value === pwPrev.value) {
    return
  }
  pwPrev.value = pw.value
  const formData = new FormData()
  formData.append('password', pw.value)

  loading.value = true
  const token = await callApi({
    endpoint: 'login',
    method: 'POST',
    body: formData,
    login: true,
  })
  loading.value = false

  if (token) {
    localStorage.setItem('cloud-tasks-token', token)
    router.push({ name: 'home' })
  } else {
    incorrect.value = true
    setTimeout(() => {
      incorrect.value = false
    }, 820)
    setTimeout(() => inputRef.value.focus(), 50)
  }
}
</script>

<style lang="css" scoped>
@keyframes shake {
  8%,
  41% {
    transform: translateX(-10px);
  }
  25%,
  58% {
    transform: translateX(10px);
  }
  75% {
    transform: translateX(-5px);
  }
  92% {
    transform: translateX(5px);
  }
  0%,
  100% {
    transform: translateX(0);
  }
}

.shake {
  animation: shake 0.3s linear;
}

input[type='password'] {
  font: small-caption;
  font-size: 36px;
}
</style>
