<template>
  <div class="pb-32">
    <div class="sticky top-0 bg-gray-900 border-b border-gray-800 px-4 py-3 z-10 flex items-center justify-between">
      <div>
        <h1 class="text-xl font-bold text-white">My Family</h1>
        <p class="text-gray-400 text-sm mt-1">Manage your family</p>
      </div>
    </div>

    <div v-if="loading" class="flex items-center justify-center py-12">
      <div class="text-center">
        <svg class="w-8 h-8 animate-spin text-blue-500 mx-auto mb-2" fill="none" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
        </svg>
        <p class="text-gray-400">Loading family…</p>
      </div>
    </div>

    <div v-else-if="error" class="m-4 p-4 bg-red-900/20 border border-red-700/50 rounded-lg">
      <p class="text-red-400 text-sm">{{ error }}</p>
    </div>

    <div v-else-if="family" class="px-4 py-4 space-y-6">
      <!-- Family Info Card -->
      <section class="space-y-3">
        <h2 class="text-lg font-semibold text-white px-1">Family Information</h2>
        <div class="bg-gray-800 border border-gray-700 rounded-xl p-3 space-y-3">
          <div class="flex flex-wrap justify-between gap-2 items-start">
            <div class="flex-1">
              <div v-if="editing" class="space-y-2">
                <label class="block">
                  <span class="text-gray-400 text-xs">Name</span>
                  <input v-model="editForm.name" type="text" class="mt-1 w-full rounded-lg bg-gray-900 border border-gray-600 px-3 py-2 text-white text-sm">
                </label>
                <label class="block">
                  <span class="text-gray-400 text-xs">Description (optional)</span>
                  <textarea v-model="editForm.description" rows="2" class="mt-1 w-full rounded-lg bg-gray-900 border border-gray-600 px-3 py-2 text-white text-sm" />
                </label>
                <div class="flex gap-2">
                  <button
                    type="button"
                    class="px-3 py-1 rounded-lg bg-green-600 hover:bg-green-700 text-white text-sm font-medium disabled:opacity-50"
                    :disabled="saving"
                    @click="saveEdit"
                  >
                    {{ saving ? 'Saving…' : 'Save' }}
                  </button>
                  <button
                    type="button"
                    class="px-3 py-1 rounded-lg bg-gray-600 hover:bg-gray-700 text-white text-sm font-medium"
                    @click="editing = false"
                  >
                    Cancel
                  </button>
                </div>
              </div>
              <div v-else>
                <h3 class="text-white font-semibold">{{ family.name }}</h3>
                <p v-if="family.description" class="text-gray-400 text-sm mt-1">{{ family.description }}</p>
              </div>
            </div>
            <button
              v-if="!editing"
              type="button"
              class="px-3 py-1 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium"
              @click="startEdit"
            >
              Edit
            </button>
          </div>
        </div>
      </section>

      <!-- Members Section -->
      <section class="space-y-3">
        <h2 class="text-lg font-semibold text-white px-1">Members ({{ family.users?.length || 0 }})</h2>
        <div class="bg-gray-800 border border-gray-700 rounded-xl p-3">
          <div v-if="family.users?.length" class="space-y-2">
            <div
              v-for="member in family.users"
              :key="member.id"
              class="flex justify-between items-center bg-gray-900 rounded-lg px-3 py-2 text-sm"
            >
              <div class="flex-1">
                <p class="text-gray-300">{{ member.name }}</p>
                <p class="text-gray-500 text-xs">{{ member.email }}</p>
              </div>
              <button
                type="button"
                class="px-2 py-1 rounded bg-red-600 hover:bg-red-700 text-white text-xs font-medium"
                @click="confirmRemoveMember(member)"
              >
                Remove
              </button>
            </div>

            <!-- Remove Confirmation -->
            <div v-if="memberToRemove" class="mt-3 p-3 bg-red-900/20 border border-red-700/50 rounded-lg">
              <p class="text-red-400 text-sm mb-2">Remove {{ memberToRemove.name }} from the family?</p>
              <div class="flex gap-2">
                <button
                  type="button"
                  class="px-3 py-1 rounded-lg bg-red-600 hover:bg-red-700 text-white text-sm font-medium disabled:opacity-50"
                  :disabled="saving"
                  @click="removeMember"
                >
                  {{ saving ? 'Removing…' : 'Remove' }}
                </button>
                <button
                  type="button"
                  class="px-3 py-1 rounded-lg bg-gray-600 hover:bg-gray-700 text-white text-sm font-medium"
                  @click="memberToRemove = null"
                >
                  Cancel
                </button>
              </div>
            </div>
          </div>
          <div v-else class="text-gray-400 text-sm">No members yet</div>
        </div>
      </section>

      <!-- Note about adding members -->
      <section class="bg-blue-900/20 border border-blue-700/50 rounded-lg p-4">
        <p class="text-blue-400 text-sm">
          <strong>To add new members:</strong> Contact an administrator to add users to your family.
        </p>
      </section>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { useAuth } from '../composables/useAuth';
import { useApi } from '../composables/useApi';

const { user } = useAuth();
const { get, put, delete: del } = useApi();

const family = ref(null);
const loading = ref(true);
const error = ref(null);
const editing = ref(false);
const saving = ref(false);
const memberToRemove = ref(null);

const editForm = ref({
  name: '',
  description: '',
});

onMounted(async () => {
  await loadFamily();
});

async function loadFamily() {
  loading.value = true;
  error.value = null;
  try {
    const data = await get('/my-family');
    family.value = data;
  } catch (err) {
    if (err.response?.status === 404) {
      error.value = 'You are not in a family yet.';
    } else {
      error.value = err.response?.data?.message || 'Failed to load family.';
    }
  } finally {
    loading.value = false;
  }
}

function startEdit() {
  editForm.value = {
    name: family.value.name,
    description: family.value.description || '',
  };
  editing.value = true;
}

async function saveEdit() {
  saving.value = true;
  try {
    const updated = await put(`/admin/families/${family.value.id}`, {
      name: editForm.value.name,
      description: editForm.value.description || null,
    });
    family.value = updated;
    editing.value = false;
  } catch (err) {
    error.value = err.response?.data?.message || 'Failed to update family.';
  } finally {
    saving.value = false;
  }
}

function confirmRemoveMember(member) {
  memberToRemove.value = member;
}

async function removeMember() {
  if (!memberToRemove.value) return;
  saving.value = true;
  try {
    const updated = await del(`/admin/families/${family.value.id}/users/${memberToRemove.value.id}`);
    family.value.users = updated.users || [];
    memberToRemove.value = null;
  } catch (err) {
    error.value = err.response?.data?.message || 'Failed to remove member.';
  } finally {
    saving.value = false;
  }
}
</script>
