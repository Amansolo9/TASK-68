<template>
  <div>
    <div class="card">
      <div class="card-header">
        Audit Logs
        <button class="btn btn-sm btn-secondary" @click="verifyChain" :disabled="verifying">
          {{ verifying ? 'Verifying...' : 'Verify Chain Integrity' }}
        </button>
      </div>
      <div class="card-body">
        <div v-if="chainResult" :class="chainResult.valid ? 'alert alert-success' : 'alert alert-error'" style="margin-bottom: 1rem;">
          Chain integrity: {{ chainResult.valid ? 'VALID' : 'BROKEN' }} ({{ chainResult.checked }} entries checked)
          <span v-if="chainResult.broken_at"> - Broken at entry #{{ chainResult.broken_at }}</span>
        </div>

        <div style="display: flex; gap: 0.5rem; margin-bottom: 1rem; flex-wrap: wrap;">
          <select v-model="filters.entity_type" class="form-control" style="max-width: 150px" @change="fetchData">
            <option value="">All entities</option>
            <option value="user">User</option>
            <option value="organization">Organization</option>
            <option value="personnel">Personnel</option>
            <option value="position">Position</option>
            <option value="course_category">Course Category</option>
            <option value="data_dictionary">Dictionary</option>
          </select>
          <select v-model="filters.event_type" class="form-control" style="max-width: 150px" @change="fetchData">
            <option value="">All events</option>
            <option value="login">Login</option>
            <option value="logout">Logout</option>
            <option value="mfa_enabled">MFA Enabled</option>
            <option value="user_created">User Created</option>
            <option value="user_updated">User Updated</option>
          </select>
        </div>

        <div v-if="loading" class="loading"><span class="spinner"></span> Loading...</div>

        <div v-else class="table-wrapper">
          <table>
            <thead><tr><th>Time</th><th>Actor</th><th>Entity</th><th>Event</th><th>IP</th><th>Chain Hash</th></tr></thead>
            <tbody>
              <tr v-for="log in items" :key="log.id">
                <td style="font-size: 0.8rem;">{{ new Date(log.created_at).toLocaleString() }}</td>
                <td>{{ log.actor_user_id || 'System' }}</td>
                <td>{{ log.entity_type }}{{ log.entity_id ? '#' + log.entity_id : '' }}</td>
                <td><span class="badge badge-info">{{ log.event_type }}</span></td>
                <td style="font-size: 0.8rem;">{{ log.ip_address }}</td>
                <td style="font-family: monospace; font-size: 0.7rem;">{{ log.chain_hash?.substring(0, 12) }}...</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-if="pagination" class="pagination">
          <button v-for="page in pagination.last_page" :key="page" :class="{ active: page === pagination.current_page }" @click="fetchData(page)">{{ page }}</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import api from '../utils/api';

const items = ref([]);
const loading = ref(false);
const pagination = ref(null);
const verifying = ref(false);
const chainResult = ref(null);
const filters = reactive({ entity_type: '', event_type: '' });

const fetchData = async (page = 1) => {
  loading.value = true;
  try {
    const params = { page, per_page: 50 };
    if (filters.entity_type) params.entity_type = filters.entity_type;
    if (filters.event_type) params.event_type = filters.event_type;
    const response = await api.get('/audit-logs', { params });
    items.value = response.data.data;
    pagination.value = response.data.meta?.pagination;
  } catch { items.value = []; }
  finally { loading.value = false; }
};

const verifyChain = async () => {
  verifying.value = true;
  try {
    const response = await api.get('/audit-logs/verify/chain');
    chainResult.value = response.data.data;
  } catch { chainResult.value = { valid: false, checked: 0 }; }
  finally { verifying.value = false; }
};

onMounted(() => fetchData());
</script>
