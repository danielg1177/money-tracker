<template>
  <div class="pb-32">
    <div class="sticky top-0 bg-gray-900 border-b border-gray-800 px-4 py-3 z-10 flex items-center justify-between">
      <div>
        <h1 class="text-xl font-bold text-white">Users</h1>
        <p class="text-gray-400 text-sm mt-1">Manage accounts and family membership</p>
      </div>
      <button
        @click="showCreateModal = true"
        class="inline-flex items-center gap-2 px-3 py-2 sm:px-4 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-colors p-2"
      >
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
        </svg>
        <span class="hidden sm:inline">Add User</span>
      </button>
    </div>

    <div v-if="loading && !users.length" class="flex items-center justify-center py-12">
      <div class="text-center">
        <svg class="w-8 h-8 animate-spin text-blue-500 mx-auto mb-2" fill="none" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
        </svg>
        <p class="text-gray-400">Loading users…</p>
      </div>
    </div>

    <div v-else-if="listError" class="m-4 p-4 bg-red-900/20 border border-red-700/50 rounded-lg">
      <p class="text-red-400 text-sm">{{ listError }}</p>
    </div>

    <div v-else class="px-4 py-4 space-y-6">
      <section class="space-y-2">
        <h2 class="text-lg font-semibold text-white px-1">All users</h2>
        <div
          v-for="u in users"
          :key="u.id"
          class="bg-gray-800 border border-gray-700 rounded-xl p-3 space-y-3"
        >
          <div v-if="u.editing" class="space-y-3 border-b border-gray-700 pb-3">
            <h4 class="text-white font-semibold text-sm">Edit user</h4>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <label class="block">
                <span class="text-gray-400 text-xs">Name</span>
                <input v-model="u.editForm.name" type="text" class="mt-1 w-full rounded-lg bg-gray-900 border border-gray-600 px-3 py-2 text-white text-sm">
              </label>
              <label class="block">
                <span class="text-gray-400 text-xs">Email</span>
                <input v-model="u.editForm.email" type="email" class="mt-1 w-full rounded-lg bg-gray-900 border border-gray-600 px-3 py-2 text-white text-sm">
              </label>
              <label class="block">
                <span class="text-gray-400 text-xs">Family</span>
                <select v-model.number="u.editForm.family_id" class="mt-1 w-full rounded-lg bg-gray-900 border border-gray-600 px-3 py-2 text-white text-sm">
                  <option :value="null">None</option>
                  <option v-for="f in families" :key="f.id" :value="f.id">{{ f.name }}</option>
                </select>
              </label>
              <label class="block">
                <span class="text-gray-400 text-xs">Role</span>
                <select v-model="u.editForm.role" class="mt-1 w-full rounded-lg bg-gray-900 border border-gray-600 px-3 py-2 text-white text-sm">
                  <option value="member">Family Member</option>
                  <option value="head_of_household">Head of Household</option>
                </select>
              </label>
              <label class="block">
                <span class="text-gray-400 text-xs">New Password (optional)</span>
                <input
                  v-model="u.editForm.password"
                  type="password"
                  autocomplete="new-password"
                  class="mt-1 w-full rounded-lg bg-gray-900 border border-gray-600 px-3 py-2 text-white text-sm"
                  placeholder="Leave blank to keep current password"
                >
              </label>
              <label class="block sm:col-span-2">
                <div class="flex items-center gap-3 mt-2 p-3 bg-gray-700/50 rounded-lg">
                  <input v-model="u.editForm.is_admin" type="checkbox" class="w-4 h-4 rounded border-gray-600 bg-gray-800 text-blue-500 focus:ring-blue-500">
                  <div>
                    <span class="text-gray-300 text-sm font-medium">System Admin</span>
                    <p class="text-gray-500 text-xs">Can manage all users and families</p>
                  </div>
                </div>
              </label>
            </div>
            <div class="flex gap-2">
              <button
                type="button"
                class="px-3 py-1 rounded-lg bg-green-600 hover:bg-green-700 text-white text-sm font-medium disabled:opacity-50"
                :disabled="u.saving"
                @click="saveEdit(u)"
              >
                {{ u.saving ? 'Saving…' : 'Save' }}
              </button>
              <button
                type="button"
                class="px-3 py-1 rounded-lg bg-gray-600 hover:bg-gray-700 text-white text-sm font-medium"
                @click="u.editing = false"
              >
                Cancel
              </button>
            </div>
          </div>

          <div v-else class="flex flex-wrap items-start justify-between gap-3">
            <div class="flex-1">
              <p class="text-white font-medium">{{ u.name }}</p>
              <p class="text-gray-400 text-sm">{{ u.email }}</p>
              <div class="text-right text-sm mt-2">
                <span class="text-gray-500">Family:</span>
                <span class="text-gray-300 ml-1">{{ u.family?.name || '—' }}</span>
                <span
                  class="ml-2 px-2 py-0.5 rounded text-xs font-medium"
                  :class="u.role === 'head_of_household' ? 'bg-blue-900/50 text-blue-300' : 'bg-gray-700 text-gray-300'"
                >
                  {{ u.role === 'head_of_household' ? 'Head of Household' : 'Family Member' }}
                </span>
                <span
                  v-if="u.is_admin"
                  class="ml-1 px-2 py-0.5 rounded text-xs font-medium bg-amber-900/50 text-amber-300"
                >
                  Admin
                </span>
              </div>
            </div>
            <div class="flex gap-2">
              <button
                type="button"
                class="p-2 rounded-lg bg-blue-600 hover:bg-blue-700 text-white"
                title="Edit user"
                @click="startEdit(u)"
              >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                </svg>
              </button>
              <button
                type="button"
                class="p-2 rounded-lg bg-red-600 hover:bg-red-700 text-white"
                title="Delete user"
                @click="u.deleteConfirm = true"
              >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                </svg>
              </button>
            </div>
          </div>

          <div v-if="u.deleteConfirm && !u.editing" class="p-3 bg-red-900/20 border border-red-700/50 rounded-lg">
            <p class="text-red-400 text-sm mb-2">Are you sure you want to delete this user?</p>
            <div class="flex gap-2">
              <button
                type="button"
                class="px-3 py-1 rounded-lg bg-red-600 hover:bg-red-700 text-white text-sm font-medium disabled:opacity-50"
                :disabled="u.saving"
                @click="deleteUser(u)"
              >
                {{ u.saving ? 'Deleting…' : 'Delete' }}
              </button>
              <button
                type="button"
                class="px-3 py-1 rounded-lg bg-gray-600 hover:bg-gray-700 text-white text-sm font-medium"
                @click="u.deleteConfirm = false"
              >
                Cancel
              </button>
            </div>
          </div>
        </div>
      </section>
    </div>

    <!-- Create User Modal -->
    <Transition
      enter-active-class="transition duration-300"
      enter-from-class="translate-y-full"
      enter-to-class="translate-y-0"
      leave-active-class="transition duration-300"
      leave-from-class="translate-y-0"
      leave-to-class="translate-y-full"
    >
      <div v-if="showCreateModal" class="fixed inset-0 z-50">
        <div class="absolute inset-0 bg-black/50" @click="showCreateModal = false" />
        <div class="absolute bottom-0 left-0 right-0 bg-gray-900 rounded-t-2xl max-h-[85vh] overflow-y-auto">
          <div class="sticky top-0 border-b border-gray-800 px-4 py-4 bg-gray-900 flex items-center justify-between">
            <h2 class="text-xl font-bold text-white">Create user</h2>
            <button @click="showCreateModal = false" class="text-gray-400 hover:text-white">
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>
          <div class="p-4 space-y-4">
            <div v-if="formError" class="text-red-400 text-sm p-3 bg-red-900/20 border border-red-700/50 rounded-lg">{{ formError }}</div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <label class="block">
                <span class="text-gray-400 text-xs">Name</span>
                <input v-model="form.name" type="text" class="mt-1 w-full rounded-lg bg-gray-800 border border-gray-700 px-3 py-2 text-white text-sm">
              </label>
              <label class="block">
                <span class="text-gray-400 text-xs">Email</span>
                <input v-model="form.email" type="email" class="mt-1 w-full rounded-lg bg-gray-800 border border-gray-700 px-3 py-2 text-white text-sm">
              </label>
              <label class="block">
                <span class="text-gray-400 text-xs">Password</span>
                <input v-model="form.password" type="password" class="mt-1 w-full rounded-lg bg-gray-800 border border-gray-700 px-3 py-2 text-white text-sm">
              </label>
              <label class="block">
                <span class="text-gray-400 text-xs">Family</span>
                <select v-model.number="form.family_id" class="mt-1 w-full rounded-lg bg-gray-800 border border-gray-700 px-3 py-2 text-white text-sm">
                  <option :value="null">None</option>
                  <option v-for="f in families" :key="f.id" :value="f.id">{{ f.name }}</option>
                </select>
              </label>
              <label class="block sm:col-span-2">
                <span class="text-gray-400 text-xs">Role</span>
                <select v-model="form.role" class="mt-1 w-full rounded-lg bg-gray-800 border border-gray-700 px-3 py-2 text-white text-sm">
                  <option value="member">Family Member</option>
                  <option value="head_of_household">Head of Household</option>
                </select>
              </label>
              <label class="block sm:col-span-2">
                <div class="flex items-center gap-3 mt-4 p-3 bg-gray-700/50 rounded-lg">
                  <input v-model="form.is_admin" type="checkbox" class="w-4 h-4 rounded border-gray-600 bg-gray-800 text-blue-500 focus:ring-blue-500">
                  <div>
                    <span class="text-gray-300 text-sm font-medium">System Admin</span>
                    <p class="text-gray-500 text-xs">Can manage all users and families</p>
                  </div>
                </div>
              </label>
            </div>
            <div class="flex gap-2 pt-4 border-t border-gray-700">
              <button
                @click="showCreateModal = false"
                class="flex-1 py-2 bg-gray-800 text-gray-300 font-medium rounded-lg hover:bg-gray-700"
              >
                Cancel
              </button>
              <button
                @click="submitUser"
                :disabled="saving"
                class="flex-1 py-2 bg-blue-600 text-white font-medium rounded-lg hover:bg-blue-700 disabled:bg-gray-700"
              >
                {{ saving ? 'Saving…' : 'Create user' }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </Transition>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import { useApi } from '../../composables/useApi';

const { get, post, put, delete: del, loading } = useApi();

const users = ref([]);
const families = ref([]);
const listError = ref(null);
const formError = ref(null);
const saving = ref(false);
const showCreateModal = ref(false);

const form = reactive({
  name: '',
  email: '',
  password: '',
  family_id: null,
  role: 'member',
  is_admin: false,
});

onMounted(() => {
  refresh();
});

async function refresh() {
  listError.value = null;
  try {
    const [u, f] = await Promise.all([
      get('/admin/users'),
      get('/admin/families'),
    ]);
    users.value = Array.isArray(u) ? u.map(user => ({
      ...user,
      editing: false,
      deleteConfirm: false,
      saving: false,
      editForm: {
        name: user.name,
        email: user.email,
        family_id: user.family_id,
        role: user.role,
        password: '',
        is_admin: user.is_admin,
      },
    })) : [];
    families.value = Array.isArray(f) ? f : [];
  } catch (err) {
    listError.value = err.response?.data?.message || 'Failed to load users.';
  }
}

async function submitUser() {
  formError.value = null;
  saving.value = true;
  try {
    const payload = {
      name: form.name,
      email: form.email,
      password: form.password,
      role: form.role,
      family_id: form.family_id || null,
      is_admin: form.is_admin,
    };
    const created = await post('/admin/users', payload);
    users.value = [{
      ...created,
      editing: false,
      deleteConfirm: false,
      saving: false,
      editForm: {
        name: created.name,
        email: created.email,
        family_id: created.family_id,
        role: created.role,
        is_admin: created.is_admin,
      },
    }, ...users.value.filter((x) => x.id !== created.id)];
    form.name = '';
    form.email = '';
    form.password = '';
    form.family_id = null;
    form.role = 'member';
    form.is_admin = false;
    showCreateModal.value = false;
  } catch (err) {
    const msg = err.response?.data?.message;
    const errors = err.response?.data?.errors;
    if (errors && typeof errors === 'object') {
      formError.value = Object.values(errors).flat().join(' ');
    } else {
      formError.value = msg || 'Could not create user.';
    }
  } finally {
    saving.value = false;
  }
}

function startEdit(u) {
  u.editForm = {
    name: u.name,
    email: u.email,
    family_id: u.family_id,
    role: u.role,
    password: '',
    is_admin: u.is_admin,
  };
  u.editing = true;
}

async function saveEdit(u) {
  u.saving = true;
  try {
    const updated = await put(`/admin/users/${u.id}`, {
      name: u.editForm.name,
      email: u.editForm.email,
      family_id: u.editForm.family_id || null,
      role: u.editForm.role,
      password: u.editForm.password || null,
      is_admin: u.editForm.is_admin,
    });
    Object.assign(u, updated);
    u.editing = false;
  } catch (err) {
    console.error('Failed to update user:', err);
  } finally {
    u.saving = false;
  }
}

async function deleteUser(u) {
  u.saving = true;
  try {
    await del(`/admin/users/${u.id}`);
    users.value = users.value.filter(user => user.id !== u.id);
  } catch (err) {
    console.error('Failed to delete user:', err);
    u.deleteConfirm = false;
  } finally {
    u.saving = false;
  }
}
</script>
