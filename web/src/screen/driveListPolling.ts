// How often a file list asks again while files of it are being checked: the list shows "being checked" until the scan
// is done, so it refreshes on its own — often at first, then less often, and not at all after a quarter of an hour
// (a scan that slow waits for the next reload or action).

/** Asks every 4 s during the first minute of a check. */
export const DRIVE_LIST_POLL_FAST_MS = 4_000;
/** Then every 15 s until five minutes. */
export const DRIVE_LIST_POLL_MEDIUM_MS = 15_000;
/** Then every minute until a quarter of an hour. */
export const DRIVE_LIST_POLL_SLOW_MS = 60_000;
/** After that the list stops asking. */
export const DRIVE_LIST_POLL_GIVE_UP_AFTER_MS = 15 * 60_000;

/**
 * The polling interval of a file list (0 = no polling): `checkingSinceMs` is when the list first showed a file being
 * checked (null = none is).
 */
export const driveListPollingIntervalOf = (checkingSinceMs: number | null, nowMs: number): number => {
  if (checkingSinceMs === null) return 0;
  const checkingForMs = Math.max(0, nowMs - checkingSinceMs);
  if (checkingForMs < 60_000) return DRIVE_LIST_POLL_FAST_MS;
  if (checkingForMs < 5 * 60_000) return DRIVE_LIST_POLL_MEDIUM_MS;
  if (checkingForMs < DRIVE_LIST_POLL_GIVE_UP_AFTER_MS) return DRIVE_LIST_POLL_SLOW_MS;
  return 0;
};
