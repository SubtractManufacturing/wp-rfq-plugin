import { isDevFixtureMode } from "../mocks/devConfig";

export function DevModeBanner() {
  if (!isDevFixtureMode()) {
    return null;
  }

  return (
    <div
      className="mb-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950"
      role="status"
    >
      <strong>Dev mode:</strong> Sample data is preloaded and step tabs are unlocked. API responses are mocked.
    </div>
  );
}
