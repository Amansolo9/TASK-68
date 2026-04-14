<template>
  <div>
    <div class="card">
      <div class="card-header">
        Organizations
        <button v-if="canEdit" class="btn btn-primary btn-sm" @click="showCreate = true">Add Organization</button>
      </div>
      <div class="card-body">
        <div style="display: flex; gap: 0.5rem; margin-bottom: 1rem;">
          <input v-model="search" class="form-control" placeholder="Search organizations..." style="max-width: 300px" @input="debouncedFetch" />
        </div>

        <div v-if="loading" class="loading"><span class="spinner"></span> Loading...</div>

        <div v-else class="table-wrapper">
          <table>
            <thead><tr><th>Code</th><th>Name</th><th>Type</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
              <tr v-for="org in items" :key="org.id">
                <td><code>{{ org.code }}</code></td>
                <td>{{ org.name }}</td>
                <td>{{ org.type || 'N/A' }}</td>
                <td><span :class="org.status === 'active' ? 'badge badge-success' : 'badge badge-warning'">{{ org.status }}</span></td>
                <td>
                  <button class="btn btn-sm btn-secondary" @click="viewDetail(org)">View</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div v-if="pagination" class="pagination">
          <button v-for="page in pagination.last_page" :key="page" :class="{ active: page === pagination.current_page }" @click="fetchData(page)">{{ page }}</button>
        </div>
      </div>
    </div>

    <!-- Create Modal -->
    <div v-if="showCreate" style="position: fixed; inset: 0; background: rgba(0,0,0,0.5); display: flex; align-items: center; justify-content: center; z-index: 1000;">
      <div class="card" style="width: 500px;">
        <div class="card-header">
          Add Organization
          <button class="btn btn-sm btn-secondary" @click="showCreate = false">Close</button>
        </div>
        <div class="card-body">
          <div v-if="formError" class="alert alert-error">{{ formError }}</div>
          <form @submit.prevent="handleCreate">
            <div class="form-group">
              <label>Code (e.g., ORG-000123)</label>
              <input v-model="form.code" class="form-control" required pattern="ORG-\d{6}" />
            </div>
            <div class="form-group">
              <label>Name</label>
              <input v-model="form.name" class="form-control" required />
            </div>
            <div class="form-group">
              <label>Type</label>
              <input v-model="form.type" class="form-control" />
            </div>
            <div class="form-group">
              <label>Address</label>
              <textarea v-model="form.address" class="form-control" rows="2"></textarea>
            </div>
            <div class="form-group">
              <label>Phone</label>
              <input v-model="form.phone" class="form-control" />
            </div>
            <button type="submit" class="btn btn-primary" :disabled="saving">{{ saving ? 'Saving...' : 'Create' }}</button>
          </form>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted, computed } from 'vue';
import { useAuthStore } from '../store/auth';
import api from '../utils/api';

const auth = useAuthStore();
const items = ref([]);
const loading = ref(false);
const search = ref('');
const pagination = ref(null);
const showCreate = ref(false);
const saving = ref(false);
const formError = ref('');

const canEdit = computed(() => auth.hasAnyRole(['steward', 'admin']));

const form = reactive({ code: '', name: '', type: '', address: '', phone: '' });

let debounceTimer;
const debouncedFetch = () => {
  clearTimeout(debounceTimer);
  debounceTimer = setTimeout(() => fetchData(), 300);
};

const fetchData = async (page = 1) => {
  loading.value = true;
  try {
    const params = { page, per_page: 20 };
    if (search.value) params.search = search.value;
    const response = await api.get('/organizations', { params });
    items.value = response.data.data;
    pagination.value = response.data.meta?.pagination;
  } catch { items.value = []; }
  finally { loading.value = false; }
};

const handleCreate = async () => {
  saving.value = true;
  formError.value = '';
  try {
    await api.post('/organizations', form);
    showCreate.value = false;
    Object.assign(form, { code: '', name: '', type: '', address: '', phone: '' });
    fetchData();
  } catch (err) {
    formError.value = err.response?.data?.error?.message || 'Failed to create.';
  } finally { saving.value = false; }
};

const viewDetail = (org) => {
  alert(`Organization: ${org.code} - ${org.name}\nStatus: ${org.status}\nType: ${org.type || 'N/A'}`);
};

onMounted(() => fetchData());
</script>
