// Sends the bytes of a reserved upload straight to the object store (presigned PUTs; PHP never proxies bytes).
import type { DrivePresignedRequest, DriveUploadReservation } from '../types.ts';

/** The progress of one upload in bytes. */
export type DriveUploadProgress = (sentBytes: number, totalBytes: number) => void;

const sendRequest = async (request: DrivePresignedRequest, body: Blob, signal?: AbortSignal): Promise<void> => {
  const response = await fetch(request.url, { method: request.method, headers: request.headers ?? {}, body, signal });
  if (!response.ok) {
    throw new Error(`Upload refused by the store (${response.status})`);
  }
};

/**
 * Sends a file along a reservation: one PUT, or every part of a multipart upload one after the other. Every header of
 * a request goes out unchanged (they are signed into the URL). Content the space knows needs nothing. `signal` aborts
 * the running request (the fetch rejects with an `AbortError`).
 */
export const sendDriveUpload = async (
  reservation: DriveUploadReservation,
  file: Blob,
  onProgress?: DriveUploadProgress,
  signal?: AbortSignal
): Promise<void> => {
  if (reservation.isContentKnown) {
    onProgress?.(file.size, file.size);
    return;
  }
  if (!reservation.isMultipart) {
    if (!reservation.uploadRequest) throw new Error('The reservation has no upload request');
    await sendRequest(reservation.uploadRequest, file, signal);
    onProgress?.(file.size, file.size);
    return;
  }
  const partSize = reservation.partSizeBytes ?? 0;
  let sentBytes = 0;
  for (const part of reservation.partRequests?.elements ?? []) {
    const start = (part.partNumber - 1) * partSize;
    const chunk = file.slice(start, Math.min(file.size, start + partSize));
    await sendRequest(part.request, chunk, signal);
    sentBytes += chunk.size;
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
