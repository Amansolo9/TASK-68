<template>
  <div class="app-layout">
    <aside class="sidebar">
      <div class="sidebar-brand">Admissions System</div>
      <nav class="sidebar-nav">
        <router-link to="/">Dashboard</router-link>

        <div class="nav-section">Consultations</div>
        <router-link to="/tickets">Tickets</router-link>
        <router-link to="/appointments">Appointments</router-link>

        <div class="nav-section">Admissions</div>
        <router-link to="/admissions-plans">Admissions Plans</router-link>

        <div class="nav-section">Administration</div>
        <router-link v-if="auth.isAdmin" to="/users">User Management</router-link>
        <router-link v-if="auth.hasAnyRole(['admin', 'steward'])" to="/dictionaries">Data Dictionaries</router-link>
        <router-link v-if="auth.hasAnyRole(['admin'])" to="/audit-logs">Audit Logs</router-link>

        <div class="nav-section">Master Data</div>
        <router-link to="/organizations">Organizations</router-link>
        <router-link to="/personnel">Personnel</router-link>
        <router-link to="/positions">Positions</router-link>
        <router-link to="/course-categories">Course Categories</router-link>

        <div class="nav-section">Account</div>
        <router-link to="/mfa-setup">MFA Setup</router-link>
      </nav>
    </aside>

    <div class="main-content">
      <header class="topbar">
        <div>
          <strong>{{ pageTitle }}</strong>
        </div>
        <div class="topbar-user">
          <span>{{ auth.user?.full_name }}</span>
          <span class="badge badge-info">{{ primaryRole }}</span>
          <button class="btn btn-sm btn-secondary" @click="handleLogout">Logout</button>
        </div>
      </header>

      <main class="content-area">
        <router-view />
      </main>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { useAuthStore } from '../store/auth';
import { useRouter, useRoute } from 'vue-router';

const auth = useAuthStore();
const router = useRouter();
const route = useRoute();

const pageTitle = computed(() => {
  const name = route.name || '';
  return name.charAt(0).toUpperCase() + name.slice(1).replace(/-/g, ' ');
});

const primaryRole = computed(() => {
  const roles = auth.user?.roles || [];
  return roles[0] || 'user';
});

const handleLogout = async () => {
  await auth.logout();
  router.push('/login');
};
</script>
