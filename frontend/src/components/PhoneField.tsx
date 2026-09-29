import type { CountryCode } from "../lib/phone";
import { formatPhoneDisplay, getCountryOptions } from "../lib/phone";
import { Select } from "./Select";

const countryOptions = getCountryOptions();

export function PhoneField({
  phone,
  phoneCountry,
  onChange,
}: {
  phone: string;
  phoneCountry: CountryCode;
  onChange: (next: { phone: string; phoneCountry: CountryCode }) => void;
}) {
  return (
    <span className="mt-1 flex items-center gap-2">
      <Select
        aria-label="Country code"
        onChange={(event) => {
          const nextCountry = event.target.value as CountryCode;
          onChange({
            phoneCountry: nextCountry,
            phone: phone === "" ? "" : formatPhoneDisplay(phone, nextCountry),
          });
        }}
        value={phoneCountry}
        wrapperClassName="w-44 shrink-0"
      >
        {countryOptions.map((option) => (
          <option key={option.code} value={option.code}>
            {option.label} (+{option.callingCode})
          </option>
        ))}
      </Select>
      <input
        aria-label="Phone"
        className="w-full rounded-md border border-slate-300 px-3 py-2"
        onChange={(event) => {
          onChange({
            phoneCountry,
            phone: formatPhoneDisplay(event.target.value, phoneCountry),
          });
        }}
        type="tel"
        value={phone}
      />
    </span>
  );
}
