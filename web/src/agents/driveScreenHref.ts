/** The drive screen's URL keys (`CLOUD_DRIVE_URL_KEYS` and `CLOUD_DRIVE_NODE_KEY` of `./screen`), kept here so the
 * agents entry does not load the screen. */
const SPACE_KEY = 'space';
const PATH_KEY = 'path';
const FILE_KEY = 'file';
const NODE_KEY = 'node';

/** A place in the drive screen; every part is optional, an empty part is left out of the link. */
export interface DriveScreenPlace {
  /** The drive space that holds the place. */
  space?: string | number | null;
  /** The folder the screen opens: a path (`/out/2026`) or a folder node id. */
  path?: string | number | null;
  /** The file the screen opens in its details drawer (node id). */
  file?: string | number | null;
  /** A file or folder by node id: the screen finds its space and folder and replaces it by space + path + file. */
  node?: string | number | null;
}

/**
 * The drive screen at a place, as a link under `route` (the route the host mounted `CloudDriveScreen` at, default
 * `/drive`): `/drive?space=31&path=%2Fout&file=812`. The keys are the screen's URL keys.
 */
export const buildDriveScreenHref = (place: DriveScreenPlace, route = '/drive'): string => {
  const query = new URLSearchParams();
  const parts: [string, DriveScreenPlace[keyof DriveScreenPlace]][] = [
    [SPACE_KEY, place.space],
    [PATH_KEY, place.path],
    [FILE_KEY, place.file],
    [NODE_KEY, place.node],
  ];
  for (const [key, value] of parts) {
    if (value === undefined || value === null || String(value).trim() === '') continue;
    query.set(key, String(value).trim());
  }
  const search = query.toString();
  return search === '' ? route : `${route}?${search}`;
};

/** The node id inside a drive reference (`drive/812` → `812`), or `null` for a reference of another shape. */
export const nodeIdOfDriveReference = (reference: string): string | null => {
  const match = /^[\w-]+\/(\d+)$/.exec(reference.trim());
  return match?.[1] ?? null;
};
