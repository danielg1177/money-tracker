<template>
  <div class="pb-32">
    <div class="sticky top-0 bg-gray-900 border-b border-gray-800 px-4 py-3 z-10 flex items-center justify-between">
      <div>
        <h1 class="text-xl font-bold text-white">Categories</h1>
        <p class="text-gray-400 text-sm mt-1">Income and expense categories by family</p>
      </div>
    </div>

    <div v-if="loading && !families.length" class="flex items-center justify-center py-12">
      <div class="text-center">
        <svg class="w-8 h-8 animate-spin text-blue-500 mx-auto mb-2" fill="none" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
        </svg>
        <p class="text-gray-400">Loading…</p>
      </div>
    </div>

    <div v-else-if="listError" class="m-4 p-4 bg-red-900/20 border border-red-700/50 rounded-lg">
      <p class="text-red-400 text-sm">{{ listError }}</p>
    </div>

    <div v-else class="px-4 py-4 space-y-8">
      <section
        v-for="fam in families"
        :key="fam.id"
        class="bg-gray-800 border border-gray-700 rounded-xl overflow-hidden"
      >
        <div class="px-4 py-3 border-b border-gray-700 flex flex-wrap justify-between gap-2">
          <h2 class="text-lg font-semibold text-white">{{ fam.name }}</h2>
          <span class="text-gray-400 text-sm">{{ fam.categories?.length || 0 }} categories</span>
        </div>

        <div class="p-4 space-y-2">
          <div
            v-for="cat in fam.categories || []"
            :key="cat.id"
            class="flex items-center justify-between gap-2 py-2 border-b border-gray-700/80 last:border-0"
          >
            <div class="flex items-center gap-2 min-w-0">
              <span v-if="cat.icon" class="text-lg shrink-0">{{ cat.icon }}</span>
              <span class="text-gray-200 truncate">{{ cat.name }}</span>
            </div>
            <div class="text-xs text-gray-500 shrink-0">
              <span v-if="cat.is_income">income</span>
              <span v-if="cat.is_income && cat.is_expense"> · </span>
              <span v-if="cat.is_expense">expense</span>
            </div>
          </div>
          <p v-if="!(fam.categories || []).length" class="text-gray-500 text-sm">No categories yet.</p>
        </div>

        <div class="px-4 py-3 bg-gray-900/50 border-t border-gray-700 space-y-3">
          <h3 class="text-sm font-medium text-gray-300">Add category</h3>
          <div v-if="formErrors[fam.id]" class="text-red-400 text-sm">{{ formErrors[fam.id] }}</div>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <label class="block sm:col-span-2">
              <span class="text-gray-400 text-xs">Name</span>
              <input
                v-model="newCategory[fam.id].name"
                type="text"
                class="mt-1 w-full rounded-lg bg-gray-900 border border-gray-600 px-3 py-2 text-white text-sm"
                placeholder="e.g. Groceries"
              >
            </label>
            <label class="block">
              <span class="text-gray-400 text-xs">Icon (optional)</span>
              <input
                v-model="newCategory[fam.id].icon"
                type="text"
                class="mt-1 w-full rounded-lg bg-gray-900 border border-gray-600 px-3 py-2 text-white text-sm"
                placeholder="emoji"
              >
            </label>
            <div class="flex items-end gap-4 pb-1">
              <label class="flex items-center gap-2 text-sm text-gray-300">
                <input v-model="newCategory[fam.id].is_income" type="checkbox" class="rounded border-gray-600">
                Income
              </label>
              <label class="flex items-center gap-2 text-sm text-gray-300">
                <input v-model="newCategory[fam.id].is_expense" type="checkbox" class="rounded border-gray-600">
                Expense
              </label>
            </div>
          </div>
          <button
            type="button"
            class="px-4 py-2 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium disabled:opacity-50"
            :disabled="savingId === fam.id"
            @click="submitCategory(fam.id)"
          >
            {{ savingId === fam.id ? 'Saving…' : 'Add category' }}
          </button>
        </div>
      </section>

      <p v-if="!families.length" class="text-gray-500 text-sm text-center py-8">No families yet. Create a family first.</p>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import { useApi } from '../../composables/useApi';

const { get, post, loading } = useApi();

const families = ref([]);
const listError = ref(null);
const formErrors = reactive({});
const savingId = ref(null);
const newCategory = reactive({});

function ensureForm(familyId) {
  if (!newCategory[familyId]) {
    newCategory[familyId] = {
      name: '',
      icon: '',
      is_income: true,
      is_expense: true,
    };
  }
}

onMounted(() => {
  refresh();
});

async function refresh() {
  listError.value = null;
  try {
    const data = await get('/admin/families');
    families.value = Array.isArray(data) ? data : [];
    for (const fam of families.value) {
      ensureForm(fam.id);
    }
  } catch (err) {
    listError.value = err.response?.data?.message || 'Failed to load data.';
  }
}

async function submitCategory(familyId) {
  ensureForm(familyId);
  formErrors[familyId] = null;
  const f = newCategory[familyId];
  if (!f.name?.trim()) {
    formErrors[familyId] = 'Name is required.';
    return;
  }
  savingId.value = familyId;
  try {
    const created = await post('/admin/categories', {
      family_id: familyId,
      name: f.name.trim(),
      icon: f.icon?.trim() || null,
      is_income: !!f.is_income,
      is_expense: !!f.is_expense,
    });
    const fam = families.value.find((x) => x.id === familyId);
    if (fam) {
      if (!fam.categories) {
        fam.categories = [];
      }
      fam.categories.push(created);
    }
    f.name = '';
    f.icon = '';
    f.is_income = true;
    f.is_expense = true;
  } catch (err) {
    const errors = err.response?.data?.errors;
    formErrors[familyId] = errors
      ? Object.values(errors).flat().join(' ')
      : err.response?.data?.message || 'Could not create category.';
  } finally {
    savingId.value = null;
  }
}
</script>
