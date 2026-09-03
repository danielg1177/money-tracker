export const CLOSEOUT_MODE_CLASSIC = 'classic';
export const CLOSEOUT_MODE_FAMILY_POOLED = 'family_pooled';

export function normalizeCloseoutMode(mode) {
  return mode === CLOSEOUT_MODE_FAMILY_POOLED ? CLOSEOUT_MODE_FAMILY_POOLED : CLOSEOUT_MODE_CLASSIC;
}

export function isFamilyPooledCloseout(mode) {
  return normalizeCloseoutMode(mode) === CLOSEOUT_MODE_FAMILY_POOLED;
}

export function allowsExpenseBasisExclusion(mode) {
  return !isFamilyPooledCloseout(mode);
}
