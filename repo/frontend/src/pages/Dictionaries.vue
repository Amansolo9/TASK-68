<template>
  <div>
    <div class="card">
      <div class="card-header">
        Data Dictionaries
        <button class="btn btn-primary btn-sm" @click="showCreate = true">Add Entry</button>
      </div>
      <div class="card-body">
        <div style="display: flex; gap: 0.5rem; margin-bottom: 1rem;">
          <select v-model="typeFilter" class="form-control" style="max-width: 200px" @change="fetchData">
            <option value="">All Types</option>
            <option v-for="t in types" :key="t" :value="t">{{ t }}</option>
          </select>
        </div>
        <div v-if="loading" class="loading"><span class="spinner"></span> Loading...</div>
        <div v-else class="table-wrapper">
          <table>
            <thead><tr><th>Type</th><th>Code</th><th>Label</th><th>Active</th><th>Sort</th><th>Actions</th></tr></thead>
            <tbody>
              <tr v-for="item in items" :key="item.id">
                <td>{{ item.dictionary_type }}</td>
                <td><code>{{ item.code }}</code></td>
                <td>{{ item.label }}</td>
                <td><span :class="item.is_active ? 'badge badge-success' : 'badge badge-warning'">{{ item.is_active ? 'Active' : 'Inactive' }}</span></td>
                <td>{{ item.sort_order }}</td>
                <td>
                  <button class="btn btn-sm btn-secondary" @click="toggleActive(item)">{{ item.is_active ? 'Deactivate' : 'Activate' }}</button>
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
    <div v-if="showCreate" style="position: fixed; inset: 0; background: rgba(0,0,0,0.5); display: flex; align-items: center; justify-content: center; z-index: 1000;">
      <div class="card" style="width: 500px;">
        <div class="card-header">Add Dictionary Entry <button class="btn btn-sm btn-secondary" @click="showCreate = false">Close</button></div>
        <div class="card-body">
          <div v-if="formError" class="alert alert-error">{{ formError }}</div>
          <form @submit.prevent="handleCreate">
            <div class="form-group"><label>Type</label><input v-model="form.dictionary_type" class="form-control" required /></div>
            <div class="form-group"><label>Code</label><input v-model="form.code" class="form-control" required /></div>
            <div class="form-group"><label>Label</label><input v-model="form.label" class="form-control" required /></div>
            <div class="form-group"><label>Description</label><textarea v-model="form.description" class="form-control" rows="2"></textarea></div>
            <div class="form-group"><label>Sort Order</label><input v-model.number="form.sort_order" type="number" class="form-control" /></div>
            <button type="submit" class="btn btn-primary" :disabled="saving">{{ saving ? 'Saving...' : 'Create' }}</button>
          </form>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import api from '../utils/api';

const items = ref([]);
const types = ref(['ticket_category', 'ticket_priority', 'org_type', 'appointment_type']);
const loading = ref(false);
const typeFilter = ref('');
const pagination = ref(null);
const showCreate = ref(false);
const saving = ref(false);
const formError = ref('');
const form = reactive({ dictionary_type: '', code: '', label: '', description: '', sort_order: 0 });

const fetchData = async (page = 1) => {
  loading.value = true;
  try {
    const params = { page, per_page: 50 };
    if (typeFilter.value) params.type = typeFilter.value;
    const response = await api.get('/dictionaries', { params });
    items.value = response.data.data;
    pagination.value = response.data.meta?.pagination;
  } catch { items.value = []; }
  finally { loading.value = false; }
};

const handleCreate = async () => {
  saving.value = true; formError.value = '';
  try {
    await api.post('/dictionaries', form);
    showCreate.value = false;
    Object.assign(form, { dictionary_type: '', code: '', label: '', description: '', sort_order: 0 });
    fetchData();
  } catch (err) { formError.value = err.response?.data?.error?.message || 'Failed to create.'; }
  finally { saving.value = false; }
};

const toggleActive = async (item) => {
  try {
    await api.put(`/dictionaries/${item.id}`, { is_active: !item.is_active });
    fetchData();
  } catch (err) { alert(err.response?.data?.error?.message || 'Failed.'); }
};

onMounted(() => fetchData());
</script>
