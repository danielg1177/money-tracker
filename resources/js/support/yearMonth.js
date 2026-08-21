export const monthNames = [
  'January',
  'February',
  'March',
  'April',
  'May',
  'June',
  'July',
  'August',
  'September',
  'October',
  'November',
  'December',
];

const YEAR_MONTH_PATTERN = /^\d{4}-(0[1-9]|1[0-2])$/;

export function parseYearMonth(value) {
  const normalized = Array.isArray(value) ? value[0] : value;
  if (typeof normalized !== 'string') {
    return null;
  }
  if (!YEAR_MONTH_PATTERN.test(normalized)) {
    return null;
  }
  return normalized;
}

export function currentCalendarMonth() {
  const today = new Date();
  const year = today.getFullYear();
  const month = String(today.getMonth() + 1).padStart(2, '0');
  return `${year}-${month}`;
}

export function buildQuickSelectMonths() {
  const months = [];
  const cursor = new Date();
  cursor.setDate(1);
  cursor.setMonth(cursor.getMonth() + 2);

  for (let i = 0; i < 26; i += 1) {
    const year = cursor.getFullYear();
    const monthIndex = cursor.getMonth();
    const monthNumber = monthIndex + 1;
    months.push({
      label: `${monthNames[monthIndex]} ${year}`,
      value: `${year}-${String(monthNumber).padStart(2, '0')}`,
    });
    cursor.setMonth(cursor.getMonth() - 1);
  }

  return months;
}
