(function () {
  "use strict";

  var API_BASE = "api/";
  var MIN_GUESTS = 1;
  var MAX_GUESTS = 6;
  var guests = 2;

  var form = document.getElementById("reservationForm");
  var confirmation = document.getElementById("confirmation");
  var nameInput = document.getElementById("name");
  var emailInput = document.getElementById("email");
  var phoneInput = document.getElementById("phone");
  var dsgvoInput = document.getElementById("dsgvo");
  var newsInput = document.getElementById("news");
  var websiteInput = document.getElementById("website");
  var guestsValueEl = document.getElementById("guestsValue");
  var guestsMinusBtn = document.getElementById("guestsMinus");
  var guestsPlusBtn = document.getElementById("guestsPlus");
  var errName = document.getElementById("err-name");
  var errEmail = document.getElementById("err-email");
  var errDsgvo = document.getElementById("err-dsgvo");
  var banner = document.getElementById("formBanner");
  var soldOutBanner = document.getElementById("soldOutBanner");
  var submitBtn = form.querySelector("button[type=submit]");
  var submitBtnDefaultText = submitBtn.textContent;

  function setSeats(seatsLeft, seatPct, cap) {
    if (seatsLeft != null) {
      document.getElementById("seatsLeftText").textContent = seatsLeft;
      document.getElementById("seatsLeftTextInner").textContent = seatsLeft;
    }
    if (seatPct != null) {
      document.getElementById("seatsFillOuter").style.width = seatPct + "%";
      document.getElementById("seatsFillInner").style.width = seatPct + "%";
    }
    if (cap != null) {
      document.getElementById("capText").textContent = cap;
    }
    if (seatsLeft != null) applySoldOutState(seatsLeft);
  }

  function applySoldOutState(seatsLeft) {
    var soldOut = seatsLeft <= 0;
    soldOutBanner.hidden = !soldOut;
    if (soldOut) {
      form.hidden = true;
    } else if (confirmation.hidden) {
      form.hidden = false;
    }
  }

  function renderGuests() {
    guestsValueEl.textContent = guests;
  }

  function showBanner(message) {
    banner.textContent = message;
    banner.hidden = false;
  }

  function hideBanner() {
    banner.hidden = true;
    banner.textContent = "";
  }

  function clearErrors() {
    errName.textContent = "";
    errEmail.textContent = "";
    errDsgvo.textContent = "";
    hideBanner();
  }

  function validateClientSide() {
    var err = {};
    if (!nameInput.value.trim()) err.name = "Bitte Namen angeben.";
    if (!/^[^@\s]+@[^@\s]+\.[a-z]{2,}$/i.test(emailInput.value.trim())) {
      err.email = "Bitte gültige E-Mail-Adresse angeben.";
    }
    if (!dsgvoInput.checked) {
      err.dsgvo = "Ohne Einwilligung können wir die Reservierung nicht speichern.";
    }
    return err;
  }

  function loadSeats() {
    fetch(API_BASE + "seats.php")
      .then(function (res) { return res.json(); })
      .then(function (data) {
        if (data && data.ok) setSeats(data.seatsLeft, data.seatPct, data.cap);
      })
      .catch(function () {
        // Keep whatever was last shown rather than breaking the page.
      });
  }

  guestsMinusBtn.addEventListener("click", function () {
    guests = Math.max(MIN_GUESTS, guests - 1);
    renderGuests();
  });

  guestsPlusBtn.addEventListener("click", function () {
    guests = Math.min(MAX_GUESTS, guests + 1);
    renderGuests();
  });

  [nameInput, emailInput, dsgvoInput].forEach(function (el) {
    el.addEventListener("input", clearErrors);
    el.addEventListener("change", clearErrors);
  });

  form.addEventListener("submit", function (e) {
    e.preventDefault();

    var err = validateClientSide();
    clearErrors();
    if (err.name) errName.textContent = err.name;
    if (err.email) errEmail.textContent = err.email;
    if (err.dsgvo) errDsgvo.textContent = err.dsgvo;
    if (Object.keys(err).length) return;

    submitBtn.disabled = true;
    submitBtn.textContent = "Wird gesendet… - bitte kurz warten";

    fetch(API_BASE + "reserve.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        name: nameInput.value.trim(),
        email: emailInput.value.trim(),
        phone: phoneInput.value.trim(),
        guests: guests,
        dsgvo: dsgvoInput.checked,
        news: newsInput.checked,
        website: websiteInput.value
      })
    })
      .then(function (res) {
        return res.json().then(function (data) { return data; });
      })
      .then(function (data) {
        if (!data.ok) {
          var shown = false;
          if (data.errors) {
            if (data.errors.name) { errName.textContent = data.errors.name; shown = true; }
            if (data.errors.email) { errEmail.textContent = data.errors.email; shown = true; }
            if (data.errors.dsgvo) { errDsgvo.textContent = data.errors.dsgvo; shown = true; }
            if (data.errors.guests) { showBanner(data.errors.guests); shown = true; }
          }
          if (!shown) {
            showBanner(data.message || "Die Reservierung konnte nicht gespeichert werden. Bitte später erneut versuchen.");
          }
          return;
        }

        setSeats(data.seatsLeft, data.seatPct, null);

        document.getElementById("confirmGuests").textContent = guests + " Plätze";
        document.getElementById("confirmName").textContent = nameInput.value.trim();
        document.getElementById("confirmEmail").textContent = emailInput.value.trim();
        document.getElementById("confirmCode").textContent = data.code;

        form.hidden = true;
        confirmation.hidden = false;
      })
      .catch(function () {
        showBanner("Verbindung fehlgeschlagen. Bitte Internetverbindung prüfen und erneut senden.");
      })
      .finally(function () {
        submitBtn.disabled = false;
        submitBtn.textContent = submitBtnDefaultText;
      });
  });

  document.getElementById("resetForm").addEventListener("click", function () {
    form.reset();
    guests = 2;
    renderGuests();
    clearErrors();
    confirmation.hidden = true;
    loadSeats();
  });

  renderGuests();
  loadSeats();
})();
