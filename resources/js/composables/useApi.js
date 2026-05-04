import { ref } from 'vue';

export function useApi() {
  const loading = ref(false);
  const error = ref(null);

  async function get(url) {
    loading.value = true;
    error.value = null;
    try {
      const response = await window.axios.get(url);
      return response.data;
    } catch (err) {
      error.value = err.response?.data?.message || err.message;
      throw err;
    } finally {
      loading.value = false;
    }
  }

  async function post(url, data) {
    loading.value = true;
    error.value = null;
    try {
      const response = await window.axios.post(url, data);
      return response.data;
    } catch (err) {
      error.value = err.response?.data?.message || err.message;
      throw err;
    } finally {
      loading.value = false;
    }
  }

  async function put(url, data) {
    loading.value = true;
    error.value = null;
    try {
      const response = await window.axios.put(url, data);
      return response.data;
    } catch (err) {
      error.value = err.response?.data?.message || err.message;
      throw err;
    } finally {
      loading.value = false;
    }
  }

  async function del(url) {
    loading.value = true;
    error.value = null;
    try {
      const response = await window.axios.delete(url);
      return response.data;
    } catch (err) {
      error.value = err.response?.data?.message || err.message;
      throw err;
    } finally {
      loading.value = false;
    }
  }

  return { loading, error, get, post, put, del, delete: del };
}
