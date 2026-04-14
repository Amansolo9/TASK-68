<template>
  <div>
    <div v-if="loading" class="loading"><span class="spinner"></span> Loading...</div>

    <div v-else-if="error" class="alert alert-error">{{ error }}</div>

    <div v-else-if="plan">
      <!-- Plan Header -->
      <div class="card">
        <div class="card-header">
          {{ plan.academic_year }} &mdash; {{ plan.intake_batch }}
          <router-link to="/admissions-plans" class="btn btn-sm btn-secondary">Back to Plans</router-link>
        </div>
        <div class="card-body">
          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
            <div><strong>Academic Year:</strong> {{ plan.academic_year }}</div>
            <div><strong>Intake Batch:</strong> {{ plan.intake_batch }}</div>
            <div v-if="plan.published_version?.effective_date"><strong>Effective Date:</strong> {{ plan.published_version.effective_date }}</div>
            <div v-if="plan.published_version?.description"><strong>Description:</strong> {{ plan.published_version.description }}</div>
          </div>
        </div>
      </div>

      <!-- Programs and Tracks -->
      <div class="card" v-if="plan.published_version?.programs?.length">
        <div class="card-header">Programs &amp; Tracks</div>
        <div class="card-body">
          <div v-for="prog in plan.published_version.programs" :key="prog.id" style="margin-bottom: 1.5rem; padding: 1rem; background: var(--gray-50, #f8f9fa); border-radius: var(--radius, 4px);">
            <h3 style="margin: 0 0 0.5rem;">
              <code>{{ prog.program_code }}</code> &mdash; {{ prog.program_name }}
            </h3>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; margin-bottom: 0.75rem;">
              <div v-if="prog.planned_capacity != null"><strong>Planned Capacity:</strong> {{ prog.planned_capacity }}</div>
              <div v-if="prog.capacity_notes"><strong>Capacity Notes:</strong> {{ prog.capacity_notes }}</div>
              <div v-if="prog.description"><strong>Description:</strong> {{ prog.description }}</div>
            </div>

            <div v-if="prog.tracks?.length">
              <h4 style="margin: 0.5rem 0 0.25rem; font-size: 0.9rem;">Tracks</h4>
              <table>
                <thead>
                  <tr>
                    <th>Code</th>
                    <th>Name</th>
                    <th>Capacity</th>
                    <th>Capacity Notes</th>
                    <th>Admission Criteria</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="track in prog.tracks" :key="track.id">
                    <td><code>{{ track.track_code }}</code></td>
                    <td>{{ track.track_name }}</td>
                    <td>{{ track.planned_capacity ?? 'N/A' }}</td>
                    <td>{{ track.capacity_notes || '—' }}</td>
                    <td>{{ track.admission_criteria || '—' }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
            <p v-else style="color: var(--gray-500, #6c757d); font-size: 0.85rem;">No tracks defined for this program.</p>
          </div>
        </div>
      </div>
      <div v-else class="card">
        <div class="card-body">
          <p style="color: var(--gray-500, #6c757d);">No programs have been published for this plan yet.</p>
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
const plan = ref(null);
const loading = ref(true);
const error = ref('');

const fetchPlan = async () => {
  loading.value = true;
  error.value = '';
  try {
    const response = await api.get(`/published-plans/${route.params.id}`);
    plan.value = response.data.data;
  } catch (err) {
    error.value = err.response?.data?.error?.message || 'Failed to load plan details.';
    plan.value = null;
  } finally {
    loading.value = false;
  }
};

onMounted(() => fetchPlan());
</script>
