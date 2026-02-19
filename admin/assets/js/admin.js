/**
 * Ovesio WordPress Admin JS
 */

/* ============================================================
   Shared AJAX helpers
   ============================================================ */

function ovesioAjaxSubmitForm(form) {
  form.querySelectorAll('.ov-alert').forEach(function(alert) { alert.remove(); });

  var fields = jQuery(form).serialize();

  var btn = form.querySelector('button[type="submit"]');
  if (!btn) {
    btn = document.querySelector('#ovesioModal #btn_save_modal');
  }

  var originalBtnHtml = btn ? btn.innerHTML : '';

  return jQuery.ajax({
    url: form.action,
    type: form.method || 'POST',
    dataType: 'json',
    data: fields,
    beforeSend: function() {
      if (btn) {
        btn.classList.add('ov-btn-loading');
        btn.innerHTML = '<span class="ov-spinner ov-spinner-sm"></span> ' + (window.ovesioAdmin && window.ovesioAdmin.saving ? window.ovesioAdmin.saving : 'Saving...');
      }
    },
    complete: function() {
      if (btn) {
        btn.classList.remove('ov-btn-loading');
        btn.innerHTML = originalBtnHtml;
      }
    },
    error: function(xhr) {
      if (xhr.status === 422 && xhr.responseJSON && xhr.responseJSON.errors) {
        for (var field in xhr.responseJSON.errors) {
          if (!xhr.responseJSON.errors.hasOwnProperty(field)) continue;
          var message = xhr.responseJSON.errors[field];
          var parts = field.split('.');
          if (parts.length > 1) {
            field = parts[0] + '[' + parts.slice(1).join('][') + ']';
          }
          var input = form.querySelector('[name="' + field + '"]');
          if (input) {
            var existing = input.parentNode.querySelector('.ov-error-message');
            if (existing) existing.parentNode.removeChild(existing);
            var errorNode = document.createElement('div');
            errorNode.classList.add('ov-error-message');
            errorNode.innerText = message;
            input.parentNode.appendChild(errorNode);
          }
        }
      }
    }
  });
}

function ovesioAjaxGet(url, data, dataType) {
  return jQuery.ajax({
    url: url,
    type: 'GET',
    data: data || {},
    dataType: dataType || 'json'
  });
}

function ovesioAjaxPost(url, data) {
  return jQuery.ajax({
    url: url,
    type: 'POST',
    dataType: 'json',
    data: data || {}
  });
}

function ovesioBuildAlert(message, type, size) {
  type = type || 'info';
  size = size || 'sm';
  var html = '<div class="ov-alert ov-alert-' + size + ' ov-alert-' + type + ' ov-mb-3" role="alert">' + message + '</div>';
  var div = document.createElement('div');
  div.innerHTML = html.trim();
  return div.firstChild;
}

/* ============================================================
   ovesioWP namespace (WordPress admin)
   ============================================================ */

window.ovesioWP = {};

ovesioWP.buildAlert = ovesioBuildAlert;

/**
 * Open the Ovesio modal with content loaded from a URL
 */
ovesioWP.modalButton = function(e) {
  e.preventDefault();

  var btn   = e.target;
  var url   = btn.getAttribute('data-url');
  var title = btn.getAttribute('data-title');

  ovesioAjaxGet(url, {}, 'html').then(function(res) {
    ovesioWP.openModal(res, title);
  });
};

ovesioWP.openModal = function(content, title) {
  var modal        = document.getElementById('ovesioModal');
  var modalTitle   = document.getElementById('modalTitle');
  var modalContent = document.getElementById('modalContent');

  if (!modal) return;
  modalTitle.innerHTML  = title || '';
  modalContent.innerHTML = content;
  modal.style.display   = 'block';
};

ovesioWP.closeModal = function() {
  var modal = document.getElementById('ovesioModal');
  if (modal) modal.style.display = 'none';
};

ovesioWP.saveModal = function() {
  var modal = document.getElementById('ovesioModal');
  if (!modal) return;
  var form = modal.querySelector('form');
  if (form) {
    form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
  }
};

/* Close modal when clicking backdrop */
document.addEventListener('mousedown', function(event) {
  var modal = document.getElementById('ovesioModal');
  if (modal && event.target === modal) {
    setTimeout(function() { ovesioWP.closeModal(); }, 150);
  }
});

/**
 * Disconnect from Ovesio API
 */
ovesioWP.disconnectApi = function(e) {
  e.preventDefault();

  var btn            = e.target;
  var originalHtml   = btn.innerHTML;
  var url            = btn.getAttribute('data-url');
  var confirmMessage = btn.getAttribute('data-confirm');

  if (confirmMessage && !confirm(confirmMessage)) {
    return;
  }

  btn.classList.add('ov-btn-loading');
  btn.innerHTML = '<span class="ov-spinner ov-spinner-sm"></span> ...';

  ovesioAjaxPost(url, {}).then(function(res) {
    btn.classList.remove('ov-btn-loading');
    btn.innerHTML = originalHtml;

    if (res.success) {
      // Reload page to reflect disconnected state
      window.location.reload();
    } else {
      var alertNode = ovesioBuildAlert(res.data || 'Error disconnecting', 'danger');
      var container = document.getElementById('ovesio_feedback_container');
      if (container) container.appendChild(alertNode);
    }
  }).fail(function() {
    btn.classList.remove('ov-btn-loading');
    btn.innerHTML = originalHtml;
  });
};

/**
 * Save Generate Content form (modal)
 */
ovesioWP.generateContentFormSave = function(e) {
  e.preventDefault();

  var form = e.target;

  ovesioAjaxSubmitForm(form).then(function(res) {
    if (res && res.success) {
      var data = res.data || {};

      if (data.card_html) {
        var card = document.getElementById('generate_content_card');
        if (card) {
          var tmp = document.createElement('div');
          tmp.innerHTML = data.card_html.trim();
          card.parentNode.replaceChild(tmp.firstChild, card);
        }
      }

      // Show feedback inside card (card may have been replaced, re-query)
      var feedbackContainer = document.querySelector('#generate_content_card .ov-feedback-container');
      if (feedbackContainer) {
        var alertNode = ovesioBuildAlert(data.message || res.data || 'Saved.', 'success');
        feedbackContainer.appendChild(alertNode);
        setTimeout(function() { alertNode.remove(); }, 5000);
      }
    }

    ovesioWP.closeModal();
  });
};

/**
 * Save Generate SEO form (modal)
 */
ovesioWP.generateSeoFormSave = function(e) {
  e.preventDefault();

  var form = e.target;

  ovesioAjaxSubmitForm(form).then(function(res) {
    if (res && res.success) {
      var data = res.data || {};

      if (data.card_html) {
        var card = document.getElementById('generate_seo_card');
        if (card) {
          var tmp = document.createElement('div');
          tmp.innerHTML = data.card_html.trim();
          card.parentNode.replaceChild(tmp.firstChild, card);
        }
      }

      var feedbackContainer = document.querySelector('#generate_seo_card .ov-feedback-container');
      if (feedbackContainer) {
        var alertNode = ovesioBuildAlert(data.message || res.data || 'Saved.', 'success');
        feedbackContainer.appendChild(alertNode);
        setTimeout(function() { alertNode.remove(); }, 5000);
      }
    }

    ovesioWP.closeModal();
  });
};

/**
 * Save Translation settings form (modal)
 */
ovesioWP.translateFormSave = function(e) {
  e.preventDefault();

  var form = e.target;

  ovesioAjaxSubmitForm(form).then(function(res) {
    if (res && res.success) {
      var data = res.data || {};

      if (data.card_html) {
        var card = document.getElementById('translate_card');
        if (card) {
          var tmp = document.createElement('div');
          tmp.innerHTML = data.card_html.trim();
          card.parentNode.replaceChild(tmp.firstChild, card);
        }
      }

      var feedbackContainer = document.querySelector('#translate_card .ov-feedback-container');
      if (feedbackContainer) {
        var alertNode = ovesioBuildAlert(data.message || res.data || 'Saved.', 'success');
        feedbackContainer.appendChild(alertNode);
        setTimeout(function() { alertNode.remove(); }, 5000);
      }
    }

    ovesioWP.closeModal();
  });
};

/* ============================================================
   WordPress jQuery admin handlers (on document ready)
   ============================================================ */

jQuery(document).ready(function($) {

  /* ----------------------------------------------------------
     Auto-refresh when pending translations exist
     ---------------------------------------------------------- */

  function initPendingAutoRefresh() {
    var config = window.ovesioAdmin || {};
    if (!config.autoRefreshPending) return;
    if (!$('.ovesio-pending-translations').length) return;

    var refreshInterval = parseInt(config.refreshInterval, 10) || 30;
    var remaining       = refreshInterval;

    var noticeId      = 'ovesio-pending-refresh-notice';
    var counterClass  = 'ovesio-pending-refresh-counter';
    var label         = config.countdownLabel || 'Refreshing in';
    var secondsLabel  = config.secondsLabel   || 'seconds';

    var $notice = $('<div id="' + noticeId + '" class="notice notice-info inline"><p></p></div>');

    var $targetWrap = $('#wpbody-content .wrap').first();
    if ($targetWrap.length) $targetWrap.prepend($notice);

    $('.ovesio-pending-translations .ovesio-pending-label').each(function() {
      var $lbl = $(this);
      if (!$lbl.find('.' + counterClass).length) {
        $lbl.append('<small class="' + counterClass + '"></small>');
      }
    });

    function renderCountdown() {
      var text = label + ' ' + remaining + ' ' + secondsLabel + '.';
      $notice.find('p').text(text);
      $('.' + counterClass).text('(' + text + ')');
    }

    renderCountdown();

    var timer = setInterval(function() {
      remaining -= 1;
      if (remaining <= 0) {
        clearInterval(timer);
        window.location.reload();
        return;
      }
      renderCountdown();
    }, 1000);
  }

  /* ----------------------------------------------------------
     Translation row action (AJAX)
     ---------------------------------------------------------- */

  $(document).on('click', '.ovesio-translate-ajax-request', function(e) {
    e.preventDefault();

    var $link = $(this);

    $.ajax({
      url: $link.attr('href') + '&translate=true',
      beforeSend: function() {
        $('.ovesio-loader-overlay-container').show();
      },
      success: function() {
        $('.ovesio-loader-overlay-container').hide();
        window.location.reload();
      },
      error: function(xhr) {
        $('.ovesio-loader-overlay-container').hide();

        var errorMessage = 'Request failed';
        if (xhr.responseJSON && xhr.responseJSON.data) {
          errorMessage = xhr.responseJSON.data;
        }
        alert(errorMessage);
      }
    });
  });

  /* ----------------------------------------------------------
     Generate Content row action (AJAX)
     ---------------------------------------------------------- */

  $(document).on('click', '.ovesio-generate-ajax-request', function(e) {
    e.preventDefault();

    var $link = $(this);
    var originalText = $link.text();

    $link.text('...');

    $.ajax({
      url: $link.attr('href'),
      dataType: 'json',
      success: function(response) {
        $link.text(originalText);

        if (response && response.success) {
          // Show pending badge in place of this link
          $link.closest('span').html('<span class="ovesio-pending-translations"><span class="ovesio-pending-label">Content: Pending</span></span>');
        } else {
          var msg = (response && response.data) ? response.data : 'Error generating content';
          alert(msg);
        }
      },
      error: function(xhr) {
        $link.text(originalText);

        var errorMessage = 'Request failed';
        if (xhr.responseJSON && xhr.responseJSON.data) {
          errorMessage = xhr.responseJSON.data;
        }
        alert(errorMessage);
      }
    });
  });

  /* ----------------------------------------------------------
     Generate SEO row action (AJAX)
     ---------------------------------------------------------- */

  $(document).on('click', '.ovesio-generate-seo-ajax-request', function(e) {
    e.preventDefault();

    var $link = $(this);
    var originalText = $link.text();

    $link.text('...');

    $.ajax({
      url: $link.attr('href'),
      dataType: 'json',
      success: function(response) {
        $link.text(originalText);

        if (response && response.success) {
          $link.closest('span').html('<span class="ovesio-pending-translations"><span class="ovesio-pending-label">SEO: Pending</span></span>');
        } else {
          var msg = (response && response.data) ? response.data : 'Error generating SEO';
          alert(msg);
        }
      },
      error: function(xhr) {
        $link.text(originalText);

        var errorMessage = 'Request failed';
        if (xhr.responseJSON && xhr.responseJSON.data) {
          errorMessage = xhr.responseJSON.data;
        }
        alert(errorMessage);
      }
    });
  });

  /* ----------------------------------------------------------
     Legacy API engine selection (settings page)
     ---------------------------------------------------------- */

  var selectedEngine = $('#ovesio_api_form_table #ovesio_api_engine');
  if (selectedEngine.length) {
    var initial = selectedEngine.val();
    $('#ovesio_api_form_table .section[data-section="' + initial + '"] input.required').attr('required', 'required');
    $('#ovesio_api_form_table .section[data-section="' + initial + '"]').show();

    selectedEngine.on('change', function() {
      var selected = $(this).val();
      $('#ovesio_api_form_table .section input.required').removeAttr('required');
      $('#ovesio_api_form_table .section[data-section="' + selected + '"] input.required').attr('required', 'required');
      $('#ovesio_api_form_table .section').hide();
      $('#ovesio_api_form_table .section[data-section="' + selected + '"]').show();
    });
  }

  /* ----------------------------------------------------------
     Range input value display
     ---------------------------------------------------------- */

  $('#ovesio_api_form_table input[type="range"]').on('input', function() {
    var value = $(this).val();
    $(this).next('.range-value').text(value);
  });

  /* ----------------------------------------------------------
     Initialize auto-refresh
     ---------------------------------------------------------- */

  initPendingAutoRefresh();
});
