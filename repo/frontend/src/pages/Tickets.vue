<template>
  <div>
    <div class="card">
      <div class="card-header">
        Consultation Tickets
        <button v-if="auth.isApplicant" class="btn btn-primary btn-sm" @click="showCreate = true">Submit Consultation</button>
      </div>
      <div class="card-body">
        <div style="display: flex; gap: 0.5rem; margin-bottom: 1rem; flex-wrap: wrap;">
          <select v-model="filters.status" class="form-control" style="max-width: 150px" @change="fetchTickets">
            <option value="">All statuses</option>
            <option v-for="s in statuses" :key="s" :value="s">{{ s }}</option>
          </select>
          <select v-model="filters.priority" class="form-control" style="max-width: 120px" @change="fetchTickets">
            <option value="">All priorities</option>
            <option value="Normal">Normal</option>
            <option value="High">High</option>
          </select>
          <input v-model="filters.department_id" class="form-control" style="max-width: 160px" placeholder="Department ID" @change="fetchTickets" />
          <select v-model="filters.category_tag" class="form-control" style="max-width: 160px" @change="fetchTickets">
            <option value="">All categories</option>
            <option value="GENERAL">General</option>
            <option value="ADMISSION">Admission</option>
            <option value="FINANCIAL">Financial</option>
            <option value="TRANSFER">Transfer</option>
            <option value="PROGRAM">Program</option>
          </select>
          <label style="display: flex; align-items: center; gap: 0.25rem; font-size: 0.85rem;">
            <input type="checkbox" v-model="filters.overdue" @change="fetchTickets" /> Overdue only
          </label>
        </div>

        <div v-if="loading" class="loading"><span class="spinner"></span> Loading...</div>
        <div v-else class="table-wrapper">
          <table>
            <thead><tr>
              <th>Ticket #</th><th>Category</th><th>Department</th><th>Priority</th><th>Status</th>
              <th>SLA Due</th><th>Overdue</th><th>Created</th><th>Actions</th>
            </tr></thead>
            <tbody>
              <tr v-for="t in tickets" :key="t.id">
                <td><code>{{ t.local_ticket_no }}</code></td>
                <td>{{ t.category_tag }}</td>
                <td>{{ t.department_id || '—' }}</td>
                <td><span :class="t.priority === 'High' ? 'badge badge-danger' : 'badge badge-info'">{{ t.priority }}</span></td>
                <td><span :class="ticketStatusBadge(t.status)">{{ t.status }}</span></td>
                <td style="font-size: 0.8rem;">{{ t.first_response_due_at ? new Date(t.first_response_due_at).toLocaleString() : '—' }}</td>
                <td><span v-if="t.overdue_flag" class="badge badge-danger">OVERDUE</span></td>
                <td style="font-size: 0.8rem;">{{ new Date(t.created_at).toLocaleString() }}</td>
                <td>
                  <router-link :to="`/tickets/${t.id}`" class="btn btn-sm btn-secondary" style="margin-right:0.25rem">View</router-link>
                  <button v-if="auth.hasAnyRole(['manager','admin'])" class="btn btn-sm btn-warning" @click="openReassign(t)">Reassign</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-if="pagination" class="pagination">
          <button v-for="page in pagination.last_page" :key="page" :class="{ active: page === pagination.current_page }" @click="fetchTickets(page)">{{ page }}</button>
        </div>
      </div>
    </div>

    <!-- Create Ticket Modal -->
    <div v-if="showCreate" style="position: fixed; inset: 0; background: rgba(0,0,0,0.5); display: flex; align-items: center; justify-content: center; z-index: 1000;">
      <div class="card" style="width: 550px; max-height: 90vh; overflow-y: auto;">
        <div class="card-header">Submit Consultation <button class="btn btn-sm btn-secondary" @click="showCreate = false">Close</button></div>
        <div class="card-body">
          <div v-if="createError" class="alert alert-error">{{ createError }}</div>
          <div v-if="createdTicketNo" class="alert alert-success">
            Ticket created: <strong>{{ createdTicketNo }}</strong>
          </div>
          <form v-if="!createdTicketNo" @submit.prevent="handleCreate">
            <div class="form-group">
              <label>Category</label>
              <select v-model="form.category_tag" class="form-control" required>
                <option value="">Select category...</option>
                <option value="GENERAL">General Inquiry</option>
                <option value="ADMISSION">Admission Question</option>
                <option value="FINANCIAL">Financial Aid</option>
                <option value="TRANSFER">Transfer Credit</option>
                <option value="PROGRAM">Program Information</option>
              </select>
            </div>
            <div class="form-group">
              <label>Priority</label>
              <select v-model="form.priority" class="form-control" required>
                <option value="Normal">Normal</option>
                <option value="High">High</option>
              </select>
            </div>
            <div class="form-group">
              <label>Message</label>
              <textarea v-model="form.message" class="form-control" rows="5" required minlength="10" placeholder="Describe your question or concern..."></textarea>
            </div>
            <div class="form-group">
              <label>Attachments (JPEG/PNG, max 5MB each, max 3)</label>
              <input type="file" @change="handleFiles" multiple accept=".jpg,.jpeg,.png" class="form-control" />
            </div>
            <button type="submit" class="btn btn-primary" :disabled="creating">{{ creating ? 'Submitting...' : 'Submit Consultation' }}</button>
          </form>
        </div>
      </div>
    </div>
    <!-- Reassign Modal -->
    <div v-if="reassignTarget" style="position: fixed; inset: 0; background: rgba(0,0,0,0.5); display: flex; align-items: center; justify-content: center; z-index: 1000;">
      <div class="card" style="width: 450px; max-height: 90vh; overflow-y: auto;">
        <div class="card-header">Reassign Ticket <button class="btn btn-sm btn-secondary" @click="reassignTarget = null">Close</button></div>
        <div class="card-body">
          <div v-if="reassignError" class="alert alert-error">{{ reassignError }}</div>
          <div v-if="reassignSuccess" class="alert alert-success">Ticket reassigned successfully.</div>
          <form v-if="!reassignSuccess" @submit.prevent="submitReassign">
            <div class="form-group">
              <label>To Advisor ID (optional)</label>
              <input v-model.number="reassignForm.to_advisor_id" type="number" class="form-control" placeholder="Advisor user ID" />
            </div>
            <div class="form-group">
              <label>To Department ID (optional)</label>
              <input v-model="reassignForm.to_department_id" class="form-control" placeholder="Department ID" />
            </div>
            <div class="form-group">
              <label>Reason (required)</label>
              <textarea v-model="reassignForm.reason" class="form-control" rows="3" required minlength="5" placeholder="Explain the reason for reassignment..."></textarea>
            </div>
            <button type="submit" class="btn btn-primary" :disabled="reassigning">{{ reassigning ? 'Reassigning...' : 'Reassign Ticket' }}</button>
          </form>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import { useAuthStore } from '../store/auth';
import api from '../utils/api';

const auth = useAuthStore();
const tickets = ref([]);
const loading = ref(false);
const pagination = ref(null);
const showCreate = ref(false);
const creating = ref(false);
const createError = ref('');
const createdTicketNo = ref('');
const selectedFiles = ref([]);
const reassignTarget = ref(null);
const reassignError = ref('');
const reassignSuccess = ref(false);
const reassigning = ref(false);
const reassignForm = reactive({ to_advisor_id: null, to_department_id: '', reason: '' });

const statuses = ['new', 'triaged', 'reassigned', 'in_progress', 'waiting_applicant', 'resolved', 'reopened', 'closed', 'auto_closed'];

const filters = reactive({ status: '', priority: '', overdue: false, department_id: '', category_tag: '' });
const form = reactive({ category_tag: '', priority: 'Normal', message: '' });

const ticketStatusBadge = (status) => {
  const map = {
    new: 'badge badge-info', triaged: 'badge badge-info', in_progress: 'badge badge-warning',
    waiting_applicant: 'badge badge-warning', resolved: 'badge badge-success', closed: 'badge',
    auto_closed: 'badge', reassigned: 'badge badge-warning', reopened: 'badge badge-danger',
  };
  return map[status] || 'badge';
};

const fetchTickets = async (page = 1) => {
  loading.value = true;
  try {
    const params = { page, per_page: 20 };
    if (filters.status) params.status = filters.status;
    if (filters.priority) params.priority = filters.priority;
    if (filters.overdue) params.overdue = 1;
    if (filters.department_id) params.department_id = filters.department_id;
    if (filters.category_tag) params.category_tag = filters.category_tag;
    const response = await api.get('/tickets', { params });
    tickets.value = response.data.data;
    pagination.value = response.data.meta?.pagination;
  } catch { tickets.value = []; }
  finally { loading.value = false; }
};

const openReassign = (ticket) => {
  reassignTarget.value = ticket;
  reassignForm.to_advisor_id = null;
  reassignForm.to_department_id = '';
  reassignForm.reason = '';
  reassignError.value = '';
  reassignSuccess.value = false;
};

const submitReassign = async () => {
  reassigning.value = true;
  reassignError.value = '';
  try {
    await api.post(`/tickets/${reassignTarget.value.id}/reassign`, {
      to_advisor_id: reassignForm.to_advisor_id || null,
      to_department_id: reassignForm.to_department_id || null,
      reason: reassignForm.reason,
    });
    reassignSuccess.value = true;
    fetchTickets();
  } catch (err) {
    reassignError.value = err.response?.data?.error?.message || 'Reassignment failed.';
  } finally {
    reassigning.value = false;
  }
};

const handleFiles = (e) => { selectedFiles.value = Array.from(e.target.files || []); };

const handleCreate = async () => {
  creating.value = true; createError.value = ''; createdTicketNo.value = '';
  try {
    const fd = new FormData();
    fd.append('category_tag', form.category_tag);
    fd.append('priority', form.priority);
    fd.append('message', form.message);
    selectedFiles.value.forEach(f => fd.append('attachments[]', f));
    const response = await api.post('/tickets', fd, { headers: { 'Content-Type': 'multipart/form-data' } });
    createdTicketNo.value = response.data.data.local_ticket_no;
    fetchTickets();
  } catch (err) { createError.value = err.response?.data?.error?.message || 'Failed to submit.'; }
  finally { creating.value = false; }
};

onMounted(() => fetchTickets());
</script>
