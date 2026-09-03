import { normalizeCloseoutMode } from './closeoutMode.js';

/**
 * Normalize user payload from /user or localStorage so the SPA always has isAdmin
 * and canManageFamily regardless of snake_case vs camelCase JSON.
 */
export function normalizeAuthUser(raw) {
  if (!raw || typeof raw !== 'object') {
    return null;
  }

  const isAdmin = Boolean(raw.isAdmin ?? raw.is_admin ?? raw.role === 'admin');
  const canManageFamily = Boolean(raw.canManageFamily)
    || Boolean(raw.can_manage_family)
    || isAdmin
    || raw.role === 'head_of_household'
    || Boolean(raw.is_head_of_household)
    || Boolean(raw.isHeadOfHousehold);
  const closeoutMode = normalizeCloseoutMode(raw.closeout_mode || raw.closeoutMode);

  return {
    ...raw,
    isAdmin,
    is_admin: isAdmin,
    canManageFamily,
    can_manage_family: canManageFamily,
    closeout_mode: closeoutMode,
    closeoutMode,
  };
}
