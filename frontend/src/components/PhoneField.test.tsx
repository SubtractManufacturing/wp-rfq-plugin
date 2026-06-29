import { useState } from "react";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it } from "vitest";
import type { CountryCode } from "../lib/phone";
import { PhoneField } from "./PhoneField";

function PhoneFieldHarness({ initialCountry = "US" as CountryCode }) {
  const [phone, setPhone] = useState("");
  const [phoneCountry, setPhoneCountry] = useState<CountryCode>(initialCountry);

  return (
    <PhoneField
      onChange={({ phone: nextPhone, phoneCountry: nextCountry }) => {
        setPhone(nextPhone);
        setPhoneCountry(nextCountry);
      }}
      phone={phone}
      phoneCountry={phoneCountry}
    />
  );
}

describe("PhoneField", () => {
  it("formats phone input and updates the selected country", async () => {
    const user = userEvent.setup();

    render(<PhoneFieldHarness />);

    await user.type(screen.getByRole("textbox", { name: "Phone" }), "2025550105");
    expect(screen.getByRole("textbox", { name: "Phone" })).toHaveValue("(202) 555-0105");

    await user.selectOptions(screen.getByRole("combobox", { name: "Country code" }), "GB");
    expect(screen.getByRole("combobox", { name: "Country code" })).toHaveValue("GB");
  });
});
