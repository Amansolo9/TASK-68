<template>
  <div>
    <div class="card">
      <div class="card-header">
        User Management
        <button class="btn btn-primary btn-sm" @click="showCreateModal = true">Create User</button>
      </div>
      <div class="card-body">
        <div style="display: flex; gap: 0.5rem; margin-bottom: 1rem;">
          <input v-model="search" class="form-control" placeholder="Search users..." style="max-width: 300px" @input="fetchUsers" />
          <select v-model="statusFilter" class="form-control" style="max-width: 150px" @change="fetchUsers">
            <option value="">All statuses</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
            <option value="locked">Locked</option>
          </select>
        </div>

        <div v-if="loading" class="loading"><span class="spinner"></span> Loading...</div>

        <div v-else class="table-wrapper">
          <table>
            <thead>
              <tr>
                <th>Username</th>
                <th>Full Name</th>
                <th>Roles</th>
                <th>Status</th>
                <th>MFA</th>
                <th>Last Login</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="user in users" :key="user.id">
                <td>{{ user.username }}</td>
                <td>{{ user.full_name }}</td>
                <td>
                  <span class="badge badge-info" v-for="rs in user.active_role_scopes" :key="rs.id" style="margin-right: 0.15rem;">
                    {{ rs.role }}
                  </span>
                </td>
                <td>
                  <span :class="statusBadge(user.status)">{{ user.status }}</span>
                </td>
                <td>{{ user.totp_enabled ? 'Enabled' : 'Disabled' }}</td>
                <td>{{ user.last_login_at ? new Date(user.last_login_at).toLocaleString() : 'Never' }}</td>
                <td>
                  <router-link :to="`/users/${user.id}`" class="btn btn-sm btn-secondary">View</router-link>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div v-if="pagination" class="pagination">
          <button v-for="page in pagination.last_page" :key="page" :class="{ active: page === pagination.current_page }" @click="goToPage(page)">
            {{ page }}
          </button>
        </div>
      </div>
    </div>

    <!-- Create User Modal -->
    <div v-if="showCreateModal" style="position: fixed; inset: 0; background: rgba(0,0,0,0.5); display: flex; align-items: center; justify-content: center; z-index: 1000;">
      <div class="card" style="width: 500px; max-height: 90vh; overflow-y: auto;">
        <div class="card-header">
          Create New User
          <button class="btn btn-sm btn-secondary" @click="showCreateModal = false">Close</button>
        </div>
        <div class="card-body">
          <div v-if="createError" class="alert alert-error">{{ createError }}</div>

          <form @submit.prevent="createUser">
            <div class="form-group">
              <label>Username</label>
              <input v-model="newUser.username" class="form-control" required />
            </div>
            <div class="form-group">
              <label>Password (min 12 chars)</label>
              <input v-model="newUser.password" type="password" class="form-control" required minlength="12" />
            </div>
            <div class="form-group">
              <label>Full Name</label>
              <input v-model="newUser.full_name" class="form-control" required />
            </div>
            <div class="form-group">
              <label>Email</label>
              <input v-model="newUser.email" type="email" class="form-control" />
            </div>
            <div class="form-group">
              <label>Role</label>
              <select v-model="newUser.role" class="form-control" required>
                <option value="applicant">Applicant</option>
                <option value="advisor">Advisor</option>
                <option value="manager">Manager</option>
                <option value="steward">Data Steward</option>
                <option value="admin">Administrator</option>
              </select>
            </div>
            <div class="form-group">
              <label>Department Scope (optional)</label>
              <input v-model="newUser.department_scope" class="form-control" />
            </div>
            <button type="submit" class="btn btn-primary" :disabled="creating">
              {{ creating ? 'Creating...' : 'Create User' }}
            </button>
          </form>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import api from '../utils/api';

const users = ref([]);
const loading = ref(false);
const search = ref('');
const statusFilter = ref('');
const pagination = ref(null);
const showCreateModal = ref(false);
const creating = ref(false);
const createError = ref('');

const newUser = reactive({
  username: '',
  password: '',
  full_name: '',
  email: '',
  role: 'applicant',
  department_scope: '',
});

const statusBadge = (status) => {
  const map = { active: 'badge badge-success', inactive: 'badge badge-warning', locked: 'badge badge-danger' };
  return map[status] || 'badge';
};

const fetchUsers = async (page = 1) => {
  loading.value = true;
  try {
    const params = { page, per_page: 20 };
    if (search.value) params.search = search.value;
    if (statusFilter.value) params.status = statusFilter.value;

    const response = await api.get('/users', { params });
    users.value = response.data.data;
    pagination.value = response.data.meta?.pagination;
  } catch {
    users.value = [];
  } finally {
    loading.value = false;
  }
};

const goToPage = (page) => fetchUsers(page);

const createUser = async () => {
  creating.value = true;
  createError.value = '';
  try {
    await api.post('/users', {
      ...newUser,
      roles: [{ role: newUser.role, department_scope: newUser.department_scope || null }],
    });
    showCreateModal.value = false;
    Object.assign(newUser, { username: '', password: '', full_name: '', email: '', role: 'applicant', department_scope: '' });
    fetchUsers();
  } catch (err) {
    createError.value = err.response?.data?.error?.message || 'Failed to create user.';
  } finally {
    creating.value = false;
  }
};

onMounted(() => fetchUsers());
</script>
