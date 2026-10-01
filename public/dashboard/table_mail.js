// Tischbestätigung: placeholder buttons and recipient selection (step 1),
// the typed confirmation (step 2) and the one-by-one send loop (step 3).
(function () {
  // --- Step 1: compose -----------------------------------------------------
  var body = document.getElementById("mail-body");
  var subject = document.getElementById("mail-subject");
  if (body && subject) {
    // Placeholders go into whichever of the two fields was used last.
    var target = body;
    [body, subject].forEach(function (field) {
      field.addEventListener("focus", function () { target = field; });
    });

    Array.prototype.forEach.call(document.querySelectorAll(".mail-chip"), function (chip) {
      chip.addEventListener("click", function () {
        var text = chip.getAttribute("data-placeholder");
        var start = target.selectionStart, end = target.selectionEnd;
        target.value = target.value.slice(0, start) + text + target.value.slice(end);
        target.focus();
        target.selectionStart = target.selectionEnd = start + text.length;
      });
    });

    var reset = document.getElementById("mail-reset");
    reset.addEventListener("click", function () {
      if (!window.confirm("Betreff und Text durch den Standardtext ersetzen?")) return;
      subject.value = reset.getAttribute("data-subject");
      body.value = reset.getAttribute("data-body");
    });

    var checks = document.querySelectorAll(".mail-check");
    var count = document.getElementById("mail-count");
    var updateCount = function () {
      count.textContent = document.querySelectorAll(".mail-check:checked").length;
    };
    var selectAll = function (pick) {
      Array.prototype.forEach.call(checks, function (box) { box.checked = pick(box); });
      updateCount();
    };
    Array.prototype.forEach.call(checks, function (box) { box.addEventListener("change", updateCount); });
    document.getElementById("mail-select-new").addEventListener("click", function () {
      selectAll(function (box) { return box.getAttribute("data-new") === "1"; });
    });
    document.getElementById("mail-select-none").addEventListener("click", function () {
      selectAll(function () { return false; });
    });
    updateCount();

    // Enter in a text field must not submit — the first submit button in
    // the form is "Testmail senden".
    document.getElementById("mail-form").addEventListener("keydown", function (evt) {
      if (evt.key === "Enter" && evt.target.tagName === "INPUT" && evt.target.type !== "checkbox") {
        evt.preventDefault();
      }
    });
  }

  // --- Step 2: confirm -----------------------------------------------------
  var confirmForm = document.getElementById("mail-confirm-form");
  if (confirmForm) {
    var total = confirmForm.getAttribute("data-total");
    var check = document.getElementById("mail-confirm-check");
    var typed = document.getElementById("mail-confirm-count");
    var submit = document.getElementById("mail-confirm-submit");
    var sync = function () {
      submit.disabled = !(check.checked && typed.value.trim() === total);
    };
    check.addEventListener("change", sync);
    typed.addEventListener("input", sync);
    sync();

    confirmForm.addEventListener("submit", function (evt) {
      if (submit.disabled || !window.confirm("Jetzt wirklich " + total + " E-Mail(s) an die Gäste versenden?")) {
        evt.preventDefault();
        return;
      }
      submit.disabled = true; // no double click
    });
  }

  // --- Step 3: send --------------------------------------------------------
  var progress = document.getElementById("mail-progress");
  if (progress) {
    var rows = Array.prototype.slice.call(document.querySelectorAll("tr[data-id]"));
    var text = document.getElementById("mail-progress-text");
    var fill = document.getElementById("mail-bar-fill");
    var resume = document.getElementById("mail-resume");
    var stop = document.getElementById("mail-stop");
    var labels = { sent: "versendet", failed: "fehlgeschlagen", skipped: "übersprungen", error: "nicht versendet" };
    var running = false;
    var stopRequested = false;

    var pendingRows = function () {
      return rows.filter(function (row) { return row.getAttribute("data-status") === "pending"; });
    };
    var countOf = function (status) {
      return rows.filter(function (row) { return row.getAttribute("data-status") === status; }).length;
    };
    var render = function () {
      var open = pendingRows().length;
      fill.style.width = (rows.length ? ((rows.length - open) / rows.length) * 100 : 100) + "%";
      var summary = countOf("sent") + " versendet, " + countOf("failed") + " fehlgeschlagen, " + countOf("skipped") + " übersprungen";
      if (running) {
        text.textContent = "Versand läuft – bitte diese Seite geöffnet lassen. " + (rows.length - open) + " von " + rows.length + " verarbeitet (" + summary + ").";
      } else if (open) {
        text.textContent = "Versand angehalten: " + open + " von " + rows.length + " E-Mails sind noch offen (" + summary + ").";
      } else {
        text.textContent = "Versand abgeschlossen: " + summary + ". Fehlgeschlagene E-Mails können über „Zurück“ erneut ausgewählt werden.";
      }
      resume.hidden = running || !open;
      stop.hidden = !running;
    };
    var setStatus = function (row, status, message) {
      row.setAttribute("data-status", status);
      var cell = row.querySelector(".mail-status");
      cell.textContent = "";
      var badge = document.createElement("span");
      badge.className = "dash-badge " + (status === "sent" ? "dash-badge--active" : "dash-badge--cancelled");
      badge.textContent = labels[status] || status;
      cell.appendChild(badge);
      if (message) {
        var note = document.createElement("span");
        note.className = "dash-note";
        note.textContent = " " + message;
        cell.appendChild(note);
      }
    };

    var sendNext = function () {
      var row = pendingRows()[0];
      if (!row || stopRequested) {
        running = false;
        render();
        return;
      }
      var data = new URLSearchParams();
      data.set("id", row.getAttribute("data-id"));
      data.set("token", progress.getAttribute("data-token"));
      data.set("csrf", progress.getAttribute("data-csrf"));

      fetch("table_mail_send.php", { method: "POST", body: data, credentials: "same-origin" })
        .then(function (response) { return response.json(); })
        .then(function (result) {
          setStatus(row, result.status, result.message);
          render();
          window.setTimeout(sendNext, 400);
        })
        .catch(function () {
          // Unclear whether this one went out — stop rather than guess.
          setStatus(row, "error", "Keine Antwort vom Server – bitte im E-Mail-Log prüfen.");
          running = false;
          render();
        });
    };
    var start = function () {
      if (running) return;
      running = true;
      stopRequested = false;
      render();
      sendNext();
    };

    resume.addEventListener("click", start);
    stop.addEventListener("click", function () { stopRequested = true; stop.hidden = true; });
    window.addEventListener("beforeunload", function (evt) {
      if (running) { evt.preventDefault(); evt.returnValue = ""; }
    });

    render();
    if (progress.getAttribute("data-autostart") === "1") start();
  }
})();
