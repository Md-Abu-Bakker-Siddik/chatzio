/**
 * Chatzio - Admin JavaScript
 */

(function ($) {
  "use strict";

  $(document).ready(function () {
    initTabs();
    initColorPicker();
    initLogoUpload();
    initContentSync();
    initResourceUpload();
    initResourcePaste();
    initResourceTabs();
    initApiTest();
    initResourceDelete();
    initToastAutoDismiss();
    initRangeSliders();
    initCountUpStats();
    initDatePicker();
    initConversationStarters();
    initNewsItems();
    initWidgetModeToggle();
    initHomeBlockCollapse();
    initFaqItems();
    initTabIconPicker();
    initSetupWizard();
    initPluginReset();
  });

  // Tab Navigation — persist tab across save redirect
  function initTabs() {
    var tabKey = "chatzio_settings_tab";
    var subTabKey = "chatzio_settings_subtab";

    function activateTab(tabId) {
      if (!tabId || tabId === "#") return;
      var $tab = $('.nav-tab[href="' + tabId + '"]');
      var $content = $(tabId);
      if (!$tab.length || !$content.length) return;
      $(".nav-tab").removeClass("nav-tab-active");
      $tab.addClass("nav-tab-active");
      $(".tab-content").removeClass("active");
      $content.addClass("active");
    }

    function saveTab(tabId) {
      if (tabId && tabId.charAt(0) === "#") {
        try {
          sessionStorage.setItem(tabKey, tabId);
        } catch (e) {}
      }
    }

    function saveSubTab() {
      try {
        var $activeSection = $(".widget-layout-content .wl-section.active");
        if ($activeSection.length) {
          sessionStorage.setItem(subTabKey, $activeSection.attr("id"));
        }
      } catch (e) {}
    }

    // Restore tab after page load (saved before form submit)
    try {
      var saved = sessionStorage.getItem(tabKey);
      if (saved) {
        activateTab(saved);
        sessionStorage.removeItem(tabKey);
      }
    } catch (e) {}

    $(".nav-tab").on("click", function (e) {
      e.preventDefault();
      var targetTab = $(this).attr("href");
      activateTab(targetTab);
      saveTab(targetTab);
    });

    // Save current tab + sub-tab before form submit
    $(".chatzio-form").on("submit", function () {
      var $active = $(".tab-content.active");
      if ($active.length) {
        saveTab("#" + $active.attr("id"));
      }
      saveSubTab();
    });
  }

  // Coloris color picker initialization
  function initColorPicker() {
    if (typeof Coloris === "undefined" || typeof Coloris !== "function") return;

    try {
      // Create swatches FIRST, before Coloris initializes
      $(".chatzio-color-field").each(function () {
        var $field = $(this);
        var $input = $field.find(".chatzio-color-input");
        if (!$input.length) return;

        var val = $input.val() || "#4F46E5";

        // Add swatch at the start of the field (before input)
        if (!$field.find(".sc-swatch").length) {
          $field.prepend(
            '<span class="sc-swatch" style="background:' + val + '"></span>',
          );
        }

        // Update swatch when input changes
        $input.on("input change", function () {
          $field.find(".sc-swatch").css("background", $(this).val());
        });
      });

      // Now initialize Coloris
      if (typeof Coloris.init === "function") {
        Coloris.init();
      }
      Coloris({
        el: ".chatzio-color-input",
        theme: "polaroid",
        themeMode: "light",
        alpha: false,
        formatToggle: false,
        wrap: false,
        preview: false,
        swatches: [
          "#4F46E5",
          "#6366F1",
          "#2563EB",
          "#7C3AED",
          "#EC4899",
          "#EF4444",
          "#F59E0B",
          "#10B981",
          "#06B6D4",
          "#0EA5E9",
          "#1E293B",
          "#FFFFFF",
        ],
      });
    } catch (e) {
      console.warn("Chatzio: Coloris color picker failed to initialize", e);
    }
  }

  // Date Picker (Flatpickr — lightweight, like Coloris for colors)
  function initDatePicker() {
    if (typeof flatpickr === "undefined") return;

    try {
      var dateInputs = document.querySelectorAll(
        '.filters-form input[type="date"]',
      );
      if (!dateInputs.length) return;

      dateInputs.forEach(function (input) {
        var existingValue = input.value;

        // Change type to text so Flatpickr controls the UI
        input.type = "text";
        input.setAttribute("autocomplete", "off");
        input.setAttribute("placeholder", "dd/mm/yyyy");
        input.setAttribute("readonly", "readonly");

        flatpickr(input, {
          dateFormat: "Y-m-d",
          altInput: true,
          altFormat: "d/m/Y",
          allowInput: false,
          disableMobile: true,
          defaultDate: existingValue || null,
          animate: true,
          appendTo: document.body,
          onReady: function (selectedDates, dateStr, instance) {
            // Set placeholder on the visible alt input
            if (instance.altInput) {
              instance.altInput.setAttribute("placeholder", "dd/mm/yyyy");
              instance.altInput.classList.add("chatzio-flatpickr-input");
            }
          },
        });
      });
    } catch (e) {
      console.warn("Chatzio: Flatpickr date picker failed to initialize", e);
    }
  }

  // Logo Upload
  function initLogoUpload() {
    let mediaUploader;

    function openMediaUploader() {
      if (mediaUploader) {
        mediaUploader.open();
        return;
      }

      mediaUploader = wp.media({
        title: "Choose Logo",
        button: {
          text: "Use this image",
        },
        multiple: false,
      });

      mediaUploader.on("select", function () {
        const attachment = mediaUploader
          .state()
          .get("selection")
          .first()
          .toJSON();
        $("#logo-url").val(attachment.url);

        // Update preview inside the box
        const $box = $("#logo-upload-box");
        $box.html(`
          <img src="${attachment.url}" alt="Logo Preview" class="logo-preview-img">
          <div class="logo-upload-overlay">
            <span class="dashicons dashicons-update"></span>
            <span>Change Logo</span>
          </div>
        `);
      });

      mediaUploader.open();
    }

    // Click handler for the upload box
    $("#logo-upload-box").on("click", function (e) {
      e.preventDefault();
      openMediaUploader();
    });
  }

  // Content Sync
  function initContentSync() {
    var $btn = $("#sync-content-btn");
    if (!$btn.length) return;

    $btn.on("click", function () {
      var $btn = $(this);
      var $progress = $("#sync-progress");
      var $progressFill = $progress.find(".progress-fill");
      var $progressText = $progress.find(".progress-text");
      var $result = $("#sync-result");
      var progressTimer = null;
      var pct = 0;
      var syncDone = false;

      $btn.prop("disabled", true);
      $result.html("");

      // Reset and show progress
      $progressFill.css("width", "0%");
      $progressText.text("Starting sync...");
      $progress.show();

      // Phases give a natural feel (fast start, slow middle, pause near end)
      var phases = [
        { target: 30, step: 3, delay: 60, label: "Syncing pages and posts..." },
        { target: 60, step: 2, delay: 80, label: "Processing products..." },
        { target: 80, step: 1, delay: 120, label: "Embedding content..." },
        { target: 92, step: 0.5, delay: 200, label: "Generating AI prompt..." },
      ];
      var phase = 0;

      function tick() {
        if (syncDone) return;
        var p = phases[phase];
        pct += p.step;
        if (pct >= p.target) {
          pct = p.target;
          phase++;
          if (phase >= phases.length) {
            $progressFill.css("width", pct + "%");
            return; // stay at 92% until server responds
          }
          $progressText.text(phases[phase].label);
        }
        $progressFill.css("width", pct + "%");
        progressTimer = setTimeout(tick, p.delay);
      }

      // Kick off after a brief pause so the bar is visible at 0% first
      progressTimer = setTimeout(function () {
        $progressText.text(phases[0].label);
        tick();
      }, 150);

      $.ajax({
        url: chatzioAdmin.ajaxUrl,
        type: "POST",
        timeout: 180000,
        data: {
          action: "chatzio_sync_content",
          nonce: chatzioAdmin.nonce,
        },
        success: function (response) {
          if (response.success && response.data && response.data.results) {
            var results = response.data.results;
            var removedText =
              results.removed && results.removed > 0
                ? " | Removed: " + results.removed + " stale"
                : "";
            var chunksText =
              results.chunks_embedded !== undefined &&
              results.chunks_created !== undefined
                ? " | Chunks embedded: " +
                  results.chunks_embedded +
                  " / " +
                  results.chunks_created
                : "";
            var promptText = results.generated_prompt
              ? ' | <a href="' +
                (chatzioAdmin.settingsUrl || "#") +
                '" class="chatzio-link">AI prompt generated — view in Settings</a>'
              : "";
            var errorsHtml =
              results.errors && results.errors.length > 0
                ? '<p class="chatzio-sync-errors" style="margin-top:8px;">' +
                  results.errors
                    .map(function (e) {
                      return escHtml(e);
                    })
                    .join("<br>") +
                  "</p>"
                : "";
            $result.html(
              '<div class="chatzio-alert chatzio-alert-success">' +
                "<strong>✓ Sync Complete!</strong> " +
                "<span>Posts: " +
                (results.posts || 0) +
                " | Pages: " +
                (results.pages || 0) +
                " | Products: " +
                (results.products || 0) +
                removedText +
                chunksText +
                promptText +
                "</span>" +
                errorsHtml +
                "</div>",
            );

            // Reload page after 2 seconds
            setTimeout(function () {
              location.reload();
            }, 2000);
          } else {
            var msg =
              response.data && response.data.message
                ? response.data.message
                : "Unknown error";
            $result.html(`
                            <div class="chatzio-alert chatzio-alert-error">
                                <strong>✗ Sync Failed</strong>
                                <span>${escHtml(msg)}</span>
                            </div>
                        `);
          }
        },
        error: function () {
          $result.html(`
                        <div class="chatzio-alert chatzio-alert-error">
                            <strong>✗ Connection Error</strong>
                            <span>Please try again.</span>
                        </div>
                    `);
        },
        complete: function () {
          syncDone = true;
          if (progressTimer) {
            clearTimeout(progressTimer);
            progressTimer = null;
          }

          // Animate to 100% so user sees it finish
          $progressText.text("Done!");
          $progressFill.css("width", "100%");

          // Keep visible for 800ms, then hide
          setTimeout(function () {
            $progress.fadeOut(300, function () {
              $progressFill.css("width", "0%");
            });
          }, 800);

          $btn.prop("disabled", false);
        },
      });
    });
  }

  // Resource Upload
  function initResourceUpload() {
    var fileInput = document.getElementById("resource-file-input");
    var selectBtn = document.getElementById("select-file-btn");
    var uploadArea = document.getElementById("upload-area");

    // Exit if elements don't exist (not on resources page)
    if (!fileInput || !selectBtn) {
      return;
    }

    // Click handler for button - use native click
    selectBtn.addEventListener("click", function (e) {
      e.preventDefault();
      e.stopPropagation();
      fileInput.click();
    });

    // Click handler for upload area
    if (uploadArea) {
      uploadArea.addEventListener("click", function (e) {
        // Don't trigger if clicking on button
        if (e.target === selectBtn || selectBtn.contains(e.target)) {
          return;
        }
        fileInput.click();
      });

      // Drag and drop
      uploadArea.addEventListener("dragover", function (e) {
        e.preventDefault();
        e.stopPropagation();
        this.classList.add("drag-over");
        this.style.borderColor = "#4F46E5";
      });

      uploadArea.addEventListener("dragleave", function (e) {
        e.preventDefault();
        e.stopPropagation();
        this.classList.remove("drag-over");
        this.style.borderColor = "#D1D5DB";
      });

      uploadArea.addEventListener("drop", function (e) {
        e.preventDefault();
        e.stopPropagation();
        this.classList.remove("drag-over");
        this.style.borderColor = "#D1D5DB";

        var files = e.dataTransfer.files;
        if (files.length > 0) {
          uploadResource(files[0]);
        }
      });
    }

    // File input change handler
    fileInput.addEventListener("change", function () {
      if (this.files.length > 0) {
        uploadResource(this.files[0]);
      }
    });
  }

  function uploadResource(file) {
    const $progress = $("#upload-progress");
    const $result = $("#upload-result");
    const $uploadArea = $("#upload-area");
    const formData = new FormData();

    // Validate file size (10MB max)
    const maxSize = 10 * 1024 * 1024;
    if (file.size > maxSize) {
      showUploadResult("error", "File too large. Maximum size is 10MB.");
      return;
    }

    // Validate file type
    const allowedTypes = ["pdf", "doc", "docx", "txt"];
    const fileExt = file.name.split(".").pop().toLowerCase();
    if (!allowedTypes.includes(fileExt)) {
      showUploadResult(
        "error",
        "Invalid file type. Allowed: PDF, DOC, DOCX, TXT",
      );
      return;
    }

    formData.append("action", "chatzio_upload_resource");
    formData.append("nonce", chatzioAdmin.nonce);
    formData.append("resource_file", file);

    // Show progress
    $result.hide();
    $progress.show();
    $uploadArea.css("opacity", "0.5");

    $.ajax({
      url: chatzioAdmin.ajaxUrl,
      type: "POST",
      data: formData,
      processData: false,
      contentType: false,
      success: function (response) {
        if (response.success) {
          showUploadResult(
            "success",
            'Resource "' + file.name + '" uploaded successfully! Redirecting...',
          );
          setTimeout(function () {
            window.location.href = chatzioAdmin.adminUrl + "admin.php?page=chatzio-resources&tab=library";
          }, 1500);
        } else {
          // Access error from response.data
          const errorMsg =
            response.data && (response.data.error || response.data.message)
              ? response.data.error || response.data.message
              : "Unknown error occurred";
          showUploadResult("error", "Upload failed: " + errorMsg);
        }
      },
      error: function (xhr, status, error) {
        console.error("Upload error:", status, error, xhr.responseText);
        let errorMsg = "Upload failed. ";
        if (xhr.status === 413) {
          errorMsg += "File too large for server.";
        } else if (xhr.status === 0) {
          errorMsg += "Connection error. Check your internet.";
        } else {
          errorMsg += "Please try again. (Error: " + status + ")";
        }
        showUploadResult("error", errorMsg);
      },
      complete: function () {
        $progress.hide();
        $uploadArea.css("opacity", "1");
        $("#resource-file-input").val("");
      },
    });
  }

  function showUploadResult(type, message) {
    const $result = $("#upload-result");
    const icon = type === "success" ? "✓" : "✗";
    const className =
      type === "success"
        ? "chatzio-alert chatzio-alert-success"
        : "chatzio-alert chatzio-alert-error";

    $result
      .html(
        `<div class="${className}"><strong>${icon}</strong><span>${escHtml(message)}</span></div>`,
      )
      .show();

    // Auto-hide error after 10 seconds
    if (type === "error") {
      setTimeout(function () {
        $result.fadeOut();
      }, 10000);
    }
  }

  // API Test
  function initApiTest() {
    $("#test-api-btn").on("click", function () {
      const $btn = $(this);
      const $result = $("#test-api-result");

      $btn
        .prop("disabled", true)
        .html(
          '<span class="dashicons dashicons-update spin"></span> Testing...',
        );
      $result.html("");

      $.ajax({
        url: chatzioAdmin.ajaxUrl,
        type: "POST",
        data: {
          action: "chatzio_test_api",
          nonce: chatzioAdmin.nonce,
        },
        success: function (response) {
          if (response.success) {
            $result.html(`
                            <div class="chatzio-alert chatzio-alert-success">
                                <strong>✓ Connection Successful!</strong>
                                <span>API is working correctly.</span>
                            </div>
                        `);
          } else {
            $result.html(`
                            <div class="chatzio-alert chatzio-alert-error">
                                <strong>✗ Connection Failed</strong>
                                <span>${escHtml(response.data.error)}</span>
                            </div>
                        `);
          }
        },
        error: function () {
          $result.html(`
                        <div class="chatzio-alert chatzio-alert-error">
                            <strong>✗ Request Failed</strong>
                            <span>Please check your settings and try again.</span>
                        </div>
                    `);
        },
        complete: function () {
          $btn
            .prop("disabled", false)
            .html(
              '<span class="dashicons dashicons-yes-alt"></span> Test API Connection',
            );
        },
      });
    });
  }

  // Resource Tabs (Paste/Upload toggle)
  // Scoped to .upload-tabs parent to avoid colliding with main settings tabs
  function initResourceTabs() {
    $(".upload-tabs .tab-btn").on("click", function () {
      var targetTab = $(this).data("tab");
      var $container = $(this).closest(".upload-tabs").parent();

      // Update active button within this tab group only
      $(this).siblings(".tab-btn").removeClass("active");
      $(this).addClass("active");

      // Show target content within this section only
      $container.find(".resource-tab-content").removeClass("active").hide();
      $container
        .find("#" + targetTab)
        .addClass("active")
        .show();
    });
  }

  // Resource Paste Form
  function initResourcePaste() {
    $("#paste-resource-form").on("submit", function (e) {
      e.preventDefault();

      var $form = $(this);
      var $btn = $("#save-paste-btn");
      var $result = $("#paste-result");
      var title = $("#resource-title").val().trim();
      var content = $("#resource-content").val().trim();

      // Validate
      if (!title) {
        showPasteResult("error", "Please enter a resource title.");
        return;
      }
      if (!content) {
        showPasteResult("error", "Please paste some content.");
        return;
      }
      if (content.length < 50) {
        showPasteResult(
          "error",
          "Content is too short. Please paste at least 50 characters.",
        );
        return;
      }

      // Disable button
      $btn
        .prop("disabled", true)
        .html(
          '<span class="dashicons dashicons-update spin"></span> Saving...',
        );

      $.ajax({
        url: chatzioAdmin.ajaxUrl,
        type: "POST",
        data: {
          action: "chatzio_paste_resource",
          nonce: chatzioAdmin.nonce,
          title: title,
          content: content,
        },
        success: function (response) {
          if (response.success) {
            showPasteResult(
              "success",
              "Resource saved successfully! (" +
                response.data.content_length +
                " characters) Redirecting...",
            );
            setTimeout(function () {
              window.location.href = chatzioAdmin.adminUrl + "admin.php?page=chatzio-resources&tab=library";
            }, 1500);
          } else {
            var errorMsg =
              response.data && response.data.message
                ? response.data.message
                : "Unknown error occurred";
            showPasteResult("error", "Save failed: " + errorMsg);
          }
        },
        error: function (xhr, status, error) {
          showPasteResult("error", "Connection error. Please try again.");
        },
        complete: function () {
          $btn
            .prop("disabled", false)
            .html(
              '<span class="dashicons dashicons-saved"></span> Save Resource',
            );
        },
      });
    });
  }

  function showPasteResult(type, message) {
    var $result = $("#paste-result");
    var icon = type === "success" ? "✓" : "✗";
    var className =
      type === "success"
        ? "chatzio-alert chatzio-alert-success"
        : "chatzio-alert chatzio-alert-error";

    $result
      .html(
        '<div class="' +
          className +
          '"><strong>' +
          icon +
          "</strong><span>" +
          escHtml(message) +
          "</span></div>",
      )
      .show();

    if (type === "error") {
      setTimeout(function () {
        $result.fadeOut();
      }, 10000);
    }
  }

  // Resource Delete
  function initResourceDelete() {
    $(document).on("click", ".delete-resource-btn", function () {
      if (!confirm("Are you sure you want to delete this resource?")) {
        return;
      }

      const resourceId = $(this).data("resource-id");
      const $row = $(this).closest("tr");

      $.ajax({
        url: chatzioAdmin.ajaxUrl,
        type: "POST",
        data: {
          action: "chatzio_delete_resource",
          nonce: chatzioAdmin.nonce,
          resource_id: resourceId,
        },
        success: function (response) {
          if (response.success) {
            $row.fadeOut(function () {
              $(this).remove();
            });
          } else {
            alert("Failed to delete resource");
          }
        },
        error: function () {
          alert("Failed to delete resource");
        },
      });
    });
  }

  // Toast auto-dismiss after 4 seconds
  function initToastAutoDismiss() {
    $(".chatzio-toast").each(function () {
      var $toast = $(this);
      setTimeout(function () {
        $toast.css("animation", "chatzio-toast-out 0.3s forwards");
        setTimeout(function () {
          $toast.remove();
        }, 350);
      }, 4000);
    });
  }

  // Range sliders sync with display value
  function initRangeSliders() {
    $(document).on("input", ".chatzio-range-input", function () {
      var $display = $(this).siblings(".chatzio-range-value");
      var $hidden = $(this).siblings('input[type="hidden"]');
      var val = $(this).val();
      if ($display.length) $display.text(val);
      if ($hidden.length) $hidden.val(val);
    });
  }

  // Count-up animation for stat card numbers
  function initCountUpStats() {
    $(".stat-content h3").each(function () {
      var $el = $(this);
      var target = parseInt($el.text().replace(/,/g, ""), 10);
      if (isNaN(target) || target === 0) return;
      var duration = 600;
      var start = 0;
      var startTime = null;
      function step(timestamp) {
        if (!startTime) startTime = timestamp;
        var progress = Math.min((timestamp - startTime) / duration, 1);
        var eased = 1 - Math.pow(1 - progress, 3); // ease-out cubic
        $el.text(Math.floor(eased * target).toLocaleString());
        if (progress < 1) requestAnimationFrame(step);
        else $el.text(target.toLocaleString());
      }
      $el.text("0");
      requestAnimationFrame(step);
    });
  }

  /**
   * Escape HTML to prevent XSS when injecting server messages
   */
  function escHtml(str) {
    if (!str) return "";
    var div = document.createElement("div");
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
  }

  /**
   * Conversation Starters management (add/remove rows + emoji picker)
   */
  var EMOJI_PRESETS = [
    "💬",
    "👋",
    "🛒",
    "📦",
    "💡",
    "❓",
    "🔥",
    "⭐",
    "🎯",
    "💰",
    "📞",
    "🚀",
    "💳",
    "🏷️",
    "📋",
    "🎁",
    "🔧",
    "📊",
    "🤝",
    "💼",
  ];

  function initConversationStarters() {
    var $list = $("#chatzio-starters-list");
    if (!$list.length) return;

    var maxStarters = 6;

    // Emoji picker — toggle on icon click
    $list.on("click", ".starter-icon-input", function (e) {
      e.stopPropagation();
      var $wrap = $(this).closest(".starter-icon-wrap");
      // Close any open pickers first
      $(".starter-emoji-picker").remove();
      var $picker = $('<div class="starter-emoji-picker"></div>');
      EMOJI_PRESETS.forEach(function (em) {
        $picker.append(
          '<button type="button" class="starter-emoji-option">' +
            em +
            "</button>",
        );
      });
      $wrap.append($picker);
    });

    // Select emoji
    $list.on("click", ".starter-emoji-option", function (e) {
      e.stopPropagation();
      var emoji = $(this).text();
      $(this)
        .closest(".starter-icon-wrap")
        .find(".starter-icon-input")
        .val(emoji);
      $(".starter-emoji-picker").remove();
    });

    // Close picker on outside click
    $(document).on("click", function () {
      $(".starter-emoji-picker").remove();
    });

    // Add starter row
    $("#chatzio-add-starter").on("click", function () {
      var currentCount = $list.find(".chatzio-starter-row").length;
      if (currentCount >= maxStarters) {
        alert("Maximum " + maxStarters + " conversation starters allowed.");
        return;
      }

      var newIndex = currentCount;
      $list.find(".chatzio-starter-row").each(function () {
        var idx = parseInt($(this).data("index"));
        if (idx >= newIndex) newIndex = idx + 1;
      });

      var $row = $(
        '<div class="chatzio-starter-row" data-index="' +
          newIndex +
          '">' +
          '<div class="starter-icon-wrap">' +
          '<input type="text" name="chatzio_settings[conversation_starters][' +
          newIndex +
          '][icon]" value="💬" class="starter-icon-input" placeholder="💬" maxlength="4" readonly>' +
          "</div>" +
          '<input type="text" name="chatzio_settings[conversation_starters][' +
          newIndex +
          '][title]" value="" class="starter-title-input" placeholder="Title (e.g., Ask a question)">' +
          '<input type="text" name="chatzio_settings[conversation_starters][' +
          newIndex +
          '][subtitle]" value="" class="starter-subtitle-input" placeholder="Subtitle (optional)">' +
          '<button type="button" class="button chatzio-remove-starter" title="Remove"><span class="dashicons dashicons-trash"></span></button>' +
          "</div>",
      );
      $list.append($row);
      $row.find(".starter-title-input").focus();
      if (window.updateHomeBlockSummaries) window.updateHomeBlockSummaries();
    });

    // Remove starter row
    $list.on("click", ".chatzio-remove-starter", function () {
      var $row = $(this).closest(".chatzio-starter-row");
      var rowCount = $list.find(".chatzio-starter-row").length;

      if (rowCount <= 1) {
        $row.find(".starter-title-input").val("");
        $row.find(".starter-subtitle-input").val("");
        $row.find(".starter-icon-input").val("💬");
        if (window.updateHomeBlockSummaries) window.updateHomeBlockSummaries();
        return;
      }

      $row.css("opacity", 0.5);
      setTimeout(function () {
        $row.remove();
        if (window.updateHomeBlockSummaries) window.updateHomeBlockSummaries();
      }, 150);
    });
  }

  /**
   * News item image upload (Media Library)
   */
  function initNewsImageUpload($list) {
    if (!$list || !$list.length) return;
    var newsMediaUploader;
    var $targetInput, $targetCell;

    $list.on("click", ".chatzio-news-upload-image", function (e) {
      e.preventDefault();
      var $row = $(this).closest(".chatzio-news-row");
      $targetInput = $row.find(".news-image-url-input");
      $targetCell = $row.find(".news-row-image-cell");

      if (!newsMediaUploader) {
        newsMediaUploader = wp.media({
          title: "Choose Image",
          button: { text: "Use this image" },
          multiple: false,
          library: { type: "image" },
        });
        newsMediaUploader.on("select", function () {
          var attachment = newsMediaUploader
            .state()
            .get("selection")
            .first()
            .toJSON();
          var url = attachment.url;
          if ($targetInput && $targetInput.length) {
            $targetInput.val(url);
            $targetCell.find(".news-image-preview").remove();
            $targetCell
              .find(".chatzio-news-upload-image")
              .html('<span class="dashicons dashicons-edit"></span> Change');
            $targetCell.append(
              '<div class="news-image-preview"><img src="' +
                url +
                '" alt=""></div>',
            );
          }
        });
      }

      newsMediaUploader.open();
    });
  }

  /**
   * News Items management
   */
  function initNewsItems() {
    var $list = $("#chatzio-news-list");
    if (!$list.length) return;

    initNewsImageUpload($list);

    var maxItems = 6;

    $("#chatzio-add-news").on("click", function () {
      var currentCount = $list.find(".chatzio-news-row").length;
      if (currentCount >= maxItems) {
        alert("Maximum " + maxItems + " news items allowed.");
        return;
      }

      var newIndex = 0;
      $list.find(".chatzio-news-row").each(function () {
        var idx = parseInt($(this).data("index"));
        if (idx >= newIndex) newIndex = idx + 1;
      });

      var n = newIndex;
      var newId =
        "n" + Date.now() + "_" + Math.random().toString(36).substr(2, 9);
      var $row = $(
        '<div class="chatzio-news-row" data-index="' +
          n +
          '">' +
          '<input type="hidden" name="chatzio_settings[news_items][' +
          n +
          '][id]" value="' +
          newId +
          '" class="news-id-input">' +
          '<div class="news-row-main">' +
          '<div class="news-row-image-cell">' +
          '<input type="hidden" name="chatzio_settings[news_items][' +
          n +
          '][image_url]" value="" class="news-image-url-input">' +
          '<button type="button" class="button chatzio-news-upload-image" title="Add image"><span class="dashicons dashicons-format-image"></span> Upload</button>' +
          "</div>" +
          '<input type="text" name="chatzio_settings[news_items][' +
          n +
          '][title]" value="" class="news-title-input" placeholder="Title (eg: New feature launched!)">' +
          '<input type="text" name="chatzio_settings[news_items][' +
          n +
          '][description]" value="" class="news-desc-input" placeholder="Description (eg: Brief summary)">' +
          '<input type="text" name="chatzio_settings[news_items][' +
          n +
          '][url]" value="" class="news-url-input" placeholder="Link URL (eg: https://example.com)">' +
          "</div>" +
          '<div class="news-row-meta">' +
          '<div><input type="text" name="chatzio_settings[news_items][' +
          n +
          '][date]" value="" class="news-date-input" placeholder="Badge (eg: New, Sale)"></div>' +
          '<button type="button" class="button chatzio-remove-news" title="Remove"><span class="dashicons dashicons-trash"></span></button>' +
          "</div>" +
          "</div>",
      );
      $list.append($row);
      $row.find(".news-title-input").focus();
      if (window.updateHomeBlockSummaries) window.updateHomeBlockSummaries();
    });

    $list.on("click", ".chatzio-remove-news", function () {
      var $row = $(this).closest(".chatzio-news-row");
      if ($list.find(".chatzio-news-row").length <= 1) {
        $row.find("input[type=text]").val("");
        $row.find("select").val("");
        $row.find('input[type="checkbox"]').prop("checked", false);
        if (window.updateHomeBlockSummaries) window.updateHomeBlockSummaries();
        return;
      }
      $row.remove();
      if (window.updateHomeBlockSummaries) window.updateHomeBlockSummaries();
    });
  }

  /**
   * Widget mode toggle — disable side-nav + sections when Simple mode is selected.
   * Also syncs tab checkboxes with nav item states.
   */
  function initWidgetModeToggle() {
    var $select = $("#widget-mode-select");
    var $tabbedOptions = $("#tabbed-options");
    var $nav = $(".widget-layout-nav");
    if (!$select.length || !$tabbedOptions.length) return;

    // Map: nav data-section -> related tab checkbox name suffix
    var tabNavMap = {
      "wl-home": 'input[name="chatzio_settings[widget_tab_home]"]',
      "wl-faq": 'input[name="chatzio_settings[widget_tab_faq]"]',
      "wl-products": 'input[name="chatzio_settings[widget_tab_products]"]',
    };

    function applyModeState() {
      var mode = $select.val();
      var isSimple = mode === "simple";

      // Toggle tabbed options immediately (no save/refresh needed)
      if (isSimple) {
        $tabbedOptions.addClass("hidden").hide();
      } else {
        $tabbedOptions.removeClass("hidden").show();
      }

      // Toggle side-nav items (all except wl-mode)
      $nav.find(".wl-nav-item").each(function () {
        var section = $(this).data("section");
        if (section === "wl-mode") return;
        $(this).toggleClass("wl-nav-disabled", isSimple);
      });

      // Add/remove disabled overlay on sections
      $(".wl-section").each(function () {
        var id = $(this).attr("id");
        if (id === "wl-mode") return;
        $(this).find(".wl-disabled-overlay").remove();
        if (isSimple) {
          $(this)
            .css("position", "relative")
            .append(
              '<div class="wl-disabled-overlay">' +
                '<span class="dashicons dashicons-lock"></span>' +
                "Switch to <strong>Tabbed</strong> mode to configure this section" +
                "</div>",
            );
        }
      });

      // If not simple, also sync tab checkbox states and off prompts
      if (!isSimple) {
        syncTabCheckboxes();
        updateTabOffPrompts();
      }
    }

    // Sync tab checkboxes → nav "off" badges
    function syncTabCheckboxes() {
      Object.keys(tabNavMap).forEach(function (navSection) {
        var $cb = $(tabNavMap[navSection]);
        var $navItem = $nav.find('[data-section="' + navSection + '"]');
        if (!$cb.length || !$navItem.length) return;
        var isOff = !$cb.is(":checked");
        $navItem.toggleClass("wl-nav-off", isOff);
      });
    }

    // Show/hide "tab is off" prompts in FAQ and Products sections
    function updateTabOffPrompts() {
      var mode = $select.val();
      if (mode === "simple") return;
      var faqOff = !$('input[name="chatzio_settings[widget_tab_faq]"]').is(
        ":checked",
      );
      var productsOff = !$(
        'input[name="chatzio_settings[widget_tab_products]"]',
      ).is(":checked");
      $("#wl-faq-off-prompt").toggle(faqOff);
      $("#wl-products-off-prompt").toggle(productsOff);
      $(".wl-faq-content").toggleClass("wl-section-content-dimmed", faqOff);
      $(".wl-products-content").toggleClass(
        "wl-section-content-dimmed",
        productsOff,
      );
    }
    window.updateTabOffPrompts = updateTabOffPrompts;

    $(document).on("click", ".wl-enable-tab-btn", function () {
      var name = $(this).data("tab");
      var section = $(this).data("section");
      if (!name) return;
      var $cb = $('input[name="chatzio_settings[' + name + ']"]');
      if ($cb.length) {
        $cb.prop("checked", true).trigger("change");
        updateTabOffPrompts();
        if (section) {
          var $navItem = $(".widget-layout-nav").find(
            '[data-section="' + section + '"]',
          );
          if ($navItem.length) $navItem.trigger("click");
        }
      }
    });

    $select.on("change", applyModeState);

    // Max 5 tabs (Chat + 4 optional): enforce at most 4 optional checked
    var $opts = $tabbedOptions.find(".wl-tab-opt");
    function enforceMaxTabs() {
      var checked = $opts.filter(":checked");
      if (checked.length <= 4) return;
      var justChecked = $opts.filter(function () {
        return this.checked && $(this).data("just-checked");
      });
      $opts.removeData("just-checked");
      var toUncheck = justChecked.length
        ? checked.not(justChecked).first()
        : checked.last();
      toUncheck.prop("checked", false);
      syncTabCheckboxes();
    }
    $tabbedOptions.on("change", ".wl-tab-opt", function () {
      if (this.checked) $(this).data("just-checked", true);
      enforceMaxTabs();
      updateTabOffPrompts();
      syncTabCheckboxes();
    });

    // Listen to other tab checkbox changes (e.g. disabled Chat)
    $tabbedOptions.on("change", 'input[type="checkbox"]', function () {
      if (!$(this).hasClass("wl-tab-opt")) return;
      syncTabCheckboxes();
    });

    // Initial state
    applyModeState();
  }

  /**
   * Tab icon picker — flat SVG icons from same library as widget
   */
  function initTabIconPicker() {
    var library =
      (window.chatzioAdmin && window.chatzioAdmin.tabIconLibrary) || {};
    var flat = {};
    Object.keys(library).forEach(function (tabKey) {
      var options = library[tabKey];
      if (Array.isArray(options)) {
        options.forEach(function (opt) {
          flat[opt.id] = { svg: opt.svg, label: opt.label, tabKey: tabKey };
        });
      }
    });

    var $picker = $(
      '<div id="chatzio-flat-icon-picker" class="chatzio-flat-icon-picker" style="display:none;">' +
        '<div class="chatzio-flat-icon-picker-grid"></div>' +
        "</div>",
    );
    $("body").append($picker);

    var $targetInput = null;
    var $targetBtn = null;
    var currentTabKey = null;

    function renderPickerForTab(tabKey) {
      var options = library[tabKey];
      var $grid = $picker.find(".chatzio-flat-icon-picker-grid").empty();
      if (!Array.isArray(options)) return;
      options.forEach(function (opt) {
        $grid.append(
          '<button type="button" class="chatzio-flat-icon-opt" data-icon-id="' +
            (opt.id || "").replace(/"/g, "&quot;") +
            '" title="' +
            (opt.label || "").replace(/"/g, "&quot;") +
            '">' +
            (opt.svg || "") +
            "</button>",
        );
      });
    }

    function setButtonSvg($btn, iconId) {
      var item = flat[iconId];
      if (item && item.svg) {
        $btn.html(item.svg).addClass("wl-tab-icon-has-svg");
      } else {
        $btn.text(iconId || "…").removeClass("wl-tab-icon-has-svg");
      }
    }

    $(document).on("click", ".wl-tab-flat-icon-btn", function (e) {
      e.preventDefault();
      var $wrap = $(this).closest(".wl-tab-icon-picker-wrap");
      var $row = $(this).closest(".wl-tab-custom-row");
      currentTabKey = $row.data("tab-key");
      $targetInput = $row.find(".wl-tab-icon-input");
      $targetBtn = $(this);
      renderPickerForTab(currentTabKey);
      $picker
        .detach()
        .appendTo($wrap)
        .removeClass("chatzio-flat-icon-picker-above");
      $picker.show();
      var wrapRect = $wrap[0].getBoundingClientRect();
      var pickerH = $picker[0].offsetHeight || 120;
      var gap = 6;
      if (
        wrapRect.bottom + pickerH + gap > window.innerHeight &&
        wrapRect.top > pickerH + gap
      ) {
        $picker.addClass("chatzio-flat-icon-picker-above");
      }
    });

    $picker.on("click", ".chatzio-flat-icon-opt", function (e) {
      e.preventDefault();
      var iconId = $(this).data("icon-id");
      if ($targetInput && $targetInput.length) {
        $targetInput.val(iconId);
        setButtonSvg($targetBtn, iconId);
      }
      $picker.hide();
    });

    $(document).on("click", function (e) {
      if (
        $picker.is(":visible") &&
        !$.contains($picker[0], e.target) &&
        !$(e.target).hasClass("wl-tab-flat-icon-btn") &&
        !$(e.target).closest(".wl-tab-flat-icon-btn").length
      ) {
        $picker.hide();
      }
    });

    // On load, set each button to show current icon SVG
    $(".wl-tab-flat-icon-btn").each(function () {
      var $btn = $(this);
      var iconId = $btn
        .closest(".wl-tab-icon-picker-wrap")
        .find(".wl-tab-icon-input")
        .val();
      setButtonSvg($btn, iconId);
    });
  }

  /**
   * FAQ Items management
   */
  function initFaqItems() {
    var $list = $("#chatzio-faq-list");
    if (!$list.length) return;

    var maxItems = 20;

    $("#chatzio-add-faq").on("click", function () {
      var currentCount = $list.find(".chatzio-faq-row").length;
      if (currentCount >= maxItems) {
        alert("Maximum " + maxItems + " FAQ items allowed.");
        return;
      }

      var newIndex = 0;
      $list.find(".chatzio-faq-row").each(function () {
        var idx = parseInt($(this).data("index"));
        if (idx >= newIndex) newIndex = idx + 1;
      });

      var n = newIndex;
      var $row = $(
        '<div class="chatzio-faq-row" data-index="' +
          n +
          '">' +
          '<div class="faq-row-main">' +
          '<input type="text" name="chatzio_settings[faq_items][' +
          n +
          '][question]" value="" class="faq-question-input" placeholder="Question (e.g., What are your shipping options?)">' +
          '<input type="text" name="chatzio_settings[faq_items][' +
          n +
          '][category]" value="" class="faq-category-input" placeholder="Category">' +
          '<button type="button" class="button chatzio-remove-faq" title="Remove"><span class="dashicons dashicons-trash"></span></button>' +
          "</div>" +
          '<div class="faq-row-answer">' +
          '<textarea name="chatzio_settings[faq_items][' +
          n +
          '][answer]" class="faq-answer-input" placeholder="Answer (shown when visitor clicks this FAQ)..." rows="2"></textarea>' +
          "</div>" +
          "</div>",
      );
      $list.append($row);
      $row.find(".faq-question-input").focus();
    });

    $list.on("click", ".chatzio-remove-faq", function () {
      var $row = $(this).closest(".chatzio-faq-row");
      if ($list.find(".chatzio-faq-row").length <= 1) {
        $row.find("input, textarea").val("");
        return;
      }
      $row.remove();
    });
  }

  // Add spinning animation for loading
  $(
    "<style>.spin { animation: spin 1s linear infinite; } @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }</style>",
  ).appendTo("head");

  // ==========================================================================
  // UNIVERSAL ADMIN UTILITIES
  // Shared helper functions for filters, exports, and common UI patterns
  // ==========================================================================

  window.ChatzioAdminUtils = {
    /**
     * Build URL with current filter params preserved
     * @param {Object} overrides - Key/value pairs to add or override
     * @return {string} Updated URL
     */
    buildFilterUrl: function (overrides) {
      var params = new URLSearchParams(window.location.search);

      for (var key in overrides) {
        if (overrides[key] === null || overrides[key] === "") {
          params.delete(key);
        } else {
          params.set(key, overrides[key]);
        }
      }

      return window.location.pathname + "?" + params.toString();
    },

    /**
     * Get all current filter params as object
     * @return {Object} Current filter params
     */
    getFilterParams: function () {
      var params = new URLSearchParams(window.location.search);
      var result = {};
      params.forEach(function (value, key) {
        result[key] = value;
      });
      return result;
    },

    /**
     * Export data via AJAX download
     * @param {string} action - AJAX action name
     * @param {Object} extraParams - Additional params to include
     */
    exportData: function (action, extraParams) {
      var params = new URLSearchParams();
      params.set("action", action);
      params.set("nonce", chatzioAdmin.nonce);

      // Include current filter params
      var currentParams = this.getFilterParams();
      for (var key in currentParams) {
        if (key !== "page" && key !== "paged") {
          params.set(key, currentParams[key]);
        }
      }

      // Add any extra params
      if (extraParams) {
        for (var k in extraParams) {
          params.set(k, extraParams[k]);
        }
      }

      window.location.href = ajaxurl + "?" + params.toString();
    },

    /**
     * Show a toast notification
     * @param {string} message - Message to show
     * @param {string} type - 'success', 'error', 'warning', 'info'
     */
    showToast: function (message, type) {
      type = type || "success";
      var $toast = $(
        '<div class="chatzio-toast chatzio-toast-' +
          type +
          '">' +
          message +
          "</div>",
      );
      $("body").append($toast);

      setTimeout(function () {
        $toast.addClass("show");
      }, 10);

      setTimeout(function () {
        $toast.removeClass("show");
        setTimeout(function () {
          $toast.remove();
        }, 300);
      }, 3000);
    },

    /**
     * Confirm action with custom dialog
     * @param {string} message - Confirmation message
     * @param {Function} callback - Function to call if confirmed
     */
    confirm: function (message, callback) {
      if (confirm(message)) {
        callback();
      }
    },

    /**
     * Format number with locale
     * @param {number} num - Number to format
     * @return {string} Formatted number
     */
    formatNumber: function (num) {
      return num.toLocaleString();
    },

    /**
     * Debounce function calls
     * @param {Function} func - Function to debounce
     * @param {number} wait - Wait time in ms
     * @return {Function} Debounced function
     */
    debounce: function (func, wait) {
      var timeout;
      return function () {
        var context = this,
          args = arguments;
        clearTimeout(timeout);
        timeout = setTimeout(function () {
          func.apply(context, args);
        }, wait);
      };
    },
  };

  // Auto-bind export buttons using data attributes
  $(document).on("click", "[data-export-action]", function (e) {
    e.preventDefault();
    var action = $(this).data("export-action");
    ChatzioAdminUtils.exportData(action);
  });

  // Auto-bind confirm buttons
  $(document).on("click", "[data-confirm]", function (e) {
    if (!confirm($(this).data("confirm"))) {
      e.preventDefault();
      return false;
    }
  });

  // ==========================================================================
  // SETUP WIZARD (Overview page)
  // ==========================================================================

  function initSetupWizard() {
    var $wiz = $(".chatzio-setup-wizard");
    if (!$wiz.length) return;

    function goToStep(step) {
      $wiz.attr("data-current-step", step);
      $wiz.find(".wiz-panel").removeClass("active");
      $("#wiz-panel-" + step).addClass("active");
      // Update progress
      $wiz.find(".wiz-progress-step").each(function () {
        var s = parseInt($(this).data("step"));
        $(this).toggleClass("active", s <= step);
      });
    }

    function markStepDone(step) {
      var $s = $wiz.find('.wiz-progress-step[data-step="' + step + '"]');
      $s.addClass("done");
      var $line = $s.next(".wiz-progress-line");
      if ($line.length) $line.addClass("filled");
      // Enable next button in current panel
      $("#wiz-panel-" + step + " .wiz-btn-next").prop("disabled", false);
    }

    // Navigation: Back / Next
    $wiz.on("click", ".wiz-btn-back, .wiz-btn-next", function () {
      if ($(this).prop("disabled")) return;
      var target = parseInt($(this).data("goto"));
      if (target) goToStep(target);
    });

    // Step 1: Test & Save API Key
    $("#wizard-test-api").on("click", function () {
      var key = $("#wizard-api-key").val().trim();
      if (!key) {
        $("#wizard-api-result").html(
          '<span class="error">Please enter an API key.</span>',
        );
        return;
      }
      var $btn = $(this).prop("disabled", true);
      $btn.find("svg").hide();
      $btn.contents().last()[0].textContent = " Testing...";
      $("#wizard-api-result").html("");

      $.post(
        chatzioAdmin.ajaxUrl,
        {
          action: "chatzio_save_settings",
          chatzio_openrouter_api_key: key,
          nonce: chatzioAdmin.nonce,
        },
        function () {
          $.post(
            chatzioAdmin.ajaxUrl,
            {
              action: "chatzio_test_api",
              nonce: chatzioAdmin.nonce,
            },
            function (res) {
              if (res.success) {
                markStepDone(1);
                // Replace input with success badge
                $(
                  "#wiz-panel-1 .wiz-input-group, #wiz-panel-1 .wiz-hint, #wizard-api-result",
                ).remove();
                $(
                  '<div class="wiz-success-badge"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd"/></svg>API key connected successfully</div>',
                ).insertAfter("#wiz-panel-1 .wiz-panel-desc");
                // Reload page after a short delay to update status banner
                setTimeout(function () {
                  // Reload to refresh server-side status check
                  window.location.reload();
                }, 800);
              } else {
                var msg =
                  (res.data && (res.data.error || res.data.message)) ||
                  "Connection failed";
                $("#wizard-api-result").html(
                  '<span class="error">' + escHtml(msg) + "</span>",
                );
                $btn.prop("disabled", false);
                $btn.find("svg").show();
                $btn.contents().last()[0].textContent = " Test & Save";
              }
            },
          );
        },
      ).fail(function () {
        $("#wizard-api-result").html(
          '<span class="error">Request failed. Try again.</span>',
        );
        $btn.prop("disabled", false);
        $btn.find("svg").show();
        $btn.contents().last()[0].textContent = " Test & Save";
      });
    });

    // Step 2: Sync Content
    $("#wizard-sync").on("click", function () {
      var $btn = $(this).prop("disabled", true);
      $btn.find("svg").hide();
      $btn.contents().last()[0].textContent = " Syncing...";
      $("#wizard-sync-result").html("");

      $.ajax({
        url: chatzioAdmin.ajaxUrl,
        type: "POST",
        timeout: 180000,
        data: { action: "chatzio_sync_content", nonce: chatzioAdmin.nonce },
      })
        .done(function (res) {
          if (res.success && res.data && res.data.results) {
            var r = res.data.results;
            var count = (r.posts || 0) + (r.pages || 0) + (r.products || 0);
            markStepDone(2);
            $btn.remove();
            $(
              '<div class="wiz-success-badge"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd"/></svg>' +
                count +
                " items synced</div>",
            ).insertAfter("#wiz-panel-2 .wiz-panel-desc");
            setTimeout(function () {
              goToStep(3);
            }, 600);
          } else {
            var msg = (res.data && res.data.message) || "Sync failed.";
            $("#wizard-sync-result").html(
              '<span class="error">' + escHtml(msg) + "</span>",
            );
            $btn.prop("disabled", false);
            $btn.find("svg").show();
            $btn.contents().last()[0].textContent = " Sync All Content Now";
          }
        })
        .fail(function () {
          $("#wizard-sync-result").html(
            '<span class="error">Connection error. Try again.</span>',
          );
          $btn.prop("disabled", false);
          $btn.find("svg").show();
          $btn.contents().last()[0].textContent = " Sync All Content Now";
        });
    });

    // Step 3: Launch
    $("#wizard-finish").on("click", function () {
      var $btn = $(this).prop("disabled", true);
      $btn.contents().last()[0].textContent = " Launching...";
      $.post(chatzioAdmin.ajaxUrl, {
        action: "chatzio_save_settings",
        chatzio_bot_name: $("#wizard-bot-name").val(),
        chatzio_welcome_message: $("#wizard-welcome").val(),
        chatzio_primary_color: $("#wizard-color").val(),
        nonce: chatzioAdmin.nonce,
      })
        .done(function () {
          $.post(chatzioAdmin.ajaxUrl, {
            action: "chatzio_complete_setup",
            nonce: chatzioAdmin.nonce,
          }).done(function () {
            // Reload page to show dashboard content
            window.location.reload();
          });
        })
        .fail(function () {
          $btn.prop("disabled", false);
          $btn.contents().last()[0].textContent = " Launch My Chatbot";
        });
    });

    // Skip
    $("#wizard-skip-link").on("click", function (e) {
      e.preventDefault();
      $.post(chatzioAdmin.ajaxUrl, {
        action: "chatzio_complete_setup",
        nonce: chatzioAdmin.nonce,
      }).done(function () {
        // Reload page to show dashboard content
        window.location.reload();
      });
    });
  }

  // ==========================================================================
  // tab settings - SIDE NAVIGATION
  // ==========================================================================

  function initWidgetLayoutNav() {
    var $nav = $(".widget-layout-nav");
    var $content = $(".widget-layout-content");
    var subTabKey = "chatzio_settings_subtab";

    if (!$nav.length) return;

    // "Manage featured products" / "Manage latest updates" buttons: switch to that section
    $(document).on("click", ".wl-goto-section", function (e) {
      e.preventDefault();
      var section = $(this).data("section");
      if (section) {
        var $item = $nav.find('.wl-nav-item[data-section="' + section + '"]');
        if ($item.length) $item.trigger("click");
      }
    });

    // Handle nav item clicks
    $nav.on("click", ".wl-nav-item", function (e) {
      e.preventDefault();

      var $this = $(this);
      var sectionId = $this.data("section");

      // Update active nav item
      $nav.find(".wl-nav-item").removeClass("active");
      $this.addClass("active");

      // Show corresponding section
      $content.find(".wl-section").removeClass("active");
      $content.find("#" + sectionId).addClass("active");

      if (window.updateTabOffPrompts) window.updateTabOffPrompts();

      // Update URL hash for bookmarkability (without page jump)
      if (history.replaceState) {
        history.replaceState(null, null, "#" + sectionId);
      }
    });

    // Restore sub-tab after page load (sessionStorage takes priority over hash)
    try {
      var savedSub = sessionStorage.getItem(subTabKey);
      if (savedSub) {
        var $target = $nav.find('[data-section="' + savedSub + '"]');
        if ($target.length) {
          $target.trigger("click");
        }
        sessionStorage.removeItem(subTabKey);
        return; // skip hash check
      }
    } catch (e) {}

    // Check if URL has a hash to restore section
    var hash = window.location.hash;
    if (hash && hash.startsWith("#wl-")) {
      var sectionId = hash.substring(1);
      var $targetNav = $nav.find('[data-section="' + sectionId + '"]');
      if ($targetNav.length) {
        $targetNav.trigger("click");
      }
    }
  }

  // Initialize on document ready
  $(function () {
    initWidgetLayoutNav();
  });

  // Home tab blocks: collapsible with summary counts
  var STORAGE_KEY_HOME_BLOCKS = "wl_home_block_collapsed";

  function updateHomeBlockSummaries() {
    var $home = $("#wl-home");
    if (!$home.length) return;

    $home.find(".wl-home-block").each(function () {
      var $block = $(this);
      var blockId = $block.data("block-id");
      var $summary = $block.find(".wl-home-block-summary");
      if (!$summary.length) return;

      var text = "";
      if (blockId === "starters") {
        var n = $block
          .find(".chatzio-starter-row .starter-title-input")
          .filter(function () {
            return $(this).val().trim().length > 0;
          }).length;
        text =
          n === 0
            ? "None configured"
            : n + " starter" + (n !== 1 ? "s" : "") + " configured";
      } else if (blockId === "featured-products") {
        var $sel = $block.find(".wl-products-featured-select");
        var count = $sel.length
          ? $sel.find("option:selected").length
          : $block.find(".pc-multiselect-row input:checked").length;
        text =
          count === 0
            ? "No products selected"
            : count + " product" + (count !== 1 ? "s" : "") + " selected";
      } else if (blockId === "featured-news") {
        var $sel = $block.find(".wl-news-featured-select");
        var count = $sel.length
          ? $sel.find("option:selected").length
          : $block.find(".pc-multiselect-row input:checked").length;
        text =
          count === 0
            ? "No news selected"
            : count + " news item" + (count !== 1 ? "s" : "") + " featured";
      }
      $summary.text(text);
    });
  }

  function initHomeBlockCollapse() {
    var $home = $("#wl-home");
    if (!$home.length) return;

    var stored = {};
    try {
      var raw = sessionStorage.getItem(STORAGE_KEY_HOME_BLOCKS);
      if (raw) stored = JSON.parse(raw);
    } catch (e) {}

    $home.find(".wl-home-block").each(function () {
      var $block = $(this);
      var blockId = $block.data("block-id");
      if (blockId && stored[blockId] === false) {
        $block.removeClass("wl-home-block-collapsed");
      }
      // Welcome has no collapsed class by default; others have it, unless stored says false
    });

    updateHomeBlockSummaries();

    $home.on("click", ".wl-home-block-toggle", function (e) {
      e.preventDefault();
      var $block = $(this).closest(".wl-home-block");
      $block.toggleClass("wl-home-block-collapsed");
      var blockId = $block.data("block-id");
      if (blockId) {
        try {
          var o = {};
          var raw = sessionStorage.getItem(STORAGE_KEY_HOME_BLOCKS);
          if (raw) o = JSON.parse(raw);
          o[blockId] = $block.hasClass("wl-home-block-collapsed");
          sessionStorage.setItem(STORAGE_KEY_HOME_BLOCKS, JSON.stringify(o));
        } catch (err) {}
      }
      updateHomeBlockSummaries();
    });

    // Expose for other inits (starters/news add/remove) and product multiselect
    window.updateHomeBlockSummaries = updateHomeBlockSummaries;
    $(document).on(
      "change",
      "#wl-home .pc-multiselect-row input[type='checkbox']",
      function () {
        setTimeout(updateHomeBlockSummaries, 0);
      },
    );
    $(document).on("change", "#wl-home .wl-news-featured-select", function () {
      setTimeout(updateHomeBlockSummaries, 0);
    });
  }

  // Plugin Reset Handler
  function initPluginReset() {
    $("#chatzio-reset-plugin").on("click", function () {
      var $btn = $(this);
      var $result = $("#chatzio-reset-result");

      // Single confirmation modal with input
      var resetCode = prompt(
        "⚠️ WARNING: This will erase ALL Chatzio data (conversations, leads, analytics, synced content, resources, logs, and settings).\n\n" +
          "This cannot be undone.\n\n" +
          "To confirm, type 'RESET' (all caps) and press OK.",
      );

      if (resetCode !== "RESET") {
        if (resetCode !== null) {
          $result
            .html(
              '<div class="chatzio-alert chatzio-alert-error">Reset cancelled. You must type "RESET" exactly to confirm.</div>',
            )
            .show();
          setTimeout(function () {
            $result.fadeOut();
          }, 5000);
        }
        return;
      }

      // Disable button and show loading
      $btn
        .prop("disabled", true)
        .html(
          '<span class="dashicons dashicons-update spin"></span> Resetting...',
        );
      $result.html("").hide();

      $.ajax({
        url: chatzioAdmin.ajaxUrl,
        type: "POST",
        data: {
          action: "chatzio_reset_plugin",
          nonce: chatzioAdmin.nonce,
        },
        success: function (response) {
          if (response.success) {
            // Clear browser storage if flag is set
            if (response.data.clear_browser_storage) {
              try {
                // Clear all Chatzio-related localStorage items
                var keysToRemove = [];
                for (var i = 0; i < localStorage.length; i++) {
                  var key = localStorage.key(i);
                  if (key && key.startsWith("chatzio_")) {
                    keysToRemove.push(key);
                  }
                }
                keysToRemove.forEach(function (key) {
                  localStorage.removeItem(key);
                });

                // Clear sessionStorage
                var sessionKeysToRemove = [];
                for (var j = 0; j < sessionStorage.length; j++) {
                  var sessionKey = sessionStorage.key(j);
                  if (sessionKey && sessionKey.startsWith("chatzio_")) {
                    sessionKeysToRemove.push(sessionKey);
                  }
                }
                sessionKeysToRemove.forEach(function (key) {
                  sessionStorage.removeItem(key);
                });
              } catch (e) {
                console.warn("Could not clear browser storage:", e);
              }
            }

            $result
              .html(
                '<div class="chatzio-alert chatzio-alert-success">' +
                  "<strong>✓ Plugin Reset Complete</strong><br>" +
                  "<span>" +
                  response.data.message +
                  "</span>" +
                  "</div>" +
                  '<p class="chatzio-reset-reload-note">Page will reload in 3 seconds...</p>',
              )
              .show();
            // Reload page after 3 seconds
            setTimeout(function () {
              window.location.reload();
            }, 3000);
          } else {
            $result
              .html(
                '<div class="chatzio-alert chatzio-alert-error">' +
                  "<strong>✗ Reset Failed</strong><br>" +
                  "<span>" +
                  (response.data.message || "An error occurred") +
                  "</span>" +
                  "</div>",
              )
              .show();
            $btn
              .prop("disabled", false)
              .html(
                '<span class="dashicons dashicons-update"></span> Reset Plugin to Defaults',
              );
          }
        },
        error: function (xhr, status, error) {
          var errorMsg = "Please try again or check your connection.";
          if (
            xhr.responseJSON &&
            xhr.responseJSON.data &&
            xhr.responseJSON.data.message
          ) {
            errorMsg = xhr.responseJSON.data.message;
          } else if (xhr.responseText) {
            try {
              var parsed = JSON.parse(xhr.responseText);
              if (parsed.data && parsed.data.message) {
                errorMsg = parsed.data.message;
              }
            } catch (e) {
              // Keep default message
            }
          }
          $result
            .html(
              '<div class="chatzio-alert chatzio-alert-error">' +
                "<strong>✗ Request Failed</strong><br>" +
                "<span>" +
                errorMsg +
                "</span>" +
                "</div>",
            )
            .show();
          $btn
            .prop("disabled", false)
            .html(
              '<span class="dashicons dashicons-update"></span> Reset Plugin to Defaults',
            );
        },
      });
    });
  }
})(jQuery);
