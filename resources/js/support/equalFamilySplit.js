/**
 * Equal percentage shares that sum to 100 (within float precision), rounded to cents.
 *
 * @param {number} count
 * @returns {number[]}
 */
export function equalSharePercentages(count) {
  if (count <= 0) {
    return [];
  }
  if (count === 1) {
    return [100];
  }

  const base = Math.floor(10000 / count) / 100;
  let sum = 0;
  const parts = [];
  for (let i = 0; i < count - 1; i++) {
    parts.push(base);
    sum += base;
  }
  parts.push(Math.round((100 - sum) * 100) / 100);

  return parts;
}

/**
 * @param {{ id: number }[]} familyUsers
 * @returns {{ user_id: number, share_percentage: number }[]}
 */
export function equalSplitPayloadForFamilyUsers(familyUsers) {
  if (!familyUsers?.length) {
    return [];
  }

  const percents = equalSharePercentages(familyUsers.length);

  return familyUsers.map((u, i) => ({
    user_id: u.id,
    share_percentage: percents[i],
  }));
}

/**
 * True when splits have no positive percentages (fresh toggle or uninitialized).
 *
 * @param {Array<{ share_percentage?: number, percentage?: number }>|undefined|null} splits
 */
export function hasPositiveSplitShares(splits) {
  return Boolean(
    splits?.some((s) => Number(s.share_percentage ?? s.percentage ?? 0) > 0)
  );
}
