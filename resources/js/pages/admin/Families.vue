<template>
  <div class="pb-32">
    <div class="sticky top-0 bg-gray-900 border-b border-gray-800 px-4 py-3 z-10 flex items-center justify-between">
      <div>
        <h1 class="text-xl font-bold text-white">Families</h1>
        <p class="text-gray-400 text-sm mt-1">Households and their members</p>
      </div>
      <button
        @click="showCreateModal = true"
        class="inline-flex items-center gap-2 px-3 py-2 sm:px-4 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-colors p-2"
      >
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
        </svg>
        <span class="hidden sm:inline">Add Family</span>
      </button>
    </div>

    <div v-if="loading && !families.length" class="flex items-center justify-center py-12">
      <div class="text-center">
        <svg class="w-8 h-8 animate-spin text-blue-500 mx-auto mb-2" fill="none" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
        </svg>
        <p class="text-gray-400">Loading families…</p>
      </div>
    </div>

    <div v-else-if="listError" class="m-4 p-4 bg-red-900/20 border border-red-700/50 rounded-lg">
      <p class="text-red-400 text-sm">{{ listError }}</p>
    </div>

    <div v-else class="px-4 py-4 space-y-6">

      <section class="space-y-3">
        <h2 class="text-lg font-semibold text-white px-1">All families</h2>
        <div
          v-for="fam in families"
          :key="fam.id"
          class="bg-gray-800 border border-gray-700 rounded-xl p-3 space-y-3"
        >
          <div class="flex flex-wrap justify-between gap-2 items-start">
            <div class="flex-1">
              <div v-if="fam.editing" class="space-y-2">
                <label class="block">
                  <span class="text-gray-400 text-xs">Name</span>
                  <input v-model="fam.editForm.name" type="text" class="mt-1 w-full rounded-lg bg-gray-900 border border-gray-600 px-3 py-2 text-white text-sm">
                </label>
                <label class="block">
                  <span class="text-gray-400 text-xs">Description (optional)</span>
                  <textarea v-model="fam.editForm.description" rows="2" class="mt-1 w-full rounded-lg bg-gray-900 border border-gray-600 px-3 py-2 text-white text-sm" />
                </label>
                <div class="flex gap-2">
                  <button
                    type="button"
                    class="px-3 py-1 rounded-lg bg-green-600 hover:bg-green-700 text-white text-sm font-medium disabled:opacity-50"
                    :disabled="fam.saving"
                    @click="saveEdit(fam)"
                  >
                    {{ fam.saving ? 'Saving…' : 'Save' }}
                  </button>
                  <button
                    type="button"
                    class="px-3 py-1 rounded-lg bg-gray-600 hover:bg-gray-700 text-white text-sm font-medium"
                    @click="fam.editing = false"
                  >
                    Cancel
                  </button>
                </div>
              </div>
              <div v-else>
                <h3 class="text-white font-semibold">{{ fam.name }}</h3>
                <p v-if="fam.description" class="text-gray-400 text-sm mt-1">{{ fam.description }}</p>
              </div>
            </div>
            <div v-if="!fam.editing" class="flex gap-2">
              <button
                type="button"
                class="px-3 py-1 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium"
                @click="startEdit(fam)"
              >
                Edit
              </button>
              <button
                type="button"
                class="px-3 py-1 rounded-lg bg-red-600 hover:bg-red-700 text-white text-sm font-medium"
                @click="confirmDeleteFamily(fam)"
              >
                Delete
              </button>
            </div>
          </div>

          <div v-if="fam.deleteConfirm" class="p-3 bg-red-900/20 border border-red-700/50 rounded-lg">
            <p class="text-red-400 text-sm mb-2">Are you sure you want to delete this family?</p>
            <div class="flex gap-2">
              <button
                type="button"
                class="px-3 py-1 rounded-lg bg-red-600 hover:bg-red-700 text-white text-sm font-medium disabled:opacity-50"
                :disabled="fam.saving"
                @click="deleteFamily(fam)"
              >
                {{ fam.saving ? 'Deleting…' : 'Delete' }}
              </button>
              <button
                type="button"
                class="px-3 py-1 rounded-lg bg-gray-600 hover:bg-gray-700 text-white text-sm font-medium"
                @click="fam.deleteConfirm = false"
              >
                Cancel
              </button>
            </div>
          </div>

          <div class="border-t border-gray-700 pt-3 space-y-2">
            <h4 class="text-sm font-semibold text-gray-300">Members ({{ fam.users?.length || 0 }})</h4>
            <div v-if="fam.users?.length" class="space-y-1">
              <div
                v-for="member in fam.users"
                :key="member.id"
                class="flex justify-between items-center bg-gray-900 rounded-lg px-3 py-2 text-sm"
              >
                <span class="text-gray-300">{{ member.name }}</span>
                <button
                  type="button"
                  class="px-2 py-1 rounded bg-red-600 hover:bg-red-700 text-white text-xs font-medium"
                  @click="removeMember(fam, member)"
                >
                  Remove
                </button>
              </div>
            </div>
            <div v-else class="text-gray-400 text-sm">No members</div>

            <div class="pt-2">
              <button
                type="button"
                class="px-3 py-1 rounded-lg bg-green-600 hover:bg-green-700 text-white text-sm font-medium w-full"
                @click="fam.showAddMember = true"
              >
                Add Member
              </button>
              <div v-if="fam.showAddMember" class="mt-2 space-y-2">
                <select
                  v-model="fam.selectedUserId"
                  class="w-full rounded-lg bg-gray-900 border border-gray-600 px-3 py-2 text-white text-sm"
                >
                  <option value="" disabled>Select a user…</option>
                  <option
                    v-for="u in availableUsers(fam)"
                    :key="u.id"
                    :value="u.id"
                  >
                    {{ u.name }} ({{ u.email }})
                  </option>
                </select>
                <button
                  type="button"
                  class="px-3 py-1 rounded-lg bg-green-600 hover:bg-green-700 text-white text-sm font-medium w-full disabled:opacity-50"
                  :disabled="!fam.selectedUserId || fam.saving"
                  @click="addMember(fam)"
                >
                  {{ fam.saving ? 'Adding…' : 'Add' }}
                </button>
                <button
                  type="button"
                  class="px-3 py-1 rounded-lg bg-red-600 hover:bg-red-700 text-white text-sm font-medium w-full"
                  @click="cancelAddMember(fam)"
                >
                  Cancel
                </button>
              </div>
            </div>
          </div>

          <div class="text-xs text-gray-500">
            {{ fam.categories?.length || 0 }} categories
          </div>
        </div>
      </section>
    </div>

    <!-- Create Family Modal -->
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
            <h2 class="text-xl font-bold text-white">Create family</h2>
            <button @click="showCreateModal = false" class="text-gray-400 hover:text-white">
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>
          <div class="p-4 space-y-4">
            <div v-if="formError" class="text-red-400 text-sm p-3 bg-red-900/20 border border-red-700/50 rounded-lg">{{ formError }}</div>
            <label class="block">
              <span class="text-gray-400 text-xs">Name</span>
              <input v-model="form.name" type="text" class="mt-1 w-full rounded-lg bg-gray-800 border border-gray-700 px-3 py-2 text-white text-sm">
            </label>
            <label class="block">
              <span class="text-gray-400 text-xs">Description (optional)</span>
              <textarea v-model="form.description" rows="2" class="mt-1 w-full rounded-lg bg-gray-800 border border-gray-700 px-3 py-2 text-white text-sm" />
            </label>
            <div class="flex gap-2 pt-4 border-t border-gray-700">
              <button
                @click="showCreateModal = false"
                class="flex-1 py-2 bg-gray-800 text-gray-300 font-medium rounded-lg hover:bg-gray-700"
              >
                Cancel
              </button>
              <button
                @click="submitFamily"
                :disabled="saving"
                class="flex-1 py-2 bg-blue-600 text-white font-medium rounded-lg hover:bg-blue-700 disabled:bg-gray-700"
              >
                {{ saving ? 'Saving…' : 'Create family' }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </Transition>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted, computed } from 'vue';
import { useApi } from '../../composables/useApi';

const { get, post, put, delete: del, loading } = useApi();

const families = ref([]);
const users = ref([]);
const listError = ref(null);
const formError = ref(null);
const saving = ref(false);
const showCreateModal = ref(false);

const form = reactive({
  name: '',
  description: '',
});

onMounted(() => {
  refresh();
  loadUsers();
});

async function refresh() {
  listError.value = null;
  try {
    const data = await get('/admin/families');
    families.value = Array.isArray(data) ? data.map(f => ({
      ...f,
      editing: false,
      deleteConfirm: false,
      showAddMember: false,
      selectedUserId: '',
      saving: false,
      editForm: { name: f.name, description: f.description || '' },
    })) : [];
  } catch (err) {
    listError.value = err.response?.data?.message || 'Failed to load families.';
  }
}

async function loadUsers() {
  try {
    const data = await get('/admin/users');
    users.value = Array.isArray(data) ? data : [];
  } catch (err) {
    console.error('Failed to load users:', err);
  }
}

async function submitFamily() {
  formError.value = null;
  saving.value = true;
  try {
    const created = await post('/admin/families', {
      name: form.name,
      description: form.description || null,
    });
    families.value = [{
      ...created,
      editing: false,
      deleteConfirm: false,
      showAddMember: false,
      selectedUserId: '',
      saving: false,
      editForm: { name: created.name, description: created.description || '' },
    }, ...families.value.filter((f) => f.id !== created.id)];
    form.name = '';
    form.description = '';
    showCreateModal.value = false;
  } catch (err) {
    const errors = err.response?.data?.errors;
    formError.value = errors
      ? Object.values(errors).flat().join(' ')
      : err.response?.data?.message || 'Could not create family.';
  } finally {
    saving.value = false;
  }
}

function startEdit(fam) {
  fam.editForm = { name: fam.name, description: fam.description || '' };
  fam.editing = true;
}

async function saveEdit(fam) {
  fam.saving = true;
  try {
    const updated = await put(`/admin/families/${fam.id}`, {
      name: fam.editForm.name,
      description: fam.editForm.description || null,
    });
    Object.assign(fam, updated);
    fam.editing = false;
  } catch (err) {
    console.error('Failed to update family:', err);
  } finally {
    fam.saving = false;
  }
}

function confirmDeleteFamily(fam) {
  fam.deleteConfirm = true;
}

async function deleteFamily(fam) {
  fam.saving = true;
  try {
    await del(`/admin/families/${fam.id}`);
    families.value = families.value.filter(f => f.id !== fam.id);
  } catch (err) {
    console.error('Failed to delete family:', err);
  } finally {
    fam.saving = false;
  }
}

function availableUsers(fam) {
  const currentMemberIds = new Set((fam.users || []).map(u => u.id));
  return users.value.filter(u => !currentMemberIds.has(u.id));
}

async function addMember(fam) {
  if (!fam.selectedUserId) return;
  fam.saving = true;
  try {
    const updated = await post(`/admin/families/${fam.id}/users`, {
      user_id: fam.selectedUserId,
    });
    fam.users = updated.users || [];
    fam.selectedUserId = '';
    fam.showAddMember = false;
  } catch (err) {
    console.error('Failed to add member:', err);
  } finally {
    fam.saving = false;
  }
}

async function removeMember(fam, member) {
  try {
    const updated = await del(`/admin/families/${fam.id}/users/${member.id}`);
    fam.users = updated.users || [];
  } catch (err) {
    console.error('Failed to remove member:', err);
  }
}

function cancelAddMember(fam) {
  fam.showAddMember = false;
  fam.selectedUserId = '';
}
</script>
