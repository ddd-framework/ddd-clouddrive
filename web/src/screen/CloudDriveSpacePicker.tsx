'use client';

import { Check, ChevronsUpDown, HardDrive } from 'lucide-react';
import { useMemo, useState } from 'react';

import { type FileAreaKey, type FileAreaRefView, formatBytes } from '@mgamadeus/ddd-admin-ui/files';
import { useTranslate } from '@mgamadeus/ddd-admin-ui/i18n';
import { Button } from '@mgamadeus/ddd-admin-ui/primitives';
import { cn } from '@mgamadeus/ddd-admin-ui/primitives';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@mgamadeus/ddd-admin-ui/primitives';
import { Popover, PopoverContent, PopoverTrigger } from '@mgamadeus/ddd-admin-ui/primitives';
import { groupDriveSpaces } from './driveFileMapping.ts';
import type { DriveSpaceListItem } from '../types.ts';

export interface CloudDriveSpacePickerProps {
  /** The spaces (`driveAreaRefOf` of the space list): name and group. */
  areas: readonly FileAreaRefView[];
  areaKey: FileAreaKey;
  onSelectArea: (areaKey: FileAreaKey) => void;
  /** The space list items: each entry then shows its file count and used size on a second line. */
  spaces?: readonly DriveSpaceListItem[];
  className?: string;
}

/** The space picker of the drive screen: one button, a searchable list grouped by the space's group. */
export const CloudDriveSpacePicker = ({ areas, areaKey, onSelectArea, spaces, className }: CloudDriveSpacePickerProps) => {
  const { t } = useTranslate();
  const [open, setOpen] = useState(false);
  const groups = useMemo(() => groupDriveSpaces(areas), [areas]);
  const current = areas.find(area => String(area.key) === String(areaKey));
  const detailOf = (key: FileAreaKey): string | null => {
    const space = spaces?.find(item => String(item.id) === String(key));
    if (!space) return null;
    return `${t('{count, plural, one {# file} other {# files}}', { count: space.quota.fileCount })} · ${formatBytes(space.quota.usedBytes)}`;
  };

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button variant="outline" role="combobox" aria-expanded={open} className={cn('w-full max-w-sm justify-between gap-2', className)}>
          <span className="flex min-w-0 items-center gap-2">
            <HardDrive className="size-4 shrink-0 text-muted-foreground" aria-hidden />
            <span className="truncate">{current?.name ?? t('Choose a space')}</span>
            {current?.groupLabel && <span className="truncate text-xs text-muted-foreground">· {current.groupLabel}</span>}
          </span>
          <ChevronsUpDown className="size-4 shrink-0 opacity-50" aria-hidden />
        </Button>
      </PopoverTrigger>
      <PopoverContent className="w-(--radix-popover-trigger-width) min-w-72 p-0" align="start">
        <Command>
          <CommandInput placeholder={t('Search spaces…')} />
          <CommandList>
            <CommandEmpty>{t('No space matches.')}</CommandEmpty>
            {groups.map(group => (
              <CommandGroup key={group.label || '__ungrouped__'} heading={group.label || undefined}>
                {group.areas.map(area => (
                  <CommandItem
                    key={String(area.key)}
                    value={`${area.name} ${area.groupLabel ?? ''} ${String(area.key)}`}
                    onSelect={() => {
                      onSelectArea(area.key);
                      setOpen(false);
                    }}
                  >
                    <Check className={cn('size-4 shrink-0', String(area.key) === String(areaKey) ? 'opacity-100' : 'opacity-0')} aria-hidden />
                    <span className="flex min-w-0 flex-col">
                      <span className="truncate">{area.name}</span>
                      {detailOf(area.key) && <span className="truncate text-xs text-muted-foreground tabular-nums">{detailOf(area.key)}</span>}
                    </span>
                  </CommandItem>
                ))}
              </CommandGroup>
            ))}
          </CommandList>
        </Command>
      </PopoverContent>
    </Popover>
  );
};
