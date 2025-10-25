// Utility: strip non-digits
function onlyDigits(value) {
    return (value || "").replace(/\D+/g, "");
}

// Utility: Luhn checksum for card numbers
function luhnCheck(cardNumber) {
    const digits = onlyDigits(cardNumber);
    if (digits.length < 12 || digits.length > 19) return false;

    let sum = 0;
    let shouldDouble = false;

    for (let i = digits.length - 1; i >= 0; i--) {
        let digit = parseInt(digits[i], 10);
        if (shouldDouble) {
            digit *= 2;
            if (digit > 9) digit -= 9;
        }
        sum += digit;
        shouldDouble = !shouldDouble;
    }
    return sum % 10 === 0;
}

// Format: group into 4s
function formatCardNumber(value) {
    const digits = onlyDigits(value).slice(0, 19);
    const groups = [];
    for (let i = 0; i < digits.length; i += 4) {
        groups.push(digits.slice(i, i + 4));
    }
    return groups.join(" ");
}

// Expiry validation: MM, YY, not in the past
function validateExpiry(mm, yy) {
    const month = parseInt(mm, 10);
    const yearTwo = parseInt(yy, 10);

    if (isNaN(month) || isNaN(yearTwo)) return false;
    if (month < 1 || month > 12) return false;

    const now = new Date();
    const currentYearTwo = parseInt(now.getFullYear().toString().slice(-2), 10);
    const currentMonth = now.getMonth() + 1;

    // normalize YY to 2000–2099
    const fullYear = 2000 + yearTwo;

    if (fullYear < now.getFullYear()) return false;
    if (fullYear === now.getFullYear() && month < currentMonth) return false;

    return true;
}

function validateCVV(cvv) {
    const digits = onlyDigits(cvv);
    return digits.length === 3 || digits.length === 4;
}

function setError(id, message) {
    const el = document.querySelector(`small[data-error-for="${id}"]`);
    if (el) el.textContent = message || "";
}

function clearAllErrors() {
    document.querySelectorAll(".error").forEach(el => (el.textContent = ""));
}

function setStatus(message) {
    const el = document.getElementById("form-status");
    if (el) el.textContent = message;
}

function toggleSuccess(show) {
    const el = document.getElementById("success");
    if (!el) return;
    el.classList.toggle("hidden", !show);
}

function validateEmail(email) {
    // Simple, robust email check
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
}

function attachCardFormatting() {
    const cardInput = document.getElementById("card-number");
    if (!cardInput) return;

    cardInput.addEventListener("input", () => {
        const caret = cardInput.selectionStart;
        const formatted = formatCardNumber(cardInput.value);
        cardInput.value = formatted;

        // Keep caret near end; fine for basic UX
        cardInput.selectionStart = cardInput.selectionEnd = caret;
    });

    cardInput.addEventListener("blur", () => {
        const valid = luhnCheck(cardInput.value);
        setError("card-number", valid ? "" : "Invalid card number.");
    });
}

function formToData() {
    const fields = [
        "name",
        "email",
        "card-number",
        "expiry-month",
        "expiry-year",
        "cvv",
        "address-line1",
        "address-line2",
        "city",
        "state",
        "zip",
        "country",
        "amount",
    ];
    const data = {};
    for (const id of fields) {
        const el = document.getElementById(id);
        if (!el) continue;
        data[id] = el.value.trim();
    }
    return data;
}

function validateForm(data) {
    let ok = true;

    if (!data["name"]) {
        setError("name", "Name is required.");
        ok = false;
    }
    if (!validateEmail(data["email"])) {
        setError("email", "Enter a valid email.");
        ok = false;
    }
    if (!luhnCheck(data["card-number"])) {
        setError("card-number", "Invalid card number.");
        ok = false;
    }
    if (!validateExpiry(data["expiry-month"], data["expiry-year"])) {
        setError("expiry-month", "Check month.");
        setError("expiry-year", "Check year.");
        ok = false;
    } else {
        setError("expiry-month", "");
        setError("expiry-year", "");
    }
    if (!validateCVV(data["cvv"])) {
        setError("cvv", "CVV must be 3 or 4 digits.");
        ok = false;
    }
    if (!data["address-line1"]) {
        setError("address-line1", "Address line 1 is required.");
        ok = false;
    }
    if (!data["city"]) {
        setError("city", "City is required.");
        ok = false;
    }
    if (!data["state"]) {
        setError("state", "State/Province is required.");
        ok = false;
    }
    if (!data["zip"]) {
        setError("zip", "ZIP/Postal code is required.");
        ok = false;
    }
    if (!data["country"]) {
        setError("country", "Country is required.");
        ok = false;
    }
    const amountNum = Number(data["amount"]);
    if (!isFinite(amountNum) || amountNum < 0.5) {
        setError("amount", "Enter an amount >= 0.50.");
        ok = false;
    }

    return ok;
}

function fakeToken() {
    // Placeholder token to demonstrate flow; replace with gateway tokenization.
    const rand = Math.random().toString(36).slice(2);
    return `tok_${Date.now()}_${rand}`;
}

function handleSubmit(e) {
    e.preventDefault();
    clearAllErrors();
    toggleSuccess(false);
    setStatus("Validating...");

    const data = formToData();

    if (!validateForm(data)) {
        setStatus("Please fix the errors and try again.");
        return;
    }

    // DO NOT send raw card data to your backend in production.
    // Instead, use a payment provider's client SDK to tokenize the card
    // and send only the token + billing details to your backend.
    const token = fakeToken();

    setStatus("Validated. Ready to send a payment token.");
    toggleSuccess(true);

    // Example payload structure for a real payment request:
    const payload = {
        token,
        amount: Number(data["amount"]),
        currency: "USD",
        email: data["email"],
        billing: {
            name: data["name"],
            addressLine1: data["address-line1"],
            addressLine2: data["address-line2"] || null,
            city: data["city"],
            state: data["state"],
            zip: data["zip"],
            country: data["country"],
        },
        // NEVER include raw card fields server-side.
    };

    console.log("Submit this payload to your backend:", payload);
}

function ready(fn) {
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", fn);
    } else {
        fn();
    }
}

ready(() => {
    attachCardFormatting();
    const form = document.getElementById("payment-form");
    form?.addEventListener("submit", handleSubmit);
});