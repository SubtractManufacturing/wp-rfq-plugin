import "@testing-library/jest-dom/vitest";

// jsdom does not implement Element.scrollIntoView.
if (typeof HTMLElement.prototype.scrollIntoView !== "function") {
  HTMLElement.prototype.scrollIntoView = () => undefined;
}
