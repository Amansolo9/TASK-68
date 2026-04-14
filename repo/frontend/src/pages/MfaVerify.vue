<template>
  <div class="login-container">
    <div class="login-card">
      <h1>Two-Factor Authentication</h1>
      <p>Enter the 6-digit code from your authenticator app</p>

      <div v-if="error" class="alert alert-error">{{ error }}</div>

      <form v-if="!showRecovery" @submit.prevent="handleVerify">
        <div class="form-group">
          <label for="code">Authentication Code</label>
          <input
            id="code"
            v-model="code"
            type="text"
            class="form-control"
            maxlength="6"
            pattern="[0-9]{6}"
            placeholder="000000"
            required
            autofocus
          />
        </div>

        <button type="submit" class="btn btn-primary" style="width: 100%; margin-bottom: 0.5rem" :disabled="loading">
          {{ loading ? 'Verifying...' : 'Verify' }}
        </button>

        <button type="button" class="btn btn-secondary" style="width: 100%" @click="showRecovery = true">
          Use Recovery Code
        </button>
      </form>

      <form v-else @submit.prevent="handleRecovery">
        <div class="form-group">
          <label for="recovery">Recovery Code</label>
          <input
            id="recovery"
            v-model="recoveryCode"
            type="text"
            class="form-control"
            placeholder="XXXX-XXXX-XXXX"
            required
          />
        </div>

        <button type="submit" class="btn btn-primary" style="width: 100%; margin-bottom: 0.5rem" :disabled="loading">
          {{ loading ? 'Verifying...' : 'Use Recovery Code' }}
        </button>

        <button type="button" class="btn btn-secondary" style="width: 100%" @click="showRecovery = false">
          Back to Code Entry
        </button>
      </form>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '../store/auth';

const router = useRouter();
const auth = useAuthStore();

const code = ref('');
const recoveryCode = ref('');
const error = ref('');
const loading = ref(false);
const showRecovery = ref(false);

const handleVerify = async () => {
  error.value = '';
  loading.value = true;
  try {
    await auth.verifyMfa(code.value);
    router.push('/');
  } catch (err) {
    error.value = err.response?.data?.error?.message || 'Invalid code. Please try again.';
  } finally {
    loading.value = false;
  }
};

const handleRecovery = async () => {
  error.value = '';
  loading.value = true;
  try {
    await auth.useRecoveryCode(recoveryCode.value);
    router.push('/');
  } catch (err) {
    error.value = err.response?.data?.error?.message || 'Invalid recovery code.';
  } finally {
    loading.value = false;
  }
};
</script>
