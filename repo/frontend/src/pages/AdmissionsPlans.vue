<template>
  <div>
    <div class="card">
      <div class="card-header">
        {{ isStaff ? 'Admissions Plans (Management)' : 'Published Admissions Plans' }}
        <button v-if="canCreate" class="btn btn-primary btn-sm" @click="showCreate = true">Create Plan</button>
      </div>
      <div class="card-body">
        <div style="display: flex; gap: 0.5rem; margin-bottom: 1rem;">
          <input v-model="yearFilter" class="form-control" placeholder="Academic Year..." style="max-width: 200px" @input="debounceFetch" />
          <input v-model="intakeFilter" class="form-control" placeholder="Intake Batch..." style="max-width: 200px" @input="debounceFetch" />
        </div>

        <div v-if="loading" class="loading"><span class="spinner"></span> Loading...</div>

        <div v-else-if="!plans.length" class="alert alert-info">No plans found matching your filters.</div>

        <div v-else class="table-wrapper">
          <table>
            <thead>
              <tr>
                <th>Academic Year</th>
                <th>Intake Batch</th>
                <template v-if="isStaff">
                  <th>Current Version</th>
                  <th>Version State</th>
                </template>
                <template v-else>
                  <th>Programs</th>
                  <th>Status</th>
                </template>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="plan in plans" :key="plan.id">
                <td>{{ plan.academic_year }}</td>
                <td>{{ plan.intake_batch }}</td>
                <template v-if="isStaff">
                  <td>v{{ plan.current_version?.version_no || '—' }}</td>
                  <td>
                    <span :class="stateBadge(plan.current_version?.state)">{{ plan.current_version?.state || 'N/A' }}</span>
                  </td>
                </template>
                <template v-else>
                  <td>{{ publishedProgramCount(plan) }} program(s)</td>
                  <td><span class="badge badge-success">Published</span></td>
                </template>
                <td>
                  <router-link v-if="isStaff" :to="`/admissions-plans/${plan.id}`" class="btn btn-sm btn-secondary">Manage</router-link>
                  <router-link v-else :to="`/published-plans/${plan.id}`" class="btn btn-sm btn-secondary">View Details</router-link>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div v-if="pagination" class="pagination">
          <button v-for="page in pagination.last_page" :key="page" :class="{ active: page === pagination.current_page }" @click="fetchPlans(page)">{{ page }}</button>
        </div>
      </div>
    </div>

    <!-- Create Modal -->
    <div v-if="showCreate" style="position: fixed; inset: 0; background: rgba(0,0,0,0.5); display: flex; align-items: center; justify-content: center; z-index: 1000;">
      <div class="card" style="width: 500px;">
        <div class="card-header">Create Admissions Plan <button class="btn btn-sm btn-secondary" @click="showCreate = false">Close</button></div>
        <div class="card-body">
          <div v-if="formError" class="alert alert-error">{{ formError }}</div>
          <form @submit.prevent="handleCreate">
            <div class="form-group"><label>Academic Year</label><input v-model="form.academic_year" class="form-control" required placeholder="e.g. 2025-2026" /></div>
            <div class="form-group"><label>Intake Batch</label><input v-model="form.intake_batch" class="form-control" required placeholder="e.g. Fall 2025" /></div>
            <div class="form-group"><label>Description</label><textarea v-model="form.description" class="form-control" rows="3"></textarea></div>
            <button type="submit" class="btn btn-primary" :disabled="saving">{{ saving ? 'Creating...' : 'Create Plan' }}</button>
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
const plans = ref([]);
const loading = ref(false);
const yearFilter = ref('');
const intakeFilter = ref('');
const pagination = ref(null);
const showCreate = ref(false);
const saving = ref(false);
const formError = ref('');

const isStaff = computed(() => auth.hasAnyRole(['manager', 'admin', 'advisor', 'steward']));
const canCreate = computed(() => auth.hasAnyRole(['manager', 'admin']));
const form = reactive({ academic_year: '', intake_batch: '', description: '' });

let debounceTimer = null;
const debounceFetch = () => {
  clearTimeout(debounceTimer);
  debounceTimer = setTimeout(() => fetchPlans(), 300);
};

const publishedProgramCount = (plan) => {
  const pubVersion = plan.versions?.find(v => v.state === 'published');
  return pubVersion?.programs?.length || 0;
};

const stateBadge = (state) => {
  const map = {
    draft: 'badge badge-info', submitted: 'badge badge-warning', under_review: 'badge badge-warning',
    approved: 'badge badge-success', published: 'badge badge-success', returned: 'badge badge-danger',
    rejected: 'badge badge-danger', archived: 'badge', superseded: 'badge',
  };
  return map[state] || 'badge';
};

const fetchPlans = async (page = 1) => {
  loading.value = true;
  try {
    const params = { page, per_page: 20 };
    if (yearFilter.value) params.academic_year = yearFilter.value;
    if (intakeFilter.value) params.intake_batch = intakeFilter.value;

    const endpoint = isStaff.value ? '/admissions-plans' : '/published-plans';
    const response = await api.get(endpoint, { params });
    plans.value = response.data.data;
    pagination.value = response.data.meta?.pagination;
  } catch { plans.value = []; }
  finally { loading.value = false; }
};

const handleCreate = async () => {
  saving.value = true; formError.value = '';
  try {
    await api.post('/admissions-plans', form);
    showCreate.value = false;
    Object.assign(form, { academic_year: '', intake_batch: '', description: '' });
    fetchPlans();
  } catch (err) { formError.value = err.response?.data?.error?.message || 'Failed to create plan.'; }
  finally { saving.value = false; }
};

onMounted(() => fetchPlans());
</script>
