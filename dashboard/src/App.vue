<template>
  <div class="flex">
    <aside class="basis-auto w-[250px] shrink-0 bg-white p-6 min-h-screen">
      <Menu />
    </aside>
    <div class="flex-1 max-w-[calc(100%-250px)] p-6">
      <router-view></router-view>
    </div>
  </div>
</template>

<style lang="postcss">
.router-link-active {
  @apply bg-indigo-100 text-indigo-900 font-bold;
}
:root {
  --popper-theme-background-color: #333333;
  --popper-theme-background-color-hover: #333333;
  --popper-theme-text-color: #ffffff;
  --popper-theme-border-width: 0px;
  --popper-theme-border-style: solid;
  --popper-theme-border-radius: 6px;
  --popper-theme-padding: 4px;
  --popper-theme-box-shadow: 0 6px 30px -6px rgba(0, 0, 0, 0.25);
}
</style>
<script setup>
import Menu from './components/Menu.vue'
</script>
