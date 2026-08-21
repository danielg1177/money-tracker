import { ref } from 'vue';
import { currentCalendarMonth, parseYearMonth } from '../support/yearMonth';

const STORAGE_KEY = 'selectedMonth';

function readStoredMonth() {
  try {
    return parseYearMonth(sessionStorage.getItem(STORAGE_KEY));
  } catch {
    return null;
  }
}

function persistMonth(value) {
  try {
    sessionStorage.setItem(STORAGE_KEY, value);
  } catch {
    // Ignore quota / private-mode failures; in-memory state still works.
  }
}

const selectedMonth = ref(readStoredMonth() || currentCalendarMonth());

export function useSelectedMonth() {
  function setSelectedMonth(value) {
    const parsed = parseYearMonth(value);
    if (!parsed) {
      return selectedMonth.value;
    }
    if (selectedMonth.value !== parsed) {
      selectedMonth.value = parsed;
      persistMonth(parsed);
    }
    return parsed;
  }

  return { selectedMonth, setSelectedMonth };
}
