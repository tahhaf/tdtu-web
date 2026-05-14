import { useState, useCallback } from "react";
import noteService from "../services/noteService";

export default function useNotes(onUnauthorized) {
  const [notes, setNotes] = useState([]);
  const [sharedNotes, setSharedNotes] = useState([]);
  const [labels, setLabels] = useState([]);

  const clearWorkspace = useCallback(() => {
    setNotes([]);
    setSharedNotes([]);
    setLabels([]);
  }, []);

  const handleError = useCallback((error) => {
    if (error?.status === 401) {
      clearWorkspace();
      onUnauthorized?.();
      return;
    }

    console.error(error);
  }, [clearWorkspace, onUnauthorized]);

  const refreshWorkspace = useCallback(async () => {
    try {
      const [ownNotesResponse, sharedNotesResponse, labelsResponse] = await Promise.all([
        noteService.getNotes(),
        noteService.getSharedNotes(),
        noteService.getLabels(),
      ]);

      setNotes(ownNotesResponse.data || []);
      setSharedNotes(sharedNotesResponse.data || []);
      setLabels(labelsResponse.data || []);
    } catch (error) {
      handleError(error);
    }
  }, [handleError]);

  const deleteNote = useCallback(async (id, password = null) => {
    await noteService.deleteNote(id, password);
    await refreshWorkspace();
  }, [refreshWorkspace]);

  const togglePin = useCallback(async (id) => {
    await noteService.togglePin(id);
    await refreshWorkspace();
  }, [refreshWorkspace]);

  return {
    notes,
    setNotes,
    sharedNotes,
    labels,
    refreshWorkspace,
    deleteNote,
    togglePin,
  };
}
