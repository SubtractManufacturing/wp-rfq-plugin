export interface UploadQueue {
  enqueue: (task: () => Promise<void>) => void;
}

/**
 * Runs upload tasks with a fixed concurrency so a large batch of parts does not
 * open dozens of simultaneous S3 connections. Tasks must not throw; any
 * rejection is swallowed so one failure never stalls the queue.
 */
export function createUploadQueue(concurrency: number): UploadQueue {
  const pending: Array<() => Promise<void>> = [];
  let active = 0;

  const drain = () => {
    while (active < concurrency && pending.length > 0) {
      const task = pending.shift();
      if (!task) {
        return;
      }
      active += 1;
      void task()
        .catch(() => undefined)
        .finally(() => {
          active -= 1;
          drain();
        });
    }
  };

  return {
    enqueue(task) {
      pending.push(task);
      drain();
    },
  };
}
