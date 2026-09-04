<template>
  <div v-if="familyMembers.length > 0" class="space-y-2">
    <div
      class="flex items-center justify-between rounded-lg border border-gray-700 p-3 transition-colors hover:border-gray-600"
      :class="[
        surfaceClass,
        disabled ? 'cursor-not-allowed opacity-60' : 'cursor-pointer',
      ]"
      role="button"
      tabindex="0"
      @click="!disabled && toggleEnabled()"
      @keydown.enter.prevent="!disabled && toggleEnabled()"
      @keydown.space.prevent="!disabled && toggleEnabled()"
    >
      <div>
        <p class="text-sm font-medium text-gray-300">Sent to a family member</p>
        <p class="mt-0.5 text-xs text-gray-500">
          Records matching income for them and keeps both sides out of family totals
        </p>
      </div>
      <div
        class="relative flex h-6 w-10 shrink-0 rounded-full transition-colors"
        :class="enabled ? 'bg-sky-600' : 'bg-gray-700'"
      >
        <div
          class="absolute top-1 h-4 w-4 rounded-full bg-white shadow transition-transform"
          :class="enabled ? 'translate-x-5' : 'translate-x-1'"
        />
      </div>
    </div>
    <div v-if="enabled" class="space-y-1">
      <label class="block text-xs font-medium text-gray-400" :for="selectId">Who received it?</label>
      <select
        :id="selectId"
        :value="userId ?? ''"
        :disabled="disabled"
        class="min-h-[44px] w-full rounded-lg border border-gray-700 bg-gray-800 px-4 py-2 text-white focus:border-sky-500 focus:outline-none disabled:opacity-50"
        @change="onSelect($event)"
      >
        <option value="" disabled>Select a family member</option>
        <option v-for="member in familyMembers" :key="member.id" :value="member.id">
          {{ member.name }}
        </option>
      </select>
    </div>
  </div>
</template>

<script setup>
const props = defineProps({
  enabled: {
    type: Boolean,
    default: false,
  },
  userId: {
    type: [Number, String],
    default: null,
  },
  familyMembers: {
    type: Array,
    default: () => [],
  },
  disabled: {
    type: Boolean,
    default: false,
  },
  surfaceClass: {
    type: String,
    default: 'bg-gray-800',
  },
  selectId: {
    type: String,
    default: 'family-transfer-select',
  },
});

const emit = defineEmits(['update:enabled', 'update:userId']);

function toggleEnabled() {
  const next = !props.enabled;
  emit('update:enabled', next);
  if (!next) {
    emit('update:userId', null);

    return;
  }
  if (props.familyMembers.length === 1) {
    emit('update:userId', props.familyMembers[0].id);
  } else if (!props.familyMembers.some((member) => Number(member.id) === Number(props.userId))) {
    emit('update:userId', null);
  }
}

function onSelect(event) {
  const value = event.target.value;
  emit('update:userId', value === '' ? null : Number(value));
}
</script>
