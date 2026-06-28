import { useEffect, useState } from "react";
import { performHealthStartup, scheduleHealthCheckAbort } from "../lib/appHealth";

export type StartupState = { status: "loading" } | { status: "fallback" } | { status: "ready" };

export function useAppHealthStartup({
  restBase,
  fetchImpl = fetch,
}: {
  restBase: string;
  fetchImpl?: typeof fetch;
}): StartupState {
  const [startup, setStartup] = useState<StartupState>({ status: "loading" });

  useEffect(() => {
    const controller = new AbortController();
    const clearStartupTimer = scheduleHealthCheckAbort(controller);

    void performHealthStartup({
      restBase,
      fetchImpl,
      signal: controller.signal,
      clearStartupTimer,
    }).then((status) => {
      setStartup({ status });
    });

    return () => {
      clearStartupTimer();
      controller.abort();
    };
  }, [restBase, fetchImpl]);

  return startup;
}
