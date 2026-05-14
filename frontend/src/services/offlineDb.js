const DB_NAME = "notemate-offline-db";
const DB_VERSION = 1;

function openDatabase() {
  return new Promise((resolve, reject) => {
    const request = indexedDB.open(DB_NAME, DB_VERSION);

    request.onerror = () => reject(request.error || new Error("Unable to open offline database."));
    request.onsuccess = () => resolve(request.result);
    request.onupgradeneeded = () => {
      const db = request.result;

      if (!db.objectStoreNames.contains("notes")) {
        db.createObjectStore("notes", { keyPath: "id" });
      }

      if (!db.objectStoreNames.contains("labels")) {
        db.createObjectStore("labels", { keyPath: "id" });
      }

      if (!db.objectStoreNames.contains("shared_notes")) {
        db.createObjectStore("shared_notes", { keyPath: "id" });
      }

      if (!db.objectStoreNames.contains("sync_queue")) {
        const queueStore = db.createObjectStore("sync_queue", { keyPath: "id", autoIncrement: true });
        queueStore.createIndex("noteId", "noteId", { unique: false });
      }
    };
  });
}

function requestToPromise(request) {
  return new Promise((resolve, reject) => {
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error || new Error("IndexedDB request failed."));
  });
}

async function runTransaction(storeNames, mode, callback) {
  const db = await openDatabase();

  return new Promise((resolve, reject) => {
    const transaction = db.transaction(storeNames, mode);
    const stores = Array.isArray(storeNames)
      ? Object.fromEntries(storeNames.map((name) => [name, transaction.objectStore(name)]))
      : transaction.objectStore(storeNames);

    let callbackResult;

    transaction.oncomplete = () => {
      db.close();
      resolve(callbackResult);
    };

    transaction.onerror = () => {
      db.close();
      reject(transaction.error || new Error("IndexedDB transaction failed."));
    };

    transaction.onabort = () => {
      db.close();
      reject(transaction.error || new Error("IndexedDB transaction was aborted."));
    };

    Promise.resolve()
      .then(() => callback(stores))
      .then((result) => {
        callbackResult = result;
      })
      .catch((error) => {
        transaction.abort();
        reject(error);
      });
  });
}

function normalizeNote(note, overrides = {}) {
  return {
    id: note.id,
    clientId: note.clientId ?? null,
    userId: note.userId ?? 0,
    title: note.title ?? "",
    content: note.content ?? "",
    noteColor: note.noteColor ?? "default",
    isPinned: Boolean(note.isPinned),
    pinnedAt: note.pinnedAt ?? null,
    isLocked: Boolean(note.isLocked),
    images: Array.isArray(note.images) ? note.images : [],
    labelIds: Array.isArray(note.labelIds) ? note.labelIds : [],
    sharedWith: Array.isArray(note.sharedWith) ? note.sharedWith : [],
    ownerEmail: note.ownerEmail ?? null,
    ownerDisplayName: note.ownerDisplayName ?? null,
    sharedAt: note.sharedAt ?? null,
    permission: note.permission ?? "owner",
    createdAt: note.createdAt ?? new Date().toISOString(),
    updatedAt: note.updatedAt ?? new Date().toISOString(),
    syncStatus: note.syncStatus ?? "synced",
    isLocalOnly: Boolean(note.isLocalOnly),
    ...overrides,
  };
}

async function getAllFromStore(storeName) {
  return runTransaction(storeName, "readonly", (store) => requestToPromise(store.getAll()));
}

async function saveCollection(storeName, records, options = {}) {
  const { preservePendingNotes = false } = options;

  return runTransaction(storeName, "readwrite", async (store) => {
    let pendingNotes = [];

    if (storeName === "notes" && preservePendingNotes) {
      const existingNotes = await requestToPromise(store.getAll());
      pendingNotes = existingNotes.filter((note) => note.syncStatus === "pending" || note.isLocalOnly);
    }

    await requestToPromise(store.clear());

    const recordsToSave = [...records];

    if (pendingNotes.length > 0) {
      const serverClientIds = new Set(
        recordsToSave
          .map((record) => record.clientId)
          .filter((clientId) => clientId !== null && clientId !== undefined && clientId !== "")
          .map(String)
      );
      pendingNotes = pendingNotes.filter(
        (note) => !note.clientId || !serverClientIds.has(String(note.clientId))
      );

      const pendingById = new Map(pendingNotes.map((note) => [String(note.id), note]));
      const merged = recordsToSave
        .filter((record) => !pendingById.has(String(record.id)))
        .concat(pendingNotes);

      for (const record of merged) {
        await requestToPromise(store.put(record));
      }

      return;
    }

    for (const record of recordsToSave) {
      await requestToPromise(store.put(record));
    }
  });
}

export function createLocalNoteId() {
  return `local-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
}

export function toSyncPayload(note) {
  return {
    clientId: note.clientId ?? (String(note.id).startsWith("local-") ? note.id : null),
    title: note.title ?? "",
    content: note.content ?? "",
    noteColor: note.noteColor ?? "default",
    isPinned: Boolean(note.isPinned),
    images: Array.isArray(note.images) ? note.images : [],
    labelIds: Array.isArray(note.labelIds) ? note.labelIds : [],
  };
}

export async function getAllNotes() {
  const notes = await getAllFromStore("notes");
  return notes.sort((a, b) => new Date(b.updatedAt).getTime() - new Date(a.updatedAt).getTime());
}

export async function getNoteById(id) {
  return runTransaction("notes", "readonly", (store) => requestToPromise(store.get(id)));
}

export async function saveNotes(notes) {
  const normalizedNotes = notes.map((note) => normalizeNote(note));
  await saveCollection("notes", normalizedNotes, { preservePendingNotes: true });
}

export async function saveNote(note, overrides = {}) {
  const normalizedNote = normalizeNote(note, overrides);

  await runTransaction("notes", "readwrite", (store) => requestToPromise(store.put(normalizedNote)));
  return normalizedNote;
}

export async function removeNote(id) {
  await runTransaction("notes", "readwrite", (store) => requestToPromise(store.delete(id)));
}

export async function replaceTempNote(tempId, serverNote) {
  const normalizedServerNote = normalizeNote(serverNote, { syncStatus: "synced", isLocalOnly: false });

  await runTransaction("notes", "readwrite", async (store) => {
    await requestToPromise(store.delete(tempId));
    await requestToPromise(store.put(normalizedServerNote));
  });

  return normalizedServerNote;
}

export async function saveLabels(labels) {
  await saveCollection("labels", labels);
}

export async function getAllLabels() {
  return getAllFromStore("labels");
}

export async function saveSharedNotes(sharedNotes) {
  const normalizedNotes = sharedNotes.map((note) => normalizeNote(note));
  await saveCollection("shared_notes", normalizedNotes);
}

export async function getAllSharedNotes() {
  const notes = await getAllFromStore("shared_notes");
  return notes.sort((a, b) => new Date(b.updatedAt).getTime() - new Date(a.updatedAt).getTime());
}

export async function clearQueuedActionsForNote(noteId) {
  return runTransaction("sync_queue", "readwrite", async (store) => {
    const allItems = await requestToPromise(store.getAll());
    const relatedItems = allItems.filter((item) => String(item.noteId) === String(noteId));

    for (const item of relatedItems) {
      await requestToPromise(store.delete(item.id));
    }
  });
}

export async function enqueueNoteUpsert(note) {
  const payload = toSyncPayload(note);

  await runTransaction("sync_queue", "readwrite", async (store) => {
    const allItems = await requestToPromise(store.getAll());
    const relatedItems = allItems.filter(
      (item) => String(item.noteId) === String(note.id) && item.action === "upsert"
    );

    for (const item of relatedItems) {
      await requestToPromise(store.delete(item.id));
    }

    await requestToPromise(
      store.add({
        action: "upsert",
        noteId: note.id,
        payload,
        queuedAt: new Date().toISOString(),
      })
    );
  });
}

export async function enqueueNotePin(noteId, isPinned) {
  await runTransaction("sync_queue", "readwrite", async (store) => {
    const allItems = await requestToPromise(store.getAll());
    const relatedItems = allItems.filter(
      (item) => String(item.noteId) === String(noteId) && item.action === "pin"
    );

    for (const item of relatedItems) {
      await requestToPromise(store.delete(item.id));
    }

    await requestToPromise(
      store.add({
        action: "pin",
        noteId,
        payload: { isPinned },
        queuedAt: new Date().toISOString(),
      })
    );
  });
}

export async function enqueueNoteDelete(noteId, password = null) {
  await runTransaction("sync_queue", "readwrite", async (store) => {
    const allItems = await requestToPromise(store.getAll());
    const relatedItems = allItems.filter((item) => String(item.noteId) === String(noteId));

    for (const item of relatedItems) {
      await requestToPromise(store.delete(item.id));
    }

    await requestToPromise(
      store.add({
        action: "delete",
        noteId,
        payload: { password },
        queuedAt: new Date().toISOString(),
      })
    );
  });
}

export async function getSyncQueue() {
  const queueItems = await getAllFromStore("sync_queue");
  return queueItems.sort((a, b) => a.id - b.id);
}

export async function removeSyncQueueItem(id) {
  await runTransaction("sync_queue", "readwrite", (store) => requestToPromise(store.delete(id)));
}
