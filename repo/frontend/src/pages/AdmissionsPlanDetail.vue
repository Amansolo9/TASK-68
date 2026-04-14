<template>
  <div>
    <div v-if="loading" class="loading"><span class="spinner"></span> Loading...</div>

    <div v-else-if="plan">
      <!-- Plan Header -->
      <div class="card">
        <div class="card-header">
          {{ plan.academic_year }} — {{ plan.intake_batch }}
          <div>
            <button v-if="canCreate" class="btn btn-sm btn-primary" style="margin-right: 0.25rem" @click="createNewVersion">New Draft Version</button>
            <button v-if="hasPublished && canCreate" class="btn btn-sm btn-secondary" @click="deriveFromPublished">Derive from Published</button>
          </div>
        </div>
        <div class="card-body">
          <p>Current version: <strong>v{{ plan.current_version?.version_no }}</strong> — <span :class="stateBadge(plan.current_version?.state)">{{ plan.current_version?.state }}</span></p>
        </div>
      </div>

      <!-- Version List -->
      <div class="card">
        <div class="card-header">Versions</div>
        <div class="card-body">
          <table>
            <thead><tr><th>Version</th><th>State</th><th>Effective Date</th><th>Created By</th><th>Actions</th></tr></thead>
            <tbody>
              <tr v-for="v in plan.versions" :key="v.id">
                <td>v{{ v.version_no }}</td>
                <td><span :class="stateBadge(v.state)">{{ v.state }}</span></td>
                <td>{{ v.effective_date || 'Not set' }}</td>
                <td>{{ v.created_by }}</td>
                <td>
                  <button class="btn btn-sm btn-secondary" style="margin-right: 0.25rem" @click="selectVersion(v)">View</button>
                  <button v-if="v.state === 'draft'" class="btn btn-sm btn-primary" style="margin-right: 0.25rem" @click="transition(v, 'submitted')">Submit</button>
                  <button v-if="v.state === 'submitted'" class="btn btn-sm btn-primary" style="margin-right: 0.25rem" @click="transition(v, 'under_review')">Start Review</button>
                  <button v-if="v.state === 'under_review'" class="btn btn-sm btn-primary" style="margin-right: 0.25rem" @click="transition(v, 'approved')">Approve</button>
                  <button v-if="v.state === 'under_review'" class="btn btn-sm btn-danger" style="margin-right: 0.25rem" @click="transition(v, 'returned', 'Returned for revision')">Return</button>
                  <button v-if="v.state === 'approved'" class="btn btn-sm btn-primary" @click="transition(v, 'published')">Publish</button>
                  <button v-if="v.state === 'published' || v.state === 'superseded'" class="btn btn-sm btn-secondary" @click="verifyIntegrity(v)">Verify Integrity</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Compare Versions -->
      <div v-if="plan.versions && plan.versions.length >= 2" class="card">
        <div class="card-header">Compare Versions</div>
        <div class="card-body">
          <div style="display: flex; gap: 0.5rem; align-items: center; margin-bottom: 1rem;">
            <select v-model="compareLeft" class="form-control" style="max-width: 200px;">
              <option value="">Left version...</option>
              <option v-for="v in plan.versions" :key="v.id" :value="v.id">v{{ v.version_no }} ({{ v.state }})</option>
            </select>
            <span>vs</span>
            <select v-model="compareRight" class="form-control" style="max-width: 200px;">
              <option value="">Right version...</option>
              <option v-for="v in plan.versions" :key="v.id" :value="v.id">v{{ v.version_no }} ({{ v.state }})</option>
            </select>
            <button class="btn btn-sm btn-primary" @click="runComparison" :disabled="!compareLeft || !compareRight || comparing">
              {{ comparing ? 'Comparing...' : 'Compare' }}
            </button>
          </div>

          <div v-if="comparisonResult">
            <div class="alert alert-info">
              Programs: +{{ comparisonResult.summary.programs_added }} / -{{ comparisonResult.summary.programs_removed }} / ~{{ comparisonResult.summary.programs_modified }} |
              Tracks: +{{ comparisonResult.summary.tracks_added }} / -{{ comparisonResult.summary.tracks_removed }} / ~{{ comparisonResult.summary.tracks_modified }}
            </div>
            <div v-for="change in comparisonResult.program_changes" :key="change.program_code" style="margin-bottom: 0.75rem; padding: 0.5rem; background: var(--gray-50); border-radius: var(--radius);">
              <strong>
                <span v-if="change.type === 'added'" style="color: var(--success);">[+]</span>
                <span v-if="change.type === 'removed'" style="color: var(--danger);">[-]</span>
                <span v-if="change.type === 'modified'" style="color: var(--warning);">[~]</span>
                {{ change.program_code }}
              </strong>
              <div v-if="change.field_changes" style="margin-left: 1rem; font-size: 0.85rem;">
                <div v-for="fc in change.field_changes" :key="fc.field">
                  <code>{{ fc.field }}</code>: <span style="color: var(--danger);">{{ fc.left }}</span> → <span style="color: var(--success);">{{ fc.right }}</span>
                </div>
              </div>
              <div v-if="change.track_changes" style="margin-left: 1rem; font-size: 0.85rem;">
                <div v-for="tc in change.track_changes" :key="tc.track_code">
                  <span v-if="tc.type === 'added'" style="color: var(--success);">[+track]</span>
                  <span v-if="tc.type === 'removed'" style="color: var(--danger);">[-track]</span>
                  <span v-if="tc.type === 'modified'" style="color: var(--warning);">[~track]</span>
                  {{ tc.track_code }}
                  <div v-if="tc.field_changes" style="margin-left: 1rem;">
                    <div v-for="tfc in tc.field_changes" :key="tfc.field">
                      <code>{{ tfc.field }}</code>: {{ tfc.left }} → {{ tfc.right }}
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Selected Version Detail -->
      <div v-if="selectedVersion" class="card">
        <div class="card-header">
          Version v{{ selectedVersion.version_no }} Detail
          <button class="btn btn-sm btn-secondary" @click="selectedVersion = null">Close</button>
        </div>
        <div class="card-body">
          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
            <div><strong>State:</strong> <span :class="stateBadge(selectedVersion.state)">{{ selectedVersion.state }}</span></div>
            <div><strong>Effective Date:</strong> {{ selectedVersion.effective_date || 'Not set' }}</div>
            <div><strong>Description:</strong> {{ selectedVersion.description || 'N/A' }}</div>
            <div v-if="selectedVersion.snapshot_hash"><strong>Snapshot Hash:</strong> <code style="font-size: 0.75rem;">{{ selectedVersion.snapshot_hash }}</code></div>
          </div>

          <!-- Edit effective date for draft -->
          <div v-if="selectedVersion.state === 'draft' && canCreate" style="margin-bottom: 1rem;">
            <div class="form-group">
              <label>Set Effective Date</label>
              <div style="display: flex; gap: 0.5rem;">
                <input v-model="editEffectiveDate" type="date" class="form-control" style="max-width: 200px;" />
                <button class="btn btn-sm btn-primary" @click="updateEffectiveDate">Save</button>
              </div>
            </div>
          </div>

          <!-- Programs -->
          <h3 style="margin-bottom: 0.5rem;">Programs</h3>
          <div v-if="canCreate && selectedVersion.state === 'draft'" style="margin-bottom: 0.5rem;">
            <button class="btn btn-sm btn-primary" @click="showAddProgram = true">Add Program</button>
          </div>
          <table v-if="selectedVersion.programs?.length">
            <thead><tr><th>Code</th><th>Name</th><th>Capacity</th><th>Tracks</th><th v-if="selectedVersion.state === 'draft'">Actions</th></tr></thead>
            <tbody>
              <tr v-for="prog in selectedVersion.programs" :key="prog.id">
                <td><code>{{ prog.program_code }}</code></td>
                <td>{{ prog.program_name }}</td>
                <td>{{ prog.planned_capacity ?? 'N/A' }}</td>
                <td>{{ prog.tracks?.length || 0 }} track(s)</td>
                <td v-if="selectedVersion.state === 'draft'">
                  <button class="btn btn-sm btn-secondary" style="margin-right: 0.25rem" @click="toggleTracks(prog)">{{ expandedProgram === prog.id ? 'Hide' : 'Show' }} Tracks</button>
                  <button class="btn btn-sm btn-danger" @click="removeProgram(prog)">Remove</button>
                </td>
              </tr>
              <!-- Expanded tracks -->
              <template v-for="prog in selectedVersion.programs" :key="'tracks-' + prog.id">
                <tr v-if="expandedProgram === prog.id" v-for="track in prog.tracks" :key="track.id" style="background: var(--gray-50);">
                  <td style="padding-left: 2rem;"><code>{{ track.track_code }}</code></td>
                  <td>{{ track.track_name }}</td>
                  <td>{{ track.planned_capacity ?? 'N/A' }}</td>
                  <td>{{ track.admission_criteria || 'N/A' }}</td>
                  <td v-if="selectedVersion.state === 'draft'">
                    <button class="btn btn-sm btn-danger" @click="removeTrack(prog, track)">Remove</button>
                  </td>
                </tr>
              </template>
            </tbody>
          </table>
          <p v-else style="color: var(--gray-500);">No programs added yet.</p>

          <!-- State History -->
          <h3 style="margin: 1rem 0 0.5rem;">State History</h3>
          <table v-if="selectedVersion.state_history?.length">
            <thead><tr><th>Time</th><th>From</th><th>To</th><th>Actor</th><th>Reason</th></tr></thead>
            <tbody>
              <tr v-for="h in selectedVersion.state_history" :key="h.id">
                <td style="font-size: 0.8rem;">{{ new Date(h.transitioned_at).toLocaleString() }}</td>
                <td>{{ h.from_state || '—' }}</td>
                <td><span :class="stateBadge(h.to_state)">{{ h.to_state }}</span></td>
                <td>{{ h.actor_user_id }}</td>
                <td>{{ h.reason || '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Integrity Result -->
      <div v-if="integrityResult" :class="integrityResult.valid ? 'alert alert-success' : 'alert alert-error'">
        Integrity: {{ integrityResult.valid ? 'VALID' : 'COMPROMISED' }} |
        Expected: <code>{{ integrityResult.expected_hash?.substring(0, 16) }}...</code> |
        Computed: <code>{{ integrityResult.computed_hash?.substring(0, 16) }}...</code>
      </div>
    </div>

    <!-- Add Program Modal -->
    <div v-if="showAddProgram" style="position: fixed; inset: 0; background: rgba(0,0,0,0.5); display: flex; align-items: center; justify-content: center; z-index: 1000;">
      <div class="card" style="width: 500px;">
        <div class="card-header">Add Program <button class="btn btn-sm btn-secondary" @click="showAddProgram = false">Close</button></div>
        <div class="card-body">
          <div v-if="programError" class="alert alert-error">{{ programError }}</div>
          <form @submit.prevent="addProgram">
            <div class="form-group"><label>Program Code</label><input v-model="programForm.program_code" class="form-control" required /></div>
            <div class="form-group"><label>Program Name</label><input v-model="programForm.program_name" class="form-control" required /></div>
            <div class="form-group"><label>Planned Capacity</label><input v-model.number="programForm.planned_capacity" type="number" class="form-control" /></div>
            <div class="form-group"><label>Capacity Notes</label><textarea v-model="programForm.capacity_notes" class="form-control" rows="2"></textarea></div>
            <button type="submit" class="btn btn-primary" :disabled="addingProgram">{{ addingProgram ? 'Adding...' : 'Add Program' }}</button>
          </form>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted, computed } from 'vue';
import { useRoute } from 'vue-router';
import { useAuthStore } from '../store/auth';
import api from '../utils/api';

const route = useRoute();
const auth = useAuthStore();
const plan = ref(null);
const loading = ref(true);
const selectedVersion = ref(null);
const expandedProgram = ref(null);
const canCreate = computed(() => auth.hasAnyRole(['manager', 'admin']));
const hasPublished = computed(() => plan.value?.versions?.some(v => v.state === 'published'));

// Comparison
const compareLeft = ref('');
const compareRight = ref('');
const comparing = ref(false);
const comparisonResult = ref(null);

// Integrity
const integrityResult = ref(null);

// Add program
const showAddProgram = ref(false);
const addingProgram = ref(false);
const programError = ref('');
const programForm = reactive({ program_code: '', program_name: '', planned_capacity: null, capacity_notes: '' });

// Edit effective date
const editEffectiveDate = ref('');

const stateBadge = (state) => {
  const map = {
    draft: 'badge badge-info', submitted: 'badge badge-warning', under_review: 'badge badge-warning',
    approved: 'badge badge-success', published: 'badge badge-success', returned: 'badge badge-danger',
    rejected: 'badge badge-danger', archived: 'badge', superseded: 'badge',
  };
  return map[state] || 'badge';
};

const fetchPlan = async () => {
  loading.value = true;
  try {
    const response = await api.get(`/admissions-plans/${route.params.id}`);
    plan.value = response.data.data;
  } catch { plan.value = null; }
  finally { loading.value = false; }
};

const selectVersion = async (v) => {
  try {
    const response = await api.get(`/admissions-plans/${route.params.id}/versions/${v.id}`);
    selectedVersion.value = response.data.data;
    editEffectiveDate.value = selectedVersion.value.effective_date || '';
  } catch (err) { alert(err.response?.data?.error?.message || 'Failed to load version.'); }
};

const transition = async (v, targetState, reason = null) => {
  if (!reason && ['returned', 'rejected'].includes(targetState)) {
    reason = prompt('Enter reason:');
    if (!reason) return;
  }
  try {
    await api.post(`/admissions-plans/${route.params.id}/versions/${v.id}/transition`, { target_state: targetState, reason });
    fetchPlan();
    if (selectedVersion.value?.id === v.id) selectVersion(v);
  } catch (err) { alert(err.response?.data?.error?.message || 'Transition failed.'); }
};

const createNewVersion = async () => {
  try {
    await api.post(`/admissions-plans/${route.params.id}/versions`, { description: 'New draft version' });
    fetchPlan();
  } catch (err) { alert(err.response?.data?.error?.message || 'Failed.'); }
};

const deriveFromPublished = async () => {
  try {
    await api.post(`/admissions-plans/${route.params.id}/derive-from-published`, { description: 'Derived from published' });
    fetchPlan();
  } catch (err) { alert(err.response?.data?.error?.message || 'Failed.'); }
};

const runComparison = async () => {
  comparing.value = true;
  try {
    const response = await api.post(`/admissions-plans/${route.params.id}/compare`, {
      left_version_id: parseInt(compareLeft.value),
      right_version_id: parseInt(compareRight.value),
    });
    comparisonResult.value = response.data.data;
  } catch (err) { alert(err.response?.data?.error?.message || 'Comparison failed.'); }
  finally { comparing.value = false; }
};

const verifyIntegrity = async (v) => {
  try {
    const response = await api.get(`/admissions-plans/${route.params.id}/versions/${v.id}/integrity`);
    integrityResult.value = response.data.data;
  } catch (err) { alert(err.response?.data?.error?.message || 'Verification failed.'); }
};

const addProgram = async () => {
  addingProgram.value = true; programError.value = '';
  try {
    await api.post(`/admissions-plans/${route.params.id}/versions/${selectedVersion.value.id}/programs`, programForm);
    showAddProgram.value = false;
    Object.assign(programForm, { program_code: '', program_name: '', planned_capacity: null, capacity_notes: '' });
    selectVersion(selectedVersion.value);
  } catch (err) { programError.value = err.response?.data?.error?.message || 'Failed.'; }
  finally { addingProgram.value = false; }
};

const removeProgram = async (prog) => {
  if (!confirm(`Remove program ${prog.program_code}?`)) return;
  try {
    await api.delete(`/admissions-plans/${route.params.id}/versions/${selectedVersion.value.id}/programs/${prog.id}`);
    selectVersion(selectedVersion.value);
  } catch (err) { alert(err.response?.data?.error?.message || 'Failed.'); }
};

const removeTrack = async (prog, track) => {
  if (!confirm(`Remove track ${track.track_code}?`)) return;
  try {
    await api.delete(`/admissions-plans/${route.params.id}/versions/${selectedVersion.value.id}/programs/${prog.id}/tracks/${track.id}`);
    selectVersion(selectedVersion.value);
  } catch (err) { alert(err.response?.data?.error?.message || 'Failed.'); }
};

const toggleTracks = (prog) => {
  expandedProgram.value = expandedProgram.value === prog.id ? null : prog.id;
};

const updateEffectiveDate = async () => {
  try {
    await api.put(`/admissions-plans/${route.params.id}/versions/${selectedVersion.value.id}`, { effective_date: editEffectiveDate.value });
    selectVersion(selectedVersion.value);
  } catch (err) { alert(err.response?.data?.error?.message || 'Failed.'); }
};

onMounted(() => fetchPlan());
</script>
