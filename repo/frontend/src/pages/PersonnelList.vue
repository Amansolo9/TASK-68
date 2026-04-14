<template>
  <div>
    <div class="card">
      <div class="card-header">
        Personnel
        <button v-if="canEdit" class="btn btn-primary btn-sm" @click="showCreate = true">Add Personnel</button>
      </div>
      <div class="card-body">
        <div style="display: flex; gap: 0.5rem; margin-bottom: 1rem;">
          <input v-model="search" class="form-control" placeholder="Search personnel..." style="max-width: 300px" @input="debouncedFetch" />
        </div>

        <div v-if="loading" class="loading"><span class="spinner"></span> Loading...</div>

        <div v-else class="table-wrapper">
          <table>
            <thead><tr><th>Employee ID</th><th>Name</th><th>Email</th><th>DOB</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
              <tr v-for="p in items" :key="p.id">
                <td>{{ p.employee_id || 'N/A' }}</td>
                <td>{{ p.full_name }}</td>
                <td>{{ p.email || 'N/A' }}</td>
                <td><span class="masked-value">{{ p.date_of_birth || 'N/A' }}</span></td>
                <td><span :class="p.status === 'active' ? 'badge badge-success' : 'badge badge-warning'">{{ p.status }}</span></td>
                <td><button class="btn btn-sm btn-secondary" @click="viewDetail(p)">View</button></td>
              </tr>
            </tbody>
          </table>
        </div>

        <div v-if="pagination" class="pagination">
          <button v-for="page in pagination.last_page" :key="page" :class="{ active: page === pagination.current_page }" @click="fetchData(page)">{{ page }}</button>
        </div>
      </div>
    </div>

    <div v-if="showCreate" style="position: fixed; inset: 0; background: rgba(0,0,0,0.5); display: flex; align-items: center; justify-content: center; z-index: 1000;">
      <div class="card" style="width: 500px;">
        <div class="card-header">Add Personnel <button class="btn btn-sm btn-secondary" @click="showCreate = false">Close</button></div>
        <div class="card-body">
          <div v-if="formError" class="alert alert-error">{{ formError }}</div>
          <form @submit.prevent="handleCreate">
            <div class="form-group"><label>Employee ID</label><input v-model="form.employee_id" class="form-control" /></div>
            <div class="form-group"><label>Full Name</label><input v-model="form.full_name" class="form-control" required /></div>
            <div class="form-group"><label>Email</label><input v-model="form.email" type="email" class="form-control" /></div>
            <div class="form-group"><label>Phone</label><input v-model="form.phone" class="form-control" /></div>
            <div class="form-group"><label>Date of Birth (MM/DD/YYYY)</label><input v-model="form.date_of_birth" class="form-control" placeholder="MM/DD/YYYY" /></div>
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

const form = reactive({ employee_id: '', full_name: '', email: '', phone: '', date_of_birth: '' });

let debounceTimer;
const debouncedFetch = () => { clearTimeout(debounceTimer); debounceTimer = setTimeout(() => fetchData(), 300); };

const fetchData = async (page = 1) => {
  loading.value = true;
  try {
    const params = { page, per_page: 20 };
    if (search.value) params.search = search.value;
    const response = await api.get('/personnel', { params });
    items.value = response.data.data;
    pagination.value = response.data.meta?.pagination;
  } catch { items.value = []; }
  finally { loading.value = false; }
};

const handleCreate = async () => {
  saving.value = true; formError.value = '';
  try {
    await api.post('/personnel', form);
    showCreate.value = false;
    Object.assign(form, { employee_id: '', full_name: '', email: '', phone: '', date_of_birth: '' });
    fetchData();
  } catch (err) { formError.value = err.response?.data?.error?.message || 'Failed to create.'; }
  finally { saving.value = false; }
};

const viewDetail = (p) => alert(`Personnel: ${p.full_name}\nEmployee ID: ${p.employee_id || 'N/A'}\nStatus: ${p.status}`);

onMounted(() => fetchData());
</script>
