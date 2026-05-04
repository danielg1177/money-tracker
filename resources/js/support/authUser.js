/**
 * Normalize user payload from /user or localStorage so the SPA always has isAdmin.
 */
export function normalizeAuthUser(raw) {
  if (!raw || typeof raw !== 'object') {
    return null;
  }

  return {
    ...raw,
    isAdmin: Boolean(raw.isAdmin ?? raw.is_admin ?? raw.role === 'admin'),
  };
}
