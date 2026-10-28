import { useEffect } from "react";

/** Asks the browser to confirm before a reload, tab close, or navigation away from the page. */
export function useLeaveWarning(active: boolean) {
  useEffect(() => {
    if (!active) {
      return;
    }
    const warn = (event: BeforeUnloadEvent) => {
      event.preventDefault();
      // Older Safari and Firefox only prompt when returnValue is set.
      event.returnValue = "";
    };
    window.addEventListener("beforeunload", warn);
    return () => window.removeEventListener("beforeunload", warn);
  }, [active]);
}
