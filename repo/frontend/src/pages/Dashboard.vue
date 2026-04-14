<template>
  <div>
    <div class="card">
      <div class="card-header">System Overview</div>
      <div class="card-body">
        <p>Welcome, <strong>{{ auth.user?.full_name }}</strong></p>
        <p>Role(s): <span class="badge badge-info" v-for="role in auth.roles" :key="role" style="margin-right: 0.25rem">{{ role }}</span></p>
        <p style="margin-top: 0.5rem; color: var(--gray-500); font-size: 0.85rem">
          System Status: <span class="badge badge-success">Operational</span>
        </p>
      </div>
    </div>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem;">
      <div class="card" v-if="auth.hasAnyRole(['admin', 'manager', 'advisor'])">
        <div class="card-header">Quick Actions</div>
        <div class="card-body">
          <router-link v-if="auth.isAdmin" to="/users" class="btn btn-primary btn-sm" style="margin-right: 0.5rem;">Manage Users</router-link>
          <router-link to="/organizations" class="btn btn-secondary btn-sm" style="margin-right: 0.5rem;">Organizations</router-link>
          <router-link to="/personnel" class="btn btn-secondary btn-sm">Personnel</router-link>
        </div>
      </div>

      <div class="card">
        <div class="card-header">Session Info</div>
        <div class="card-body" style="font-size: 0.85rem;">
          <p>MFA Status: <span :class="auth.mfaVerified ? 'badge badge-success' : 'badge badge-warning'">{{ auth.mfaVerified ? 'Verified' : 'Not Required' }}</span></p>
          <p v-if="auth.sessionInfo">Session expires: {{ new Date(auth.sessionInfo.expires_at).toLocaleString() }}</p>
        </div>
      </div>
    </div>

    <div class="card" v-if="auth.isAdmin" style="margin-top: 1rem;">
      <div class="card-header">System Health</div>
      <div class="card-body" style="font-size: 0.85rem; color: var(--gray-500);">
        <p>Last poll: {{ lastPoll || 'Not yet polled' }}</p>
        <p>Poll interval: {{ pollInterval / 1000 }}s</p>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import { useAuthStore } from '../store/auth';
import api from '../utils/api';

const auth = useAuthStore();
const lastPoll = ref('');
const pollInterval = ref(10000);
let pollTimer = null;

const poll = async () => {
  try {
    const response = await api.get('/dashboard/poll');
    lastPoll.value = new Date().toLocaleTimeString();
    if (response.data.meta?.poll_after_ms) {
      pollInterval.value = response.data.meta.poll_after_ms;
    }
  } catch {
    // Silent poll failure
  }
};

onMounted(() => {
  poll();
  pollTimer = setInterval(poll, pollInterval.value);
});

onUnmounted(() => {
  if (pollTimer) clearInterval(pollTimer);
});
</script>
