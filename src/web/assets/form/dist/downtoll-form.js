/**
 * Downtoll — shipped front-end for the render() form.
 *
 * Wires each `.c-block-gated-form[data-endpoint]`: reCAPTCHA token (if present),
 * a JSON submit to the plugin controller, inline field errors, swap/reload
 * success, and the affiliation → district toggle. Dependency-free, idempotent.
 *
 * The consuming site owns styling and may replace this wholesale; it only relies
 * on the markup + response contract, both stable.
 */
(function () {
  'use strict';

  function init(root) {
    if (root.hasAttribute('data-downtoll-init')) return;
    root.setAttribute('data-downtoll-init', '');

    var form = root.querySelector('.c-block-gated-form--form');
    if (!form) return;

    var endpoint = root.getAttribute('data-endpoint');
    var mode = root.getAttribute('data-mode') || 'swap';
    var successBox = root.querySelector('.m-success');
    var failureBox = root.querySelector('.m-failure');
    var submitBtn = form.querySelector('[type="submit"]');

    wireDistrictToggle(form);
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      submit();
    });

    function submit() {
      clearErrors();
      if (failureBox) failureBox.hidden = true;
      setBusy(true);

      recaptchaToken(form).then(function (token) {
        var recaptcha = form.querySelector('.c-block-gated-form--recaptcha');
        if (recaptcha && token) recaptcha.value = token;

        return fetch(endpoint, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
          },
          body: JSON.stringify(serialize(form))
        });
      }).then(function (res) {
        return res.json().then(function (data) {
          return { status: res.status, data: data || {} };
        });
      }).then(function (r) {
        handleResponse(r.status, r.data);
      }).catch(function () {
        showFailure();
      }).then(function () {
        setBusy(false);
      });
    }

    function handleResponse(status, data) {
      if (status >= 200 && status < 300 && data.success) {
        if ((data.mode || mode) === 'reload') {
          window.location.reload();
          return;
        }
        // swap: hide the form, reveal the download.
        form.hidden = true;
        if (successBox) {
          successBox.hidden = false;
          var link = successBox.querySelector('[data-gc-download]');
          if (link && data.downloadUrl) link.setAttribute('href', data.downloadUrl);
        }
        return;
      }

      if (status === 422 && data.errors) {
        renderErrors(data.errors);
        return;
      }

      showFailure(data.error);
    }

    function showFailure(message) {
      if (!failureBox) return;
      if (message) {
        var text = failureBox.querySelector('.c-block-gated-form--message-text');
        if (text && !text.textContent.trim()) text.textContent = message;
      }
      failureBox.hidden = false;
    }

    function setBusy(busy) {
      if (!submitBtn) return;
      submitBtn.disabled = busy;
      submitBtn.setAttribute('aria-busy', busy ? 'true' : 'false');
    }

    // --- field errors -----------------------------------------------------

    function clearErrors() {
      form.querySelectorAll('.c-block-gated-form--error').forEach(function (el) {
        el.remove();
      });
      form.querySelectorAll('[aria-invalid]').forEach(function (el) {
        el.removeAttribute('aria-invalid');
      });
    }

    function renderErrors(errors) {
      var first = null;
      Object.keys(errors).forEach(function (key) {
        var input = fieldByServerKey(form, key);
        var msg = document.createElement('p');
        msg.className = 'c-block-gated-form--error';
        msg.setAttribute('role', 'alert');
        msg.textContent = errors[key];

        if (input) {
          input.setAttribute('aria-invalid', 'true');
          var field = input.closest('.c-block-gated-form--field') || input.parentNode;
          field.appendChild(msg);
          if (!first) first = input;
        } else {
          // No matching input (unexpected key) — surface it at the top so it's never lost.
          form.insertBefore(msg, form.firstChild);
        }
      });
      if (first && first.focus) first.focus();
    }

    // --- helpers ----------------------------------------------------------

    function wireDistrictToggle(form) {
      var aff = form.querySelector('[name="affiliation"]');
      var district = form.querySelector('[data-gc-district]');
      if (!aff || !district) return;
      var sync = function () {
        var opt = aff.options[aff.selectedIndex];
        var on = opt && opt.getAttribute('data-gc-trigger') === '1';
        district.classList.toggle('m-hide', !on);
        district.hidden = !on;
      };
      aff.addEventListener('change', sync);
      sync();
    }
  }

  /** Build the JSON body. Checkboxes named `x[]` collapse to an `x` array. */
  function serialize(form) {
    var out = {};
    Array.prototype.forEach.call(form.elements, function (el) {
      if (!el.name || el.disabled) return;
      if ((el.type === 'checkbox' || el.type === 'radio') && !el.checked) return;

      var name = el.name;
      if (name.slice(-2) === '[]') {
        var key = name.slice(0, -2);
        (out[key] = out[key] || []).push(el.value);
      } else {
        out[name] = el.value;
      }
    });
    return out;
  }

  /**
   * reCAPTCHA v3 token, best-effort. Resolves '' when grecaptcha is absent, the
   * site key is empty, or anything throws — the server skips verification when it
   * has no secret, and a missing token must never block submit.
   */
  function recaptchaToken(form) {
    var input = form.querySelector('.c-block-gated-form--recaptcha');
    var siteKey = input && input.getAttribute('data-parameter');
    if (!siteKey || typeof window.grecaptcha === 'undefined') {
      return Promise.resolve('');
    }
    return new Promise(function (resolve) {
      try {
        window.grecaptcha.ready(function () {
          window.grecaptcha.execute(siteKey, { action: 'downtoll' })
            .then(resolve, function () { resolve(''); });
        });
      } catch (e) {
        resolve('');
      }
    });
  }

  /**
   * Map a server error key back to its input. The server keys are Title-Case
   * ('First Name'); the inputs are kebab ('first-name'). Try the raw key, a
   * kebab form, and email special-case.
   */
  function fieldByServerKey(form, key) {
    var kebab = key.toLowerCase().replace(/\s+/g, '-');
    return form.querySelector('[name="' + cssEscape(key) + '"]')
      || form.querySelector('[name="' + cssEscape(kebab) + '"]')
      || null;
  }

  function cssEscape(s) {
    return String(s).replace(/["\\]/g, '\\$&');
  }

  function boot() {
    document.querySelectorAll('.c-block-gated-form[data-endpoint]').forEach(init);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
