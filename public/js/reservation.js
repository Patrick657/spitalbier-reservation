(function () {
  "use strict";

  var API_BASE = "api/";
  var MIN_GUESTS = 1;
  var MAX_GUESTS = 6;
  var guests = 2;

  var form = document.getElementById("reservationForm");
  var confirmation = document.getElementById("confirmation");
  var companyInput = document.getElementById("company");
  var firstNameInput = document.getElementById("firstName");
  var lastNameInput = document.getElementById("lastName");
  var emailInput = document.getElementById("email");
  var phoneInput = document.getElementById("phone");
  var dsgvoInput = document.getElementById("dsgvo");
  var newsInput = document.getElementById("news");
  var websiteInput = document.getElementById("website");
  var guestsValueEl = document.getElementById("guestsValue");
  var guestsMinusBtn = document.getElementById("guestsMinus");
  var guestsPlusBtn = document.getElementById("guestsPlus");
  var errCompany = document.getElementById("err-company");
  var errFirstName = document.getElementById("err-first-name");
  var errLastName = document.getElementById("err-last-name");
  var errEmail = document.getElementById("err-email");
  var errDsgvo = document.getElementById("err-dsgvo");
  var banner = document.getElementById("formBanner");
  var soldOutBanner = document.getElementById("soldOutBanner");
  var submitBtn = form.querySelector("button[type=submit]");
  var submitBtnDefaultText = submitBtn.textContent;
  var closedBanner = null;

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

  // Once the deadline has passed the form stays hidden for good; the banner
  // is built here so index.html (not in git) needs no extra markup.
  function applyClosedState(message) {
    if (!closedBanner) {
      closedBanner = document.createElement("div");
      closedBanner.className = "form-card__banner form-card__banner--soldout";
      closedBanner.setAttribute("role", "alert");
      closedBanner.appendChild(document.createElement("p"));
      form.parentNode.insertBefore(closedBanner, form);
    }
    // Turn e-mail addresses in the plain-text message into mailto links.
    var p = closedBanner.firstChild;
    p.textContent = "";
    message.split(/([^\s@]+@[^\s@]+\.[a-z]{2,})/i).forEach(function (part, i) {
      if (i % 2) {
        var a = document.createElement("a");
        a.href = "mailto:" + part;
        a.textContent = part;
        p.appendChild(a);
      } else if (part) {
        p.appendChild(document.createTextNode(part));
      }
    });
    soldOutBanner.hidden = true;
    form.hidden = true;
    confirmation.hidden = true;
  }

  function applySoldOutState(seatsLeft) {
    if (closedBanner) return;
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
    errCompany.textContent = "";
    errFirstName.textContent = "";
    errLastName.textContent = "";
    errEmail.textContent = "";
    errDsgvo.textContent = "";
    hideBanner();
  }

  function validateClientSide() {
    var err = {};
    // A company name may stand in for the person.
    if (!companyInput.value.trim()) {
      if (!firstNameInput.value.trim()) err.first_name = "Bitte Vornamen angeben.";
      if (!lastNameInput.value.trim()) err.last_name = "Bitte Nachnamen angeben.";
    }
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
        if (data && data.ok) {
          setSeats(data.seatsLeft, data.seatPct, data.cap);
          if (data.closed) applyClosedState(data.closedMessage);
        }
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

  [companyInput, firstNameInput, lastNameInput, emailInput, dsgvoInput].forEach(function (el) {
    el.addEventListener("input", clearErrors);
    el.addEventListener("change", clearErrors);
  });

  form.addEventListener("submit", function (e) {
    e.preventDefault();

    var err = validateClientSide();
    clearErrors();
    if (err.first_name) errFirstName.textContent = err.first_name;
    if (err.last_name) errLastName.textContent = err.last_name;
    if (err.email) errEmail.textContent = err.email;
    if (err.dsgvo) errDsgvo.textContent = err.dsgvo;
    if (Object.keys(err).length) return;

    submitBtn.disabled = true;
    submitBtn.textContent = "Wird gesendet… - bitte kurz warten";

    fetch(API_BASE + "reserve.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        company: companyInput.value.trim(),
        first_name: firstNameInput.value.trim(),
        last_name: lastNameInput.value.trim(),
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
          if (data.errors && data.errors.closed) {
            applyClosedState(data.errors.closed);
            return;
          }
          var shown = false;
          if (data.errors) {
            if (data.errors.company) { errCompany.textContent = data.errors.company; shown = true; }
            if (data.errors.first_name) { errFirstName.textContent = data.errors.first_name; shown = true; }
            if (data.errors.last_name) { errLastName.textContent = data.errors.last_name; shown = true; }
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
        document.getElementById("confirmName").textContent = companyInput.value.trim() || (firstNameInput.value.trim() + " " + lastNameInput.value.trim());
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
