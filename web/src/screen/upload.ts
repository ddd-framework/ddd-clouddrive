// Sends the bytes of a reserved upload to the backend, which streams them into the object store (no URL of the store
// ever reaches the browser). One request carries one part: the host's body limits hold for any file size.
import type { DriveUploadReservation } from '../types.ts';

/** The progress of one upload in bytes. */
export type DriveUploadProgress = (sentBytes: number, totalBytes: number) => void;

/** The requests an upload sends (the mutation triggers of `createCloudDriveApi`, or fakes in tests). */
export interface DriveUploadSender {
  /** `PUT …/uploads/{driveBlobId}/content`: the whole content of a single-part upload. */
  putContent: (driveBlobId: number, body: Blob, signal?: AbortSignal) => Promise<unknown>;
  /** `PUT …/uploads/{driveBlobId}/parts/{partNumber}`: one part; sending it again replaces it. */
  putPart: (driveBlobId: number, partNumber: number, body: Blob, signal?: AbortSignal) => Promise<unknown>;
}

/** Attempts per request: a stalled request (the web server cuts a body that stops for a while) is sent again. */
export const DRIVE_UPLOAD_ATTEMPTS = 4;

/** The type every upload body is sent as: the backend streams such a body instead of reading it into memory. */
export const DRIVE_UPLOAD_BODY_TYPE = 'application/octet-stream';

const waitBeforeRetry = (attempt: number, signal?: AbortSignal): Promise<void> =>
  new Promise((resolve, reject) => {
    const timer = setTimeout(resolve, Math.min(8000, 500 * 2 ** attempt));
    signal?.addEventListener('abort', () => {
      clearTimeout(timer);
      reject(signal.reason ?? new DOMException('Aborted', 'AbortError'));
    }, { once: true });
  });

const isAbort = (error: unknown, signal?: AbortSignal): boolean =>
  signal?.aborted === true || (error instanceof Error && error.name === 'AbortError');

/** A refusal of the backend (4xx: wrong length, upload gone) is final; a network error or a 5xx is retried. */
const isRetryable = (error: unknown): boolean => {
  const status = (error as { status?: unknown } | null)?.status;
  return typeof status !== 'number' || status >= 500 || status === 408 || status === 429;
};

const sendWithRetries = async (send: () => Promise<unknown>, signal?: AbortSignal): Promise<void> => {
  for (let attempt = 1; ; attempt += 1) {
    try {
      await send();
      return;
    } catch (error) {
      if (isAbort(error, signal) || !isRetryable(error) || attempt >= DRIVE_UPLOAD_ATTEMPTS) throw error;
      await waitBeforeRetry(attempt, signal);
    }
  }
};

/**
 * Sends a file along a reservation to the backend: the whole content in one request, or the parts of a multipart
 * upload one after the other (one part in flight). A part the store holds already (`uploadedPartNumbers`, a resumed
 * upload) is skipped; a part that fails on the way is sent again (`DRIVE_UPLOAD_ATTEMPTS`). Content the space knows
 * needs nothing. `signal` aborts the running request. Then complete the upload.
 */
export const sendDriveUpload = async (
  reservation: DriveUploadReservation,
  file: Blob,
  sender: DriveUploadSender,
  onProgress?: DriveUploadProgress,
  signal?: AbortSignal
): Promise<void> => {
  if (reservation.isContentKnown) {
    onProgress?.(file.size, file.size);
    return;
  }
  if (!reservation.isMultipart) {
    await sendWithRetries(() => sender.putContent(reservation.driveBlobId, file.slice(0, file.size, DRIVE_UPLOAD_BODY_TYPE), signal), signal);
    onProgress?.(file.size, file.size);
    return;
  }
  const partSize = reservation.partSizeBytes ?? 0;
  if (partSize <= 0) throw new Error('The reservation has no part size');
  const partCount = reservation.partCount || Math.max(1, Math.ceil(file.size / partSize));
  const uploadedPartNumbers = new Set(reservation.uploadedPartNumbers ?? []);
  let sentBytes = 0;
  for (let partNumber = 1; partNumber <= partCount; partNumber += 1) {
    const start = (partNumber - 1) * partSize;
    const end = Math.min(file.size, start + partSize);
    if (!uploadedPartNumbers.has(partNumber)) {
      const part = file.slice(start, end, DRIVE_UPLOAD_BODY_TYPE);
      await sendWithRetries(() => sender.putPart(reservation.driveBlobId, partNumber, part, signal), signal);
    }
    sentBytes += end - start;
    onProgress?.(sentBytes, file.size);
  }
};

/** Human-readable size: `512 B`, `1.2 KB`, `3.4 MB`, `1.25 GB`. */
export const formatDriveSize = (sizeBytes: number | null | undefined): string => {
  if (sizeBytes === null || sizeBytes === undefined) return '';
  if (sizeBytes < 1024) return `${sizeBytes} B`;
  if (sizeBytes < 1024 * 1024) return `${(sizeBytes / 1024).toFixed(1)} KB`;
  if (sizeBytes < 1024 * 1024 * 1024) return `${(sizeBytes / (1024 * 1024)).toFixed(1)} MB`;
  return `${(sizeBytes / (1024 * 1024 * 1024)).toFixed(2)} GB`;
};

/** A backend time `Y-m-d H:i:s` without an offset is UTC; one with an offset is read as it is. */
export const parseDriveTime = (value: string | null | undefined): Date | null => {
  if (!value) return null;
  const isoValue = value.includes('T') ? value : value.replace(' ', 'T');
  const date = new Date(/(Z|[+-]\d{2}:?\d{2})$/.test(isoValue) ? isoValue : `${isoValue}Z`);
  return Number.isNaN(date.getTime()) ? null : date;
};
