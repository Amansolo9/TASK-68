<template>
  <div>
    <!-- Available Slots -->
    <div class="card" v-if="auth.isApplicant || isStaff">
      <div class="card-header">
        Available Appointment Slots
        <button v-if="auth.hasAnyRole(['manager', 'admin'])" class="btn btn-primary btn-sm" @click="showCreateSlot = true">Create Slot</button>
      </div>
      <div class="card-body">
        <div v-if="loadingSlots" class="loading"><span class="spinner"></span> Loading...</div>
        <div v-else class="table-wrapper">
          <table>
            <thead><tr><th>Type</th><th>Date/Time</th><th>Available</th><th>Actions</th></tr></thead>
            <tbody>
              <tr v-for="slot in slots" :key="slot.id">
                <td>{{ slot.slot_type }}</td>
                <td>{{ fmtDate(slot.start_at) }} — {{ fmtTime(slot.end_at) }}</td>
                <td>{{ slot.available_qty }} / {{ slot.capacity }}</td>
                <td>
                  <button v-if="auth.isApplicant && slot.available_qty > 0" class="btn btn-sm btn-primary" @click="bookSlot(slot)">Book</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Applicant: My Appointments -->
    <div class="card" v-if="auth.isApplicant">
      <div class="card-header">My Appointments</div>
      <div class="card-body">
        <div v-if="loadingAppts" class="loading"><span class="spinner"></span> Loading...</div>
        <div v-else class="table-wrapper">
          <table>
            <thead><tr><th>Slot</th><th>Type</th><th>State</th><th>Booked At</th><th>Actions</th></tr></thead>
            <tbody>
              <tr v-for="apt in appointments" :key="apt.id">
                <td>{{ apt.slot ? fmtDate(apt.slot.start_at) : 'N/A' }}</td>
                <td>{{ apt.booking_type }}</td>
                <td><span :class="apptBadge(apt.state)">{{ apt.state }}</span></td>
                <td>{{ apt.booked_at ? fmtDate(apt.booked_at) : '—' }}</td>
                <td>
                  <template v-if="apt.state === 'booked' || apt.state === 'rescheduled'">
                    <button v-if="canReschedule(apt)" class="btn btn-sm btn-secondary" style="margin-right:0.25rem" @click="openReschedule(apt)">Reschedule</button>
                    <span v-else class="badge badge-warning" style="margin-right:0.25rem">Reschedule window closed (24h before start)</span>

                    <button v-if="canCancel(apt)" class="btn btn-sm btn-danger" style="margin-right:0.25rem" @click="cancelAppt(apt, false)">Cancel</button>
                    <span v-else class="badge badge-warning" style="margin-right:0.25rem">Cancel window closed (12h before start)</span>
                  </template>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Staff: Appointment Management -->
    <div class="card" v-if="isStaff">
      <div class="card-header">
        Appointment Management
        <div style="display:flex;gap:0.5rem;align-items:center;">
          <select v-model="staffStateFilter" class="form-control" style="max-width:160px;" @change="fetchStaffAppointments">
            <option value="">All states</option>
            <option value="booked">Booked</option>
            <option value="rescheduled">Rescheduled</option>
            <option value="cancelled">Cancelled</option>
            <option value="completed">Completed</option>
            <option value="no_show">No-Show</option>
          </select>
        </div>
      </div>
      <div class="card-body">
        <div v-if="loadingStaffAppts" class="loading"><span class="spinner"></span> Loading...</div>
        <div v-else class="table-wrapper">
          <table>
            <thead><tr><th>Applicant</th><th>Slot</th><th>Type</th><th>State</th><th>Booked At</th><th>Actions</th></tr></thead>
            <tbody>
              <tr v-for="apt in staffAppointments" :key="apt.id">
                <td>{{ apt.applicant?.full_name || `#${apt.applicant_id}` }}</td>
                <td>{{ apt.slot ? fmtDate(apt.slot.start_at) : 'N/A' }}</td>
                <td>{{ apt.booking_type }}</td>
                <td><span :class="apptBadge(apt.state)">{{ apt.state }}</span></td>
                <td>{{ apt.booked_at ? fmtDate(apt.booked_at) : '—' }}</td>
                <td>
                  <template v-if="apt.state === 'booked' || apt.state === 'rescheduled'">
                    <button class="btn btn-sm btn-secondary" style="margin-right:0.25rem" @click="openReschedule(apt)">Reschedule</button>

                    <button v-if="canCancel(apt)" class="btn btn-sm btn-danger" style="margin-right:0.25rem" @click="cancelAppt(apt, false)">Cancel</button>
                    <button v-else-if="auth.hasAnyRole(['manager','admin'])" class="btn btn-sm btn-danger" style="margin-right:0.25rem" @click="cancelAppt(apt, true)">Override Cancel</button>

                    <button class="btn btn-sm btn-primary" style="margin-right:0.25rem" @click="completeAppt(apt)">Complete</button>
                    <button v-if="canMarkNoShow(apt)" class="btn btn-sm btn-danger" @click="markNoShow(apt)">No-Show</button>
                    <span v-else style="font-size:0.8rem;color:var(--gray-500);">No-show: available 10 min after slot start</span>
                  </template>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Reschedule Modal -->
    <div v-if="rescheduleTarget" style="position:fixed;inset:0;background:rgba(0,0,0,.5);display:flex;align-items:center;justify-content:center;z-index:1000">
      <div class="card" style="width:500px">
        <div class="card-header">Reschedule Appointment <button class="btn btn-sm btn-secondary" @click="rescheduleTarget=null">Close</button></div>
        <div class="card-body">
          <div v-if="rescheduleError" class="alert alert-error">{{ rescheduleError }}</div>
          <p style="margin-bottom:0.75rem;font-size:0.85rem;color:var(--gray-500)">
            Policy: reschedule allowed up to <strong>24 hours</strong> before slot start.
          </p>
          <div class="form-group">
            <label>New Slot</label>
            <select v-model="rescheduleSlotId" class="form-control">
              <option value="">Select a new slot…</option>
              <option v-for="s in slots" :key="s.id" :value="s.id" :disabled="s.available_qty<1">
                {{ s.slot_type }} — {{ fmtDate(s.start_at) }} ({{ s.available_qty }} left)
              </option>
            </select>
          </div>
          <div class="form-group">
            <label>Reason</label>
            <textarea v-model="rescheduleReason" class="form-control" rows="2" required></textarea>
          </div>
          <button class="btn btn-primary" :disabled="!rescheduleSlotId||!rescheduleReason||rescheduling" @click="submitReschedule">
            {{ rescheduling ? 'Rescheduling…' : 'Confirm Reschedule' }}
          </button>
        </div>
      </div>
    </div>

    <!-- Create Slot Modal -->
    <div v-if="showCreateSlot" style="position:fixed;inset:0;background:rgba(0,0,0,.5);display:flex;align-items:center;justify-content:center;z-index:1000">
      <div class="card" style="width:500px">
        <div class="card-header">Create Slot <button class="btn btn-sm btn-secondary" @click="showCreateSlot=false">Close</button></div>
        <div class="card-body">
          <div v-if="slotError" class="alert alert-error">{{ slotError }}</div>
          <form @submit.prevent="createSlot">
            <div class="form-group"><label>Type</label><select v-model="slotForm.slot_type" class="form-control" required>
              <option value="IN_PERSON">In Person</option><option value="PHONE">Phone</option><option value="VIDEO">Video</option>
            </select></div>
            <div class="form-group"><label>Start</label><input v-model="slotForm.start_at" type="datetime-local" class="form-control" required /></div>
            <div class="form-group"><label>End</label><input v-model="slotForm.end_at" type="datetime-local" class="form-control" required /></div>
            <div class="form-group"><label>Capacity</label><input v-model.number="slotForm.capacity" type="number" class="form-control" required min="1" /></div>
            <button type="submit" class="btn btn-primary" :disabled="creatingSlot">{{ creatingSlot ? 'Creating…' : 'Create Slot' }}</button>
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
const slots = ref([]);
const appointments = ref([]);
const staffAppointments = ref([]);
const loadingSlots = ref(false);
const loadingAppts = ref(false);
const loadingStaffAppts = ref(false);
const showCreateSlot = ref(false);
const creatingSlot = ref(false);
const slotError = ref('');
const slotForm = reactive({ slot_type: 'IN_PERSON', start_at: '', end_at: '', capacity: 1 });
const staffStateFilter = ref('');

const isStaff = computed(() => auth.hasAnyRole(['advisor', 'manager', 'admin']));

// Reschedule state
const rescheduleTarget = ref(null);
const rescheduleSlotId = ref('');
const rescheduleReason = ref('');
const rescheduleError = ref('');
const rescheduling = ref(false);

const fmtDate = (d) => d ? new Date(d).toLocaleString() : '';
const fmtTime = (d) => d ? new Date(d).toLocaleTimeString() : '';
const apptBadge = (s) => ({ pending:'badge badge-info', booked:'badge badge-success', rescheduled:'badge badge-warning', cancelled:'badge', completed:'badge badge-success', no_show:'badge badge-danger', expired:'badge' }[s] || 'badge');

// Policy helpers — pre-check windows client-side for immediate feedback
const hoursUntil = (dt) => (new Date(dt).getTime() - Date.now()) / 3600000;
const canReschedule = (apt) => {
  if (auth.hasAnyRole(['manager', 'admin'])) return true;
  return apt.slot && hoursUntil(apt.slot.start_at) >= 24;
};
const canCancel = (apt) => apt.slot && hoursUntil(apt.slot.start_at) >= 12;
const canMarkNoShow = (apt) => auth.hasAnyRole(['advisor','manager','admin']) && apt.slot && hoursUntil(apt.slot.start_at) <= -(10/60);

const fetchSlots = async () => {
  loadingSlots.value = true;
  try { slots.value = (await api.get('/appointments/slots')).data.data; } catch { slots.value = []; }
  finally { loadingSlots.value = false; }
};
const fetchAppointments = async () => {
  if (!auth.isApplicant) return;
  loadingAppts.value = true;
  try { appointments.value = (await api.get('/appointments/my')).data.data; } catch { appointments.value = []; }
  finally { loadingAppts.value = false; }
};
const fetchStaffAppointments = async () => {
  if (!isStaff.value) return;
  loadingStaffAppts.value = true;
  try {
    const params = {};
    if (staffStateFilter.value) params.state = staffStateFilter.value;
    staffAppointments.value = (await api.get('/appointments', { params })).data.data;
  } catch { staffAppointments.value = []; }
  finally { loadingStaffAppts.value = false; }
};

const createSlot = async () => {
  creatingSlot.value = true; slotError.value = '';
  try { await api.post('/appointments/slots', slotForm); showCreateSlot.value = false; fetchSlots(); }
  catch (e) { slotError.value = e.response?.data?.error?.message || 'Failed.'; }
  finally { creatingSlot.value = false; }
};

const bookSlot = async (slot) => {
  const rk = crypto.randomUUID?.() || Math.random().toString(36).substring(2);
  try { await api.post('/appointments/book', { slot_id: slot.id, request_key: rk }); fetchSlots(); fetchAppointments(); alert('Appointment booked!'); }
  catch (e) { alert(e.response?.data?.error?.message || 'Booking failed.'); }
};

const openReschedule = (apt) => { rescheduleTarget.value = apt; rescheduleSlotId.value = ''; rescheduleReason.value = ''; rescheduleError.value = ''; };
const submitReschedule = async () => {
  rescheduling.value = true; rescheduleError.value = '';
  const rk = crypto.randomUUID?.() || Math.random().toString(36).substring(2);
  try {
    await api.post(`/appointments/${rescheduleTarget.value.id}/reschedule`, { new_slot_id: parseInt(rescheduleSlotId.value), request_key: rk, reason: rescheduleReason.value });
    rescheduleTarget.value = null; fetchSlots(); fetchAppointments(); fetchStaffAppointments();
  } catch (e) { rescheduleError.value = e.response?.data?.error?.message || 'Reschedule failed.'; }
  finally { rescheduling.value = false; }
};

const cancelAppt = async (apt, override = false) => {
  const reason = prompt(override ? 'Override cancellation reason (manager/admin):' : 'Cancellation reason:');
  if (!reason) return;
  try {
    await api.post(`/appointments/${apt.id}/cancel`, { reason, override });
    fetchAppointments();
    fetchStaffAppointments();
  } catch (e) { alert(e.response?.data?.error?.message || 'Cancel failed.'); }
};

const completeAppt = async (apt) => {
  try { await api.post(`/appointments/${apt.id}/complete`); fetchAppointments(); fetchStaffAppointments(); }
  catch (e) { alert(e.response?.data?.error?.message || 'Failed.'); }
};

const markNoShow = async (apt) => {
  if (!confirm('Mark this appointment as no-show? The slot will be consumed.')) return;
  try { await api.post(`/appointments/${apt.id}/no-show`); fetchAppointments(); fetchStaffAppointments(); }
  catch (e) { alert(e.response?.data?.error?.message || 'Failed.'); }
};

onMounted(() => {
  fetchSlots();
  fetchAppointments();
  fetchStaffAppointments();
});
</script>
