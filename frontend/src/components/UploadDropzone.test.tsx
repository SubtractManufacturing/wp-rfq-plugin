import { act, fireEvent, render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, describe, expect, it, vi } from "vitest";
import { UploadDropzone } from "./UploadDropzone";

function setup() {
  const onFiles = vi.fn();
  render(<UploadDropzone inputLabel="Part files" onFiles={onFiles} />);
  const input = screen.getByLabelText("Part files");
  return { onFiles, input };
}

describe("UploadDropzone file browser busy state", () => {
  afterEach(() => {
    vi.useRealTimers();
  });

  it("shows a spinner as soon as the file browser opens", async () => {
    const user = userEvent.setup();
    setup();

    expect(screen.queryByTestId("spinner")).not.toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: /select files/i }));

    expect(screen.getByTestId("spinner")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /waiting for files/i })).toHaveAttribute("aria-busy", "true");
  });

  it("clears the spinner when files are chosen", async () => {
    const user = userEvent.setup();
    const { input, onFiles } = setup();

    await user.click(screen.getByRole("button", { name: /select files/i }));
    fireEvent.change(input, { target: { files: [new File(["x"], "a.step")] } });

    expect(onFiles).toHaveBeenCalledTimes(1);
    expect(screen.queryByTestId("spinner")).not.toBeInTheDocument();
  });

  it("clears the spinner when the file browser is cancelled", async () => {
    const user = userEvent.setup();
    const { input } = setup();

    await user.click(screen.getByRole("button", { name: /select files/i }));
    fireEvent(input, new Event("cancel"));

    expect(screen.queryByTestId("spinner")).not.toBeInTheDocument();
    expect(screen.getByRole("button", { name: /select files/i })).toBeInTheDocument();
  });

  it("falls back to clearing the spinner after the window regains focus with no event", async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
    setup();

    await user.click(screen.getByRole("button", { name: /select files/i }));
    act(() => {
      window.dispatchEvent(new Event("focus"));
    });
    expect(screen.getByTestId("spinner")).toBeInTheDocument();

    act(() => {
      vi.advanceTimersByTime(3100);
    });
    expect(screen.queryByTestId("spinner")).not.toBeInTheDocument();
  });
});
