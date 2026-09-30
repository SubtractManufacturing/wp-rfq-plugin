import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";
import { FormProvider, useForm } from "../state/FormContext";
import type { RfqFormConfig } from "../types/config";
import { StepUploads } from "./StepUploads";

const config: RfqFormConfig = {
  restBase: "https://example.test/wp-json/rfq/v1",
  nonce: "nonce",
  airtableEmbedUrl: "https://airtable.com/embed/app",
  internationalRfqEmail: "large-rfq@example.test",
  salesContactEmail: "sales@example.test",
  maxParts: 3,
  materials: [],
};

function StepProbe() {
  const { step } = useForm();
  return <p data-testid="step">{step}</p>;
}

/** Answers upload-url requests and S3 PUTs; optionally fails PUTs for chosen filenames. */
function createFetchMock({ failFilenames = [] as string[] } = {}) {
  const requestedFilenames: string[] = [];
  const mock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
    const url = String(input);
    if (url.endsWith("/upload-urls")) {
      const body = JSON.parse(String(init?.body)) as { filename: string; file_type: string };
      requestedFilenames.push(body.filename);
      return new Response(
        JSON.stringify({
          upload_url: `https://s3.test/${encodeURIComponent(body.filename)}`,
          file_key: `intake/session-1/${body.file_type}s/${body.filename}`,
        }),
      );
    }
    const failed = failFilenames.some((name) => url.endsWith(encodeURIComponent(name)));
    return new Response("", { status: failed ? 500 : 200 });
  });
  return { mock, requestedFilenames };
}

function renderStep(fetchMock: typeof fetch, overrides: Partial<RfqFormConfig> = {}) {
  return render(
    <FormProvider config={{ ...config, ...overrides }} sessionId="session-1" token="jwt">
      <StepUploads fetchImpl={fetchMock} />
      <StepProbe />
    </FormProvider>,
  );
}

const step = (name: string) => new File(["cad"], name, { type: "application/octet-stream" });

describe("StepUploads batch upload", () => {
  it("shows a drop zone first and turns a multi-file selection into one part per file", async () => {
    const user = userEvent.setup();
    const { mock, requestedFilenames } = createFetchMock();
    renderStep(mock as unknown as typeof fetch);

    expect(screen.getByText("Upload part files")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /select files/i })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /continue to part details/i })).toBeDisabled();

    await user.upload(screen.getByLabelText(/^part files$/i), [step("a.step"), step("b.step"), step("c.step")]);

    await waitFor(() => expect(screen.getAllByText(/^uploaded/i)).toHaveLength(3));
    expect(requestedFilenames.sort()).toEqual(["a.step", "b.step", "c.step"]);
    expect(screen.getByText("3 parts")).toBeInTheDocument();
    expect(screen.queryByText("Upload part files")).not.toBeInTheDocument();

    const continueButton = screen.getByRole("button", { name: /continue to part details/i });
    expect(continueButton).toBeEnabled();
    await user.click(continueButton);
    expect(screen.getByTestId("step")).toHaveTextContent("partMeta");
  });

  it("accepts files dropped onto the drop zone", async () => {
    const { mock } = createFetchMock();
    const { container } = renderStep(mock as unknown as typeof fetch);

    const zone = container.querySelector(".border-dashed");
    expect(zone).not.toBeNull();
    const file = step("dropped.step");
    fireEvent.drop(zone as Element, { dataTransfer: { files: [file] } });

    await waitFor(() => expect(screen.getByText(/^uploaded/i)).toBeInTheDocument());
    expect(screen.getByText("dropped.step")).toBeInTheDocument();
  });

  it("caps the batch at maxParts and explains what was skipped", async () => {
    const user = userEvent.setup();
    const { mock, requestedFilenames } = createFetchMock();
    renderStep(mock as unknown as typeof fetch, { maxParts: 2 });

    await user.upload(screen.getByLabelText(/^part files$/i), [step("a.step"), step("b.step"), step("c.step")]);

    await waitFor(() => expect(screen.getAllByText(/^uploaded/i)).toHaveLength(2));
    expect(requestedFilenames).not.toContain("c.step");
    expect(screen.getByText(/only 2 of 3 files were added/i)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /add more parts/i })).toBeDisabled();
  });

  it("blocks continue while a part failed, and unblocks once it is removed", async () => {
    const user = userEvent.setup();
    const { mock } = createFetchMock({ failFilenames: ["bad.step"] });
    renderStep(mock as unknown as typeof fetch);

    await user.upload(screen.getByLabelText(/^part files$/i), [step("good.step"), step("bad.step")]);

    expect(await screen.findByText(/upload failed/i)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /continue to part details/i })).toBeDisabled();
    expect(screen.getByText(/retry or remove failed part files/i)).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: /remove bad\.step/i }));

    expect(screen.queryByText("bad.step")).not.toBeInTheDocument();
    expect(screen.getByRole("button", { name: /continue to part details/i })).toBeEnabled();
  });

  it("attaches supporting files to the part they were added under", async () => {
    // Drag-and-drop bypasses the input's accept filter, so exercise our own validation.
    const user = userEvent.setup({ applyAccept: false });
    const { mock, requestedFilenames } = createFetchMock();
    renderStep(mock as unknown as typeof fetch);

    await user.upload(screen.getByLabelText(/^part files$/i), [step("a.step"), step("b.step")]);
    await waitFor(() => expect(screen.getAllByText(/^uploaded/i)).toHaveLength(2));

    const drawing = new File(["pdf"], "b-drawing.pdf", { type: "application/pdf" });
    const badDrawing = new File(["txt"], "notes.txt", { type: "text/plain" });
    await user.upload(screen.getByLabelText(/supporting files for b\.step/i), [drawing, badDrawing]);

    await waitFor(() => expect(requestedFilenames).toContain("b-drawing.pdf"));
    expect(requestedFilenames).not.toContain("notes.txt");
    expect(await screen.findByText(/drawings must be pdf, png, or jpeg/i)).toBeInTheDocument();

    const rows = screen.getAllByRole("listitem").filter((item) => item.querySelector("input[aria-label^='Supporting']"));
    const rowB = rows.find((row) => within(row).queryByText("b.step"));
    expect(rowB).toBeDefined();
    expect(within(rowB as HTMLElement).getByText("b-drawing.pdf")).toBeInTheDocument();
    const rowA = rows.find((row) => within(row).queryByText("a.step"));
    expect(within(rowA as HTMLElement).queryByText("b-drawing.pdf")).not.toBeInTheDocument();
  });

  it("rejects oversized part files without requesting an upload url", async () => {
    const user = userEvent.setup();
    const { mock, requestedFilenames } = createFetchMock();
    renderStep(mock as unknown as typeof fetch);

    const huge = step("huge.step");
    Object.defineProperty(huge, "size", { value: 501 * 1024 * 1024 });
    await user.upload(screen.getByLabelText(/^part files$/i), huge);

    expect(await screen.findByText(/500 mb or smaller/i)).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /retry upload/i })).not.toBeInTheDocument();
    expect(requestedFilenames).toEqual([]);
  });
});
