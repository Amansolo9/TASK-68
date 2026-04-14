<template>
  <router-view />
</template>

<script setup>
import { onMounted } from 'vue';
import { useAuthStore } from './store/auth';
import { useRouter } from 'vue-router';

const auth = useAuthStore();
const router = useRouter();

onMounted(async () => {
  if (auth.token) {
    try {
      await auth.fetchSession();
    } catch {
      auth.clearAuth();
      router.push('/login');
    }
  }
});
</script>
