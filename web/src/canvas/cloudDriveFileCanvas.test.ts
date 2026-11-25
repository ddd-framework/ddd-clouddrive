import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import { cloudDriveFileCanvasReferenceOf, cloudDriveFolderCanvasReferenceOf, driveNodeIdOfCanvasReference, parseDriveFolderWidgetPayload } from './cloudDriveFileCanvas.ts';

describe('the file canvas of the cloud drive', () => {
  it('opens for files the viewer previews, by their reference', () => {
    const names = ['notes.md', 'report.pdf', 'photo.JPG', 'data.csv', 'config.json', 'script.ts', 'log.txt', 'clip.mp4', 'voice.mp3'];
    for (const name of names) assert.equal(cloudDriveFileCanvasReferenceOf({ reference: ' drive/812 ', name }), 'drive/812', name);
    // a file without an extension, by its type
    assert.equal(cloudDriveFileCanvasReferenceOf({ reference: 'drive/5', name: 'scan', contentType: 'application/pdf' }), 'drive/5');
  });

  it('leaves folders, files without a preview and foreign references to the former actions', () => {
    assert.equal(cloudDriveFileCanvasReferenceOf({ reference: 'drive/40', name: 'web', isFolder: true }), null);
    for (const name of ['deck.pptx', 'budget.xlsx', 'letter.docx', 'bundle.zip', 'logo.svg', 'page.html', 'raw.heic', 'movie.avi', 'unknown']) {
      assert.equal(cloudDriveFileCanvasReferenceOf({ reference: 'drive/812', name }), null, name);
    }
    assert.equal(cloudDriveFileCanvasReferenceOf({ reference: 'workspace/out/notes.md', name: 'notes.md' }), null);
    assert.equal(cloudDriveFileCanvasReferenceOf({ reference: 'drive/abc', name: 'notes.md' }), null);
    assert.equal(cloudDriveFileCanvasReferenceOf({ reference: '', name: 'notes.md' }), null);
  });

  it('shows an office file with a page picture as that picture', () => {
    assert.equal(cloudDriveFileCanvasReferenceOf({ reference: 'drive/812', name: 'deck.pptx', thumbnailUrl: '/api/public/drive/content/t' }), 'drive/812');
  });

  it('reads the node of a reference and nothing else', () => {
    assert.equal(driveNodeIdOfCanvasReference('drive/812'), 812);
    for (const reference of [undefined, null, '', 'drive/0', 'drive/abc', 'drive/812/../1', 'https://evil.example/812', 'drive/99999999999999999999']) {
      assert.equal(driveNodeIdOfCanvasReference(reference), null, String(reference));
    }
  });

  it('opens an office file the server renders as a PDF', () => {
    assert.equal(cloudDriveFileCanvasReferenceOf({ reference: 'drive/812', name: 'letter.docx', hasPdfRendition: true }), 'drive/812');
    assert.equal(cloudDriveFileCanvasReferenceOf({ reference: 'drive/812', name: 'letter.docx', hasPdfRendition: false }), null);
  });
});

describe('the folder canvas of the cloud drive', () => {
  it('opens for a folder of the drive and for nothing else', () => {
    assert.equal(cloudDriveFolderCanvasReferenceOf({ reference: ' drive/40 ', isFolder: true }), 'drive/40');
    assert.equal(cloudDriveFolderCanvasReferenceOf({ reference: 'drive/40', isFolder: false }), null);
    assert.equal(cloudDriveFolderCanvasReferenceOf({ reference: 'drive/40' }), null);
    assert.equal(cloudDriveFolderCanvasReferenceOf({ reference: 'workspace/out', isFolder: true }), null);
  });

  it('reads a DriveFolder block: the node, the title and where it is', () => {
    assert.deepEqual(parseDriveFolderWidgetPayload(JSON.stringify({ driveNodeId: 40, name: 'Reports', path: '/2026/Reports', spaceTitle: 'Library' })), {
      reference: 'drive/40',
      driveNodeId: 40,
      title: 'Reports',
      location: 'Library · /2026/Reports',
    });
    // the agent's title wins; a root folder is named by its space
    assert.equal(parseDriveFolderWidgetPayload(JSON.stringify({ driveNodeId: 40, name: 'Reports', title: 'The weekly reports' }))?.title, 'The weekly reports');
    assert.deepEqual(parseDriveFolderWidgetPayload(JSON.stringify({ driveNodeId: 7, path: '/', spaceTitle: 'Library' })), {
      reference: 'drive/7',
      driveNodeId: 7,
      title: 'Library',
      location: 'Library',
    });
  });

  it('refuses a block that names no node, and cuts long texts', () => {
    for (const payload of [undefined, null, '', 'not json', '[]', '"drive/40"', '{}', '{"driveNodeId":0}', '{"driveNodeId":"abc"}', '{"driveNodeId":1.5}', '{"driveNodeId":-3}']) {
      assert.equal(parseDriveFolderWidgetPayload(payload), null, String(payload));
    }
    const long = parseDriveFolderWidgetPayload(JSON.stringify({ driveNodeId: 1, title: 'x'.repeat(5000), path: 42 }));
    assert.equal(long?.title.length, 200);
    assert.equal(long?.location, null);
  });
});
