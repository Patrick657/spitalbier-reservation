// Click-to-sort for every .dash-table. A cell may carry data-sort with the
// value to sort by (dates, counts); otherwise its text is used. Headers
// marked data-nosort (action columns) stay inert.
(function () {
  var collator = new Intl.Collator("de", { numeric: true, sensitivity: "base" });

  function cellValue(row, index) {
    var cell = row.cells[index];
    if (!cell) return "";
    var raw = cell.getAttribute("data-sort");
    return (raw !== null ? raw : cell.textContent).trim();
  }

  function sortBy(table, index, dir) {
    var body = table.tBodies[0];
    var rows = Array.prototype.filter.call(body.rows, function (row) {
      return !row.querySelector(".dash-table__empty");
    });
    rows.sort(function (a, b) {
      var result = collator.compare(cellValue(a, index), cellValue(b, index));
      return dir === "desc" ? -result : result;
    });
    rows.forEach(function (row) { body.appendChild(row); });

    Array.prototype.forEach.call(table.tHead.rows[0].cells, function (th, i) {
      if (i === index) th.setAttribute("data-sorted", dir);
      else th.removeAttribute("data-sorted");
    });
  }

  Array.prototype.forEach.call(document.querySelectorAll("table.dash-table"), function (table) {
    if (!table.tHead || !table.tBodies.length) return;
    Array.prototype.forEach.call(table.tHead.rows[0].cells, function (th, index) {
      if (th.hasAttribute("data-nosort")) return;
      th.classList.add("is-sortable");
      th.setAttribute("title", "Zum Sortieren klicken");
      th.addEventListener("click", function () {
        sortBy(table, index, th.getAttribute("data-sorted") === "asc" ? "desc" : "asc");
      });
    });
  });
})();
