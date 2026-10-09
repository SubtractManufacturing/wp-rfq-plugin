import { act, fireEvent, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { AirtableFallback, FALLBACK_LOAD_TIMEOUT_MS } from "./AirtableFallback";

describe("AirtableFallback", () => {
  afterEach(() => {
    vi.useRealTimers();
  });

  // @covers AC-WP-002
  it("shows the loading indicator and hides the iframe until Airtable finishes loading", () => {
    render(<AirtableFallback src="https://airtable.com/embed/app" />);

    const frame = screen.getByTitle("RFQ fallback form");
    expect(screen.getByRole("status")).toHaveTextContent(/loading quote form/i);
    expect(frame).toHaveClass("opacity-0");

    fireEvent.load(frame);

    expect(screen.queryByRole("status")).not.toBeInTheDocument();
    expect(frame).toHaveClass("opacity-100");
  });

  it("reveals the iframe after the timeout if Airtable never fires load", () => {
    vi.useFakeTimers();
    render(<AirtableFallback src="https://airtable.com/embed/app" />);

    act(() => {
      vi.advanceTimersByTime(FALLBACK_LOAD_TIMEOUT_MS - 1);
    });
    expect(screen.getByRole("status")).toBeInTheDocument();

    act(() => {
      vi.advanceTimersByTime(1);
    });
    expect(screen.queryByRole("status")).not.toBeInTheDocument();
    expect(screen.getByTitle("RFQ fallback form")).toHaveClass("opacity-100");
  });

  it("shows the unavailable message, with no loader, when no Airtable URL is configured", () => {
    render(<AirtableFallback src="" />);

    expect(screen.getByText(/temporarily unavailable/i)).toBeInTheDocument();
    expect(screen.queryByRole("status")).not.toBeInTheDocument();
    expect(screen.queryByTitle("RFQ fallback form")).not.toBeInTheDocument();
  });
});
