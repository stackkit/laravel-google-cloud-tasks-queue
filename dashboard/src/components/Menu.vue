<template>
  <router-link
    :to="{ name: 'home' }"
    class="block p-4 rounded mb-2 cursor-pointer"
    >Dashboard
  </router-link>
  <router-link
    :to="{ name: 'recent' }"
    class="block p-4 rounded mb-2 cursor-pointer"
    :class="{
      'router-link-active': $route?.matched[0]?.meta?.route === 'recent',
    }"
    >Recent
  </router-link>
  <router-link
    :to="{ name: 'queued' }"
    class="block p-4 rounded mb-2 cursor-pointer"
    :class="{
      'router-link-active': $route?.matched[0]?.meta?.route === 'queued',
    }"
    >Queued
  </router-link>
  <router-link
    :to="{ name: 'failed' }"
    class="block p-4 rounded mb-2"
    :class="{
      'router-link-active': $route?.matched[0]?.meta?.route === 'failed',
    }"
    >Failed
  </router-link>
</template>
