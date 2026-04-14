<template>
  <div>
    <div v-if="loading" class="loading"><span class="spinner"></span> Loading...</div>
    <div v-else-if="ticket">
      <!-- Ticket Header -->
      <div class="card">
        <div class="card-header">
          <span><code>{{ ticket.local_ticket_no }}</code> — {{ ticket.category_tag }}</span>
          <span :class="ticket.priority === 'High' ? 'badge badge-danger' : 'badge badge-info'">{{ ticket.priority }}</span>
        </div>
        <div class="card-body">
          <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 0.75rem; font-size: 0.9rem;">
            <div><strong>Status:</strong> <span :class="statusBadge(ticket.status)">{{ ticket.status }}</span></div>
            <div><strong>Applicant:</strong> {{ ticket.applicant?.full_name }}</div>
            <div><strong>Advisor:</strong> {{ ticket.advisor?.full_name || 'Unassigned' }}</div>
            <div><strong>SLA Due:</strong> {{ ticket.first_response_due_at ? new Date(ticket.first_response_due_at).toLocaleString() : '—' }}</div>
            <div v-if="ticket.overdue_flag"><span class="badge badge-danger">OVERDUE</span></div>
            <div><strong>Created:</strong> {{ new Date(ticket.created_at).toLocaleString() }}</div>
          </div>

          <!-- Actions -->
          <div v-if="canManage" style="margin-top: 1rem; display: flex; gap: 0.5rem; flex-wrap: wrap;">
            <button v-if="ticket.status === 'new'" class="btn btn-sm btn-primary" @click="transition('triaged')">Triage</button>
            <button v-if="['triaged', 'reassigned', 'reopened'].includes(ticket.status)" class="btn btn-sm btn-primary" @click="transition('in_progress')">Start Working</button>
            <button v-if="ticket.status === 'in_progress'" class="btn btn-sm btn-secondary" @click="transition('waiting_applicant')">Waiting on Applicant</button>
            <button v-if="ticket.status === 'in_progress'" class="btn btn-sm btn-primary" @click="transition('resolved')">Resolve</button>
            <button v-if="ticket.status === 'resolved'" class="btn btn-sm btn-primary" @click="transition('closed')">Close</button>
            <button v-if="canReassign" class="btn btn-sm btn-danger" @click="showReassign = true">Reassign</button>
          </div>
        </div>
      </div>

      <!-- Transcript -->
      <div class="card">
        <div class="card-header">Conversation</div>
        <div class="card-body" style="max-height: 500px; overflow-y: auto;">
          <div v-for="msg in ticket.messages" :key="msg.id" style="margin-bottom: 1rem; padding: 0.75rem; background: var(--gray-50); border-radius: var(--radius);">
            <div style="display: flex; justify-content: space-between; font-size: 0.8rem; color: var(--gray-500); margin-bottom: 0.25rem;">
              <strong>{{ msg.sender?.full_name || 'User #' + msg.sender_user_id }}</strong>
              <span>{{ new Date(msg.created_at).toLocaleString() }}</span>
            </div>
            <div style="white-space: pre-wrap;">{{ msg.message_text }}</div>
          </div>
        </div>
      </div>

      <!-- Reply -->
      <div v-if="!['closed', 'auto_closed'].includes(ticket.status)" class="card">
        <div class="card-header">Reply</div>
        <div class="card-body">
          <form @submit.prevent="sendReply">
            <div class="form-group">
              <textarea v-model="replyText" class="form-control" rows="3" placeholder="Type your reply..." required></textarea>
            </div>
            <button type="submit" class="btn btn-primary" :disabled="sending">{{ sending ? 'Sending...' : 'Send Reply' }}</button>
          </form>
        </div>
      </div>

      <!-- Attachments -->
      <div v-if="ticket.attachments?.length" class="card">
        <div class="card-header">Attachments</div>
        <div class="card-body">
          <div v-for="att in ticket.attachments" :key="att.id" style="display: flex; justify-content: space-between; padding: 0.5rem 0; border-bottom: 1px solid var(--gray-200);">
            <span>{{ att.original_filename }} ({{ (att.file_size / 1024).toFixed(1) }} KB)</span>
            <span :class="att.upload_status === 'completed' ? 'badge badge-success' : 'badge badge-danger'">{{ att.upload_status }}</span>
          </div>
        </div>
      </div>

      <!-- Routing History -->
      <div v-if="ticket.routing_history?.length" class="card">
        <div class="card-header">Routing History</div>
        <div class="card-body">
          <table>
            <thead><tr><th>Time</th><th>From</th><th>To</th><th>Reason</th></tr></thead>
            <tbody>
              <tr v-for="r in ticket.routing_history" :key="r.id">
                <td style="font-size: 0.8rem;">{{ new Date(r.created_at).toLocaleString() }}</td>
                <td>{{ r.from_advisor || r.from_department || '—' }}</td>
                <td>{{ r.to_advisor || r.to_department || '—' }}</td>
                <td>{{ r.reason }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Reassign Modal -->
    <div v-if="showReassign" style="position: fixed; inset: 0; background: rgba(0,0,0,0.5); display: flex; align-items: center; justify-content: center; z-index: 1000;">
      <div class="card" style="width: 400px;">
        <div class="card-header">Reassign Ticket <button class="btn btn-sm btn-secondary" @click="showReassign = false">Close</button></div>
        <div class="card-body">
          <form @submit.prevent="handleReassign">
            <div class="form-group"><label>New Advisor ID</label><input v-model.number="reassignForm.to_advisor_id" type="number" class="form-control" /></div>
            <div class="form-group"><label>Reason (required)</label><textarea v-model="reassignForm.reason" class="form-control" rows="3" required minlength="5"></textarea></div>
            <button type="submit" class="btn btn-primary btn-sm">Reassign</button>
          </form>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted, onUnmounted, computed } from 'vue';
import { useRoute } from 'vue-router';
import { useAuthStore } from '../store/auth';
import api from '../utils/api';

const route = useRoute();
const auth = useAuthStore();
const ticket = ref(null);
const loading = ref(true);
const replyText = ref('');
const sending = ref(false);
const showReassign = ref(false);
const reassignForm = reactive({ to_advisor_id: null, reason: '' });
let pollTimer = null;

const canManage = computed(() => auth.hasAnyRole(['advisor', 'manager', 'admin']));
const canReassign = computed(() => auth.hasAnyRole(['manager', 'admin']));

const statusBadge = (s) => {
  const map = { new: 'badge badge-info', triaged: 'badge badge-info', in_progress: 'badge badge-warning', resolved: 'badge badge-success', closed: 'badge', overdue: 'badge badge-danger' };
  return map[s] || 'badge badge-info';
};

const fetchTicket = async () => {
  try {
    const response = await api.get(`/tickets/${route.params.id}`);
    ticket.value = response.data.data;
  } catch { ticket.value = null; }
  finally { loading.value = false; }
};

const transition = async (status) => {
  try { await api.post(`/tickets/${route.params.id}/transition`, { status }); fetchTicket(); }
  catch (err) { alert(err.response?.data?.error?.message || 'Failed.'); }
};

const sendReply = async () => {
  sending.value = true;
  try { await api.post(`/tickets/${route.params.id}/reply`, { message: replyText.value }); replyText.value = ''; fetchTicket(); }
  catch (err) { alert(err.response?.data?.error?.message || 'Failed.'); }
  finally { sending.value = false; }
};

const handleReassign = async () => {
  try { await api.post(`/tickets/${route.params.id}/reassign`, reassignForm); showReassign.value = false; fetchTicket(); }
  catch (err) { alert(err.response?.data?.error?.message || 'Failed.'); }
};

const pollForUpdates = async () => {
  try {
    const response = await api.get(`/tickets/${route.params.id}/poll`);
    const data = response.data.data;
    if (data.message_count !== ticket.value?.messages?.length || data.status !== ticket.value?.status) {
      fetchTicket();
    }
  } catch { /* silent */ }
};

onMounted(() => { fetchTicket(); pollTimer = setInterval(pollForUpdates, 10000); });
onUnmounted(() => { if (pollTimer) clearInterval(pollTimer); });
</script>
