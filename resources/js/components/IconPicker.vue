<template>
  <div>
    <!-- Trigger Button -->
    <button
      type="button"
      @click="showPicker = !showPicker"
      class="inline-flex items-center gap-2 px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg text-gray-300 hover:bg-gray-700 transition-colors"
    >
      <span class="text-lg">{{ modelValue || '🎯' }}</span>
      <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 16 16">
        <path d="M11.354 1.353a.5.5 0 0 0-.708.708L12.293 3.75H2.5A.5.5 0 0 0 2 4.25v10a.5.5 0 0 0 .5.5h10a.5.5 0 0 0 .5-.5v-10a.5.5 0 0 0-1 0v9.5H3V4.75h9.293l-1.647 1.646a.5.5 0 0 0 .708.708l2.5-2.5a.5.5 0 0 0 0-.708l-2.5-2.5z" />
      </svg>
    </button>

    <!-- Picker Overlay -->
    <Transition
      enter-active-class="transition duration-200"
      enter-from-class="opacity-0"
      enter-to-class="opacity-100"
      leave-active-class="transition duration-200"
      leave-from-class="opacity-100"
      leave-to-class="opacity-0"
    >
      <div
        v-if="showPicker"
        class="fixed inset-0 z-50 bg-black/50 flex items-end"
        @click.self="showPicker = false"
      >
        <!-- Picker Content -->
        <Transition
          enter-active-class="transition duration-300"
          enter-from-class="translate-y-full"
          enter-to-class="translate-y-0"
          leave-active-class="transition duration-300"
          leave-from-class="translate-y-0"
          leave-to-class="translate-y-full"
        >
          <div
            v-show="showPicker"
            class="w-full bg-gray-900 border-t border-gray-700 rounded-t-2xl max-h-[70vh] overflow-hidden flex flex-col"
            @click.stop
          >
            <!-- Header -->
            <div class="sticky top-0 bg-gray-900 border-b border-gray-700 px-4 py-4 flex items-center justify-between">
              <h3 class="text-lg font-semibold text-white">Select Icon</h3>
              <button
                type="button"
                @click="showPicker = false"
                class="text-gray-400 hover:text-white transition-colors"
              >
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </div>

            <!-- Icon Grid by Category -->
            <div class="overflow-y-auto flex-1 px-4 py-4 space-y-6">
              <div
                v-for="category in iconCategories"
                :key="category.name"
                class="space-y-3"
              >
                <h4 class="text-sm font-medium text-gray-400">{{ category.name }}</h4>
                <div class="grid grid-cols-6 gap-3">
                  <button
                    v-for="icon in category.icons"
                    :key="icon"
                    type="button"
                    @click="selectIcon(icon)"
                    :class="[
                      'text-2xl p-2 rounded-lg transition-colors',
                      modelValue === icon
                        ? 'bg-blue-600/30 border border-blue-500'
                        : 'bg-gray-800 border border-gray-700 hover:bg-gray-700'
                    ]"
                  >
                    {{ icon }}
                  </button>
                </div>
              </div>
            </div>
          </div>
        </Transition>
      </div>
    </Transition>
  </div>
</template>

<script setup>
import { ref } from 'vue';

defineProps({
  modelValue: {
    type: String,
    default: '',
  },
});

const emit = defineEmits(['update:modelValue']);

const showPicker = ref(false);

const iconCategories = [
  {
    name: 'Money',
    icons: ['💰', '💵', '💳', '💸', '🪙', '💹', '📈', '📉', '🏦'],
  },
  {
    name: 'Food',
    icons: ['🍕', '🍔', '🍜', '🍣', '🍺', '☕', '🍜', '🥗', '🍰'],
  },
  {
    name: 'Transport',
    icons: ['🚗', '🚕', '🚌', '🚂', '✈️', '🚢', '🛵', '⛽', '🛣️'],
  },
  {
    name: 'Home',
    icons: ['🏠', '🏡', '🛒', '🛁', '🪴', '🧹', '🔧', '🛏️', '💡'],
  },
  {
    name: 'Health',
    icons: ['💊', '🏥', '🩺', '🏋️', '🧘', '🦷', '👨‍⚕️', '🧬', '💉'],
  },
  {
    name: 'Entertainment',
    icons: ['🎮', '🎬', '🎵', '🎨', '🎳', '🎲', '🎭', '🎪', '📺'],
  },
  {
    name: 'Shopping',
    icons: ['👕', '👟', '👜', '💄', '📱', '💻', '📚', '👓', '⌚'],
  },
  {
    name: 'Other',
    icons: ['📦', '🎁', '✈️', '🌟', '⭐', '🔑', '🗒️', '❓', '🐶', '👨‍👩‍👧‍👦'],
  },
];

function selectIcon(icon) {
  emit('update:modelValue', icon);
  showPicker.value = false;
}
</script>
