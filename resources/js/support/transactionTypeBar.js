const VIOLET = 'color-mix(in srgb, var(--color-violet-700) 35%, transparent)';
const YELLOW = 'color-mix(in srgb, var(--color-yellow-500) 55%, transparent)';
const GREEN = 'color-mix(in srgb, var(--color-green-600) 45%, transparent)';
const RED = 'color-mix(in srgb, var(--color-red-600) 45%, transparent)';
const BLUE = 'color-mix(in srgb, var(--color-blue-500) 45%, transparent)';
const DELETE_RED = 'var(--color-red-600)';

function typeColor(type) {
  return type === 'income' ? GREEN : RED;
}

function diagonalSplitBar(type, { familyMemberMiddle = false } = {}) {
  if (familyMemberMiddle) {
    return `linear-gradient(12deg, ${VIOLET} 0% 33.333%, ${YELLOW} 33.333% 66.667%, ${typeColor(type)} 66.667% 100%)`;
  }

  return `linear-gradient(12deg, ${VIOLET} 0% 50%, ${typeColor(type)} 50% 100%)`;
}

/**
 * Inline style for the thick bottom type bar on a transaction card.
 *
 * @param {object|null|undefined} transaction
 * @param {{ confirmDelete?: boolean, otherMemberSplit?: boolean }} [options]
 * @returns {{ background: string }}
 */
export function transactionTypeBarStyle(transaction, options = {}) {
  if (options.confirmDelete) {
    return { background: DELETE_RED };
  }

  const isSplit = Boolean(transaction?.splits?.length) || Boolean(transaction?.is_split);
  if (isSplit) {
    return { background: diagonalSplitBar(transaction?.type, { familyMemberMiddle: Boolean(options.otherMemberSplit) }) };
  }

  return { background: typeColor(transaction?.type) };
}

/**
 * @param {object|null|undefined} row
 * @param {'income'|'expense'|string|null|undefined} categoryType
 * @param {{ otherMemberSplit?: boolean }} [options]
 * @returns {{ background: string }}
 */
export function categoryRowTypeBarStyle(row, categoryType, options = {}) {
  const type = categoryType === 'income' ? 'income' : 'expense';
  if (row?.is_split) {
    return { background: diagonalSplitBar(type, { familyMemberMiddle: Boolean(options.otherMemberSplit) }) };
  }

  return { background: typeColor(type) };
}

export function closeoutFundTypeBarStyle() {
  return { background: BLUE };
}
