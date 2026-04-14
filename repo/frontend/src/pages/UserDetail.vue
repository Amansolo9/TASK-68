<template>
  <div>
    <div v-if="loading" class="loading"><span class="spinner"></span> Loading...</div>

    <div v-else-if="user">
      <div class="card">
        <div class="card-header">
          User: {{ user.username }}
          <div>
            <button v-if="user.status === 'active'" class="btn btn-sm btn-danger" style="margin-right: 0.25rem" @click="deactivate">Deactivate</button>
            <button v-if="user.status === 'inactive'" class="btn btn-sm btn-primary" style="margin-right: 0.25rem" @click="activate">Activate</button>
            <button v-if="user.status === 'locked'" class="btn btn-sm btn-primary" @click="unlock">Unlock</button>
          </div>
        </div>
        <div class="card-body">
          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
            <div>
              <strong>Full Name:</strong> {{ user.full_name }}
            </div>
            <div>
              <strong>Email:</strong> {{ user.email || 'N/A' }}
            </div>
            <div>
              <strong>Status:</strong> <span :class="statusBadge(user.status)">{{ user.status }}</span>
            </div>
            <div>
              <strong>MFA:</strong> {{ user.totp_enabled ? 'Enabled' : 'Disabled' }}
            </div>
            <div>
              <strong>Date of Birth:</strong> <span class="masked-value">{{ user.date_of_birth || 'N/A' }}</span>
            </div>
            <div>
              <strong>Government ID:</strong> <span class="masked-value">{{ user.government_id || 'N/A' }}</span>
            </div>
            <div>
              <strong>Last Login:</strong> {{ user.last_login_at ? new Date(user.last_login_at).toLocaleString() : 'Never' }}
            </div>
            <div>
              <strong>Failed Logins:</strong> {{ user.failed_login_count }}
            </div>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-header">Roles</div>
        <div class="card-body">
          <table>
            <thead><tr><th>Role</th><th>Department Scope</th><th>Active</th></tr></thead>
            <tbody>
              <tr v-for="rs in user.active_role_scopes" :key="rs.id">
                <td><span class="badge badge-info">{{ rs.role }}</span></td>
                <td>{{ rs.department_scope || 'Global' }}</td>
                <td><span class="badge badge-success">Active</span></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="card">
        <div class="card-header">Administrative Actions</div>
        <div class="card-body" style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
          <button class="btn btn-sm btn-secondary" @click="resetPassword">Reset Password</button>
          <button v-if="user.totp_enabled" class="btn btn-sm btn-secondary" @click="disableMfa">Disable MFA</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import api from '../utils/api';

const route = useRoute();
const user = ref(null);
const loading = ref(true);

const statusBadge = (status) => {
  const map = { active: 'badge badge-success', inactive: 'badge badge-warning', locked: 'badge badge-danger' };
  return map[status] || 'badge';
};

const fetchUser = async () => {
  loading.value = true;
  try {
    const response = await api.get(`/users/${route.params.id}`);
    user.value = response.data.data;
  } catch {
    user.value = null;
  } finally {
    loading.value = false;
  }
};

const deactivate = async () => {
  if (!confirm('Deactivate this user?')) return;
  await api.post(`/users/${route.params.id}/deactivate`);
  fetchUser();
};

const activate = async () => {
  await api.post(`/users/${route.params.id}/activate`);
  fetchUser();
};

const unlock = async () => {
  await api.post(`/users/${route.params.id}/unlock`);
  fetchUser();
};

const resetPassword = async () => {
  const newPw = prompt('Enter new password (min 12 characters):');
  if (!newPw || newPw.length < 12) { alert('Password must be at least 12 characters.'); return; }
  await api.post(`/users/${route.params.id}/reset-password`, { new_password: newPw });
  alert('Password reset successfully.');
};

const disableMfa = async () => {
  if (!confirm('Disable MFA for this user?')) return;
  await api.post('/mfa/disable', { user_id: parseInt(route.params.id) });
  fetchUser();
};

onMounted(() => fetchUser());
</script>
