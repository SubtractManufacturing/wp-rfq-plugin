import { setupWorker } from "msw/browser";
import { devMockHandlers } from "./handlers";

export const devMockWorker = setupWorker(...devMockHandlers);
