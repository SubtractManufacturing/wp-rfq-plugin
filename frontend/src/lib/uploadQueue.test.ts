import { describe, expect, it } from "vitest";
import { createUploadQueue } from "./uploadQueue";

function deferred() {
  let resolve!: () => void;
  const promise = new Promise<void>((res) => {
    resolve = res;
  });
  return { promise, resolve };
}

describe("createUploadQueue", () => {
  it("never runs more tasks than the concurrency limit and drains the rest in order", async () => {
    const queue = createUploadQueue(2);
    const gates = [deferred(), deferred(), deferred(), deferred()];
    const started: number[] = [];

    gates.forEach((gate, index) => {
      queue.enqueue(async () => {
        started.push(index);
        await gate.promise;
      });
    });

    expect(started).toEqual([0, 1]);

    gates[0]?.resolve();
    await new Promise((resolve) => setTimeout(resolve, 0));
    expect(started).toEqual([0, 1, 2]);

    gates[1]?.resolve();
    gates[2]?.resolve();
    gates[3]?.resolve();
    await new Promise((resolve) => setTimeout(resolve, 0));
    expect(started).toEqual([0, 1, 2, 3]);
  });

  it("keeps draining after a task rejects", async () => {
    const queue = createUploadQueue(1);
    const ran: string[] = [];

    queue.enqueue(async () => {
      ran.push("first");
      throw new Error("boom");
    });
    queue.enqueue(async () => {
      ran.push("second");
    });

    await new Promise((resolve) => setTimeout(resolve, 0));
    expect(ran).toEqual(["first", "second"]);
  });
});
