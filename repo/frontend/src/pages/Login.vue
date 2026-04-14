<template>
  <div class="login-container">
    <div class="login-card">
      <h1>Admissions System</h1>
      <p>Sign in to your account</p>

      <div v-if="error" class="alert alert-error">{{ error }}</div>

      <form @submit.prevent="handleLogin">
        <div class="form-group">
          <label for="username">Username</label>
          <input
            id="username"
            v-model="form.username"
            type="text"
            class="form-control"
            :class="{ error: errors.username }"
            required
            autocomplete="username"
          />
          <div v-if="errors.username" class="form-error">{{ errors.username }}</div>
        </div>

        <div class="form-group">
          <label for="password">Password</label>
          <input
            id="password"
            v-model="form.password"
            type="password"
            class="form-control"
            :class="{ error: errors.password }"
            required
            autocomplete="current-password"
          />
          <div v-if="errors.password" class="form-error">{{ errors.password }}</div>
        </div>

        <div v-if="captchaRequired" class="form-group">
          <label>Security Check: {{ captchaQuestion }}</label>
          <input
            v-model="form.captchaAnswer"
            type="text"
            class="form-control"
            placeholder="Enter your answer"
            required
          />
        </div>

        <button type="submit" class="btn btn-primary" style="width: 100%" :disabled="loading">
          <span v-if="loading" class="spinner"></span>
          {{ loading ? 'Signing in...' : 'Sign In' }}
        </button>
      </form>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '../store/auth';
import api from '../utils/api';

const router = useRouter();
const auth = useAuthStore();

const form = reactive({
  username: '',
  password: '',
  captchaAnswer: '',
});

const errors = reactive({
  username: '',
  password: '',
});

const error = ref('');
const loading = ref(false);
const captchaRequired = ref(false);
const captchaKey = ref('');
const captchaQuestion = ref('');

const fetchCaptcha = async () => {
  try {
    const response = await api.post('/auth/captcha');
    captchaKey.value = response.data.data.challenge_key;
    captchaQuestion.value = response.data.data.question;
    captchaRequired.value = true;
  } catch {
    error.value = 'Failed to load security challenge.';
  }
};

const handleLogin = async () => {
  error.value = '';
  errors.username = '';
  errors.password = '';
  loading.value = true;

  if (!form.username) {
    errors.username = 'Username is required.';
    loading.value = false;
    return;
  }
  if (!form.password) {
    errors.password = 'Password is required.';
    loading.value = false;
    return;
  }

  try {
    const result = await auth.login(
      form.username,
      form.password,
      captchaRequired.value ? captchaKey.value : null,
      captchaRequired.value ? form.captchaAnswer : null
    );

    if (result.mfa_required) {
      router.push('/mfa');
    } else {
      router.push('/');
    }
  } catch (err) {
    const resp = err.response?.data;
    if (resp?.error?.code === 'CAPTCHA_REQUIRED' || resp?.error?.details?.captcha_required) {
      await fetchCaptcha();
      error.value = resp?.error?.message || 'CAPTCHA required.';
    } else if (resp?.error?.code === 'VALIDATION_ERROR') {
      const details = resp.error.details || {};
      errors.username = details.username?.[0] || '';
      errors.password = details.password?.[0] || '';
    } else {
      error.value = resp?.error?.message || 'Login failed. Please try again.';
    }
  } finally {
    loading.value = false;
  }
};
</script>
