(function () {
  if (typeof window.svppAdminAjaxUrl !== 'string') return;

  // Minimal visibility during debugging: if something breaks, it won't fail silently.
  function safe(fn) {
    return function () {
      try {
        return fn.apply(this, arguments);
      } catch (e) {
        if (window.console && console.error) console.error('[svprivateproducts] autocomplete error', e);
      }
    };
  }

  function el(tag, attrs) {
    var n = document.createElement(tag);
    if (attrs) {
      Object.keys(attrs).forEach(function (k) {
        n.setAttribute(k, attrs[k]);
      });
    }
    return n;
  }

  function attachAutocomplete(opts) {
    var input = document.querySelector(opts.inputSelector);
    if (!input) return;
    var hidden = opts.hiddenSelector ? document.querySelector(opts.hiddenSelector) : null;

    // Render the dropdown in <body> to avoid BO containers with overflow/z-index issues.
    var box = el('div', { class: 'svpp-ac-box' });
    // Ensure it stays above BO overlays.
    box.style.zIndex = '2147483647';
    document.body.appendChild(box);

    var abort = null;
    var lastQ = '';
    var timer = null;

    function clearBox() {
      box.innerHTML = '';
      box.style.display = 'none';
    }

    function positionBox() {
      var r = input.getBoundingClientRect();
      box.style.left = String(Math.max(0, r.left + window.scrollX)) + 'px';
      box.style.top = String(r.bottom + window.scrollY + 2) + 'px';
      box.style.width = String(r.width) + 'px';
    }

    function render(items) {
      box.innerHTML = '';
      if (!items || !items.length) {
        clearBox();
        return;
      }
      positionBox();
      items.forEach(function (it) {
        var row = el('button', { type: 'button', class: 'svpp-ac-item' });
        row.textContent = it.label;
        row.addEventListener('click', function () {
          if (typeof opts.onSelect === 'function') {
            opts.onSelect(it, { input: input, hidden: hidden, clear: clearBox });
            return;
          }
          if (hidden) hidden.value = String(it.id);
          input.value = it.label;
          clearBox();
        });
        box.appendChild(row);
      });
      box.style.display = 'block';
    }

    function fetchItems(q) {
      if (abort) abort.abort();
      abort = new AbortController();

      var source = opts.source || opts.action;
      var url = window.svppAdminAjaxUrl + '&ajax=1&action=' + encodeURIComponent(opts.action) + '&q=' + encodeURIComponent(q) + '&source=' + encodeURIComponent(source);
      if (window.console && console.debug) {
        console.debug('[svprivateproducts] fetch', opts.action, source, q, url);
      }
      return fetch(url, { signal: abort.signal, credentials: 'same-origin' })
        .then(function (r) {
          if (window.console && console.debug) {
            console.debug('[svprivateproducts] resp', opts.action, source, r.status, r.headers.get('content-type'));
          }
          var ct = (r.headers.get('content-type') || '').toLowerCase();
          if (ct.indexOf('application/json') !== -1) {
            return r.json();
          }
          return r.text().then(function (t) {
            throw new Error('Non-JSON response: ' + r.status + ' ' + ct + ' :: ' + t.slice(0, 400));
          });
        })
        .then(function (j) {
          if (window.console && console.debug) {
            console.debug('[svprivateproducts] json', opts.action, source, j);
          }
          render(j && j.items ? j.items : []);
        })
        .catch(function (e) {
          // Ignore abort errors (typing fast).
          if (e && (e.name === 'AbortError' || String(e.message || '').indexOf('aborted') !== -1)) {
            return;
          }
          if (window.console && console.error) {
            console.error('[svprivateproducts] fetch error', opts.action, source, e);
          }
        });
    }

    input.addEventListener('input', safe(function () {
      var q = (input.value || '').trim();
      if (q.length < (opts.minChars || 2)) {
        if (hidden) hidden.value = '';
        clearBox();
        return;
      }
      if (q === lastQ) return;
      lastQ = q;
      if (hidden) hidden.value = '';
      if (timer) window.clearTimeout(timer);
      timer = window.setTimeout(function () {
        fetchItems(q);
      }, opts.delay || 250);
    }));

    window.addEventListener('scroll', function () {
      if (box.style.display === 'block') positionBox();
    }, true);
    window.addEventListener('resize', function () {
      if (box.style.display === 'block') positionBox();
    });

    input.addEventListener('blur', function () {
      setTimeout(clearBox, 200);
    });
  }

  function init() {
    if (window.console && console.debug) {
      console.debug('[svprivateproducts] admin autocomplete init', {
        ajaxUrl: window.svppAdminAjaxUrl,
        trace: window.svppTrace,
      });
    }

    // Quick ping so you can see something in Network.
    if (window.svppTrace === 1) {
      fetch(window.svppAdminAjaxUrl + '&ajax=1&action=svppPing', { credentials: 'same-origin' })
        .then(function (r) { return r.text(); })
        .then(function (t) { console.debug('[svprivateproducts] ping', t); })
        .catch(function (e) { console.error('[svprivateproducts] ping error', e); });
    }

    // Assign UI (single product)
    attachAutocomplete({
      inputSelector: 'input[name="svpp_ui_product_search"]',
      hiddenSelector: 'input[name="svpp_assign_id_product"]',
      action: 'svppSearchProduct',
      source: 'private_product',
    });

    // Customers multi-select (chips)
    (function () {
      var inputSel = 'input[name="svpp_ui_customer_search"]';
      var input = document.querySelector(inputSel);
      if (!input) return;

      var chips = document.querySelector('.svpp-chips');
      var hiddenBox = document.querySelector('.svpp-hidden[data-name="svpp_assign_customers"]');
      if (!chips || !hiddenBox) return;

      function hasId(id) {
        return !!hiddenBox.querySelector('input[value="' + String(id) + '"]');
      }

      function addChip(it) {
        if (hasId(it.id)) return;

        var chip = el('span', { class: 'svpp-chip' });
        chip.textContent = it.label;
        var x = el('button', { type: 'button', class: 'svpp-chip-x' });
        x.textContent = 'x';
        x.addEventListener('click', function () {
          chip.remove();
          var h = hiddenBox.querySelector('input[value="' + String(it.id) + '"]');
          if (h) h.remove();
        });
        chip.appendChild(x);
        chips.appendChild(chip);

        var h = el('input', { type: 'hidden', name: 'svpp_assign_customers[]', value: String(it.id) });
        hiddenBox.appendChild(h);

        input.value = '';
      }

      attachAutocomplete({
        inputSelector: inputSel,
        action: 'svppSearchCustomer',
        minChars: 3,
        delay: 300,
        onSelect: function (it, ctx) {
          addChip(it);
          ctx.clear();
        },
      });
    })();

    // Redirect product
    attachAutocomplete({
      inputSelector: 'input[name="svpp_ui_assign_redirect_product_search"]',
      hiddenSelector: 'input[name="svpp_assign_redirect_id_product"]',
      action: 'svppSearchProduct',
      source: 'private_redirect',
    });
  }

  // In PS8 back office some pages load scripts after DOMContentLoaded.
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
