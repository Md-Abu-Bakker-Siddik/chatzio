/**
 * Chatzio - Frontend Engine v4.2
 * Shadow DOM isolated, zero dependencies, vanilla JS
 */
(function () {
  "use strict";

  console.time("[chatzio] total boot");
  console.log("[chatzio] script executing at", Math.round(performance.now()), "ms after page start");

  if (typeof window.chatzioData === "undefined" && typeof window.smartchatData === "undefined") {
    console.warn("[chatzio] ABORT: no chatzioData or smartchatData found");
    console.timeEnd("[chatzio] total boot");
    return;
  }
  var cfg = window.chatzioData || window.smartchatData;
  console.log("[chatzio] config found:", cfg ? "yes" : "no");

  // =========================================================================
  // State
  // =========================================================================
  var sessionId = "";
  var isProcessing = false;
  var shadow = null;
  var conversationHistory = []; // [{role, content}, ...] — raw text for API
  var lastConversationId = null; // server-side ID for feedback
  var currentTab = "home"; // Current active tab
  var tabsEnabled = false; // Whether tabs are shown
  var faqCache = null; // Cache for FAQ data
  var leadOverlayShown = false; // Track if pre-chat lead form overlay has been shown
  var proactiveTimeoutId = null; // Clear when widget opens so bubble does not show late

  var $ = function (sel) {
    return shadow ? shadow.querySelector(sel) : null;
  };
  var $$ = function (sel) {
    return shadow ? shadow.querySelectorAll(sel) : [];
  };

  // =========================================================================
  // Boot — call init() immediately. The script is in the footer, so #chatzio-host
  // is already in the DOM above us. No need to wait for DOMContentLoaded.
  // =========================================================================
  console.log("[chatzio] readyState:", document.readyState, "— calling init immediately");
  init();

  function init() {
    console.log("[chatzio] init() called at", Math.round(performance.now()), "ms");

    // Read stored session (wrap all storage in try/catch for iOS private/restricted)
    var storedSessionId = "";
    var storedTimestamp = 0;
    try {
      storedSessionId = localStorage.getItem("chatzio_session_id") || "";
      storedTimestamp = parseInt(
        localStorage.getItem("chatzio_session_ts") || "0",
        10,
      );
    } catch (e) {}

    var tabAlive = false;
    try {
      tabAlive = !!sessionStorage.getItem("chatzio_tab_alive");
    } catch (e) {}

    var today = new Date().toDateString();
    var storedDay = storedTimestamp
      ? new Date(storedTimestamp).toDateString()
      : "";
    var isSameDay = today === storedDay;

    var shouldResumeSession = false;
    if (storedSessionId && tabAlive && isSameDay) {
      sessionId = storedSessionId;
      shouldResumeSession = true;
    } else if (storedSessionId && isSameDay && !tabAlive) {
      sessionId = uniqid();
    } else {
      sessionId = uniqid();
    }

    try {
      localStorage.setItem("chatzio_session_id", sessionId);
      localStorage.setItem("chatzio_session_ts", Date.now().toString());
      sessionStorage.setItem("chatzio_tab_alive", "1");
    } catch (e) {}

    // Restock shortcode button — lives outside Shadow DOM, on product pages.
    // Register before the #chatzio-host check so it works even without the widget.
    // Use capture phase so WoodMart/WooCommerce stopPropagation can't block us.
    document.addEventListener("click", function (e) {
      var btn = e.target.closest(".chatzio-restock-btn");
      if (!btn) return;
      e.preventDefault();
      e.stopPropagation();
      handleRestockNotify(btn);
    }, true);

    var host = document.getElementById("chatzio-host");
    if (!host) {
      console.warn("[chatzio] ABORT: #chatzio-host not found in DOM");
      console.timeEnd("[chatzio] total boot");
      return;
    }
    console.log("[chatzio] host element found at", Math.round(performance.now()), "ms");

    var position =
      cfg.settings && cfg.settings.widgetPosition
        ? cfg.settings.widgetPosition
        : "bottom-right";
    host.classList.add("position-" + position);

    shadow = host.attachShadow({ mode: "closed" });
    console.log("[chatzio] shadow DOM attached at", Math.round(performance.now()), "ms");

    console.time("[chatzio] injectStyles");
    injectStyles();
    console.timeEnd("[chatzio] injectStyles");

    console.time("[chatzio] buildWidget");
    buildWidget();
    console.timeEnd("[chatzio] buildWidget");

    console.time("[chatzio] bindEvents");
    bindEvents();
    console.timeEnd("[chatzio] bindEvents");

    initProactiveMessage();

    if (shouldResumeSession) {
      restoreSessionOnInit();
    }

    console.log("[chatzio] init complete at", Math.round(performance.now()), "ms");
    console.timeEnd("[chatzio] total boot");
  }

  function restoreSessionOnInit() {
    // Load the stored conversation history for this session
    var sessions = getStoredSessions();
    for (var i = 0; i < sessions.length; i++) {
      if (sessions[i].id === sessionId) {
        conversationHistory = sessions[i].history || [];
        if (conversationHistory.length > 0) {
          // Rebuild the chat UI from history; keep quick replies visible for new input
          setTimeout(function () {
            rebuildChatFromHistory();
          }, 100);
        }
        break;
      }
    }
  }

  // =========================================================================
  // Styles
  // =========================================================================
  function injectStyles() {
    var s = cfg.settings || {};

    // Load main stylesheet via <link> inside Shadow DOM (proper caching, no JSON bloat).
    // Falls back to legacy cfg.css inline string if cssUrl is not available.
    if (cfg.cssUrl) {
      var link = document.createElement("link");
      link.rel = "stylesheet";
      link.href = cfg.cssUrl;
      // Prevent FOUC: hide host until stylesheet is loaded
      var hostEl = shadow.host;
      if (hostEl) hostEl.style.visibility = "hidden";
      link.onload = function () { if (hostEl) hostEl.style.visibility = ""; };
      link.onerror = function () { if (hostEl) hostEl.style.visibility = ""; };
      shadow.appendChild(link);
    } else if (cfg.css) {
      var legacyStyle = document.createElement("style");
      legacyStyle.textContent = cfg.css;
      shadow.appendChild(legacyStyle);
    }

    // Dynamic overrides (always via inline <style>)
    var style = document.createElement("style");
    var overrides =
      ":host {" +
      "--chatzio-primary:" +
      cssColor(s.primaryColor, "#4F46E5") +
      " !important;" +
      "--chatzio-secondary:" +
      cssColor(s.secondaryColor, "#6366F1") +
      " !important;" +
      "--chatzio-link-color:" +
      cssColor(s.linkColor, "#2563EB") +
      " !important;" +
      "--chatzio-font-size:" +
      (parseInt(s.fontSize, 10) || 14) +
      "px !important;" +
      "--chatzio-font:" +
      (s.fontFamilyCSS || "'Inter', system-ui, sans-serif") +
      " !important;" +
      "--chatzio-bubble-size:" +
      (parseInt(s.bubbleSize, 10) || 60) +
      "px !important;" +
      "}";

    // Helper to hide header on home tab (only in tabbed mode)
    overrides +=
      ".chatzio-widget.has-tabs .chatzio-window.home-active .chatzio-header { display: none !important; }";

    if (s.widgetPosition === "custom") {
      var db = Math.max(0, parseInt(s.positionCustomDesktopBottom, 10) || 24);
      var dr = Math.max(0, parseInt(s.positionCustomDesktopRight, 10) || 24);
      var mb = Math.max(0, parseInt(s.positionCustomMobileBottom, 10) || 20);
      var mr = Math.max(0, parseInt(s.positionCustomMobileRight, 10) || 20);
      overrides +=
        ".chatzio-widget { bottom: " + db + "px !important; right: " + dr + "px !important; }";
      overrides +=
        "@media (max-width: 480px) {" +
        " .chatzio-widget { bottom: " + mb + "px !important; right: " + mr + "px !important; }" +
        "}";
    }

    style.textContent = overrides;
    shadow.appendChild(style);
  }

  function cssColor(val, fallback) {
    if (!val || typeof val !== "string") return fallback;
    if (
      /^(#[0-9a-fA-F]{3,8}|rgba?\([^)]+\)|hsla?\([^)]+\)|[a-zA-Z]+)$/.test(
        val.trim(),
      )
    ) {
      return val.trim();
    }
    return fallback;
  }

  // =========================================================================
  // Widget HTML
  // =========================================================================
  function buildWidget() {
    var s = cfg.settings || {};
    var botName = escapeHtml(s.botName || "Chatzio");
    var welcome = escapeHtml(
      s.welcomeMessage || "Hi! How can I help you today?",
    );
    var placeholder = escapeAttr(s.placeholder || "Type your message...");
    var logo = s.logo || "";
    var time = formatTime(new Date());

    // Determine widget mode and tabs
    var widgetMode = s.widgetMode || "tabbed";
    var tabs = s.widgetTabs || {};
    var homeEnabled = tabs.home !== false;
    var faqEnabled = !!tabs.faq;
    var productsEnabled = !!tabs.products;
    var historyEnabled = !!tabs.history;
    var newsEnabled = !!tabs.news;

    // In simple mode, disable all tabs
    if (widgetMode === "simple") {
      tabsEnabled = false;
    } else {
      tabsEnabled =
        homeEnabled ||
        faqEnabled ||
        productsEnabled ||
        historyEnabled ||
        newsEnabled;
    }

    // Tab labels and flat icons (icon ID) from settings
    var tabLabels = s.tabLabels || {
      home: "Home",
      chat: "Chat",
      faq: "FAQ",
      products: "Products",
      history: "History",
      news: "News",
    };
    var tabIcons = s.tabIcons || {
      home: "home",
      chat: "chat",
      faq: "faq",
      products: "products",
      history: "history",
      news: "news",
    };
    var tabIconLibrary = s.tabIconLibrary || {};
    function getTabLabel(key) {
      return tabLabels[key] || key;
    }
    function getTabIconSvg(key) {
      var iconId = tabIcons[key] || key;
      return tabIconLibrary[iconId] || tabIconLibrary[key] || "";
    }

    // Set initial tab from settings (defaultTab), only if that tab is enabled
    var defaultTab = s.defaultTab || "home";
    if (!tabsEnabled) {
      // Simple mode or no optional tabs: chat is the only view, so start on chat
      currentTab = "chat";
    } else if (defaultTab === "home" && homeEnabled) currentTab = "home";
    else if (defaultTab === "chat") currentTab = "chat";
    else if (defaultTab === "faq" && faqEnabled) currentTab = "faq";
    else if (defaultTab === "products" && productsEnabled)
      currentTab = "products";
    else if (defaultTab === "history" && historyEnabled) currentTab = "history";
    else if (defaultTab === "news" && newsEnabled) currentTab = "news";
    else currentTab = homeEnabled ? "home" : "chat";

    var tabStyle = s.widgetTabStyle || "icons_labels";
    var showLabels = tabStyle === "icons_labels";

    // Avatar HTML
    var avatar = logo
      ? '<img src="' +
        escapeAttr(logo) +
        '" alt="" class="bot-avatar" loading="lazy">'
      : '<div class="bot-avatar-placeholder" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg></div>';

    // Inline avatar for bot messages
    var inlineAvatarHtml = logo
      ? '<img src="' +
        escapeAttr(logo) +
        '" alt="" class="inline-avatar" loading="lazy">'
      : '<div class="inline-avatar-placeholder" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg></div>';

    // Content generation
    var quickRepliesHtml = buildQuickRepliesHtml(s.quickReplies);
    var startersHtml = buildStartersHtml(
      s.conversationStarters,
      s.defaultStarterTitle,
      s.defaultStarterSubtitle,
      s.defaultStarterIcon,
    );
    var newsHtml = buildNewsHtml(
      s.newsItems,
      s.homeNewsHeading || "Latest Updates",
      s.newsFeatured || [],
    );
    var featuredProductsHtml =
      '<div class="home-featured-products" id="home-featured-products"></div>'; // Will be populated after load

    // Home tab content
    var homeHeadline = escapeHtml(s.homeHeadline || "Hi there 👋");
    var homeSubtext = escapeHtml(s.homeSubtext || "How can we help you today?");

    // Build tab bar
    var tabBarHtml = "";
    if (tabsEnabled) {
      tabBarHtml = '<nav class="chatzio-tab-bar" role="tablist">';
      if (homeEnabled) {
        tabBarHtml +=
          '<button id="chatzio-tab-home" class="tab-btn' +
          (currentTab === "home" ? " active" : "") +
          '" data-tab="home" role="tab" aria-selected="' +
          (currentTab === "home") +
          '" aria-controls="chatzio-panel-home">' +
          (getTabIconSvg("home") ? getTabIconSvg("home") : "") +
          (showLabels
            ? "<span>" + escapeHtml(getTabLabel("home")) + "</span>"
            : "") +
          "</button>";
      }
      tabBarHtml +=
        '<button id="chatzio-tab-chat" class="tab-btn' +
        (currentTab === "chat" ? " active" : "") +
        '" data-tab="chat" role="tab" aria-selected="' +
        (currentTab === "chat") +
        '" aria-controls="chatzio-panel-chat">' +
        (getTabIconSvg("chat") ? getTabIconSvg("chat") : "") +
        (showLabels
          ? "<span>" + escapeHtml(getTabLabel("chat")) + "</span>"
          : "") +
        "</button>";
      if (faqEnabled) {
        tabBarHtml +=
          '<button id="chatzio-tab-faq" class="tab-btn' +
          (currentTab === "faq" ? " active" : "") +
          '" data-tab="faq" role="tab" aria-selected="' +
          (currentTab === "faq") +
          '" aria-controls="chatzio-panel-faq">' +
          (getTabIconSvg("faq") ? getTabIconSvg("faq") : "") +
          (showLabels
            ? "<span>" + escapeHtml(getTabLabel("faq")) + "</span>"
            : "") +
          "</button>";
      }
      if (productsEnabled) {
        tabBarHtml +=
          '<button id="chatzio-tab-products" class="tab-btn' +
          (currentTab === "products" ? " active" : "") +
          '" data-tab="products" role="tab" aria-selected="' +
          (currentTab === "products") +
          '" aria-controls="chatzio-panel-products">' +
          (getTabIconSvg("products") ? getTabIconSvg("products") : "") +
          (showLabels
            ? "<span>" + escapeHtml(getTabLabel("products")) + "</span>"
            : "") +
          "</button>";
      }
      if (historyEnabled) {
        tabBarHtml +=
          '<button id="chatzio-tab-history" class="tab-btn' +
          (currentTab === "history" ? " active" : "") +
          '" data-tab="history" role="tab" aria-selected="' +
          (currentTab === "history") +
          '" aria-controls="chatzio-panel-history">' +
          (getTabIconSvg("history") ? getTabIconSvg("history") : "") +
          (showLabels
            ? "<span>" + escapeHtml(getTabLabel("history")) + "</span>"
            : "") +
          "</button>";
      }
      if (newsEnabled) {
        tabBarHtml +=
          '<button id="chatzio-tab-news" class="tab-btn' +
          (currentTab === "news" ? " active" : "") +
          '" data-tab="news" role="tab" aria-selected="' +
          (currentTab === "news") +
          '" aria-controls="chatzio-panel-news">' +
          (getTabIconSvg("news") ? getTabIconSvg("news") : "") +
          (showLabels
            ? "<span>" + escapeHtml(getTabLabel("news")) + "</span>"
            : "") +
          "</button>";
      }
      tabBarHtml += "</nav>";
    }

    // Build widget HTML
    var html =
      '<div class="chatzio-widget' +
      (tabsEnabled ? " has-tabs" : "") +
      '" role="complementary" aria-label="Chat widget">' +
      '<div class="chatzio-toggle-wrap">' +
      '<button class="chatzio-toggle" aria-label="Toggle chat">' +
      '<svg class="chat-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>' +
      '<svg class="close-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>' +
      '<span class="chatzio-status-dot"></span>' +
      '<span class="notification-dot"></span>' +
      "</button>" +
      "</div>" +
      '<div id="chatzio-window" class="chatzio-window' +
      (currentTab === "home" ? " home-active" : "") +
      '" role="dialog">' +
      // HEADER (Hidden on Home Tab via CSS)
      '<header class="chatzio-header">' +
      avatar +
      '<div class="header-info">' +
      "<h3>" +
      botName +
      "</h3>" +
      '<div class="header-subtitle"><span class="online-dot"></span> Usually replies instantly</div>' +
      "</div>" +
      '<button class="header-btn chatzio-new-chat" type="button" title="New conversation"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99"/></svg></button>' +
      '<button class="header-btn chatzio-minimize" aria-label="Close chat" type="button">' +
      '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>' +
      "</button>" +
      "</header>" +
      // PANELS
      '<div class="chatzio-panels">' +
      // HOME PANEL
      (tabsEnabled && homeEnabled
        ? '<div id="chatzio-panel-home" class="chatzio-panel panel-home' +
          (currentTab === "home" ? " active" : "") +
          '" data-panel="home" role="tabpanel" aria-labelledby="chatzio-tab-home">' +
          '<div class="home-hero">' +
          // Logo on top-left of Home hero
          (logo
            ? '<div class="home-hero-logo"><img src="' +
              escapeAttr(logo) +
              '" alt="" loading="lazy"></div>'
            : "") +
          // Close button for Home tab (since header is hidden)
          '<div class="home-header-actions">' +
          '<button class="home-header-btn chatzio-minimize" aria-label="Close"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg></button>' +
          "</div>" +
          '<div class="home-greeting">' +
          '<h2 class="home-headline">' +
          homeHeadline +
          "</h2>" +
          '<p class="home-subtext">' +
          homeSubtext +
          "</p>" +
          "</div>" +
          "</div>" +
          '<div class="home-content-cards">' +
          startersHtml +
          featuredProductsHtml +
          newsHtml +
          "</div>" +
          "</div>"
        : "") +
      // CHAT PANEL
      '<div id="chatzio-panel-chat" class="chatzio-panel panel-chat' +
      (currentTab === "chat" ? " active" : "") +
      '" data-panel="chat" role="tabpanel" aria-labelledby="chatzio-tab-chat">' +
      '<div class="chatzio-messages">' +
      '<div class="message bot-message">' +
      '<div class="bot-message-row">' +
      inlineAvatarHtml +
      '<div class="message-content"><p>' +
      welcome +
      "</p></div>" +
      "</div>" +
      '<div class="message-meta"><span class="message-time">' +
      time +
      "</span></div>" +
      "</div>" +
      '<div class="typing-indicator">' +
      inlineAvatarHtml +
      '<div class="typing-bubble"><div class="typing-dot"></div><div class="typing-dot"></div><div class="typing-dot"></div></div>' +
      "</div>" +
      "</div>" +
      '<footer class="chatzio-input-area">' +
      '<div class="chatzio-quick-replies-bar">' +
      quickRepliesHtml +
      '</div>' +
      '<div class="input-wrapper">' +
      '<textarea class="message-input" placeholder="' +
      placeholder +
      '" rows="1"></textarea>' +
      '<button class="send-button" type="button">' +
      '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M3.478 2.405a.75.75 0 00-.926.94l2.432 7.905H13.5a.75.75 0 010 1.5H4.984l-2.432 7.905a.75.75 0 00.926.94 60.519 60.519 0 0018.445-8.986.75.75 0 000-1.218A60.517 60.517 0 003.478 2.405z"/></svg>' +
      "</button>" +
      "</div>" +
      "</footer>" +
      "</div>" +
      // FAQ PANEL (Q&A only; no products) — category slider like Products
      (tabsEnabled && faqEnabled
        ? '<div id="chatzio-panel-faq" class="chatzio-panel panel-faq' +
          (currentTab === "faq" ? " active" : "") +
          '" data-panel="faq" role="tabpanel" aria-labelledby="chatzio-tab-faq">' +
          '<div class="faq-search">' +
          '<input type="text" class="faq-search-input" placeholder="Search FAQs...">' +
          "</div>" +
          '<div class="faq-panel-inner">' +
          '<div class="pc-slider faq-panel-cats-wrap">' +
          '<div class="faq-panel-cats"></div>' +
          "</div>" +
          '<div class="faq-list"><div class="faq-loading">Loading FAQs...</div></div>' +
          "</div></div>"
        : "") +
      // PRODUCTS PANEL (browse products only)
      (tabsEnabled && productsEnabled
        ? '<div id="chatzio-panel-products" class="chatzio-panel panel-products' +
          (currentTab === "products" ? " active" : "") +
          '" data-panel="products" role="tabpanel" aria-labelledby="chatzio-tab-products">' +
          '<div class="products-panel-inner">' +
          '<div class="faq-section-label products-panel-heading">' +
          escapeHtml(
            cfg.settings && cfg.settings.productsTabHeading
              ? cfg.settings.productsTabHeading
              : "Browse Products",
          ) +
          "</div>" +
          '<div class="pc-slider products-panel-cats-wrap">' +
          '<div class="products-panel-cats"></div>' +
          "</div>" +
          '<div class="faq-products-list products-panel-list"></div>' +
          "</div></div>"
        : "") +
      // HISTORY PANEL
      (tabsEnabled && historyEnabled
        ? '<div id="chatzio-panel-history" class="chatzio-panel panel-history' +
          (currentTab === "history" ? " active" : "") +
          '" data-panel="history" role="tabpanel" aria-labelledby="chatzio-tab-history">' +
          '<div class="history-list"></div>' +
          '<button class="history-clear" type="button">Clear History</button>' +
          "</div>"
        : "") +
      // NEWS PANEL
      (tabsEnabled && newsEnabled
        ? '<div id="chatzio-panel-news" class="chatzio-panel panel-news' +
          (currentTab === "news" ? " active" : "") +
          '" data-panel="news" role="tabpanel" aria-labelledby="chatzio-tab-news">' +
          '<div class="news-panel-hero">' +
          '<div class="news-hero-text">' +
          "<h3>News & Offers</h3>" +
          "<p>Stay updated with the latest</p>" +
          "</div>" +
          "</div>" +
          '<div class="news-panel-list">' +
          buildFullNewsHtml(s.newsItems) +
          "</div>" +
          "</div>"
        : "") +
      "</div>" +
      // Tab bar
      tabBarHtml +
      "</div>" +
      "</div>";

    var container = document.createElement("div");
    container.innerHTML = html;
    shadow.appendChild(container.firstElementChild);

    if (faqEnabled) loadFaq();
    if (productsEnabled) loadProductsPanel();
    if (historyEnabled) renderHistory();
    if (homeEnabled) loadFeaturedProducts(); // Load featured products for Home tab
  }

  function buildNewsHtml(items, sectionTitle, featuredIds) {
    if (!items || !items.length) return "";
    featuredIds = featuredIds || [];

    // Only show items whose id is in featuredIds (set on Home tab in admin)
    var featuredItems = [];
    for (var i = 0; i < items.length; i++) {
      if (items[i].title && featuredIds.indexOf(String(items[i].id)) !== -1) {
        featuredItems.push(items[i]);
      }
    }
    if (featuredItems.length === 0) return "";

    var html = '<div class="home-news-section">';
    html +=
      '<div class="news-label">' +
      escapeHtml(sectionTitle || "Latest Updates") +
      "</div>";

    for (var k = 0; k < featuredItems.length && k < 3; k++) {
      var item = featuredItems[k];
      html += buildNewsItemHtml(item, true);
    }

    html += "</div>";
    return html;
  }

  function buildFullNewsHtml(items) {
    if (!items || !items.length)
      return '<div class="news-empty">No news or offers at this time.</div>';

    var html = "";
    for (var i = 0; i < items.length; i++) {
      var item = items[i];
      if (!item.title) continue;
      html += buildNewsItemHtml(item, false);
    }
    return (
      html || '<div class="news-empty">No news or offers at this time.</div>'
    );
  }

  function buildNewsItemHtml(item, isFeatured) {
    var url = item.url ? escapeAttr(item.url) : "#";
    var target = item.url ? ' target="_blank" rel="noopener"' : "";
    var date = item.date ? escapeHtml(item.date) : "";
    var desc = item.description ? escapeHtml(item.description) : "";
    var featuredClass = isFeatured ? " news-item-featured" : "";
    var imgUrl = item.image_url ? escapeAttr(item.image_url) : "";

    var html =
      '<a href="' +
      url +
      '" class="news-item news-item-card' +
      featuredClass +
      '"' +
      target +
      ">";
    if (imgUrl) {
      html +=
        '<div class="news-item-image"><img src="' +
        imgUrl +
        '" alt="" loading="lazy"></div>';
    }
    html += '<div class="news-item-body">';
    html += '<div class="news-item-content">';
    html += '<div class="news-item-title">' + escapeHtml(item.title) + "</div>";
    if (desc) {
      html += '<div class="news-item-desc">' + desc + "</div>";
    }
    if (date) {
      html += '<span class="news-badge">' + date + "</span>";
    }
    html += "</div>";
    if (item.url) {
      html +=
        '<div class="news-item-link"><svg class="news-link-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg></div>';
    }
    html += "</div></a>";
    return html;
  }

  function buildQuickRepliesHtml(replies) {
    if (!replies || !replies.length) return "";
    var html = '<div class="chatzio-quick-replies">';
    for (var i = 0; i < replies.length; i++) {
      html +=
        '<button class="quick-reply-btn" type="button">' +
        escapeHtml(replies[i]) +
        "</button>";
    }
    html += "</div>";
    return html;
  }

  function buildStartersHtml(
    starters,
    defaultTitle,
    defaultSubtitle,
    defaultIcon,
  ) {
    defaultTitle =
      (defaultTitle || "Ask a question").trim() || "Ask a question";
    defaultSubtitle = (
      defaultSubtitle || "We typically reply in a few minutes"
    ).trim();
    defaultIcon = (defaultIcon || "👋").trim() || "👋";
    // Always show at least the default starter card on Home tab
    var html =
      '<button class="starter-card" type="button" data-action="chat">' +
      '<span class="starter-icon">' +
      escapeHtml(defaultIcon) +
      "</span>" +
      '<div class="starter-text">' +
      '<span class="starter-title">' +
      escapeHtml(defaultTitle) +
      "</span>" +
      (defaultSubtitle
        ? '<span class="starter-subtitle">' +
          escapeHtml(defaultSubtitle) +
          "</span>"
        : "") +
      "</div>" +
      '<svg class="starter-arrow" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3"/></svg>' +
      "</button>";

    if (!starters || !starters.length) return html;
    for (var i = 0; i < starters.length; i++) {
      var st = starters[i];
      if (!st.title) continue;
      html +=
        '<button class="starter-card" type="button" data-message="' +
        escapeAttr(st.title) +
        '">' +
        '<span class="starter-icon">' +
        escapeHtml(st.icon || "💬") +
        "</span>" +
        '<div class="starter-text">' +
        '<span class="starter-title">' +
        escapeHtml(st.title) +
        "</span>" +
        (st.subtitle
          ? '<span class="starter-subtitle">' +
            escapeHtml(st.subtitle) +
            "</span>"
          : "") +
        "</div>" +
        '<svg class="starter-arrow" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3"/></svg>' +
        "</button>";
    }
    return html;
  }

  // =========================================================================
  // Events
  // =========================================================================
  function bindEvents() {
    var widget = $(".chatzio-widget");
    var toggle = $(".chatzio-toggle");
    // Note: minimize buttons are class based now
    var minimizeBtns = $$(".chatzio-minimize");
    var newChat = $(".chatzio-new-chat");
    var input = $(".message-input");
    var sendBtn = $(".send-button");

    if (!widget || !toggle || !input || !sendBtn) return;

    // Show new-chat button only when Chat tab is active (hidden on Home, FAQ, etc.)
    widget.classList.toggle("chat-tab-active", currentTab === "chat");

    // Toggle
    toggle.addEventListener("click", function () {
      var isOpen = widget.classList.contains("open");
      if (isOpen) {
        closeWidget();
      } else {
        openWidget();
      }
    });

    // Minimize buttons (header and home hero)
    for (var i = 0; i < minimizeBtns.length; i++) {
      minimizeBtns[i].addEventListener("click", function () {
        closeWidget();
      });
    }

    function openWidget() {
      if (proactiveTimeoutId) {
        clearTimeout(proactiveTimeoutId);
        proactiveTimeoutId = null;
      }
      var proactive = $(".chatzio-proactive");
      if (proactive) proactive.remove();

      trackAnalyticsEvent("widget_open");

      widget.classList.remove("closing");
      widget.classList.add("open");
      toggle.setAttribute("aria-expanded", "true");
      if (currentTab === "chat") {
        setTimeout(function () {
          input.focus();
        }, 300);
        // Show pre-chat lead form only when Chat tab is active
        maybeShowLeadForm();
      }
      var dot = $(".notification-dot");
      if (dot) dot.classList.remove("active");
    }

    function closeWidget() {
      widget.classList.add("closing");
      widget.classList.remove("open");
      toggle.setAttribute("aria-expanded", "false");
      setTimeout(function () {
        widget.classList.remove("closing");
        toggle.focus();
      }, 260);
    }

    if (newChat)
      newChat.addEventListener("click", function () {
        resetConversation();
      });

    input.addEventListener("input", function () {
      input.style.height = "auto";
      input.style.height = Math.min(input.scrollHeight, 120) + "px";
    });

    input.addEventListener("keydown", function (e) {
      if (e.key === "Enter" && !e.shiftKey) {
        e.preventDefault();
        sendMessage();
      }
    });

    sendBtn.addEventListener("click", function () {
      sendMessage();
    });

    document.addEventListener("keydown", function (e) {
      if (e.key === "Escape" && widget.classList.contains("open")) {
        closeWidget();
      }
    });

    var tabBar = $(".chatzio-tab-bar");
    if (tabBar) {
      tabBar.addEventListener("click", function (e) {
        var btn = e.target.closest(".tab-btn");
        if (!btn) return;
        var tabName = btn.getAttribute("data-tab");
        if (tabName) switchTab(tabName);
      });
    }

    var homeStarters = $(".home-content-cards");
    if (homeStarters) {
      homeStarters.addEventListener("click", function (e) {
        var card = e.target.closest(".starter-card");
        if (!card) return;

        // If it's the "Ask a question" card
        if (card.getAttribute("data-action") === "chat") {
          switchTab("chat");
          return;
        }

        var message = card.getAttribute("data-message");
        if (message) {
          switchTab("chat");
          setTimeout(function () {
            input.value = message;
            sendMessage();
          }, 100);
        }
      });
    }

    // Quick reply buttons live in the input footer, not in .chatzio-messages — delegate on widget
    if (widget) {
      widget.addEventListener("click", function (e) {
        var qrBtn = e.target.closest(".quick-reply-btn");
        if (qrBtn) {
          var text = qrBtn.textContent.trim();
          if (text) {
            var qrContainer = qrBtn.closest(".chatzio-quick-replies");
            if (qrContainer) qrContainer.remove();
            input.value = text;
            sendMessage();
          }
          return;
        }

        // Restock "Notify Me" button
        var notifyBtn = e.target.closest(".pc-notify-btn");
        if (notifyBtn) {
          e.preventDefault();
          e.stopPropagation();
          handleRestockNotify(notifyBtn);
          return;
        }

        var btn = e.target.closest(".pc-add-to-cart-btn");
        if (!btn) return;
        e.preventDefault();
        e.stopPropagation();
        var pid = btn.getAttribute("data-product-id");
        var fallbackHref = btn.getAttribute("href");
        if (!pid || !cfg.ajaxUrl || !cfg.nonce) {
          if (fallbackHref) window.location.href = fallbackHref;
          return;
        }
        if (
          btn.classList.contains("pc-adding") ||
          btn.classList.contains("pc-added")
        )
          return;
        btn.classList.add("pc-adding");
        btn.disabled = true;
        var body = new FormData();
        body.append("action", "chatzio_add_to_cart");
        body.append("nonce", cfg.nonce);
        body.append("product_id", pid);
        fetch(cfg.ajaxUrl, {
          method: "POST",
          body: body,
          credentials: "same-origin",
        })
          .then(function (r) {
            return r.json();
          })
          .then(function (data) {
            btn.classList.remove("pc-adding");
            if (!data.success && fallbackHref) {
              btn.disabled = false;
              window.location.href = fallbackHref;
              return;
            }
            btn.classList.add("pc-added");
            var span = btn.querySelector("span");
            if (span) {
              span.textContent = "Added";
            }
            if (typeof jQuery !== "undefined")
              jQuery(document.body).trigger("wc_fragment_refresh");
            setTimeout(function () {
              btn.classList.remove("pc-added");
              if (span) span.textContent = "Add to cart";
              btn.disabled = false;
            }, 2000);
          })
          .catch(function () {
            btn.classList.remove("pc-adding");
            btn.disabled = false;
            if (fallbackHref) window.location.href = fallbackHref;
          });
      });
    }

    var faqSearch = $(".faq-search-input");
    var faqSearchTimeout;
    if (faqSearch) {
      faqSearch.addEventListener("input", function () {
        var q = this.value;
        clearTimeout(faqSearchTimeout);
        faqSearchTimeout = setTimeout(function () {
          filterFaq(q);
        }, 200);
      });
    }

    var faqList = $(".faq-list");
    if (faqList) {
      faqList.addEventListener("click", function (e) {
        var header = e.target.closest(".faq-item-header");
        if (header) {
          var item = header.closest(".faq-item");
          if (item) item.classList.toggle("expanded");
          return;
        }
        var askBtn = e.target.closest(".faq-ask-btn");
        if (askBtn) {
          var question = askBtn.getAttribute("data-question");
          if (question) {
            switchTab("chat");
            setTimeout(function () {
              input.value = question;
              sendMessage();
            }, 100);
          }
        }
      });
    }

    var historyList = $(".history-list");
    if (historyList) {
      historyList.addEventListener("click", function (e) {
        var item = e.target.closest(".history-item");
        if (!item) return;
        var sid = item.getAttribute("data-session");
        if (sid) {
          loadSession(sid);
          switchTab("chat");
        }
      });
    }

    var clearHistoryBtn = $(".history-clear");
    if (clearHistoryBtn)
      clearHistoryBtn.addEventListener("click", function () {
        clearHistory();
      });
  }

  function switchTab(tabName) {
    if (tabName === currentTab) return;

    var prevTab = currentTab;
    currentTab = tabName;

    // Show refresh/new-chat button only on Chat tab
    var w = $(".chatzio-widget");
    if (w) w.classList.toggle("chat-tab-active", tabName === "chat");

    // Toggle header visibility based on tab
    var win = $("#chatzio-window");
    if (tabName === "home") win.classList.add("home-active");
    else win.classList.remove("home-active");

    var tabBtns = $$(".tab-btn");
    for (var i = 0; i < tabBtns.length; i++) {
      var isActive = tabBtns[i].getAttribute("data-tab") === tabName;
      tabBtns[i].classList.toggle("active", isActive);
      tabBtns[i].setAttribute("aria-selected", isActive ? "true" : "false");
    }

    var panels = $$(".chatzio-panel");
    var tabOrder = ["home", "chat", "faq", "products", "history", "news"];
    var prevIndex = tabOrder.indexOf(prevTab);
    var nextIndex = tabOrder.indexOf(tabName);
    var direction = nextIndex > prevIndex ? "left" : "right";

    for (var j = 0; j < panels.length; j++) {
      var panel = panels[j];
      var panelName = panel.getAttribute("data-panel");

      if (panelName === tabName) {
        panel.classList.add("active");
        panel.classList.remove("slide-out-left", "slide-out-right");
        panel.classList.add(
          "slide-in-" + (direction === "left" ? "right" : "left"),
        );
      } else if (panelName === prevTab) {
        panel.classList.remove("active", "slide-in-left", "slide-in-right");
        panel.classList.add("slide-out-" + direction);
      } else {
        panel.classList.remove(
          "active",
          "slide-in-left",
          "slide-in-right",
          "slide-out-left",
          "slide-out-right",
        );
      }
    }

    if (tabName === "chat") {
      var input = $(".message-input");
      if (input)
        setTimeout(function () {
          input.focus();
        }, 100);
      // Ensure lead form only appears on Chat tab
      maybeShowLeadForm();
    }

    if (tabName === "history") renderHistory();
  }

  function initProactiveMessage() {
    var s = cfg.settings || {};
    var msg = (s.proactiveMessage || "").trim();
    var delaySec = Math.max(1, parseInt(s.proactiveDelay, 10) || 5);
    if (!msg || delaySec <= 0) return;

    proactiveTimeoutId = setTimeout(function () {
      proactiveTimeoutId = null;
      var widgetEl = $(".chatzio-widget");
      if (!widgetEl || widgetEl.classList.contains("open")) return;
      if ($(".chatzio-proactive")) return;

      var wrapEl = $(".chatzio-toggle-wrap");
      if (!wrapEl) return;

      var bubble = document.createElement("div");
      bubble.className = "chatzio-proactive";
      bubble.setAttribute("role", "button");
      bubble.tabIndex = 0;
      bubble.innerHTML =
        escapeHtml(msg) +
        '<button type="button" class="chatzio-proactive-close" aria-label="Close">×</button>';
      bubble.addEventListener("click", function (e) {
        if (e.target.classList.contains("chatzio-proactive-close")) return;
        var toggle = $(".chatzio-toggle");
        if (toggle) toggle.click();
      });
      var closeBtn = bubble.querySelector(".chatzio-proactive-close");
      if (closeBtn) {
        closeBtn.addEventListener("click", function (e) {
          e.stopPropagation();
          bubble.remove();
        });
      }
      wrapEl.insertBefore(bubble, wrapEl.firstChild);
    }, delaySec * 1000);
  }

  // =========================================================================
  // Messaging & Other Functions
  // =========================================================================
  function sendMessage() {
    var input = $(".message-input");
    var sendBtn = $(".send-button");
    var message = input.value.trim();

    if (!message || isProcessing) return;

    if (conversationHistory.length === 0)
      trackAnalyticsEvent("conversation_started");

    addMessage(message, "user");
    conversationHistory.push({ role: "user", content: message });
    hideQuickRepliesBar();

    input.value = "";
    input.style.height = "auto";
    isProcessing = true;
    sendBtn.disabled = true;
    input.disabled = true;

    if (cfg.streamUrl) {
      sendMessageStream(message);
    } else {
      sendMessageAjax(message);
    }
  }

  function sendMessageStream(message) {
    var input = $(".message-input");
    var sendBtn = $(".send-button");
    var streamDiv = null;
    var contentEl = null;
    var rawText = "";

    // Show typing dots while waiting for the first chunk
    showTyping(true);

    var body = new FormData();
    body.append("nonce", cfg.nonce);
    body.append("message", message);
    body.append("session_id", sessionId);
    body.append("conversation_history", JSON.stringify(conversationHistory));
    body.append("page_url", window.location.href);

    fetch(cfg.streamUrl, {
      method: "POST",
      body: body,
      credentials: "same-origin",
    })
      .then(function (response) {
        if (!response.ok) {
          console.warn(
            "[Chatzio] Streaming failed with status " +
              response.status +
              ". Falling back to AJAX.",
          );
          // Fallback to AJAX on 403 or other errors
          throw new Error("Stream failed: " + response.status);
        }
        var reader = response.body.getReader();
        var decoder = new TextDecoder();

        function readChunk() {
          return reader.read().then(function (result) {
            if (result.done) return;
            var text = decoder.decode(result.value, { stream: true });
            var lines = text.split("\n");
            for (var i = 0; i < lines.length; i++) {
              var line = lines[i].trim();
              if (line.indexOf("data: ") !== 0) continue;
              var jsonStr = line.substring(6);
              try {
                var data = JSON.parse(jsonStr);
                // Handle streaming errors
                if (data.error) {
                  console.error("[Chatzio Stream Error]", data.error);
                  if (data.debug) {
                    console.error("[Chatzio Stream Debug]", data.debug);
                  }
                  showTyping(false);
                  addMessage(
                    "An error occurred: " + escapeHtml(data.error),
                    "bot",
                  );
                  return;
                }
                if (data.done) {
                  // Strip [TOPIC: xxx] and [UNANSWERED] tags that may have leaked through streaming chunks
                  rawText = rawText.replace(/\s*\[TOPIC\s*:\s*[a-z_]+\]\s*/gi, "").replace(/\s*\[UNANSWERED\]\s*/gi, "").trim();
                  if (contentEl) {
                    var finalHtml = data.formatted
                      ? styleProductRefs(data.formatted)
                      : styleProductRefs(markdownLinksToHtml(rawText));
                    contentEl.classList.remove("streaming");
                    contentEl.innerHTML =
                      finalHtml.indexOf("<") !== -1
                        ? finalHtml
                        : "<p>" + finalHtml + "</p>";
                  }
                  if (data.session_id) sessionId = data.session_id;
                  try {
                    localStorage.setItem("chatzio_session_id", sessionId);
                    localStorage.setItem(
                      "chatzio_session_ts",
                      Date.now().toString(),
                    );
                  } catch (e) {}
                  lastConversationId = data.conversation_id || null;
                  if (streamDiv && lastConversationId)
                    addFeedbackToMessage(streamDiv, lastConversationId);
                  conversationHistory.push({
                    role: "assistant",
                    content: rawText,
                  });
                  saveCurrentSession();
                  scrollToBottom();
                  return;
                }
                if (data.content) {
                  // Create the bot message bubble on first content chunk
                  if (!streamDiv) {
                    showTyping(false);
                    streamDiv = createEmptyBotMessage();
                    contentEl = streamDiv.querySelector(".message-content");
                    contentEl.classList.add("streaming");
                  }
                  rawText += data.content;
                  contentEl.textContent += data.content;
                  scrollToBottom();
                }
              } catch (e) {}
            }
            return readChunk();
          });
        }
        return readChunk();
      })
      .catch(function () {
        showTyping(false);
        if (streamDiv && streamDiv.parentNode)
          streamDiv.parentNode.removeChild(streamDiv);
        return sendMessageAjax(message);
      })
      .then(function () {
        // Remove streaming cursor
        if (contentEl) contentEl.classList.remove("streaming");
        isProcessing = false;
        sendBtn.disabled = false;
        input.disabled = false;
        input.focus();
      });
  }

  function sendMessageAjax(message) {
    var input = $(".message-input");
    var sendBtn = $(".send-button");
    showTyping(true);

    var body = new FormData();
    body.append("action", "chatzio_send_message");
    body.append("nonce", cfg.nonce);
    body.append("message", message);
    body.append("session_id", sessionId);
    body.append("conversation_history", JSON.stringify(conversationHistory));

    fetch(cfg.ajaxUrl, {
      method: "POST",
      body: body,
      credentials: "same-origin",
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        showTyping(false);
        if (data.success) {
          // Log performance timings if available
          if (data.data.performance) {
            console.log("[Chatzio Performance]", data.data.performance);
            var total = data.data.performance.total || 0;
            if (total > 3000) {
              console.warn(
                "[Chatzio] Slow response detected (" +
                  total +
                  "ms). Check Logs & Debug page for details.",
              );
            }
          }

          if (data.data.session_id) sessionId = data.data.session_id;
          try {
            localStorage.setItem("chatzio_session_id", sessionId);
            localStorage.setItem("chatzio_session_ts", Date.now().toString());
          } catch (e) {}
          lastConversationId = data.data.conversation_id || null;
          addMessage(data.data.response, "bot", lastConversationId);
          conversationHistory.push({
            role: "assistant",
            content: data.data.raw_response || stripHtml(data.data.response),
          });
          saveCurrentSession();
        } else {
          // Log error details to console for debugging
          var errMsg =
            data.data && data.data.message
              ? data.data.message
              : "Unknown error";
          console.error("[Chatzio Error]", errMsg);
          if (data.data && data.data.debug) {
            console.error("[Chatzio Debug]", data.data.debug);
          }
          addMessage("An error occurred: " + escapeHtml(errMsg), "bot");
        }
      })
      .catch(function (err) {
        showTyping(false);
        console.error("[Chatzio Network Error]", err);
        addMessage("Sorry, I'm having trouble connecting.", "bot");
      })
      .then(function () {
        isProcessing = false;
        sendBtn.disabled = false;
        input.disabled = false;
        input.focus();
      });
  }

  function createEmptyBotMessage() {
    var messages = $(".chatzio-messages");
    var indicator = $(".typing-indicator");
    var s = cfg.settings || {};
    var logo = s.logo || "";
    var time = formatTime(new Date());
    var inlineAvatar = logo
      ? '<img src="' + escapeAttr(logo) + '" alt="" class="inline-avatar">'
      : '<div class="inline-avatar-placeholder"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg></div>';

    var div = document.createElement("div");
    div.className = "message bot-message";
    div.innerHTML =
      '<div class="bot-message-row">' +
      inlineAvatar +
      '<div class="message-content"></div></div><div class="message-meta"><span class="message-time">' +
      time +
      "</span></div>";
    // Insert BEFORE typing indicator so it stays at the bottom
    if (indicator) {
      messages.insertBefore(div, indicator);
    } else {
      messages.appendChild(div);
    }
    scrollToBottom();
    return div;
  }

  // Feedback functionality has been removed from the UI for cleaner design
  // Keeping the function stub for backward compatibility
  function addFeedbackToMessage(msgEl, conversationId) {
    // No-op: Feedback buttons removed for premium design
  }

  function addMessage(content, type, conversationId) {
    var messages = $(".chatzio-messages");
    var indicator = $(".typing-indicator");
    if (!messages) return;
    var s = cfg.settings || {};
    var logo = s.logo || "";
    var time = formatTime(new Date());
    var displayContent;

    if (type === "user") {
      // Always treat user content as plain text for safety
      displayContent = "<p>" + escapeHtml(content) + "</p>";
    } else {
      // Bot content can be either:
      // - Already formatted HTML from the server (Ajax path)
      // - Plain text/markdown (defensive fallback)
      var isHtml = typeof content === "string" && content.indexOf("<") !== -1;
      var botHtml = isHtml ? content : markdownLinksToHtml(content || "");
      botHtml = styleProductRefs(botHtml);
      displayContent =
        botHtml.indexOf("<") !== -1 ? botHtml : "<p>" + botHtml + "</p>";
    }

    var div = document.createElement("div");
    div.className = "message " + type + "-message";

    if (type === "bot") {
      var inlineAvatar = logo
        ? '<img src="' + escapeAttr(logo) + '" alt="" class="inline-avatar">'
        : '<div class="inline-avatar-placeholder"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg></div>';
      // Check if previous message is also from bot for grouping
      var prevMsg = indicator
        ? indicator.previousElementSibling
        : messages.lastElementChild;
      var isGrouped =
        prevMsg &&
        prevMsg.classList &&
        prevMsg.classList.contains("bot-message");
      if (isGrouped) div.classList.add("grouped");
      div.innerHTML =
        '<div class="bot-message-row">' +
        inlineAvatar +
        '<div class="message-content">' +
        displayContent +
        '</div></div><div class="message-meta"><span class="message-time">' +
        time +
        "</span></div>";
    } else {
      div.innerHTML =
        '<div class="message-content">' +
        displayContent +
        '</div><div class="message-meta"><span class="message-time">' +
        time +
        "</span></div>";
    }
    // Insert BEFORE typing indicator so it stays at the bottom
    if (indicator) {
      messages.insertBefore(div, indicator);
    } else {
      messages.appendChild(div);
    }
    scrollToBottom();
  }

  // Feedback function removed for cleaner design - keeping stub for backward compatibility
  function handleFeedbackClick(btn) {
    // No-op: feedback UI removed
  }

  function resetConversation() {
    conversationHistory = [];
    sessionId = uniqid();
    try {
      localStorage.setItem("chatzio_session_id", sessionId);
    } catch (e) {}
    $(".chatzio-messages").innerHTML = "";
    var s = cfg.settings || {};
    var logo = s.logo || "";
    var inlineAva = logo
      ? '<img src="' + escapeAttr(logo) + '" class="inline-avatar">'
      : '<div class="inline-avatar-placeholder"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg></div>';
    var welcome = escapeHtml(
      s.welcomeMessage || "Hi! How can I help you today?",
    );
    $(".chatzio-messages").innerHTML =
      '<div class="message bot-message"><div class="bot-message-row">' +
      inlineAva +
      '<div class="message-content"><p>' +
      welcome +
      '</p></div></div><div class="message-meta"><span class="message-time">' +
      formatTime(new Date()) +
      "</span></div></div>" +
      '<div class="typing-indicator" aria-label="Assistant is typing">' +
      inlineAva +
      '<div class="typing-bubble"><div class="typing-dot"></div><div class="typing-dot"></div><div class="typing-dot"></div></div></div>';
    // Re-inject quick reply buttons (clicking a button removes them from the DOM)
    var qrBar = $(".chatzio-quick-replies-bar");
    if (qrBar) {
      var sCfg = cfg.settings || {};
      qrBar.innerHTML = buildQuickRepliesHtml(sCfg.quickReplies);
    }
    showQuickRepliesBar();
  }

  function hideQuickRepliesBar() {
    var bar = $(".chatzio-quick-replies-bar");
    if (bar) bar.classList.add("hidden");
  }

  function showQuickRepliesBar() {
    var bar = $(".chatzio-quick-replies-bar");
    if (bar) bar.classList.remove("hidden");
  }

  function showTyping(visible) {
    var indicator = $(".typing-indicator");
    if (!indicator) return;
    if (visible) {
      indicator.classList.add("visible");
      scrollToBottom();
    } else {
      indicator.classList.remove("visible");
    }
  }

  function scrollToBottom() {
    var msg = $(".chatzio-messages");
    if (msg) msg.scrollTo({ top: msg.scrollHeight, behavior: "smooth" });
  }

  function formatTime(d) {
    var h = d.getHours();
    var m = String(d.getMinutes()).padStart(2, "0");
    var ampm = h >= 12 ? "PM" : "AM";
    h = h % 12 || 12;
    return h + ":" + m + " " + ampm;
  }

  function trackAnalyticsEvent(eventType) {
    if (!cfg.ajaxUrl || !eventType) return;
    var body = new FormData();
    body.append("action", "chatzio_track_event");
    body.append("nonce", cfg.nonce);
    body.append("session_id", sessionId);
    body.append("event_type", eventType);
    body.append("page_url", window.location.href);
    fetch(cfg.ajaxUrl, {
      method: "POST",
      body: body,
      credentials: "same-origin",
    }).catch(function () {});
  }

  function escapeHtml(str) {
    if (!str) return "";
    var div = document.createElement("div");
    div.textContent = str;
    return div.innerHTML;
  }

  /** Convert markdown links [text](url) to <a> and escape the rest (for plain-text content e.g. from history) */
  function markdownLinksToHtml(str) {
    if (!str || typeof str !== "string") return "";
    // Step 1: Convert markdown links [text](url) → <a> while escaping surrounding text
    var re = /\[([^\]]*)\]\(([^)]+)\)/g;
    var parts = [];
    var lastIndex = 0;
    var match;
    while ((match = re.exec(str)) !== null) {
      parts.push(escapeHtml(str.slice(lastIndex, match.index)));
      parts.push(
        '<a href="' +
          escapeAttr(match[2].trim()) +
          '" target="_blank" rel="noopener">' +
          escapeHtml(match[1].trim()) +
          "</a>",
      );
      lastIndex = re.lastIndex;
    }
    parts.push(escapeHtml(str.slice(lastIndex)));
    var html = parts.join("");

    // Step 2: Convert **bold** → <strong>
    html = html.replace(/\*\*([^*]+)\*\*/g, "<strong>$1</strong>");

    // Step 3: Convert *italic* → <em> (bold already converted, so remaining * are italic)
    html = html.replace(/\*([^*]+)\*/g, "<em>$1</em>");

    // Step 4: Convert line breaks
    html = html.replace(/\n/g, "<br>");

    return html;
  }

  /** Turn [[Product Name]] into link-colored spans in HTML string */
  function styleProductRefs(html) {
    if (!html || typeof html !== "string") return html;
    return html.replace(/\[\[([^\]]*)\]\]/g, function (_, text) {
      return (
        '<span class="chatzio-product-ref">' +
        escapeHtml(text.trim()) +
        "</span>"
      );
    });
  }

  function escapeAttr(str) {
    return String(str || "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#39;");
  }

  // ── Restock Notification Handlers ──

  function handleRestockNotify(btn) {
    var pid = btn.getAttribute("data-product-id");
    var title = btn.getAttribute("data-product-title") || "this product";
    if (!pid || btn.classList.contains("pc-notify-processing")) return;

    btn.classList.add("pc-notify-processing");
    btn.disabled = true;

    var checkBody = new FormData();
    checkBody.append("action", "chatzio_restock_check");
    checkBody.append("nonce", cfg.nonce);
    checkBody.append("session_id", sessionId);
    checkBody.append("product_id", pid);

    fetch(cfg.ajaxUrl, { method: "POST", body: checkBody, credentials: "same-origin" })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        btn.classList.remove("pc-notify-processing");
        btn.disabled = false;

        if (data.success && data.data.already_subscribed) {
          showRestockMessage(btn, "You're already on the list!", "info");
          return;
        }

        if (data.success && data.data.known_user) {
          submitRestockSubscription(btn, pid, "", title);
        } else {
          showRestockEmailInput(btn, pid, title);
        }
      })
      .catch(function () {
        btn.classList.remove("pc-notify-processing");
        btn.disabled = false;
      });
  }

  function showRestockEmailInput(btn, productId, productTitle) {
    // Detect shortcode via the button's own class — more reliable than parent lookup
    // (themes may wrap the shortcode output in extra containers that break .closest())
    var isShortcode = btn.classList.contains("chatzio-restock-btn");

    // Shortcode: show a modal overlay on the page
    if (isShortcode) {
      showRestockModal(btn, productId, productTitle);
      return;
    }

    // In-chat card: inline email row inside shadow DOM
    var card = btn.closest(".pc-card");
    if (!card || card.querySelector(".pc-notify-email-row")) return;

    var row = document.createElement("div");
    row.className = "pc-notify-email-row";
    row.innerHTML =
      '<input type="email" class="pc-notify-email-input" placeholder="Your email" autocomplete="email">' +
      '<button class="pc-notify-email-submit" title="Subscribe">' +
        '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5"/></svg>' +
      '</button>';
    card.appendChild(row);

    var emailInput = row.querySelector(".pc-notify-email-input");
    var submitBtn = row.querySelector(".pc-notify-email-submit");
    emailInput.focus();

    submitBtn.addEventListener("click", function () {
      var email = emailInput.value.trim();
      if (!email || !email.match(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)) {
        emailInput.classList.add("pc-input-error");
        return;
      }
      submitRestockSubscription(btn, productId, email, productTitle);
      row.remove();
    });

    emailInput.addEventListener("keydown", function (ev) {
      if (ev.key === "Enter") {
        ev.preventDefault();
        submitBtn.click();
      }
      emailInput.classList.remove("pc-input-error");
    });
  }

  /**
   * Full-page modal for restock email capture (shortcode / product page).
   */
  function showRestockModal(btn, productId, productTitle) {
    // Prevent duplicate modals
    if (document.getElementById("chatzio-restock-modal")) return;

    var overlay = document.createElement("div");
    overlay.id = "chatzio-restock-modal";
    overlay.className = "chatzio-restock-modal-overlay";
    overlay.innerHTML =
      '<div class="chatzio-restock-modal">' +
        '<button type="button" class="chatzio-restock-modal-close" aria-label="Close">&times;</button>' +
        '<div class="chatzio-restock-modal-icon">' +
          '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0"/></svg>' +
        '</div>' +
        '<h3 class="chatzio-restock-modal-title">Get Notified</h3>' +
        '<p class="chatzio-restock-modal-subtitle">Enter your email and we\u2019ll notify you when <strong>' + escapeHtml(productTitle) + '</strong> is back in stock.</p>' +
        '<form class="chatzio-restock-modal-form">' +
          '<input type="email" class="chatzio-restock-modal-email" placeholder="Your email address" autocomplete="email" required>' +
          '<button type="submit" class="chatzio-restock-modal-submit">Notify Me</button>' +
        '</form>' +
      '</div>';

    document.body.appendChild(overlay);

    // Animate in
    requestAnimationFrame(function () {
      overlay.classList.add("chatzio-restock-modal-visible");
    });

    var modal = overlay.querySelector(".chatzio-restock-modal");
    var closeBtn = overlay.querySelector(".chatzio-restock-modal-close");
    var form = overlay.querySelector(".chatzio-restock-modal-form");
    var emailInput = overlay.querySelector(".chatzio-restock-modal-email");
    var submitBtn = overlay.querySelector(".chatzio-restock-modal-submit");

    setTimeout(function () { emailInput.focus(); }, 100);

    function closeModal() {
      overlay.classList.remove("chatzio-restock-modal-visible");
      setTimeout(function () { overlay.remove(); }, 200);
    }

    // Close on overlay click (not modal itself)
    overlay.addEventListener("click", function (e) {
      if (e.target === overlay) closeModal();
    });
    closeBtn.addEventListener("click", closeModal);
    document.addEventListener("keydown", function onEsc(e) {
      if (e.key === "Escape") {
        closeModal();
        document.removeEventListener("keydown", onEsc);
      }
    });

    form.addEventListener("submit", function (e) {
      e.preventDefault();
      var email = emailInput.value.trim();
      if (!email || !email.match(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)) {
        emailInput.classList.add("chatzio-restock-modal-input-error");
        return;
      }
      submitBtn.disabled = true;
      submitBtn.textContent = "Subscribing\u2026";
      submitRestockSubscription(btn, productId, email, productTitle);
      closeModal();
    });

    emailInput.addEventListener("input", function () {
      emailInput.classList.remove("chatzio-restock-modal-input-error");
    });
  }

  function submitRestockSubscription(btn, productId, email, productTitle) {
    btn.classList.add("pc-notify-processing");
    btn.disabled = true;

    var source = btn.classList.contains("chatzio-restock-btn") ? "shortcode" : "inchat";
    var body = new FormData();
    body.append("action", "chatzio_restock_subscribe");
    body.append("nonce", cfg.nonce);
    body.append("session_id", sessionId);
    body.append("product_id", productId);
    body.append("source", source);
    if (email) body.append("email", email);

    fetch(cfg.ajaxUrl, { method: "POST", body: body, credentials: "same-origin" })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        btn.classList.remove("pc-notify-processing");
        if (data.success) {
          if (data.data.status === "already_subscribed") {
            showRestockMessage(btn, "You're already on the list!", "info");
          } else {
            showRestockMessage(btn, data.data.message || ("You'll be notified when " + escapeHtml(productTitle) + " is back in stock!"), "success");
          }
        } else {
          btn.disabled = false;
          if (data.data && data.data.message === "email_required") {
            showRestockEmailInput(btn, productId, productTitle);
          }
        }
      })
      .catch(function () {
        btn.classList.remove("pc-notify-processing");
        btn.disabled = false;
      });
  }

  function showRestockMessage(btn, message) {
    var isShortcode = btn.classList.contains("chatzio-restock-btn");
    var card = isShortcode
      ? (btn.closest(".chatzio-restock-shortcode") || btn.parentElement)
      : btn.closest(".pc-card");
    if (!card) {
      btn.disabled = false;
      return;
    }

    // Remove email input row if present
    var row = card.querySelector(".pc-notify-email-row");
    if (row) row.remove();

    // Remove the notify button
    btn.remove();

    // Resolve primary color from the shadow host's computed style for inline use
    var primaryColor = "#4f46e5";
    try {
      var hostEl = shadow && shadow.host ? shadow.host : document.documentElement;
      var hostStyle = getComputedStyle(hostEl);
      var resolved = hostStyle.getPropertyValue("--chatzio-primary").trim();
      if (resolved) primaryColor = resolved;
    } catch (e) {}

    var fontSize = isShortcode ? "13px" : "11px";
    var iconSize = isShortcode ? "16px" : "14px";

    var bar = document.createElement("div");
    bar.className = "pc-notify-confirmation";
    bar.style.cssText = "display:flex;align-items:center;gap:6px;padding:5px 8px;font-size:" + fontSize + ";font-weight:600;color:" + primaryColor;
    bar.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="' + iconSize + '" height="' + iconSize + '" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="width:' + iconSize + ';height:' + iconSize + ';flex-shrink:0;color:' + primaryColor + '"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg><span>' + escapeHtml(message) + '</span>';

    // For shortcode: insert inside the container so our document CSS applies.
    // For in-chat cards: insert after the card (inside shadow DOM).
    if (isShortcode) {
      card.appendChild(bar);
    } else {
      card.insertAdjacentElement("afterend", bar);
    }
  }

  function stripHtml(html) {
    var div = document.createElement("div");
    div.innerHTML = html;
    return div.textContent || div.innerText || "";
  }

  function uniqid() {
    return (
      "chatzio_" +
      Date.now().toString(36) +
      Math.random().toString(36).substr(2, 9)
    );
  }

  // =========================================================================
  // Pre-Chat Lead Form
  // =========================================================================
  function maybeShowLeadForm() {
    var s = cfg.settings || {};
    if (!s.enableLeadForm) return;

    // Do not show to logged-in WordPress users
    if (s.isUserLoggedIn) return;

    // Only show on Chat tab
    if (currentTab !== "chat") return;

    // Do not show multiple times in a single page view
    if (leadOverlayShown) return;

    try {
      var captureKey = "chatzio_lead_captured_" + (sessionId || "");
      if (localStorage.getItem(captureKey)) return;
    } catch (e) {}

    var chatPanel = $("#chatzio-panel-chat");
    if (!chatPanel) return;

    // Avoid duplicate overlays
    if (chatPanel.querySelector(".chatzio-lead-overlay")) return;

    var fields = s.leadFormFields || {};
    var heading =
      s.leadFormHeading || "Before we start, tell us about yourself";
    var subheading =
      s.leadFormSubheading || "We'd love to know who we're chatting with.";

    var html =
      '<div class="chatzio-lead-overlay">' +
      '<div class="lead-form-heading">' +
      escapeHtml(heading) +
      "</div>";

    if (subheading) {
      html +=
        '<p class="lead-form-subtitle">' + escapeHtml(subheading) + "</p>";
    }

    html += '<form class="chatzio-lead-form">';

    if (fields.name) {
      html +=
        '<input type="text" name="lead_name" placeholder="Your name" required>';
    }
    html +=
      '<input type="email" name="lead_email" placeholder="Your email" required>';
    if (fields.phone) {
      html += '<input type="tel" name="lead_phone" placeholder="Phone number">';
    }
    html +=
      '<button type="submit" class="lead-form-submit">Start Chatting</button>';
    html += "</form>";
    // Optional skip button based on settings
    var allowSkip =
      typeof s.leadFormAllowSkip === "undefined" ? true : !!s.leadFormAllowSkip;
    if (allowSkip) {
      html +=
        '<button type="button" class="lead-form-skip">Skip for now</button>';
    }
    html += "</div>";

    chatPanel.insertAdjacentHTML("afterbegin", html);
    leadOverlayShown = true;

    var overlay = chatPanel.querySelector(".chatzio-lead-overlay");
    var form = overlay.querySelector(".chatzio-lead-form");
    var skip = overlay.querySelector(".lead-form-skip");

    // iOS WebKit fix: inputs inside position:fixed/absolute chains lose focus on tap
    // Explicitly focus on touchstart to bypass the bug
    var inputs = form.querySelectorAll("input");
    for (var ii = 0; ii < inputs.length; ii++) {
      inputs[ii].addEventListener("touchstart", function (e) {
        e.stopPropagation();
        this.focus();
      }, { passive: true });
    }

    form.addEventListener("submit", function (e) {
      e.preventDefault();
      var email = form.querySelector('[name="lead_email"]').value.trim();
      var name = form.querySelector('[name="lead_name"]');
      var phone = form.querySelector('[name="lead_phone"]');

      var body = new FormData();
      body.append("action", "chatzio_capture_lead");
      body.append("nonce", cfg.nonce);
      body.append("session_id", sessionId);
      body.append("email", email);
      body.append("name", name ? name.value.trim() : "");
      body.append("phone", phone ? phone.value.trim() : "");
      body.append("page_url", window.location.href);

      fetch(cfg.ajaxUrl, {
        method: "POST",
        body: body,
        credentials: "same-origin",
      })
        .then(function (res) {
          return res.json().then(function (data) {
            if (data && data.success) {
              try {
                var captureKey = "chatzio_lead_captured_" + (sessionId || "");
                localStorage.setItem(captureKey, "1");
              } catch (e) {}
            }
            return data;
          });
        })
        .catch(function () {})
        .then(function () {
          overlay.remove();
        });
    });

    if (skip) {
      skip.addEventListener("click", function () {
        overlay.remove();
      });
    }
  }

  // =========================================================================
  // Products panel: load categories and bind (separate from FAQ)
  // =========================================================================
  function loadProductsPanel() {
    var catsEl = $(".products-panel-cats");
    var listEl = $(".faq-products-list");
    if (!catsEl) return;

    catsEl.innerHTML = '<div class="faq-loading">Loading...</div>';

    var body = new FormData();
    body.append("action", "chatzio_get_faq");
    body.append("nonce", cfg.nonce);

    fetch(cfg.ajaxUrl, {
      method: "POST",
      body: body,
      credentials: "same-origin",
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        var productCats =
          data.success && data.data && data.data.product_categories
            ? data.data.product_categories
            : [];
        var showCategoryFilter = !!(
          data.success &&
          data.data &&
          data.data.show_category_filter
        );
        var sliderWrap = $(".products-panel-cats-wrap");
        if (sliderWrap)
          sliderWrap.style.display = showCategoryFilter ? "" : "none";

        var html =
          '<button type="button" class="faq-product-cat-btn active" data-cat-id="all">All</button>';
        for (var p = 0; p < productCats.length; p++) {
          var cat = productCats[p];
          html +=
            '<button type="button" class="faq-product-cat-btn" data-cat-id="' +
            escapeAttr(cat.id) +
            '">' +
            escapeHtml(cat.name) +
            ' <span class="cat-count">(' +
            (cat.count || 0) +
            ")</span>" +
            "</button>";
        }
        catsEl.innerHTML = html;
        if (listEl)
          listEl.innerHTML =
            '<div class="faq-loading">Loading products...</div>';

        var btns = catsEl.querySelectorAll(".faq-product-cat-btn");
        for (var b = 0; b < btns.length; b++) {
          btns[b].addEventListener("click", function () {
            var id = this.getAttribute("data-cat-id");
            catsEl
              .querySelectorAll(".faq-product-cat-btn")
              .forEach(function (btn) {
                btn.classList.remove("active");
              });
            this.classList.add("active");
            loadProductsForCategory(id);
          });
        }

        loadProductsForCategory("all");
      })
      .catch(function () {
        catsEl.innerHTML =
          '<p class="faq-error">Unable to load categories.</p>';
      });
  }

  // FAQ Loading & Filtering (v2 with categories)
  // =========================================================================
  function loadFaq() {
    var faqList = $(".faq-list");
    if (!faqList) return;

    faqList.innerHTML = '<div class="faq-loading">Loading FAQs...</div>';

    var body = new FormData();
    body.append("action", "chatzio_get_faq");
    body.append("nonce", cfg.nonce);

    fetch(cfg.ajaxUrl, {
      method: "POST",
      body: body,
      credentials: "same-origin",
    })
      .then(function (response) {
        return response.json();
      })
      .then(function (data) {
        if (data.success && data.data) {
          faqCache = data.data;
          renderFaqPanel(data.data);
        } else {
          faqList.innerHTML =
            '<div class="faq-empty">No FAQs available yet.</div>';
        }
      })
      .catch(function () {
        faqList.innerHTML = '<div class="faq-error">Unable to load FAQs.</div>';
      });
  }

  function renderFaqPanel(data) {
    var faqList = $(".faq-list");
    if (!faqList) return;

    var faqItems = data.faq_items || [];
    var categories = data.categories || [];

    // Category slider (like Products page): always show so layout matches Products
    var catsWrap = $(".faq-panel-cats-wrap");
    var catsEl = $(".faq-panel-cats");
    if (catsWrap && catsEl) {
      catsWrap.style.display = "";
      var catHtml =
        '<button type="button" class="faq-cat-btn active" data-cat="">All</button>';
      for (var c = 0; c < categories.length; c++) {
        if (!categories[c]) continue;
        catHtml +=
          '<button type="button" class="faq-cat-btn" data-cat="' +
          escapeAttr(categories[c]) +
          '">' +
          escapeHtml(categories[c]) +
          "</button>";
      }
      catsEl.innerHTML = catHtml;
      var catBtns = catsEl.querySelectorAll(".faq-cat-btn");
      for (var cb = 0; cb < catBtns.length; cb++) {
        catBtns[cb].addEventListener("click", function () {
          var cat = this.getAttribute("data-cat");
          filterFaqByCategory(cat);
          var siblings = catsEl.querySelectorAll(".faq-cat-btn");
          for (var s = 0; s < siblings.length; s++)
            siblings[s].classList.remove("active");
          this.classList.add("active");
        });
      }
    }

    // FAQ list content (section + items only)
    var html = "";
    if (faqItems.length > 0) {
      html += '<div class="faq-section">';
      for (var i = 0; i < faqItems.length; i++) {
        var item = faqItems[i];
        var catAttr = item.category
          ? ' data-category="' + escapeAttr(item.category) + '"'
          : "";
        html +=
          '<div class="faq-item" data-question="' +
          escapeAttr(item.question.toLowerCase()) +
          '"' +
          catAttr +
          ">" +
          '<button class="faq-item-header" type="button">' +
          '<span class="faq-item-title">' +
          escapeHtml(item.question) +
          "</span>" +
          '<svg class="faq-chevron" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m19.5 8.25-7.5 7.5-7.5-7.5"/></svg>' +
          "</button>" +
          '<div class="faq-item-body">' +
          (item.answer ? "<p>" + escapeHtml(item.answer) + "</p>" : "") +
          "</div>" +
          "</div>";
      }
      html += "</div>";
    }

    if (!faqItems.length) {
      html = '<div class="faq-empty">No FAQs available yet.</div>';
    }

    faqList.innerHTML = html;
  }

  function filterFaqByCategory(cat) {
    var items = $$(".faq-item");
    for (var i = 0; i < items.length; i++) {
      var itemCat = items[i].getAttribute("data-category") || "";
      var matches = !cat || itemCat === cat;
      items[i].style.display = matches ? "" : "none";
    }
  }

  function filterFaq(query) {
    query = query.toLowerCase().trim();
    var items = $$(".faq-item");
    for (var i = 0; i < items.length; i++) {
      var question = items[i].getAttribute("data-question") || "";
      var matches = !query || question.indexOf(query) !== -1;
      items[i].style.display = matches ? "" : "none";
    }
  }

  function loadProductsForCategory(catId) {
    var list = $(".faq-products-list");
    if (!list) return;

    list.innerHTML = '<div class="faq-loading">Loading products...</div>';

    var body = new FormData();
    body.append("action", "chatzio_get_products_by_category");
    body.append("nonce", cfg.nonce);
    body.append("category_id", catId);

    fetch(cfg.ajaxUrl, {
      method: "POST",
      body: body,
      credentials: "same-origin",
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        if (
          data.success &&
          data.data.products &&
          data.data.products.length > 0
        ) {
          var html = "";
          var products = data.data.products;
          var cur = data.data.currency || "$";
          var shopUrl =
            data.data.shop_url && data.data.shop_url.length > 0
              ? data.data.shop_url
              : "";
          var showShopLink = !!data.data.show_shop_link;
          var highlightedIds =
            cfg.settings && cfg.settings.productsHighlight
              ? cfg.settings.productsHighlight
              : [];
          for (var i = 0; i < products.length; i++) {
            var p = products[i];
            var isHighlighted =
              p.highlighted || (p.id && highlightedIds.indexOf(p.id) !== -1);
            var hasImage = p.image && p.image.length > 0;
            var hasSale =
              !p.price_formatted &&
              p.sale_price &&
              p.sale_price !== "" &&
              p.sale_price !== p.regular_price;
            var priceHtml = "";
            if (p.price_formatted && p.regular_price) {
              priceHtml =
                '<span class="pc-price">' +
                escapeHtml(p.regular_price) +
                "</span>";
            } else if (hasSale) {
              priceHtml =
                '<span class="pc-price pc-sale">' +
                escapeHtml(cur + p.sale_price) +
                "</span>" +
                '<span class="pc-price pc-regular pc-strikethrough">' +
                escapeHtml(cur + p.regular_price) +
                "</span>";
            } else if (p.regular_price) {
              priceHtml =
                '<span class="pc-price">' +
                escapeHtml(cur + p.regular_price) +
                "</span>";
            }
            var stockClass = p.stock_status === "outofstock" ? " pc-out" : "";
            var isSimpleInStock =
              p.product_type === "simple" && p.stock_status !== "outofstock";
            var cardImgBody =
              (hasImage
                ? '<div class="pc-img"><img src="' +
                  escapeAttr(p.image) +
                  '" alt="' +
                  escapeAttr(p.title) +
                  '" loading="lazy"></div>'
                : '<div class="pc-img pc-img-empty"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M3.75 21h16.5A2.25 2.25 0 0 0 22.5 18.75V5.25A2.25 2.25 0 0 0 20.25 3H3.75A2.25 2.25 0 0 0 1.5 5.25v13.5A2.25 2.25 0 0 0 3.75 21Z"/></svg></div>') +
              '<div class="pc-body">' +
              '<span class="pc-title">' +
              escapeHtml(p.title) +
              "</span>" +
              (priceHtml
                ? '<div class="pc-prices">' + priceHtml + "</div>"
                : "") +
              (p.stock_status === "outofstock"
                ? '<span class="pc-stock-badge">Out of stock</span>'
                : "") +
              "</div>";
            var arrowSvg =
              '<svg class="pc-arrow" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5"/></svg>';
            var addToCartBtn =
              '<a href="' +
              escapeAttr(
                (window.location.origin || "") + "/?add-to-cart=" + p.id,
              ) +
              '" class="pc-add-to-cart-btn" data-product-id="' +
              escapeAttr(String(p.id)) +
              '" title="Add to cart"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 0 0-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.924-7.138a60.114 60.114 0 0 0-16.536-1.84M7.5 14.25 5.106 5.272M6 20.25a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Zm12.75 0a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z"/></svg><span>Add to cart</span></a>';
            if (isSimpleInStock && p.id) {
              html +=
                '<div class="pc-card pc-has-add-to-cart' +
                stockClass +
                '"><a href="' +
                escapeAttr(p.url) +
                '" class="pc-card-link" target="_blank">' +
                cardImgBody +
                "</a>" +
                addToCartBtn +
                "</div>";
            } else if (cfg.settings.notifyRestock && p.stock_status === "outofstock" && p.id) {
              var bellSvg = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0"/></svg>';
              html +=
                '<div class="pc-card pc-has-notify' + stockClass + '"><a href="' +
                escapeAttr(p.url) + '" class="pc-card-link" target="_blank">' +
                cardImgBody + '</a><button class="pc-notify-btn" data-product-id="' +
                escapeAttr(String(p.id)) + '" data-product-title="' +
                escapeAttr(p.title) + '">' + bellSvg + '<span>Notify Me</span></button></div>';
            } else {
              html +=
                '<a href="' +
                escapeAttr(p.url) +
                '" class="pc-card' +
                stockClass +
                '" target="_blank">' +
                cardImgBody +
                arrowSvg +
                "</a>";
            }
          }
          if (showShopLink && shopUrl) {
            html +=
              '<a href="' +
              escapeAttr(shopUrl) +
              '" class="pc-shop-link" target="_blank" rel="noopener">View full shop</a>';
          }
          list.innerHTML = html;
        } else {
          list.innerHTML = '<div class="faq-empty">No products found.</div>';
        }
      })
      .catch(function () {
        list.innerHTML =
          '<div class="faq-error">Failed to load products.</div>';
      });
  }

  // Load featured products for Home tab
  function loadFeaturedProducts() {
    var container = $("#home-featured-products");
    if (!container) return;

    var featuredIds =
      cfg.settings && cfg.settings.productsFeatured
        ? cfg.settings.productsFeatured
        : [];
    if (!featuredIds || featuredIds.length === 0) {
      container.style.display = "none";
      return;
    }

    var sectionTitle =
      cfg.settings && cfg.settings.homeFeaturedProductsHeading
        ? cfg.settings.homeFeaturedProductsHeading
        : "Featured Products";

    // Fetch products by IDs
    var body = new FormData();
    body.append("action", "chatzio_get_featured_products");
    body.append("nonce", cfg.nonce);
    body.append("product_ids", JSON.stringify(featuredIds));

    fetch(cfg.ajaxUrl, {
      method: "POST",
      body: body,
      credentials: "same-origin",
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        if (
          data.success &&
          data.data &&
          data.data.products &&
          data.data.products.length > 0
        ) {
          var html = '<div class="home-featured-products-section">';
          html +=
            '<div class="news-label">' + escapeHtml(sectionTitle) + "</div>";
          var products = data.data.products;
          var cur = data.data.currency || "$";
          for (var i = 0, shown = 0; i < products.length && shown < 6; i++) {
            var p = products[i];
            // Hide out-of-stock products from featured on home tab
            if (p.stock_status === "outofstock") continue;
            shown++;
            var hasImage = p.image && p.image.length > 0;
            var hasSale =
              !p.price_formatted &&
              p.sale_price &&
              p.sale_price !== "" &&
              p.sale_price !== p.regular_price;
            var priceHtml = "";
            if (p.price_formatted && p.regular_price) {
              priceHtml =
                '<span class="pc-price">' +
                escapeHtml(p.regular_price) +
                "</span>";
            } else if (hasSale) {
              priceHtml =
                '<span class="pc-price pc-sale">' +
                escapeHtml(cur + p.sale_price) +
                "</span>" +
                '<span class="pc-price pc-regular pc-strikethrough">' +
                escapeHtml(cur + p.regular_price) +
                "</span>";
            } else if (p.regular_price) {
              priceHtml =
                '<span class="pc-price">' +
                escapeHtml(cur + p.regular_price) +
                "</span>";
            }
            var stockClass = p.stock_status === "outofstock" ? " pc-out" : "";
            var isSimpleInStock =
              p.product_type === "simple" && p.stock_status !== "outofstock";
            var cardImgBody =
              (hasImage
                ? '<div class="pc-img"><img src="' +
                  escapeAttr(p.image) +
                  '" alt="' +
                  escapeAttr(p.title) +
                  '" loading="lazy"></div>'
                : '<div class="pc-img pc-img-empty"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M3.75 21h16.5A2.25 2.25 0 0 0 22.5 18.75V5.25A2.25 2.25 0 0 0 20.25 3H3.75A2.25 2.25 0 0 0 1.5 5.25v13.5A2.25 2.25 0 0 0 3.75 21Z"/></svg></div>') +
              '<div class="pc-body">' +
              '<span class="pc-title">' +
              escapeHtml(p.title) +
              "</span>" +
              (priceHtml
                ? '<div class="pc-prices">' + priceHtml + "</div>"
                : "") +
              (p.stock_status === "outofstock"
                ? '<span class="pc-stock-badge">Out of stock</span>'
                : "") +
              "</div>";
            var arrowSvg =
              '<svg class="pc-arrow" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5"/></svg>';
            var addToCartBtn =
              '<a href="' +
              escapeAttr(
                (window.location.origin || "") + "/?add-to-cart=" + p.id,
              ) +
              '" class="pc-add-to-cart-btn" data-product-id="' +
              escapeAttr(String(p.id)) +
              '" title="Add to cart"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 0 0-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.924-7.138a60.114 60.114 0 0 0-16.536-1.84M7.5 14.25 5.106 5.272M6 20.25a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Zm12.75 0a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z"/></svg><span>Add to cart</span></a>';
            if (isSimpleInStock && p.id) {
              html +=
                '<div class="pc-card pc-featured-home pc-has-add-to-cart' +
                stockClass +
                '"><a href="' +
                escapeAttr(p.url) +
                '" class="pc-card-link" target="_blank">' +
                cardImgBody +
                "</a>" +
                addToCartBtn +
                "</div>";
            } else if (cfg.settings.notifyRestock && p.stock_status === "outofstock" && p.id) {
              var bellSvgH = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0"/></svg>';
              html +=
                '<div class="pc-card pc-featured-home pc-has-notify' + stockClass + '"><a href="' +
                escapeAttr(p.url) + '" class="pc-card-link" target="_blank">' +
                cardImgBody + '</a><button class="pc-notify-btn" data-product-id="' +
                escapeAttr(String(p.id)) + '" data-product-title="' +
                escapeAttr(p.title) + '">' + bellSvgH + '<span>Notify Me</span></button></div>';
            } else {
              html +=
                '<a href="' +
                escapeAttr(p.url) +
                '" class="pc-card pc-featured-home' +
                stockClass +
                '" target="_blank">' +
                cardImgBody +
                arrowSvg +
                "</a>";
            }
          }
          html += "</div>";
          container.innerHTML = html;
        } else {
          container.style.display = "none";
        }
      })
      .catch(function () {
        container.style.display = "none";
      });
  }

  // =========================================================================
  // History Panel
  // =========================================================================
  function renderHistory() {
    var historyList = $(".history-list");
    if (!historyList) return;

    var sessions = getStoredSessions();

    if (!sessions || sessions.length === 0) {
      historyList.innerHTML =
        '<div class="history-empty">No past conversations yet.</div>';
      return;
    }

    var html = "";
    for (var i = 0; i < sessions.length; i++) {
      var sess = sessions[i];
      var relTime = timeAgo(sess.timestamp);
      html +=
        '<button class="history-item" type="button" data-session="' +
        escapeAttr(sess.id) +
        '">' +
        '<div class="history-item-content">' +
        '<span class="history-item-preview">' +
        escapeHtml(truncate(sess.firstMessage, 50)) +
        "</span>" +
        '<span class="history-item-time">' +
        relTime +
        "</span>" +
        "</div>" +
        '<svg class="history-chevron" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m8.25 4.5 7.5 7.5-7.5 7.5"/></svg>' +
        "</button>";
    }
    historyList.innerHTML = html;
  }

  function getStoredSessions() {
    try {
      return JSON.parse(localStorage.getItem("chatzio_sessions")) || [];
    } catch (e) {
      return [];
    }
  }

  function saveCurrentSession() {
    if (!sessionId || conversationHistory.length === 0) return;

    var firstUserMsg = "";
    for (var i = 0; i < conversationHistory.length; i++) {
      if (conversationHistory[i].role === "user") {
        firstUserMsg = conversationHistory[i].content;
        break;
      }
    }
    if (!firstUserMsg) return;

    var sessions = getStoredSessions();
    var found = false;
    for (var j = 0; j < sessions.length; j++) {
      if (sessions[j].id === sessionId) {
        sessions[j].timestamp = Date.now();
        sessions[j].history = conversationHistory;
        found = true;
        break;
      }
    }

    if (!found) {
      sessions.unshift({
        id: sessionId,
        firstMessage: firstUserMsg,
        timestamp: Date.now(),
        history: conversationHistory,
      });
    }

    if (sessions.length > 5) sessions = sessions.slice(0, 5);
    try {
      localStorage.setItem("chatzio_sessions", JSON.stringify(sessions));
      // Update session timestamp to keep session alive
      localStorage.setItem("chatzio_session_ts", Date.now().toString());
    } catch (e) {}
  }

  function loadSession(sid) {
    var sessions = getStoredSessions();
    for (var i = 0; i < sessions.length; i++) {
      if (sessions[i].id === sid) {
        sessionId = sid;
        try {
          localStorage.setItem("chatzio_session_id", sid);
        } catch (e) {}
        conversationHistory = sessions[i].history || [];
        rebuildChatFromHistory();
        hideQuickRepliesBar();
        return;
      }
    }
  }

  function rebuildChatFromHistory() {
    var messages = $(".chatzio-messages");
    if (!messages) return;

    var s = cfg.settings || {};
    var logo = s.logo || "";
    var inlineAva = logo
      ? '<img src="' + escapeAttr(logo) + '" alt="" class="inline-avatar">'
      : '<div class="inline-avatar-placeholder"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg></div>';

    var html = "";
    for (var i = 0; i < conversationHistory.length; i++) {
      var msg = conversationHistory[i];
      if (msg.role === "user") {
        html +=
          '<div class="message user-message"><div class="message-content"><p>' +
          escapeHtml(msg.content) +
          "</p></div></div>";
      } else {
        var botHtml = styleProductRefs(markdownLinksToHtml(msg.content));
        html +=
          '<div class="message bot-message"><div class="bot-message-row">' +
          inlineAva +
          '<div class="message-content">' +
          (botHtml.indexOf("<") !== -1 ? botHtml : "<p>" + botHtml + "</p>") +
          "</div></div></div>";
      }
    }

    // Add typing indicator back
    html +=
      '<div class="typing-indicator" aria-label="Assistant is typing">' +
      inlineAva +
      '<div class="typing-bubble"><div class="typing-dot"></div><div class="typing-dot"></div><div class="typing-dot"></div></div></div>';

    messages.innerHTML = html;
    scrollToBottom();
  }

  function clearHistory() {
    localStorage.removeItem("chatzio_sessions");
    renderHistory();
  }

  function timeAgo(timestamp) {
    var seconds = Math.floor((Date.now() - timestamp) / 1000);
    if (seconds < 60) return "Just now";
    var minutes = Math.floor(seconds / 60);
    if (minutes < 60) return minutes + "m ago";
    var hours = Math.floor(minutes / 60);
    if (hours < 24) return hours + "h ago";
    var days = Math.floor(hours / 24);
    if (days < 7) return days + "d ago";
    return new Date(timestamp).toLocaleDateString();
  }

  function truncate(str, len) {
    if (!str) return "";
    return str.length > len ? str.substring(0, len) + "..." : str;
  }

  // =========================================================================
  // Analytics & Triggers
  // =========================================================================
  (function initSmartTriggers() {
    try {
      var visitCount =
        parseInt(localStorage.getItem("chatzio_visits") || "0", 10) + 1;
      localStorage.setItem("chatzio_visits", String(visitCount));
    } catch (e) {}
  })();
})();
