/**
 * Client-side paging for the Order API viewer.
 *
 * Every row the API returned is already in the document. Paging only shows and hides them, so moving
 * between pages costs nothing — no request, and in particular not another call to the rationed
 * case-order endpoint. That is the whole reason this is done here rather than on the server.
 *
 * The table is fully usable with this script absent: without it every row is simply visible at once,
 * which is correct, just longer. Nothing here is load-bearing for reading the data.
 */
(function () {
  'use strict';

  var PER_PAGE = 25;

  function init(root) {
    var rows = Array.prototype.slice.call(root.querySelectorAll('tbody > tr'));
    var controls = document.querySelector('[data-order-api-viewer-pager]');

    if (!controls || rows.length <= PER_PAGE) {
      return;
    }

    var pageCount = Math.ceil(rows.length / PER_PAGE);
    var current = 1;

    var label = controls.querySelector('[data-pager-label]');
    var previous = controls.querySelector('[data-pager-prev]');
    var next = controls.querySelector('[data-pager-next]');

    function render() {
      var first = (current - 1) * PER_PAGE;
      var last = first + PER_PAGE;

      rows.forEach(function (row, index) {
        // `hidden` rather than a class: a hidden row is removed from the accessibility tree too,
        // so a screen reader does not announce 200 rows on a 25-row page.
        row.hidden = index < first || index >= last;
      });

      if (label) {
        label.textContent = 'Page ' + current + ' of ' + pageCount;
      }

      if (previous) {
        previous.disabled = current === 1;
      }

      if (next) {
        next.disabled = current === pageCount;
      }

      // Pausing is the courteous half of hiding: a recording playing in a row that has just
      // scrolled out of the page would otherwise keep playing with no visible way to stop it.
      root.querySelectorAll('tr[hidden] audio').forEach(function (audio) {
        if (!audio.paused) {
          audio.pause();
        }
      });
    }

    if (previous) {
      previous.addEventListener('click', function () {
        if (current > 1) {
          current--;
          render();
        }
      });
    }

    if (next) {
      next.addEventListener('click', function () {
        if (current < pageCount) {
          current++;
          render();
        }
      });
    }

    controls.hidden = false;
    render();
  }

  document.addEventListener('DOMContentLoaded', function () {
    var table = document.querySelector('[data-order-api-viewer-table]');

    if (table) {
      init(table);
    }
  });
})();
