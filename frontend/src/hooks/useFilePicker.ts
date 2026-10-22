import { useCallback, useEffect, useState, type RefObject } from "react";

/** Safety net for browsers without a file-input `cancel` event: clear the busy state this long after the window regains focus. */
const FOCUS_FALLBACK_MS = 3000;

/**
 * Opens a hidden file input and tracks a `picking` flag so the UI can show a spinner
 * from the moment the OS file browser opens until the selection is handed to the app.
 *
 * The browser gives no reliable signal for "dialog closed, files still being read", so
 * the flag turns on at click and turns off on `change`, `cancel`, or (as a fallback)
 * shortly after the window regains focus with no event.
 */
export function useFilePicker(inputRef: RefObject<HTMLInputElement | null>) {
  const [picking, setPicking] = useState(false);

  const open = useCallback(() => {
    setPicking(true);
    inputRef.current?.click();
  }, [inputRef]);

  useEffect(() => {
    if (!picking) {
      return undefined;
    }

    const input = inputRef.current;
    let timer: number | undefined;
    const stop = () => setPicking(false);
    const onWindowFocus = () => {
      window.clearTimeout(timer);
      timer = window.setTimeout(stop, FOCUS_FALLBACK_MS);
    };

    input?.addEventListener("change", stop);
    input?.addEventListener("cancel", stop);
    window.addEventListener("focus", onWindowFocus);

    return () => {
      window.clearTimeout(timer);
      input?.removeEventListener("change", stop);
      input?.removeEventListener("cancel", stop);
      window.removeEventListener("focus", onWindowFocus);
    };
  }, [picking, inputRef]);

  return { picking, open };
}
