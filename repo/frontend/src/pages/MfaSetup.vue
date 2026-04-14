<template>
  <div>
    <div class="card">
      <div class="card-header">Two-Factor Authentication Setup</div>
      <div class="card-body">
        <div v-if="auth.user?.totp_enabled" class="alert alert-success">
          MFA is currently <strong>enabled</strong> on your account.
        </div>
        <div v-else class="alert alert-info">
          MFA is not enabled. Set it up to secure your account.
        </div>

        <div v-if="!setupData">
          <button class="btn btn-primary" @click="initSetup" :disabled="loading">
            {{ auth.user?.totp_enabled ? 'Reconfigure MFA' : 'Set Up MFA' }}
          </button>
        </div>

        <div v-else>
          <div style="margin-bottom: 1rem;">
            <p><strong>Step 1:</strong> Add this account to your authenticator app using the URI below:</p>
            <div style="background: var(--gray-100); padding: 0.75rem; border-radius: var(--radius); word-break: break-all; font-family: monospace; font-size: 0.8rem; margin: 0.5rem 0;">
              {{ setupData.otpauth_uri }}
            </div>
          </div>

          <div style="margin-bottom: 1rem;">
            <p><strong>Step 2:</strong> Save these recovery codes in a secure location:</p>
            <div style="background: var(--gray-100); padding: 0.75rem; border-radius: var(--radius); font-family: monospace; font-size: 0.85rem;">
              <div v-for="code in setupData.recovery_codes" :key="code">{{ code }}</div>
            </div>
          </div>

          <div>
            <p><strong>Step 3:</strong> Enter a code from your authenticator to verify:</p>
            <form @submit.prevent="verifySetup" style="display: flex; gap: 0.5rem; margin-top: 0.5rem;">
              <input v-model="verifyCode" class="form-control" maxlength="6" placeholder="000000" style="max-width: 150px" />
              <button class="btn btn-primary" type="submit" :disabled="verifying">{{ verifying ? 'Verifying...' : 'Verify & Enable' }}</button>
            </form>
            <div v-if="verifyError" class="form-error" style="margin-top: 0.5rem;">{{ verifyError }}</div>
            <div v-if="verifySuccess" class="alert alert-success" style="margin-top: 0.5rem;">MFA enabled successfully!</div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import { useAuthStore } from '../store/auth';
import api from '../utils/api';

const auth = useAuthStore();
const setupData = ref(null);
const loading = ref(false);
const verifyCode = ref('');
const verifying = ref(false);
const verifyError = ref('');
const verifySuccess = ref(false);

const initSetup = async () => {
  loading.value = true;
  try {
    const response = await api.post('/mfa/setup');
    setupData.value = response.data.data;
  } catch (err) {
    alert(err.response?.data?.error?.message || 'Failed to initialize MFA setup.');
  } finally {
    loading.value = false;
  }
};

const verifySetup = async () => {
  verifying.value = true;
  verifyError.value = '';
  verifySuccess.value = false;
  try {
    await api.post('/mfa/verify', { code: verifyCode.value });
    verifySuccess.value = true;
    await auth.fetchSession();
  } catch (err) {
    verifyError.value = err.response?.data?.error?.message || 'Invalid code.';
  } finally {
    verifying.value = false;
  }
};
</script>
